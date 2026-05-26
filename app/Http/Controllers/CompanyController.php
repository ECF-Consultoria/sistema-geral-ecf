<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\AdmanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(private AdmanService $adman) {}

    /**
     * Listagem de empresas (admin).
     *
     * Frente A do Módulo Serviços: a UI antiga exibia TACOS e Faturamento (30d)
     * com cache híbrido contra a Adman; ambas foram REMOVIDAS — a lista agora
     * mostra "Serviço" (badges dos contratos ativos). A lógica de cache foi
     * removida junto pra não deixar código órfão / não despachar jobs sem uso.
     */
    public function index()
    {
        $companies = Company::with([
                'consultor',
                'estrategista',
                // Contratos ATIVOS com servico embedado — alimenta a coluna "Serviço"
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
            ])
            ->orderBy('name')
            ->get();

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
                'estrategista'     => $c->estrategista->first()?->only(['id', 'name']),
                // Contratos ativos: payload mínimo para a coluna Serviço (badges + tooltip)
                'contratos_servico' => $c->contratosServico->map(fn($ct) => [
                    'id'               => $ct->id,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'data_contratacao' => optional($ct->data_contratacao)->toDateString(),
                    'data_vencimento'  => optional($ct->data_vencimento)?->toDateString(),
                    'servico'          => $ct->servico ? [
                        'id'            => $ct->servico->id,
                        'nome'          => $ct->servico->nome,
                        'tipo_cobranca' => $ct->servico->tipo_cobranca,
                    ] : null,
                ])->values(),
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
                ->whereIn('id', DB::table('user_setores')->where('cargo_id', $cargoEstrategistaId)->pluck('user_id'))
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
            // Contratos (ativos + inativos) com servico embedado — UI filtra na renderização
            'contratosServico' => fn($q) => $q->orderBy('ativo', 'desc')->orderBy('data_contratacao', 'desc')->with('servico'),
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

        // Catálogo de serviços ativos para popular o <Select> do modal "Adicionar contrato"
        $servicosDisponiveis = Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

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
                'estrategista'     => $company->estrategista->map->only(['id', 'name'])->values(),
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
                // Contratos de serviço (ativos + inativos) — UI filtra "Mostrar inativos"
                'contratos_servico' => $company->contratosServico->map(fn($ct) => [
                    'id'               => $ct->id,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'data_contratacao' => optional($ct->data_contratacao)->toDateString(),
                    'data_vencimento'  => optional($ct->data_vencimento)?->toDateString(),
                    'ativo'            => (bool) $ct->ativo,
                    'observacoes'      => $ct->observacoes,
                    'servico'          => $ct->servico ? [
                        'id'            => $ct->servico->id,
                        'nome'          => $ct->servico->nome,
                        'valor_padrao'  => (float) $ct->servico->valor_padrao,
                        'tipo_cobranca' => $ct->servico->tipo_cobranca,
                    ] : null,
                ])->values(),
            ],
            'servicos_disponiveis' => $servicosDisponiveis,
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

    // ─── Contratos de Serviço (Módulo Serviços — Frente A) ──────────────────

    /**
     * Cria contrato de serviço para a empresa.
     */
    public function storeContrato(Request $request, Company $company)
    {
        $data = $request->validate([
            'servico_id'       => 'required|exists:servicos,id',
            'valor_contratado' => 'required|numeric|min:0',
            'data_contratacao' => 'required|date',
            'data_vencimento'  => 'nullable|date|after_or_equal:data_contratacao',
            'observacoes'      => 'nullable|string|max:1000',
        ]);

        $company->contratosServico()->create([
            'servico_id'       => $data['servico_id'],
            'valor_contratado' => $data['valor_contratado'],
            'data_contratacao' => $data['data_contratacao'],
            'data_vencimento'  => $data['data_vencimento'] ?? null,
            'observacoes'      => $data['observacoes'] ?? null,
            'ativo'            => true,
        ]);

        return back()->with('success', 'Contrato adicionado.');
    }

    /**
     * Atualiza contrato existente (apenas se pertencer à empresa da URL).
     */
    public function updateContrato(Request $request, Company $company, ContratoServico $contrato)
    {
        abort_if($contrato->company_id !== $company->id, 404);

        $data = $request->validate([
            'valor_contratado' => 'required|numeric|min:0',
            'data_contratacao' => 'required|date',
            'data_vencimento'  => 'nullable|date|after_or_equal:data_contratacao',
            'ativo'            => 'boolean',
            'observacoes'      => 'nullable|string|max:1000',
        ]);

        $contrato->update($data);

        return back()->with('success', 'Contrato atualizado.');
    }

    /**
     * Desativa contrato (soft-deactivate via ativo=false — preserva histórico).
     */
    public function destroyContrato(Company $company, ContratoServico $contrato)
    {
        abort_if($contrato->company_id !== $company->id, 404);

        $contrato->update(['ativo' => false]);

        return back()->with('success', 'Contrato desativado.');
    }
}
