<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Smoke diagnóstico de adman_metrics para a empresa Bymobille (company_id=298).
 *
 * Phase 45 Plan 45-01 — gate decision para Plans 45-02/03 (MlMetricsProvider).
 * Roda em produção via `php artisan dev:bymobille-smoke` para confirmar se
 * company_id=298 tem cobertura em adman_metrics ou apenas em ml_tokens.
 *
 * Saídas:
 *   - Console: tabela estruturada com resultado das 3 consultas (A, B, C)
 *   - Console: decisão explícita "NECESSÁRIAS" ou "NÃO NECESSÁRIAS" para Plans 45-02/03
 *
 * NÃO toca: nenhuma tabela de produção — é 100% leitura (SELECT only).
 * NÃO registrado no scheduler — comando manual de diagnóstico.
 */
class BymobilleSmoke extends Command
{
    protected $signature = 'dev:bymobille-smoke
        {--company=298 : ID numérico da empresa (default: Bymobille)}
        {--dias=30 : Janela recente em dias para consulta B (default: 30)}';

    protected $description = 'Diagnóstico Phase 45: verifica cobertura de adman_metrics para Bymobille — gate decision para Plans 45-02/03';

    public function handle(): int
    {
        $companyId = (int) $this->option('company');
        $dias      = max(1, min(365, (int) $this->option('dias')));

        $this->newLine();
        $this->info("[Phase 45 Smoke] company_id={$companyId}, janela_recente={$dias}d");
        $this->newLine();

        // ─── Consulta A — presença/intervalo em adman_metrics ────────────────
        $this->line('=== A — adman_metrics WHERE company_id=' . $companyId . ' ===');

        $resultA = DB::selectOne(
            'SELECT COUNT(*) as total, MIN(reference_date) as desde, MAX(reference_date) as ate
             FROM adman_metrics
             WHERE company_id = ?',
            [$companyId],
        );

        $totalA = $resultA ? (int) $resultA->total : 0;
        $desdeA = $resultA?->desde ?? 'N/A';
        $ateA   = $resultA?->ate   ?? 'N/A';

        $this->table(
            ['total', 'desde (MIN)', 'até (MAX)'],
            [[$totalA, $desdeA, $ateA]],
        );

        // ─── Consulta B — métricas recentes ──────────────────────────────────
        $this->newLine();
        $this->line("=== B — adman_metrics últimos {$dias}d (company_id={$companyId}) ===");

        $resultB = DB::selectOne(
            'SELECT
                COUNT(*) as registros,
                COALESCE(SUM(revenue), 0) as revenue_total,
                COALESCE(SUM(ad_spend), 0) as ad_spend_total,
                MIN(reference_date) as desde,
                MAX(reference_date) as ate
             FROM adman_metrics
             WHERE company_id = ?
               AND reference_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)',
            [$companyId, $dias],
        );

        $registrosB = $resultB ? (int) $resultB->registros : 0;
        $revenueB   = $resultB ? number_format((float) $resultB->revenue_total, 2, ',', '.') : '0,00';
        $adSpendB   = $resultB ? number_format((float) $resultB->ad_spend_total, 2, ',', '.') : '0,00';
        $desdeB     = $resultB?->desde ?? 'N/A';
        $ateB       = $resultB?->ate   ?? 'N/A';

        $this->table(
            ['registros', 'revenue_total', 'ad_spend_total', 'desde', 'até'],
            [[$registrosB, "R$ {$revenueB}", "R$ {$adSpendB}", $desdeB, $ateB]],
        );

        // ─── Consulta C — identifiers da empresa ─────────────────────────────
        $this->newLine();
        $this->line("=== C — companies WHERE id={$companyId} ===");

        $empresa = Company::find($companyId);

        if ($empresa === null) {
            $this->error("Empresa id={$companyId} não encontrada na base de dados.");
            return self::FAILURE;
        }

        $this->table(
            ['id', 'name', 'adman_account_id', 'ml_store_id', 'active'],
            [[
                $empresa->id,
                $empresa->name,
                $empresa->adman_account_id ?? 'NULL',
                $empresa->ml_store_id      ?? 'NULL',
                $empresa->active ? 'sim' : 'não',
            ]],
        );

        // ─── Consulta D — status do ml_token ────────────────────────────────
        $this->newLine();
        $this->line("=== D — ml_tokens WHERE company_id={$companyId} ===");

        $token = DB::selectOne(
            'SELECT status, expires_at FROM ml_tokens WHERE company_id = ? ORDER BY id DESC LIMIT 1',
            [$companyId],
        );

        if ($token) {
            $this->table(
                ['status', 'expires_at'],
                [[$token->status, $token->expires_at ?? 'N/A']],
            );
        } else {
            $this->warn("Nenhum ml_token encontrado para company_id={$companyId}.");
        }

        // ─── Decisão gate ────────────────────────────────────────────────────
        $this->newLine();
        $this->line('=== DECISÃO GATE — Plans 45-02/03 ===');
        $this->newLine();

        // Critério: total > 0 em adman_metrics E pelo menos 1 registro nos últimos 60d
        $temDadosAdman     = $totalA > 0;
        $temDadosRecentes  = $registrosB > 0;
        $semAdmanAccountId = empty($empresa->adman_account_id);

        if ($temDadosAdman && $temDadosRecentes) {
            $this->info('DECISAO: Plans 45-02/03 — NAO NECESSARIAS');
            $this->line("  Justificativa: Bymobille tem {$totalA} registros em adman_metrics");
            $this->line("  com {$registrosB} recentes (últimos {$dias}d).");
            $this->line('  O gap de score pode ser outro (ex: cust_id mismatch, erro de join).');
            $this->line('  Investigar PortfolioScoreService e query de join antes de criar provider ML.');
        } elseif ($temDadosAdman && !$temDadosRecentes) {
            $this->warn('DECISAO: Plans 45-02/03 — NECESSARIAS (dados muito antigos)');
            $this->line("  Justificativa: Bymobille tem {$totalA} registros em adman_metrics,");
            $this->line("  mas NENHUM nos últimos {$dias}d (último: {$ateA}).");
            $this->line('  Sync Adman não está cobrindo esta empresa — MlMetricsProvider resolve.');
        } else {
            $this->error('DECISAO: Plans 45-02/03 — NECESSARIAS');
            $this->line("  Justificativa: Bymobille tem total={$totalA} em adman_metrics.");
            if ($semAdmanAccountId) {
                $this->line('  adman_account_id é NULL — empresa é ML-only, nunca terá dados Adman.');
            }
            $this->line('  MlMetricsProvider (Plan 45-02/03) é necessário para cobertura desta empresa.');
        }

        $this->newLine();
        $this->line('Rode este comando em produção: php artisan dev:bymobille-smoke');
        $this->newLine();

        return self::SUCCESS;
    }
}
