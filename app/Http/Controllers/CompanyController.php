<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\EcfDriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private EcfDriveService $ecf,
    ) {}

    /**
     * Listagem de empresas (admin).
     *
     * Frente A do Módulo Serviços: a UI antiga exibia TACOS e Faturamento (30d)
     * com cache híbrido contra a Adman; ambas foram REMOVIDAS — a lista agora
     * mostra "Serviço" (badges dos contratos ativos). A lógica de cache foi
     * removida junto pra não deixar código órfão / não despachar jobs sem uso.
     */
    public function index(Request $request)
    {
        // Phase 18 W5-T4 — Filtro opcional por cust_id_status. Aceita apenas
        // valores do dominio da coluna ENUM; fora disso, ignora silenciosamente
        // (no-op em when() — preserva comportamento anterior).
        $custIdStatusFilter = $request->input('cust_id_status');
        if (!in_array($custIdStatusFilter, ['ok', 'invalido', 'desconhecido', 'nao_aplicavel'], true)) {
            $custIdStatusFilter = null;
        }

        $companies = Company::with([
                'consultor',
                'estrategista',
                // Contratos ATIVOS com servico embedado — alimenta a coluna "Serviço"
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'mlToken',
                'grupo:id,name,color',
            ])
            // Grant ativo local (company_grants sincronizado da API ECF Drive) para a pendência "sem grant".
            ->withCount(['grants as grants_ativos_count' => fn($q) => $q->where('status', 'active')])
            ->when($custIdStatusFilter, fn($q) => $q->where('cust_id_status', $custIdStatusFilter))
            ->orderBy('name')
            ->get();

        $companies = $companies->map(fn($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'cnpj'             => $c->cnpj,
                'segment'          => $c->segment,
                'active'           => $c->active,
                'status'           => $c->status,
                // Phase 18 W5-T3 — flag persistida por dashboard:mark-custid-status.
                // Frontend usa para badge "Cust ID Invalido" quando === 'invalido'.
                'cust_id_status'   => $c->cust_id_status,
                'notes'            => $c->notes,
                // Phase 31 D-04 + Quick 260611-eml — contato do cliente (preenche o openEdit do modal admin)
                'email_cliente'    => $c->email_cliente,
                'telefone'         => $c->telefone,
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
                'ml_token_status'  => $c->mlToken?->status ?? null,
                // Grupo nomeado (tipo carteira) — null se a empresa não está em nenhum
                'grupo'            => $c->grupo ? [
                    'id'    => $c->grupo->id,
                    'name'  => $c->grupo->name,
                    'color' => $c->grupo->color,
                ] : null,
                'company_group_id' => $c->company_group_id,
                // Pendências calculadas (4 tipos) — alimenta a aba "Pendências".
                // Só fazem sentido para empresas ativas; o frontend filtra por active.
                'pendencias'       => array_values(array_filter([
                    ($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null,
                    (! $c->adman_account_id && ! $c->ml_store_id)             ? 'sem_cust_id' : null,
                    (! $c->email_cliente)                                     ? 'sem_email_colaborador' : null,
                    ($c->grants_ativos_count < 1)                            ? 'sem_grant_ativo' : null,
                    // contratosServico já vem filtrado por ativo=true no eager load
                    ($c->contratosServico->isEmpty())                        ? 'sem_servico' : null,
                ])),
            ]);

        $users = User::where('active', true)
            ->where('role', '!=', 'admin')
            ->get(['id', 'name', 'role']);

        // Users por CARGO no pivot user_setores. Helper local: pluck dos ids do
        // cargo por slug (há slugs duplicados em prod, ex: 2x "analista" — por isso
        // pluck/whereIn em vez de value('id'), que pegaria só um e perderia users).
        $usersPorCargo = function (string $slug) {
            $cargoIds = \App\Models\Cargo::where('slug', $slug)->pluck('id');
            if ($cargoIds->isEmpty()) {
                return collect();
            }
            return User::where('active', true)
                ->whereIn('id', DB::table('user_setores')->whereIn('cargo_id', $cargoIds)->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values();
        };

        $estrategistas = $usersPorCargo('estrategista');
        $analistas     = $usersPorCargo('analista');

        // Grupos nomeados (tipo carteira) com contagem de empresas — aba "Grupos".
        $grupos = \App\Models\CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        // Contagem de empresas ATIVAS por tipo de serviço (contrato ativo) — chips de filtro.
        // Query direta para contar empresas distintas por serviço sem depender de relação.
        $servicoCounts = DB::table('contratos_servico as cs')
            ->join('servicos as s', 's.id', '=', 'cs.servico_id')
            ->join('companies as c', 'c.id', '=', 'cs.company_id')
            ->where('cs.ativo', true)
            ->where('c.active', true)
            ->groupBy('s.id', 's.nome')
            ->orderBy('s.nome')
            ->selectRaw('s.id, s.nome, COUNT(DISTINCT c.id) as total')
            ->get();

        return Inertia::render('Companies/Index', [
            'companies'      => $companies,
            'users'          => $users,
            'estrategistas'  => $estrategistas,
            'analistas'      => $analistas,
            'grupos'         => $grupos,
            'servico_counts' => $servicoCounts,
            // Phase 18 W5-T4 — Filtro snake_case; null se nao aplicado.
            'filters'        => [
                'cust_id_status' => $custIdStatusFilter,
            ],
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
            'admanMetrics' => fn($q) => $q->orderBy('reference_date', 'desc')->limit(90),
            // Contratos (ativos + inativos) com servico embedado — UI filtra na renderização
            'contratosServico' => fn($q) => $q->orderBy('ativo', 'desc')->orderBy('data_contratacao', 'desc')->with('servico'),
            'mlToken',
        ]);

        // Faturamento bruto + ACOS/TACOS/margem dos últimos 30 dias —
        // chamadas diretas à Adman (1 empresa, ~2 chamadas, sem risco de
        // memória). Cache 60min embutido nos métodos.
        //
        // Usa o accessor cust_id (adman_account_id ?: ml_store_id) — empresas
        // cadastradas via Comercial só com ml_store_id também passam pelo fallback.
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();

        $revenue30d = 0.0;
        $acos30d    = null;
        $tacos30d   = null;
        $margin30d  = null;
        $liquidMargin30d = null;
        $adInvestment30d = null;

        $custId = $company->cust_id;
        if ($company->is_ml_driven) {
            // Cutover (Opção A): empresa com token ML ativo é servida pelo
            // caminho ML — agrega as métricas já gravadas pelo sync direto do
            // Mercado Livre (adman_metrics), mesmo que ainda tenha adman_account_id.
            // O sistema NÃO chama a Adman para ela (o cust_id seria o Seller ID
            // ML, que a Adman não reconhece).
            //
            // ACOS/TACOS são RECOMPUTADOS sobre as somas do período (não média
            // dos valores diários) — assim batem com a definição da Adman.
            // ad_revenue não tem coluna própria; vem do raw_data.ads do sync ML.
            $cutoff = now()->subDays(30)->startOfDay();
            $slice  = $company->admanMetrics->filter(
                fn($m) => $m->reference_date && $m->reference_date->gte($cutoff)
            );

            $sumRevenue   = (float) $slice->sum(fn($m) => (float) $m->revenue);
            $sumAdSpend   = (float) $slice->sum(fn($m) => (float) $m->ad_spend);
            $sumAdRevenue = (float) $slice->sum(fn($m) => (float) ($m->raw_data['ads']['ad_revenue'] ?? 0));

            $revenue30d      = $sumRevenue;
            $adInvestment30d = $sumAdSpend > 0 ? $sumAdSpend : null;
            $tacos30d        = $sumRevenue   > 0 ? round(($sumAdSpend / $sumRevenue)   * 100, 2) : null;
            $acos30d         = $sumAdRevenue > 0 ? round(($sumAdSpend / $sumAdRevenue) * 100, 2) : null;
            // Margem % exige CMV + impostos — indisponível na API ML. Mantém null;
            // a UI exibe "—" com aviso para empresas ML-driven.
            $margin30d       = null;
        } elseif ($custId && $company->adman_account_id) {
            // Empresa Adman (sem token ML ativo): busca via API Adman
            $revenue30d = (float) ($this->adman->fetchGrossBilling(
                $custId, $dateFrom, $dateTo
            ) ?? 0);

            $accountMetrics = $this->adman->fetchAccountMetricsCached(
                $custId, $dateFrom, $dateTo
            );
            if ($accountMetrics !== null) {
                $acos30d         = $accountMetrics['acos'];
                $tacos30d        = $accountMetrics['tacos'];
                $margin30d       = $accountMetrics['percentage_margin'];
                $liquidMargin30d = $accountMetrics['liquid_margin'];
                $adInvestment30d = $accountMetrics['investment'];
            }
        }

        // ─── Phase 25 Plano 04 (2026-06-05) — Integração ECF Drive ────────────
        // Bloco PURAMENTE ADITIVO no Show.jsx (widget novo "Análise ECF Drive")
        // com dados que a Adman não cobre: vendas, visitas, scores, medalha,
        // histórico 12 meses, alertas estratégicos.
        //
        // Plano 05 do mesmo dia (revisão): a substituição de revenue_30d e
        // ad_investment_30d foi REVERTIDA porque `metricaMensalAtual.tgmvLc` é
        // o GMV do MÊS CORRENTE PARCIAL (hoje dia 5 → só 5 dias de dados),
        // enquanto `revenue_30d` da Adman é janela móvel de 30 dias. Semânticas
        // diferentes; substituir confundia o usuário com valores 100× menores
        // dando impressão de "zerado". Adman intacta nos KPIs financeiros.
        //
        // Try/catch silencioso — falha de ECF NUNCA quebra a página.
        $ecfDrive = null;
        if ($custId) {
            try {
                $sellerData = $this->ecf->seller((string) $custId);

                // Endpoints sellerMetricasMensal/sellerMedalhas/sellerSignals
                // retornam SHAPE PAGINADO `{data, total, page, limit}` — extrair
                // .data antes do slice. Mesmo padrão do EmpresaAnaliseEcfController
                // (linha 102). Bug do Plano 04: slice ia no top-level.
                $metricas = [];
                $medalhas = [];
                $signals  = [];
                try {
                    $r = $this->ecf->sellerMetricasMensal((string) $custId);
                    $metricas = array_slice($r['data'] ?? [], -12);
                } catch (\Throwable $e) { Log::warning("[Companies/Show] ECF metricasMensal falhou cust={$custId}: " . $e->getMessage()); }
                try {
                    $r = $this->ecf->sellerMedalhas((string) $custId);
                    $medalhas = array_slice($r['data'] ?? [], -12);
                } catch (\Throwable $e) { Log::warning("[Companies/Show] ECF medalhas falhou cust={$custId}: " . $e->getMessage()); }
                try {
                    $r = $this->ecf->sellerSignals((string) $custId);
                    $signals = array_slice($r['data'] ?? [], 0, 20);
                } catch (\Throwable $e) { Log::warning("[Companies/Show] ECF signals falhou cust={$custId}: " . $e->getMessage()); }

                $ecfDrive = [
                    'seller'   => $sellerData,
                    'metricas' => $metricas,
                    'medalhas' => $medalhas,
                    'signals'  => $signals,
                ];
            } catch (\Throwable $e) {
                // Não encontrada (404) ou erro genérico: silencia, KPIs Adman
                // continuam funcionando normalmente.
                Log::warning("[Companies/Show] ECF Drive indisponível cust={$custId}: " . $e->getMessage());
                $ecfDrive = null;
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
                // Phase 31 D-04 + Quick 260611-eml — contato do cliente
                'email_cliente'    => $company->email_cliente,
                'telefone'         => $company->telefone,
                'adman_account_id' => $company->adman_account_id,
                'adman_store_id'   => $company->adman_store_id,
                'ml_store_id'      => $company->ml_store_id,
                'ml_token'         => $company->mlToken ? [
                    'status'            => $company->mlToken->status,
                    'ml_user_id'        => $company->mlToken->ml_user_id,
                    'expires_at'        => $company->mlToken->expires_at?->toISOString(),
                    'connected_at'      => $company->mlToken->connected_at?->toISOString(),
                    'last_refreshed_at' => $company->mlToken->last_refreshed_at?->toISOString(),
                ] : null,
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
                    // Phase 31 Plan 31-04 (Gotcha Plan 31-02) — colunas legacy
                    // score_overall/score_consultant/score_mentor foram dropadas
                    // no Plan 31-01 e recriadas como score_empresa/score_analista/
                    // score_estrategista (escala 1-5). O JSX Companies/Show.jsx
                    // (linhas 377, 848) ainda referencia os nomes antigos e sera
                    // ajustado no Plan 31-05.
                    'response' => $s->response ? [
                        'respondent_name'    => $s->response->respondent_name,
                        'score_empresa'      => $s->response->score_empresa,
                        'score_analista'     => $s->response->score_analista,
                        'score_estrategista' => $s->response->score_estrategista,
                        'comment'            => $s->response->comment,
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
                // Métricas ML diárias (últimos 90 dias) — usadas para KPIs com filtro de período
                'ml_metrics' => $company->is_ml_driven
                    ? $company->admanMetrics->map(fn($m) => [
                        'date'     => $m->reference_date instanceof \Carbon\Carbon
                            ? $m->reference_date->toDateString()
                            : (string) $m->reference_date,
                        'revenue'  => (float) $m->revenue,
                        'ad_spend' => (float) $m->ad_spend,
                    ])->values()
                    : [],
            ],
            'servicos_disponiveis' => $servicosDisponiveis,
            // Phase 25 Plano 04: bloco ECF Drive (seller + 12m métricas + medalhas + signals)
            // ou null quando empresa não tem cust_id ou API ECF Drive indisponível.
            'ecf_drive'            => $ecfDrive,
        ]);
    }

    // Cadastro de empresa removido de /companies — entrada exclusiva por /comercial/empresas
    // (ComercialController::store). Em /companies só editamos empresas existentes.

    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'cnpj'             => 'nullable|string|max:18',
            'adman_store_id'   => 'nullable|string|max:100',
            'ml_store_id'      => 'nullable|string|max:100',
            'segment'          => 'nullable|string|max:100',
            'notes'            => 'nullable|string',
            // Phase 31 D-04 — destinatario do email NPS mensal
            'email_cliente'    => 'nullable|email|max:255',
            // Quick 260611-eml — contato comercial.
            'telefone'         => 'nullable|string|max:20',
            'active'           => 'boolean',
            'consultor_id'     => 'nullable|exists:users,id',
            'estrategista_id'        => 'nullable|exists:users,id',
            // Grupo nomeado (tipo carteira) — null limpa o vínculo
            'company_group_id' => 'nullable|integer|exists:company_groups,id',
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

    /**
     * Atribui (ou remove, com null) a empresa a um grupo nomeado.
     *
     * Endpoint dedicado para os cards da aba "Grupos" — evita os efeitos
     * colaterais do update() completo (sync de responsáveis, pendente→ativo).
     */
    public function setGroup(Request $request, Company $company)
    {
        $data = $request->validate([
            'company_group_id' => 'nullable|integer|exists:company_groups,id',
        ]);

        $company->update(['company_group_id' => $data['company_group_id'] ?? null]);

        return back()->with('success', 'Grupo da empresa atualizado.');
    }

    /**
     * Exclusão em massa (hard-delete) de empresas selecionadas na aba Pendências.
     *
     * Todas as FKs filhas de companies são cascadeOnDelete (contratos, grants,
     * métricas, sugadores, pivô company_users, etc.) e parent_company_id/
     * mlb_empresas são nullOnDelete — então delete() limpa tudo com segurança.
     */
    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:companies,id',
        ]);

        $companies = Company::whereIn('id', $data['ids'])->get();
        foreach ($companies as $c) {
            $c->delete();
        }

        return back()->with('success', $companies->count() . ' empresa(s) excluída(s).');
    }

    /**
     * Atribuição em massa de Analista (role=consultor) ou Estrategista a várias
     * empresas. Substitui apenas o papel informado, preservando o outro.
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'integer|exists:companies,id',
            'role'    => 'required|in:consultor,estrategista',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        foreach (Company::whereIn('id', $data['ids'])->get() as $c) {
            // Remove só o papel alvo (mantém o outro) e atribui o novo responsável.
            DB::table('company_users')->where('company_id', $c->id)->where('role', $data['role'])->delete();
            $c->users()->attach($data['user_id'], ['role' => $data['role'], 'assigned_at' => now()->toDateString()]);
        }

        $label = $data['role'] === 'consultor' ? 'Analista' : 'Estrategista';
        return back()->with('success', count($data['ids']) . " empresa(s) — {$label} atribuído.");
    }

    /**
     * Reativa uma empresa previamente desativada (active = false → true).
     *
     * A exclusão pelo Comercial (ComercialController::destroy) é um soft-delete
     * via `active = false` — preserva mlb_empresas, sugadores e demais registros
     * relacionados. Este método permite recuperar a empresa sem recadastrar.
     */
    public function ativar(Request $request, Company $company)
    {
        $nome = $company->name;
        $company->update(['active' => true]);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $nome])
            ->log('Empresa reativada: "' . $nome . '"');

        return back()->with('success', 'Empresa "' . $nome . '" reativada.');
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
