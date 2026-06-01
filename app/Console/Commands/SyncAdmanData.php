<?php

namespace App\Console\Commands;

use App\Jobs\SyncAdmanCompanyJob;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Console\Command;

class SyncAdmanData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'adman:sync
        {--company= : Sincroniza apenas uma empresa específica (ID)}
        {--date= : Data específica YYYY-MM-DD (padrão: hoje)}
        {--from= : Início do período histórico YYYY-MM-DD}
        {--to= : Fim do período histórico YYYY-MM-DD}
        {--list-accounts : Lista as contas disponíveis na Adman}';

    protected $description = 'Sincroniza dados da API Adman para todas as empresas ativas';

    public function handle(AdmanService $adman): int
    {
        // Listar contas da Adman
        if ($this->option('list-accounts')) {
            $this->info('Buscando contas na Adman...');
            try {
                $data = $adman->listAccounts();
                $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        $companyId = $this->option('company');
        $date      = $this->option('date') ?? now()->toDateString();
        $from      = $this->option('from');
        $to        = $this->option('to') ?? $date;

        // Sincroniza empresa específica (síncrono — 1 empresa só, sem risco de rate limit)
        if ($companyId) {
            $company = Company::findOrFail($companyId);
            $this->info("Sincronizando {$company->name} (custId: {$company->adman_account_id})...");

            try {
                if ($from) {
                    $results = $adman->syncHistorical($company, $from, $to);
                    $this->info("Histórico concluído: {$results['success']} datas com sucesso, {$results['failed']} falhas.");
                } else {
                    $metric = $adman->syncCompany($company, $date);
                    $this->info("Sincronizado para {$date}: TACOS={$metric->tacos}%, Faturamento=R\${$metric->revenue}");
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }
            return self::SUCCESS;
        }

        // Fan-out com delay incremental de 7s (AdmanService::ADMAN_RATE_LIMIT_RPM = 10 rpm
        // → 60s/10 = 6s teórico + 1s de folga). Respeita o limite global mesmo com
        // numprocs=2 no Supervisor: apenas 1 job fica "ready" a cada 7s, independente
        // do número de workers. Substitui o $adman->syncAll() síncrono usado anteriormente,
        // que dependia do throttle interno do processo (não cobria paralelismo do worker).
        $this->info('Iniciando fan-out Adman (' . now()->format('H:i:s') . ')...');

        $companies = Company::query()
            ->where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) { $q2->whereNotNull('ml_store_id')->where('ml_store_id', '!=', ''); })
                  ->orWhere(function ($q2) { $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', ''); });
            })
            // Cutover Adman → ML (Opção A): empresas com token ML ativo são
            // sincronizadas só pelo ml:sync. Sem isso, adman:sync (11:00) e
            // ml:sync (11:05) gravavam a MESMA linha adman_metrics e o ML
            // sobrescrevia — gerando linhas mistas (revenue ML + margem Adman).
            ->whereDoesntHave('mlToken', fn($q) => $q->where('status', 'active'))
            ->get();

        $total = $companies->count();
        if ($total === 0) {
            $this->info('Nenhuma empresa ativa com ID Adman/ML — nada a enfileirar.');
            return self::SUCCESS;
        }

        foreach ($companies as $i => $company) {
            SyncAdmanCompanyJob::dispatch($company)
                ->delay(now()->addSeconds($i * 7));
        }

        $estimadoMin = (int) ceil(($total * 7) / 60);
        $this->info("Enfileirados {$total} SyncAdmanCompanyJob com delays de 0s..." . (($total - 1) * 7) . "s (~{$estimadoMin}min até o último processar).");

        return self::SUCCESS;
    }
}
