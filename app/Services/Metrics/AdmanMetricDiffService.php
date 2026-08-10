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
     *     metrics: array{
     *         revenue: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_source: ?string},
     *         contribution_margin_value: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_source: ?string},
     *         contribution_margin_pct: array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_pp: ?float, diff_source: ?string},
     *     },
     *     quality: array{status: string, source: string, computed_at: string, diff_pp_disponivel: bool},
     * }
     */
    /**
     * Chave do cache do diff — FONTE ÚNICA (extraída em 2026-08-07 para o
     * `isCached()` abaixo poder perguntar sem duplicar o formato; duas cópias
     * do prefixo `v6` divergiriam no próximo bump e o gate passaria a mentir).
     *
     * As versões `v2`..`v7` estão documentadas em `compute()`, onde cada bump
     * foi decidido — não repetir o histórico aqui.
     */
    private function cacheKey(string $marketplace, string $custId, array $periodo): string
    {
        return "adman:diff:v7:{$marketplace}:{$custId}:{$periodo['current_start']}:{$periodo['current_end']}:" . $this->cacheDay();
    }

    /**
     * Este (empresa, período) já está em cache?
     *
     * Existe para a carteira poder decidir se pode montar a tabela AGORA ou se
     * precisa aquecer em background: `compute()` faz HTTP síncrono à Adman, e
     * 25 empresas frias custaram 157s medidos em produção (2026-08-07) — a
     * página pendurava até o `max_execution_time` de 300s do php-fpm.
     *
     * Empresa sem `cust_id` conta como QUENTE: `compute()` devolve o shape
     * vazio na hora, sem tocar a rede — não há nada a aquecer.
     */
    public function isCached(Company $company, array $periodo): bool
    {
        $custId      = $company->adman_account_id ?: $company->ml_store_id;
        $marketplace = $company->marketplace ?? 'meli';

        if (empty($custId)) {
            return true;
        }

        return Cache::has($this->cacheKey($marketplace, (string) $custId, $periodo));
    }

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
        // v5 (2026-07-27): faturamento cai pro `billing` do endpoint detalhado
        // quando o /performance dá 500 (caso Jf Auto) — o bump invalida as entradas
        // "sem fonte" antigas dessas empresas.
        // v6 (2026-07-27): shape ganha prev_value/diff_pp (Fase 117, MPP-01/02/03) —
        // o bump invalida as entradas com shape antigo.
        // v7 (2026-08-10, quick 260810-mbv): no modo operacional a margem passa a
        // comparar contra a JANELA BASELINE do período (lida da Adman), em vez de
        // ficar sem variação. As entradas v6 do mês corrente guardam `prev_value`
        // da janela imediatamente anterior (o `prev` nativo) e `diff_pp` null —
        // shape igual, semântica outra, então precisam sair de circulação.
        $cacheKey = $this->cacheKey($marketplace, $custId, $periodo);

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

        // Fallback NATIVO de faturamento (2026-07-27): a Adman devolve HTTP 500 no
        // endpoint /performance/{custId} de algumas empresas (ex.: Jf Auto Câmbio),
        // zerando o revenue → "sem fonte" na carteira MESMO com o dado existindo na
        // Adman. O endpoint detalhado /accounts/{custId}/metrics RESPONDE nesses
        // casos e traz `billing` = o MESMO {value,diff} do grossBilling do
        // /performance (confirmado idêntico em SINVAL 127.593,78/+0,3% e Relojoaria
        // Wenus 3.242.727,12/+13,86%). Usa `billing` como fonte quando o /performance
        // não veio — segue sendo valor NATIVO da Adman (sem cálculo local). Só cai
        // pro fallback local (SUM adman_metrics) se AMBOS os endpoints falharem.
        if ($revenueAdman === null && isset($accountMetrics['billing'])) {
            $revenueAdman = $accountMetrics['billing'];
        }

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
            'contribution_margin_pct'   => $this->resolveMargemPct(
                $marginPctAdman,
                $isJanelaIgual,
                $isJanelaIgual ? null : $this->fetchMargemPctBaseline($custId, $periodo, $marketplace),
            ),
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
     * @return array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_source: ?string}
     */
    private function resolveField(?array $adman, bool $isJanelaIgual, callable $fallback): array
    {
        $value     = isset($adman['value']) ? (float) $adman['value'] : null;
        // Fase 117 (MPP-01, D-05): prev_value é exposto sempre que a Adman
        // mandou `.prev`, INDEPENDENTE do comparison_mode — não é gateado
        // como diff_pp (que só existe em contribution_margin_pct).
        $prevValue = isset($adman['prev']) ? (float) $adman['prev'] : null;
        $adminDiff = $adman['diff'] ?? null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'prev_value'  => $prevValue,
                'diff_pct'    => (float) $adminDiff,
                'diff_source' => 'adman_diff',
            ];
        }

        return [
            'value'       => $value,
            'prev_value'  => $prevValue,
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
     * ### Fase 117 (v21.0, 2026-07-27) — reabertura DELIBERADA do hotfix acima
     * `diff_pct` **continua** sendo sempre o `.diff` nativo da Adman — o
     * hotfix `a413e823` de 24/07 permanece válido e NÃO é revertido. Esta
     * fase acrescenta `diff_pp = value − prev_value`, um campo NOVO e
     * aditivo, porque **pontos percentuais não são expressáveis** pelo
     * `.diff` nativo (que é variação RELATIVA, não diferença em pp). Esta é
     * a decisão D1 travada da milestone v21.0. `diff_pp` ainda não é
     * consumido por ninguém nesta fase — quem consome é a Fase 119, e só
     * depois do gate do probe de estabilidade do Plano 117-02 aprovar.
     *
     * ### quick 260810-mbv (2026-08-10) — a margem volta a existir no mês corrente
     * O gate acima deixava o mês em curso SEM variação de margem: sem `diff_pp`,
     * `CompanyScoreService` devolve `margem_pontos = null` e a coluna Margem do
     * ranking, o card do profissional e a célula da tabela por empresa ficam
     * vazios o mês inteiro. Demanda do Maycon: "não está trazendo no mês atual e
     * deveria trazer".
     *
     * A saída NÃO é aceitar o `prev` nativo fora da janela-igual. Medido ao vivo
     * (LUCCMAX, 2026-08-10): na janela 08-01..10 a Adman devolve `prev = 22,51`,
     * enquanto 07-01..10 vale de fato `21,64` — o `prev` é a janela
     * IMEDIATAMENTE ANTERIOR, não o mesmo intervalo do mês passado, que é o
     * baseline com que o faturamento já se compara. Usar o `prev` cru daria
     * +4,72 p.p. no lugar dos +5,59 p.p. corretos.
     *
     * Então, no modo operacional, `$baselinePct` chega lido da PRÓPRIA Adman na
     * janela `baseline_start..baseline_end` do período
     * (`fetchMargemPctBaseline()`), e o par vira `prev_value`/`diff_pp`. Os dois
     * lados da subtração seguem sendo valor nativo — o hotfix de 2026-07-24
     * ("nunca calcular a variação local") continua respeitado; o que muda é a
     * janela apontada, não a fonte.
     *
     * `prev_value` acompanha o `diff_pp` de propósito: a tabela por empresa
     * exibe "antes → depois" ao lado da variação, e mostrar o `prev` nativo com
     * um p.p. de outra janela seria uma conta que não fecha na tela.
     *
     * `diff_pct` continua `null` fora da janela-igual — é o metadado legado
     * (`componentes.var_margem_pct` / `nota_final_legado`), e a nota oficial lê
     * `diff_pp`. Sem baseline disponível, cai no comportamento anterior.
     *
     * @param  ?array{value: ?float, diff: ?float, prev: ?float}  $marginPctAdman
     * @param  ?float  $baselinePct  margem % da janela baseline (só no modo
     *   operacional; `null` em janela-igual ou quando a Adman não respondeu).
     * @return array{value: ?float, prev_value: ?float, diff_pct: ?float, diff_pp: ?float, diff_source: ?string}
     */
    private function resolveMargemPct(?array $marginPctAdman, bool $isJanelaIgual, ?float $baselinePct = null): array
    {
        $value     = isset($marginPctAdman['value']) ? (float) $marginPctAdman['value'] : null;
        $prevValue = isset($marginPctAdman['prev']) ? (float) $marginPctAdman['prev'] : null;
        $adminDiff = $marginPctAdman['diff'] ?? null;

        // Fase 117 (D-07/MPP-02): diff_pp só nasce em janela-igual, com
        // value e prev_value AMBOS numéricos — gate independente da
        // presença de `.diff` (diff_pct pode ser null e diff_pp ainda
        // assim ser calculado, ver cenário p do teste).
        $diffPp = ($isJanelaIgual && $value !== null && $prevValue !== null)
            ? round($value - $prevValue, 2)
            : null;

        if ($isJanelaIgual && $adminDiff !== null) {
            return [
                'value'       => $value,
                'prev_value'  => $prevValue,
                'diff_pct'    => (float) $adminDiff,
                'diff_pp'     => $diffPp,
                'diff_source' => 'adman_diff',
            ];
        }

        // Modo operacional com a janela baseline lida da Adman (quick 260810-mbv).
        if (! $isJanelaIgual && $value !== null && $baselinePct !== null) {
            return [
                'value'       => $value,
                'prev_value'  => $baselinePct,
                'diff_pct'    => null,
                'diff_pp'     => round($value - $baselinePct, 2),
                'diff_source' => 'adman_janela_baseline',
            ];
        }

        return [
            'value'       => $value,
            'prev_value'  => $prevValue,
            'diff_pct'    => null,
            'diff_pp'     => $diffPp,
            'diff_source' => 'adman_indisponivel',
        ];
    }

    /**
     * Margem % da JANELA BASELINE do período, lida da própria Adman
     * (quick 260810-mbv).
     *
     * Existe só para o modo operacional: é o segundo valor nativo de que
     * `resolveMargemPct()` precisa para produzir `diff_pp` contra o mesmo
     * baseline que o faturamento já usa (dia 1..N do mês anterior), em vez do
     * `prev` da Adman, que aponta para a janela imediatamente anterior.
     *
     * CUSTO: uma chamada a mais ao endpoint detalhado por empresa, ~2s medidos,
     * e só quando o cache do diff (`adman:diff:v7`) dá miss no mês corrente. O
     * endpoint tem cache próprio por dia e retry com backoff+jitter contra 429,
     * então na prática é uma chamada por empresa por dia. Mês fechado nunca
     * passa por aqui.
     *
     * Fail-open: `fetchAccountMetricsDetailedCached()` já devolve `null` em vez
     * de lançar, e o chamador cai no comportamento anterior (sem variação).
     */
    private function fetchMargemPctBaseline(string $custId, array $periodo, string $marketplace): ?float
    {
        $inicio = $periodo['baseline_start'] ?? null;
        $fim    = $periodo['baseline_end'] ?? null;

        if (empty($inicio) || empty($fim)) {
            return null;
        }

        $metrics = $this->admanService->fetchAccountMetricsDetailedCached(
            $custId, $inicio, $fim, 1440, false, $marketplace
        );

        $valor = $metrics['percentageMargin']['value'] ?? null;

        return $valor !== null ? (float) $valor : null;
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

    /**
     * Fase 117 (D-06): o shape deixou de ser uniforme entre métricas — só
     * `contribution_margin_pct` expõe `diff_pp` (pontos percentuais só
     * fazem sentido pra uma métrica que já é percentual). Por isso não dá
     * mais pra usar `array_fill_keys()` com um único array vazio: cada
     * métrica é construída explicitamente com o shape que lhe cabe.
     */
    private function emptyMetrics(): array
    {
        $vazioComum = ['value' => null, 'prev_value' => null, 'diff_pct' => null, 'diff_source' => null];

        return [
            'revenue'                   => $vazioComum,
            'contribution_margin_value' => $vazioComum,
            'contribution_margin_pct'   => [
                'value'       => null,
                'prev_value'  => null,
                'diff_pct'    => null,
                'diff_pp'     => null,
                'diff_source' => null,
            ],
        ];
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
            'status'             => $status,
            'source'             => 'adman',
            'computed_at'        => now()->toIso8601String(),
            // Fase 117 (D-08): indicador INFORMATIVO de cobertura de diff_pp —
            // NÃO influencia `$status` nem a política de TTL de compute() (isso
            // prenderia empresa sem `prev` em TTL curto permanentemente,
            // martelando a Adman). A Fase 121 agrega este campo pra medir
            // cobertura de carteira sem refazer as chamadas. `?? null` blinda
            // contra qualquer caminho que não passe pelas construções acima.
            'diff_pp_disponivel' => ($metrics['contribution_margin_pct']['diff_pp'] ?? null) !== null,
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
