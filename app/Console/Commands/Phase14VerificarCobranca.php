<?php

namespace App\Console\Commands;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Support\CobrancaCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase14VerificarCobranca — pre-flight do drop das colunas legacy.
 *
 * Itera todas as empresas comparando o cálculo legacy (faixa + additional_service_price)
 * contra o cálculo novo (faixa + SUM contratos mensais ativos). Se houver divergência
 * > R$ 0,01 e a flag `--abort-on-divergence` estiver ativa, retorna exit 1 — o que
 * impede a migration 3 (drop das colunas) em pipelines que validam exit code.
 *
 * Sequência de execução em produção (Plan 14-06):
 *   1. migrate seed_servicos_catalog (popula catálogo)
 *   2. migrate migrate_legacy_service_data (cria contratos derivados)
 *   3. >>> php artisan phase14:verificar-cobranca --abort-on-divergence  ← CHECKPOINT
 *   4. migrate drop_legacy_service_columns_from_companies
 *
 * Per CONTEXT.md D-08/D-10 e RESEARCH §4.
 */
class Phase14VerificarCobranca extends Command
{
    protected $signature = 'phase14:verificar-cobranca {--abort-on-divergence : Aborta com exit code 1 se houver divergência}';

    protected $description = 'Phase 14: compara cálculo de cobrança legacy vs novo (contratos_servico) em todas as empresas. Roda ANTES do drop das colunas legacy (Plan 14-06).';

    /**
     * Tabela de progressão de FAIXAS — duplicada verbatim de
     * AdminController::FAIXAS (linhas 22-29). Duplicação intencional e
     * temporária: este comando é deletado após a conclusão da Phase 14, então
     * não há valor em extrair um service compartilhado para ele.
     */
    private const FAIXAS = [
        ['limite' => 499_999.99,   'label' => 'faixa_1', 'valor' => 3_000.00],
        ['limite' => 999_999.99,   'label' => 'faixa_2', 'valor' => 4_500.00],
        ['limite' => 1_999_999.99, 'label' => 'faixa_3', 'valor' => 6_000.00],
        ['limite' => 2_999_999.99, 'label' => 'faixa_4', 'valor' => 7_500.00],
        ['limite' => 3_999_999.99, 'label' => 'faixa_5', 'valor' => 9_000.00],
        ['limite' => 4_999_999.99, 'label' => 'faixa_6', 'valor' => 10_500.00],
    ];

    public function handle(): int
    {
        $divergencias = 0;
        $total        = Company::count();

        $this->info("[Phase14] Verificando cobrança em {$total} empresa(s)...");

        // Pitfall 2 do RESEARCH: eager loading obrigatório para evitar N+1.
        Company::with(['contratosServico' => fn($q) => $q->where('ativo', true)->with('servico')])
            ->chunk(100, function ($companies) use (&$divergencias) {
                foreach ($companies as $company) {
                    if ($this->verificarEmpresa($company)) {
                        $divergencias++;
                    }
                }
            });

        if ($divergencias > 0) {
            $this->error("[Phase14] {$divergencias} empresa(s) com divergência — corrigir antes de prosseguir.");

            return $this->option('abort-on-divergence') ? 1 : 0;
        }

        $this->info("[Phase14] Todas as {$total} empresas conferem (0 divergências).");

        return 0;
    }

    /**
     * Verifica uma empresa individualmente. Retorna true se houver divergência.
     */
    private function verificarEmpresa(Company $company): bool
    {
        $faturamento = $this->faturamentoDoMes($company->id);
        $faixaData   = $this->calcularFaixa($faturamento);

        $legacy = CobrancaCalculator::legacy(
            $faixaData,
            (float) ($company->additional_service_price ?? 0),
        );

        $novo = CobrancaCalculator::novo($faixaData, $company->contratosServico);

        // Tolerância de R$ 0,01 — evita falsos positivos de arredondamento decimal.
        if (abs($legacy - $novo) > 0.01) {
            $msg = sprintf(
                '[Phase14] cobranca divergente empresa #%d (%s): legacy=R$ %s novo=R$ %s',
                $company->id,
                $company->name,
                number_format($legacy, 2, ',', '.'),
                number_format($novo, 2, ',', '.'),
            );

            Log::error($msg);
            $this->error($msg);

            return true;
        }

        return false;
    }

    /**
     * Soma o faturamento Adman dos últimos 30 dias para a empresa.
     * Retorna null se a empresa não tem métricas no período (estado "sem_dados").
     */
    private function faturamentoDoMes(int $companyId): ?float
    {
        $sum = AdmanMetric::whereBetween('reference_date', [
            Carbon::now()->subDays(30),
            Carbon::now(),
        ])
            ->whereNotNull('revenue')
            ->where('company_id', $companyId)
            ->sum('revenue');

        return $sum > 0 ? (float) $sum : null;
    }

    /**
     * Calcula a faixa de cobrança para um dado faturamento.
     * Retorna null se faturamento for null (empresa sem dados).
     *
     * @return array{faixa: string, valor: float}|null
     */
    private function calcularFaixa(?float $faturamento): ?array
    {
        if ($faturamento === null) {
            return null;
        }

        foreach (self::FAIXAS as $faixa) {
            if ($faturamento <= $faixa['limite']) {
                return ['faixa' => $faixa['label'], 'valor' => (float) $faixa['valor']];
            }
        }

        return ['faixa' => 'maxima', 'valor' => 12_000.00];
    }
}
