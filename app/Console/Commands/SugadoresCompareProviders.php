<?php

namespace App\Console\Commands;

use App\Services\Sugadores\ProviderComparisonService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Phase 40 Plan 40-04 — Imprime relatório de paridade Adman vs ML para uma
 * empresa em uma janela de datas. Consome ProviderComparisonService::compareWindow
 * (Plan 40-03).
 *
 * Formato:
 *   --format=table (default) → tabela legível pt-BR + status APROVADA/REPROVADA
 *   --format=json            → JSON parseável (machine-readable / CI)
 *
 * Exit code:
 *   0 → paridade_motivos_pct >= 95.0 (alvo §7 plano-migracao)
 *   1 → paridade_motivos_pct < 95.0 OU erro de validação de argumento/data/format
 */
class SugadoresCompareProviders extends Command
{
    protected $signature = 'sugadores:compare-providers
        {--company= : ID da empresa (obrigatório)}
        {--from=    : Data inicial YYYY-MM-DD (obrigatório)}
        {--to=      : Data final YYYY-MM-DD (obrigatório)}
        {--format=table : Formato de saída — "table" ou "json"}';

    protected $description = 'Phase 40 — imprime relatório de paridade Adman vs ML para uma empresa numa janela de datas; exit 1 se paridade < 95%';

    public function __construct(private ProviderComparisonService $comparison)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $companyId = $this->option('company');
        $from      = $this->option('from');
        $to        = $this->option('to');
        $format    = $this->option('format');

        // ─── Validações de argumentos obrigatórios ──────────────────────────
        if ($companyId === null || $companyId === '') {
            $this->error('Parâmetro --company obrigatório (ID numérico da empresa).');
            return self::FAILURE;
        }
        if (!ctype_digit((string) $companyId)) {
            $this->error("--company inválido: '{$companyId}'. Use ID numérico.");
            return self::FAILURE;
        }
        if ($from === null || $from === '') {
            $this->error('Parâmetro --from obrigatório (data YYYY-MM-DD).');
            return self::FAILURE;
        }
        if ($to === null || $to === '') {
            $this->error('Parâmetro --to obrigatório (data YYYY-MM-DD).');
            return self::FAILURE;
        }

        if (!in_array($format, ['table', 'json'], true)) {
            $this->error("--format inválido: '{$format}'. Use 'table' ou 'json'.");
            return self::FAILURE;
        }

        // ─── Parse das datas com try/catch ──────────────────────────────────
        try {
            $fromCarbon = Carbon::parse((string) $from);
            $toCarbon   = Carbon::parse((string) $to);
        } catch (\Throwable $e) {
            $this->error("Data inválida: " . $e->getMessage());
            return self::FAILURE;
        }

        // ─── Executa comparação ─────────────────────────────────────────────
        $report = $this->comparison->compareWindow((int) $companyId, $fromCarbon, $toCarbon);

        // ─── Render conforme formato ────────────────────────────────────────
        if ($format === 'json') {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Empresa #{$companyId} | Período {$from} → {$to}");
            $this->table(
                ['Bucket', 'Count'],
                [
                    ['Coincidências (matched)', $report['matched']],
                    ['Métricas divergentes',    $report['metrics_diff']],
                    ['Motivos divergentes',     $report['motivo_diff']],
                    ['Apenas Adman',            $report['apenas_adman']],
                    ['Apenas ML',               $report['apenas_ml']],
                    ['Quarentena divergente',   $report['quarentena_diff']],
                    ['TOTAL',                   $report['total_items']],
                ]
            );
            $pct = number_format((float) $report['paridade_motivos_pct'], 2, ',', '.');
            if ((float) $report['paridade_motivos_pct'] >= 95.0) {
                $this->info("Paridade de motivos: {$pct}% (>=95% — APROVADA)");
            } else {
                $this->error("Paridade de motivos: {$pct}% (<95% — REPROVADA)");
            }
        }

        return ((float) $report['paridade_motivos_pct']) >= 95.0 ? self::SUCCESS : self::FAILURE;
    }
}
