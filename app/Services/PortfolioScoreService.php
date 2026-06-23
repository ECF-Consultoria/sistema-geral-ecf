<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\PortfolioGoal;
use App\Models\User;
use Carbon\Carbon;
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
        // Atual: 0-30 dias (rolling)
        // Anterior: 30-60 dias
        // Retro:    60-90 dias (usado pra recuperação)
        $hoje          = now();
        $atualFrom     = $hoje->copy()->subDays(30)->toDateString();
        $atualTo       = $hoje->toDateString();
        $anteriorFrom  = $hoje->copy()->subDays(60)->toDateString();
        $anteriorTo    = $hoje->copy()->subDays(30)->toDateString();
        $retroFrom     = $hoje->copy()->subDays(90)->toDateString();
        $retroTo       = $hoje->copy()->subDays(60)->toDateString();

        $companies = $user->companies()->with('grants')->where('active', true)->get();
        $companyIds = $companies->pluck('id');
        $custIds    = $companies->map(fn ($c) => $c->cust_id)->filter()->unique()->values()->all();

        // ── Revenue por empresa (3 janelas) ──
        $revAtual    = $this->revenuePorEmpresa($companies, $custIds, $atualFrom, $atualTo, $companyIds);
        $revAnterior = $this->revenuePorEmpresa($companies, [], $anteriorFrom, $anteriorTo, $companyIds);
        $revRetro    = $this->revenuePorEmpresa($companies, [], $retroFrom, $retroTo, $companyIds);

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
        $metaModel = PortfolioGoal::where('user_id', $user->id)
            ->where('metric', 'revenue')
            ->active()
            ->orderByDesc('id')
            ->first();
        $metaTarget    = $metaModel?->target_value !== null ? (float) $metaModel->target_value : null;
        $metaRealizado = (float) $totalAtual;
        $metaAtingidaPct = ($metaTarget && $metaTarget > 0)
            ? round(($metaRealizado / $metaTarget) * 100, 1)
            : null;

        // ── 5. Recuperação de empresas em queda ──
        // Em queda no período anterior = revAnterior < revRetro (ambos > 0).
        // Recuperada = revAtual > revAnterior.
        $emQuedaIds = $companies->filter(function ($c) use ($revAnterior, $revRetro) {
            $ant   = (float) ($revAnterior[$c->id] ?? 0);
            $retro = (float) ($revRetro[$c->id]    ?? 0);
            return $retro > 0 && $ant > 0 && $ant < $retro;
        })->pluck('id');

        $recuperadasCount = $companies->filter(function ($c) use ($emQuedaIds, $revAtual, $revAnterior) {
            if (!$emQuedaIds->contains($c->id)) return false;
            return ((float) ($revAtual[$c->id] ?? 0)) > ((float) ($revAnterior[$c->id] ?? 0));
        })->count();

        $recuperacaoPct = $emQuedaIds->count() > 0
            ? round(($recuperadasCount / $emQuedaIds->count()) * 100, 1)
            : null;

        // ── 6. Execução: empresas que ativaram Ads ──
        // Sem Ads no anterior (sum ad_spend = 0) e com Ads no atual (>0).
        $adsAnteriorPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$anteriorFrom, $anteriorTo])
            ->selectRaw('company_id, SUM(ad_spend) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');
        $adsAtualPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$atualFrom, $atualTo])
            ->selectRaw('company_id, SUM(ad_spend) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $semAdsAnterior = $companies->filter(function ($c) use ($adsAnteriorPorEmpresa, $revAnterior) {
            $ads = (float) ($adsAnteriorPorEmpresa[$c->id] ?? 0);
            $rev = (float) ($revAnterior[$c->id] ?? 0);
            return $ads <= 0 && $rev > 0;
        });

        $ativaramAds = $semAdsAnterior->filter(function ($c) use ($adsAtualPorEmpresa) {
            return ((float) ($adsAtualPorEmpresa[$c->id] ?? 0)) > 0;
        })->count();

        $execucaoPct = $semAdsAnterior->count() > 0
            ? round(($ativaramAds / $semAdsAnterior->count()) * 100, 1)
            : null;

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
                ],
                'recuperacao' => [
                    'recuperadas'   => $recuperadasCount,
                    'em_queda'      => $emQuedaIds->count(),
                    'pct'           => $recuperacaoPct,
                ],
                'execucao_ads' => [
                    'ativaram'      => $ativaramAds,
                    'oportunidades' => $semAdsAnterior->count(),
                    'pct'           => $execucaoPct,
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
                'from'          => $atualFrom,
                'to'            => $atualTo,
                'from_anterior' => $anteriorFrom,
                'to_anterior'   => $anteriorTo,
            ],
        ];
    }

    /**
     * Revenue por empresa numa janela. Prioriza cache Adman gross (mesma
     * estratégia do PortfolioController pós hotfix cust_id) com fallback SUM DB.
     * O cache só funciona pra janela 0-30d (mês atual); janelas históricas
     * usam SUM DB direto.
     *
     * @return array<int, float>
     */
    private function revenuePorEmpresa(Collection $companies, array $custIds, string $from, string $to, Collection $companyIds): array
    {
        // SUM DB (sempre, pra ter fallback)
        $sumDb = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$from, $to])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        // Cache só se for a janela atual (com custIds passado)
        $cache = !empty($custIds)
            ? $this->adman->getCachedGrossBillingsMany($custIds, $from, $to)
            : [];

        $out = [];
        foreach ($companies as $c) {
            $rev = null;
            if ($c->cust_id && isset($cache[$c->cust_id]['value']) && $cache[$c->cust_id]['value'] !== null) {
                $rev = (float) $cache[$c->cust_id]['value'];
            }
            if ($rev === null) {
                $rev = (float) ($sumDb[$c->id] ?? 0);
            }
            $out[$c->id] = $rev;
        }
        return $out;
    }

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
