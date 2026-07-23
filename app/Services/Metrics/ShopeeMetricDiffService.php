<?php

namespace App\Services\Metrics;

use App\Models\Company;
use App\Models\ShopeeMetric;
use Illuminate\Support\Facades\Cache;

/**
 * Fase 109 (v?.0) — irmão Shopee do `AdmanMetricDiffService`, espelhando o
 * MESMO contrato de retorno (`company_id`/`period`/`metrics`/`quality`) para
 * que Carteira e Desempenho herdem a fonte Shopee sem mudar o shape que já
 * consomem.
 *
 * ### Por que este service é mais simples que o Adman
 * `AdmanMetricDiffService` é HTTP-first (lê `.diff` ao vivo da Adman) com
 * fallback calculado guardado por dias-comuns. A Shopee NÃO tem endpoint de
 * diff nativo — este service é 100% leitura local determinística de
 * `shopee_metrics`, sempre `diff_source='calculated_fallback'` pra `revenue`
 * e `investment`.
 *
 * ### Margem sempre null (decisão travada 2026-07-23)
 * `contribution_margin_value`/`contribution_margin_pct` retornam
 * `value`/`diff_pct`/`diff_source` sempre `null` — a Shopee não fornece CMV.
 * Arquitetura *future-ready*: quando a Shopee passar a fornecer margem,
 * basta trocar `margemNula()` por um cálculo real, sem mudar o shape.
 *
 * ### Guard de soma DIFERENTE do Adman (dias ausentes = zero, não "sem dado")
 * `shopee_metrics` só tem linha nos dias em que HOUVE venda — um dia sem
 * linha é venda zero real, não gap de sync. Por isso `revenue` NÃO aplica o
 * guard de "interseção de dias-comuns" do Adman: soma direto a janela
 * inteira (`SUM()` já trata ausência de linha como zero implícito).
 *
 * ### `investment` — campo extra, null-aware (fora de `metrics`)
 * `ad_expense` só tem os últimos ~6 meses de histórico (lookback da Shopee
 * Ads). Uma janela fora do lookback tem TODAS as linhas com `ad_expense`
 * NULL — precisa ser distinguível de "R$ 0 investido de fato". Por isso
 * `investment.value` é `null` quando NENHUMA linha da janela tem
 * `ad_expense` preenchido, e a soma real caso contrário.
 *
 * ### quality.status quase sempre 'partial' (documentado, não é bug)
 * `buildQuality()` reusa o critério do Adman (conta `metrics` com `value`
 * não-null): como a margem é sempre null, um Shopee com `revenue` presente
 * já é classificado `'partial'`. Consumidores (Carteira/Desempenho) NÃO
 * devem usar este `status` como sinal de "dado ruim" — a Carteira decide o
 * próprio status de exibição; o Desempenho lê só `diff_pct`.
 */
class ShopeeMetricDiffService
{
    /** As 3 chaves de métrica sempre presentes no shape de retorno (espelha o Adman). */
    private const METRIC_KEYS = ['revenue', 'contribution_margin_value', 'contribution_margin_pct'];

    /**
     * Soma o diff de período (revenue + investimento) para uma empresa num
     * período resolvido pelo `MetricPeriodResolver`. Margem sempre null.
     *
     * @param  array  $periodo  Objeto retornado por `MetricPeriodResolver::resolve()`
     *   — usa `current_start`, `current_end`, `baseline_start`, `baseline_end`.
     *
     * @return array{
     *     company_id: int,
     *     period: array,
     *     metrics: array<string, array{value: ?float, diff_pct: ?float, diff_source: ?string}>,
     *     investment: array{value: ?float, diff_pct: ?float, diff_source: ?string},
     *     quality: array{status: string, source: string, computed_at: string},
     * }
     */
    public function compute(Company $company, array $periodo): array
    {
        // Cache por (company_id, janela current, DIA BRT) — mesmo padrão de
        // chave do Adman. Leitura é local/determinística (sem HTTP), então
        // TTL do dia inteiro é seguro (nada a "auto-curar" via retry curto).
        $cacheKey = "shopee:diff:v1:{$company->id}:{$periodo['current_start']}:{$periodo['current_end']}:" . $this->cacheDay();

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $metrics = [
            'revenue'                   => $this->calcularRevenue($company, $periodo),
            'contribution_margin_value' => $this->margemNula(),
            'contribution_margin_pct'   => $this->margemNula(),
        ];

        $investment = $this->calcularInvestimento($company, $periodo);

        $resultado = $this->buildResult($company, $periodo, $metrics, $investment);

        Cache::put($cacheKey, $resultado, now()->addMinutes(1440));

        return $resultado;
    }

    // ─────────────────────── revenue ───────────────────────

    /**
     * Soma `revenue` nas janelas current/baseline. Dias sem linha contam
     * como zero (SUM já ignora ausência — não há guard de dias-comuns aqui,
     * diferente do Adman: ver docblock da classe).
     */
    private function calcularRevenue(Company $company, array $periodo): array
    {
        $atual = (float) $this->naJanela($company, $periodo['current_start'], $periodo['current_end'])
            ->sum('revenue');

        $anterior = (float) $this->naJanela($company, $periodo['baseline_start'], $periodo['baseline_end'])
            ->sum('revenue');

        return [
            'value'       => $atual,
            'diff_pct'    => $this->diffPctGuardado($atual, $anterior),
            'diff_source' => 'calculated_fallback',
        ];
    }

    // ─────────────────────── investment (ad_expense, null-aware) ───────────────────────

    /**
     * Soma `ad_expense` nas janelas current/baseline, distinguindo "sem
     * dado de Ads" (fora do lookback de ~6 meses) de "R$ 0 investido" —
     * ver docblock da classe.
     */
    private function calcularInvestimento(Company $company, array $periodo): array
    {
        $atual    = $this->somaAdExpenseNullAware($company, $periodo['current_start'], $periodo['current_end']);
        $anterior = $this->somaAdExpenseNullAware($company, $periodo['baseline_start'], $periodo['baseline_end']);
        // Nota: ver naJanela() — usa whereDate (não whereBetween) porque
        // reference_date é persistido como datetime (00:00:00), e whereBetween
        // faz comparação de STRING contra a borda 'Y-m-d' (exclui o último dia).

        return [
            'value'       => $atual,
            'diff_pct'    => $this->diffPctGuardado($atual, $anterior),
            'diff_source' => 'calculated_fallback',
        ];
    }

    /**
     * Soma `ad_expense` NOT NULL na janela; `null` quando NENHUMA linha da
     * janela tem o campo preenchido (nunca confundir com zero investido).
     */
    private function somaAdExpenseNullAware(Company $company, string $inicio, string $fim): ?float
    {
        $query = $this->naJanela($company, $inicio, $fim)->whereNotNull('ad_expense');

        if ((clone $query)->count() === 0) {
            return null;
        }

        return (float) $query->sum('ad_expense');
    }

    /**
     * Query base filtrada por `company_id` + janela `[inicio, fim]` inclusiva.
     * Usa `whereDate` (não `whereBetween` puro) porque `reference_date` é
     * persistido como datetime (`Y-m-d 00:00:00`) — comparar como STRING
     * contra a borda `Y-m-d` excluiria o último dia da janela (a string
     * "2026-07-03 00:00:00" é lexicograficamente MAIOR que "2026-07-03").
     * `whereDate` compara só a parte de data, sem essa armadilha — mesmo
     * padrão de `AdmanMetricDiffService::somasComGuards()`.
     */
    private function naJanela(Company $company, string $inicio, string $fim)
    {
        return ShopeeMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $inicio)
            ->whereDate('reference_date', '<=', $fim);
    }

    /**
     * `(atual - anterior) / anterior * 100`, guardado contra baseline
     * ausente/zero/negativo (nunca -100%/divisão-por-zero artificial) —
     * mesma semântica de `AdmanMetricDiffService::diffPctGuardado()`.
     */
    private function diffPctGuardado(?float $atual, ?float $anterior): ?float
    {
        if ($atual === null || $anterior === null || $anterior <= 0) {
            return null;
        }

        return round((($atual - $anterior) / $anterior) * 100.0, 2);
    }

    // ─────────────────────── Shape / quality / cache ───────────────────────

    /** Bloco de margem — sempre null nos 3 campos (Shopee não tem CMV). */
    private function margemNula(): array
    {
        return ['value' => null, 'diff_pct' => null, 'diff_source' => null];
    }

    private function buildResult(Company $company, array $periodo, array $metrics, array $investment): array
    {
        return [
            'company_id' => $company->id,
            'period'     => $periodo,
            'metrics'    => $metrics,
            'investment' => $investment,
            'quality'    => $this->buildQuality($metrics),
        ];
    }

    /**
     * Mesma lógica de `buildQuality` do Adman, contando só `metrics` (não
     * `investment`). Como a margem é sempre null, o status Shopee nunca
     * chega a 'complete' — ver docblock da classe.
     */
    private function buildQuality(array $metrics): array
    {
        $comValue = collect($metrics)->filter(fn ($m) => $m['value'] !== null)->count();

        $status = match (true) {
            $comValue === count(self::METRIC_KEYS) => 'complete',
            $comValue === 0                        => 'missing',
            default                                => 'partial',
        };

        return [
            'status'      => $status,
            'source'      => 'shopee',
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Data atual em BRT — mesmo padrão de `AdmanMetricDiffService::cacheDay()`.
     */
    private function cacheDay(): string
    {
        return now()->setTimezone(config('app.timezone'))->toDateString();
    }
}
