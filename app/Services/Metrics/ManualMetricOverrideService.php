<?php

namespace App\Services\Metrics;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fase 136 Plano 03 — decorator que substitui o eixo `manual` sobre o
 * resultado JÁ PRONTO do `MetricDiffDispatcher::compute()`.
 *
 * ### Por que é um decorator DEPOIS do dispatcher, e não um terceiro ramo do match()
 * `MetricDiffDispatcher::compute()` roteia por FONTE TÉCNICA ('adman'|
 * 'shopee') — o `match()` ali é a defesa de tampering (T-109-02): qualquer
 * valor fora da whitelist lança `InvalidArgumentException`. "Manual" NÃO é
 * uma terceira fonte técnica — é um EIXO ORTOGONAL, aplicável tanto sobre
 * 'adman' quanto sobre 'shopee' (D-07: faturamento e margem alternam
 * auto/manual de forma independente na mesma célula, e a fonte técnica
 * continua sendo a mesma para o que não foi marcado manual). Enfiar "manual"
 * dentro do `match()` do dispatcher misturaria dois conceitos que precisam
 * continuar separáveis e quebraria a whitelist que protege contra tampering.
 * Por isso este service roda DEPOIS do dispatcher, substituindo só os blocos
 * da célula marcada manual — nunca dentro do roteador.
 *
 * ### D-EXC-01 — exceção estreita e declarada ao hotfix de 2026-07-24
 * O hotfix `a413e823` (2026-07-24) travou que "a variação de MARGEM (R$ e %)
 * vem SEMPRE do valor que a própria Adman disponibiliza (`.diff` nativo),
 * NUNCA de cálculo local" (ver
 * `AdmanMetricDiffService::resolveMargemPct()`, linhas 308-325). Margem
 * derivada de CMV digitado à mão é, por definição, cálculo local — e É uma
 * exceção a essa regra. **A exceção vale SOMENTE para a célula marcada
 * `manual`** (`ativo=true` em `desempenho_metricas_manuais`), é
 * IDENTIFICÁVEL pelo `diff_source='manual_mes_calendario'` (nunca
 * `'adman_diff'`, `'calculated_fallback'` nem `'adman_janela_baseline'`),
 * roda FORA de `resolveMargemPct()` — substitui o bloco já retornado por
 * `AdmanMetricDiffService`/`ShopeeMetricDiffService`, nunca altera o cálculo
 * interno deles — e **NÃO relaxa a regra do caminho automático**: uma célula
 * sem lançamento manual continua 100% imune a este service (ver "caminho
 * rápido" abaixo). Qualquer sessão futura que ler o hotfix nos learnings e
 * cogitar reverter isto: NÃO reverta — é exceção intencional, travada pelo
 * usuário em `136-CONTEXT.md` (D-EXC-01).
 *
 * ### Custo de HTTP contido — só quem tem lançamento paga (T-136-17)
 * `AdmanMetricDiffService::compute()` pode fazer até 2 chamadas HTTP
 * síncronas por (empresa, janela) no cache miss, e cada janela tem
 * `cacheKey()` próprio. O "caminho rápido" de `aplicar()` devolve
 * `$resultado` intocado — SEM nenhuma chamada extra a
 * `MetricPeriodResolver::resolve()` nem ao dispatcher — quando a empresa não
 * tem NENHUM lançamento manual ativo na célula. O custo extra de
 * `totalMesCheio()` (resolver + computar a janela de mês cheio) só existe
 * para a dezena de células que de fato têm lançamento — não para a base
 * inteira da carteira.
 *
 * @see .planning/phases/136-.../136-03-PLAN.md Task 1
 * @see .planning/phases/136-.../136-CONTEXT.md D-01, D-02, D-05, D-06, D-07, D-08, D-EXC-01
 */
class ManualMetricOverrideService
{
    /**
     * Memo em memória (escopo da instância/request) de `totalMesCheio()`,
     * chaveado por "{company_id}:{periodKey}:{fonte}" — o eixo faturamento e
     * o eixo margem da MESMA célula frequentemente pedem a mesma janela do
     * mês anterior; sem o memo, ela seria resolvida e computada duas vezes.
     *
     * @var array<string, ?float>
     */
    private array $memoTotalMesCheio = [];

    public function __construct(
        private MetricPeriodResolver $periodResolver,
        private MetricDiffDispatcher $diffDispatcher,
    ) {
    }

    /**
     * Pré-carrega, em UMA única query, os lançamentos ATIVOS da competência
     * `$mes` **e** do mês calendário anterior (a cascata de D-06 precisa dos
     * dois lados) para o conjunto de empresas informado. Chamar UMA vez para
     * a carteira inteira — nunca dentro de um loop por empresa (evita N+1).
     *
     * A chave inclui a `fonte` porque a mesma empresa pode ter lançamento em
     * DOIS canais, atendidos por profissionais diferentes. Sem ela, o valor
     * digitado para a Shopee seria aplicado também a quem computa 'adman' na
     * mesma conta — contaminando a nota de um time com número do outro.
     *
     * @param  array<int, int>  $companyIds
     * @return Collection<string, DesempenhoMetricaManual>  chave
     *         "{company_id}:{fonte}:{metrica}:{Y-m}"
     */
    public function carregarLancamentos(Carbon $mes, array $companyIds): Collection
    {
        if ($companyIds === []) {
            return collect();
        }

        $mesAtualStr    = $mes->copy()->startOfMonth()->toDateString();
        $mesAnteriorStr = $mes->copy()->startOfMonth()->subMonthNoOverflow()->toDateString();

        $linhas = DesempenhoMetricaManual::query()
            ->where('ativo', true)
            ->whereIn('company_id', $companyIds)
            ->where(function ($query) use ($mesAtualStr, $mesAnteriorStr) {
                $query->whereDate('mes_referencia', $mesAtualStr)
                    ->orWhereDate('mes_referencia', $mesAnteriorStr);
            })
            ->get();

        return $linhas->keyBy(
            fn (DesempenhoMetricaManual $linha): string => "{$linha->company_id}:{$linha->fonte}:{$linha->metrica}:{$linha->mes_referencia->format('Y-m')}"
        );
    }

    /**
     * Aplica o override sobre o resultado do dispatcher para a célula
     * `(company, mes)`.
     *
     * Comportamento, na ordem:
     * 1. Sem lançamento em nenhum dos dois eixos → caminho rápido: devolve
     *    `$resultado` com `quality.faturamento_fonte`/`quality.margem_fonte`
     *    = `'auto'`, sem nenhuma chamada extra.
     * 2. Eixo faturamento com lançamento manual (D-01/D-02/D-07): substitui
     *    `metrics.revenue` inteiro. O dado da API do mês corrente NUNCA é
     *    consultado nem comparado — D-02, manual nunca reverte sozinho.
     * 3. Eixo margem com lançamento de CMV (D-05/D-08): deriva
     *    `contribution_margin_value`/`contribution_margin_pct` do faturamento
     *    efetivo da célula (sempre em mês cheio) menos o CMV lançado.
     * 4. `quality.faturamento_fonte`/`quality.margem_fonte` marcam `'manual'`
     *    no eixo substituído e `'auto'` no outro — chaves já existentes de
     *    `quality` nunca são removidas.
     *
     * @param  array  $resultado  Shape do `MetricDiffDispatcher::compute()`
     *         — `metrics.revenue`/`metrics.contribution_margin_value`/
     *         `metrics.contribution_margin_pct`, mais `quality`.
     * @param  Collection<string, DesempenhoMetricaManual>  $lancamentos  Pré-carregada por `carregarLancamentos()`.
     * @return array  Mesmo shape recebido, com os blocos do eixo manual substituídos.
     */
    public function aplicar(Company $company, Carbon $mes, array $periodo, string $fonte, array $resultado, Collection $lancamentos): array
    {
        $mesAtual    = $mes->copy()->startOfMonth();
        $mesAnterior = $mesAtual->copy()->subMonthNoOverflow();

        // A `$fonte` recebida é o canal que ESTE profissional está computando
        // (sai dos vínculos da carteira dele). Casar a chave por ela é o que
        // impede o lançamento de um canal de vazar para a nota do outro.
        $chaveAtualFat = "{$company->id}:{$fonte}:" . DesempenhoMetricaManual::METRICA_FATURAMENTO . ":{$mesAtual->format('Y-m')}";
        $chaveAtualCmv = "{$company->id}:{$fonte}:" . DesempenhoMetricaManual::METRICA_MARGEM_CMV . ":{$mesAtual->format('Y-m')}";
        $chaveAntFat   = "{$company->id}:{$fonte}:" . DesempenhoMetricaManual::METRICA_FATURAMENTO . ":{$mesAnterior->format('Y-m')}";
        $chaveAntCmv   = "{$company->id}:{$fonte}:" . DesempenhoMetricaManual::METRICA_MARGEM_CMV . ":{$mesAnterior->format('Y-m')}";

        $faturamentoAtualManual    = $lancamentos->get($chaveAtualFat);
        $cmvAtualManual            = $lancamentos->get($chaveAtualCmv);
        $faturamentoAnteriorManual = $lancamentos->get($chaveAntFat);
        $cmvAnteriorManual         = $lancamentos->get($chaveAntCmv);

        // Caminho rápido — nenhum lançamento na célula: devolve o resultado
        // do dispatcher intocado, com o sinal de quality em 'auto' nos dois
        // eixos, e SEM nenhuma chamada a resolve()/compute() extra.
        if ($faturamentoAtualManual === null && $cmvAtualManual === null) {
            $resultado['quality']['faturamento_fonte'] = 'auto';
            $resultado['quality']['margem_fonte']      = 'auto';

            return $resultado;
        }

        // ─── Eixo faturamento (D-01/D-02/D-07) ─────────────────────────────
        if ($faturamentoAtualManual !== null) {
            $value     = (float) $faturamentoAtualManual->valor;
            $prevValue = $faturamentoAnteriorManual !== null
                ? (float) $faturamentoAnteriorManual->valor
                : $this->totalMesCheio($company, $mesAnterior->format('Y-m'), $fonte);

            // D-02: o dado da API do mês corrente NUNCA é consultado nem
            // comparado aqui — não existe condição sob a qual ele vença uma
            // célula marcada manual.
            $resultado['metrics']['revenue'] = [
                'value'       => $value,
                'prev_value'  => $prevValue,
                'diff_pct'    => $this->diffPctGuardado($value, $prevValue),
                'diff_source' => 'manual_mes_calendario',
            ];
            $resultado['quality']['faturamento_fonte'] = 'manual';
        } else {
            $resultado['quality']['faturamento_fonte'] = 'auto';
        }

        // ─── Eixo margem (D-05/D-08) ────────────────────────────────────────
        if ($cmvAtualManual !== null) {
            // Faturamento efetivo da célula, SEMPRE em mês cheio (D-08): o
            // valor manual do eixo faturamento se ele estiver marcado
            // manual; senão o total do mês calendário inteiro pela API.
            $faturamentoEfetivo = $faturamentoAtualManual !== null
                ? (float) $faturamentoAtualManual->valor
                : $this->totalMesCheio($company, $mesAtual->format('Y-m'), $fonte);

            $cmv = (float) $cmvAtualManual->valor;

            if ($faturamentoEfetivo === null || $faturamentoEfetivo <= 0) {
                // D-08: sem faturamento em nenhuma das duas fontes, o CMV
                // sozinho não produz margem.
                $resultado['metrics']['contribution_margin_value'] = [
                    'value'       => null,
                    'prev_value'  => null,
                    'diff_pct'    => null,
                    'diff_source' => 'manual_mes_calendario',
                ];
                $resultado['metrics']['contribution_margin_pct'] = [
                    'value'       => null,
                    'prev_value'  => null,
                    'diff_pct'    => null,
                    'diff_pp'     => null,
                    'diff_source' => 'manual_mes_calendario',
                ];
                $resultado['quality']['motivos_manual'] = array_values(array_unique(array_merge(
                    $resultado['quality']['motivos_manual'] ?? [],
                    ['margem_manual_sem_faturamento'],
                )));
            } else {
                $margemValorAtual = round($faturamentoEfetivo - $cmv, 2);
                $margemPctAtual   = round((($faturamentoEfetivo - $cmv) / $faturamentoEfetivo) * 100.0, 2);

                // Base do mês anterior: exige CMV MANUAL do mês anterior —
                // não há fallback de API para CMV (Adman/Shopee não expõem
                // um "CMV" comparável a esta célula). O faturamento efetivo
                // anterior segue a MESMA cascata de D-06.
                $prevValor = null;
                $prevPct   = null;
                $diffPp    = null;

                if ($cmvAnteriorManual !== null) {
                    $faturamentoEfetivoAnterior = $faturamentoAnteriorManual !== null
                        ? (float) $faturamentoAnteriorManual->valor
                        : $this->totalMesCheio($company, $mesAnterior->format('Y-m'), $fonte);

                    if ($faturamentoEfetivoAnterior !== null && $faturamentoEfetivoAnterior > 0) {
                        $cmvAnterior = (float) $cmvAnteriorManual->valor;
                        $prevValor   = round($faturamentoEfetivoAnterior - $cmvAnterior, 2);
                        $prevPct     = round((($faturamentoEfetivoAnterior - $cmvAnterior) / $faturamentoEfetivoAnterior) * 100.0, 2);
                        $diffPp      = round($margemPctAtual - $prevPct, 2);
                    }
                }

                $resultado['metrics']['contribution_margin_value'] = [
                    'value'       => $margemValorAtual,
                    'prev_value'  => $prevValor,
                    'diff_pct'    => $this->diffPctGuardado($margemValorAtual, $prevValor),
                    'diff_source' => 'manual_mes_calendario',
                ];
                $resultado['metrics']['contribution_margin_pct'] = [
                    'value'       => $margemPctAtual,
                    'prev_value'  => $prevPct,
                    'diff_pct'    => $this->diffPctGuardado($margemPctAtual, $prevPct),
                    'diff_pp'     => $diffPp,
                    'diff_source' => 'manual_mes_calendario',
                ];
            }

            $resultado['quality']['margem_fonte'] = 'manual';
        } else {
            $resultado['quality']['margem_fonte'] = 'auto';
        }

        return $resultado;
    }

    /**
     * "Total daquele mês" (D-05/D-06/D-08) — SEMPRE em mês cheio, resolvido
     * pelo ÚNICO ponto de resolução de período do núcleo
     * (`MetricPeriodResolver::resolve(['period_key' => 'YYYY-MM'])`), NUNCA
     * por cálculo manual de dias/`startOfMonth()`. Lê `metrics.revenue.value`
     * do dispatcher sobre essa janela — NUNCA o `diff_pct` dela, que compara
     * contra a janela-de-mesmo-tamanho anterior a ela própria, não contra o
     * mês calendário anterior inteiro.
     *
     * Memoizado por `"{company_id}:{periodKey}:{fonte}"` — a mesma janela é
     * pedida por mais de um eixo dentro da MESMA chamada de `aplicar()`
     * (ex.: `prev_value` do faturamento e faturamento efetivo anterior da
     * margem pedem a mesma janela do mês anterior).
     */
    private function totalMesCheio(Company $company, string $periodKey, string $fonte): ?float
    {
        $chaveMemo = "{$company->id}:{$periodKey}:{$fonte}";

        if (array_key_exists($chaveMemo, $this->memoTotalMesCheio)) {
            return $this->memoTotalMesCheio[$chaveMemo];
        }

        $periodoMesCheio   = $this->periodResolver->resolve(['period_key' => $periodKey]);
        $resultadoMesCheio = $this->diffDispatcher->compute($company, $periodoMesCheio, $fonte);

        return $this->memoTotalMesCheio[$chaveMemo] = $resultadoMesCheio['metrics']['revenue']['value'] ?? null;
    }

    /**
     * `(atual - anterior) / anterior * 100`, guardado contra baseline
     * ausente/zero/negativo (nunca -100%/divisão-por-zero artificial) —
     * MESMA semântica de `ShopeeMetricDiffService::diffPctGuardado()`
     * (linhas 189-196) e `AdmanMetricDiffService::diffPctGuardado()` (linha
     * 647). Os dois arquivos têm cópia própria e privada deste método;
     * replicar a MESMA semântica aqui segue a convenção já existente do
     * projeto em vez de criar um acoplamento novo entre os três services.
     */
    private function diffPctGuardado(?float $atual, ?float $anterior): ?float
    {
        if ($atual === null || $anterior === null || $anterior <= 0) {
            return null;
        }

        return round((($atual - $anterior) / $anterior) * 100.0, 2);
    }
}
