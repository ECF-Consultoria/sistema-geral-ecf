<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SugadorAnalysisService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AnalyzeSugadores extends Command
{
    protected $signature = 'sugadores:analyze
        {--company= : Analisa apenas uma empresa específica (ID)}
        {--date=    : Força reference_date (YYYY-MM-DD, padrão: hoje)}
        {--dry-run  : Mostra quem seria flagado sem gravar no banco}';

    protected $description = 'Detecta adgroups (e opcionalmente campanhas) "sugadores" que drenam investimento sem retorno via Adman API';

    public function handle(SugadorAnalysisService $service): int
    {
        $companyId = $this->option('company');
        $dateStr   = $this->option('date');
        $dryRun    = (bool) $this->option('dry-run');

        $referenceDate = $dateStr ? Carbon::parse($dateStr)->startOfDay() : now()->startOfDay();

        if ($dryRun) {
            $this->warn('🧪 DRY RUN — nenhuma linha será gravada no banco.');
        }
        $this->info("Reference date: {$referenceDate->toDateString()}");

        // ─── Análise de uma empresa ──────────────────────────────────────────
        if ($companyId) {
            $company = Company::findOrFail($companyId);
            $this->info("Analisando: {$company->name} (custId: {$company->adman_account_id})");

            try {
                $r = $service->analyzeCompany($company, $referenceDate, $dryRun);
            } catch (\Throwable $e) {
                $this->error("Erro: {$e->getMessage()}");
                return self::FAILURE;
            }

            if ($r['skipped']) {
                $this->warn("Empresa pulada: {$r['reason']}");
                return self::SUCCESS;
            }

            $this->info("✓ {$r['adgroups']} adgroup(s) e {$r['campanhas']} campanha(s) flagado(s).");
            $this->printDetalhes($r['detalhes']);
            return self::SUCCESS;
        }

        // ─── Análise global ─────────────────────────────────────────────────
        $this->info('Iniciando análise de todas as empresas com config ativa...');
        $start  = microtime(true);
        $totals = $service->analyzeAll($referenceDate, $dryRun);
        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Empresas analisadas', $totals['companies_analyzed']],
                ['Empresas puladas',    $totals['companies_skipped']],
                ['Empresas com falha',  $totals['companies_failed']],
                ['Adgroups flagados',   $totals['adgroups_flagados']],
                ['Campanhas flagadas',  $totals['campanhas_flagadas']],
            ]
        );

        $this->info("✓ Concluído em {$elapsed}s.");
        return $totals['companies_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function printDetalhes(array $detalhes): void
    {
        if (empty($detalhes)) {
            $this->line('Nenhum sugador detectado.');
            return;
        }

        $this->newLine();
        $rows = array_map(fn($d) => [
            $d['tipo'],
            $d['nome'] ?? ($d['adgroup_id'] ?? $d['campaign_id']),
            'R$ ' . number_format((float) ($d['investimento'] ?? 0), 2, ',', '.'),
            (int) ($d['vendas'] ?? 0),
            implode(', ', $d['motivos'] ?? []),
        ], $detalhes);

        $this->table(['Tipo', 'Nome / ID', 'Investimento', 'Vendas', 'Motivos'], $rows);
    }
}
