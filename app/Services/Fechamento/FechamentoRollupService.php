<?php

namespace App\Services\Fechamento;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Services\Metrics\MetricPeriodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FechamentoRollupService — responde "quanto uma empresa faturou nesta
 * competência?" (Fase 137, D-05, D-06, D-07).
 *
 * D-06 mata o acumulativo: toda competência é mês-calendário FECHADO — mês
 * corrente vai do dia 1 até hoje, mês passado é o mês inteiro. Nunca a
 * janela móvel retroativa de 30 dias (a que este serviço substitui em
 * `AdminController::fechamento()`).
 *
 * D-05 soma ML (`adman_metrics`) + Shopee (`shopee_metrics`) numa faixa
 * única. D-07 garante que Shopee entra no fechamento mesmo sem ML.
 *
 * Não usa o serviço de diff HTTP-first do módulo de bônus (orientado a
 * variação percentual — chamada de rede desnecessária todo dia 1) nem a
 * tabela de faturamento mensal legada de ML (fica com o valor rolling do
 * momento em que o mês virou, nunca re-sincronizado — ver
 * 137-RESEARCH.md §1). Fonte é a soma direta do faturamento diário de
 * `adman_metrics`, mesmo padrão que `AdminController::fechamento()` já usa
 * para mês passado.
 */
class FechamentoRollupService
{
    public function __construct(private MetricPeriodResolver $periodResolver)
    {
    }

    /**
     * Janela de mês-calendário fechado para `$mes` ('YYYY-MM'). Delega a
     * `MetricPeriodResolver` — ÚNICA implementação de D-06, nunca reescrever
     * Carbon à mão.
     *
     * @return array{inicio: Carbon, fim: Carbon}
     */
    public function janela(string $mes): array
    {
        // Mês corrente usa 'current_month' (current_end = hoje, clampado —
        // nunca 30 dias para trás). Mês fechado usa o próprio 'YYYY-MM'
        // (current_end = fim do mês calendário completo).
        $periodKey = $mes === Carbon::now()->format('Y-m') ? 'current_month' : $mes;

        $periodo = $this->periodResolver->resolve(['period_key' => $periodKey]);

        return [
            // NUNCA Carbon::createFromFormat('Y-m', ...) sem o dia — sem o
            // dia explícito o PHP preenche com o dia de hoje e estoura para
            // o mês seguinte quando o mês alvo tem menos dias (armadilha
            // documentada em ConsolidarMesDesempenho.php linhas 118-127).
            // Aqui não se aplica o risco: current_start/current_end já vêm
            // como string 'Y-m-d' completa do resolver.
            'inicio' => Carbon::createFromFormat('Y-m-d', $periodo['current_start'])->startOfDay(),
            'fim'    => Carbon::createFromFormat('Y-m-d', $periodo['current_end'])->startOfDay(),
        ];
    }

    /**
     * Faturamento ML + Shopee por empresa, na janela de mês-calendário de
     * `$mes`. Duas queries agregadas (nunca N+1).
     *
     * Quando `$companies` é informado, o resultado tem UMA entrada por
     * empresa da coleção — mesmo as sem nenhuma métrica no mês (com as três
     * chaves nulas: ausência é estado distinto de "faturou zero", nunca
     * 0.0). Quando `$companies` é omitido, o resultado só contém empresas
     * com pelo menos uma linha de métrica no mês.
     *
     * @param  Collection<int, Company>|null  $companies
     * @return array<int, array{faturamento_ml: float|null, faturamento_shopee: float|null, faturamento_total: float|null}>
     */
    public function porEmpresa(string $mes, ?Collection $companies = null): array
    {
        $janela = $this->janela($mes);
        $inicio = $janela['inicio'];
        $fim    = $janela['fim'];

        $mlQuery = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id');

        // whereDate (não whereBetween): reference_date é persistido como
        // datetime ('Y-m-d 00:00:00'); whereBetween puro compararia como
        // STRING contra a borda 'Y-m-d' e excluiria o último dia do mês.
        $shopeeQuery = ShopeeMetric::whereDate('reference_date', '>=', $inicio->toDateString())
            ->whereDate('reference_date', '<=', $fim->toDateString())
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id');

        if ($companies !== null) {
            $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->values();
            $mlQuery->whereIn('company_id', $companyIds);
            $shopeeQuery->whereIn('company_id', $companyIds);
        }

        $porEmpresaMl     = $mlQuery->get()->keyBy('company_id');
        $porEmpresaShopee = $shopeeQuery->get()->keyBy('company_id');

        $idsParaMontar = $companies !== null
            ? $companies->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()
            : $porEmpresaMl->keys()->merge($porEmpresaShopee->keys())->map(fn ($id) => (int) $id)->unique()->values();

        $resultado = [];

        foreach ($idsParaMontar as $companyId) {
            $faturamentoMl     = $porEmpresaMl->has($companyId) ? (float) $porEmpresaMl[$companyId]->faturamento : null;
            $faturamentoShopee = $porEmpresaShopee->has($companyId) ? (float) $porEmpresaShopee[$companyId]->faturamento : null;

            // "Sem faturamento" e "faturou zero" são estados diferentes —
            // total só existe quando pelo menos um dos dois lados existe.
            $faturamentoTotal = ($faturamentoMl !== null || $faturamentoShopee !== null)
                ? ($faturamentoMl ?? 0.0) + ($faturamentoShopee ?? 0.0)
                : null;

            $resultado[$companyId] = [
                'faturamento_ml'     => $faturamentoMl,
                'faturamento_shopee' => $faturamentoShopee,
                'faturamento_total'  => $faturamentoTotal,
            ];
        }

        return $resultado;
    }
}
