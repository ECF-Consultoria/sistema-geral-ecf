<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function index()
    {
        $companies = Company::with(['consultor', 'mentor', 'latestMetrics'])
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'cnpj'             => $c->cnpj,
                'segment'          => $c->segment,
                'active'           => $c->active,
                'notes'            => $c->notes,
                'adman_account_id' => $c->adman_account_id,
                'adman_store_id'   => $c->adman_store_id,
                'ml_store_id'      => $c->ml_store_id,
                'consultor'        => $c->consultor->first()?->only(['id', 'name']),
                'mentor'           => $c->mentor->first()?->only(['id', 'name']),
                'tacos'            => $c->latestMetrics?->tacos,
                'revenue'          => $c->latestMetrics?->revenue,
                'margin_pct'       => $c->latestMetrics?->contribution_margin_pct,
            ]);

        $users = User::where('active', true)
            ->where('role', '!=', 'admin')
            ->get(['id', 'name', 'role']);

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'users'     => $users,
        ]);
    }

    public function show(Company $company)
    {
        $company->load([
            'consultor', 'mentor',
            'goals' => fn($q) => $q->where('active', true),
            'ppas.mentor',
            'meetings' => fn($q) => $q->orderBy('scheduled_at', 'desc')->limit(10),
            'npsSurveys' => fn($q) => $q->where('status', 'completed')->with('response')->orderBy('completed_at', 'desc')->limit(10),
            'admanMetrics' => fn($q) => $q->orderBy('reference_date', 'desc')->limit(30),
        ]);

        return Inertia::render('Companies/Show', [
            'company' => [
                'id'               => $company->id,
                'name'             => $company->name,
                'cnpj'             => $company->cnpj,
                'segment'          => $company->segment,
                'active'           => $company->active,
                'notes'            => $company->notes,
                'adman_account_id' => $company->adman_account_id,
                'adman_store_id'   => $company->adman_store_id,
                'ml_store_id'      => $company->ml_store_id,
                'consultor'        => $company->consultor->map->only(['id', 'name'])->values(),
                'mentor'           => $company->mentor->map->only(['id', 'name'])->values(),
                'goals'            => $company->goals->map(fn($g) => [
                    'id' => $g->id, 'metric' => $g->metric, 'metric_label' => $g->metric_label,
                    'target_value' => $g->target_value, 'active' => $g->active,
                ])->values(),
                'meetings'         => $company->meetings->map(fn($m) => [
                    'id' => $m->id, 'scheduled_at' => $m->scheduled_at,
                    'status' => $m->status, 'meeting_link' => $m->meeting_link,
                    'consultant_present' => $m->consultant_present,
                    'mentor_present' => $m->mentor_present,
                ])->values(),
                'nps_surveys'      => $company->npsSurveys->map(fn($s) => [
                    'id' => $s->id, 'status' => $s->status,
                    'response' => $s->response ? [
                        'respondent_name' => $s->response->respondent_name,
                        'score_overall'   => $s->response->score_overall,
                        'score_consultant' => $s->response->score_consultant,
                        'score_mentor'    => $s->response->score_mentor,
                        'comment'         => $s->response->comment,
                    ] : null,
                ])->values(),
                'ppas'             => $company->ppas->map(fn($p) => [
                    'id' => $p->id, 'title' => $p->title,
                    'completion_pct' => $p->completion_pct,
                    'actions_count'  => count($p->actions ?? []),
                    'mentor' => $p->mentor ? ['name' => $p->mentor->name] : null,
                ])->values(),
                'adman_metrics'    => $company->admanMetrics->map(fn($m) => [
                    'id' => $m->id, 'reference_date' => $m->reference_date,
                    'revenue' => $m->revenue, 'investment' => $m->investment,
                    'tacos' => $m->tacos, 'contribution_margin_pct' => $m->contribution_margin_pct,
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'cnpj'             => 'nullable|string|max:18|unique:companies',
            'adman_account_id' => 'nullable|string|max:100',
            'adman_store_id'   => 'nullable|string|max:100',
            'ml_store_id'      => 'nullable|string|max:100',
            'segment'          => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
            'consultor_id'     => 'nullable|exists:users,id',
            'mentor_id'        => 'nullable|exists:users,id',
        ]);

        $company = Company::create($data);

        if (!empty($data['consultor_id'])) {
            $company->users()->attach($data['consultor_id'], ['role' => 'consultor', 'assigned_at' => now()->toDateString()]);
        }
        if (!empty($data['mentor_id'])) {
            $company->users()->attach($data['mentor_id'], ['role' => 'mentor', 'assigned_at' => now()->toDateString()]);
        }

        return back()->with('success', "Empresa {$company->name} criada com sucesso.");
    }

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'cnpj'             => 'nullable|string|max:18',
            'adman_account_id' => 'nullable|string|max:100',
            'adman_store_id'   => 'nullable|string|max:100',
            'ml_store_id'      => 'nullable|string|max:100',
            'segment'          => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
            'active'           => 'boolean',
            'consultor_id'     => 'nullable|exists:users,id',
            'mentor_id'        => 'nullable|exists:users,id',
        ]);

        $company->update($data);

        $sync = [];
        if (!empty($data['consultor_id'])) {
            $sync[$data['consultor_id']] = ['role' => 'consultor', 'assigned_at' => now()->toDateString()];
        }
        if (!empty($data['mentor_id']) && $data['mentor_id'] !== $data['consultor_id']) {
            $sync[$data['mentor_id']] = ['role' => 'mentor', 'assigned_at' => now()->toDateString()];
        }

        if (!empty($sync)) {
            $company->users()->detach();
            $company->users()->attach($sync);
        }

        return back()->with('success', 'Empresa atualizada com sucesso.');
    }

    public function destroy(Company $company)
    {
        $name = $company->name;
        $company->delete();
        return back()->with('success', "Empresa {$name} excluída.");
    }
}

