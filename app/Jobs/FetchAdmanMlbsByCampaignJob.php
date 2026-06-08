<?php

namespace App\Jobs;

use App\Services\AdmanMcpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Varredura COMPLETA de productAds da Adman MCP em background.
 *
 * Resolve o caso em que o drilldown "MLBs deste adgroup" no SugadorController
 * só lê 16 páginas (cap síncrono pra caber no fastcgi_read_timeout=300s do nginx).
 * Para contas com 460+ páginas (~23k anúncios), 16 páginas = 3.5% — quase sempre
 * o adgroup correto NÃO está nesse recorte.
 *
 * Estratégia:
 *  - Job dispara `fetchAllProductAds` com maxPages alto (1000, efetivamente sem cap)
 *  - O resultado é cacheado pela chave que inclui maxPages, então a próxima
 *    chamada síncrona com o mesmo maxPages encontra cache hit imediato.
 *  - `ShouldBeUnique` evita disparos duplicados do mesmo (custId, dateFrom, dateTo).
 *  - tries=5 + backoff escalonado generoso pra cobrir 429/timeout da MCP que
 *    podem durar uma janela inteira de rate-limit (até várias horas).
 *  - Progresso incremental gravado no statusCacheKey a cada página — UI
 *    consegue mostrar "X/Y páginas lidas" enquanto o job roda.
 *
 * Custo estimado: 460 páginas × ~3s/página (com throttle de 1.5s) ≈ 23min.
 * Timeout 1800s (30min) cobre isso com folga.
 *
 * ⚠️ ATENÇÃO supervisor: o $timeout=1800 do job só é honrado se o worker
 *    for chamado com `--timeout=1800` (ou maior). O default do Laravel é 60s —
 *    com isso o worker mata o job em 60s mesmo declarando timeout maior.
 *    Conferir `[program:ecf-worker]` no supervisorctl com:
 *       command=php artisan queue:work --timeout=1800 --tries=5 ...
 */
class FetchAdmanMlbsByCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Phase 30 D-04 — cap reduzido de 1000 → 500. Com rate limiter global
     * 8/min, varredura completa de 500 páginas distribui em ~62min worst-case;
     * checkpoint cuida do resto via re-dispatch (D-03).
     */
    public const MAX_PAGES_FULL_SCAN = 500;

    /**
     * Phase 30 D-03 Pitfall 2 — previne loop infinito de re-dispatch quando
     * Job sempre estoura timeout (conta gigante / MCP degradada). Ao atingir,
     * gravamos status 'failed' com mensagem amigável.
     */
    public const MAX_DISPATCH_COUNT = 10;

    public int $tries = 5;

    /** 30 min — varredura full de 500 páginas com throttle dá ~25min. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $custId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        /**
         * Phase 30 D-03 — Página inicial da varredura. Default 1 = primeira
         * execução. Continuações passam pages_read+1 do checkpoint anterior.
         */
        public readonly int $startPage = 1,
        /**
         * Phase 30 D-03 — Items já coletados em execuções anteriores.
         * Default [] = primeira execução. Continuações passam o array mesclado.
         */
        public readonly array $mlbsAcumulados = [],
        /**
         * Phase 30 D-03 — Contador de re-dispatches (anti-loop). Default 0
         * = primeira execução. Cap em MAX_DISPATCH_COUNT.
         */
        public readonly int $dispatchCount = 0,
    ) {}

    /**
     * Backoff escalonado generoso: 10min, 30min, 1h, 2h, 3h. Rate-limit do
     * upstream da MCP pode prender por mais que 30min em janelas de pico;
     * os backoff antigos (5/15/30min) gastavam as 3 tentativas em ~50min e
     * matavam o job antes da janela liberar.
     */
    public function backoff(): array
    {
        return [600, 1800, 3600, 7200, 10800];
    }

    /**
     * Chave de unicidade: custId + range. Garante que cliques múltiplos no
     * "Recarregar" de sugadores diferentes da mesma conta+range não enfileiram
     * duplicatas. TTL = timeout do job para não bloquear novo dispatch após falha.
     */
    public function uniqueId(): string
    {
        return "{$this->custId}_{$this->dateFrom}_{$this->dateTo}";
    }

    /**
     * TTL do lock — libera após 30min mesmo se o job sumir. Sem isso o
     * ShouldBeUnique bloqueia indefinidamente em caso de crash do worker.
     */
    public function uniqueFor(): int
    {
        return 1800;
    }

    /**
     * Phase 30 D-01 — Aplica throttle global Adman 'adman-api' (8/min).
     * Workers concorrentes compartilham o bucket via Redis. Quando o limite
     * estoura, Laravel reagenda em delayed sem marcar falha.
     */
    public function middleware(): array
    {
        return [new RateLimited('adman-api')];
    }

    public function handle(AdmanMcpService $mcp): void
    {
        $started = microtime(true);
        $statusKey = $this->statusCacheKey();

        // Phase 30 fix W1 (revisão pós-smoke D) — leitura defensiva das
        // properties novas (startPage, mlbsAcumulados, dispatchCount).
        // Jobs enfileirados ANTES do Plan 30-01 Task 2 não têm essas no
        // payload serializado. Sem `?? default`, typed readonly property
        // uninitialized throw Error e o Job nunca termina (vimos isso em
        // prod: ::dispatchCount must not be accessed before initialization).
        $startPage      = $this->startPage      ?? 1;
        $mlbsAcumulados = $this->mlbsAcumulados ?? [];
        $dispatchCount  = $this->dispatchCount  ?? 0;

        // Phase 30 D-03 Pitfall 2 — Guard de cap de re-dispatch.
        // Se uma cadeia de continuações já alcançou MAX_DISPATCH_COUNT sem
        // terminar, sinaliza falha definitiva. Evita loop infinito quando
        // a conta é grande demais ou a MCP está degradada.
        if ($dispatchCount >= self::MAX_DISPATCH_COUNT) {
            $cap = self::MAX_DISPATCH_COUNT;
            Cache::put($statusKey, [
                'status'         => 'failed',
                'error'          => "Cap de {$cap} re-dispatches atingido. Conta muito grande ou MCP degradada — investigar.",
                'dispatch_count' => $dispatchCount,
                'mlbs_acumulados' => count($mlbsAcumulados),
                'completed_at'   => now()->toIso8601String(),
            ], now()->addMinutes(30));

            Log::error(sprintf(
                '[FetchAdmanMlbs] cap de %d re-dispatches atingido custId=%s — abortando',
                $cap, $this->custId,
            ));
            return;
        }

        // Marca "running" imediatamente — sem isso a UI mostra "em andamento"
        // por inércia (não tem nada no cache) até a 1ª página chegar (~15s).
        // Phase 30 D-03 — anotar startPage + dispatch_count + mlbs já coletados
        // (continuação preserva contexto, UI mostra "continuando varredura...").
        Cache::put($statusKey, [
            'status'          => 'running',
            'pages_read'      => max(0, $startPage - 1),
            'total_pages'     => null,
            'items_count'     => count($mlbsAcumulados),
            'start_page'      => $startPage,
            'dispatch_count'  => $dispatchCount,
            'mlbs_acumulados' => count($mlbsAcumulados),
            'started_at'      => now()->toIso8601String(),
            'updated_at'      => now()->toIso8601String(),
            'attempt'         => $this->attempts(),
        ], now()->addMinutes(35));

        Log::info(sprintf(
            '[FetchAdmanMlbs] iniciando custId=%s range=%s..%s attempt=%d startPage=%d dispatch=%d mlbs_acumulados=%d',
            $this->custId, $this->dateFrom, $this->dateTo, $this->attempts(),
            $startPage, $dispatchCount, count($mlbsAcumulados),
        ));

        try {
            // Passa statusKey pro service gravar progresso a cada página.
            // Phase 30 D-03 — startPage permite retomar de onde parou (checkpoint).
            $result = $mcp->fetchAllProductAds(
                custId:           $this->custId,
                dateFrom:         $this->dateFrom,
                dateTo:           $this->dateTo,
                itemsPerPage:     50,
                maxPages:         self::MAX_PAGES_FULL_SCAN,
                progressCacheKey: $statusKey,
                startPage:        $startPage,
            );
        } catch (\Throwable $e) {
            // Log com stack trace pra investigar causa-raiz (rate limit? TLS?
            // worker killed?). O backoff cuida do retry; aqui só atualizamos
            // status pra UI ver a tentativa atual e re-throw pra Laravel marcar
            // como failed (que dispara backoff ou failed() se esgotou).
            Cache::put($statusKey, [
                'status'        => 'retrying',
                'attempt'       => $this->attempts(),
                'tries_total'   => $this->tries,
                'error'         => $e->getMessage(),
                'next_retry_in' => $this->backoff()[$this->attempts() - 1] ?? null,
                'updated_at'    => now()->toIso8601String(),
            ], now()->addMinutes(35));

            Log::error(sprintf(
                '[FetchAdmanMlbs] tentativa %d/%d falhou custId=%s: %s',
                $this->attempts(), $this->tries, $this->custId, $e->getMessage(),
            ), ['exception' => $e]);

            throw $e;
        }

        // Phase 30 D-03 — Decisão de checkpoint:
        // Se varredura não terminou (pages_read < total_pages) E consumimos
        // >= 80% do timeout, persistir progresso + re-dispatchar continuação.
        $pagesRead   = (int) ($result['pages_read'] ?? 0);
        $totalPages  = (int) ($result['total_pages'] ?? 0);
        $itemsResult = $result['items'] ?? [];
        // Phase 30 fix W1 (revisão D) — também aciona checkpoint quando o
        // Service retorna rate_limited=true (rate-limit local ou 429 upstream
        // capturado em fetchAllProductAds). Sem isso, Job termina como 'ready'
        // com apenas as poucas páginas que conseguiu antes de bater o limit.
        $rateLimitedNoService = (bool) ($result['rate_limited'] ?? false);
        $elapsedRatio = (microtime(true) - $started) / max(1, $this->timeout);
        $incompleto   = $totalPages > 0 && $pagesRead < $totalPages;
        $podeContinuar = $dispatchCount < self::MAX_DISPATCH_COUNT - 1;
        $deveFazerCheckpoint = $incompleto && $podeContinuar
            && ($rateLimitedNoService || $elapsedRatio >= 0.80);

        // Sempre mesclamos os items coletados ATÉ AGORA com o acumulado anterior.
        // Em continuações, isso evita que a UI veja só os últimos batch de páginas.
        $mlbsAcumulados = array_merge($mlbsAcumulados, $itemsResult);

        if ($deveFazerCheckpoint) {
            // Re-dispatch da continuação a partir da próxima página
            $proximaPagina = $pagesRead + 1;
            $proximoDispatch = $dispatchCount + 1;

            self::dispatch(
                custId:         $this->custId,
                dateFrom:       $this->dateFrom,
                dateTo:         $this->dateTo,
                startPage:      $proximaPagina,
                mlbsAcumulados: $mlbsAcumulados,
                dispatchCount:  $proximoDispatch,
            );

            Cache::put($statusKey, [
                'status'          => 'continuando',
                'pages_read'      => $pagesRead,
                'total_pages'     => $totalPages,
                'items_count'     => count($mlbsAcumulados),
                'start_page'      => $proximaPagina,
                'dispatch_count'  => $proximoDispatch,
                'mlbs_acumulados' => count($mlbsAcumulados),
                'rate_limited'    => $rateLimitedNoService,
                'updated_at'      => now()->toIso8601String(),
            ], now()->addMinutes(35));

            Log::info(sprintf(
                '[FetchAdmanMlbs] checkpoint custId=%s pages_read=%d/%d dispatch=%d mlbs_acumulados=%d rate_limited=%s — re-dispatchando continuação',
                $this->custId, $pagesRead, $totalPages, $proximoDispatch, count($mlbsAcumulados),
                $rateLimitedNoService ? 'sim' : 'nao',
            ));
            return;
        }

        // Varredura concluiu (ou maxPages atingido sem mais tempo de continuação).
        // Estado final = mlbs_acumulados (preserva tudo coletado em re-dispatches anteriores).
        Cache::put(
            $statusKey,
            [
                'status'         => 'ready',
                'pages_read'     => $pagesRead,
                'total_pages'    => $totalPages,
                'items_count'    => count($mlbsAcumulados),
                'dispatch_count' => $dispatchCount,
                'completed_at'   => now()->toIso8601String(),
            ],
            now()->addMinutes(30),
        );

        $elapsed = round(microtime(true) - $started, 1);
        Log::info(sprintf(
            '[FetchAdmanMlbs] concluído custId=%s pages=%d/%d items=%d dispatch=%d elapsed=%ss',
            $this->custId, $pagesRead, $totalPages, count($mlbsAcumulados), $dispatchCount, $elapsed,
        ));
    }

    public function failed(\Throwable $e): void
    {
        // Mensagem amigável pra UI: o operador não precisa ver "has been attempted
        // too many times" — precisa saber o que pode fazer agora.
        $userMessage = sprintf(
            'A varredura completa não terminou após %d tentativas (causa: %s). '
            . 'Geralmente é rate-limit temporário do upstream — espere ~1h e clique em Recarregar.',
            $this->tries,
            substr($e->getMessage(), 0, 200),
        );

        Cache::put(
            $this->statusCacheKey(),
            [
                'status'       => 'failed',
                'error'        => $userMessage,
                'raw_error'    => $e->getMessage(),
                'attempts'     => $this->attempts(),
                'completed_at' => now()->toIso8601String(),
            ],
            now()->addMinutes(30),
        );

        Log::error(sprintf(
            '[FetchAdmanMlbs] falha definitiva custId=%s range=%s..%s after %d tries: %s',
            $this->custId, $this->dateFrom, $this->dateTo, $this->attempts(), $e->getMessage(),
        ), ['exception' => $e]);
    }

    /**
     * Chave de cache que a UI/controller usa pra checar status do scan.
     * Bate com o cacheKey do fetchAllProductAds — assim a UI sabe quando
     * o resultado completo está pronto.
     */
    public function statusCacheKey(): string
    {
        return sprintf(
            'adman_mcp:scan_status:%s:%s:%s',
            $this->custId, $this->dateFrom, $this->dateTo,
        );
    }

    /**
     * Helper estático pra outros lugares (SugadorController) saberem qual
     * é a chave sem precisar instanciar o job. Mantém a chave única no projeto.
     */
    public static function statusCacheKeyFor(string $custId, string $dateFrom, string $dateTo): string
    {
        return sprintf(
            'adman_mcp:scan_status:%s:%s:%s',
            $custId, $dateFrom, $dateTo,
        );
    }
}
