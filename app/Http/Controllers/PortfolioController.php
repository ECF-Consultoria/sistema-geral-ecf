<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\PortfolioGoal;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\PortfolioScoreService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortfolioController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private PortfolioScoreService $scoreService,
    ) {}

    // Admin vê a carteira de qualquer profissional
    public function show(Request $request, User $user)
    {
        return $this->renderPortfolio($request, $user);
    }

    // Aba "Carteira" no sidebar — bifurca por papel (quick 260610-lj6):
    //  - admin → visão consolidada de TODOS analistas/estrategistas (cards)
    //  - profissional → carteira pessoal (Portfolio/Show)
    public function own(Request $request)
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return $this->renderCarteirasConsolidadas($request);
        }
        return $this->renderPortfolio($request, $user);
    }

    /**
     * Visão consolidada de carteiras: cards de TODOS analistas e estrategistas
     * com métricas agregadas (TACOS, faturamento, margem, ad spend) da carteira
     * de cada um. Usado pela aba Carteira quando o user logado é admin.
     *
     * Fonte da verdade pra Analista vs Estrategista: cargo (slug) no pivot
     * user_setores → cargos, NÃO users.role (legacy). Lógica originalmente
     * implementada no DashboardController (quick 260610-f69) e migrada pra cá
     * no quick 260610-lj6 — bifurcação admin/não-admin na aba Carteira.
     */
    private function renderCarteirasConsolidadas(Request $request): \Inertia\Response
    {
        $period = $request->get('period', '30');
        $days   = match ($period) {
            '1'   => 1,
            '7'   => 7,
            '180' => 180,
            default => 30,
        };
        $since = now()->subDays($days);

        $analistas = User::where('active', 1)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'analista');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $estrategistas = User::where('active', 1)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'estrategista');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $todos = $analistas->map(fn($u) => ['user' => $u, 'tipo' => 'analista'])
            ->concat($estrategistas->map(fn($u) => ['user' => $u, 'tipo' => 'estrategista']));

        // Hotfix 2026-06-23 — Carteiras consolidadas divergia do /portfolio/show:
        // somava só AdmanMetric.revenue/ad_spend (DB local incompleto), enquanto
        // o renderPortfolio individual usa cache Adman gross + investment (mais
        // completo). Ex.: Ana Julia mostrava R$ 16,8M aqui vs R$ 20,6M no
        // individual. Mesma estrategia do hotfix cust_id aplicada antes.
        $dateFrom = $since->toDateString();
        $dateTo   = now()->toDateString();

        $portfolios = $todos->map(function ($item) use ($dateFrom, $dateTo) {
            $u    = $item['user'];
            $tipo = $item['tipo'];

            $companies = ($tipo === 'estrategista')
                ? $u->estrategistaCompanies()->where('active', true)->get(['companies.id', 'companies.adman_account_id', 'companies.ml_store_id'])
                : $u->consultorCompanies()->where('active', true)->get(['companies.id', 'companies.adman_account_id', 'companies.ml_store_id']);

            if ($companies->isEmpty()) return null;

            $companyIds = $companies->pluck('id');
            $custIds    = $companies->map(fn ($c) => $c->cust_id)->filter()->unique()->values()->all();

            // SUM DB (fallback completo) + cache Adman pra empresas com custId.
            $sumDb = AdmanMetric::whereIn('company_id', $companyIds)
                ->where('reference_date', '>=', $dateFrom)
                ->selectRaw('company_id, SUM(revenue) as rev, SUM(ad_spend) as ads, AVG(contribution_margin_pct) as margem')
                ->groupBy('company_id')
                ->get()
                ->keyBy('company_id');

            $gross   = $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo);
            $account = $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo);

            $totalRevenue = 0.0;
            $totalAdSpend = 0.0;
            $tacosPorEmpresa = [];
            foreach ($companies as $c) {
                $row = $sumDb->get($c->id);

                // Revenue: cache gross com fallback DB
                $rev = null;
                if ($c->cust_id && isset($gross[$c->cust_id]['value']) && $gross[$c->cust_id]['value'] !== null) {
                    $rev = (float) $gross[$c->cust_id]['value'];
                }
                if ($rev === null) {
                    $rev = (float) ($row->rev ?? 0);
                }
                $totalRevenue += $rev;

                // Ad spend: cache investment com fallback DB
                $ads = null;
                if ($c->cust_id && isset($account[$c->cust_id]['value']['investment'])) {
                    $ads = (float) $account[$c->cust_id]['value']['investment'];
                }
                if ($ads === null) {
                    $ads = (float) ($row->ads ?? 0);
                }
                $totalAdSpend += $ads;

                // TACOS desta empresa: prioriza cache (mais preciso), fallback DB.
                $tacosEmpresa = null;
                if ($c->cust_id && isset($account[$c->cust_id]['value']['tacos'])) {
                    $tacosEmpresa = (float) $account[$c->cust_id]['value']['tacos'];
                } elseif ($rev > 0) {
                    $tacosEmpresa = ($ads / $rev) * 100;
                }
                if ($tacosEmpresa !== null) {
                    $tacosPorEmpresa[] = $tacosEmpresa;
                }
            }

            // Margem media via DB (cache nao expoe margem por empresa de forma
            // estavel; SUM DB ja era a fonte do campo aqui).
            $avgMargin = $sumDb->avg('margem');

            // TACOS médio da carteira: média SIMPLES dos TACOS per-empresa
            // (mesma estratégia do DashboardController::adminDashboard, pra que
            // /portfolio e /dashboard mostrem o mesmo número pro mesmo user).
            // Hotfix 2026-06-23 — antes usava razão dos totais (ad_spend/rev),
            // matematicamente mais correto como "TACOS efetivo agregado" mas
            // divergia do Dashboard. Pragmaticamente: alinhar pra evitar dúvida.
            $tacosCarteira = !empty($tacosPorEmpresa)
                ? round(array_sum($tacosPorEmpresa) / count($tacosPorEmpresa), 2)
                : null;

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'tipo'            => $tipo,
                'role'            => $u->role,
                'companies_count' => $companyIds->count(),
                'avg_tacos'       => $tacosCarteira,
                'total_revenue'   => round($totalRevenue, 2),
                'avg_margin'      => $avgMargin !== null ? round((float) $avgMargin, 2) : null,
                'total_ad_spend'  => round($totalAdSpend, 2),
            ];
        })->filter()->sortBy('name')->values();

        return Inertia::render('Portfolio/Carteiras', [
            'user_portfolios' => $portfolios,
            'period'          => $period,
        ]);
    }

    private function renderPortfolio(Request $request, User $user): \Inertia\Response
    {
        $period = $request->get('period', now()->format('Y-m'));
        [$year, $month] = explode('-', $period);
        $year = (int) $year;
        $month = (int) $month;

        $refDate    = Carbon::create($year, $month, 1);
        $isMesAtual = $refDate->isSameMonth(now());

        // Janelas pro faturamento. Mês atual = últimos 30 dias rolling (cache
        // grossBilling Adman); mês passado = mês calendário (SUM DB histórico).
        if ($isMesAtual) {
            $dateFrom         = now()->subDays(30)->toDateString();
            $dateTo           = now()->toDateString();
            $dateFromAnterior = now()->subDays(60)->toDateString();
            $dateToAnterior   = now()->subDays(30)->toDateString();
        } else {
            $dateFrom         = $refDate->copy()->startOfMonth()->toDateString();
            $dateTo           = $refDate->copy()->endOfMonth()->toDateString();
            $dateFromAnterior = $refDate->copy()->subMonth()->startOfMonth()->toDateString();
            $dateToAnterior   = $refDate->copy()->subMonth()->endOfMonth()->toDateString();
        }

        // Empresas da carteira (todas as roles)
        $rawCompanies = $user->companies()
            ->with(['latestMetrics', 'grants'])
            ->where('active', true)
            ->withPivot('role')
            ->orderBy('name')
            ->get();

        // Pre-calcula SUM DB por empresa (fallback / mês passado)
        $companyIdsAll = $rawCompanies->pluck('id');
        $sumDbAtual = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total, SUM(ad_spend) as ads, AVG(tacos) as tacos, AVG(contribution_margin_pct) as margem')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $sumDbAnterior = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFromAnterior, $dateToAnterior])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        // Cache batch da Adman (somente no mês atual — mês passado é histórico).
        // Hotfix 2026-06-19 auditoria — usa o accessor cust_id (adman_account_id
        // OU ml_store_id) em vez de pluck cru de adman_account_id. Antes, empresas
        // com APENAS ml_store_id (ex: DINMAP) ficavam fora do batch e o lookup
        // posterior caia no DB com valor parcial — Portfolio Gustavo perdia
        // ~R$ 816K em revenue real comparado ao Dashboard (que ja usa cust_id).
        $custIds = $rawCompanies->map(fn ($c) => $c->cust_id)
            ->filter(fn ($id) => !empty($id))
            ->unique()
            ->values()
            ->all();
        $grossAtual    = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo) : [];
        // Hotfix 2026-06-19 — Portfolio agora consome account metrics do cache
        // (mesma fonte que o Dashboard). Antes, ad_spend vinha SO do SUM DB, que
        // tem fracao minima dos dados reais (Gustavo: cache=R$ 106K, DB=R$ 1.380).
        $accountAtual  = $isMesAtual ? $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo) : [];
        $grossAnterior = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFromAnterior, $dateToAnterior) : [];

        // Pre-calcula SUM DB anterior por empresa pra detectar queda MoM
        // (consumido na derivacao de status + acao_recomendada abaixo).
        $sumDbAnteriorPorEmpresa = $sumDbAnterior; // keyed by company_id

        // Mapeia cada empresa pro array final
        $companies = $rawCompanies->map(function ($c) use ($isMesAtual, $sumDbAtual, $grossAtual, $grossAnterior, $sumDbAnteriorPorEmpresa, $accountAtual) {
            $activeGrant = $c->grants->where('status', 'active')->first();
            $custId      = $c->cust_id; // accessor: adman_account_id ?: ml_store_id

            // Faturamento da empresa no período: prioriza cache Adman (mês atual),
            // fallback SUM DB. Hotfix 2026-06-19 — usa cust_id (accessor) em vez
            // de adman_account_id puro pra incluir empresas com APENAS ml_store_id
            // (que entravam no DB com valor parcial — ex: DINMAP perdia R$ 816K).
            $revenue = null;
            if ($isMesAtual && $custId) {
                $entry = $grossAtual[$custId] ?? null;
                if ($entry && ($entry['value'] ?? null) !== null) {
                    $revenue = $entry['value'];
                }
            }
            if ($revenue === null) {
                $m = $sumDbAtual->get($c->id);
                $revenue = $m ? (float) $m->total : null;
            }

            $sumRow = $sumDbAtual->get($c->id);
            // Hotfix 2026-06-19 — ad_spend agora prioriza cache Adman (chave
            // 'investment' do getCachedAccountMetricsMany), mesma fonte do
            // DashboardController::adminDashboard. Antes vinha SO do SUM DB,
            // que tem fracao minima dos dados (Gustavo: cache=R$ 106K, DB=R$ 1.380).
            $adSpendCache = null;
            if ($isMesAtual && $custId) {
                $accEntry = $accountAtual[$custId]['value'] ?? null;
                if ($accEntry && isset($accEntry['investment'])) {
                    $adSpendCache = (float) $accEntry['investment'];
                }
            }
            $adSpendNum = $adSpendCache !== null
                ? $adSpendCache
                : ($sumRow ? (float) $sumRow->ads : ((float) ($c->latestMetrics?->ad_spend ?? 0)));
            $grantDays  = $activeGrant?->days_remaining;
            $grantOk    = $activeGrant && $activeGrant->status === 'active';

            // Faturamento anterior pra detectar queda MoM (usado em status/acao).
            // Hotfix 2026-06-19 — usa cust_id (mesmo motivo do revenue atual).
            $revAnterior = null;
            if ($isMesAtual && $custId) {
                $revAnterior = $grossAnterior[$custId]['value'] ?? null;
            }
            if ($revAnterior === null) {
                $revAnterior = (float) ($sumDbAnteriorPorEmpresa[$c->id] ?? 0);
            }
            $quedaMomPct = ($revenue !== null && $revAnterior > 0)
                ? round((($revenue - $revAnterior) / $revAnterior) * 100, 2)
                : null;

            return [
                'id'                  => $c->id,
                'name'                => $c->name,
                'role'                => $c->pivot->role,
                'tacos'               => $sumRow ? round((float) $sumRow->tacos, 2)  : $c->latestMetrics?->tacos,
                'revenue'             => $revenue,
                'revenue_anterior'    => $revAnterior > 0 ? (float) $revAnterior : null,
                'queda_mom_pct'       => $quedaMomPct,
                'contribution_margin_pct' => $sumRow ? round((float) $sumRow->margem, 2) : $c->latestMetrics?->contribution_margin_pct,
                'ad_spend'            => $adSpendNum,
                'grant_status'        => $activeGrant?->status,
                'grant_expires_at'    => $activeGrant?->expires_at?->toDateString(),
                'grant_days_remaining'=> $grantDays,
                // status + acao derivados abaixo (depois que a meta de revenue
                // por empresa estiver indexada — precisa do $goals carregado).
                '_grant_ok'           => $grantOk,
                '_ad_spend_num'       => $adSpendNum,
            ];
        });

        // Metas de carteira com último resultado
        $portfolioGoals = PortfolioGoal::where('user_id', $user->id)
            ->active()
            ->with('latestResult')
            ->get()
            ->map(fn($g) => [
                'id'              => $g->id,
                'metric'          => $g->metric,
                'metric_label'    => $g->metric_label,
                'target_value'    => $g->target_value,
                'value_type'      => $g->value_type,
                'aggregation'     => $g->aggregation,
                'period_type'     => $g->period_type,
                'role'            => $g->role,
                'description'     => $g->description,
                'baseline_value'  => $g->baseline_value,
                'baseline_period' => $g->baseline_period,
                'latest_result'=> $g->latestResult ? [
                    'period'         => $g->latestResult->period,
                    'realized_value' => $g->latestResult->realized_value,
                    'target_value'   => $g->latestResult->target_value,
                    'achieved'       => $g->latestResult->achieved,
                    'companies_count'=> $g->latestResult->companies_count,
                ] : null,
            ]);

        // Metas por empresa com último resultado
        $companyIds = $companies->pluck('id');
        $goals = Goal::where('active', true)
            ->whereIn('company_id', $companyIds)
            ->with(['results' => fn($q) => $q->orderBy('period', 'desc')->limit(1)])
            ->get()
            ->map(fn($g) => [
                'id'           => $g->id,
                'company_id'   => $g->company_id,
                'metric'       => $g->metric,
                'metric_label' => $g->metric_label,
                'target_value' => $g->target_value,
                'value_type'   => $g->value_type,
                'period_type'  => $g->period_type,
                'latest_result'=> $g->results->first() ? [
                    'period'         => $g->results->first()->period,
                    'realized_value' => $g->results->first()->realized_value,
                    'achieved'       => $g->results->first()->achieved,
                ] : null,
            ]);

        // ── Quick 260619 redesign — derivar Status + Acao recomendada por empresa ──
        // Indexa metas de revenue por company_id para consulta O(1) no map.
        $metasRevenuePorEmpresa = collect($goals)
            ->filter(fn ($g) => $g['metric'] === 'revenue')
            ->keyBy('company_id');

        $companies = $companies->map(function ($c) use ($metasRevenuePorEmpresa) {
            $meta             = $metasRevenuePorEmpresa->get($c['id']);
            $metaTarget       = $meta['target_value'] ?? null;
            $metaRealizado    = $meta['latest_result']['realized_value'] ?? $c['revenue'];
            $metaAchievedPct  = ($metaTarget && $metaTarget > 0 && $metaRealizado !== null)
                ? round(((float) $metaRealizado / (float) $metaTarget) * 100, 1)
                : null;

            // Regras de status (briefing — defaults aprovados):
            //   Critico   : grant inativo OU achieved < 50%
            //   Atencao   : 50-79% OU queda MoM >=10% OU sem meta cadastrada
            //   Saudavel  : achieved >=80% E grant ativo
            $status = 'atencao';
            if (!$c['_grant_ok'] || ($metaAchievedPct !== null && $metaAchievedPct < 50)) {
                $status = 'critico';
            } elseif ($metaAchievedPct !== null && $metaAchievedPct >= 80 && $c['_grant_ok']) {
                $status = 'saudavel';
            } elseif ($c['queda_mom_pct'] !== null && $c['queda_mom_pct'] <= -10) {
                $status = 'atencao';
            }

            // Regras de acao priorizada:
            //   1) Renovar grant       (grant expira em <=15d)
            //   2) Ativar Ads          (revenue>0 e ad_spend=0)
            //   3) Atingir meta        (achieved < 50%)
            //   4) Recuperar queda     (queda MoM >= 10%)
            //   5) Manter ritmo        (saudavel)
            //   6) —                   (nenhum acionavel claro)
            $acao = '—';
            if ($c['grant_days_remaining'] !== null && $c['grant_days_remaining'] <= 15) {
                $acao = 'Renovar grant';
            } elseif (($c['revenue'] ?? 0) > 0 && $c['_ad_spend_num'] <= 0) {
                $acao = 'Ativar Ads';
            } elseif ($metaAchievedPct !== null && $metaAchievedPct < 50) {
                $acao = 'Atingir meta';
            } elseif ($c['queda_mom_pct'] !== null && $c['queda_mom_pct'] <= -10) {
                $acao = 'Recuperar queda';
            } elseif ($status === 'saudavel') {
                $acao = 'Manter ritmo';
            }

            return array_merge($c, [
                'status'               => $status,
                'acao_recomendada'     => $acao,
                'meta_target_revenue'  => $metaTarget !== null ? (float) $metaTarget : null,
                'meta_achieved_pct'    => $metaAchievedPct,
            ]);
        });

        // Faturamento total da carteira no período atual (usa o mesmo cache
        // Adman 30d quando mês atual; SUM DB pra mês passado).
        $totalRevenueAtual = (float) $companies->sum('revenue');

        // Faturamento da carteira no PERÍODO ANTERIOR (mês passado ou janela
        // 30-60d atrás). Mesma lógica de fallback. Hotfix 2026-06-19 — cust_id.
        $totalRevenueAnterior = 0.0;
        foreach ($rawCompanies as $c) {
            $rev = null;
            if ($isMesAtual && $c->cust_id) {
                $entry = $grossAnterior[$c->cust_id] ?? null;
                if ($entry && ($entry['value'] ?? null) !== null) {
                    $rev = $entry['value'];
                }
            }
            if ($rev === null) {
                $rev = (float) ($sumDbAnterior[$c->id] ?? 0);
            }
            $totalRevenueAnterior += $rev;
        }

        // Crescimento % vs período anterior. Null se não há baseline (anterior=0)
        // pra UI mostrar "—" em vez de Infinity/NaN.
        $revenueGrowthPct = null;
        if ($totalRevenueAnterior > 0) {
            $revenueGrowthPct = round((($totalRevenueAtual - $totalRevenueAnterior) / $totalRevenueAnterior) * 100, 2);
        }

        $summary = [
            'total_companies'         => $companies->count(),
            'avg_tacos'               => $companies->whereNotNull('tacos')->avg('tacos'),
            'total_revenue'           => $totalRevenueAtual,
            'total_revenue_anterior'  => $totalRevenueAnterior,
            'revenue_growth_pct'      => $revenueGrowthPct,
            'avg_margin'              => $companies->whereNotNull('contribution_margin_pct')->avg('contribution_margin_pct'),
            'total_ad_spend'          => (float) $companies->sum('ad_spend'),
        ];

        $availablePeriods = collect();
        for ($i = 0; $i < 12; $i++) {
            $availablePeriods->push(now()->subMonths($i)->format('Y-m'));
        }

        // Quick 260617-prt — Cards de alerta da carteira (4 grupos).
        // Calculados aqui pra UI consumir direto sem N+1 client-side.
        $alertas = [
            // Grants expirando em ate 30 dias (inclui ja expirados ou sem grant ativo? — so os ATIVOS proximos do limite).
            'grants_expirando_30d' => $companies
                ->filter(fn($c) => $c['grant_status'] === 'active'
                    && $c['grant_days_remaining'] !== null
                    && $c['grant_days_remaining'] <= 30)
                ->sortBy('grant_days_remaining')
                ->values()
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'grant_expires_at' => $c['grant_expires_at'],
                    'grant_days_remaining' => $c['grant_days_remaining'],
                ])
                ->all(),

            // Empresas em queda MoM — revenue atual < revenue anterior (ambos > 0).
            'empresas_em_queda' => $rawCompanies
                ->map(function ($c) use ($isMesAtual, $sumDbAtual, $sumDbAnterior, $grossAtual, $grossAnterior) {
                    $revAtual = null;
                    $revAnt = null;
                    // Hotfix 2026-06-19 — cust_id (mesmo motivo do bloco principal).
                    if ($isMesAtual && $c->cust_id) {
                        $revAtual = $grossAtual[$c->cust_id]['value'] ?? null;
                        $revAnt = $grossAnterior[$c->cust_id]['value'] ?? null;
                    }
                    if ($revAtual === null) {
                        $m = $sumDbAtual->get($c->id);
                        $revAtual = $m ? (float) $m->total : null;
                    }
                    if ($revAnt === null) {
                        $revAnt = (float) ($sumDbAnterior[$c->id] ?? 0);
                    }
                    if ($revAtual === null || $revAtual <= 0 || $revAnt <= 0 || $revAtual >= $revAnt) {
                        return null;
                    }
                    $queda_pct = round((($revAtual - $revAnt) / $revAnt) * 100, 2);
                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'revenue_atual' => (float) $revAtual,
                        'revenue_anterior' => (float) $revAnt,
                        'queda_pct' => $queda_pct,
                    ];
                })
                ->filter()
                ->sortBy('queda_pct') // queda mais severa primeiro (negativo menor)
                ->values()
                ->all(),

            // Empresas com revenue > 0 mas sem ad spend (oportunidade de venda de servico Ads).
            'empresas_sem_ad_spend' => $companies
                ->filter(fn($c) => ($c['ad_spend'] === null || $c['ad_spend'] == 0)
                    && $c['revenue'] !== null
                    && $c['revenue'] > 0)
                ->sortByDesc('revenue')
                ->values()
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'revenue' => $c['revenue'],
                ])
                ->all(),

            // Top 3 empresas por faturamento no periodo.
            'top_3_revenue' => $companies
                ->filter(fn($c) => $c['revenue'] !== null && $c['revenue'] > 0)
                ->sortByDesc('revenue')
                ->take(3)
                ->values()
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'revenue' => $c['revenue'],
                ])
                ->all(),
        ];

        // ── Quick 260619 redesign — serie temporal diaria do faturamento ──
        // Somatorio diario de revenue (AdmanMetric) de TODAS as empresas da
        // carteira no [dateFrom, dateTo]. Frontend plota como linha "Realizado"
        // junto com Meta Acumulada (target / dias_periodo * dia_index) e
        // Projecao (realizado_ate_hoje + media_diaria * dias_restantes).
        $serieRealizado = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->whereNotNull('revenue')
            ->selectRaw('reference_date as data, SUM(revenue) as total')
            ->groupBy('reference_date')
            ->orderBy('reference_date')
            ->get()
            ->keyBy(fn ($r) => (string) $r->data);

        // Meta de revenue da carteira (PortfolioGoal metric='revenue' ativo).
        $metaCarteiraModel = PortfolioGoal::where('user_id', $user->id)
            ->where('metric', 'revenue')
            ->active()
            ->orderByDesc('id')
            ->first();
        $metaCarteiraTarget    = $metaCarteiraModel?->target_value !== null
            ? (float) $metaCarteiraModel->target_value
            : null;
        $metaCarteiraRealizado = $totalRevenueAtual;
        $metaCarteiraAchieved  = ($metaCarteiraTarget && $metaCarteiraTarget > 0)
            ? round(($metaCarteiraRealizado / $metaCarteiraTarget) * 100, 1)
            : null;
        $metaCarteiraRestante  = $metaCarteiraTarget !== null
            ? max(0.0, $metaCarteiraTarget - $metaCarteiraRealizado)
            : null;

        // Constroi a serie [dateFrom .. dateTo] preenchendo gaps com 0.
        $inicio        = Carbon::parse($dateFrom);
        $fim           = Carbon::parse($dateTo);
        $diasNoPeriodo = $inicio->diffInDays($fim) + 1;
        $hoje          = now()->startOfDay();
        $revenueTimeseries = [];
        $acumulado     = 0.0;
        $dia           = 0;
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $dia++;
            $key       = $d->toDateString();
            $realDia   = isset($serieRealizado[$key]) ? (float) $serieRealizado[$key]->total : 0.0;
            $acumulado += $realDia;
            $metaAcum  = $metaCarteiraTarget !== null
                ? round(($metaCarteiraTarget / max(1, $diasNoPeriodo)) * $dia, 2)
                : null;
            $isFuturo  = $d->gt($hoje);
            $revenueTimeseries[] = [
                'date'              => $key,
                'realizado'         => $isFuturo ? null : round($acumulado, 2),
                'realizado_dia'     => $isFuturo ? null : round($realDia, 2),
                'meta_acumulada'    => $metaAcum,
                'is_futuro'         => $isFuturo,
            ];
        }
        // Projecao = realizado_hoje + media_diaria_realizada * dias_restantes.
        // Preenche `projecao` em todos os pontos: ate hoje copia realizado, depois extrapola.
        $diasAteHoje      = min($diasNoPeriodo, max(1, $inicio->diffInDays($hoje->isAfter($fim) ? $fim : $hoje) + 1));
        $mediaDiariaReal  = $acumulado > 0 ? ($acumulado / $diasAteHoje) : 0.0;
        $projecaoAcumulada = 0.0;
        foreach ($revenueTimeseries as $idx => &$pt) {
            if ($pt['is_futuro']) {
                $projecaoAcumulada += $mediaDiariaReal;
                $pt['projecao'] = round(($revenueTimeseries[$idx - 1]['projecao'] ?? $acumulado) + $mediaDiariaReal, 2);
            } else {
                $pt['projecao'] = $pt['realizado'];
            }
        }
        unset($pt);

        // ── Periodo vigente da amostra (ex: "01/06 a 22/06") ──
        $amostraFim = $hoje->isAfter($fim) ? $fim : $hoje;
        $periodoAmostra = [
            'from'       => $dateFrom,
            'to'         => $amostraFim->toDateString(),
            'from_label' => $inicio->format('d/m'),
            'to_label'   => $amostraFim->format('d/m'),
            'mes_label'  => $refDate->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
            'is_mes_atual' => $isMesAtual,
        ];

        // ── Prioridade do dia ── distinct de empresas que aparecem em qualquer
        // alerta acionavel (grants expirando, em queda, sem ad spend). Top 3 NAO
        // entra (e ranking positivo). Distinct evita inflar count quando a mesma
        // empresa cai em 2 alertas.
        //
        // Hotfix 260623 — expõe LISTA detalhada (não só count) pra UI mostrar
        // quais empresas exigem ação. Cada item: {id, name, motivo}. Quando a
        // mesma empresa cai em 2 motivos, junta com " · ".
        $motivosPorId = [];
        foreach ($alertas['grants_expirando_30d'] as $a) {
            $motivosPorId[$a['id']]['name'] = $a['name'];
            $motivosPorId[$a['id']]['motivos'][] = 'Grant vence em ' . ($a['grant_days_remaining'] ?? '?') . 'd';
        }
        foreach ($alertas['empresas_em_queda'] as $a) {
            $motivosPorId[$a['id']]['name'] = $a['name'];
            $motivosPorId[$a['id']]['motivos'][] = 'Queda ' . round($a['queda_pct'] ?? 0, 1) . '%';
        }
        foreach ($alertas['empresas_sem_ad_spend'] as $a) {
            $motivosPorId[$a['id']]['name'] = $a['name'];
            $motivosPorId[$a['id']]['motivos'][] = 'Sem Ads';
        }
        $prioridadeListaDetalhada = collect($motivosPorId)
            ->map(fn ($v, $id) => [
                'id'      => $id,
                'name'    => $v['name'],
                'motivos' => $v['motivos'],
            ])
            ->values()
            ->all();
        $prioridadeDoDia = count($prioridadeListaDetalhada);

        // Limpa flags internos (_grant_ok / _ad_spend_num) antes do payload.
        $companies = $companies->map(function ($c) {
            unset($c['_grant_ok'], $c['_ad_spend_num']);
            return $c;
        });

        // ── Quick 260623 redesign performance — Score + comparacao contextual ──
        // Conforme metodologia-desempenho-carteira.md: NAO comparar por
        // faturamento bruto (vies de tamanho). Comparar por crescimento ajustado,
        // % empresas crescendo, atingimento meta, recuperacao, execucao, qualidade.
        // Score 0-100 combina as 6 categorias com pesos do brief.
        $performanceProfissional = $this->scoreService->compute($user);

        // Comparacao contextual com pares do mesmo cargo (analista x analista
        // ou estrategista x estrategista). Identifica cargo via user_setores
        // (fonte da verdade desde quick 260610-f69; users.role eh legacy).
        $cargoSlug = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->where('us.user_id', $user->id)
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->value('c.slug');

        $comparacaoContextual = null;
        if ($cargoSlug) {
            $paresIds = DB::table('user_setores as us')
                ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                ->join('users as u', 'u.id', '=', 'us.user_id')
                ->where('c.slug', $cargoSlug)
                ->where('u.active', true)
                ->pluck('us.user_id')
                ->unique()
                ->values();

            // Calcula score de cada par (N+1 mas N tipicamente <= 10).
            $scoresPares = collect();
            foreach (User::whereIn('id', $paresIds)->get() as $par) {
                $scoresPares->put($par->id, $this->scoreService->compute($par));
            }

            if ($scoresPares->count() >= 2) {
                $meuResultado = $scoresPares->get($user->id) ?? $performanceProfissional;

                $scoresAll = $scoresPares->pluck('score')->map(fn ($s) => (float) $s)->sort()->values();
                $abaixoOuIgual = $scoresAll->filter(fn ($s) => $s <= $meuResultado['score'])->count();
                $percentil = round(($abaixoOuIgual / $scoresAll->count()) * 100);

                // Helper: mediana de uma metrica em todos os pares (ignora nulls).
                $medianaPares = function (string $caminho) use ($scoresPares) {
                    $valores = $scoresPares->map(function ($r) use ($caminho) {
                        $cur = $r['metricas'] ?? null;
                        foreach (explode('.', $caminho) as $k) {
                            if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
                            $cur = $cur[$k];
                        }
                        return is_numeric($cur) ? (float) $cur : null;
                    })->filter(fn ($v) => $v !== null)->values()->all();
                    if (empty($valores)) return null;
                    sort($valores);
                    $count = count($valores);
                    $mid   = (int) floor($count / 2);
                    return $count % 2 === 1 ? (float) $valores[$mid] : (float) (($valores[$mid - 1] + $valores[$mid]) / 2);
                };

                $relativo = function (?float $meu, ?float $mediana): string {
                    if ($meu === null || $mediana === null) return 'sem_dado';
                    if ($mediana == 0.0) {
                        if ($meu > 0)  return 'acima';
                        if ($meu < 0)  return 'abaixo';
                        return 'na_media';
                    }
                    $delta = ($meu - $mediana) / abs($mediana);
                    if ($delta >= 0.05)  return 'acima';
                    if ($delta <= -0.05) return 'abaixo';
                    return 'na_media';
                };

                $meu = fn (string $caminho) => (function () use ($meuResultado, $caminho) {
                    $cur = $meuResultado['metricas'] ?? null;
                    foreach (explode('.', $caminho) as $k) {
                        if (!is_array($cur) || !array_key_exists($k, $cur)) return null;
                        $cur = $cur[$k];
                    }
                    return is_numeric($cur) ? (float) $cur : null;
                })();

                $comparacaoContextual = [
                    'cargo_slug'      => $cargoSlug,
                    'cargo_label'     => $cargoSlug === 'analista' ? 'Analista' : 'Estrategista',
                    'tamanho_amostra' => $scoresPares->count(),
                    'percentil'       => $percentil,
                    'classificacao_top' => $percentil >= 80 ? 'Top 20%'
                        : ($percentil >= 60 ? 'Top 40%'
                            : ($percentil >= 40 ? 'Mediana'
                                : 'Inferior')),
                    'medianas' => [
                        'score'                    => round((float) $scoresAll->median(), 1),
                        'crescimento_ajustado_pct' => $medianaPares('crescimento_ajustado_pct'),
                        'empresas_em_crescimento_pct' => $medianaPares('empresas_em_crescimento.pct'),
                        'atingimento_meta_pct'     => $medianaPares('atingimento_meta.pct'),
                        'execucao_ads_pct'         => $medianaPares('execucao_ads.pct'),
                        'recuperacao_pct'          => $medianaPares('recuperacao.pct'),
                    ],
                    'relativo' => [
                        'score'                       => $relativo((float) $meuResultado['score'], (float) $scoresAll->median()),
                        'crescimento_ajustado_pct'    => $relativo($meu('crescimento_ajustado_pct'), $medianaPares('crescimento_ajustado_pct')),
                        'empresas_em_crescimento_pct' => $relativo($meu('empresas_em_crescimento.pct'), $medianaPares('empresas_em_crescimento.pct')),
                        'atingimento_meta_pct'        => $relativo($meu('atingimento_meta.pct'), $medianaPares('atingimento_meta.pct')),
                        'execucao_ads_pct'            => $relativo($meu('execucao_ads.pct'), $medianaPares('execucao_ads.pct')),
                        'recuperacao_pct'             => $relativo($meu('recuperacao.pct'), $medianaPares('recuperacao.pct')),
                    ],
                ];
            }
        }

        return Inertia::render('Portfolio/Show', [
            'portfolio_user'      => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
            'companies'           => $companies,
            'portfolio_goals'     => $portfolioGoals,
            'goals'               => $goals,
            'summary'             => $summary,
            'period'              => $period,
            'available_periods'   => $availablePeriods,
            'has_metric_data'     => $companies->contains(fn($c) => $c['revenue'] !== null),
            'portfolio_goal_metrics' => PortfolioGoal::$metricLabels,
            // Quick 260617-prt — alertas pre-calculados.
            'alertas'             => $alertas,
            // Quick 260619 redesign Carteira UI — dados novos pro novo layout.
            'revenue_timeseries'  => $revenueTimeseries,
            'meta_carteira'       => [
                'target_value'   => $metaCarteiraTarget,
                'realized_value' => $metaCarteiraRealizado,
                'achieved_pct'   => $metaCarteiraAchieved,
                'restante'       => $metaCarteiraRestante,
                'has_goal'       => $metaCarteiraTarget !== null,
                'goal_id'        => $metaCarteiraModel?->id,
            ],
            'periodo_amostra'     => $periodoAmostra,
            'prioridade_do_dia'   => $prioridadeDoDia,
            'prioridade_lista'    => $prioridadeListaDetalhada,
            // Quick 260623 redesign performance — substitui comparacao_equipe antigo.
            'performance_profissional' => $performanceProfissional,
            'comparacao_contextual'    => $comparacaoContextual,
        ]);
    }

    // CRUD de metas de carteira (admin only)
    public function storeGoal(Request $request, User $user)
    {
        $data = $request->validate([
            'role'         => 'required|in:consultor,mentor',
            'metric'       => 'required|in:' . implode(',', array_keys(PortfolioGoal::$metricLabels)),
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'aggregation'  => 'required|in:avg,sum',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
        ]);

        $data['user_id'] = $user->id;

        if (in_array($data['metric'], PortfolioGoal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        } else {
            $data['value_type'] = $data['value_type'] ?? 'currency';
        }

        $goal = PortfolioGoal::create([...$data, 'active' => true]);

        // Captura snapshot baseline do período atual para rastrear progresso futuro
        $basePeriod = now()->format('Y-m');
        [$by, $bm] = explode('-', $basePeriod);
        $companies = $goal->getCarteira($basePeriod);
        $values = $companies
            ->map(fn($c) => PortfolioGoal::extractMetricValue($goal->metric, $c->id, (int)$by, (int)$bm))
            ->filter()
            ->values();

        if ($values->isNotEmpty()) {
            $baseline = $goal->aggregation === 'sum' ? $values->sum() : $values->avg();
            $goal->update(['baseline_value' => round($baseline, 4), 'baseline_period' => $basePeriod]);
        }

        return back()->with('success', 'Meta de carteira criada.');
    }

    public function updateGoal(Request $request, PortfolioGoal $goal)
    {
        $data = $request->validate([
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'aggregation'  => 'required|in:avg,sum',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
        ]);

        if (in_array($goal->metric, PortfolioGoal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        }

        $goal->update($data);

        return back()->with('success', 'Meta de carteira atualizada.');
    }

    public function destroyGoal(PortfolioGoal $goal)
    {
        $goal->update(['active' => false]);
        return back()->with('success', 'Meta de carteira removida.');
    }
}
