<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\AdmanAdgroupMlbsRepository;
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
 * Phase 30 Plan 30-04 — Sync de MLBs por adgroup pra UMA empresa.
 *
 * Substitui o pattern legacy do FetchAdmanMlbsByCampaignJob: em vez de
 * varrer a Adman MCP em tempo de request (5-25min), este Job popula a
 * tabela local adman_adgroup_mlbs off-peak (03h BRT) ou sob demanda
 * (botão "Forçar atualização" no Sugadores/Show.jsx).
 *
 * Reusa middleware RateLimited('adman-api') do Plan 30-01 (W1) — quando
 * o bucket global esgotar, Job fica em delayed sem falhar.
 */
class SyncCompanyAdgroupMlbsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** 30min — varredura full de 500 páginas com throttle 8/min dá ~62min worst-case, mas a maioria das contas <500 páginas */
    public int $timeout = 1800;

    public function __construct(
        public readonly Company $company,
        public readonly string $periodFrom,
        public readonly string $periodTo,
    ) {}

    /**
     * Backoff escalonado: 5min, 15min, 30min.
     * Mais agressivo que o FetchAdmanMlbsByCampaignJob (10min, 30min, 1h)
     * porque o sync agendado nunca está urgente — pode esperar próximo 03h.
     */
    public function backoff(): array
    {
        return [300, 900, 1800];
    }

    /**
     * Chave de unicidade: company_id + range. Evita duplicar Jobs do mesmo
     * sync (ex: scheduler 03h + clique humano em "Forçar atualização").
     */
    public function uniqueId(): string
    {
        return "{$this->company->id}_{$this->periodFrom}_{$this->periodTo}";
    }

    /** TTL do lock — libera após 30min mesmo se Job sumir */
    public function uniqueFor(): int
    {
        return 1800;
    }

    /**
     * Phase 30 D-01 — Aplica throttle global Adman 'adman-api' (8/min).
     * Workers compartilham bucket via Redis. Bucket cheio = Job delayed.
     */
    public function middleware(): array
    {
        return [new RateLimited('adman-api')];
    }

    public function handle(AdmanMcpService $mcp, AdmanAdgroupMlbsRepository $repo): void
    {
        $started   = microtime(true);
        $statusKey = $this->statusCacheKey();

        // Empresa sem adman_account_id: log warning e return — não é erro nosso.
        // ML-only deve cair no path Plan 30-02 (W2), não aqui.
        if (empty($this->company->adman_account_id)) {
            Log::warning("[SyncAdgroupMlbs] empresa {$this->company->id} ({$this->company->name}) sem adman_account_id — pulada");
            return;
        }

        $custId = (string) $this->company->adman_account_id;

        Cache::put($statusKey, [
            'status'     => 'running',
            'started_at' => now()->toIso8601String(),
        ], now()->addMinutes(35));

        Log::info(sprintf(
            '[SyncAdgroupMlbs] iniciando empresa=%d (%s) cust=%s range=%s..%s',
            $this->company->id, $this->company->name, $custId, $this->periodFrom, $this->periodTo,
        ));

        // Chama o service do W1. maxPages=500 cobre quase qualquer conta.
        $result = $mcp->fetchAllProductAds(
            custId:       $custId,
            dateFrom:     $this->periodFrom,
            dateTo:       $this->periodTo,
            itemsPerPage: 50,
            maxPages:     500,
        );

        // Fix D do Plan 30-01 — Service retorna rate_limited=true quando o bucket
        // estourou no meio da paginação. Não é erro: Job termina sem falhar e o
        // próximo sync (03h ou clique no botão) retoma. Mantemos o que foi
        // coletado até ali na tabela (próximo sync sobrescreve com fresh).
        $rateLimited = (bool) ($result['rate_limited'] ?? false);
        $items       = $result['items'] ?? [];
        $pagesRead   = (int) ($result['pages_read']  ?? 0);
        $totalPages  = (int) ($result['total_pages'] ?? 0);

        // Transforma productAds da Adman em rows da tabela e faz upsert em batch.
        $rows = [];
        foreach ($items as $ad) {
            // listingId é obrigatório — sem ele a row não faz sentido
            $mlbId = (string) ($ad['listingId'] ?? '');
            if ($mlbId === '') continue;

            $rows[] = [
                'cust_id'        => $custId,
                'adgroup_id'     => isset($ad['adgroupId']) ? (string) $ad['adgroupId'] : null,
                'adgroup_name'   => $ad['adgroupName']   ?? null,
                'campaign_id'    => isset($ad['campaignId']) ? (string) $ad['campaignId'] : null,
                'campaign_name'  => $ad['campaignName']  ?? null,
                'mlb_id'         => $mlbId,
                'title'          => isset($ad['title']) ? mb_substr((string) $ad['title'], 0, 500) : null,
                'status'         => $ad['status']        ?? null,
                'period_from'    => $this->periodFrom,
                'period_to'      => $this->periodTo,
                'metrics'        => json_encode($ad),  // raw — UI escolhe os campos relevantes
                'last_synced_at' => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ];
        }

        // Upsert em chunks de 500 — evita payload SQL gigante em contas com 10k+ items
        $totalUpserted = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            $totalUpserted += $repo->upsertBatch($chunk);
        }

        Cache::put($statusKey, [
            'status'        => $rateLimited ? 'partial' : 'ready',
            'mlbs_count'    => count($rows),
            'pages_read'    => $pagesRead,
            'total_pages'   => $totalPages,
            'rate_limited'  => $rateLimited,
            'completed_at'  => now()->toIso8601String(),
        ], now()->addMinutes(35));

        $elapsed = round(microtime(true) - $started, 1);
        Log::info(sprintf(
            '[SyncAdgroupMlbs] %s empresa=%d cust=%s mlbs=%d pages=%d/%d elapsed=%ss',
            $rateLimited ? 'parcial-rate-limit' : 'concluído',
            $this->company->id, $custId, count($rows), $pagesRead, $totalPages, $elapsed,
        ));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put($this->statusCacheKey(), [
            'status'       => 'failed',
            'error'        => $e->getMessage(),
            'completed_at' => now()->toIso8601String(),
        ], now()->addMinutes(35));

        Log::error(sprintf(
            '[SyncAdgroupMlbs] falha definitiva empresa=%d cust=%s: %s',
            $this->company->id, $this->company->adman_account_id, $e->getMessage(),
        ), ['exception' => $e]);
    }

    /**
     * Chave de status pro UI mostrar progresso do "Forçar atualização".
     */
    public function statusCacheKey(): string
    {
        return "adman_adgroup_mlbs:sync:{$this->company->id}:{$this->periodFrom}:{$this->periodTo}";
    }

    public static function statusCacheKeyFor(int $companyId, string $periodFrom, string $periodTo): string
    {
        return "adman_adgroup_mlbs:sync:{$companyId}:{$periodFrom}:{$periodTo}";
    }
}
