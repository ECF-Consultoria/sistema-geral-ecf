<?php

namespace App\Jobs;

use App\Services\AdmanMcpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    /** Sem cap efetivo — caps de uso real ficam em 100-500 páginas. */
    public const MAX_PAGES_FULL_SCAN = 1000;

    public int $tries = 5;

    /** 30 min — varredura full de 500 páginas com throttle dá ~25min. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $custId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
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

    public function handle(AdmanMcpService $mcp): void
    {
        $started = microtime(true);
        $statusKey = $this->statusCacheKey();

        // Marca "running" imediatamente — sem isso a UI mostra "em andamento"
        // por inércia (não tem nada no cache) até a 1ª página chegar (~15s).
        Cache::put($statusKey, [
            'status'       => 'running',
            'pages_read'   => 0,
            'total_pages'  => null,
            'items_count'  => 0,
            'started_at'   => now()->toIso8601String(),
            'updated_at'   => now()->toIso8601String(),
            'attempt'      => $this->attempts(),
        ], now()->addMinutes(35));

        Log::info(sprintf(
            '[FetchAdmanMlbs] iniciando full-scan custId=%s range=%s..%s attempt=%d',
            $this->custId, $this->dateFrom, $this->dateTo, $this->attempts(),
        ));

        try {
            // Passa statusKey pro service gravar progresso a cada página.
            // A próxima chamada síncrona com o mesmo maxPages pega o cache do
            // resultado final automaticamente.
            $result = $mcp->fetchAllProductAds(
                custId:           $this->custId,
                dateFrom:         $this->dateFrom,
                dateTo:           $this->dateTo,
                itemsPerPage:     50,
                maxPages:         self::MAX_PAGES_FULL_SCAN,
                progressCacheKey: $statusKey,
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

        Cache::put(
            $statusKey,
            [
                'status'        => 'ready',
                'pages_read'    => $result['pages_read']  ?? null,
                'total_pages'   => $result['total_pages'] ?? null,
                'items_count'   => count($result['items'] ?? []),
                'completed_at'  => now()->toIso8601String(),
            ],
            now()->addMinutes(30),
        );

        $elapsed = round(microtime(true) - $started, 1);
        Log::info(sprintf(
            '[FetchAdmanMlbs] concluído custId=%s pages=%d/%d items=%d elapsed=%ss',
            $this->custId,
            $result['pages_read']  ?? 0,
            $result['total_pages'] ?? 0,
            count($result['items'] ?? []),
            $elapsed,
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
