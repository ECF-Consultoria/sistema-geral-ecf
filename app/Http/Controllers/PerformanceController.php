<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\Publicacao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PerformanceController extends Controller
{
    public function index(Request $request)
    {
        $setor = $request->get('setor', 'consultoria');

        if ($setor === 'polos') {
            return $this->indexPolos($request);
        }

        $period = $request->get('period', '30');
        $since = match ($period) {
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };

        $users = User::where('active', true)
            ->whereIn('role', ['consultor', 'mentor'])
            ->whereNull('publication_role_legacy')
            ->get();

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
            'setor'   => 'consultoria',
        ]);
    }

    private function indexPolos(Request $request): \Inertia\Response
    {
        $mesRef = $request->get('mes', now()->format('Y-m'));
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        $users = User::where('active', true)
            ->whereIn('publication_role_legacy', ['publicador', 'lider'])
            ->orderBy('name')
            ->get(['id', 'name', 'publication_role_legacy', 'publication_meta']);

        $hoje      = Carbon::today();
        $primeiroC = $ref->copy()->startOfMonth();
        $ultimoC   = $ref->copy()->endOfMonth();

        $diasDecorridos = $this->diasUteis($primeiroC, $hoje->lt($ultimoC) ? $hoje : $ultimoC);
        $diasRestantes  = $hoje->lt($ultimoC) ? $this->diasUteis($hoje->copy()->addDay(), $ultimoC) : 0;
        $diasTotal      = max($diasDecorridos + $diasRestantes, 1);

        $rawRanking = $users->map(function ($u) use ($primeiro, $ultimo, $diasDecorridos, $diasTotal, $mesRef) {
            $meta       = $this->metaParaMes($u->id, $mesRef);
            $feito      = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->count();
            $vendas     = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('vendido', true)->count();

            $percentual_meta = $meta > 0 ? $feito / $meta : 0.0;
            $conversao_raw   = $feito > 0 ? $vendas / $feito : 0.0;

            $mediaAtual = $diasDecorridos > 0 ? round($feito / $diasDecorridos, 1) : 0.0;
            $projecao   = (int) round($mediaAtual * $diasTotal);

            if ($feito >= $meta) {
                $status = 'Acima da meta';
            } elseif ($projecao >= $meta * 0.95) {
                $status = 'No alvo';
            } else {
                $status = 'Abaixo da meta';
            }

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'pub_role'        => $u->publication_role_legacy,
                'meta'            => $meta,
                'feito'           => $feito,
                'vendas'          => $vendas,
                'percentual'      => $meta > 0 ? round($percentual_meta * 100, 1) : 0.0,
                'conversao'       => round($conversao_raw * 100, 1),
                'projecao'        => $projecao,
                'status'          => $status,
                // campos intermediários para normalização
                '_pct_meta'       => $percentual_meta,
                '_conversao_raw'  => $conversao_raw,
            ];
        });

        // Normalização por grupo e cálculo do score final
        $maxVendas    = max((int) $rawRanking->max('vendas'),   1);
        $maxConversao = max($rawRanking->max('_conversao_raw'), 0.0001);

        $ranking = $rawRanking->map(function ($u) use ($maxVendas, $maxConversao) {
            $pctMeta      = min($u['_pct_meta'],      1.0); // limita em 1 para não distorcer
            $vendasNorm   = $u['vendas']           / $maxVendas;
            $conversaoNorm = $u['_conversao_raw']  / $maxConversao;

            $score = round(($pctMeta * 0.4 + $vendasNorm * 0.4 + $conversaoNorm * 0.2) * 100, 1);

            return [
                'id'              => $u['id'],
                'name'            => $u['name'],
                'pub_role'        => $u['pub_role'],
                'meta'            => $u['meta'],
                'feito'           => $u['feito'],
                'vendas'          => $u['vendas'],
                'percentual'      => $u['percentual'],
                'conversao'       => $u['conversao'],
                'projecao'        => $u['projecao'],
                'status'          => $u['status'],
                'score_final'     => $score,
            ];
        })->sortByDesc('score_final')->values();

        $meses = Publicacao::selectRaw("DATE_FORMAT(data, '%Y-%m') as mes")
            ->distinct()->orderByDesc('mes')->pluck('mes')->toArray();
        $atual = now()->format('Y-m');
        if (!in_array($atual, $meses)) array_unshift($meses, $atual);

        return Inertia::render('Performance/Index', [
            'ranking' => $ranking,
            'setor'   => 'polos',
            'mes'     => $mesRef,
            'meses'   => $meses,
        ]);
    }

    private function metaParaMes(int $userId, string $mes): int
    {
        $registro = DB::table('mlb_meta_historico')
            ->where('user_id', $userId)
            ->where('mes_inicio', '<=', $mes)
            ->orderByDesc('mes_inicio')
            ->value('meta');

        if ($registro !== null) return (int) $registro;

        return (int) (User::find($userId)?->publication_meta ?? 220);
    }

    private function diasUteis(Carbon $start, Carbon $end): int
    {
        if ($start->gt($end)) return 0;
        $count   = 0;
        $current = $start->copy()->startOfDay();
        $endDay  = $end->copy()->startOfDay();
        while ($current->lte($endDay)) {
            if ($current->isWeekday()) $count++;
            $current->addDay();
        }
        return $count;
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
