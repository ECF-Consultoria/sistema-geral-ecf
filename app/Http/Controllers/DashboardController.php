<?php

namespace App\Http\Controllers;

use App\Models\AdmanCampaignMetric;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private AdmanService $adman) {}

    public function index(Request $request)
    {
        $user = $request->user();

        // Equipe de publicação (não-admin): redireciona para a primeira página acessível
        if ($user->publication_role && !$user->isAdmin()) {
            $destinations = [
                'dashboard'   => route('mlb.dashboard'),
                'meu_painel'  => route('mlb.meu-painel'),
                'treinamento' => route('mlb.treinamentos'),
                'publicacoes' => route('mlb.publicacoes'),
                'historico'   => route('mlb.historico'),
                'empresas'    => route('mlb.empresas'),
            ];
            foreach ($destinations as $perm => $url) {
                if ($user->hasPubPermission($perm)) {
                    return redirect($url);
                }
            }
        }

        $period = $request->get('period', '30');
        $since  = $this->getSince($period);

        if ($user->isAdmin()) {
            return $this->adminDashboard($request, $since, $period);
        }

        return $this->userDashboard($user, $since, $period);
    }

    private function getSince(string $period): Carbon
    {
        return match ($period) {
            '1'   => now()->subDay(),
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };
    }

    private function adminDashboard(Request $request, Carbon $since, string $period)
    {
        $companyFilter = $request->get('company_id');
        $consultorFilter = $request->get('consultor_id');
        $estrategistaFilter = $request->get('estrategista_id') ?? $request->get('mentor_id'); // back-compat com chamadas antigas

        $companiesQuery = Company::with(['latestMetrics', 'consultor', 'estrategista'])->where('active', true);

        if ($companyFilter) $companiesQuery->where('id', $companyFilter);
        if ($consultorFilter) $companiesQuery->whereHas('consultor', fn($q) => $q->where('users.id', $consultorFilter));
        if ($estrategistaFilter) $companiesQuery->whereHas('estrategista', fn($q) => $q->where('users.id', $estrategistaFilter));

        $companies = $companiesQuery->get();

        $metrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
            ->where('reference_date', '>=', $since->toDateString())
            ->orderBy('reference_date')
            ->get();

        // Faturamento 30d por empresa — SUM(adman_metrics.revenue) do DB.
        // Era pra ser chamada direta à Adman mas N chamadas síncronas
        // estouram memory_limit. Para valores exatos da Adman, abrir a
        // tela de detalhe (show) de cada empresa.
        $revenue30dByCompany = AdmanMetric::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('reference_date', '>=', now()->subDays(30)->toDateString())
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $revenueChart = $metrics->groupBy('reference_date')
            ->map(fn($g) => ['date' => $g->first()->reference_date->format('d/m'), 'revenue' => $g->sum('revenue')])
            ->values();

        $tacosChart = $metrics->groupBy('reference_date')
            ->map(fn($g) => ['date' => $g->first()->reference_date->format('d/m'), 'tacos' => round($g->avg('tacos'), 2)])
            ->values();

        $npsResponses = NpsSurvey::with('response')
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();

        $avgNps = $npsResponses->avg(fn($s) => $s->response?->score_overall) ?? 0;

        $meetings = Meeting::whereIn('company_id', $companies->pluck('id'))
            ->where('scheduled_at', '>=', $since)
            ->where('status', 'completed')
            ->get();

        $absenteeismRate = $meetings->count() > 0
            ? $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count() / $meetings->count() * 100
            : 0;

        $avgTacos = $metrics->avg('tacos') ?? 0;
        $totalRevenue = $metrics->sum('revenue');
        $avgMargin = $metrics->avg('contribution_margin_pct') ?? 0;
        $productsWithoutCost = $metrics->avg(fn($m) => $m->products_without_cost_pct) ?? 0;

        // Métricas de campanha (ads)
        $companyIds = $companies->pluck('id');
        $campaignMetrics = AdmanCampaignMetric::whereIn('company_id', $companyIds)
            ->where('reference_date', '>=', $since->toDateString())
            ->get();

        $lastSyncDate = $metrics->max('reference_date');

        $npsDistribution = [
            'promotores' => $npsResponses->filter(fn($s) => ($s->response?->score_overall ?? 0) >= 9)->count(),
            'neutros'    => $npsResponses->filter(fn($s) => ($s->response?->score_overall ?? 0) >= 7 && ($s->response?->score_overall ?? 0) < 9)->count(),
            'detratores' => $npsResponses->filter(fn($s) => ($s->response?->score_overall ?? 0) < 7)->count(),
        ];

        $users = User::where('active', true)->where('role', '!=', 'admin')->get();
        $allCompanies = Company::where('active', true)->get(['id', 'name']);

        $ranking = $this->buildRanking($users, $since);

        $userPortfolios = $users->map(function ($u) use ($metrics, $companies) {
            $uCompanies = $companies->filter(function ($c) use ($u) {
                return $u->isMentor()
                    ? $c->estrategista->contains('id', $u->id)
                    : $c->consultor->contains('id', $u->id);
            });
            if ($uCompanies->isEmpty()) return null;
            $uCompanyIds = $uCompanies->pluck('id')->toArray();
            $uMetrics = $metrics->whereIn('company_id', $uCompanyIds);
            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'role'            => $u->role,
                'companies_count' => $uCompanies->count(),
                'avg_tacos'       => $uMetrics->count() > 0 ? round($uMetrics->avg('tacos'), 2) : null,
                'total_revenue'   => $uMetrics->sum('revenue'),
                'avg_margin'      => $uMetrics->count() > 0 ? round($uMetrics->avg('contribution_margin_pct'), 2) : null,
                'total_ad_spend'  => $uMetrics->sum('ad_spend'),
            ];
        })->filter()->values();

        $totalNetBilling    = $metrics->sum('net_billing');
        $totalSoldQuantity  = $metrics->sum('sold_quantity');
        $totalAdSpend       = $metrics->sum('ad_spend');
        $avgProfitShare     = $metrics->avg('profit_share') ?? 0;

        // Sugadores: total pendentes + top 5 empresas
        $sugadoresPendentes = Sugador::pendentes()->count();
        $sugadoresTopEmpresas = Sugador::pendentes()
            ->selectRaw('company_id, COUNT(*) as total')
            ->groupBy('company_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('company:id,name')
            ->get()
            ->map(fn($row) => [
                'company_id'   => $row->company_id,
                'company_name' => $row->company?->name ?? '—',
                'total'        => (int) $row->total,
            ]);

        return Inertia::render('Dashboard/Admin', [
            'stats' => [
                'total_companies'          => $companies->count(),
                'avg_tacos'                => round($avgTacos, 2),
                'avg_nps'                  => round($avgNps, 1),
                'absenteeism_rate'         => round($absenteeismRate, 2),
                'total_revenue'            => $totalRevenue,
                'total_net_billing'        => $totalNetBilling,
                'total_sold_quantity'      => (int) $totalSoldQuantity,
                'total_ad_spend'           => $totalAdSpend,
                'avg_margin'               => round($avgMargin, 2),
                'avg_profit_share'         => round($avgProfitShare, 2),
                'products_without_cost_pct' => round($productsWithoutCost, 2),
                'last_sync_date'           => $lastSyncDate?->format('d/m/Y'),
            ],
            'ads_stats' => [
                'total_investment' => round($campaignMetrics->sum('investment'), 2),
                'total_revenue'    => round($campaignMetrics->sum('revenue'), 2),
                'total_clicks'     => $campaignMetrics->sum('clicks'),
                'total_impressions' => $campaignMetrics->sum('impressions'),
                'total_sold'       => $campaignMetrics->sum('sold_quantity'),
                'avg_cpc'          => $campaignMetrics->count() > 0 ? round($campaignMetrics->avg('cpc'), 2) : null,
                'avg_acos'         => $campaignMetrics->count() > 0 ? round($campaignMetrics->avg('acos'), 2) : null,
                'avg_roas'         => $campaignMetrics->count() > 0 ? round($campaignMetrics->avg('roas'), 2) : null,
                'avg_tacos'        => $campaignMetrics->count() > 0 ? round($campaignMetrics->avg('tacos'), 2) : null,
                'has_data'         => $campaignMetrics->count() > 0,
            ],
            'revenue_chart'  => $revenueChart,
            'tacos_chart'    => $tacosChart,
            'nps_distribution' => $npsDistribution,
            'period'         => $period,
            'filters'        => compact('companyFilter', 'consultorFilter', 'estrategistaFilter'),
            'users'          => $users->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role]),
            'companies_list' => $allCompanies,
            'ranking'         => $ranking->take(5)->values(),
            'user_portfolios' => $userPortfolios,
            'companies_performance' => $companies->map(fn($c) => [
                'id'       => $c->id,
                'name'     => $c->name,
                'tacos'    => $c->latestMetrics?->tacos,
                // revenue 30d via SUM(adman_metrics) — pra valor exato da Adman,
                // abrir a tela de detalhe da empresa (faz chamada direta).
                'revenue'  => (float) ($revenue30dByCompany[$c->id] ?? 0),
                'margin'   => $c->latestMetrics?->contribution_margin_pct,
                'consultor' => $c->consultor->first()?->name,
                'estrategista' => $c->estrategista->first()?->name,
            ]),
            'sugadores_stats' => [
                'total_pendentes' => $sugadoresPendentes,
                'top_empresas'    => $sugadoresTopEmpresas,
            ],
        ]);
    }

    private function buildRanking($users, Carbon $since): \Illuminate\Support\Collection
    {
        return $users->map(function ($u) use ($since) {
            $companyIds = $u->isMentor()
                ? $u->estrategistaCompanies()->pluck('companies.id')
                : $u->consultorCompanies()->pluck('companies.id');

            if ($companyIds->isEmpty()) {
                $companyIds = $u->companies()->pluck('companies.id');
            }

            $surveys = NpsSurvey::with('response')
                ->whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $since)
                ->get();

            $scoreField = $u->isMentor() ? 'score_mentor' : 'score_consultant';
            $avgNps = $surveys->count() > 0
                ? round($surveys->avg(fn($s) => $s->response?->$scoreField ?? 0), 1)
                : null;

            $meetingsTotal = Meeting::whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('scheduled_at', '>=', $since)
                ->count();

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'role'            => $u->role,
                'companies_count' => $companyIds->count(),
                'avg_nps'         => $avgNps,
                'total_meetings'  => $meetingsTotal,
                'nps_responses'   => $surveys->count(),
            ];
        })->sortByDesc(fn($u) => $u['avg_nps'] ?? -1)->values();
    }

    private function userDashboard(User $user, Carbon $since, string $period)
    {
        $companies = $user->companies()->with(['latestMetrics', 'goals'])->get();

        // Sugadores na carteira do usuário (pendentes)
        $sugadoresCarteira = Sugador::pendentes()
            ->whereIn('company_id', $companies->pluck('id'))
            ->count();

        $metrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
            ->where('reference_date', '>=', $since->toDateString())
            ->get();

        // 30d via SUM do DB — N chamadas síncronas estouravam memory_limit.
        $revenue30dByCompany = AdmanMetric::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('reference_date', '>=', now()->subDays(30)->toDateString())
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $npsResponses = NpsSurvey::with('response')
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();

        $meetings = Meeting::whereIn('company_id', $companies->pluck('id'))
            ->where('scheduled_at', '>=', $since)
            ->where('status', 'completed')
            ->get();

        $absenteeismRate = $meetings->count() > 0
            ? $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count() / $meetings->count() * 100
            : 0;

        $myNpsSurveys = NpsSurvey::with(['company', 'response'])
            ->where('generated_by', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $myPpas = $user->isMentor()
            ? Ppa::with('company')->where('mentor_id', $user->id)->orderBy('created_at', 'desc')->take(5)->get()
            : collect();

        $scoreField = $user->isMentor() ? 'score_mentor' : 'score_consultant';

        return Inertia::render('Dashboard/User', [
            'stats' => [
                'total_companies' => $companies->count(),
                'avg_tacos' => round($metrics->avg('tacos') ?? 0, 2),
                'avg_nps' => round($npsResponses->avg(fn($s) => $s->response?->$scoreField ?? 0) ?? 0, 1),
                'absenteeism_rate' => round($absenteeismRate, 2),
                'total_revenue' => $metrics->sum('revenue'),
            ],
            'period' => $period,
            'companies' => $companies->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'tacos' => $c->latestMetrics?->tacos,
                // revenue 30d via SUM(adman_metrics)
                'revenue' => (float) ($revenue30dByCompany[$c->id] ?? 0),
                'goals' => $c->goals->where('active', true)->values(),
            ]),
            'my_surveys' => $myNpsSurveys,
            'my_ppas' => $myPpas,
            'sugadores_pendentes_carteira' => $sugadoresCarteira,
        ]);
    }
}

