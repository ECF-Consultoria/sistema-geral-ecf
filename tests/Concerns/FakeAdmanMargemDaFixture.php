<?php

namespace Tests\Concerns;

use App\Models\AdmanMetric;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Stub da Adman que DERIVA a margem % da própria fixture local
 * (quick 260914-ly9).
 *
 * ## Por que este trait existe
 *
 * O hotfix `a413e823` (2026-07-24) revogou o `calculated_fallback` LOCAL da
 * margem %: `AdmanMetricDiffService::resolveMargemPct()` passou a ser
 * "nativo-ou-null" — sem `.diff`/`value` vindo da Adman, `contribution_margin_pct`
 * volta `null` e `diff_source='adman_indisponivel'`. O faturamento MANTEVE o
 * fallback local (`fallbackSomaSimples`), e é por isso que, nas suítes que
 * semeiam `adman_metrics` local e fakeiam a Adman com 404,
 * `var_faturamento_pct` continuava passando enquanto `var_margem_pct` vinha
 * `null` na MESMA asserção do MESMO teste.
 *
 * `AdmanMetricDiffService::fallbackMargemPct()` é **código morto** desde então
 * (o próprio `CompanyScoreService.php:58` documenta isso). Ou seja: hoje não
 * existe caminho de produção em que a margem % nasça de soma local. Um teste
 * de margem que não fale com a Adman não testa nada que exista.
 *
 * ## O que este stub faz
 *
 * Responde `/{marketplace}/accounts/{custId}/metrics?dateFrom&dateTo` com o
 * shape real do endpoint detalhado (`metrics.percentageMargin.{value,diff,prev}`),
 * calculando os números a partir das linhas de `adman_metrics` que a própria
 * fixture semeou — nunca de constantes escritas à mão.
 *
 *  - `value` = SUM(contribution_margin) / SUM(revenue) × 100 na janela pedida,
 *    considerando **apenas os dias em que a margem existe** (regra
 *    like-with-like: numerador e denominador saem das MESMAS linhas). É o
 *    mesmo princípio do fix "Tomelin" — comparar coisas comparáveis —, e é a
 *    leitura fiel do que o endpoint significa (`liquidMargin / netBilling` do
 *    período).
 *  - `prev` = o mesmo cálculo na janela de MESMO TAMANHO imediatamente
 *    anterior a `dateFrom`. É o que a Adman devolve de verdade: o `prev`
 *    nativo é a janela imediatamente anterior (medido ao vivo em 2026-08-10,
 *    caso LUCCMAX — ver `.planning/learnings/desempenho-bonificacao.md` §0.00b).
 *    Em mês FECHADO essa janela coincide, por construção, com o
 *    `baseline_start..baseline_end` do `MetricPeriodResolver`, que é onde as
 *    fixturas semeiam o "antes".
 *  - `diff` = (value − prev) / prev × 100, o mesmo relativo que a Adman expõe.
 *
 * Nada de `billing` nem de `liquidMargin` no payload: o faturamento das
 * suítes segue vindo do `calculated_fallback` local (via `/performance/*`
 * fakeado com 404), então **todos os goldens de `var_faturamento_pct`
 * permanecem intocados**.
 *
 * ## A armadilha que custou a investigação
 *
 * `Http::fake()` chamado uma SEGUNDA vez NÃO substitui o primeiro — o stub
 * novo é ignorado em silêncio e o 404 antigo continua respondendo. Só
 * `Http::swap(new Factory($app['events']))` reseta o Factory. Por isso o
 * `swap` abre este helper.
 *
 * @see .planning/debug/motor-desempenho-11-falhas.md
 * @see .planning/quick/260914-a-ancora-volta-a-vigiar-a-nota-oficial/PLAN.md
 */
trait FakeAdmanMargemDaFixture
{
    /**
     * Instala o isolamento HTTP das suítes de desempenho:
     *  - `/performance/*` → 404 (faturamento cai no fallback local, como antes);
     *  - `/accounts/{custId}/metrics` → margem % derivada da fixture.
     *
     * `preventStrayRequests()` continua ligado: qualquer request fora destes
     * dois padrões falha ALTO, em vez de vazar para a rede.
     */
    protected function fakeAdmanComMargemDaFixture(): void
    {
        // Reseta o Factory — sem isto um `Http::fake()` anterior tem
        // precedência e este stub nunca é consultado (ver docblock do trait).
        Http::swap(new Factory(app('events')));

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => fn ($request) => Http::response(
                $this->respostaDeAccountMetrics($request->url())
            ),
        ]);
    }

    /**
     * Monta o corpo JSON do endpoint detalhado para a URL pedida.
     *
     * Empresa sem nenhuma linha de margem na janela devolve `metrics` vazio —
     * que é como a Adman se comporta para conta sem dado, e o que mantém os
     * cenários de "amostra degradada" (Fase 110) degradados de verdade.
     *
     * @return array{metrics: array<string, array{value: ?float, diff: ?float, prev: ?float}>}
     */
    private function respostaDeAccountMetrics(string $url): array
    {
        $partes = parse_url($url);
        parse_str($partes['query'] ?? '', $query);

        $dateFrom = $query['dateFrom'] ?? null;
        $dateTo   = $query['dateTo']   ?? null;

        if (! preg_match('#/accounts/([^/]+)/metrics#', $partes['path'] ?? '', $m)
            || $dateFrom === null || $dateTo === null) {
            return ['metrics' => []];
        }

        $custId  = rawurldecode($m[1]);
        $company = Company::query()
            ->where('adman_account_id', $custId)
            ->orWhere('ml_store_id', $custId)
            ->first();

        if ($company === null) {
            return ['metrics' => []];
        }

        $value = $this->margemPctDaJanela($company, $dateFrom, $dateTo);

        if ($value === null) {
            return ['metrics' => []];
        }

        // Janela de MESMO TAMANHO imediatamente anterior — é o `prev` nativo
        // da Adman (e, em mês fechado, o baseline do MetricPeriodResolver).
        $dias      = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1;
        $prevFim   = Carbon::parse($dateFrom)->subDay();
        $prevIni   = $prevFim->copy()->subDays($dias - 1);
        $prev      = $this->margemPctDaJanela($company, $prevIni->toDateString(), $prevFim->toDateString());

        $diff = ($prev !== null && $prev > 0)
            ? round((($value - $prev) / $prev) * 100.0, 2)
            : null;

        return [
            'metrics' => [
                'percentageMargin' => [
                    'value' => $value,
                    'diff'  => $diff,
                    'prev'  => $prev,
                ],
            ],
        ];
    }

    /**
     * Margem % da janela: SUM(contribution_margin) / SUM(revenue) × 100 sobre
     * os dias em que a margem EXISTE (like-with-like — numerador e denominador
     * saem das mesmas linhas). `null` quando não há dia com margem ou quando o
     * faturamento desses dias é zero.
     */
    private function margemPctDaJanela(Company $company, string $inicio, string $fim): ?float
    {
        $linhas = AdmanMetric::query()
            ->where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $inicio)
            ->whereDate('reference_date', '<=', $fim)
            ->whereNotNull('contribution_margin')
            ->get(['revenue', 'contribution_margin']);

        if ($linhas->isEmpty()) {
            return null;
        }

        $somaMargem  = (float) $linhas->sum('contribution_margin');
        $somaRevenue = (float) $linhas->sum('revenue');

        if ($somaRevenue <= 0) {
            return null;
        }

        return round(($somaMargem / $somaRevenue) * 100.0, 4);
    }
}
