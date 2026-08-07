<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
use App\Models\HubspotLineItemMapping;
use App\Models\MlbEmpresa;
use App\Models\MlbImplementacao;
use App\Models\Servico;
use App\Models\Setor;
use App\Notifications\EmpresaCadastradaNotification;
use App\Services\Comercial\PendenciasComerciaisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Módulo Comercial — ponto centralizado de cadastro de novas empresas.
 *
 * O setor Comercial é a única porta de entrada para criação de companies no
 * sistema. Com base nos serviços selecionados (catálogo da Frente A), o
 * controller cria os registros necessários em DB::transaction() e notifica
 * automaticamente os líderes do setor de destino após a confirmação da
 * transação.
 *
 * Phase 14 (Plan 14-04 — Frente B): cadastro passou a aceitar `servicos[]`
 * (lista de servico_id + valor_contratado opcional) em vez do enum legacy
 * preservado via helper estático `servicoDisparaImplementacao()`.
 *
 * Rotas protegidas por middleware 'permission:comercial.cadastrar_empresa'.
 * Admin tem acesso via short-circuit (hasPermission retorna true para admin).
 */
class ComercialController extends Controller
{
    /**
     * Roteia o NOME de um serviço (catálogo Frente A) para o slug de
     * implementação que dispara criação de `mlb_empresas`/`mlb_implementacao`.
     *
     * Helper PURO — testável sem precisar do container Laravel. Substitui o
     *
     * Critério: `str_contains` case-sensitive nos prefixos canônicos. Nomes
     * vindos do catálogo são normalizados em Title Case (D-02), então
     * 'Polos', 'Polos SP', 'Assessoria Premium' batem. Variantes em
     * lowercase ('polos') retornam null por design (não entram no catálogo
     * via fluxo normal).
     *
     * @return 'polos'|'assessoria'|'incubadora'|null
     */
    public static function servicoDisparaImplementacao(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'polos',
            str_contains($nome, 'Assessoria') => 'assessoria',
            str_contains($nome, 'Incubadora') => 'incubadora',
            default                            => null,
        };
    }

    /**
     * Form GET para cadastro de nova empresa.
     *
     * Phase 14 Plan 14-04: substitui o antigo `index()` (que era apenas um
     * redirect noop) — agora retorna a página `Comercial/NovaEmpresa` com o
     * catálogo ativo de serviços para popular o seletor multi da UI.
     */
    public function create()
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $servicos = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Comercial/NovaEmpresa', [
            'servicos_disponiveis' => $servicos,
        ]);
    }

    /**
     * @deprecated Mantido apenas para compatibilidade de redirect; use create().
     *
     * Phase 36 Plan 36-01 — antes redirecionava para `comercial.empresas`, que
     * por sua vez também é redirect (D-01). Aponta direto para `comercial.empresas.novo`
     * para evitar redirect duplo.
     */
    public function index()
    {
        return redirect()->route('comercial.empresas.novo');
    }

    /**
     * Phase 36 Plan 36-02 (D-03) — Página dedicada do Comercial para atribuir
     * contrato de serviço a uma empresa existente.
     *
     * Migrada de `/administrativo/empresas` (Admin/Empresas.jsx modal — D-06)
     * — quem sabe o que o cliente fechou é o time Comercial. A página recebe
     * a `Company` via route model binding e expõe:
     *   - dados contextuais da empresa (nome, cust_id, segment, contato)
     *   - histórico de contratos (ativos + inativos) para conferência
     *   - catálogo `servicos_disponiveis` (Servico::ativo=true) para o form
     *
     * Permissão: `comercial.cadastrar_empresa` ou admin (mesmo critério dos
     * demais endpoints do Comercial — quem cadastra empresa também atribui
     * serviço). Submit do form usa o endpoint backend já existente
     * `/empresas/{company}/contratos-servico` (POST/PUT), inalterado (D-04).
     */
    public function atribuirServico(Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // Histórico completo (ativos + inativos) ordenado do mais recente para o
        // mais antigo. A UI mostra ativos em destaque; inativos servem de
        // referência caso o usuário precise re-contratar um serviço cancelado.
        $contratos = $company->contratosServico()
            ->with('servico')
            ->orderByDesc('data_contratacao')
            ->get()
            ->map(fn($ct) => [
                'id'               => $ct->id,
                'servico_id'       => $ct->servico_id,
                'servico_nome'     => $ct->servico?->nome,
                'tipo_cobranca'    => $ct->servico?->tipo_cobranca,
                'valor_contratado' => (float) $ct->valor_contratado,
                'data_contratacao' => $ct->data_contratacao?->toDateString(),
                'data_vencimento'  => $ct->data_vencimento?->toDateString(),
                'observacoes'      => $ct->observacoes,
                'ativo'            => (bool) $ct->ativo,
            ]);

        $servicosDisponiveis = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Comercial/AtribuirServico', [
            'company' => [
                'id'            => $company->id,
                'name'          => $company->name,
                'cnpj'          => $company->cnpj,
                'cust_id'       => $company->cust_id ?? null,
                'segment'       => $company->segment ?? null,
                'email_cliente' => $company->email_cliente,
                'telefone'      => $company->telefone,
            ],
            'contratos'            => $contratos,
            'servicos_disponiveis' => $servicosDisponiveis,
        ]);
    }

    /**
     * Phase 37 Plan 37-05 (REQ-37-05/06/08/10) — Listagem unificada do Comercial.
     *
     * Endpoint GET /comercial/empresas/listagem renderiza
     * `Comercial/EmpresasListagem` com TODAS as empresas (todos os setores),
     * filtros snake_case empilhaveis (servico, setor, ordem, pendencia, q),
     * 4 cards de pendencia comercial (sem_servico, sem_valor,
     * servico_nao_reconhecido, sem_setor) calculadas APENAS para empresas com
     * origem HubSpot (REQ-37-10) — a quick task 260805-eqk removeu
     * `dados_close_incompletos` junto com as colunas que a alimentavam —, e
     * `grupos`/`servicos_disponiveis` para a aba de gestao de grupos
     * (reaproveita GruposManager + rotas company-groups.* existentes).
     *
     * Lição Phase 18 — filtros empilháveis: `applyFilter` no frontend deve
     * passar `{...filters, [key]: value}` para preservar os outros 4 filtros
     * ao alterar 1. Backend honra o whitelist nos enums (setor/ordem/pendencia)
     * com `in_array` antes de aplicar.
     */
    public function listagem(Request $request, PendenciasComerciaisService $pendencias)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // (1) Sanitização snake_case com whitelist em PHP (T-37-12 mitigation)
        $filters = [
            'servico'   => $request->input('servico'),
            'setor'     => in_array($request->input('setor'), [Servico::SETOR_PERFORMANCE, Servico::SETOR_PUBLICACAO, Servico::SETOR_OUTROS], true)
                ? $request->input('setor')
                : null,
            'ordem'     => in_array($request->input('ordem'), ['recentes', 'antigas'], true)
                ? $request->input('ordem')
                : 'recentes',
            'pendencia' => in_array($request->input('pendencia'), [
                'sem_servico', 'sem_valor', 'servico_nao_reconhecido', 'sem_setor',
                // Phase 114 (HUB-UI-02) — 3 pendencias novas, so origem HubSpot.
                'sem_contato', 'valor_revisar', 'possivel_duplicidade',
            ], true) ? $request->input('pendencia') : null,
            'q'         => $request->input('q'),
        ];

        // (2) Query base. withExists materializa a flag `hubspot_evento_origem_exists`
        // sem joins desnecessarios (subquery EXISTS). hubspotEventos eager-loaded
        // para detectar `servico_nao_reconhecido` sem N+1.
        $query = Company::with([
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'consultor',
                'estrategista',
                'grupo:id,name,color',
                'hubspotEventos' => fn($q) => $q->orderByDesc('id')->limit(3),
            ])
            ->where('active', true)
            ->withExists(['hubspotEventoOrigem']);

        // (3) Filtros sql (empilháveis — Lição Phase 18)
        if ($filters['servico']) {
            $query->whereHas('contratosServico', fn($q) =>
                $q->where('servico_id', $filters['servico'])->where('ativo', true)
            );
        }
        if ($filters['setor']) {
            $query->whereHas('contratosServico', fn($q) =>
                $q->where('ativo', true)
                  ->whereHas('servico', fn($s) => $s->where('setor', $filters['setor']))
            );
        }
        if ($filters['q']) {
            $qLike = '%' . trim($filters['q']) . '%';
            $query->where(function ($w) use ($qLike) {
                $w->where('name', 'like', $qLike)
                  ->orWhere('cnpj', 'like', $qLike);
            });
        }
        $query->orderBy('created_at', $filters['ordem'] === 'antigas' ? 'asc' : 'desc');

        // (4) Materializa, anota pendencias e is_origem_hubspot em PHP
        // (paginação manual depois do filtro pendencia para não falsear contagens).
        $todasEmpresas = $query->get();

        $todasEmpresas->each(function (Company $c) use ($pendencias) {
            $c->is_origem_hubspot      = (bool) ($c->hubspot_evento_origem_exists ?? false);
            $c->pendencias_comerciais  = $pendencias->calcular($c);
        });

        // (5) pendencia_counts ANTES do filtro pendencia (contagens absolutas)
        $pendenciaCounts = [
            'sem_servico'             => 0,
            'sem_valor'               => 0,
            'servico_nao_reconhecido' => 0,
            'sem_setor'               => 0,
            // Phase 114 (HUB-UI-02) — 3 pendencias novas, aditivas.
            'sem_contato'             => 0,
            'valor_revisar'           => 0,
            'possivel_duplicidade'    => 0,
        ];
        foreach ($todasEmpresas as $c) {
            foreach ($c->pendencias_comerciais as $p) {
                if (isset($pendenciaCounts[$p])) {
                    $pendenciaCounts[$p]++;
                }
            }
        }

        // (6) Aplica filtro pendencia em PHP (depende do calculo acima)
        $companies = $filters['pendencia']
            ? $todasEmpresas->filter(fn($c) => in_array($filters['pendencia'], $c->pendencias_comerciais, true))->values()
            : $todasEmpresas;

        // (7) Paginação manual via LengthAwarePaginator (preserva queryString)
        $perPage = 50;
        $page    = max(1, (int) $request->input('page', 1));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $companies->forPage($page, $perPage)->values(),
            $companies->count(),
            $perPage,
            $page,
            [
                'path'  => $request->url(),
                'query' => $request->query(),
            ],
        );

        // (8) Map para payload Inertia (apenas as empresas da pagina atual)
        $companiesPaginadas = $paginator->getCollection()->map(function (Company $c) {
            $contratosAtivos = $c->contratosServico->where('ativo', true);
            $setorDominante  = $contratosAtivos->map(fn($ct) => optional($ct->servico)->setor)->filter()->first();

            // Hotfix 2026-06-19 — detalhe por pendencia pra alimentar tooltips na UI.
            // Cada chave eh o slug da pendencia; valor eh a lista de itens faltantes.
            // Frontend mostra como title="..." no badge correspondente.
            $detalhes = [];
            if (in_array('servico_nao_reconhecido', $c->pendencias_comerciais, true)) {
                $nomesPendentes = [];
                foreach ($c->hubspotEventos as $ev) {
                    foreach (($ev->payload['line_items_nao_mapeados'] ?? []) as $item) {
                        $nome = (string) ($item['name'] ?? '');
                        if ($nome !== '' && !HubspotLineItemMapping::paraNome($nome)) {
                            $nomesPendentes[] = $nome;
                        }
                    }
                }
                $detalhes['servico_nao_reconhecido'] = array_values(array_unique($nomesPendentes));
            }

            // Phase 114 (HUB-UI-02) — detalhe de valor_revisar: nomes dos
            // servicos cujo contrato tem confidence baixa OU warning.
            if (in_array('valor_revisar', $c->pendencias_comerciais, true)) {
                $nomesServicosRevisar = $contratosAtivos
                    ->filter(fn($ct) => $ct->hubspot_valor_confidence === 'low' || $ct->hubspot_valor_warning !== null)
                    ->map(fn($ct) => optional($ct->servico)->nome)
                    ->filter()
                    ->values()
                    ->all();
                $detalhes['valor_revisar'] = array_values(array_unique($nomesServicosRevisar));
            }

            // Phase 114 (HUB-UI-02) — detalhe de possivel_duplicidade: SEMPRE
            // array de 1 elemento com o NOME REAL de exibicao da empresa
            // candidata (checa snapshot primeiro, payload do evento como
            // fallback). Resolve via Company::find (lookup barato, baixo
            // volume — mesmo racional do HubspotCompanyMatcher); se a
            // candidata sumiu, cai para o nome_normalizado gravado no warning.
            if (in_array('possivel_duplicidade', $c->pendencias_comerciais, true)) {
                $warning = collect($c->hubspot_snapshot['warnings'] ?? [])
                    ->first(fn($w) => ($w['tipo'] ?? null) === 'possivel_duplicidade');

                if (!$warning) {
                    foreach ($c->hubspotEventos as $ev) {
                        $pd = ($ev->payload ?? [])['possivel_duplicidade'] ?? null;
                        if ($pd) {
                            $warning = $pd;
                            break;
                        }
                    }
                }

                $candidateId   = $warning['candidate_company_id'] ?? null;
                $nomeCandidata = $candidateId ? Company::find($candidateId)?->name : null;
                $nomeExibicao  = $nomeCandidata ?? ($warning['nome_normalizado'] ?? 'Empresa não identificada');
                $detalhes['possivel_duplicidade'] = [$nomeExibicao];
            }

            return [
                'id'                    => $c->id,
                'name'                  => $c->name,
                'cnpj'                  => $c->cnpj,
                'status'                => $c->status,
                'is_origem_hubspot'     => $c->is_origem_hubspot,
                'pendencias_comerciais' => $c->pendencias_comerciais,
                'pendencias_detalhes'   => $detalhes,
                'setor_dominante'       => $setorDominante,
                'email_cliente'         => $c->email_cliente,
                'telefone'              => $c->telefone,
                // Phase 114 (HUB-UI-01) — contato principal + observacao +
                // IDs HubSpot capturados no handoff (Fase 113). Best-effort:
                // colunas nullable, ficam null quando a empresa nao veio do
                // HubSpot ou o dado nao foi resolvido.
                'nome_contato'          => $c->nome_contato,
                'cargo_contato'         => $c->cargo_contato,
                'hubspot_observacao'    => $c->hubspot_observacao,
                // Quick task 260805-eqk — lista de Notes do deal (com data) +
                // origem do lead do contato principal.
                'hubspot_notas'         => $c->hubspot_notas ?? [],
                'origem_lead'           => $c->origem_lead,
                // Campos SPIN do deal HubSpot (do snapshot) — exibidos no modal Detalhes HubSpot.
                'spin'                  => $c->hubspot_spin,
                'hubspot_deal_id'       => $c->hubspot_deal_id,
                'hubspot_company_id'    => $c->hubspot_company_id,
                // ISO 8601 (com hora) e nao toDateString(): a coluna "Cadastrado em"
                // da listagem exibe data E hora de entrada do lead.
                'created_at'            => $c->created_at?->toIso8601String(),
                'consultor'             => $c->consultor->first()?->only(['id', 'name']),
                'estrategista'          => $c->estrategista->first()?->only(['id', 'name']),
                'company_group_id'      => $c->company_group_id,
                'grupo'                 => $c->grupo ? [
                    'id'    => $c->grupo->id,
                    'name'  => $c->grupo->name,
                    'color' => $c->grupo->color,
                ] : null,
                'contratos_servico'     => $contratosAtivos->map(fn($ct) => [
                    'id'               => $ct->id,
                    'valor_contratado' => (float) $ct->valor_contratado,
                    'data_contratacao' => optional($ct->data_contratacao)->toDateString(),
                    'data_vencimento'  => optional($ct->data_vencimento)->toDateString(),
                    'servico'          => $ct->servico ? [
                        'id'    => $ct->servico->id,
                        'nome'  => $ct->servico->nome,
                        'setor' => $ct->servico->setor,
                    ] : null,
                    // Phase 114 (HUB-UI-01) — bloco de valor HubSpot (Fase 112),
                    // best-effort/nullable. valor_contratado continua sendo o
                    // valor operacional (nao mexer); estes sao os dados de
                    // origem para o Comercial conferir a inferencia.
                    'hubspot_valor_original'           => $ct->hubspot_valor_original !== null
                        ? (float) $ct->hubspot_valor_original
                        : null,
                    'hubspot_valor_normalizado_mensal'  => $ct->hubspot_valor_normalizado_mensal !== null
                        ? (float) $ct->hubspot_valor_normalizado_mensal
                        : null,
                    'hubspot_valor_confidence'          => $ct->hubspot_valor_confidence,
                    'hubspot_valor_warning'             => $ct->hubspot_valor_warning,
                    'hubspot_billing_frequency'         => $ct->hubspot_billing_frequency,
                ])->values(),
                'adman_account_id'      => $c->adman_account_id,
                'ml_store_id'           => $c->ml_store_id,
            ];
        })->values();

        $paginator->setCollection($companiesPaginadas);

        // (9) servico_counts — chips no header (replica padrão CompanyController::index)
        $servicoCounts = DB::table('contratos_servico as cs')
            ->join('servicos as s', 's.id', '=', 'cs.servico_id')
            ->join('companies as c', 'c.id', '=', 'cs.company_id')
            ->where('cs.ativo', true)
            ->where('c.active', true)
            ->groupBy('s.id', 's.nome', 's.setor')
            ->orderBy('s.nome')
            ->selectRaw('s.id, s.nome, s.setor, COUNT(DISTINCT c.id) as total')
            ->get();

        // (10) grupos + catálogo (alimentam aba Grupos via GruposManager)
        $grupos = CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        // Phase 37 fix pós-deploy — payload enxuto com TODAS as empresas ativas
        // para alimentar GruposManager (membros do grupo + AddCompanyPicker). O
        // `companies` principal é paginado/filtrado, então não serve aqui — sem
        // este array, membros do grupo aparecem como "Nenhuma empresa neste grupo"
        // mesmo quando o header conta corretamente via `g.companies_count`.
        $companiesParaGrupos = Company::where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'active', 'company_group_id'])
            ->map(fn($c) => [
                'id'               => $c->id,
                'name'             => $c->name,
                'active'           => $c->active,
                'company_group_id' => $c->company_group_id,
            ]);

        $servicosDisponiveis = Servico::where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca', 'setor']);

        return Inertia::render('Comercial/EmpresasListagem', [
            'companies'             => $paginator,
            'companies_para_grupos' => $companiesParaGrupos,
            'filters'               => $filters,
            'pendencia_counts'      => $pendenciaCounts,
            'servico_counts'        => $servicoCounts,
            'grupos'                => $grupos,
            'servicos_disponiveis'  => $servicosDisponiveis,
        ]);
    }

    /**
     * Processa o cadastro centralizado de uma nova empresa.
     *
     * Fluxo (Phase 14 Plan 14-04):
     *   (1) Validação dos campos obrigatórios + `servicos[]` no catálogo
     *   (2) Guard de duplicata por nome (case-insensitive em companies + mlb_empresas)
     *   (3) DB::transaction:
     *       (a) Cria company com status='pendente'
     *       (b) Cria 1 contrato_servico por servico selecionado
     *       (c) Roteamento por nome → cria (opcional) mlb_empresas + mlb_implementacao
     *   (4) Activity log fora da transação
     *   (5) Notificação para líderes do setor de destino fora da transação
     */
    public function store(Request $request)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // (1) Validação — aceita servicos[] do catálogo Frente A
        $validated = $request->validate([
            'nome'                        => 'required|string|max:255',
            'cnpj'                        => 'nullable|string|max:20|unique:companies,cnpj',
            'notes'                       => 'nullable|string|max:2000',
            // Quick task 260611-eml — contato do cliente para NPS mensal + futuro contato comercial.
            'email_cliente'               => 'nullable|email|max:255',
            'telefone'                    => 'nullable|string|max:20',
            // Wizard de cadastro (Polos) — handoff opcional. Só o gmail tem consumo
            // hoje (passo "Acesso Colaborador" do Onboarding). Os demais são aceitos
            // (não quebram o submit do wizard) mas ainda não exibidos nesta instância.
            'nome_contato'                => 'nullable|string|max:255',
            'gmail_colaborador'           => 'nullable|email|max:150',
            'polo'                        => 'nullable|string|max:255',
            'grupo_whatsapp'              => 'nullable|boolean',
            // Phase 34 Plan 02 — campo do "close" comercial (opcional).
            // Backend NÃO valida formato CNPJ/Telefone — máscara só no front (D-08).
            // Quick task 260805-eqk removeu nicho/dor/vende_ml/
            // faturamento_mensal/marketplaces_extras (colunas inexistentes).
            'email_colaborador'           => 'nullable|email|max:255',
            'servicos'                    => 'required|array|min:1',
            'servicos.*.servico_id'       => [
                'required',
                'integer',
                Rule::exists('servicos', 'id')->where('ativo', true),
            ],
            'servicos.*.valor_contratado' => 'nullable|numeric|min:0',
        ]);

        // (2) Guard de duplicata — verifica companies.name e mlb_empresas.nome
        $existeEmCompanies  = Company::whereRaw('LOWER(name) = LOWER(?)', [$validated['nome']])->exists();
        $existeEmMlbEmpresa = MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)', [$validated['nome']])->exists();

        if ($existeEmCompanies || $existeEmMlbEmpresa) {
            throw ValidationException::withMessages([
                'nome' => 'Já existe uma empresa com o nome "' . $validated['nome'] . '" no sistema.',
            ]);
        }

        // (3) Criação atômica via DB::transaction
        $company         = null;
        $servicosCriados = collect();

        DB::transaction(function () use ($validated, &$company, &$servicosCriados) {
            // (a) Cria company com status pendente
            $company = Company::create([
                'name'                => $validated['nome'],
                'cnpj'                => $validated['cnpj'] ?? null,
                'notes'               => $validated['notes'] ?? null,
                // Quick 260611-eml — contato comercial + destinatário NPS mensal.
                'email_cliente'       => $validated['email_cliente'] ?? null,
                'telefone'            => $validated['telefone'] ?? null,
                // Phase 34 Plan 02 — e-mail do colaborador do onboarding (opcional).
                // Único campo do "close" que sobreviveu à quick task 260805-eqk;
                // os demais nunca chegavam do HubSpot e foram removidos.
                'email_colaborador'   => $validated['email_colaborador'] ?? null,
                'status'              => 'pendente',
                'active'              => true,
            ]);

            // (b) Cria 1 contrato_servico por servico selecionado
            // O `valor_contratado` cai no `valor_padrao` do catálogo se o cliente
            // não enviar override explícito.
            foreach ($validated['servicos'] as $item) {
                $servico = Servico::find($item['servico_id']);
                if (!$servico) {
                    continue;
                }

                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $servico->id,
                    'valor_contratado' => isset($item['valor_contratado'])
                        ? (float) $item['valor_contratado']
                        : (float) $servico->valor_padrao,
                    'data_contratacao' => now()->toDateString(),
                    'data_vencimento'  => null,
                    'ativo'            => true,
                ]);

                $servicosCriados->push($servico);
            }

            // (c) Roteamento Phase 13 PRESERVADO — inspeciona NOMES dos serviços
            // (e não slugs legacy) via helper estático.
            $tiposImplementacao = $servicosCriados
                ->map(fn($s) => self::servicoDisparaImplementacao($s->nome))
                ->filter()
                ->unique()
                ->values();

            foreach ($tiposImplementacao as $tipo) {
                if ($tipo === 'polos') {
                    $mlbEmp = MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'POLO',
                        'projeto'    => 'POLOS',
                        'company_id' => $company->id,
                    ]);
                    $this->criarImplementacaoPolo($mlbEmp, $validated);
                } elseif ($tipo === 'assessoria') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'ASSESSORIA',
                        'company_id' => $company->id,
                    ]);
                } elseif ($tipo === 'incubadora') {
                    MlbEmpresa::create([
                        'nome'       => $company->name,
                        'tipo'       => 'INCUBADORA',
                        'company_id' => $company->id,
                    ]);
                }
                // Publicidade/Gestão/Publicação (helper retorna null) — sem
                // mlb_empresas, apenas company (COM-06 preservado).
            }
        });

        // (4) Activity log — fora da transaction para não afetar rollback
        $nomesServicos = $servicosCriados->pluck('nome')->toArray();

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'servicos' => $nomesServicos])
            ->log('Empresa cadastrada pelo Comercial: "' . $company->name . '"');

        // (5) Notificação para líderes dos setores de destino — fora da transaction
        $slugsSetores = $servicosCriados
            ->map(fn($s) => $this->slugSetorParaServico($s->nome))
            ->filter()
            ->unique()
            ->values();

        foreach ($slugsSetores as $slug) {
            $setor = Setor::where('slug', $slug)->first();
            if ($setor && $setor->lideres->isNotEmpty()) {
                Notification::send(
                    $setor->lideres,
                    new EmpresaCadastradaNotification(
                        $company->name,
                        $nomesServicos,  // array (não mais string com implode '+')
                        $request->user()->id,
                    ),
                );
            }
        }

        return back()->with('success', 'Empresa "' . $company->name . '" cadastrada com sucesso.');
    }

    /**
     * Atualiza campos básicos de uma empresa existente.
     *
     * Phase 14 Plan 14-04: validação limpa de campos legacy. A gestão de
     * serviços é feita via rotas `/empresas/{company}/contratos-servico`
     * (Frente A). Plan 14-06 dropa as 6 colunas legacy do schema; este
     * método já não persiste nada legacy, então não precisará de mudanças
     * adicionais no Plan 14-06.
     *
     * ainda ser enviados pelo cliente Inertia (Comercial/Empresas.jsx
     * refator final no Plan 14-07), mas o controller os IGNORA silenciosa
     * e seguramente: campos não validados não chegam em `$validated`.
     */
    public function update(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        // Validação: name/cnpj/notes/email_cliente + as empresas vinculadas (filha_ids) ao grupo.
        // O vínculo é gerenciado PELA empresa principal (marca-se as vinculadas).
        // Phase 34 Plan 34-03 — adiciona os 6 campos do close comercial (mesmos rules
        // do Plan 34-02 ComercialController::store) para que a edição em /comercial/empresas
        // mantenha esses dados sincronizados (paridade com CompanyController::update).
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'cnpj'          => 'nullable|string|max:20|unique:companies,cnpj,' . $company->id,
            'notes'         => 'nullable|string|max:2000',
            // Phase 31 D-04 — destinatario do email NPS mensal
            'email_cliente' => 'nullable|email|max:255',
            // Quick 260611-eml — contato comercial.
            'telefone'      => 'nullable|string|max:20',
            // Phase 34 Plan 34-03 — info do close comercial. Quick task
            // 260805-eqk removeu nicho/dor/vende_ml/faturamento_mensal/
            // marketplaces_extras (colunas inexistentes).
            // Phase 34 D-07 — email criado pela ECF para acesso colaborador no ML
            // (separado de email_cliente, que é o email do proprietário usado pelo NPS).
            'email_colaborador'      => 'nullable|email|max:255',
            'filha_ids'     => 'nullable|array',
            'filha_ids.*'   => ['integer', 'exists:companies,id', Rule::notIn([$company->id])],
        ]);

        // filha_ids não é fillable → ignorado no update dos campos básicos.
        $company->update($validated);

        // Campo AUSENTE = não alterar vínculos; presente = reatribuir as vinculadas.
        if ($request->has('filha_ids')) {
            $this->sincronizarFilhas($company, $validated['filha_ids'] ?? []);
        }

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name])
            ->log('Empresa editada pelo Comercial: "' . $company->name . '"');

        return back()->with('success', 'Empresa "' . $company->name . '" atualizada com sucesso.');
    }

    /**
     * Desativa uma empresa (active = false).
     * Não exclui fisicamente para preservar os registros relacionados (mlb_empresas,
     * sugadores, etc.) e permitir recuperação via admin se necessário.
     */
    public function destroy(Request $request, Company $company)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );

        $nome = $company->name;
        $company->update(['active' => false]);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $nome])
            ->log('Empresa removida pelo Comercial: "' . $nome . '"');

        return back()->with('success', 'Empresa "' . $nome . '" removida.');
    }

    /**
     * Hotfix 2026-06-19 — atualiza valor/datas/ativo/observacoes de um
     * ContratoServico a partir do Comercial. Mesma assinatura do
     * CompanyController::updateContrato (admin-only) mas gated pelo
     * permission 'comercial.cadastrar_empresa' do grupo.
     */
    public function updateContrato(Request $request, Company $company, ContratoServico $contrato)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );
        abort_if($contrato->company_id !== $company->id, 404);

        $data = $request->validate([
            'valor_contratado' => 'required|numeric|min:0',
            'data_contratacao' => 'required|date',
            'data_vencimento'  => 'nullable|date|after_or_equal:data_contratacao',
            'ativo'            => 'boolean',
            'observacoes'      => 'nullable|string|max:1000',
        ]);

        $contrato->update($data);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'contrato_id' => $contrato->id])
            ->log('Contrato editado pelo Comercial: "' . $company->name . '"');

        return back()->with('success', 'Contrato atualizado.');
    }

    /**
     * Hotfix 2026-06-19 — desativa contrato (soft, preservando historico) a
     * partir do Comercial. Espelha CompanyController::destroyContrato.
     */
    public function destroyContrato(Request $request, Company $company, ContratoServico $contrato)
    {
        abort_unless(
            auth()->user()->hasPermission('comercial.cadastrar_empresa') || auth()->user()->isAdmin(),
            403
        );
        abort_if($contrato->company_id !== $company->id, 404);

        $contrato->update(['ativo' => false]);

        activity('comercial')
            ->causedBy($request->user())
            ->withProperties(['empresa' => $company->name, 'contrato_id' => $contrato->id])
            ->log('Contrato desativado pelo Comercial: "' . $company->name . '"');

        return back()->with('success', 'Contrato desativado.');
    }

    // ─── Métodos privados ────────────────────────────────────────────────────

    /**
     * Reatribui as empresas vinculadas (filhas) de uma empresa principal.
     *
     * O vínculo de grupo é gerenciado PELA principal: marca-se quais empresas
     * pertencem ao grupo. Garante o limite de 1 nível (mensagens em pt-BR):
     *  - a própria principal não pode estar vinculada a outra empresa;
     *  - nenhuma vinculada pode ser, ela mesma, principal de outras.
     *
     * Empresas que saíram da lista são desvinculadas (parent_company_id = null).
     *
     * @param int[] $filhaIds IDs das empresas que devem pertencer ao grupo.
     * @throws ValidationException quando o vínculo é proibido.
     */
    private function sincronizarFilhas(Company $principal, array $filhaIds): void
    {
        $filhaIds = array_values(array_unique(array_map('intval', $filhaIds)));

        // 1 nível: a própria principal não pode ser uma vinculada de outra.
        if ($principal->parent_company_id !== null && ! empty($filhaIds)) {
            throw ValidationException::withMessages([
                'filha_ids' => 'Esta empresa está vinculada a outra; desvincule-a antes de adicionar vinculadas.',
            ]);
        }

        // 1 nível: nenhuma vinculada pode ser principal de outras empresas.
        $jaPrincipais = Company::whereIn('id', $filhaIds ?: [0])
            ->whereHas('filhas')
            ->pluck('name');
        if ($jaPrincipais->isNotEmpty()) {
            throw ValidationException::withMessages([
                'filha_ids' => 'Já são principais de outro grupo e não podem ser vinculadas: ' . $jaPrincipais->implode(', ') . '.',
            ]);
        }

        // Desvincula as filhas atuais que saíram da lista.
        Company::where('parent_company_id', $principal->id)
            ->whereNotIn('id', $filhaIds ?: [0])
            ->update(['parent_company_id' => null]);

        // Vincula as selecionadas (move de outro grupo, se for o caso).
        if (! empty($filhaIds)) {
            Company::whereIn('id', $filhaIds)
                ->where('id', '!=', $principal->id)
                ->update(['parent_company_id' => $principal->id]);
        }
    }

    /**
     * Mapeia o NOME de um serviço (catálogo Frente A) para o slug do setor
     *
     * Polos/Assessoria/Publicação → setor 'publicacao'
     * Publicidade                 → setor 'publicidade'
     * Gestão                      → setor 'gestao'
     * Incubadora                  → setor 'incubadora'
     *
     * Outros nomes arbitrários retornam null (não notifica setor algum).
     */
    private function slugSetorParaServico(string $nome): ?string
    {
        return match (true) {
            str_contains($nome, 'Polos')      => 'publicacao',
            str_contains($nome, 'Assessoria') => 'publicacao',
            str_contains($nome, 'Publicação') => 'publicacao',
            str_contains($nome, 'Publicidade') => 'publicidade',
            str_contains($nome, 'Gestão')      => 'gestao',
            str_contains($nome, 'Incubadora')  => 'incubadora',
            default                             => null,
        };
    }


    /**
     * Cria uma MlbImplementacao para uma empresa POLO com os dados padrão
     * configurados em MlbConfiguracao::implementacaoPadroes().
     *
     * Lógica extraída de MlbImplementacaoController::criar() (linhas 192-207)
     * para reutilização no fluxo do Comercial sem duplicação de código (D-20).
     *
     * @param  MlbEmpresa  $empresa  Empresa POLO recém-criada.
     * @param  array        $handoff  Campos do wizard (usa só gmail_colaborador aqui).
     * @return MlbImplementacao
     */
    /**
     * Phase 35 Plan 35-02 — proxy para `MlbImplementacaoFactory::criarParaPolo`.
     *
     * Lógica original extraída para a factory estática reutilizável (D-05),
     * permitindo o `HubspotWebhookController` chamar o mesmo fluxo quando um
     * deal "Fechado Ganho" do HubSpot dispara cadastro automático. Mantemos
     * o método private aqui para preservar a API interna do controller.
     */
    private function criarImplementacaoPolo(MlbEmpresa $empresa, array $handoff = []): MlbImplementacao
    {
        return \App\Services\MlbImplementacaoFactory::criarParaPolo($empresa, $handoff);
    }
}
