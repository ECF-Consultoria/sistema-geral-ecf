<?php

namespace App\Console\Commands;

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

    public function handle(\App\Services\AdmanService $adman): int
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

        // Sincroniza empresa específica
        if ($companyId) {
            $company = \App\Models\Company::findOrFail($companyId);
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

        // Sincroniza todas as empresas
        $this->info('Iniciando sincronização Adman (' . now()->format('H:i:s') . ')...');
        $results = $adman->syncAll();

        $this->table(
            ['Sucesso', 'Falha', 'Pulado'],
            [[$results['success'], $results['failed'], $results['skipped']]]
        );

        $this->info('Sincronização concluída em ' . now()->format('H:i:s'));
        return self::SUCCESS;
    }
}
