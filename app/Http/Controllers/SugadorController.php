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

        $sugadores = $query->paginate(50)->withQueryString();

        // Empresas para o filtro: globais ou apenas da carteira
        $companiesQuery = Company::where('active', true)->orderBy('name');
        if (!$hasGlobalView) {
            $companiesQuery->whereIn('id', $user->companies()->pluck('companies.id'));
        }
        $companies = $companiesQuery->get(['id', 'name']);

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

        // Contador de pendentes (na visão atual do usuário)
        $pendentesQuery = Sugador::pendentes();
        if (!$hasGlobalView) {
            $pendentesQuery->daCarteira($user);
        }
        $totalPendentes = $pendentesQuery->count();

        return Inertia::render('Sugadores/Index', [
            'sugadores'       => $sugadores,
            'companies'       => $companies,
            'users'           => $users,
            'user_companies'  => $userCompanies,
            'filters'         => $request->only(['company_id', 'status', 'tipo', 'date_from', 'date_to', 'user_id', 'include_resolved']),
            'total_pendentes' => $totalPendentes,
            'can_manage'      => Gate::allows('manage', Sugador::class),
            'can_analyze'     => Gate::allows('analyze', Sugador::class),
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

        if (!$company->adman_account_id) {
            return back()->with('warning', 'Empresa sem adman_account_id — análise pulada.');
        }

        AnalyzeCompanySugadoresJob::dispatch($company);

        return back()->with(
            'success',
            "Análise enfileirada para {$company->name}. Os sugadores aparecem na listagem em alguns minutos."
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

        $companies = Company::where('active', true)
            ->whereNotNull('adman_account_id')
            ->where('adman_account_id', '!=', '')
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
    public function mlbs(Sugador $sugador)
    {
        Gate::authorize('view', $sugador);

        if ($sugador->tipo !== Sugador::TIPO_ADGROUP) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'O drilldown de MLBs só está disponível para sugadores do tipo adgroup.',
            ], 422);
        }

        if (!$this->mcp->isConfigured()) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'API MCP da Adman não está configurada no servidor.',
            ], 503);
        }

        // MCP da Adman tem TLS handshake lento (~15s/chamada) e contas grandes
        // chegam a 50+ páginas — sem isso atinge max_execution_time (default 30s)
        // antes do cache persistir. Resultado fica cacheado 30min depois.
        @set_time_limit(0);

        $sugador->loadMissing('company:id,adman_account_id,name');
        $custId = $sugador->company?->adman_account_id;

        if (!$custId) {
            return response()->json([
                'mlbs'   => [],
                'reason' => 'Empresa sem adman_account_id.',
            ], 422);
        }

        // O sugador guarda o range analisado em periodo_inicio/periodo_fim — usar
        // o mesmo range mantém os números comparáveis com o card do sugador.
        $dateFrom = optional($sugador->periodo_inicio)->toDateString() ?? now()->subDays(7)->toDateString();
        $dateTo   = optional($sugador->periodo_fim)->toDateString()    ?? now()->subDay()->toDateString();

        // Tenta 1º o cache do FULL-SCAN (1000 páginas). Se ele estiver pronto,
        // retorna resultado completo. Se não, cai pra varredura síncrona de
        // 16 páginas e dispara Job em background pra preencher o full-scan.
        $fullScanResult = $this->mcp->cachedFullScanIfReady($custId, $dateFrom, $dateTo);

        try {
            if ($fullScanResult !== null) {
                $result = $this->mcp->filterMlbsByCampaignFromItems(
                    $fullScanResult['items'],
                    (string) $sugador->campaign_id,
                    $custId,
                );
                $result['scan_full_ready'] = true;
            } else {
                $result = $this->mcp->fetchMlbsByCampaign($custId, (string) $sugador->campaign_id, $dateFrom, $dateTo);
                $result['scan_full_ready'] = false;

                // Resultado síncrono é truncado pra contas grandes — dispara
                // varredura completa em background. O Job é ShouldBeUnique e
                // não enfileira duplicata. Próximo clique em "Recarregar" após
                // ~5min pega o cache full.
                if (!empty($result['truncated'])) {
                    FetchAdmanMlbsByCampaignJob::dispatch($custId, $dateFrom, $dateTo);
                }
            }
        } catch (\Throwable $e) {
            Log::error("[Sugadores/MLBs] Erro MCP sugador {$sugador->id} (company {$sugador->company_id}): " . $e->getMessage());
            return response()->json([
                'mlbs'   => [],
                'reason' => 'Falha ao consultar a API MCP da Adman: ' . $e->getMessage(),
            ], 502);
        }

        $mlbs = $result['mlbs'];

        // Heurística "provavelmente neste adgroup": adgroup name no ML quase sempre
        // é o título do produto base. Pra ITEM bate exato; pra FAMILY bate por
        // prefixo entre as variações. Marca como dica, não como filtro hard.
        $adgroupName = (string) ($sugador->adgroup_name ?? '');
        $needle      = $this->normalizeForMatch($adgroupName);

        foreach ($mlbs as &$m) {
            $title = $this->normalizeForMatch((string) ($m['title'] ?? ''));
            $m['matches_adgroup'] = $needle !== '' && $title !== '' && $this->similarPrefix($needle, $title);
        }
        unset($m);

        // Status do scan em background (se houver) — frontend usa pra mostrar
        // banner "varredura completa em andamento".
        $scanStatus = Cache::get(
            FetchAdmanMlbsByCampaignJob::statusCacheKeyFor($custId, $dateFrom, $dateTo)
        );

        return response()->json([
            'mlbs'            => $mlbs,
            'periodo_inicio'  => $dateFrom,
            'periodo_fim'     => $dateTo,
            'adgroup_name'    => $sugador->adgroup_name,
            'campaign_id'     => $sugador->campaign_id,
            'total'           => count($mlbs),
            'truncated'       => $result['truncated']        ?? false,
            'pages_read'      => $result['pages_read']       ?? null,
            'total_pages'     => $result['total_pages']      ?? null,
            'scan_full_ready' => $result['scan_full_ready']  ?? false,
            'scan_status'     => $scanStatus,
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
