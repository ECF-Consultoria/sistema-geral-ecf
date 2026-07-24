<?php

namespace App\Services\Metrics;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fase 101 (v18.0) — leitura AO VIVO da variação de período pronta da Adman
 * (`.diff`), com gate por `comparison_mode` e fallback calculado guardado.
 *
 * ### Por que este service existe
 * O `AdmanService` hoje descarta `.diff`/`.prev` e lê só `.value` — qualquer
 * variação de período era recalculada na mão em outro lugar. A Adman já
 * calcula a variação corretamente (fonte oficial); este service para de
 * reinventar esse cálculo, MAS só quando o baseline nativo da Adman
 * ("N dias imediatamente anteriores a `current_start`") é semanticamente
 * igual ao baseline do `MetricPeriodResolver` — o que só é verdade quando
 * `comparison_mode === 'previous_equal_length_window'` (achado empírico do
 * research, ver 101-RESEARCH.md "Risco arquitetural").
 *
 * ### O gate (ADM-02)
 * - `comparison_mode === 'previous_equal_length_window'` E a Adman devolveu
 *   `.diff` não-nulo ⇒ `diff_source = 'adman_diff'` (usa o `.diff` nativo).
 * - Qualquer outro caso (`same_interval_previous_month`, ou `.diff` ausente)
 *   ⇒ `diff_source = 'calculated_fallback'`.
 *
 * ### calculated_fallback — duplicação TEMPORÁRIA e INTENCIONAL
 * Os guards abaixo (`margem_dias` + interseção de dias-comuns) são cópias
 * PRIVADAS, fiéis, dos guards já cicatrizados em
 * `DesempenhoScoreService::computeVarMargem()`/`computeVarFaturamento()`
 * (fix Luiz 2026-07-09 + audit Tomelin/LOJASINVAL/AVF2K 2026-07-13). Este
 * service é BAIXO nível — NÃO chama `DesempenhoScoreService` (dependência
 * invertida). A Fase 102, ao reconectar o `DesempenhoScoreService` para
 * consumir este service, REMOVE a lógica duplicada de lá.
 *
 * Generalização: `DesempenhoScoreService` compara sempre mês-vs-mês (ambas
 * janelas começam no dia 1), então usa "dia do mês" como chave de
 * interseção. Aqui as janelas vêm do `MetricPeriodResolver` (podem começar
 * em qualquer dia), então a interseção usa "offset em dias desde o início
 * da janela" — equivalente generalizado da mesma ideia.
 *
 * ### Live-read, sem coluna nova (ADM-03)
 * Nenhuma migration desta fase adiciona coluna de diff em `adman_metrics`.
 * O resultado é cacheado (TTL 24h, chave por dia BRT — mesmo padrão de
 * `AdmanService`), nunca persistido como fato.
 */
class AdmanMetricDiffService
{
    /**
     * Sentinel de erro persistente — mesmo padrão de `AdmanService`.
     */
    private const ERROR_SENTINEL = '__error__';
    private const ERROR_CACHE_MINUTES = 10;

    /** As 3 chaves de métrica sempre presentes no shape de retorno. */
    private const METRIC_KEYS = ['revenue', 'contribution_margin_value', 'contribution_margin_pct'];

    /**
     * Fase 110 (FIXMARG-01) — cobertura mínima (80% dos dias-COM-LINHA da
     * janela current com `contribution_margin` não-nulo) para preferir o
     * `calculated_fallback` LOCAL determinístico sobre o `.diff` nativo ao
     * vivo na resolução de `contribution_margin_pct`. Ver `resolveMargemPct()`
     * e `.planning/debug/margem-adman-diff-instavel.md` (root cause).
     */
    private const MARGEM_COBERTURA_MINIMA = 0.8;

    /**
     * Memo em memória (escopo do request/instância) — guarda o resultado REAL
     * de `compute()` por cacheKey. Necessário porque o `DesempenhoScoreService`
     * unificado (2026-07-22) chama `compute()` DUAS vezes por empresa na mesma
     * passada (`computeVarFaturamento` + `computeVarMargem`). Quando o HTTP da
     * Adman falha e o resultado é 100% `calculated_fallback` (todos os `value`
     * null → status 'missing'), a 1ª chamada grava o ERROR_SENTINEL no cache;
     * sem este memo, a 2ª chamada leria o sentinel e descartaria o `diff_pct`
     * válido do fallback (regressão da margem). O memo NÃO altera a política de
     * TTL/cache persistente — só evita reler o sentinel dentro do MESMO request.
     */
    private array $memo = [];

    public function __construct(private AdmanService $admanService)
    {
    }

    /**
     * Lê ao vivo o diff de período (revenue, margem R$, margem %) para uma
     * empresa num período resolvido pelo `MetricPeriodResolver`.
     *
     * @param  array  $periodo  Objeto INTEIRO retornado por
     *   `MetricPeriodResolver::resolve()` — precisa de `comparison_mode`,
     *   `current_start`, `current_end`, `baseline_start`, `baseline_end`.
     *
     * @return array{
     *     company_id: int,
     *     period: array,
     *     metrics: array<string, array{value: ?float, diff_pct: ?float, diff_source: ?string}>,
     *     quality: array{status: string, source: string, computed_at: string},
     * }
     */
    public function compute(Company $company, array $periodo): array
    {
        // Prioriza adman_account_id (alinhado com Company::cust_id — padrão do AdmanService).
        $custId      = $company->adman_account_id ?: $company->ml_store_id;
        $marketplace = $company->marketplace ?? 'meli';

        if (empty($custId)) {
            return $this->buildResult($company, $periodo, $this->emptyMetrics());
        }

        // v2 (2026-07-22): descarta resultados PARCIAIS envenenados (ByMobille).
        // v3 (2026-07-22): margem R$ passou de `profitMargin` para `liquidMargin`
        // (caso Utilarshop) — o valor muda, então o bump invalida o cache v2.
        // v4 (2026-07-24): variação de margem volta ao .diff nativo da Adman
        // (sem cálculo local) — o bump invalida os valores locais errados em cache.
        $cacheKey = "adman:diff:v4:{$marketplace}:{$custId}:{$periodo['current_start']}:{$periodo['current_end']}:" . $this->cacheDay();

        // Memo do request: devolve o resultado real já computado nesta passada
        // (evita reler um ERROR_SENTINEL gravado pela 1ª de duas chamadas ao
        // mesmo (empresa, período) — ver docblock da property $memo).
        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === self::ERROR_SENTINEL
                ? $this->buildResult($company, $periodo, $this->emptyMetrics())
                : $cached;
        }

        $isJanelaIgual = ($periodo['comparison_mode'] ?? null) === 'previous_equal_length_window';

        // Leitura ao vivo — fail-open (nunca deixa exceção virar 500; V5/T-101-01).
        $revenueAdman = null;
        try {
            $performance  = $this->admanService->fetchPerformance(
                $custId, $periodo['current_start'], $periodo['current_end'], 3, $marketplace
            );
            $revenueAdman = $performance['summarizedData']['grossBilling'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("[AdmanMetricDiff] performance custId={$custId} periodo={$periodo['current_start']}..{$periodo['current_end']}: " . $e->getMessage());
        }

        // fetchAccountMetricsDetailedCached já é fail-open (retorna null, não lança).
        $accountMetrics = $this->admanService->fetchAccountMetricsDetailedCached(
            $custId, $periodo['current_start'], $periodo['current_end'], 1440, false, $marketplace
        );
        $marginPctAdman = $accountMetrics['percentageMargin'] ?? null;
        // MARGEM DE CONTRIBUIÇÃO R$ = `liquidMargin` do endpoint detalhado — o
        // MESMO campo/valor da UI da Adman (fix 2026-07-22, caso Utilarshop:
        // 55.502,36 -75,24%). ANTES usava `profitMargin` do fetchPerformance,
        // que é OUTRO metric (margem operacional): coincidia com a UI na PETSHOP
        // (39.159≈39.074) mas divergia muito na Utilarshop (98.033 vs 55.502).
        // `percentageMargin` = liquidMargin/netBilling — por isso a margem % já
        // estava certa enquanto a margem R$ não.
        $marginValueAdman = $accountMetrics['liquidMargin'] ?? null;

        $metrics = [
            'revenue'                   => $this->resolveField(
                $revenueAdman, $isJanelaIgual,
                fn () => $this->fallbackSomaSimples($company, $periodo, 'revenue'),
            ),
            // Hotfix 2026-07-24 (decisão do usuário): a variação de MARGEM (R$ e %)
            // usa SEMPRE o valor que a própria Adman disponibiliza (.diff nativo),
            // NUNCA cálculo local — o fallback local comparava baseline incompleto
            // (maio ~8 dias) vs mês cheio e divergia muito da Adman (ex.: Relojoaria
            // Wenus: Adman +15%, local -7,5%). Sem nativo → variação null ("—").
            // (Faturamento MANTÉM fallback local: revenue é sincronizado completo.)
            'contribution_margin_value' => $this->resolveField(
                $marginValueAdman, $isJanelaIgual,
                fn () => null,
            ),
            'contribution_margin_pct'   => $this->resolveMargemPct($marginPctAdman, $isJanelaIgual),
        ];

        $resultado = $this->buildResult($company, $periodo, $metrics);

        // Política de TTL por qualidade (fix 2026-07-22):
        //  - missing (0 metrics) → ERROR_SENTINEL, TTL curto (retry rápido).
        //  - partial (faltou 1+ metric, ex.: percentageMargin nulo por falha
        //    transitória/rate-limit) → TTL CURTO. Cachear pelo dia CONGELARIA o
        //    número degradado (margem % "-" + variação no fallback local errada,
        //    ex.: ByMobille -53,85% vs +18,46% real). TTL curto → re-tenta e
        //    auto-cura quando o endpoint volta.
        //  - complete (as 3 metrics com value) → TTL do dia (1440).
        $status = $resultado['quality']['status'];
        if ($status === 'missing') {
            Cache::put($cacheKey, self::ERROR_SENTINEL, now()->addMinutes(self::ERROR_CACHE_MINUTES));
        } elseif ($status === 'partial') {
            Cache::put($cacheKey, $resultado, now()->addMinutes(self::ERROR_CACHE_MINUTES));
        } else {
            Cache::put($cacheKey, $resultado, now()->addMinutes(1440));
        }

        // Memo do request com o resultado REAL (nunca o sentinel) — protege a
        // 2ª chamada ao mesmo (empresa, período) na mesma passada.
        $this->memo[$cacheKey] = $resultado;

        return $resultado;
    }

    /**
     * Resolve UM campo de métrica: aplica o gate por comparison_mode e,
     * quando não pode usar `.diff` nativo, chama o fallback.
     *
     * @param  ?array{value: ?float, diff: ?float, prev: ?float}  $adman
     * @param  callable(): ?float  $fallback
     * @return array{value: ?float, diff_pct: ?float, diff_source: ?string}
     */
    private function resolveField(?array $adman, bool $isJanelaIgual, callable $fallback): array
    {
        $value = isset($adman['value']) ? (float) $adman['value'] : null;
        $adminDiff = $adman['diff'] ?? null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'diff_pct'    => (float) $adminDiff,
                'diff_source' => 'adman_diff',
            ];
        }

        return [
            'value'       => $value,
            'diff_pct'    => $fallback(),
            'diff_source' => 'calculated_fallback',
        ];
    }

    /**
     * Resolve `contribution_margin_pct` usando SEMPRE a variação que a própria
     * Adman disponibiliza (`.diff` nativo), NUNCA cálculo local.
     *
     * Hotfix 2026-07-24 (decisão do usuário) — REVERTE a prioridade invertida da
     * Fase 110 (que preferia o `calculated_fallback` local): o fallback local
     * `SUM(margem)/SUM(revenue)` comparava um baseline LOCAL incompleto (maio com
     * ~8 dias de margem sincronizada) contra o mês corrente cheio, produzindo
     * variação divergente da Adman (ex.: Relojoaria Wenus — Adman +15%, local
     * -7,5%). A Adman é a fonte da verdade da variação; não reinventamos local.
     *
     * Ordem:
     *  1. Janela-igual + `.diff` nativo presente ⇒ usa o valor da Adman.
     *  2. Senão ⇒ variação `null` ("—"): a Adman não disponibilizou o número
     *     (janela incompatível ou indisponível/rate-limit) — melhor "—" que um
     *     valor local errado. O `value` (margem % do mês) é preservado quando
     *     presente, para não rebaixar `quality.status` indevidamente.
     *
     * @param  ?array{value: ?float, diff: ?float, prev: ?float}  $marginPctAdman
     * @return array{value: ?float, diff_pct: ?float, diff_source: ?string}
     */
    private function resolveMargemPct(?array $marginPctAdman, bool $isJanelaIgual): array
    {
        $value     = isset($marginPctAdman['value']) ? (float) $marginPctAdman['value'] : null;
        $adminDiff = $marginPctAdman['diff'] ?? null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'diff_pct'    => (float) $adminDiff,
                'diff_source' => 'adman_diff',
            ];
        }

        return [
            'value'       => $value,
            'diff_pct'    => null,
            'diff_source' => 'adman_indisponivel',
        ];
    }

    // ─────────────────────── calculated_fallback (guards cicatrizados) ───────────────────────

    /**
     * Fallback de soma simples (revenue / contribution_margin) com os guards
     * cicatrizados: (1) margem_dias — se um dos lados tem ZERO linhas com o
     * campo NOT NULL, `null` (nunca -100% artificial); (2) interseção de
     * dias-comuns — só soma os offsets presentes nas DUAS janelas.
     */
    private function fallbackSomaSimples(Company $company, array $periodo, string $campo): ?float
    {
        $somas = $this->somasComGuards($company, $periodo, $campo);

        return $this->diffPctGuardado($somas['atual'], $somas['anterior']);
    }

    /**
     * Fallback de margem % — NÃO soma percentuais diários (não faz sentido
     * estatístico). Deriva de `SUM(contribution_margin)/SUM(revenue)*100`
     * em cada janela (mesmo cálculo de `AdmanService::syncCompany()` para
     * `contribution_margin_pct`), usando os MESMOS dias-comuns/guards do
     * `contribution_margin`.
     */
    private function fallbackMargemPct(Company $company, array $periodo): ?float
    {
        $margem  = $this->somasComGuards($company, $periodo, 'contribution_margin');
        $revenue = $this->somasComGuards($company, $periodo, 'revenue');

        if ($margem['atual'] === null || $margem['anterior'] === null
            || $revenue['atual'] === null || $revenue['anterior'] === null) {
            return null;
        }
        if ($revenue['atual'] <= 0 || $revenue['anterior'] <= 0) {
            return null;
        }

        $pctAtual    = ($margem['atual'] / $revenue['atual']) * 100.0;
        $pctAnterior = ($margem['anterior'] / $revenue['anterior']) * 100.0;

        return $this->diffPctGuardado($pctAtual, $pctAnterior);
    }

    /**
     * Soma de `$campo` em AdmanMetric nas janelas atual/anterior do período,
     * aplicando os guards cicatrizados. Retorna `['atual'=>null,'anterior'=>null]`
     * quando qualquer guard barra o cálculo (margem_dias=0 num lado, ou
     * nenhum dia comum entre as janelas).
     *
     * Guard margem_dias (fix Luiz 2026-07-09): COUNT de linhas com `$campo`
     * NOT NULL em cada janela; se um dos lados é 0, não computa.
     *
     * Guard dias-comuns (audit Tomelin/LOJASINVAL/AVF2K 2026-07-13): as duas
     * janelas podem ter dias com dado em posições diferentes (gap de sync no
     * meio, empresa nova no meio do mês). Usa SÓ a interseção de "offset em
     * dias desde o início da janela" presente nas DUAS — generalização do
     * "dia do mês" do DesempenhoScoreService (lá as janelas sempre começam
     * no dia 1; aqui podem começar em qualquer dia).
     *
     * @return array{atual: ?float, anterior: ?float}
     */
    private function somasComGuards(Company $company, array $periodo, string $campo): array
    {
        $offsetsComuns = $this->offsetsComunsComLinha($company, $periodo, $campo);

        if (empty($offsetsComuns)) {
            // Guard margem_dias (um lado sem NENHUM dia com dado) OU guard
            // dias-comuns (sem dia coincidente entre as janelas) — não computa.
            return ['atual' => null, 'anterior' => null];
        }

        $currentStart  = Carbon::parse($periodo['current_start'])->startOfDay();
        $currentEnd    = Carbon::parse($periodo['current_end'])->startOfDay();
        $baselineStart = Carbon::parse($periodo['baseline_start'])->startOfDay();
        $baselineEnd   = Carbon::parse($periodo['baseline_end'])->startOfDay();

        $rowsAtual = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $currentStart->toDateString())
            ->whereDate('reference_date', '<=', $currentEnd->toDateString())
            ->whereNotNull($campo)
            ->get(['reference_date', $campo]);

        $rowsAnterior = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $baselineStart->toDateString())
            ->whereDate('reference_date', '<=', $baselineEnd->toDateString())
            ->whereNotNull($campo)
            ->get(['reference_date', $campo]);

        $offset = fn ($data, Carbon $inicioJanela): int => Carbon::parse($data)->startOfDay()->diffInDays($inicioJanela);

        $somaAtual = 0.0;
        foreach ($rowsAtual as $row) {
            if (in_array($offset($row->reference_date, $currentStart), $offsetsComuns, true)) {
                $somaAtual += (float) $row->{$campo};
            }
        }

        $somaAnterior = 0.0;
        foreach ($rowsAnterior as $row) {
            if (in_array($offset($row->reference_date, $baselineStart), $offsetsComuns, true)) {
                $somaAnterior += (float) $row->{$campo};
            }
        }

        return ['atual' => $somaAtual, 'anterior' => $somaAnterior];
    }

    /**
     * Helper compartilhado (Fase 110): devolve os offsets "dias desde o início
     * da janela" em que `$campo` tem linha NOT NULL nas DUAS janelas
     * (current ∩ baseline) — a mesma mecânica de guards (margem_dias +
     * dias-comuns) usada por `somasComGuards()`, extraída pra ser reaproveitada
     * por `coberturaMargem()` (denominador/numerador de cobertura).
     *
     * @return int[] offsets comparáveis (vazio se algum lado não tem NENHUMA
     *                linha com `$campo` não-nulo, ou se não há dia coincidente).
     */
    private function offsetsComunsComLinha(Company $company, array $periodo, string $campo): array
    {
        $currentStart  = Carbon::parse($periodo['current_start'])->startOfDay();
        $currentEnd    = Carbon::parse($periodo['current_end'])->startOfDay();
        $baselineStart = Carbon::parse($periodo['baseline_start'])->startOfDay();
        $baselineEnd   = Carbon::parse($periodo['baseline_end'])->startOfDay();

        $rowsAtual = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $currentStart->toDateString())
            ->whereDate('reference_date', '<=', $currentEnd->toDateString())
            ->whereNotNull($campo)
            ->get(['reference_date']);

        $rowsAnterior = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $baselineStart->toDateString())
            ->whereDate('reference_date', '<=', $baselineEnd->toDateString())
            ->whereNotNull($campo)
            ->get(['reference_date']);

        // Guard margem_dias: um dos lados sem NENHUM dia com dado ⇒ vazio.
        if ($rowsAtual->isEmpty() || $rowsAnterior->isEmpty()) {
            return [];
        }

        $offset = fn ($data, Carbon $inicioJanela): int => Carbon::parse($data)->startOfDay()->diffInDays($inicioJanela);

        $offsetsAtual    = $rowsAtual->pluck('reference_date')->map(fn ($d) => $offset($d, $currentStart))->unique()->values()->all();
        $offsetsAnterior = $rowsAnterior->pluck('reference_date')->map(fn ($d) => $offset($d, $baselineStart))->unique()->values()->all();

        return array_values(array_intersect($offsetsAtual, $offsetsAnterior));
    }

    /**
     * Cobertura local de margem (Fase 110 · FIXMARG-01) — fração dos
     * dias-COM-LINHA (não dias-calendário) da janela current em que
     * `contribution_margin` está sincronizado (não-nulo).
     *
     * Denominador = dias-com-linha COMPARÁVEIS entre current e baseline
     * (`offsetsComunsComLinha(..., 'revenue')` — revenue é o proxy de "a
     * empresa teve QUALQUER sync nesse dia", já que é populado tanto pelo
     * `adman:sync` quanto pelo `ml:sync`, enquanto `contribution_margin` pode
     * faltar por lag específico do `adman:sync-margem`). Aplicar o mesmo
     * raciocínio de dias-com-linha à baseline é necessário porque a
     * comparabilidade do diff já exige a interseção.
     *
     * Numerador = subconjunto desses offsets em que `contribution_margin`
     * (janela current) está não-nulo.
     *
     * Empresa que fecha fim de semana ou entrou no meio do mês NÃO é
     * penalizada por isso — só a AUSÊNCIA de margem nos dias em que ela
     * efetivamente vendeu reduz a cobertura.
     */
    private function coberturaMargem(Company $company, array $periodo): float
    {
        $offsetsComLinha = $this->offsetsComunsComLinha($company, $periodo, 'revenue');
        $denominador      = count($offsetsComLinha);

        if ($denominador === 0) {
            return 0.0; // sem dia-com-linha comparável — cai pro ramo 0/0 → null explícito.
        }

        $currentStart = Carbon::parse($periodo['current_start'])->startOfDay();
        $currentEnd   = Carbon::parse($periodo['current_end'])->startOfDay();

        $rowsComMargem = AdmanMetric::where('company_id', $company->id)
            ->whereDate('reference_date', '>=', $currentStart->toDateString())
            ->whereDate('reference_date', '<=', $currentEnd->toDateString())
            ->whereNotNull('contribution_margin')
            ->get(['reference_date']);

        $offset = fn ($data): int => Carbon::parse($data)->startOfDay()->diffInDays($currentStart);
        $offsetsComMargem = $rowsComMargem->pluck('reference_date')->map($offset)->unique()->values()->all();

        $numerador = count(array_intersect($offsetsComLinha, $offsetsComMargem));

        return $numerador / $denominador;
    }

    /**
     * `(atual - anterior) / anterior * 100`, guardado contra baseline
     * zero/negativo (nunca -100%/divisão-por-zero artificial).
     */
    private function diffPctGuardado(?float $atual, ?float $anterior): ?float
    {
        if ($atual === null || $anterior === null || $anterior <= 0) {
            return null;
        }

        return round((($atual - $anterior) / $anterior) * 100.0, 2);
    }

    // ─────────────────────── ADM-04 reframado — leitura de diff DIÁRIO do raw_data ───────────────────────

    /**
     * Lê o diff DIÁRIO (dia vs dia anterior) persistido em `AdmanMetric.raw_data`
     * — resultado de `fetchPerformance($custId, $date, $date)`, DIA ÚNICO.
     *
     * ### Por que este método existe (ADM-04 reframado)
     * O texto original do REQ pedia "backfill de `raw_data` antigo para
     * preencher os novos campos de diff". O research provou que isso é um
     * equívoco: `raw_data` é sempre resultado de um fetch de DIA ÚNICO, logo
     * `raw_data.*.diff` é sempre "esse dia vs o dia anterior" — NUNCA "esse
     * período vs o período anterior". Não há backfill de coluna (Fase 101 é
     * live-read, sem colunas novas — decisão travada).
     *
     * Este helper existe SÓ para auditoria/metadados de fato diário (ex.:
     * "como variou o faturamento de ontem pra hoje?"). O diff de PERÍODO
     * (usado pelo bônus) segue exclusivamente por `compute()` ao vivo.
     *
     * ### PROIBIDO (Pitfall 1 do research)
     * Reaproveitar o retorno deste método como se fosse diff de período é o
     * erro que o research identificou como Pitfall 1. Por isso o retorno
     * marca `scope='daily'` explicitamente e NUNCA contém `diff_source`
     * (chave exclusiva do shape de `compute()`/`resolveField()`) nem o valor
     * `'adman_diff'` — ninguém deve confundir os dois.
     *
     * `percentageMargin` NUNCA esteve em `raw_data` — só existe no endpoint
     * `/accounts/{custId}/metrics` (lido por `fetchAccountMetricsDetailedCached`).
     * Por isso `percentageMargin_diff` é sempre `null` aqui.
     *
     * Fail-open: `raw_data` ausente ou malformado (linhas antigas) retorna
     * todos os diffs `null`, nunca lança (`?? null` em todo acesso aninhado).
     *
     * @return array{grossBilling_diff: ?float, profitMargin_diff: ?float, percentageMargin_diff: null, scope: string}
     */
    public function lerDiffDiarioRawData(AdmanMetric $metric): array
    {
        $summarized = $metric->raw_data['summarizedData'] ?? null;

        $grossBillingDiff = $summarized['grossBilling']['diff'] ?? null;
        $profitMarginDiff = $summarized['profitMargin']['diff'] ?? null;

        return [
            'grossBilling_diff'     => $grossBillingDiff !== null ? (float) $grossBillingDiff : null,
            'profitMargin_diff'     => $profitMarginDiff !== null ? (float) $profitMarginDiff : null,
            // percentageMargin nunca esteve em raw_data — só em /accounts/metrics.
            'percentageMargin_diff' => null,
            'scope'                 => 'daily',
        ];
    }

    // ─────────────────────── Shape / quality / cache ───────────────────────

    private function emptyMetrics(): array
    {
        $vazio = ['value' => null, 'diff_pct' => null, 'diff_source' => null];

        return array_fill_keys(self::METRIC_KEYS, $vazio);
    }

    private function buildResult(Company $company, array $periodo, array $metrics): array
    {
        return [
            'company_id' => $company->id,
            'period'     => $periodo,
            'metrics'    => $metrics,
            'quality'    => $this->buildQuality($metrics),
        ];
    }

    private function buildQuality(array $metrics): array
    {
        $comValue = collect($metrics)->filter(fn ($m) => $m['value'] !== null)->count();

        // Fase gf9 (2026-07-24): sob rate-limit/falha ao vivo, o `value` fica
        // null, MAS o `calculated_fallback` LOCAL (determinístico) pode ter
        // preenchido `diff_pct` normalmente — contar só `value` classificava
        // isso como 'missing' e o `compute()` gravava o ERROR_SENTINEL,
        // jogando fora nas leituras seguintes o `diff_pct` bom do fallback
        // (apagava o baseline de empresas com dado local). `missing` agora só
        // quando NADA é usável (nem value ao vivo, nem diff_pct de fallback).
        $comDiff = collect($metrics)->filter(fn ($m) => $m['diff_pct'] !== null)->count();

        $status = match (true) {
            $comValue === count(self::METRIC_KEYS) => 'complete',
            $comValue === 0 && $comDiff === 0      => 'missing',
            default                                => 'partial',
        };

        return [
            'status'      => $status,
            'source'      => 'adman',
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Data atual em BRT — mesmo padrão de `AdmanService::cacheDay()` (a API
     * Adman é D-1, então chave por dia auto-invalida ao virar o dia).
     */
    private function cacheDay(): string
    {
        return now()->setTimezone(config('app.timezone'))->toDateString();
    }
}
