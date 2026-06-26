<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeCompanySugadoresJob;
use App\Jobs\FetchAdmanMlbsByCampaignJob;
use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorAcao;
use App\Services\AdmanMcpService;
use App\Services\AdmanService;
use App\Services\SugadorAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class SugadorController extends Controller
{
    public function __construct(
        private SugadorAnalysisService $service,
        private AdmanMcpService $mcp,
        private AdmanService $adman,
    ) {}

    /**
     * Listagem paginada com filtros.
     * - admin / gestor / lider: vêem tudo
     * - consultor / mentor / analista: só carteira (via company_users)
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Sugador::class);

        $user = $request->user();

        // Prioriza sugadores identificados HOJE no topo da lista — analistas estavam
        // perdendo de vista os novos porque os antigos não-resolvidos acumulam.
        // CASE retorna 0 pra hoje e 1 pro resto; ordenação asc joga hoje pro topo.
        // Param bind ao invés de CURDATE() pra funcionar em SQLite (testes) também.
        $hoje = now()->toDateString();
        $query = Sugador::with(['company:id,name', 'resolvidoPor:id,name'])
            ->orderByRaw('CASE WHEN reference_date = ? THEN 0 ELSE 1 END', [$hoje])
            ->orderBy('reference_date', 'desc')
            ->orderBy('id', 'desc');

        $hasGlobalView = $user->isAdmin() || $user->isGestor() || $user->isLiderPub();
        if (!$hasGlobalView) {
            $query->daCarteira($user);
        }

        // Phase 19 — Foco no dia atual: detecta se a request está em "modo default"
        // (sem nenhum filtro de data ou status explícito). Quando sim, aplica
        // automaticamente reference_date=hoje + status=pendente para que o operador
        // veja os 478 sugadores de hoje em vez dos 1407 acumulados.
        $temFiltroData   = $request->filled('reference_date')
            || $request->filled('date_from')
            || $request->filled('date_to')
            || $request->boolean('include_old');
        $temFiltroStatus = $request->filled('status')
            || $request->boolean('include_resolved');

        $defaultView = (!$temFiltroData && !$temFiltroStatus) ? 'hoje' : 'custom';

        // Filtros
        if ($request->filled('company_id')) {
            $query->where('company_id', (int) $request->company_id);
        }
        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->status);
        } elseif (!$request->boolean('include_resolved')) {
            // Resolvidos acumulam (centenas) e raramente são revisitados — escondê-los
            // por padrão limpa a fila. Toggle "Incluir resolvidos" no filtro mostra.
            $query->where('status', '!=', Sugador::STATUS_RESOLVIDO);
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('reference_date')) {
            $query->whereDate('reference_date', $request->reference_date);
        }
        if ($request->filled('date_from')) {
            $query->where('reference_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('reference_date', '<=', $request->date_to);
        }
        // Filtro "responsável pela empresa" — só admin pode usar; filtra sugadores
        // pelas empresas em que o usuário escolhido aparece em company_users.
        if ($user->isAdmin() && $request->filled('user_id')) {
            $userId = (int) $request->user_id;
            $query->whereIn('company_id', function ($sub) use ($userId) {
                $sub->select('company_id')
                    ->from('company_users')
                    ->where('user_id', $userId);
            });
        }

        // Phase 19 — Aplica filtros default (vista "hoje") quando não há filtros explícitos.
        // IMPORTANTE: aplicado APÓS os filtros existentes para não sobrescrever filtros
        // explícitos do usuário (ex: ?status=resolvido já adicionou a cláusula acima).
        if ($defaultView === 'hoje') {
            // Foco no dia atual — D-1 da Adman publicado às ~10h BRT; análise roda às 12h.
            // Sem este filtro, 1407 acumulados escondiam os 478 sugadores do dia.
            $query->whereDate('reference_date', today());
            $query->where('status', Sugador::STATUS_PENDENTE);
        }

        $sugadores = $query->paginate(50)->withQueryString();

        // Empresas para o filtro: globais ou apenas da carteira
        // Phase 18 W5-T3 — incluimos `cust_id_status` no SELECT para popular o
        // badge "Cust ID Invalido" no CompanyCard sem fazer N+1 lookup.
        $companiesQuery = Company::where('active', true)->orderBy('name');
        if (!$hasGlobalView) {
            $companiesQuery->whereIn('id', $user->companies()->pluck('companies.id'));
        }
        $companies = $companiesQuery->get(['id', 'name', 'cust_id_status']);

        // Usuários para o filtro "Responsável" (admin only): apenas os que têm
        // ao menos 1 empresa em company_users — outros não fariam diferença no filtro.
        $users = $user->isAdmin()
            ? \App\Models\User::whereIn('id', function ($sub) {
                $sub->select('user_id')->from('company_users');
            })->orderBy('name')->get(['id', 'name'])
            : collect();

        // Mapa user_id → [company_ids] para filtro cascata no frontend:
        // ao selecionar responsável, o dropdown de empresa exibe só as suas empresas.
        $userCompanies = $user->isAdmin()
            ? \DB::table('company_users')
                ->select('user_id', 'company_id')
                ->get()
                ->groupBy('user_id')
                ->map(fn($rows) => $rows->pluck('company_id')->values()->toArray())
            : collect();

        // Contador de pendentes (na visão atual do usuário) — `pendentes()` filtra
        // STATUS_PENDENTE, então `auto_resolvido` naturalmente fica de fora.
        $pendentesQuery = Sugador::pendentes();
        if (!$hasGlobalView) {
            $pendentesQuery->daCarteira($user);
        }
        $totalPendentes = $pendentesQuery->count();

        // Phase 15 — Resumo agregado por empresa para a vista de cards (default).
        // Query única com SUM(CASE WHEN ...) evita N+1 por empresa. Empresas
        // visíveis sem nenhum sugador ainda aparecem com counts zerados via
        // LEFT JOIN lógico em PHP (Collection::map sobre $companies).
        $canAnalyze = Gate::allows('analyze', Sugador::class);
        $visibleIds = $companies->pluck('id')->all();

        if (!empty($visibleIds)) {
            // DATE(reference_date) normaliza valores que vêm como datetime em
            // alguns drivers (SQLite usado nos testes guarda 'YYYY-MM-DD HH:MM:SS'),
            // garantindo match exato com `$hoje` (YYYY-MM-DD).
            $aggregates = Sugador::selectRaw(
                'company_id,
                 SUM(CASE WHEN status = ? AND DATE(reference_date) = ? THEN 1 ELSE 0 END) AS count_hoje,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS total_pendentes,
                 MAX(created_at) AS ultima_analise',
                [Sugador::STATUS_PENDENTE, $hoje, Sugador::STATUS_PENDENTE]
            )
                ->whereIn('company_id', $visibleIds)
                ->groupBy('company_id')
                ->get()
                ->keyBy('company_id');
        } else {
            $aggregates = collect();
        }

        // Bloqueia botão "Reanalisar" no card quando já houve sync com sucesso hoje
        // (Phase 16 SC-6). Análise diária é D-1 da Adman, reanalisar antes do próximo
        // ciclo não traz dados novos — sync falho (`error_message` preenchido) NÃO
        // conta como "rodado", permitindo retry manual.
        if (!empty($visibleIds)) {
            $companiesAnalisadasHoje = \App\Models\AdmanSyncLog::query()
                ->whereIn('company_id', $visibleIds)
                ->whereDate('created_at', today())
                ->whereNull('error_message')
                ->distinct()
                ->pluck('company_id')
                ->flip();
        } else {
            $companiesAnalisadasHoje = collect();
        }

        // LEFT JOIN lógico — toda empresa visível aparece, mesmo sem sugadores.
        $companiesSummary = $companies->map(function ($c) use ($aggregates, $canAnalyze, $companiesAnalisadasHoje) {
            $agg = $aggregates->get($c->id);

            // `ultima_analise` pode vir como string (driver MySQL/SQLite); normaliza pra ISO.
            $ultimaRaw = $agg?->ultima_analise;
            $ultima    = $ultimaRaw ? \Carbon\Carbon::parse($ultimaRaw)->toIso8601String() : null;

            return [
                'company_id'      => (int) $c->id,
                'name'            => (string) $c->name,
                'count_hoje'      => (int) ($agg?->count_hoje ?? 0),
                'total_pendentes' => (int) ($agg?->total_pendentes ?? 0),
                'ultima_analise'  => $ultima,
                'can_analyze'     => $canAnalyze,
                'analisado_hoje'  => $companiesAnalisadasHoje->has($c->id),
                // Phase 19 — alias semântico de `analisado_hoje` (vem de AdmanSyncLog).
                // "sincronizou_hoje" deixa claro no frontend que o dado reflete sync Adman
                // (cron ~11h), não a análise de sugadores em si. Manter compat via alias.
                'sincronizou_hoje' => $companiesAnalisadasHoje->has($c->id),
                // Phase 18 W5-T3 — flag preloaded em $companies->get(['id','name','cust_id_status']);
                // sem N+1 porque ja vem na query unica acima.
                'cust_id_status'  => (string) ($c->cust_id_status ?? 'desconhecido'),
            ];
        })
            // Ordenação: count_hoje DESC, total_pendentes DESC, nome ASC (case-insensitive).
            ->sort(function ($a, $b) {
                if ($a['count_hoje'] !== $b['count_hoje']) {
                    return $b['count_hoje'] <=> $a['count_hoje'];
                }
                if ($a['total_pendentes'] !== $b['total_pendentes']) {
                    return $b['total_pendentes'] <=> $a['total_pendentes'];
                }
                return strcmp(strtolower($a['name']), strtolower($b['name']));
            })
            ->values()
            ->all();

        // `view_mode` controla o default da UI; aceita apenas 'cards' ou 'list'.
        $viewMode = $request->input('view', 'cards');
        if (!in_array($viewMode, ['cards', 'list'], true)) {
            $viewMode = 'cards';
        }

        // Phase 19 — Metadado global para o banner D-1 no topo da página.
        // `ultima_execucao_global`: usa MAX(created_at) dos sugadores como proxy —
        // cada rodada da análise cria novos sugadores com created_at do momento.
        // Null quando nenhum sugador existe ainda no ambiente.
        $ultimaExecucaoRaw = Sugador::query()->max('created_at');
        $analiseDiaria = [
            'horario_cron'            => '12:00 BRT',
            'ultima_execucao_global'  => $ultimaExecucaoRaw
                ? \Carbon\Carbon::parse($ultimaExecucaoRaw)->toIso8601String()
                : null,
        ];

        return Inertia::render('Sugadores/Index', [
            'sugadores'         => $sugadores,
            'companies'         => $companies,
            'companies_summary' => $companiesSummary,
            'view_mode'         => $viewMode,
            'users'             => $users,
            'user_companies'    => $userCompanies,
            'filters'           => $request->only(['company_id', 'status', 'tipo', 'date_from', 'date_to', 'user_id', 'include_resolved']),
            'total_pendentes'   => $totalPendentes,
            'can_manage'        => Gate::allows('manage', Sugador::class),
            'can_analyze'       => $canAnalyze,
            // Phase 19 — Vista default e metadado da análise diária.
            'default_view'      => $defaultView,
            'analise_diaria'    => $analiseDiaria,
        ]);
    }

    public function show(Request $request, Sugador $sugador)
    {
        Gate::authorize('view', $sugador);

        $sugador->load(['company:id,name,adman_account_id', 'resolvidoPor:id,name', 'movidoPor:id,name', 'acoes.user:id,name']);

        return Inertia::render('Sugadores/Show', [
            'sugador'      => $sugador,
            'url_anuncio'  => $sugador->urlAnuncioML(),
            'url_ads'      => $sugador->linkAdsML(),
            'can_update'   => Gate::allows('update', $sugador),
        ]);
    }

    /**
     * Muda o status de um sugador. Cria entrada em sugador_acoes (audit log).
     * Body: { status, acao_tomada?, observacao? }
     */
    public function updateStatus(Request $request, Sugador $sugador)
    {
        Gate::authorize('update', $sugador);

        $data = $request->validate([
            'status'      => 'required|in:pendente,em_acao,resolvido,ignorado',
            'acao_tomada' => 'nullable|in:pausado,removido,reduzido_lance,reativado,outro',
            'observacao'  => 'nullable|string|max:5000',
        ]);

        $statusAnterior = $sugador->status;

        $update = ['status' => $data['status']];

        if ($data['status'] === Sugador::STATUS_RESOLVIDO) {
            $update['acao_tomada']   = $data['acao_tomada'] ?? null;
            $update['resolvido_em']  = now();
            $update['resolvido_por'] = $request->user()->id;
        } elseif ($data['status'] === Sugador::STATUS_PENDENTE) {
            // Se voltou pra pendente, limpa marcas de resolução
            $update['acao_tomada']   = null;
            $update['resolvido_em']  = null;
            $update['resolvido_por'] = null;
        }

        if (array_key_exists('observacao', $data)) {
            $update['observacao'] = $data['observacao'];
        }

        $sugador->update($update);

        // Audit log
        $acaoMap = [
            Sugador::STATUS_EM_ACAO   => SugadorAcao::ACAO_MARCOU_EM_ACAO,
            Sugador::STATUS_RESOLVIDO => SugadorAcao::ACAO_MARCOU_RESOLVIDO,
            Sugador::STATUS_IGNORADO  => SugadorAcao::ACAO_MARCOU_IGNORADO,
            Sugador::STATUS_PENDENTE  => SugadorAcao::ACAO_VOLTOU_PENDENTE,
        ];

        SugadorAcao::create([
            'sugador_id'      => $sugador->id,
            'user_id'         => $request->user()->id,
            'acao'            => $acaoMap[$data['status']],
            'status_anterior' => $statusAnterior,
            'status_novo'     => $data['status'],
            'observacao'      => $data['observacao'] ?? null,
            'created_at'      => now(),
        ]);

        return back()->with('success', 'Status atualizado.');
    }

    /**
     * Enfileira análise on-demand de uma empresa específica.
     *
     * Roda em background via AnalyzeCompanySugadoresJob — contas grandes
     * (2000+ adgroups) demoram minutos e estouravam o timeout do nginx/php-fpm.
     */
    public function analyzeCompany(Request $request, Company $company)
    {
        Gate::authorize('manage', Sugador::class);

        // Phase 42 D-05: aceita empresas ML-driven (mlToken active) sem adman_account_id.
        // ByMobille - Teste (#298) é a primeira empresa ML-only — destravada neste cut-over.
        // Mantemos rejeição apenas para empresas sem nenhum provider compatível.
        $company->loadMissing('mlToken');
        $hasAdman = !empty($company->adman_account_id);
        $hasMl    = optional($company->mlToken)->status === 'active';
        if (!$hasAdman && !$hasMl) {
            return back()->with(
                'warning',
                "Empresa {$company->name} sem adman_account_id nem mlToken ativo — análise pulada."
            );
        }

        // Phase 42 polish — botao individual da UI usa queue 'high' pra furar
        // a fila do scheduler diario (analyzeAll enfileira ~140 jobs na queue
        // 'default'). Operador que altera config e clica em "Rodar analise"
        // ve resultado em segundos em vez de esperar 80min na fila.
        // Supervisor: --queue=high,default (processa high primeiro).
        //
        // Locks orfaos do ShouldBeUnique: jobs antigos enfileirados antes do
        // uniqueFor=1800 ficavam SEM TTL no Redis. Dispatch posterior era
        // silenciosamente ignorado pelo Laravel (lock parecia ativo). Fix:
        // sempre limpar a chave de lock dessa empresa ANTES de dispatch. Se
        // ja havia job legitimo na fila, ele continua sendo processado — so
        // o lock e que e descartado. uniqueFor=1800 do Job garante que o
        // novo lock criado pelo dispatch tem TTL valido.
        $lockKey = 'laravel_unique_job:' . AnalyzeCompanySugadoresJob::class . $company->id;
        \Illuminate\Support\Facades\Cache::forget($lockKey);

        AnalyzeCompanySugadoresJob::dispatch($company)->onQueue('high');

        return back()->with(
            'success',
            "Análise enfileirada (prioritária) para {$company->name}. Sugadores aparecem em ~30s."
        );
    }

    /**
     * Enfileira análise on-demand de TODAS as empresas com config ativa.
     * Permitido a admin + analista/gestor/lider (Policy::analyze).
     *
     * Fan-out: 1 job por empresa — assim contas pequenas não esperam as grandes,
     * e o supervisor (2 workers) processa em paralelo.
     */
    public function analyzeAll()
    {
        Gate::authorize('analyze', Sugador::class);

        // Phase 42 D-05: inclui empresas ML-driven (mlToken active) além de Adman.
        // Cobre ByMobille (#298, sem adman_account_id) e empresas onboardadas via ML
        // futuramente. Auto-detection do factory escolhe o provider correto por empresa.
        $companies = Company::where('active', true)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('adman_account_id')->where('adman_account_id', '!=', '');
                })->orWhereHas('mlToken', fn($q2) => $q2->where('status', 'active'));
            })
            ->where(function ($q) {
                $q->whereHas('sugadorConfig', fn($q) => $q->where('ativo', true))
                  ->orWhereDoesntHave('sugadorConfig');
            })
            ->get();

        foreach ($companies as $company) {
            AnalyzeCompanySugadoresJob::dispatch($company);
        }

        return back()->with(
            'success',
            "Análise enfileirada para {$companies->count()} empresa(s). Os resultados aparecem na listagem conforme cada empresa termina."
        );
    }

    /**
     * Lista as campanhas-destino candidatas para a ação "Mover sugador" de uma
     * empresa específica — filtra por nome SGI/Sugador/Sugadores e status pausada
     * (convenção do time). Carregado on-demand via fetch() do modal.
     *
     * Cache de 10min por (custId) — listagem de campanhas muda raramente; libera
     * latência do TLS handshake da Adman entre clicks consecutivos.
     */
    public function sgiCampaigns(Request $request, Company $company)
    {
        // Mesma regra de view: admin/gestor/lider veem qualquer empresa; demais
        // só as da carteira. Sem isso um analista poderia listar campanhas de
        // empresa que não tem acesso.
        $user = $request->user();
        $isGlobal = $user->isAdmin()
            || (method_exists($user, 'isGestor') && $user->isGestor())
            || (method_exists($user, 'isLiderPub') && $user->isLiderPub());

        if (!$isGlobal) {
            $inCarteira = $user->companies()->where('companies.id', $company->id)->exists();
            if (!$inCarteira) {
                abort(403, 'Sem acesso a esta empresa.');
            }
        }

        if (!$company->adman_account_id) {
            return response()->json([
                'campaigns' => [],
                'reason'    => 'Empresa sem adman_account_id — não dá pra listar campanhas.',
            ], 422);
        }

        $cacheKey = "sugadores:sgi_campaigns:{$company->adman_account_id}";

        try {
            $campaigns = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($company) {
                return $this->adman->fetchSugadorCampaigns($company->adman_account_id);
            });
        } catch (\Throwable $e) {
            Log::warning("[Sugadores/SGI] Falha listando campanhas company={$company->id}: " . $e->getMessage());
            return response()->json([
                'campaigns' => [],
                'reason'    => 'Falha ao consultar API da Adman: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'campaigns' => $campaigns,
            'company'   => ['id' => $company->id, 'name' => $company->name],
        ]);
    }

    /**
     * Marca um sugador como "movido para campanha SGI X". Não chama API do ML —
     * apenas registra a decisão do analista (o move físico é feito por ele no
     * painel do ML). Status vira 'movido' (entra em STATUS_TRAVADOS).
     */
    public function move(Request $request, Sugador $sugador)
    {
        Gate::authorize('update', $sugador);

        $data = $request->validate([
            'campanha_destino_id'   => 'required|string|max:64',
            'campanha_destino_nome' => 'required|string|max:255',
            'observacao'            => 'nullable|string|max:5000',
        ]);

        $statusAnterior = $sugador->status;

        $sugador->update([
            'status'                => Sugador::STATUS_MOVIDO,
            'campanha_destino_id'   => $data['campanha_destino_id'],
            'campanha_destino_nome' => $data['campanha_destino_nome'],
            'movido_em'             => now(),
            'movido_por_id'         => $request->user()->id,
            'observacao'            => $data['observacao'] ?? $sugador->observacao,
        ]);

        SugadorAcao::create([
            'sugador_id'      => $sugador->id,
            'user_id'         => $request->user()->id,
            'acao'            => SugadorAcao::ACAO_MOVEU,
            'status_anterior' => $statusAnterior,
            'status_novo'     => Sugador::STATUS_MOVIDO,
            'observacao'      => "Movido para campanha: {$data['campanha_destino_nome']}"
                                 . (!empty($data['observacao']) ? " — {$data['observacao']}" : ''),
            'created_at'      => now(),
        ]);

        return back()->with('success', "Marcado como movido para {$data['campanha_destino_nome']}.");
    }

    /**
     * Versão bulk do move — aceita N sugadores e move todos para a mesma campanha
     * destino. Constraint: todos os sugadores devem ser da MESMA empresa (UI já
     * impõe, mas validamos aqui também). Loop autoriza cada um via Policy.
     */
    public function bulkMove(Request $request)
    {
        $data = $request->validate([
            'sugador_ids'             => 'required|array|min:1|max:500',
            'sugador_ids.*'           => 'integer',
            'campanha_destino_id'     => 'required|string|max:64',
            'campanha_destino_nome'   => 'required|string|max:255',
        ]);

        $sugadores = Sugador::whereIn('id', $data['sugador_ids'])->get();

        if ($sugadores->isEmpty()) {
            return back()->with('warning', 'Nenhum sugador encontrado para mover.');
        }

        // Constraint: todos devem ser da mesma empresa (UI impõe via filtro, mas
        // validamos aqui pra evitar inconsistência via API direta).
        $companyIds = $sugadores->pluck('company_id')->unique();
        if ($companyIds->count() > 1) {
            return back()->withErrors([
                'sugador_ids' => 'Todos os sugadores selecionados devem ser da mesma empresa.',
            ]);
        }

        // Authorize cada sugador — Policy::update já checa carteira.
        foreach ($sugadores as $s) {
            Gate::authorize('update', $s);
        }

        $now    = now();
        $userId = $request->user()->id;
        $moved  = 0;

        DB::transaction(function () use ($sugadores, $data, $now, $userId, &$moved) {
            $auditRows = [];

            foreach ($sugadores as $s) {
                $statusAnterior = $s->status;

                $s->update([
                    'status'                => Sugador::STATUS_MOVIDO,
                    'campanha_destino_id'   => $data['campanha_destino_id'],
                    'campanha_destino_nome' => $data['campanha_destino_nome'],
                    'movido_em'             => $now,
                    'movido_por_id'         => $userId,
                ]);

                $auditRows[] = [
                    'sugador_id'      => $s->id,
                    'user_id'         => $userId,
                    'acao'            => SugadorAcao::ACAO_MOVEU,
                    'status_anterior' => $statusAnterior,
                    'status_novo'     => Sugador::STATUS_MOVIDO,
                    'observacao'      => "Movido em lote para campanha: {$data['campanha_destino_nome']}",
                    'created_at'      => $now,
                ];
                $moved++;
            }

            // Insert único do audit — N rows em 1 query
            if (!empty($auditRows)) {
                SugadorAcao::insert($auditRows);
            }
        });

        return back()->with('success', "{$moved} sugador(es) marcados como movidos para {$data['campanha_destino_nome']}.");
    }

    /**
     * Drilldown: lista os MLBs (productAds) da campanha de um adgroup-sugador no
     * período analisado. Resolve a limitação histórica de que a Adman REST não
     * expõe os MLBs dentro de um adgroup — a MCP retorna métricas MLB-level
     * confiáveis (ads vs orgânico, direto vs indireto).
     *
     * Como o productAd não traz adgroupId, filtramos pelos da mesma campanha do
     * sugador e, quando dá, marcamos os "provavelmente neste adgroup" via
     * matching de título (nome do adgroup costuma ser o título do produto-base).
     */
    /**
     * Phase 30 Plan 30-04 — Lê MLBs do adgroup INSTANTANEAMENTE do banco local
     * (`adman_adgroup_mlbs`), populado pelo sync agendado 03h BRT ou pelo botão
     * "Forçar atualização".
     *
     * Substitui o pattern legacy que varria a Adman MCP em tempo de request
     * (5-25min em contas grandes). Caminho legacy fica em mlbs_legacy() pra
     * rollback caso necessário.
     */
    public function mlbs(Sugador $sugador, \App\Services\AdmanAdgroupMlbsRepository $repo)
    {
        Gate::authorize('view', $sugador);

        if ($sugador->tipo !== Sugador::TIPO_ADGROUP) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'O drilldown de MLBs só está disponível para sugadores do tipo adgroup.',
            ], 422);
        }

        $sugador->loadMissing('company:id,adman_account_id,name');
        $custId = $sugador->company?->adman_account_id;

        if (!$custId) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'Empresa sem adman_account_id. Path ML direto será implementado no Plan 30-02.',
            ], 422);
        }

        // Status do sync mais recente — UI exibe "Atualizado em HH:MM" + is_fresh
        $lastSync = $repo->lastSyncForCompany($custId);
        $isFresh  = $lastSync !== null && $lastSync->gt(now()->subHours(30));

        if ($lastSync === null) {
            return response()->json([
                'mlbs'           => [],
                'adgroup_name'   => $sugador->adgroup_name,
                'campaign_id'    => $sugador->campaign_id,
                'total'          => 0,
                'last_synced_at' => null,
                'is_fresh'       => false,
                'empty_state'    => 'never_synced',
            ]);
        }

        // Phase 30 Plan 30-04 fix smoke W4 — Adman MCP getMarketplaceadsCustIdproductAdsmetrics
        // NÃO retorna adgroupName nem campaignId, só campaignName. Pra correlacionar
        // sugador (que tem campaign_id hash) com MLBs no banco (que têm só campaign_name),
        // resolvemos via listCampaigns(custId) — cached 1h, custo baixo após 1ª chamada.
        try {
            $campaigns    = $this->mcp->listCampaigns($custId);
            $campaign     = collect($campaigns)->firstWhere('campaign_id', (string) $sugador->campaign_id);
            $campaignName = $campaign['campaign_name'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("[Sugadores/MLBs] listCampaigns falhou cust={$custId}: " . $e->getMessage());
            $campaignName = null;
        }

        if (!$campaignName) {
            return response()->json([
                'mlbs'           => [],
                'adgroup_name'   => $sugador->adgroup_name,
                'campaign_id'    => $sugador->campaign_id,
                'total'          => 0,
                'last_synced_at' => $lastSync->toIso8601String(),
                'is_fresh'       => $isFresh,
                'empty_state'    => 'campaign_not_found',
                'reason'         => 'Não foi possível resolver o nome da campanha desta sugador.',
            ]);
        }

        // Busca TODOS os MLBs da campanha no banco local
        $rows = $repo->findByCampaign($custId, $campaignName);

        if ($rows->isEmpty()) {
            return response()->json([
                'mlbs'           => [],
                'adgroup_name'   => $sugador->adgroup_name,
                'campaign_id'    => $sugador->campaign_id,
                'campaign_name'  => $campaignName,
                'total'          => 0,
                'last_synced_at' => $lastSync->toIso8601String(),
                'is_fresh'       => $isFresh,
                'empty_state'    => 'synced_no_mlbs',
            ]);
        }

        // Aplica heurística matches_adgroup pelo title (replica pattern legacy):
        // adgroup_name no ML quase sempre é o título do produto base. Pra ITEM
        // bate exato; pra FAMILY bate por prefixo entre as variações.
        $adgroupName = (string) ($sugador->adgroup_name ?? '');
        $needle      = $this->normalizeForMatch($adgroupName);

        $mlbs = $rows->map(function ($row) use ($needle) {
            $metrics = is_array($row->metrics) ? $row->metrics : [];
            $title   = $this->normalizeForMatch((string) ($row->title ?? ''));
            $matches = $needle !== '' && $title !== '' && $this->similarPrefix($needle, $title);

            return [
                'mlb_id'          => $row->mlb_id,
                'title'           => $row->title,
                'status'          => $row->status,
                'campaign_name'   => $row->campaign_name,
                'impressions'     => $metrics['impressions']  ?? null,
                'clicks'          => $metrics['clicks']       ?? null,
                'conversions'     => $metrics['conversions']  ?? null,
                'total_amount'    => $metrics['totalAmount']  ?? null,
                'ads_revenue'     => $metrics['adsRevenue']   ?? null,
                'acos'            => $metrics['acos']         ?? null,
                'matches_adgroup' => $matches,
            ];
        })->all();

        return response()->json([
            'mlbs'           => $mlbs,
            'adgroup_name'   => $sugador->adgroup_name,
            'campaign_id'    => $sugador->campaign_id,
            'campaign_name'  => $campaignName,
            'total'          => count($mlbs),
            'last_synced_at' => $lastSync->toIso8601String(),
            'is_fresh'       => $isFresh,
            'empty_state'    => null,
        ]);
    }

    /**
     * Phase 30 Plan 30-04 — Endpoint do botão "Forçar atualização".
     * Dispara SyncCompanyAdgroupMlbsJob pra UMA empresa, retorna imediatamente.
     * UI polla o status via mesma rota mlbs() (last_synced_at vai atualizar).
     */
    public function refreshAdgroupMlbs(Request $request)
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        $company = Company::findOrFail($request->integer('company_id'));

        // Autorização: admin/consultor global vê tudo; demais só carteira
        $user     = $request->user();
        $isGlobal = $user->isAdmin()
            || (method_exists($user, 'isGestor') && $user->isGestor())
            || (method_exists($user, 'isLiderPub') && $user->isLiderPub());

        if (!$isGlobal && !$user->companies()->where('companies.id', $company->id)->exists()) {
            return response()->json(['message' => 'Sem acesso a esta empresa.'], 403);
        }

        if (empty($company->adman_account_id)) {
            return response()->json([
                'message' => 'Empresa sem adman_account_id. Refresh disponível apenas para empresas Adman.',
            ], 422);
        }

        $periodTo   = now()->toDateString();
        $periodFrom = now()->subDays(30)->toDateString();

        \App\Jobs\SyncCompanyAdgroupMlbsJob::dispatch($company, $periodFrom, $periodTo);

        return response()->json([
            'status'  => 'enqueued',
            'message' => 'Sincronização iniciada em background. Recarregue o drilldown em ~5min.',
            'company_id' => $company->id,
        ]);
    }

    /**
     * Retorna lista consolidada de MLBs (productAds) de todos os sugadores do tipo
     * adgroup pendentes HOJE de uma empresa específica.
     *
     * Reusa o Cache::lock por custId introduzido no W1-T3: os N adgroups da mesma
     * conta rodam serialmente DENTRO de um único lock. O 1º adgroup paga o custo
     * MCP (~15s TLS + paginação); os demais leem do cache compartilhado de 30min
     * (mesmo dateFrom/dateTo = mesmo cache key) — custo amortizado.
     *
     * Limite: 20 adgroups por request para evitar HTTP timeout. Se count > 20,
     * processa os 20 mais recentes e retorna truncated=true — UI mostra aviso.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mlbsByCompany(Request $request, Company $company)
    {
        // Autorização: mesma regra do drilldown — admin/gestor/lider veem tudo;
        // demais só carteira. Replica padrão de sgiCampaigns().
        $user     = $request->user();
        $isGlobal = $user->isAdmin()
            || (method_exists($user, 'isGestor') && $user->isGestor())
            || (method_exists($user, 'isLiderPub') && $user->isLiderPub());

        if (!$isGlobal && !$user->companies()->where('companies.id', $company->id)->exists()) {
            abort(403, 'Sem acesso a esta empresa.');
        }

        if (!$company->adman_account_id) {
            return response()->json([
                'mlbs'                  => [],
                'total_mlbs'            => 0,
                'sugadores_processados' => 0,
                'sugadores_solicitados' => 0,
                'truncated'             => false,
                'reason'                => 'Empresa sem adman_account_id.',
            ], 422);
        }

        if (!$this->mcp->isConfigured()) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'MCP da Adman não configurada.',
            ], 503);
        }

        $hoje     = now()->toDateString();
        $adgroups = Sugador::where('company_id', $company->id)
            ->where('tipo', Sugador::TIPO_ADGROUP)
            ->where('status', Sugador::STATUS_PENDENTE)
            ->whereDate('reference_date', $hoje)
            ->orderByDesc('created_at')
            ->get(['id', 'campaign_id', 'periodo_inicio', 'periodo_fim']);

        $solicitados = $adgroups->count();
        $LIMITE      = 20; // evita request gigante; UI mostra "20 de 47 sugadores"
        $alvos       = $adgroups->take($LIMITE);
        $truncated   = $solicitados > $LIMITE;

        // MCP da Adman tem TLS handshake lento (~15s/chamada) — sem isso atinge
        // max_execution_time antes de completar. Mesmo justificativa do mlbs().
        @set_time_limit(0);

        $mlbsSet     = [];
        $processados = 0;
        $falhas      = 0;
        $ultimoErro  = null;

        foreach ($alvos as $s) {
            $dateFrom = optional($s->periodo_inicio)->toDateString() ?? now()->subDays(7)->toDateString();
            $dateTo   = optional($s->periodo_fim)->toDateString()    ?? now()->subDay()->toDateString();

            try {
                $res = $this->mcp->fetchMlbsByCampaign(
                    $company->adman_account_id,
                    (string) $s->campaign_id,
                    $dateFrom,
                    $dateTo,
                );
                foreach ($res['mlbs'] ?? [] as $m) {
                    $id = $m['listing_id'] ?? null;
                    if ($id) $mlbsSet[$id] = true;
                }
                $processados++;
            } catch (\Throwable $e) {
                $falhas++;
                $ultimoErro = $e->getMessage();
                Log::warning("[Sugadores/MlbsByCompany] sugador {$s->id} falhou: " . $e->getMessage());
                // Continua com os outros — falha de 1 não interrompe o lote.
            }
        }

        // Se TODOS os sugadores falharam, propaga 502 com mensagem clara em vez
        // de retornar mlbs:[] que o frontend interpretaria como "Sem MLBs" (bug
        // de UX reportado pelo usuário 2026-06-03: 429 silenciado mostrava
        // "Sem MLBs" quando na verdade era falha temporária da Adman).
        if ($processados === 0 && $solicitados > 0) {
            $rateLimit = $ultimoErro !== null && str_contains($ultimoErro, '429');
            $msg = $rateLimit
                ? 'Falha temporária da Adman (rate limit). Tente em ~1 minuto.'
                : 'Falha ao consultar MCP: ' . ($ultimoErro ?? 'erro desconhecido');

            return response()->json([
                'mlbs'                  => [],
                'total_mlbs'            => 0,
                'sugadores_processados' => 0,
                'sugadores_solicitados' => $solicitados,
                'falhas'                => $falhas,
                'truncated'             => $truncated,
                'reason'                => $msg,
            ], 502);
        }

        $mlbs = array_keys($mlbsSet);
        sort($mlbs);

        return response()->json([
            'mlbs'                  => $mlbs,
            'total_mlbs'            => count($mlbs),
            'sugadores_processados' => $processados,
            'sugadores_solicitados' => $solicitados,
            'falhas'                => $falhas,
            'truncated'             => $truncated,
        ]);
    }

    private function normalizeForMatch(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    /** True se os primeiros ~3 tokens em comum batem — adgroup costuma compartilhar o início do título. */
    private function similarPrefix(string $a, string $b): bool
    {
        $ta = array_slice(explode(' ', $a), 0, 4);
        $tb = array_slice(explode(' ', $b), 0, 4);
        if (count($ta) === 0 || count($tb) === 0) return false;

        $common = array_intersect($ta, $tb);
        return count($common) >= min(3, count($ta));
    }
}
