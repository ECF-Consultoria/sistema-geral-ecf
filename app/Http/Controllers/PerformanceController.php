<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period', '30');
        $since = match ($period) {
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };

        $users = User::where('active', true)->where('role', '!=', 'admin')->get();

        $ranking = $users->map(function ($u) use ($since) {
            // Usa empresas específicas pelo papel do usuário para cálculos de NPS
            $companyIds = $u->isMentor()
                ? $u->mentorCompanies()->pluck('companies.id')
                : $u->consultorCompanies()->pluck('companies.id');

            // Fallback: se não tem empresas no papel específico, usa todas
            if ($companyIds->isEmpty()) {
                $companyIds = $u->companies()->pluck('companies.id');
            }

            $surveys = NpsSurvey::with('response')
                ->whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $since)
                ->get();

            // Usa o score específico do papel: mentor → score_mentor, consultor → score_consultant
            $scoreField = $u->isMentor() ? 'score_mentor' : 'score_consultant';
            $avgNps = $surveys->count() > 0
                ? round($surveys->avg(fn($s) => $s->response?->$scoreField ?? 0), 1)
                : null;

            // Reuniões e absenteísmo
            $meetings = Meeting::whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('scheduled_at', '>=', $since)
                ->get();

            $absences = $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count();
            $absenteeism = $meetings->count() > 0
                ? round($absences / $meetings->count() * 100, 1)
                : 0;

            // Participação no crescimento de faturamento das empresas
            $admanMetrics = AdmanMetric::whereIn('company_id', $companyIds)
                ->where('reference_date', '>=', $since)
                ->get()
                ->filter(fn($m) => $m->revenue_prev_period > 0);

            $revenueGrowth = $admanMetrics->count() > 0
                ? round($admanMetrics->avg(fn($m) => $m->revenue_growth), 1)
                : null;

            // Taxa de conclusão de PPAs (% empresas com pelo menos 1 PPA concluído)
            $totalCompanies = $companyIds->count();
            $companiesWithCompletedPpa = Ppa::whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->distinct('company_id')
                ->count('company_id');

            $ppaCompletionRate = $totalCompanies > 0
                ? round($companiesWithCompletedPpa / $totalCompanies * 100, 1)
                : null;

            $avgTacos     = $admanMetrics->count() > 0 ? round($admanMetrics->avg('tacos'), 2) : null;
            $totalRevenue = $admanMetrics->sum('revenue') > 0 ? $admanMetrics->sum('revenue') : null;
            $totalSold    = $admanMetrics->sum('sold_quantity') > 0 ? (int) $admanMetrics->sum('sold_quantity') : null;

            return [
                'id'                  => $u->id,
                'name'                => $u->name,
                'role'                => $u->role,
                'companies_count'     => $companyIds->count(),
                'avg_nps'             => $avgNps,
                'total_meetings'      => $meetings->count(),
                'absenteeism_rate'    => $absenteeism,
                'nps_responses'       => $surveys->count(),
                'revenue_growth'      => $revenueGrowth,
                'ppa_completion_rate' => $ppaCompletionRate,
                'avg_tacos'           => $avgTacos,
                'total_revenue'       => $totalRevenue,
                'total_sold'          => $totalSold,
            ];
        })->sortByDesc(fn($u) => $u['avg_nps'] ?? -1)->values();

        return Inertia::render('Performance/Index', [
            'ranking' => $ranking,
            'period'  => $period,
        ]);
    }

    public function show(Request $request, User $user): \Inertia\Response
    {
        $period = $request->get('period', '30');
        $since = match ($period) {
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };

        $companyIds = $user->isMentor()
            ? $user->mentorCompanies()->pluck('companies.id')
            : $user->consultorCompanies()->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            $companyIds = $user->companies()->pluck('companies.id');
        }

        $scoreField = $user->isMentor() ? 'score_mentor' : 'score_consultant';

        $companies = Company::whereIn('id', $companyIds)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($c) use ($since, $scoreField) {
                $surveys = NpsSurvey::with('response')
                    ->where('company_id', $c->id)
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', $since)
                    ->get();

                $avgNps = $surveys->count() > 0
                    ? round($surveys->avg(fn($s) => $s->response?->$scoreField ?? 0), 1)
                    : null;

                $meetings = Meeting::where('company_id', $c->id)
                    ->where('status', 'completed')
                    ->where('scheduled_at', '>=', $since)
                    ->get();

                $absences = $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count();
                $absenteeism = $meetings->count() > 0
                    ? round($absences / $meetings->count() * 100, 1)
                    : null;

                $metric = AdmanMetric::where('company_id', $c->id)
                    ->where('reference_date', '>=', $since)
                    ->latest('reference_date')
                    ->first();

                $ppa = Ppa::where('company_id', $c->id)
                    ->latest('created_at')
                    ->first();

                return [
                    'id'               => $c->id,
                    'name'             => $c->name,
                    'avg_nps'          => $avgNps,
                    'nps_responses'    => $surveys->count(),
                    'total_meetings'   => $meetings->count(),
                    'absenteeism_rate' => $absenteeism,
                    'revenue'          => $metric?->revenue,
                    'revenue_growth'   => $metric?->revenue_prev_period > 0 ? round($metric->revenue_growth, 1) : null,
                    'tacos'            => $metric?->tacos,
                    'ppa_status'       => $ppa?->status,
                ];
            });

        // Resumo agregado
        $withNps = $companies->whereNotNull('avg_nps');
        $summary = [
            'avg_nps'          => $withNps->count() > 0 ? round($withNps->avg('avg_nps'), 1) : null,
            'total_revenue'    => $companies->sum('revenue') ?: null,
            'avg_tacos'        => $companies->whereNotNull('tacos')->avg('tacos') ? round($companies->whereNotNull('tacos')->avg('tacos'), 2) : null,
            'total_meetings'   => $companies->sum('total_meetings'),
            'companies_count'  => $companies->count(),
        ];

        return Inertia::render('Performance/Show', [
            'profile_user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
            'companies'    => $companies->values(),
            'summary'      => $summary,
            'period'       => $period,
        ]);
    }
}
