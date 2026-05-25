<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
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

        // Faturamento 30d — estratégia híbrida cache+fallback:
        //  - Cache pre-aquecido pelo RefreshGrossBillingCacheJob (cron 30min)
        //    contém o grossBilling EXATO da Adman.
        //  - Se cache miss (após restart de cache), fallback é SUM(adman_metrics)
        //    e dispara o job pra preencher cache.
        // Chamada síncrona à Adman aqui estouraria memory_limit (N empresas × items[]).
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();

        $sumDb = AdmanMetric::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('reference_date', '>=', $dateFrom)
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        // Batch read: 2 Cache::many round-trips (gross + account_metrics).
        $custIds = $companies->pluck('adman_account_id')->filter(fn($id) => !empty($id))->all();
        $grossBatch   = $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo);
        $accountBatch = $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo);

        $revenue30d   = [];
        $acos30d      = [];
        $tacos30d     = [];
        $margin30d    = [];
        $missingCache = false;
        foreach ($companies as $c) {
            if (!$c->adman_account_id) {
                $revenue30d[$c->id] = 0.0;
                continue;
            }
            // Faturamento bruto (grossBilling)
            $entry = $grossBatch[$c->adman_account_id] ?? ['value' => null, 'hasEntry' => false];
            if ($entry['value'] !== null) {
                $revenue30d[$c->id] = $entry['value'];
            } else {
                $revenue30d[$c->id] = (float) ($sumDb[$c->id] ?? 0);
                if (!$entry['hasEntry']) $missingCache = true;
            }

            // ACOS, TACOS, margem (do /accounts/metrics)
            $accEntry = $accountBatch[$c->adman_account_id] ?? ['value' => null, 'hasEntry' => false];
            if ($accEntry['value'] !== null) {
                $acos30d[$c->id]   = $accEntry['value']['acos']              ?? null;
                $tacos30d[$c->id]  = $accEntry['value']['tacos']             ?? null;
                $margin30d[$c->id] = $accEntry['value']['percentage_margin'] ?? null;
            } elseif (!$accEntry['hasEntry']) {
                $missingCache = true;
            }
        }

        if ($missingCache) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatch();
        }

        $companies = $companies->map(fn($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'cnpj'             => $c->cnpj,
                'segment'          => $c->segment,
                'active'           => $c->active,
                'status'           => $c->status,
                'notes'            => $c->notes,
                'adman_account_id' => $c->ml_store_id ?: $c->adman_account_id,
                'adman_store_id'   => $c->adman_store_id,
                'ml_store_id'      => $c->ml_store_id,
                'consultor'        => $c->consultor->first()?->only(['id', 'name']),
                'estrategista'           => $c->estrategista->first()?->only(['id', 'name']),
                // ACOS, TACOS, margem vêm do cache /accounts/metrics da Adman
                // (range 30d); fallback latestMetrics (1 dia) só se cache cold.
                'tacos'            => $tacos30d[$c->id]  ?? $c->latestMetrics?->tacos,
                'acos'             => $acos30d[$c->id]   ?? null,
                'margin_pct'       => $margin30d[$c->id] ?? $c->latestMetrics?->contribution_margin_pct,
                'revenue_30d'      => (float) ($revenue30d[$c->id] ?? 0),
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

        // Companies cadastradas pelo Comercial que aguardam complemento de dados por Publicidade/Gestão
        $empresasPendentes = Company::where(function ($q) {
                $q->whereJsonContains('service_type', 'publicidade')
                  ->orWhereJsonContains('service_type', 'gestao');
            })
            ->where('status', 'pendente')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'service_type', 'created_at']);

        return Inertia::render('Companies/Index', [
            'companies'          => $companies,
            'users'              => $users,
            'estrategistas'      => $estrategistas,
            'empresas_pendentes' => $empresasPendentes,
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

        // Faturamento bruto + ACOS/TACOS/margem dos últimos 30 dias —
        // chamadas diretas à Adman (1 empresa, ~2 chamadas, sem risco de
        // memória). Cache 60min embutido nos métodos.
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();

        $revenue30d = 0.0;
        $acos30d    = null;
        $tacos30d   = null;
        $margin30d  = null;
        $liquidMargin30d = null;
        $adInvestment30d = null;

        if ($company->adman_account_id) {
            $revenue30d = (float) ($this->adman->fetchGrossBilling(
                $company->adman_account_id, $dateFrom, $dateTo
            ) ?? 0);

            $accountMetrics = $this->adman->fetchAccountMetricsCached(
                $company->adman_account_id, $dateFrom, $dateTo
            );
            if ($accountMetrics !== null) {
                $acos30d         = $accountMetrics['acos'];
                $tacos30d        = $accountMetrics['tacos'];
                $margin30d       = $accountMetrics['percentage_margin'];
                $liquidMargin30d = $accountMetrics['liquid_margin'];
                $adInvestment30d = $accountMetrics['investment'];
            }
        }

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
                'acos_30d'         => $acos30d,
                'tacos_30d'        => $tacos30d,
                'margin_pct_30d'   => $margin30d,
                'liquid_margin_30d'=> $liquidMargin30d,
                'ad_investment_30d'=> $adInvestment30d,
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
            'adman_store_id'   => 'nullable|string|max:100',
            'ml_store_id'      => 'nullable|string|max:100',
            'segment'          => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
            'active'           => 'boolean',
            'consultor_id'     => 'nullable|exists:users,id',
            'estrategista_id'        => 'nullable|exists:users,id',
        ]);

        $company->update($data);

        // Empresa cadastrada pelo Comercial: ao ser editada pela primeira vez, sai do estado pendente
        if ($company->status === 'pendente') {
            $company->update(['status' => 'ativo']);
        }

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

