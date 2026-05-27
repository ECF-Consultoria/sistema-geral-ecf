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
use Illuminate\Support\Facades\Log;
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

        // ─── Cards 30d (Faturamento, Invest. Ads, TACOS médio) ────────────────
        //
        // Política tudo-ou-nada: se TODAS as empresas com cust_id (ml_store_id
        // ?: adman_account_id) têm cache /performance + /accounts/metrics
        // quente, usa valores EXATOS da Adman. Se QUALQUER empresa está em
        // cache miss/erro, descarta o cache do conjunto inteiro e cai para
        // SUM(adman_metrics) no DB local para TODAS as empresas.
        //
        // Por que tudo-ou-nada: mesclar "Adman exato" para algumas empresas com
        // "SUM DB" para outras (estado anterior) produzia totais oscilantes em
        // ±R$ 20M entre requests, conforme a composição cache-hit muda. Agora
        // a leitura é determinística por request: ou tudo cache, ou tudo DB.
        //
        // Por que cust_id (não só adman_account_id): empresas com apenas
        // ml_store_id (cadastradas via Comercial pós-Phase 13) eram silenciosamente
        // excluídas com a lookup antiga (if !$c->adman_account_id continue) →
        // apareciam zeradas no dashboard. cust_id casa com a chave usada por
        // RefreshGrossBillingCacheJob (writer) e AdmanService::syncCompany.
        //
        // Mantém o cache key consistente com RefreshGrossBillingCacheJob:
        // range = now()->subDays(30)..now() (today inclusive).
        $dateFrom30d = now()->subDays(30)->toDateString();
        $dateTo30d   = now()->toDateString();

        // Batch read: gross + account metrics em 2 round-trips Redis.
        $custIds30d = $companies->pluck('cust_id')->filter()->values()->all();
        $grossBatch30d   = $this->adman->getCachedGrossBillingsMany($custIds30d, $dateFrom30d, $dateTo30d);
        $accountBatch30d = $this->adman->getCachedAccountMetricsMany($custIds30d, $dateFrom30d, $dateTo30d);

        // Detecta cache completo: TODA empresa com cust_id precisa ter VALOR
        // REAL no cache (não null, não ERROR_SENTINEL). Empresas sem cust_id
        // são ignoradas no critério — contribuem 0 em ambos os modos.
        $grossCacheCompleto   = true;
        $accountCacheCompleto = true;
        foreach ($companies as $c) {
            $custId = $c->cust_id;
            if (!$custId) continue;
            $g = $grossBatch30d[$custId]   ?? ['value' => null];
            $a = $accountBatch30d[$custId] ?? ['value' => null];
            if ($g['value'] === null) $grossCacheCompleto   = false;
            if ($a['value'] === null) $accountCacheCompleto = false;
        }

        // Se algo está faltando, dispara warm-up do cache em background — não
        // afeta este request, mas próximas requests podem ter cache completo.
        if (!$grossCacheCompleto || !$accountCacheCompleto) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatch();
        }

        // Revenue 30d por empresa — SEM mistura: ou todas pelo cache Adman, ou
        // todas pelo SUM DB. tacos/acos/margem por empresa seguem o mesmo
        // critério separado (cache /accounts/metrics).
        $revenue30dByCompany = [];
        $acos30dByCompany    = [];
        $tacos30dByCompany   = [];
        $margin30dByCompany  = [];

        if ($grossCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) {
                    $revenue30dByCompany[$c->id] = 0.0;
                    continue;
                }
                $revenue30dByCompany[$c->id] = (float) $grossBatch30d[$custId]['value'];
            }
        } else {
            // Cache incompleto → SUM(adman_metrics.revenue) 30d para TODAS as
            // empresas. Usa o MESMO range do cache (BETWEEN $dateFrom30d AND
            // $dateTo30d) pra os dois modos baterem o máximo possível.
            $sumDb30d = AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFrom30d, $dateTo30d])
                ->whereNotNull('revenue')
                ->selectRaw('company_id, SUM(revenue) as total')
                ->groupBy('company_id')
                ->pluck('total', 'company_id');

            foreach ($companies as $c) {
                $revenue30dByCompany[$c->id] = (float) ($sumDb30d[$c->id] ?? 0);
            }
            Log::info('[Dashboard] revenue 30d em fallback DB (cache Adman incompleto)');
        }

        // ACOS / TACOS / margem por empresa — só ficam preenchidos quando cache
        // /accounts/metrics está completo. No fallback DB esses campos por
        // empresa ficam null (a tabela de performance no front exibe latestMetrics
        // como fallback secundário; ver companies_performance abaixo). O TOTAL
        // (avg_tacos, total_ad_investment_30d) sim tem fallback DB agregado
        // logo abaixo.
        if ($accountCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) continue;
                $v = $accountBatch30d[$custId]['value'];
                $acos30dByCompany[$c->id]   = $v['acos']              ?? null;
                $tacos30dByCompany[$c->id]  = $v['tacos']             ?? null;
                $margin30dByCompany[$c->id] = $v['percentage_margin'] ?? null;
            }
        }

        // Gera série temporal CONTÍNUA: todas as datas do período no eixo X,
        // preenchendo 0 onde não houve sync. Antes o chart pulava dias
        // (algumas empresas com sync OK, outras não) e ficava visualmente
        // desbalanceado — picos altos contrastando com gaps zerados.
        $byDate     = $metrics->groupBy(fn($m) => $m->reference_date->toDateString());
        $tacosByDate = $byDate->map(fn($g) => round($g->avg('tacos') ?? 0, 2));
        $revByDate   = $byDate->map(fn($g) => (float) $g->sum('revenue'));

        $revenueChart = collect();
        $tacosChart   = collect();
        $cursor = $since->copy()->startOfDay();
        $end    = now()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $lbl = $cursor->format('d/m');
            $revenueChart->push(['date' => $lbl, 'revenue' => (float) ($revByDate[$key] ?? 0)]);
            $tacosChart->push(['date' => $lbl, 'tacos' => (float) ($tacosByDate[$key] ?? 0)]);
            $cursor->addDay();
        }
        $revenueChart = $revenueChart->values();
        $tacosChart   = $tacosChart->values();

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

        // Card 'Faturamento' = soma do que foi escolhido acima (cache Adman OU
        // SUM DB, tudo-ou-nada). Determinístico por request.
        $totalRevenue = array_sum($revenue30dByCompany);

        // Card 'Invest. Ads 30d' e 'TACOS médio': mesma política tudo-ou-nada
        // do account cache. Quando cache incompleto, ambos caem para o DB
        // local — total_ad_spend somado em 1 query e TACOS recalculado como
        // (total_ad_spend / total_revenue) * 100. Antes do fix esses dois
        // cards excluíam silenciosamente empresas em cache cold, oscilando
        // o denominador e gerando totais aleatórios.
        if ($accountCacheCompleto) {
            $tacosValues = array_filter($tacos30dByCompany, fn($v) => $v !== null);
            $avgTacos    = !empty($tacosValues) ? array_sum($tacosValues) / count($tacosValues) : 0;

            $totalAdInvestment30d = 0.0;
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) continue;
                $accEntry = $accountBatch30d[$custId] ?? null;
                if ($accEntry && $accEntry['value'] !== null) {
                    $totalAdInvestment30d += (float) ($accEntry['value']['investment'] ?? 0);
                }
            }
        } else {
            // Fallback DB agregado: SUM(ad_spend) e TACOS = ad_spend/revenue * 100.
            // Usa o mesmo range do cache para coerência entre os dois modos.
            $totalAdInvestment30d = (float) AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFrom30d, $dateTo30d])
                ->sum('ad_spend');
            $avgTacos = $totalRevenue > 0
                ? ($totalAdInvestment30d / $totalRevenue) * 100
                : 0;
            Log::info('[Dashboard] avg_tacos + total_ad_investment_30d em fallback DB (cache Adman incompleto)');
        }

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

        $userPortfolios = $users->map(function ($u) use ($metrics, $companies, $revenue30dByCompany) {
            $uCompanies = $companies->filter(function ($c) use ($u) {
                return $u->isMentor()
                    ? $c->estrategista->contains('id', $u->id)
                    : $c->consultor->contains('id', $u->id);
            });
            if ($uCompanies->isEmpty()) return null;
            $uCompanyIds = $uCompanies->pluck('id')->toArray();
            $uMetrics = $metrics->whereIn('company_id', $uCompanyIds);

            // Carteira do user = soma do revenue30dByCompany já decidido pelo
            // critério tudo-ou-nada (cache Adman OU SUM DB) — herda o mesmo
            // modo do total global, sem mistura.
            $uTotalRevenue = 0.0;
            foreach ($uCompanyIds as $cid) {
                $uTotalRevenue += $revenue30dByCompany[$cid] ?? 0;
            }

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'role'            => $u->role,
                'companies_count' => $uCompanies->count(),
                'avg_tacos'       => $uMetrics->count() > 0 ? round($uMetrics->avg('tacos'), 2) : null,
                'total_revenue'   => $uTotalRevenue,
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
                'total_ad_investment_30d'  => $totalAdInvestment30d,
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
                // ACOS/TACOS/margem do cache /accounts/metrics (30d, exato da
                // Adman); fallback latestMetrics (1 dia) só se cache cold.
                'acos'     => $acos30dByCompany[$c->id]   ?? null,
                'tacos'    => $tacos30dByCompany[$c->id]  ?? $c->latestMetrics?->tacos,
                'revenue'  => (float) ($revenue30dByCompany[$c->id] ?? 0),
                'margin'   => $margin30dByCompany[$c->id] ?? $c->latestMetrics?->contribution_margin_pct,
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

        // 30d cache com política tudo-ou-nada (mesma estratégia do
        // adminDashboard, agora via cust_id = ml_store_id ?: adman_account_id).
        $dateFrom30d = now()->subDays(30)->toDateString();
        $dateTo30d   = now()->toDateString();

        $custIds30d = $companies->pluck('cust_id')->filter()->values()->all();
        $grossBatch30d   = $this->adman->getCachedGrossBillingsMany($custIds30d, $dateFrom30d, $dateTo30d);
        $accountBatch30d = $this->adman->getCachedAccountMetricsMany($custIds30d, $dateFrom30d, $dateTo30d);

        $grossCacheCompleto   = true;
        $accountCacheCompleto = true;
        foreach ($companies as $c) {
            $custId = $c->cust_id;
            if (!$custId) continue;
            $g = $grossBatch30d[$custId]   ?? ['value' => null];
            $a = $accountBatch30d[$custId] ?? ['value' => null];
            if ($g['value'] === null) $grossCacheCompleto   = false;
            if ($a['value'] === null) $accountCacheCompleto = false;
        }

        if (!$grossCacheCompleto || !$accountCacheCompleto) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatch();
        }

        $revenue30dByCompany = [];
        $tacos30dByCompany   = [];

        if ($grossCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) {
                    $revenue30dByCompany[$c->id] = 0.0;
                    continue;
                }
                $revenue30dByCompany[$c->id] = (float) $grossBatch30d[$custId]['value'];
            }
        } else {
            $sumDb30d = AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFrom30d, $dateTo30d])
                ->whereNotNull('revenue')
                ->selectRaw('company_id, SUM(revenue) as total')
                ->groupBy('company_id')
                ->pluck('total', 'company_id');

            foreach ($companies as $c) {
                $revenue30dByCompany[$c->id] = (float) ($sumDb30d[$c->id] ?? 0);
            }
            Log::info('[Dashboard] revenue 30d (userDashboard) em fallback DB (cache Adman incompleto)');
        }

        if ($accountCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) continue;
                $tacos30dByCompany[$c->id] = $accountBatch30d[$custId]['value']['tacos'] ?? null;
            }
        }

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
                'tacos' => $tacos30dByCompany[$c->id] ?? $c->latestMetrics?->tacos,
                'revenue' => (float) ($revenue30dByCompany[$c->id] ?? 0),
                'goals' => $c->goals->where('active', true)->values(),
            ]),
            'my_surveys' => $myNpsSurveys,
            'my_ppas' => $myPpas,
            'sugadores_pendentes_carteira' => $sugadoresCarteira,
        ]);
    }
}
