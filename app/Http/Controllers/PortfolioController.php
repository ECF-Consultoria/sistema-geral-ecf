<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\PortfolioGoal;
use App\Models\User;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PortfolioController extends Controller
{
    public function __construct(private AdmanService $adman) {}

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

        $portfolios = $todos->map(function ($item) use ($since) {
            $u    = $item['user'];
            $tipo = $item['tipo'];

            $companyIds = ($tipo === 'estrategista')
                ? $u->estrategistaCompanies()->where('active', true)->pluck('companies.id')
                : $u->consultorCompanies()->where('active', true)->pluck('companies.id');

            if ($companyIds->isEmpty()) return null;

            $uMetrics = AdmanMetric::whereIn('company_id', $companyIds)
                ->where('reference_date', '>=', $since->toDateString())
                ->get();

            $totalRevenue  = (float) $uMetrics->sum('revenue');
            $totalAdSpend  = (float) $uMetrics->sum('ad_spend');
            // TACOS REAL da carteira: razão dos totais. Null sem revenue
            // pra não exibir 0% artificial em quem só vende fora dos ads.
            $tacosCarteira = $totalRevenue > 0
                ? round(($totalAdSpend / $totalRevenue) * 100, 2)
                : null;

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'tipo'            => $tipo,
                'role'            => $u->role,
                'companies_count' => $companyIds->count(),
                'avg_tacos'       => $tacosCarteira,
                'total_revenue'   => $totalRevenue,
                'avg_margin'      => $uMetrics->count() > 0 ? round($uMetrics->avg('contribution_margin_pct'), 2) : null,
                'total_ad_spend'  => $totalAdSpend,
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
        $custIds = $rawCompanies->pluck('adman_account_id')->filter(fn($id) => !empty($id))->all();
        $grossAtual    = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo) : [];
        $grossAnterior = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFromAnterior, $dateToAnterior) : [];

        // Mapeia cada empresa pro array final
        $companies = $rawCompanies->map(function ($c) use ($isMesAtual, $sumDbAtual, $grossAtual) {
            $activeGrant = $c->grants->where('status', 'active')->first();

            // Faturamento da empresa no período: prioriza cache Adman (mês atual),
            // fallback SUM DB. Resultado é o valor MENSAL completo, não 1 dia.
            $revenue = null;
            if ($isMesAtual && $c->adman_account_id) {
                $entry = $grossAtual[$c->adman_account_id] ?? null;
                if ($entry && $entry['value'] !== null) {
                    $revenue = $entry['value'];
                }
            }
            if ($revenue === null) {
                $m = $sumDbAtual->get($c->id);
                $revenue = $m ? (float) $m->total : null;
            }

            $sumRow = $sumDbAtual->get($c->id);

            return [
                'id'                  => $c->id,
                'name'                => $c->name,
                'role'                => $c->pivot->role,
                'tacos'               => $sumRow ? round((float) $sumRow->tacos, 2)  : $c->latestMetrics?->tacos,
                'revenue'             => $revenue,
                'contribution_margin_pct' => $sumRow ? round((float) $sumRow->margem, 2) : $c->latestMetrics?->contribution_margin_pct,
                'ad_spend'            => $sumRow ? (float) $sumRow->ads             : $c->latestMetrics?->ad_spend,
                'grant_status'        => $activeGrant?->status,
                'grant_expires_at'    => $activeGrant?->expires_at?->toDateString(),
                'grant_days_remaining'=> $activeGrant?->days_remaining,
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

        // Faturamento total da carteira no período atual (usa o mesmo cache
        // Adman 30d quando mês atual; SUM DB pra mês passado).
        $totalRevenueAtual = (float) $companies->sum('revenue');

        // Faturamento da carteira no PERÍODO ANTERIOR (mês passado ou janela
        // 30-60d atrás). Mesma lógica de fallback.
        $totalRevenueAnterior = 0.0;
        foreach ($rawCompanies as $c) {
            $rev = null;
            if ($isMesAtual && $c->adman_account_id) {
                $entry = $grossAnterior[$c->adman_account_id] ?? null;
                if ($entry && $entry['value'] !== null) {
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
                    if ($isMesAtual && $c->adman_account_id) {
                        $revAtual = $grossAtual[$c->adman_account_id]['value'] ?? null;
                        $revAnt = $grossAnterior[$c->adman_account_id]['value'] ?? null;
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
