<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Goal;
use App\Models\Servico;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\EcfDriveService;
use App\Services\Metrics\MetricsProviderFactory;
use App\Models\CompanyManagerHistory;
use App\Services\Nps\NpsScoreCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CompanyController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private EcfDriveService $ecf,
        private MetricsProviderFactory $metricsFactory,
    ) {}

    /**
     * Phase 61 Plan 61-03 (DASH-06) — Traduz o caso do ADR DATA-04
     * (`ambos|so-ml|so-adman|none`) para o vocabulário travado do
     * `<SourceBadge>` frontend (`unified|ml|adman|none`).
     *
     * Enriquecimento UNCONDITIONAL (sem feature flag): `caseFor()` é I/O-free
     * — só lê accessors denormalizados da própria Company — e o badge é
     * requisito de UI do ROADMAP (SC #3 da Phase 61), não conteúdo dinâmico
     * dependente de disponibilidade de sync.
     */
    private function factoryToSource(Company $company): string
    {
        return match ($this->metricsFactory->caseFor($company)) {
            'ambos'    => 'unified',
            'so-ml'    => 'ml',
            'so-adman' => 'adman',
            default    => 'none',
        };
    }

    private function userCanViewCompany(User $user, Company $company): bool
    {
        if ($user->isAdmin() || $user->hasPermission('core.empresas')) {
            return true;
        }

        return $company->users()
            ->where('users.id', $user->id)
            ->exists();
    }

    private function userIsCompanyEstrategista(User $user, Company $company): bool
    {
        return $company->users()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'estrategista')
            ->exists();
    }

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

        // Phase 35 Plan 35-01 (D-02) — sort opcional por created_at. Usado pela
        // aba Pendencias quando filtro=empresa_nova para o admin priorizar
        // recem-cadastradas (ou ver antigas). Default mantem ordem alfabetica.
        $sort = $request->input('sort');
        if (!in_array($sort, ['nova_recente', 'nova_antiga'], true)) {
            $sort = null;
        }

        // Phase 35 Plan 35-01 (D-03) — exclui empresas com MlbEmpresa associada
        // para evitar dupla contagem com /mlb/empresas (Polos/Publicacao/etc).
        // Aplicado como query base — tanto lista quanto contadores (`pendCounts`)
        // refletem o mesmo conjunto.
        $companies = Company::with([
                // Fase 89 Plan 02 (CART-08): reapontado para as relações
                // filtradas por setor performance — a coluna Analista/
                // Estrategista nunca deve mostrar o responsável Shopee.
                // As chaves do payload continuam 'consultor'/'estrategista'
                // (só a FONTE muda) — Companies/Index.jsx não muda.
                'analistaPerformance',
                'estrategistaPerformance',
                // Contratos ATIVOS com servico embedado — alimenta a coluna "Serviço"
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'mlToken',
                'grupo:id,name,color',
            ])
            // Grant ativo local (company_grants sincronizado da API ECF Drive) para a pendência "sem grant".
            ->withCount(['grants as grants_ativos_count' => fn($q) => $q->where('status', 'active')])
            ->whereDoesntHave('mlbEmpresa')
            // Phase 37 Plan 37-06 (REQ-37-07) — /companies refoca em Performance
            // (Gestao + Mentoria). Empresas com contratos APENAS em Publicacao/Outros
            // sao visiveis em /comercial/empresas/listagem (Plan 37-05). MlbEmpresa
            // ja excluido acima (Phase 35 preservada).
            ->whereHas('contratosServico', fn($q) =>
                $q->where('contratos_servico.ativo', true)
                  ->whereHas('servico', fn($qs) =>
                      $qs->where('setor', Servico::SETOR_PERFORMANCE)
                  )
            )
            ->when($custIdStatusFilter, fn($q) => $q->where('cust_id_status', $custIdStatusFilter))
            ->when($sort, function ($q) use ($sort) {
                // Quando sort por created_at solicitado, prioriza essa ordenacao.
                // Sem sort, mantem alfabetico por nome (comportamento legado).
                $q->orderBy('created_at', $sort === 'nova_recente' ? 'desc' : 'asc');
            }, function ($q) {
                $q->orderBy('name');
            })
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
                // Phase 34 Plan 34-01 — info do close comercial (alimenta o modal
                // admin Plan 34-03). Quick task 260805-eqk removeu nicho/dor/
                // vende_ml/faturamento_mensal/marketplaces_extras; o
                // email_colaborador permanece editável aqui.
                'email_colaborador'   => $c->email_colaborador,
                // Phase 34 Plan 34-01 — tag "Empresa nova" (D-06). Bool puro alimenta o badge na linha.
                'empresa_nova'        => (bool) $c->empresa_nova,
                // Phase 59 fix — usa o accessor cust_id (adman_account_id ?: ml_store_id)
                // em vez de replicar a resolução manualmente com ordem invertida
                // (ver 59-AUDIT.md item CompanyController.php:129).
                'adman_account_id' => $c->cust_id,
                'adman_store_id'   => $c->adman_store_id,
                'ml_store_id'      => $c->ml_store_id,
                'consultor'        => $c->analistaPerformance->first()?->only(['id', 'name']),
                'estrategista'     => $c->estrategistaPerformance->first()?->only(['id', 'name']),
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
                // Pendências calculadas — alimenta a aba "Pendências".
                // Só fazem sentido para empresas ativas; o frontend filtra por active.
                //
                // Phase 34 Plan 34-01 (D-07): a pendência "sem_email_colaborador" agora
                // checa o campo correto `email_colaborador` (email criado pela ECF p/ acesso
                // no ML) em vez do `email_cliente` (email do proprietário usado pelo NPS).
                // Phase 34 Plan 34-01 (D-06): nova pendência `empresa_nova` — empresa
                // recém-cadastrada que ainda não foi triada pelo admin. Removida via
                // botão "Marcar como visto" (POST /companies/{company}/marcar-visto).
                'pendencias'       => array_values(array_filter([
                    // Fase 89 (CART-08) — AND→OR sobre as relações de
                    // Performance; a lista de pendências CRESCE (~7 empresas
                    // reais sem analista passam a aparecer) — comportamento
                    // desejado: essas empresas acumularam invisíveis
                    // justamente pelo AND antigo, que só acusava quando os
                    // DOIS papéis estavam vazios.
                    ($c->analistaPerformance->isEmpty() || $c->estrategistaPerformance->isEmpty()) ? 'sem_responsavel' : null,
                    (! $c->adman_account_id && ! $c->ml_store_id)             ? 'sem_cust_id' : null,
                    (! $c->email_colaborador)                                 ? 'sem_email_colaborador' : null,
                    ($c->grants_ativos_count < 1)                            ? 'sem_grant_ativo' : null,
                    // Phase 37 Plan 37-06 (REQ-37-07) — pendencia sem_servico removida.
                    // Migrou para /comercial/empresas/listagem (Plan 37-05) — apos o
                    // scope whereHas Performance acima, toda empresa em /companies ja
                    // tem >=1 contrato Performance ativo por definicao.
                    $c->empresa_nova                                          ? 'empresa_nova' : null,
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
        // Phase 37 hotfix 2026-06-19 — restrito a Servico::SETOR_PERFORMANCE pq /companies
        // refoca em Performance (REQ-37-07 do Plan 37-06). Sem o filtro, chips de
        // Polos/Publicação/Outros apareciam mas retornavam 0 (ou contavam empresas
        // multi-contrato Performance+Outro), confundindo o usuario.
        $servicoCounts = DB::table('contratos_servico as cs')
            ->join('servicos as s', 's.id', '=', 'cs.servico_id')
            ->join('companies as c', 'c.id', '=', 'cs.company_id')
            ->where('cs.ativo', true)
            ->where('c.active', true)
            ->where('s.setor', Servico::SETOR_PERFORMANCE)
            ->groupBy('s.id', 's.nome')
            ->orderBy('s.nome')
            ->selectRaw('s.id, s.nome, COUNT(DISTINCT c.id) as total')
            ->get();

        // Catálogo de serviços para o "Atribuir serviço ao grupo" (GruposManager).
        // Phase 37 hotfix 2026-06-19 — restrito a Performance (mesmo motivo dos chips
        // acima; GruposManager foi movido pro Comercial mas o select usado no modal
        // admin de /companies ainda consulta servicos_disponiveis).
        $servicosDisponiveis = \App\Models\Servico::where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        return Inertia::render('Companies/Index', [
            'companies'            => $companies,
            'users'                => $users,
            'estrategistas'        => $estrategistas,
            'analistas'            => $analistas,
            'grupos'               => $grupos,
            'servico_counts'       => $servicoCounts,
            'servicos_disponiveis' => $servicosDisponiveis,
            // Phase 18 W5-T4 — Filtro snake_case; null se nao aplicado.
            // Phase 35 Plan 35-01 (D-02) — `sort` exposto ao frontend para
            // sincronizar o estado do <Select> de ordenacao na aba Pendencias.
            'filters'        => [
                'cust_id_status' => $custIdStatusFilter,
                'sort'           => $sort,
            ],
        ]);
    }

    public function show(Company $company)
    {
        $user = request()->user();

        abort_unless($user && $this->userCanViewCompany($user, $company), 403);

        $company->load([
            // Fase 89 Plan 02 (CART-08): mesma troca de fonte do index() —
            // chaves 'consultor'/'estrategista' preservadas, Companies/Show.jsx não muda.
            'analistaPerformance', 'estrategistaPerformance',
            // Phase 62 Plan 62-05 (META-01): eager load pega os 12 results
            // MAIS RECENTES (desc + limit) — o mapper depois reordena ASC
            // para alimentar o chart do <GoalProgressPanel /> temporalmente.
            'goals' => fn($q) => $q->where('active', true)
                ->with(['results' => fn($rq) => $rq->orderBy('period', 'desc')->limit(12)]),
            'ppas.mentor',
            // Fase 108 — histórico de gerenciamento (entrada/saída de responsáveis).
            'managerHistory' => fn($q) => $q->with(['user:id,name', 'changedBy:id,name'])->limit(30),
            // Phase 72 Plan 02 — SC#5: eager-load response.answers alem de
            // response, pra NpsScoreCalculator::compute() nao gerar N+1 quando
            // recalcula medias por dimensao (surveys v15 com template_id).
            // Phase 96 Plan 04 (AB-96-3 · call-site #10) — resposta invalidada
            // pelo admin vira null aqui (o builder do payload abaixo já trata
            // response null), some do avgNps e da lista "NPS Respondidos" em
            // Companies/Show.jsx. Tela visível a QUALQUER usuário com acesso à
            // empresa, não só admin.
            'npsSurveys' => fn($q) => $q->where('status', 'completed')->with(['response' => fn ($rq) => $rq->valida()->with('answers'), 'template'])->orderBy('completed_at', 'desc')->limit(10),
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
        // Fase 108 — do ECF Drive só interessa o HISTÓRICO DE MEDALHAS ML (+ a
        // medalha atual). O resto do bloco "Análise ECF Drive" (métricas 12m,
        // cluster, alertas/signals) foi removido da página. Try/catch silencioso
        // — falha do ECF NUNCA quebra a página.
        $ecfDrive = null;
        if ($custId) {
            try {
                $medalhas     = [];
                $medalhaAtual = null;
                try {
                    $r = $this->ecf->sellerMedalhas((string) $custId);
                    $medalhas = array_slice($r['data'] ?? [], -12);
                } catch (\Throwable $e) { Log::warning("[Companies/Show] ECF medalhas falhou cust={$custId}: " . $e->getMessage()); }
                try {
                    $sellerData   = $this->ecf->seller((string) $custId);
                    $medalhaAtual = $sellerData['medalhaAtual'] ?? ($sellerData['medalha'] ?? null);
                } catch (\Throwable $e) { Log::warning("[Companies/Show] ECF seller falhou cust={$custId}: " . $e->getMessage()); }

                if (!empty($medalhas) || $medalhaAtual !== null) {
                    $ecfDrive = ['medalhas' => $medalhas, 'medalha_atual' => $medalhaAtual];
                }
            } catch (\Throwable $e) {
                Log::warning("[Companies/Show] ECF Drive indisponível cust={$custId}: " . $e->getMessage());
                $ecfDrive = null;
            }
        }

        // Catálogo de serviços ativos para popular o <Select> do modal "Adicionar contrato"
        $servicosDisponiveis = Servico::active()
            ->orderBy('nome')
            ->get(['id', 'nome', 'valor_padrao', 'tipo_cobranca']);

        // Fase 116 Plan 05 · card de média de NPS da empresa conta o NPS
        // efetivamente disparado e não respondido como nota 1 (D7). A LISTA
        // "NPS Respondidos" (`nps_surveys` abaixo) NÃO muda — continua
        // eager-loaded com `status = 'completed'`, só respostas reais. Aqui
        // calculamos a média OFICIAL (com o piso) e expomos em `nps_avg`
        // separadamente. Sem janela de data nova — mesma janela "irrestrita"
        // que a lista de respostas já usa hoje (limit 10 mais recentes, sem
        // corte por período); mesmo racional do Plan 116-04 (PortfolioController)
        // pra `notasDaEmpresa()` sem filtro de data.
        $calculatorEmpresa = app(\App\Services\Nps\NpsScoreCalculator::class);
        $notasReaisEmpresa = $company->npsSurveys
            ->map(function ($s) use ($calculatorEmpresa) {
                $response = $s->response;
                if (!$response) {
                    return null;
                }
                return $s->template_id !== null
                    ? $calculatorEmpresa->compute($response, 'empresa')
                    : $response->score_empresa;
            })
            ->filter(fn ($n) => $n !== null);

        $notasImputadasEmpresa = app(\App\Services\Nps\NpsImputationService::class)->notasDaEmpresa(
            collect([$company->id]),
            'empresa',
            Carbon::createFromDate(1970, 1, 1),
            now(),
        )->pluck('nota');

        // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no mês
        // também conta nota 1, mesma régua do bônus. A LISTA `nps_surveys`
        // abaixo NÃO muda — continua só com respostas reais; só `nps_avg`/
        // `nps_nao_respondidos` somam esta fonte nova. Piso retroativo
        // (DEC-09-B): leitura em JANELA ROLANTE (início relativo a `now()`),
        // nunca alcança mais que mês anterior + corrente — o histórico
        // antigo não é reescrito por este ramo.
        $semLinkService = app(\App\Services\Desempenho\NpsSemLinkService::class);
        $notasSemLinkEmpresa = $semLinkService->notasDaEmpresaSemLink(
            collect([$company->id]),
            'empresa',
            Carbon::createFromDate(1970, 1, 1),
            now(),
            null,
            null,
            $semLinkService->pisoRetroativo(),
        )->pluck('nota');

        // Cast explícito pra Collection base antes do merge(): $notasReaisEmpresa
        // é Eloquent\Collection (herda o tipo de $company->npsSurveys via
        // ->map()/->filter()) e o merge() dela assume Model::getKey() —
        // armadilha documentada nos Plans 116-03/116-04.
        $notasEmpresaCard = collect($notasReaisEmpresa->all())
            ->merge($notasImputadasEmpresa)
            ->merge($notasSemLinkEmpresa);
        $npsAvg = $notasEmpresaCard->isNotEmpty() ? round((float) $notasEmpresaCard->avg(), 1) : null;
        // Fase 116 Plan 07 (tarefa adicional) — quantidade de notas reais vs.
        // notas de não respondido que compõem `nps_avg` acima, para a página
        // da empresa explicar a queda da média sem jargão (mesmo espírito da
        // área NPS: "respondida(s)" · "sem resposta (contam 1)"). Nota:
        // $notasReaisEmpresa reflete só os 10 surveys respondidos mais
        // recentes (mesma limitação já existente do `nps_surveys` eager-load
        // usada pelo `nps_avg` desde o Plan 116-05). Fase 119.1 Plan 09 —
        // `$npsNaoRespondidos` soma as DUAS fontes (survey disparado sem
        // resposta + empresa elegível sem NENHUM link).
        $npsRespondidos     = $notasReaisEmpresa->count();
        $npsNaoRespondidos  = $notasImputadasEmpresa->count() + $notasSemLinkEmpresa->count();

        return Inertia::render('Companies/Show', [
            'company' => [
                'id'               => $company->id,
                'name'             => $company->name,
                'cnpj'             => $company->cnpj,
                'segment'          => $company->segment,
                'active'           => $company->active,
                'notes'            => $company->notes,
                // Fase 108 — data de entrada da empresa (cadastro).
                'data_entrada'     => optional($company->created_at)->toDateString(),
                // Phase 31 D-04 + Quick 260611-eml — contato do cliente
                'email_cliente'    => $company->email_cliente,
                'telefone'         => $company->telefone,
                // Campos SPIN do deal HubSpot (do snapshot) — seção SPIN na página da empresa.
                'spin'             => $company->hubspot_spin,
                // Quick task 260805-eqk — Notes do deal (bloco "Observações
                // (HubSpot)") + origem do lead vinda do contato principal.
                'hubspot_notas'    => $company->hubspot_notas ?? [],
                'origem_lead'      => $company->origem_lead,
                // Phase 34 Plan 34-03 — info do close comercial. Quick task
                // 260805-eqk removeu nicho/dor/vende_ml/faturamento_mensal/
                // marketplaces_extras da seção "Informações comerciais".
                'email_colaborador'   => $company->email_colaborador,
                'adman_account_id' => $company->adman_account_id,
                'adman_store_id'   => $company->adman_store_id,
                'ml_store_id'      => $company->ml_store_id,
                // Phase 61 Plan 61-03 (DASH-06) — origem para SourceBadge no
                // header. Derivado do ADR DATA-04 via MetricsProviderFactory
                // (leitura denormalizada, I/O-free — safe by default).
                'source'           => $this->factoryToSource($company),
                'ml_token'         => $company->mlToken ? [
                    'status'            => $company->mlToken->status,
                    'ml_user_id'        => $company->mlToken->ml_user_id,
                    'expires_at'        => $company->mlToken->expires_at?->toISOString(),
                    'connected_at'      => $company->mlToken->connected_at?->toISOString(),
                    'last_refreshed_at' => $company->mlToken->last_refreshed_at?->toISOString(),
                ] : null,
                // Fase 108 — só Faturamento + Margem (Tacos/Acos saíram da página).
                'revenue_30d'      => $revenue30d,
                'margin_pct_30d'   => $margin30d,
                'liquid_margin_30d'=> $liquidMargin30d,
                'ad_investment_30d'=> $adInvestment30d,
                // Responsáveis atuais + "desde" (assigned_at do pivot performance).
                'consultor'        => $company->analistaPerformance->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name,
                    'desde' => $u->pivot->assigned_at ? Carbon::parse($u->pivot->assigned_at)->toDateString() : null,
                ])->values(),
                'estrategista'     => $company->estrategistaPerformance->map(fn ($u) => [
                    'id' => $u->id, 'name' => $u->name,
                    'desde' => $u->pivot->assigned_at ? Carbon::parse($u->pivot->assigned_at)->toDateString() : null,
                ])->values(),
                // Phase 62 Plan 62-05 (META-01): shape enriquecido — inclui
                // value_type/period_type + results[] (ate 12 periodos, ASC pra
                // chart). Alimenta <GoalProgressPanel /> na Section "Metas Ativas".
                // results[] pode ser vazio (goal recem-criada sem historico).
                'goals'            => $company->goals->map(fn($g) => [
                    'id'           => $g->id,
                    'metric'       => $g->metric,
                    'metric_label' => $g->metric_label,
                    'target_value' => $g->target_value,
                    'value_type'   => $g->value_type,
                    'period_type'  => $g->period_type,
                    'active'       => $g->active,
                    'results'      => $g->results
                        ->sortBy('period')
                        ->values()
                        ->map(fn($r) => [
                            'id'             => $r->id,
                            'period'         => $r->period,
                            'realized_value' => (float) $r->realized_value,
                            'target_value'   => (float) $r->target_value,
                            'achieved'       => (bool) $r->achieved,
                        ])->all(),
                ])->values(),
                // Fase 108 — histórico de gerenciamento (entrada/saída de responsáveis).
                'historico_gestao' => $company->managerHistory->map(fn ($h) => [
                    'id'         => $h->id,
                    'user'       => $h->user?->name,
                    'papel'      => $h->papel,   // analista | estrategista
                    'evento'     => $h->evento,  // entrada | saida
                    'changed_by' => $h->changedBy?->name,
                    'data'       => optional($h->created_at)->toDateString(),
                ])->values(),
                // Phase 72 Plan 02 — SC#5 (dual-path NpsScoreCalculator):
                // Surveys v15 (template_id != null) tem medias por dimensao
                // recalculadas a partir de nps_response_answers.option_peso_snapshot
                // via NpsScoreCalculator (respeita template_snapshot, imune a
                // hard-delete do template — Phase 69-02). Surveys legacy pre-v15
                // (template_id == null) mantem os score_* legados persistidos em
                // nps_responses (Phase 31). As CHAVES do payload
                // score_empresa/score_analista/score_estrategista sao preservadas
                // bit-a-bit — JSX Companies/Show.jsx nao muda; muda apenas o
                // valor para surveys v15. Phase 73 remove o path legacy quando
                // dashboards estiverem 100% convertidos.
                // Fase 116 Plan 05 — média oficial (com o piso do não
                // respondido); `nps_surveys` abaixo permanece intocado.
                'nps_avg'             => $npsAvg,
                // Fase 116 Plan 07 (tarefa adicional) — composição da média
                // acima: quantas notas são de resposta real vs. quantas
                // vieram de NPS enviado e não respondido (contam nota 1).
                'nps_respondidos'     => $npsRespondidos,
                'nps_nao_respondidos' => $npsNaoRespondidos,
                'nps_surveys'      => (function () use ($company) {
                    $calculator = app(NpsScoreCalculator::class);
                    return $company->npsSurveys->map(function ($s) use ($calculator) {
                        $response = $s->response;
                        $isV15    = $s->template_id !== null;
                        return [
                            'id'          => $s->id,
                            'status'      => $s->status,
                            'template_id' => $s->template_id,
                            'response'    => $response ? [
                                'respondent_name'    => $response->respondent_name,
                                'score_empresa'      => $isV15
                                    ? $calculator->compute($response, 'empresa')
                                    : $response->score_empresa,
                                'score_analista'     => $isV15
                                    ? $calculator->compute($response, 'analista')
                                    : $response->score_analista,
                                'score_estrategista' => $isV15
                                    ? $calculator->compute($response, 'estrategista')
                                    : $response->score_estrategista,
                                'comment'            => $response->comment,
                            ] : null,
                        ];
                    })->values();
                })(),
                'ppas'             => $company->ppas->map(fn($p) => [
                    'id' => $p->id, 'title' => $p->title,
                    'completion_pct' => $p->completion_pct,
                    'actions_count'  => count($p->actions ?? []),
                    // Mentor do PPA é um conceito separado (Ppa.mentor_id) — não
                    // renomeado pra estrategista. Aqui é o user responsável pelo plano,
                    // não necessariamente o Estrategista da empresa.
                    'mentor' => $p->mentor ? ['name' => $p->mentor->name] : null,
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
            // Phase 25 Plano 04: bloco ECF Drive (seller + 12m métricas + medalhas + signals)
            // ou null quando empresa não tem cust_id ou API ECF Drive indisponível.
            'goal_metrics'          => Goal::$metricLabels,
            'goal_percentage_only_metrics' => Goal::$percentageOnlyMetrics,
            'permissions'           => [
                'can_manage_contracts'    => $user->isAdmin(),
                'can_create_goals'        => $user->isAdmin() || $this->userIsCompanyEstrategista($user, $company),
                'can_initiate_ml_oauth'   => true,
                'can_disconnect_ml_oauth' => $user->isAdmin(),
                'can_sync_ml'             => $user->isAdmin(),
            ],
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
            // Phase 34 Plan 34-03 — info do close comercial. Quick task
            // 260805-eqk removeu nicho/dor/vende_ml/faturamento_mensal/
            // marketplaces_extras (colunas inexistentes).
            // Phase 34 D-07 — email criado pela ECF para acesso colaborador no ML
            // (separado de email_cliente, que é o email do proprietário usado pelo NPS).
            'email_colaborador'      => 'nullable|email|max:255',
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
            // Fase 108 — captura os responsáveis ANTES da troca, para registrar
            // o histórico de gerenciamento (entrada/saída) logo abaixo.
            $antigoAnalista     = $company->analistaPerformance()->value('users.id');
            $antigoEstrategista = $company->estrategistaPerformance()->value('users.id');

            // Phase 76 (DEC-A3 / Pitfall 3): escrita ESCOPADA por servico_id.
            // Resolve o servico_id do contrato performance ATIVO (NULL p/ ML puro
            // sem contrato performance = slot consolidado). NUNCA detach() de TUDO
            // — isso apagaria o responsável Shopee (que vive em outro servico_id).
            $servicoMlId = $this->servicoPerformanceAtivoId($company);

            // Injeta o servico_id em cada linha do pivot a gravar (persiste a
            // coluna independente de withPivot).
            foreach ($sync as $userId => $pivot) {
                $sync[$userId]['servico_id'] = $servicoMlId;
            }

            // Detach ESCOPADO ao slot performance/consolidado (roles consultor/
            // estrategista), filtrando por servico_id — whereNull quando NULL
            // (Pitfall 1: `= NULL` nunca casa). Linhas Shopee ficam intactas.
            $detach = DB::table('company_users')
                ->where('company_id', $company->id)
                ->whereIn('role', ['consultor', 'estrategista']);
            $servicoMlId === null
                ? $detach->whereNull('servico_id')
                : $detach->where('servico_id', $servicoMlId);
            $detach->delete();

            $company->users()->attach($sync);

            // Fase 108 — registra as trocas de responsável no histórico.
            $this->registrarHistoricoGestao(
                $company,
                $antigoAnalista !== null ? (int) $antigoAnalista : null,
                $antigoEstrategista !== null ? (int) $antigoEstrategista : null,
                !empty($data['consultor_id']) ? (int) $data['consultor_id'] : null,
                !empty($data['estrategista_id']) ? (int) $data['estrategista_id'] : null,
                (int) $request->user()->id,
            );
        }

        return back()->with('success', 'Empresa atualizada com sucesso.');
    }

    /**
     * Fase 108 — registra entrada/saída de responsáveis (analista/estrategista)
     * comparando o estado anterior com o novo. Só grava o que MUDOU.
     */
    private function registrarHistoricoGestao(
        Company $company,
        ?int $antigoAnalista,
        ?int $antigoEstrategista,
        ?int $novoAnalista,
        ?int $novoEstrategista,
        int $changedBy,
    ): void {
        $papeis = [
            ['analista',     $antigoAnalista,     $novoAnalista],
            ['estrategista', $antigoEstrategista, $novoEstrategista],
        ];

        foreach ($papeis as [$papel, $antigo, $novo]) {
            if ($antigo === $novo) {
                continue; // sem troca neste papel
            }
            if ($antigo) {
                CompanyManagerHistory::create([
                    'company_id' => $company->id, 'user_id' => $antigo,
                    'papel' => $papel, 'evento' => 'saida', 'changed_by' => $changedBy,
                ]);
            }
            if ($novo) {
                CompanyManagerHistory::create([
                    'company_id' => $company->id, 'user_id' => $novo,
                    'papel' => $papel, 'evento' => 'entrada', 'changed_by' => $changedBy,
                ]);
            }
        }
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
     * Resolve o servico_id do contrato PERFORMANCE ativo da empresa.
     *
     * Retorna null para empresa ML pura sem contrato performance ativo — nesse
     * caso a atribuição cai no slot consolidado (servico_id NULL), consistente
     * com a data-migration do Plano 76-01 (DEC-A3 / Open Question 1). MIN()
     * torna determinístico caso haja mais de um contrato performance ativo.
     */
    private function servicoPerformanceAtivoId(Company $company): ?int
    {
        $id = DB::table('contratos_servico as ct')
            ->join('servicos as s', 's.id', '=', 'ct.servico_id')
            ->where('ct.company_id', $company->id)
            ->where('ct.ativo', true)
            ->where('s.setor', Servico::SETOR_PERFORMANCE)
            ->min('ct.servico_id');

        return $id === null ? null : (int) $id;
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
            // Phase 76 (DEC-A3): escrita ESCOPADA por servico_id do contrato
            // performance ATIVO (NULL = slot consolidado p/ ML puro). Apaga só o
            // slot performance/consolidado daquele papel — não toca linhas Shopee.
            $servicoMlId = $this->servicoPerformanceAtivoId($c);

            $del = DB::table('company_users')
                ->where('company_id', $c->id)
                ->where('role', $data['role']);
            // Pitfall 1: `= NULL` nunca casa — usar whereNull no slot consolidado.
            $servicoMlId === null
                ? $del->whereNull('servico_id')
                : $del->where('servico_id', $servicoMlId);
            $del->delete();

            $c->users()->attach($data['user_id'], [
                'role'        => $data['role'],
                'servico_id'  => $servicoMlId,
                'assigned_at' => now()->toDateString(),
            ]);
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

    /**
     * Phase 34 Plan 34-01 (D-06) — "Marcar como visto" para tag "Empresa nova".
     *
     * Botão exibido na linha da empresa em /companies (aba Pendências) quando
     * `empresa_nova=true`. Apenas admin pode acionar — qualquer outro role
     * recebe 403. Não dispara activity log (mudança operacional rotineira).
     */
    public function marcarVisto(Request $request, Company $company)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $company->update([
            'empresa_nova'           => false,
            'empresa_nova_visto_em'  => now(),
            'empresa_nova_visto_por' => $request->user()->id,
        ]);

        return back()->with('success', "Empresa {$company->name} marcada como vista.");
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
