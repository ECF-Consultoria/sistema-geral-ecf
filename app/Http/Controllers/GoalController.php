<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Goal;
use App\Models\PortfolioGoal;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GoalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Phase 62 (META-04): eager load `results` (últimos 12 períodos) por goal
        // alimenta chart do <GoalProgressPanel> quando exibido inline. Filtro `active=true`
        // movido para o closure (economiza query + garante consistência com resultado do map).
        $query = Company::with([
                'goals' => fn($q) => $q->where('active', true)
                    ->with(['results' => fn($rq) => $rq->orderBy('period', 'desc')->limit(12)]),
                'consultor',
                'estrategista',
            ])
            ->where('active', true)
            ->orderBy('name');

        if (!$user->isAdmin()) {
            $companyIds = $user->companies()->pluck('companies.id');
            $query->whereIn('id', $companyIds);
        }

        $companies = $query->get()->map(fn($c) => [
            'id'        => $c->id,
            'name'      => $c->name,
            'consultor' => $c->consultor->first()?->name,
            'estrategista' => $c->estrategista->first()?->name,
            'goals'     => $c->goals->map(fn($g) => [
                'id'           => $g->id,
                'metric'       => $g->metric,
                'metric_label' => $g->metric_label,
                'target_value' => $g->target_value,
                'value_type'   => $g->value_type,
                'period_type'  => $g->period_type,
                'description'  => $g->description,
                // Inversão para ASC (por period) — chart do painel de progresso precisa ordem cronológica.
                'results'      => $g->results
                    ->sortBy('period')
                    ->values()
                    ->map(fn($r) => [
                        'id'             => $r->id,
                        'period'         => $r->period,
                        'realized_value' => (float) $r->realized_value,
                        'target_value'   => (float) $r->target_value,
                        'achieved'       => (bool) $r->achieved,
                    ]),
            ])->values(),
        ]);

        $users = [];
        if ($user->isAdmin()) {
            $users = User::where('active', true)
                ->whereIn('role', ['consultor', 'mentor'])
                ->with(['portfolioGoals' => fn($q) => $q->active()])
                ->orderBy('name')
                ->get()
                ->map(fn($u) => [
                    'id'   => $u->id,
                    'name' => $u->name,
                    'role' => $u->role,
                    'portfolio_goals' => $u->portfolioGoals->map(fn($g) => [
                        'id'           => $g->id,
                        'metric'       => $g->metric,
                        'metric_label' => $g->metric_label,
                        'target_value' => $g->target_value,
                        'value_type'   => $g->value_type,
                        'aggregation'  => $g->aggregation,
                        'period_type'  => $g->period_type,
                        'role'         => $g->role,
                        'description'  => $g->description,
                    ])->values(),
                ]);
        }

        // Para mentor/analista: retorna as próprias metas de carteira atribuídas pelo admin
        $myPortfolioGoals = [];
        if (!$user->isAdmin()) {
            $myPortfolioGoals = $user->portfolioGoals()
                ->active()
                ->get()
                ->map(fn($g) => [
                    'id'           => $g->id,
                    'metric'       => $g->metric,
                    'metric_label' => $g->metric_label,
                    'target_value' => $g->target_value,
                    'value_type'   => $g->value_type,
                    'aggregation'  => $g->aggregation,
                    'period_type'  => $g->period_type,
                    'role'         => $g->role,
                    'description'  => $g->description,
                ])->values();
        }

        return Inertia::render('Goals/Index', [
            'companies'               => $companies,
            'metrics'                 => Goal::$metricLabels,
            'percentage_only_metrics' => Goal::$percentageOnlyMetrics,
            'can_manage'              => $user->isAdmin(),
            'users'                   => $users,
            'my_portfolio_goals'      => $myPortfolioGoals,
            'portfolio_goal_metrics'  => PortfolioGoal::$metricLabels,
            'portfolio_pct_metrics'   => PortfolioGoal::$percentageOnlyMetrics,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_id'   => 'required|exists:companies,id',
            'metric'       => 'required|in:' . implode(',', array_keys(Goal::$metricLabels)),
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
        ]);

        $company = Company::findOrFail($data['company_id']);
        $user = $request->user();
        $canCreate = $user?->isAdmin()
            || $company->users()
                ->where('users.id', $user?->id)
                ->wherePivot('role', 'estrategista')
                ->exists();

        abort_unless($canCreate, 403);

        // Métricas de porcentagem não têm opção de R$
        if (in_array($data['metric'], Goal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        } else {
            $data['value_type'] = $data['value_type'] ?? 'currency';
        }

        Goal::create([...$data, 'active' => true]);

        return back()->with('success', 'Meta criada com sucesso.');
    }

    public function update(Request $request, Goal $goal)
    {
        // META-04: edicao aberta pra admin OR estrategista vinculado a empresa (pivot company_users.role='estrategista').
        // Consultor, mentor e user sem vinculo recebem 403. Mesmo padrao do store (linhas 111-118).
        $company = $goal->company;
        $user = $request->user();
        $canManage = $user?->isAdmin()
            || $company->users()
                ->where('users.id', $user?->id)
                ->wherePivot('role', 'estrategista')
                ->exists();

        abort_unless($canManage, 403);

        $data = $request->validate([
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
            'active'       => 'boolean',
        ]);

        // Bloqueio delete-via-toggle: estrategista NAO pode desativar meta via active=false (so admin).
        // Chave descartada silenciosamente pra nao quebrar callers atuais.
        if (!$user->isAdmin()) {
            unset($data['active']);
        }

        if (in_array($goal->metric, Goal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        } else {
            $data['value_type'] = $data['value_type'] ?? $goal->value_type;
        }

        $goal->update($data);

        return back()->with('success', 'Meta atualizada.');
    }

    public function destroy(Goal $goal)
    {
        $goal->update(['active' => false]);
        return back()->with('success', 'Meta removida.');
    }

    /**
     * META-04: retorna as ultimas 10 entries do activity_log dessa meta.
     *
     * Auth: admin OU qualquer user vinculado a empresa da meta (via pivot company_users)
     * — mesmo criterio de leitura de `/companies/{id}`. Isolamento por subject_id
     * garante que nao vaza historico cross-empresa.
     */
    public function history(Goal $goal, Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $canView = $user?->isAdmin()
            || $goal->company->users()->where('users.id', $user?->id)->exists();

        abort_unless($canView, 403);

        // Ordenacao: created_at DESC + id DESC como tiebreaker (entries no mesmo
        // segundo mantem ordem cronologica correta — necessario porque activity_log
        // tem granularidade de segundos e updates rapidos batem no mesmo timestamp).
        $entries = \Spatie\Activitylog\Models\Activity::with('causer')
            ->where('subject_type', Goal::class)
            ->where('subject_id', $goal->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'description' => $a->description,
                'causer_name' => optional($a->causer)->name,
                'created_at'  => optional($a->created_at)->toIso8601String(),
                'changes'     => [
                    'old'        => $a->properties['old'] ?? null,
                    'attributes' => $a->properties['attributes'] ?? null,
                ],
            ]);

        return response()->json(['entries' => $entries]);
    }
}
