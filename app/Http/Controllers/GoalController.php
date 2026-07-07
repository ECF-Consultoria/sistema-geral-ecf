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

        $query = Company::with(['goals', 'consultor', 'estrategista'])
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
            'goals'     => $c->goals->where('active', true)->map(fn($g) => [
                'id'           => $g->id,
                'metric'       => $g->metric,
                'metric_label' => $g->metric_label,
                'target_value' => $g->target_value,
                'value_type'   => $g->value_type,
                'period_type'  => $g->period_type,
                'description'  => $g->description,
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
        $data = $request->validate([
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
            'active'       => 'boolean',
        ]);

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
}
