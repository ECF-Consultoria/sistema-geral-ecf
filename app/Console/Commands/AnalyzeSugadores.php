<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\SugadorAnalysisService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AnalyzeSugadores extends Command
{
    protected $signature = 'sugadores:analyze
        {--company=  : Analisa apenas uma empresa específica (ID)}
        {--date=     : Força reference_date (YYYY-MM-DD, padrão: hoje)}
        {--dry-run   : Mostra quem seria flagado sem gravar no banco}
        {--provider= : Força provider de dados (adman|ml). Default = capability detection. ml sem --dry-run aborta (Phase 39 — gravação ML disponível em Phase 42).}';

    protected $description = 'Detecta adgroups (e opcionalmente campanhas) "sugadores" que drenam investimento sem retorno via provider de anúncios (Adman ou Mercado Livre)';

    public function handle(SugadorAnalysisService $service): int
    {
        $companyId = $this->option('company');
        $dateStr   = $this->option('date');
        $dryRun    = (bool) $this->option('dry-run');
        $provider  = $this->option('provider');

        // ─── Validação da flag --provider (Phase 39 Plan 39-05) ───────────────
        // Whitelist: apenas 'adman' ou 'ml' são aceitos. Qualquer outra string
        // é rejeitada com mensagem clara (T-39-05-02: provider value injection).
        if ($provider !== null && !\in_array($provider, ['adman', 'ml'], true)) {
            $this->error("Provider inválido: '{$provider}'. Valores aceitos: adman, ml");
            return self::FAILURE;
        }

        // Guard ml_primary: até Phase 42, gravar via path ML é proibido.
        // --provider=ml só é permitido em conjunto com --dry-run (T-39-05-01:
        // gravação acidental ML path).
        if ($provider === 'ml' && !$dryRun) {
            $this->error('Modo ml_primary só disponível em Phase 42 — use --dry-run para testar leitura sem gravação.');
            return self::FAILURE;
        }

        $referenceDate = $dateStr ? Carbon::parse($dateStr)->startOfDay() : now()->startOfDay();

        if ($dryRun) {
            $this->warn('🧪 DRY RUN — nenhuma linha será gravada no banco.');
        }
        $this->info("Reference date: {$referenceDate->toDateString()}");
        if ($provider !== null) {
            $this->info("Provider forçado: {$provider}");
        }

        // Aviso quando --provider é passado sem --company:
        // analyzeAll não propaga $forceProvider (cada empresa cai no factory
        // por capability detection). Documentamos o no-op para evitar confusão.
        if ($provider !== null && !$companyId) {
            $this->warn('--provider só tem efeito com --company (path global usa capability detection por empresa).');
        }

        // ─── Análise de uma empresa ──────────────────────────────────────────
        if ($companyId) {
            $company = Company::findOrFail($companyId);
            $this->info("Analisando: {$company->name} (custId: {$company->adman_account_id})");

            try {
                // Phase 39 Plan 39-05: 4º param $forceProvider propaga para
                // SugadorAnalysisService → SugadoresAdsProviderFactory::for().
                // Default null = factory escolhe via capability detection (Adman
                // preferido quando ambos suportam, regra travada até Phase 42).
                $r = $service->analyzeCompany($company, $referenceDate, $dryRun, $provider);
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
