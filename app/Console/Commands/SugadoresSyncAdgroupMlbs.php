<?php

namespace App\Console\Commands;

use App\Jobs\SyncCompanyAdgroupMlbsJob;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 30 Plan 30-04 — Sincroniza MLBs por adgroup pra tabela local.
 *
 * Agendado em routes/console.php pra rodar 1x ao dia às 03h BRT (off-peak total).
 * Pode ser invocado manualmente pra debug:
 *   - php artisan sugadores:sync-adgroup-mlbs --all
 *   - php artisan sugadores:sync-adgroup-mlbs --company=42
 *   - php artisan sugadores:sync-adgroup-mlbs --all --days=15
 */
class SugadoresSyncAdgroupMlbs extends Command
{
    protected $signature = 'sugadores:sync-adgroup-mlbs
                            {--company= : ID específico da Company}
                            {--all : Sincroniza todas as empresas ativas com adman_account_id}
                            {--days=30 : Range temporal em dias retroativos a partir de hoje}';

    protected $description = 'Sincroniza MLBs por adgroup das empresas Adman pra tabela local (Plan 30-04)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days <= 0 || $days > 365) {
            $this->error('--days deve estar entre 1 e 365.');
            return self::FAILURE;
        }

        $periodTo   = now()->toDateString();
        $periodFrom = now()->subDays($days)->toDateString();

        $companyId = $this->option('company');
        $all       = (bool) $this->option('all');

        if (!$companyId && !$all) {
            $this->error('Use --company=ID ou --all.');
            return self::FAILURE;
        }

        $companies = collect();
        if ($companyId) {
            $company = Company::find($companyId);
            if (!$company) {
                $this->error("Empresa #{$companyId} não encontrada.");
                return self::FAILURE;
            }
            $companies->push($company);
        } else {
            $companies = Company::where('active', true)
                ->whereNotNull('adman_account_id')
                ->get();
        }

        $enfileiradas = 0;
        $puladas      = 0;
        foreach ($companies as $company) {
            // Skip defensivo: empresa pode estar marcada Adman mas sem ID válido
            if (empty($company->adman_account_id)) {
                $puladas++;
                continue;
            }
            SyncCompanyAdgroupMlbsJob::dispatch($company, $periodFrom, $periodTo);
            $enfileiradas++;
        }

        $msg = sprintf(
            '[sugadores:sync-adgroup-mlbs] %d empresa(s) enfileirada(s), %d pulada(s) sem adman_account_id (range %s..%s)',
            $enfileiradas, $puladas, $periodFrom, $periodTo,
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }
}
