<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\PortfolioGoal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quick 260623 redesign performance — Score 0-100 + 6 métricas justas pra avaliar
 * analistas e estrategistas, conforme metodologia-desempenho-carteira.md.
 *
 * Princípio: NÃO premiar tamanho da carteira (faturamento bruto bias quem
 * recebe carteiras grandes). Premia evolução, eficiência, execução e
 * consistência. Métricas e pesos:
 *
 *   30%  Crescimento ajustado: (rev_30d - rev_30d_anterior) / rev_30d_anterior
 *        com cap em ±20% pra evitar outliers (1 empresa que cresceu 500% nao
 *        domina). 0% real → 50 pontos; +20% → 100; -20% → 0.
 *   20%  % empresas em crescimento: empresas elegíveis com rev_atual > rev_anterior.
 *   20%  Atingimento da meta da carteira (PortfolioGoal revenue ativo), cap 100%.
 *   15%  Recuperação de empresas em queda: das que estavam em queda no
 *        período anterior (30d-60d vs 60d-90d), quantas voltaram a crescer (0-30d).
 *   10%  Execução: cobertura de ações operacionais — empresas que estavam sem
 *        Ads no período anterior e ativaram no atual.
 *    5%  Qualidade: NPS médio + frequência de reuniões − absenteísmo.
 *
 * Quando uma categoria não tem dados (ex: sem meta, sem NPS), o peso dela é
 * redistribuído proporcionalmente entre as outras pra não puxar o score pra
 * baixo de quem não tem dados (vs quem tem dados ruins).
 *
 * Empresa elegível pro cálculo de crescimento (regra do brief):
 *   - ativa
 *   - tem revenue >0 no período anterior (há baseline)
 *   - OU tem revenue >0 no período atual (entrou neste período)
 * Excluímos empresas zeradas em ambos (geralmente sem cust_id, sem dados).
 */
class PortfolioScoreService
{
    public function __construct(private AdmanService $adman) {}

    /**
     * Computa todas as métricas + score + classificação pra um user.
     *
     * @return array{
     *   tem_base_comparativa: bool,
     *   empresas_eligiveis: int,
     *   empresas_carteira: int,
     *   metricas: array,
     *   score: float,
     *   classificacao: string,
     *   periodo: array{from: string, to: string, from_anterior: string, to_anterior: string},
     * }
     */
    public function compute(User $user): array
    {
        // ── Janelas ──
        // Hotfix smoke test 260623: DB local guarda 30d rolling; cache Adman
        // gross/account so retorna janela rolling. Comparar 0-30d vs 30-60d
        // era impossivel (gerava +6997% pro Gustavo). Solucao: usar a coluna
        // AdmanMetric.revenue_prev_period (vem da API Adman ja com baseline
        // do dia anterior equivalente). Cada metrica diaria carrega revenue
        // ATUAL + revenue ANTERIOR no payload da Adman — usa isso.
        //
        // Atual:   0-30 dias (rolling)
        // Half1:   0-15 dias (segunda metade — usado pra detectar recuperacao)
        // Half2:   15-30 dias (primeira metade)
        $hoje          = now();
        $atualFrom     = $hoje->copy()->subDays(30)->toDateString();
        $atualTo       = $hoje->toDateString();
        $half1From     = $hoje->copy()->subDays(15)->toDateString();
        $half2From     = $hoje->copy()->subDays(30)->toDateString();
        $half2To       = $hoje->copy()->subDays(15)->toDateString();

        $companies = $user->companies()->with('grants')->where('active', true)->get();
        $companyIds = $companies->pluck('id');
        $custIds    = $companies->map(fn ($c) => $c->cust_id)->filter()->unique()->values()->all();

        // ── Revenue + revenue_prev_period por empresa (janela atual) ──
        // Soma diaria: tanto revenue (a empresa fez no periodo) quanto
        // revenue_prev_period (a empresa fez no mesmo numero de dias antes
        // do periodo). Adman calcula isso por dia; somar dia-a-dia da uma
        // aproximacao confiavel do crescimento 30d vs 30d anteriores SEM
        // depender de cache historico do nosso DB.
        $sumAtual = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$atualFrom, $atualTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Cache atual via Adman gross (mais robusto que SUM DB; preenche custIds
        // missing como DINMAP fix do hotfix anterior).
        $grossCache = $this->adman->getCachedGrossBillingsMany($custIds, $atualFrom, $atualTo);

        // Revenue final por empresa: cache gross (mais completo) com fallback
        // pra SUM DB. revenue_prev_period vem SEMPRE do DB porque o cache nao
        // expoe esse campo separado.
        $revAtual = [];
        $revAnterior = [];
        foreach ($companies as $c) {
            $row = $sumAtual->get($c->id);
            $cacheRev = null;
            if ($c->cust_id && isset($grossCache[$c->cust_id]['value']) && $grossCache[$c->cust_id]['value'] !== null) {
                $cacheRev = (float) $grossCache[$c->cust_id]['value'];
            }
            $revAtual[$c->id]    = $cacheRev ?? (float) ($row->rev ?? 0);
            $revAnterior[$c->id] = (float) ($row->rev_prev ?? 0);
        }

        // ── Empresas eligíveis pra cálculo de crescimento ──
        $eligiveis = $companies->filter(function ($c) use ($revAtual, $revAnterior) {
            $atual = $revAtual[$c->id]    ?? 0;
            $ant   = $revAnterior[$c->id] ?? 0;
            return ($atual > 0) || ($ant > 0);
        });

        // Base comparativa exige 5 empresas elegíveis (brief) — abaixo disso o
        // score sai mas marcamos `tem_base_comparativa=false`.
        $temBase = $eligiveis->count() >= 5;

        // ── 1. Crescimento ajustado da carteira ──
        $totalAtual    = $eligiveis->sum(fn ($c) => $revAtual[$c->id] ?? 0);
        $totalAnterior = $eligiveis->sum(fn ($c) => $revAnterior[$c->id] ?? 0);
        $crescimentoAjustadoPct = ($totalAnterior > 0)
            ? round((($totalAtual - $totalAnterior) / $totalAnterior) * 100, 2)
            : null;

        // ── 2. Crescimento mediano por empresa (mitiga outliers) ──
        $crescimentosPorEmpresa = $eligiveis
            ->map(function ($c) use ($revAtual, $revAnterior) {
                $atual = (float) ($revAtual[$c->id]    ?? 0);
                $ant   = (float) ($revAnterior[$c->id] ?? 0);
                if ($ant <= 0) return null;
                return round((($atual - $ant) / $ant) * 100, 2);
            })
            ->filter(fn ($v) => $v !== null)
            ->values();
        $crescimentoMedianoPct = $crescimentosPorEmpresa->isNotEmpty()
            ? round($this->mediana($crescimentosPorEmpresa->all()), 2)
            : null;

        // ── 3. % de empresas em crescimento ──
        $emCrescimentoCount = $eligiveis
            ->filter(function ($c) use ($revAtual, $revAnterior) {
                $atual = (float) ($revAtual[$c->id]    ?? 0);
                $ant   = (float) ($revAnterior[$c->id] ?? 0);
                return $ant > 0 && $atual > $ant;
            })
            ->count();
        $emCrescimentoPct = $eligiveis->count() > 0
            ? round(($emCrescimentoCount / $eligiveis->count()) * 100, 1)
            : 0.0;

        // ── 4. Atingimento da meta da carteira ──
        // Prioridade: PortfolioGoal de revenue ativo (meta da carteira inteira).
        // Fallback (hotfix 260623): se nao houver PortfolioGoal revenue, soma
        // as metas individuais ativas (Goal.metric=revenue) das empresas da
        // carteira. Realizado = soma do revenue das empresas QUE TEM meta
        // (compara like-for-like). Assim, basta cadastrar uma meta numa
        // empresa qualquer pra a categoria aparecer no score.
        $metaModel = PortfolioGoal::where('user_id', $user->id)
            ->where('metric', 'revenue')
            ->active()
            ->orderByDesc('id')
            ->first();

        $metaTarget       = null;
        $metaRealizado    = null;
        $metaAtingidaPct  = null;
        $metaOrigem       = null;

        if ($metaModel?->target_value !== null) {
            // Caminho A: meta de carteira (target absoluto, realizado = total da carteira).
            $metaTarget    = (float) $metaModel->target_value;
            $metaRealizado = (float) $totalAtual;
            $metaOrigem    = 'portfolio';
        } else {
            // Caminho B: soma das metas de empresas individuais.
            $goalsIndividuais = \App\Models\Goal::where('active', true)
                ->where('metric', 'revenue')
                ->whereIn('company_id', $companyIds)
                ->get(['company_id', 'target_value']);
            if ($goalsIndividuais->isNotEmpty()) {
                $targets = $goalsIndividuais->mapWithKeys(fn ($g) => [$g->company_id => (float) $g->target_value]);
                $metaTarget    = (float) $targets->sum();
                $metaRealizado = (float) $companies
                    ->filter(fn ($c) => $targets->has($c->id))
                    ->sum(fn ($c) => $revAtual[$c->id] ?? 0);
                $metaOrigem    = 'empresas:' . $goalsIndividuais->count();
            }
        }

        if ($metaTarget !== null && $metaTarget > 0) {
            $metaAtingidaPct = round(($metaRealizado / $metaTarget) * 100, 1);
        }

        // ── 5. Recuperação de empresas em queda ──
        // Hotfix 260623: sem historico >30d, comparamos as 2 metades da janela
        // de 30d. Em queda na 1a metade (15-30d): sum(rev) da 1a metade <
        // sum(rev_prev_period) da 1a metade. Recuperada: sum(rev) da 2a metade
        // > sum(rev) da 1a metade.
        $sumHalf1 = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$half1From, $atualTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $sumHalf2 = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$half2From, $half2To])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $emQuedaIds = $companies->filter(function ($c) use ($sumHalf2) {
            $row = $sumHalf2->get($c->id);
            if (!$row) return false;
            $rev = (float) $row->rev;
            $prev = (float) $row->rev_prev;
            return $prev > 0 && $rev > 0 && $rev < $prev;
        })->pluck('id');

        $recuperadasCount = $companies->filter(function ($c) use ($emQuedaIds, $sumHalf1, $sumHalf2) {
            if (!$emQuedaIds->contains($c->id)) return false;
            $h1 = $sumHalf1->get($c->id);
            $h2 = $sumHalf2->get($c->id);
            if (!$h1 || !$h2) return false;
            return (float) $h1->rev > (float) $h2->rev;
        })->count();

        $recuperacaoPct = $emQuedaIds->count() > 0
            ? round(($recuperadasCount / $emQuedaIds->count()) * 100, 1)
            : null;

        // ── 6. Categoria 'execucao_ads' DESCONTINUADA (quick 260623 v3) ──
        // Premissa errada: o sistema marca "sem Ads" baseado em cache Adman
        // investment + SUM DB ad_spend. Quando ambos retornam 0 (cache MISS
        // + sync nao rodou), gera falso positivo pra empresas que ATIVAMENTE
        // tem Ads na Adman mas o nosso lado nao sincronizou. Como a ECF
        // gerencia Ads em 100% das empresas, qualquer "sem Ads" reportado
        // tendia a ser ruido. Categoria fica null aqui — o peso de 10% e
        // redistribuido automaticamente pelas outras 5 categorias via
        // scoreFinal(). Mantida a chave 'execucao_ads' no retorno por
        // compat com codigos que leem (todos null pra UI esconder).
        $comAdsAtivosCount = null;
        $execucaoPct       = null;

        // ── 7. Qualidade ──
        $surveys = NpsSurvey::with('response')
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $atualFrom)
            ->get();
        $scoreField = $user->isMentor() ? 'score_estrategista' : 'score_analista';
        $avgNps = $surveys->count() > 0
            ? round($surveys->avg(fn ($s) => $s->response?->$scoreField ?? 0), 2)
            : null;

        $meetings = Meeting::whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->where('scheduled_at', '>=', $atualFrom)
            ->get();
        $absencias = $meetings->filter(fn ($m) => !$m->consultant_present || !$m->mentor_present)->count();
        $absenteismoPct = $meetings->count() > 0
            ? round(($absencias / $meetings->count()) * 100, 1)
            : null;

        // ── Pontuações por categoria (0-100) ──
        $pontosCrescimento = $crescimentoAjustadoPct !== null
            ? $this->normalizarCrescimento($crescimentoAjustadoPct)
            : null;
        $pontosEmCrescimento = $eligiveis->count() > 0 ? $emCrescimentoPct : null;
        $pontosMeta          = $metaAtingidaPct !== null ? min(100.0, (float) $metaAtingidaPct) : null;
        $pontosRecuperacao   = $recuperacaoPct;
        $pontosExecucao      = $execucaoPct;
        $pontosQualidade     = $this->scoreQualidade($avgNps, $meetings->count(), $absenteismoPct);

        // ── Score com redistribuição de pesos pra categorias sem dado ──
        $pontos = [
            'crescimento_ajustado' => ['valor' => $pontosCrescimento,  'peso' => 30],
            'empresas_crescendo'   => ['valor' => $pontosEmCrescimento,'peso' => 20],
            'atingimento_meta'     => ['valor' => $pontosMeta,         'peso' => 20],
            'recuperacao'          => ['valor' => $pontosRecuperacao,  'peso' => 15],
            'execucao'             => ['valor' => $pontosExecucao,     'peso' => 10],
            'qualidade'            => ['valor' => $pontosQualidade,    'peso' =>  5],
        ];
        $score = $this->scoreFinal($pontos);
        $classif = $this->classificar($score);

        return [
            'tem_base_comparativa' => $temBase,
            'empresas_eligiveis'   => $eligiveis->count(),
            'empresas_carteira'    => $companies->count(),
            'metricas' => [
                'crescimento_ajustado_pct'       => $crescimentoAjustadoPct,
                'crescimento_mediano_empresa_pct'=> $crescimentoMedianoPct,
                'empresas_em_crescimento' => [
                    'count' => $emCrescimentoCount,
                    'total' => $eligiveis->count(),
                    'pct'   => $emCrescimentoPct,
                ],
                'atingimento_meta' => [
                    'pct'            => $metaAtingidaPct,
                    'target_value'   => $metaTarget,
                    'realized_value' => $metaRealizado,
                    'origem'         => $metaOrigem, // 'portfolio' | 'empresas:N' | null
                ],
                'recuperacao' => [
                    'recuperadas'   => $recuperadasCount,
                    'em_queda'      => $emQuedaIds->count(),
                    'pct'           => $recuperacaoPct,
                ],
                'execucao_ads' => [
                    'com_ads_ativos' => $comAdsAtivosCount,
                    'total'          => $eligiveis->count(),
                    'pct'            => $execucaoPct,
                ],
                'qualidade' => [
                    'avg_nps'        => $avgNps,
                    'meetings'       => $meetings->count(),
                    'absenteismo_pct'=> $absenteismoPct,
                ],
                'faturamento' => [
                    'atual'    => round((float) $totalAtual, 2),
                    'anterior' => round((float) $totalAnterior, 2),
                ],
            ],
            'pontos_categoria' => $pontos,
            'score'            => $score,
            'classificacao'    => $classif,
            'periodo' => [
                'from'   => $atualFrom,
                'to'     => $atualTo,
                // half1=0-15d, half2=15-30d (usado em recuperacao + execucao).
                'half1_from' => $half1From,
                'half2_from' => $half2From,
                'half2_to'   => $half2To,
            ],
        ];
    }

    /**
    /**
     * Crescimento ajustado → pontos. Linear com cap em ±20%.
     * -20% → 0; 0% → 50; +20% → 100. Valores fora do range são clamped.
     */
    private function normalizarCrescimento(float $pct): float
    {
        $clamped = max(-20.0, min(20.0, $pct));
        return round((($clamped + 20.0) / 40.0) * 100.0, 2);
    }

    /**
     * Qualidade combinada (NPS 0-5 + presença).
     * NPS: peso 70%. Conversão: nps/5 * 100.
     * Presença: peso 30%. Conversão: (100 - absenteismo_pct) ou 100 se sem reuniões.
     * Retorna null se NÃO houve NPS nem reuniões — categoria sem dado.
     */
    private function scoreQualidade(?float $avgNps, int $meetingsCount, ?float $absenteismoPct): ?float
    {
        if ($avgNps === null && $meetingsCount === 0) return null;

        $pNps = $avgNps !== null ? max(0.0, min(100.0, ($avgNps / 5.0) * 100.0)) : null;
        $pPresenca = $absenteismoPct !== null ? max(0.0, 100.0 - $absenteismoPct) : null;

        if ($pNps !== null && $pPresenca !== null) {
            return round($pNps * 0.7 + $pPresenca * 0.3, 2);
        }
        return round($pNps ?? $pPresenca ?? 0, 2);
    }

    /**
     * Score final 0-100 com redistribuição de pesos pra categorias sem dado.
     */
    private function scoreFinal(array $pontos): float
    {
        $totalPeso = 0;
        $somaPonderada = 0.0;
        foreach ($pontos as $p) {
            if ($p['valor'] === null) continue;
            $totalPeso     += $p['peso'];
            $somaPonderada += $p['peso'] * (float) $p['valor'];
        }
        if ($totalPeso === 0) return 0.0;
        return round($somaPonderada / $totalPeso, 1);
    }

    private function classificar(float $score): string
    {
        if ($score >= 85) return 'excelente';
        if ($score >= 70) return 'bom';
        if ($score >= 55) return 'atencao';
        return 'critico';
    }

    private function mediana(array $valores): float
    {
        if (empty($valores)) return 0.0;
        sort($valores);
        $count = count($valores);
        $mid   = (int) floor($count / 2);
        if ($count % 2 === 1) return (float) $valores[$mid];
        return (float) (($valores[$mid - 1] + $valores[$mid]) / 2);
    }
}
