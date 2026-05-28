<?php

namespace App\Console\Commands;

use App\Jobs\SyncMlCompanyJob;
use App\Models\Company;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use Illuminate\Console\Command;

/**
 * Sincroniza dados diretamente da API ML para todas as empresas
 * que possuem token OAuth ativo. Substitui gradualmente o adman:sync.
 */
class SyncMlData extends Command
{
    protected $signature = 'ml:sync
        {--company= : Sincroniza apenas uma empresa específica (ID)}
        {--date=    : Data específica YYYY-MM-DD (padrão: ontem — ML é D-1)}
        {--from=    : Início do período histórico YYYY-MM-DD}
        {--to=      : Fim do período histórico YYYY-MM-DD}
        {--test     : Testa a conexão sem salvar dados (busca /users/me)}';

    protected $description = 'Sincroniza pedidos e publicidade do Mercado Livre para empresas com OAuth ativo';

    public function handle(MercadoLivreService $ml): int
    {
        // ML publica dados com D-1 — sincroniza ontem por padrão
        $date = $this->option('date') ?? now()->subDay()->toDateString();
        $from = $this->option('from');
        $to   = $this->option('to') ?? $date;

        // Teste de conexão
        if ($this->option('test')) {
            return $this->testConexao($ml);
        }

        $companyId = $this->option('company');

        // Empresa específica — execução síncrona
        if ($companyId) {
            $company = Company::findOrFail($companyId);

            if (! $company->mlToken || $company->mlToken->status !== 'active') {
                $this->error("Empresa {$company->name} não tem token ML ativo.");
                return self::FAILURE;
            }

            $this->info("Sincronizando {$company->name}...");

            try {
                if ($from) {
                    $this->syncHistorico($ml, $company, $from, $to);
                } else {
                    $metric = $ml->syncCompany($company, $date);
                    $this->info("  ✓ revenue=R\${$metric->revenue}, ad_spend=R\${$metric->ad_spend}, tacos={$metric->tacos}%");
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        // Fan-out: todas as empresas com token ML ativo
        $companies = Company::query()
            ->where('active', true)
            ->whereHas('mlToken', fn($q) => $q->where('status', 'active'))
            ->with('mlToken')
            ->get();

        $total = $companies->count();

        if ($total === 0) {
            $this->info('Nenhuma empresa com token ML ativo — nada a enfileirar.');
            $this->line('  Conecte empresas via painel: Empresas → [empresa] → Integração Mercado Livre → Gerar link de conexão');
            return self::SUCCESS;
        }

        $this->info("Enfileirando {$total} empresa(s) para sync ML ({$date})...");

        foreach ($companies as $i => $company) {
            // Delay de 2s entre jobs — ML tem rate limit de 1500 req/min
            SyncMlCompanyJob::dispatch($company, $date)
                ->delay(now()->addSeconds($i * 2));
        }

        $estimadoMin = (int) ceil(($total * 2) / 60);
        $this->info("Enfileirados {$total} SyncMlCompanyJob (delays até " . (($total - 1) * 2) . "s, ~{$estimadoMin}min).");

        return self::SUCCESS;
    }

    private function testConexao(MercadoLivreService $ml): int
    {
        $companies = Company::whereHas('mlToken', fn($q) => $q->where('status', 'active'))
            ->with('mlToken')
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('Nenhuma empresa com token ML ativo.');
            return self::FAILURE;
        }

        $this->info("Testando conexão ML para {$companies->count()} empresa(s):\n");

        foreach ($companies as $company) {
            try {
                $user = $ml->fetchUserInfo($company);
                $this->line("  ✓ {$company->name} → ML user: {$user['nickname']} (ID: {$user['id']})");
            } catch (\Throwable $e) {
                $this->warn("  ✗ {$company->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    private function syncHistorico(MercadoLivreService $ml, Company $company, string $from, string $to): void
    {
        $dates   = [];
        $current = \Carbon\Carbon::parse($from);
        $end     = \Carbon\Carbon::parse($to);

        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        $this->info("Histórico: {$from} → {$to} (" . count($dates) . " dias)");
        $success = 0;
        $failed  = 0;

        foreach ($dates as $date) {
            try {
                $ml->syncCompany($company, $date);
                $success++;
                $this->line("  ✓ {$date}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  ✗ {$date}: {$e->getMessage()}");
            }
        }

        $this->info("Histórico concluído: {$success} sucesso, {$failed} falhas.");
    }
}
