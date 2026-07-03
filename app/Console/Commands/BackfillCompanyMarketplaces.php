<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 57 v13.0 — Backfill idempotente da tabela pivot company_marketplaces.
 *
 * Para cada Company:
 * 1. Cria/atualiza row PRIMARY a partir de:
 *    - marketplace  ← companies.marketplace (ENUM Phase 18.5)
 *    - store_id     ← companies.ml_store_id
 *    - adman_id     ← companies.adman_account_id
 * 2. Cria/atualiza rows EXTRAS a partir de companies.marketplaces_extras
 *    (JSON array, Phase 34/35) — marketplaces adicionais nao-primary.
 *
 * Rerun eh safe — usa updateOrCreate na chave (company_id, marketplace).
 *
 * Uso:
 *   php artisan companies:backfill-marketplaces --dry-run  # so mostra
 *   php artisan companies:backfill-marketplaces            # executa
 *
 * @see .planning/adrs/DATA-01-multi-marketplace-model.md
 */
class BackfillCompanyMarketplaces extends Command
{
    protected $signature = 'companies:backfill-marketplaces
        {--dry-run : So mostra o que faria, sem gravar}';

    protected $description = 'Backfill idempotente da tabela pivot company_marketplaces a partir dos campos legacy';

    private const VALID_MARKETPLACES = ['meli', 'shopee', 'amazon', 'magalu'];

    public function handle(): int
    {
        $dry   = (bool) $this->option('dry-run');
        $total = Company::count();

        $this->info("[Phase57] Backfill company_marketplaces — {$total} empresas" . ($dry ? ' (DRY-RUN)' : ''));

        $stats = [
            'processed'       => 0,
            'primary_created' => 0,
            'primary_updated' => 0,
            'extras_created'  => 0,
            'extras_skipped'  => 0,
            'errors'          => 0,
        ];

        Company::query()->chunkById(200, function ($companies) use ($dry, &$stats) {
            foreach ($companies as $company) {
                try {
                    $this->processCompany($company, $dry, $stats);
                    $stats['processed']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    Log::error("[Phase57] backfill falhou empresa {$company->id}: " . $e->getMessage());
                }
            }
        });

        $this->info('');
        $this->info('[Phase57] Concluido:');
        $this->table(
            ['Metrica', 'Valor'],
            collect($stats)->map(fn($v, $k) => [$k, $v])->values()->toArray()
        );

        return self::SUCCESS;
    }

    private function processCompany(Company $company, bool $dry, array &$stats): void
    {
        // adman_account_id e ml_store_id TEM accessors legacy (Phase 57 —
        // leem da pivot com fallback flat). Aqui no backfill, queremos o
        // valor RAW da coluna flat original — usa getRawOriginal para
        // pular accessors sem depender da estrutura interna do model.
        $primaryMp    = $company->getRawOriginal('marketplace') ?? 'meli';
        $primaryStore = $company->getRawOriginal('ml_store_id');
        $primaryAdman = $company->getRawOriginal('adman_account_id');

        // Sanity: se marketplace ENUM veio invalido, cai no default.
        if (!in_array($primaryMp, self::VALID_MARKETPLACES, true)) {
            Log::warning("[Phase57] marketplace ENUM invalido '{$primaryMp}' na empresa {$company->id}, usando 'meli'");
            $primaryMp = 'meli';
        }

        // 1. Row primary — updateOrCreate garante idempotencia
        if (!$dry) {
            $existed = CompanyMarketplace::where('company_id', $company->id)
                ->where('marketplace', $primaryMp)
                ->exists();

            CompanyMarketplace::updateOrCreate(
                ['company_id' => $company->id, 'marketplace' => $primaryMp],
                [
                    'store_id'          => $primaryStore,
                    'adman_id'          => $primaryAdman,
                    'is_primary'        => true,
                    'active'            => true,
                    'integracao_status' => 'ativa',
                ]
            );

            $existed ? $stats['primary_updated']++ : $stats['primary_created']++;
        } else {
            $stats['primary_created']++;
        }

        // 2. Rows extras — a partir de marketplaces_extras JSON
        // Company tem cast 'marketplaces_extras' => 'array' — usar accessor
        // padrao do Eloquent que aplica o cast (retorna array desserializado).
        // NAO tem accessor legacy (custom), entao acesso direto eh seguro.
        $extras = $company->marketplaces_extras;

        if (!is_array($extras) || empty($extras)) {
            return;
        }

        foreach ($extras as $slug) {
            if (!is_string($slug) || $slug === $primaryMp) {
                $stats['extras_skipped']++;
                continue;
            }

            if (!in_array($slug, self::VALID_MARKETPLACES, true)) {
                $stats['extras_skipped']++;
                Log::warning("[Phase57] marketplace_extras invalido '{$slug}' na empresa {$company->id}");
                continue;
            }

            if (!$dry) {
                CompanyMarketplace::updateOrCreate(
                    ['company_id' => $company->id, 'marketplace' => $slug],
                    [
                        'is_primary'        => false,
                        'active'            => true,
                        'integracao_status' => 'pendente',
                    ]
                );
            }
            $stats['extras_created']++;
        }
    }
}
