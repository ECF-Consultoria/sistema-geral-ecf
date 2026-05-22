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
 *  - tries=3 + backoff escalonado pra cobrir 429/timeout da MCP.
 *
 * Custo estimado: 460 páginas × ~3s/página (com throttle de 1.5s) ≈ 23min.
 * Timeout 1800s (30min) cobre isso com folga.
 */
class FetchAdmanMlbsByCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Sem cap efetivo — caps de uso real ficam em 100-500 páginas. */
    public const MAX_PAGES_FULL_SCAN = 1000;

    public int $tries = 3;

    /** 30 min — varredura full de 500 páginas com throttle dá ~25min. */
    public int $timeout = 1800;

    public function __construct(
        public readonly string $custId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
    ) {}

    /**
     * Backoff escalonado: 5min, 15min, 30min. A MCP tem janela rate-limit ~1h.
     */
    public function backoff(): array
    {
        return [300, 900, 1800];
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

        Log::info(sprintf(
            '[FetchAdmanMlbs] iniciando full-scan custId=%s range=%s..%s',
            $this->custId, $this->dateFrom, $this->dateTo
        ));

        // Força a varredura completa. O método já cacheia o resultado por 30min
        // com a chave incluindo $maxPages — ou seja, próxima chamada síncrona
        // com o mesmo maxPages pega esse cache.
        $result = $mcp->fetchAllProductAds(
            custId:       $this->custId,
            dateFrom:     $this->dateFrom,
            dateTo:       $this->dateTo,
            itemsPerPage: 50,
            maxPages:     self::MAX_PAGES_FULL_SCAN,
        );

        // Marca status de "varredura concluída" para a UI consultar.
        // Cache key separada, TTL 30min (igual ao cache do resultado).
        Cache::put(
            $this->statusCacheKey(),
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
        Cache::put(
            $this->statusCacheKey(),
            [
                'status'      => 'failed',
                'error'       => $e->getMessage(),
                'completed_at'=> now()->toIso8601String(),
            ],
            now()->addMinutes(30),
        );

        Log::error(sprintf(
            '[FetchAdmanMlbs] falha definitiva custId=%s range=%s..%s: %s',
            $this->custId, $this->dateFrom, $this->dateTo, $e->getMessage(),
        ));
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
