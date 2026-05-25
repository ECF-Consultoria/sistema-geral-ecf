<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\AdmanService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(private AdmanService $adman) {}

    public function index()
    {
        $companies = Company::with(['consultor', 'estrategista', 'latestMetrics'])
            ->orderBy('name')
            ->get();

        // Faturamento bruto dos últimos 30 dias — chamada DIRETA à Adman
        // (summarizedData.grossBilling.value), batch paralelo + cache 10min.
        // Substituiu SUM(adman_metrics.revenue) que divergia da dashboard
        // Adman porque (a) sync diário pode pular dias e (b) Adman aplica
        // ajustes retroativos que não voltam pro DB.
        $custIds = $companies->pluck('adman_account_id')
            ->filter(fn($id) => !empty($id))
            ->all();

        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();
        $revenue30d = $this->adman->fetchGrossBillingsBatch($custIds, $dateFrom, $dateTo);

        $companies = $companies->map(fn($c) => [
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
                'estrategista'           => $c->estrategista->first()?->only(['id', 'name']),
                'tacos'            => $c->latestMetrics?->tacos,
                // 30d substituiu o latest pra bater com a Adman. tacos/margem
                // permanecem no latest porque são razões (não somam) e o "agora"
                // tem mais sentido nessas métricas.
                'revenue_30d'      => (float) ($revenue30d[$c->adman_account_id] ?? 0),
                'margin_pct'       => $c->latestMetrics?->contribution_margin_pct,
            ]);

        $users = User::where('active', true)
            ->where('role', '!=', 'admin')
            ->get(['id', 'name', 'role']);

        // Users com o cargo "Estrategista" (slug=estrategista) atribuído no
        // pivot user_setores. O nome de "mentor" mudou pra "Estrategista" na
        // empresa — o select do popup usa essa lista em vez do legacy
        // User.role='mentor'.
        // Implementação via whereIn + subquery direta no pivot — wherePivot()
        // dentro de whereHas('setores', ...) NÃO funciona (Eloquent gera SQL
        // inválido tipo `pivot = cargo_id`); precisa do DB::table().
        $cargoEstrategistaId = \App\Models\Cargo::where('slug', 'estrategista')->value('id');
        $estrategistas = $cargoEstrategistaId
            ? User::where('active', true)
                ->whereIn('id', \DB::table('user_setores')->where('cargo_id', $cargoEstrategistaId)->pluck('user_id'))
                ->get(['id', 'name'])
                ->values()
            : collect();

        return Inertia::render('Companies/Index', [
            'companies'     => $companies,
            'users'         => $users,
            'estrategistas' => $estrategistas,
        ]);
    }

    public function show(Company $company)
    {
        $company->load([
            'consultor', 'estrategista',
            'goals' => fn($q) => $q->where('active', true),
            'ppas.mentor',
            'meetings' => fn($q) => $q->orderBy('scheduled_at', 'desc')->limit(10),
            'npsSurveys' => fn($q) => $q->where('status', 'completed')->with('response')->orderBy('completed_at', 'desc')->limit(10),
            'admanMetrics' => fn($q) => $q->orderBy('reference_date', 'desc')->limit(30),
        ]);

        // Faturamento bruto dos últimos 30 dias — chamada direta à Adman
        // (summarizedData.grossBilling.value), cache 10min. Não soma do DB
        // porque sync diário pode pular dias e Adman aplica ajustes retroativos.
        $revenue30d = $company->adman_account_id
            ? (float) ($this->adman->fetchGrossBilling(
                $company->adman_account_id,
                now()->subDays(30)->toDateString(),
                now()->toDateString(),
            ) ?? 0)
            : 0.0;

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
                'revenue_30d'      => $revenue30d,
                'consultor'        => $company->consultor->map->only(['id', 'name'])->values(),
                'estrategista'           => $company->estrategista->map->only(['id', 'name'])->values(),
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
                    // Mentor do PPA é um conceito separado (Ppa.mentor_id) — não
                    // renomeado pra estrategista. Aqui é o user responsável pelo plano,
                    // não necessariamente o Estrategista da empresa.
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
            'estrategista_id'        => 'nullable|exists:users,id',
        ]);

        $company = Company::create($data);

        if (!empty($data['consultor_id'])) {
            $company->users()->attach($data['consultor_id'], ['role' => 'consultor', 'assigned_at' => now()->toDateString()]);
        }
        if (!empty($data['estrategista_id'])) {
            $company->users()->attach($data['estrategista_id'], ['role' => 'estrategista', 'assigned_at' => now()->toDateString()]);
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
            'estrategista_id'        => 'nullable|exists:users,id',
        ]);

        $company->update($data);

        $sync = [];
        if (!empty($data['consultor_id'])) {
            $sync[$data['consultor_id']] = ['role' => 'consultor', 'assigned_at' => now()->toDateString()];
        }
        if (!empty($data['estrategista_id']) && $data['estrategista_id'] !== $data['consultor_id']) {
            $sync[$data['estrategista_id']] = ['role' => 'estrategista', 'assigned_at' => now()->toDateString()];
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

