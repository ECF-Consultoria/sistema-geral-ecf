<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\Ppa;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Desempenho\NpsSemLinkService;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsPendingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Colunas de `adman_metrics` carregadas pelos dashboards — TODAS menos
     * `raw_data` (longtext com o payload cru da Adman, ~71 KB/linha, cast
     * `array`). O `raw_data` NÃO é lido em nenhum ponto do dashboard, mas o
     * `->get()` sem projeção hidratava ~222 MB só desse campo por janela de
     * 30d (~3k linhas) e, somado à janela anterior + overhead do Eloquent,
     * estourava o `memory_limit` de 512 MB do PHP-FPM (500 Server Error).
     * Projetar as colunas escalares mantém o comportamento idêntico com uma
     * fração da memória. Hotfix 2026-07-27.
     */
    private const ADMAN_METRIC_COLS = [
        'id', 'company_id', 'reference_date', 'tacos', 'revenue', 'net_billing',
        'sales_fee', 'taxes', 'shipping_cost', 'product_cost', 'return_cost',
        'profit_share', 'sold_quantity', 'ad_spend', 'contribution_margin',
        'contribution_margin_pct', 'products_total', 'products_without_cost',
        'revenue_prev_period', 'synced_at', 'created_at', 'updated_at',
    ];

    public function __construct(
        private AdmanService $adman,
        private MetricsProviderFactory $metricsFactory,
        // Phase 72 Plan 02 — SC#3: fonte unica de "empresas pendentes de NPS"
        // reusada por adminDashboard + userDashboard (widget Plan 72-03).
        private NpsPendingService $npsPending,
        // Fase 116 Plan 05 — NPS efetivamente disparado e não respondido conta
        // nota 1 (D2/D3/D7). Única fonte de leitura para os 3 widgets de NPS
        // deste controller (adminDashboard, buildRanking, userDashboard).
        private NpsImputationService $npsImputation,
        // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no mês
        // também conta nota 1, mesma régua do bônus. Injetado por CONSTRUTOR
        // (nunca `app()` dentro do laço de `buildRanking()`) porque
        // `NpsElegibilidadeService::empresasElegiveis()` memoiza por mês NA
        // INSTÂNCIA (T-119.1-41) — só a instância única do request preserva
        // o memo entre as N pessoas do ranking. `app()` dentro do laço
        // resolveria uma instância NOVA a cada pessoa e desarmaria o memo,
        // repetindo o precedente de dashboard de 70s já registrado no projeto.
        private NpsSemLinkService $npsSemLink,
    ) {}

    /**
     * Phase 61 Plan 61-01 — Feature flag ADR DATA-04 (default false).
     *
     * Leitura via `config()` (nunca `env()` em runtime — Laravel invalida
     * `env()` fora de config files quando `config:cache` está ativo em prod).
     */
    private function unifiedMetricsEnabled(): bool
    {
        return (bool) config('metrics.unified_metrics_enabled');
    }

    /**
     * Phase 61 Plan 61-01 — Mapa canônico caseFor → valor de `source` no
     * payload Inertia (ADR DATA-04 seção "Vocabulário do campo `source`").
     *
     *   'ambos'    → 'unified'
     *   'so-ml'    → 'ml'
     *   'so-adman' → 'adman'
     *   'none'     → 'none'
     *
     * Chamada barata: `caseFor()` só olha accessors denormalizados
     * (`is_ml_driven` via `mlToken` + `adman_account_id`) — não faz HTTP.
     * Duplicação intencional com `PortfolioController::factoryToSource`
     * (extração pra trait fica pra v14.x se justificar — Task 3 do plan).
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

    public function index(Request $request)
    {
        $user = $request->user();

        // Equipe de publicação (não-admin): redireciona para a primeira página acessível
        if ($user->publication_role && !$user->isAdmin()) {
            $destinations = [
                'dashboard'   => route('mlb.dashboard'),
                'meu_painel'  => route('mlb.meu-painel'),
                'treinamento' => route('mlb.treinamentos'),
                'publicacoes' => route('mlb.publicacoes'),
                'historico'   => route('mlb.historico'),
                'empresas'    => route('mlb.empresas'),
            ];
            foreach ($destinations as $perm => $url) {
                if ($user->hasPubPermission($perm)) {
                    return redirect($url);
                }
            }
        }

        // Membro do setor Polos (não-admin): landing direto no Painel Polos — a
        // equipe de Polos opera na seção Polos, não no dashboard de carteira/
        // consultoria. Independe do cargo (permissão é concedida ao setor).
        if (! $user->isAdmin() && $user->setores()->where('slug', 'polos')->exists()) {
            return redirect()->route('mlb.polos-painel');
        }

        $period = $request->get('period', '30');
        $since  = $this->getSince($period);

        if ($user->isAdmin()) {
            return $this->adminDashboard($request, $since, $period);
        }

        // Quick 260623 — Lider de setor (Performance) tambem ve adminDashboard.
        // A permission core.dashboard ja foi concedida via AUTO_LIDERANCA_PERFORMANCE,
        // mas o layout do dashboard precisa ser o consolidado (admin), nao o
        // userDashboard de carteira propria.
        if ($user->isLider() && $user->hasPermission('core.dashboard')) {
            return $this->adminDashboard($request, $since, $period);
        }

        // Ajuste UAT 2026-07-07: Analista (consultor) e Estrategista (mentor)
        // recebem o dashboard operacional da carteira na landing após login
        // (mesma tela do /dashboard/mercadolivre) — antes caía no userDashboard
        // legacy que não fazia sentido pro fluxo novo.
        if (! $user->isLider() && ($user->isConsultor() || $user->isMentor())) {
            return app(\App\Http\Controllers\PerformanceController::class)
                ->dashboardCarteira($request);
        }

        return $this->userDashboard($user, $since, $period);
    }

    /**
     * Phase 58 DASH-01 — Dashboard ECF agregado atraves de marketplaces.
     * Ajuste pos-UAT: renderiza EcfShell (em construcao) direto, bypass do
     * pipeline. Isto (a) diferencia active-state no sidebar do /dashboard/
     * mercadolivre e (b) comunica claramente que a agregacao real cross-
     * marketplace esta reservada pra v14+ quando Shopee/Amazon integrarem
     * (hoje 0 empresas com 2+ marketplaces — CONTEXT §2 + Deferred).
     * Whitelist mantida no request pra rejeitar `?marketplace=invalido`.
     */
    public function ecf(Request $request)
    {
        $request->validate(['marketplace' => 'nullable|string|in:meli,shopee,amazon']);

        return Inertia::render('Dashboard/EcfShell');
    }

    /**
     * Phase 58 DASH-02 — Dashboard Mercado Livre. Branching por role:
     * - Analista (consultor) ou Estrategista (mentor) e NÃO líder → dashboard
     *   operacional da carteira (Performance/Dashboard) via delegação
     *   pra PerformanceController::dashboardCarteira().
     * - Admin, líder de Performance ou demais → dashboard admin tradicional
     *   com filter=meli (comportamento anterior).
     *
     * Ajuste UAT 2026-07-06: separa a experiência de carteira do resumo admin.
     */
    public function mercadolivre(Request $request)
    {
        $user = $request->user();

        $isCarteiraUser = ! $user->isAdmin()
            && ! $user->isLider()
            && ($user->isConsultor() || $user->isMentor());

        if ($isCarteiraUser) {
            return app(\App\Http\Controllers\PerformanceController::class)
                ->dashboardCarteira($request);
        }

        $request->merge(['marketplace' => 'meli']);

        $request->validate(['marketplace' => 'nullable|string|in:meli,shopee,amazon']);

        return $this->index($request);
    }

    /**
     * Dashboard Shopee — dados EXCLUSIVAMENTE da Shopee (isolado do ML).
     * Lê shopee_metrics (alimentada por `shopee:sync`) só das empresas com token
     * Shopee ativo, agrega por período (seletor via ?from=&to=; padrão mês atual)
     * e devolve KPIs + série diária + quebra por loja.
     */
    public function shopee(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        // Universo isolado: empresas com Shopee conectada (token ativo).
        $lojas = Company::whereHas('shopeeToken', fn ($q) => $q->where('status', 'active'))
            ->orderBy('name')
            ->get(['id', 'name']);
        $lojaIds = $lojas->pluck('id');

        // Filtro por empresa (opcional). Só aceita loja dentro do universo conectado;
        // valor inválido ou vazio cai para "todas as lojas".
        $companyFilter = $request->filled('company_id') && $lojaIds->contains((int) $request->input('company_id'))
            ? (int) $request->input('company_id')
            : null;

        // IDs efetivos das métricas: todas as conectadas OU só a loja selecionada.
        $companyIds = $companyFilter ? collect([$companyFilter]) : $lojaIds;

        // Limites de dados disponíveis (para o seletor saber o alcance) —
        // sempre sobre o universo conectado, para o empty state grande só
        // aparecer quando NADA foi sincronizado (filtro por loja sem dados
        // cai no estado "sem vendas nesse período").
        $bounds = ShopeeMetric::whereIn('company_id', $lojaIds)
            ->selectRaw('MIN(reference_date) as min_date, MAX(reference_date) as max_date')
            ->first();

        // Período selecionado — padrão: mês atual.
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : now()->endOfDay();

        $metrics = ShopeeMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $revenue = round((float) $metrics->sum('revenue'), 2);
        $orders  = (int) $metrics->sum('orders_count');
        $items   = (int) $metrics->sum('sold_quantity');

        // ─── Ads (colunas ad_*) — mesma linha do faturamento ───────────────
        // ad_expense NULL = dia sem Ads sincronizado (fora do lookback de 6 meses
        // da Shopee). `ads_disponivel` liga/desliga o fallback "— indisponível" na UI.
        $adsDisponivel = $metrics->contains(fn ($m) => $m->ad_expense !== null);
        $adSpend = round((float) $metrics->sum('ad_expense'), 2);
        $tacos   = $revenue > 0 ? round($adSpend / $revenue, 4) : null;

        // Série diária (gráfico).
        $series = $metrics
            ->groupBy(fn ($m) => $m->reference_date->toDateString())
            ->sortKeys()
            ->map(fn ($g, $date) => [
                'date'     => $date,
                'revenue'  => round((float) $g->sum('revenue'), 2),
                'orders'   => (int) $g->sum('orders_count'),
                'ad_spend' => round((float) $g->sum('ad_expense'), 2),
            ])
            ->values();

        // Quebra por loja no período (enriquecida com o nome da empresa).
        $names = Company::whereIn('id', $metrics->pluck('company_id')->unique())->pluck('name', 'id');
        $porLoja = $metrics
            ->groupBy('company_id')
            ->map(function ($g, $cid) use ($names) {
                $rev   = round((float) $g->sum('revenue'), 2);
                $spend = round((float) $g->sum('ad_expense'), 2);

                return [
                    'company_id' => (int) $cid,
                    'name'       => $names[$cid] ?? ('#' . $cid),
                    'revenue'    => $rev,
                    'orders'     => (int) $g->sum('orders_count'),
                    'ad_spend'   => $spend,
                    'tacos'      => $rev > 0 ? round($spend / $rev, 4) : null,
                ];
            })
            ->values()
            ->sortByDesc('revenue')
            ->values();

        return Inertia::render('Dashboard/ShopeeShell', [
            'marketplace' => 'shopee',
            'label'       => 'Shopee',
            'period'      => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'bounds'      => ['min' => $bounds?->min_date, 'max' => $bounds?->max_date],
            'kpis'        => [
                'revenue'      => $revenue,
                'orders'       => $orders,
                'items'        => $items,
                'ticket_medio' => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
                'lojas'        => $lojas->count(),
                'ad_spend'     => $adSpend,
                'tacos'        => $tacos,
            ],
            'ads_disponivel' => $adsDisponivel,
            'series'         => $series,
            'porLoja'        => $porLoja,
            // Filtro por empresa: opções (lojas conectadas) + seleção atual.
            'empresas'       => $lojas->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->values(),
            'companyId'      => $companyFilter,
        ]);
    }

    /**
     * Phase 58 DASH-03 — Shell Amazon. Renderiza direto (bypass pipeline)
     * para evitar KPIs zerados enganosos (CONTEXT §2).
     */
    public function amazon(Request $request)
    {
        return Inertia::render('Dashboard/AmazonShell', [
            'marketplace' => 'amazon',
            'label'       => 'Amazon',
        ]);
    }

    private function getSince(string $period): Carbon
    {
        return match ($period) {
            '1'   => now()->subDay(),
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };
    }

    /**
     * Phase 18 (W2-T1) — Range derivado do filtro de período.
     *
     * Retorna `from`/`to` em formato YYYY-MM-DD (BRT do servidor). Mantém o
     * mesmo conjunto de períodos suportados por `getSince()` para consistência.
     *
     * Substitui o range 30d hardcoded (linhas 106-107 originais); chamado uma
     * vez no topo de `adminDashboard` e propagado pra todas as queries de
     * cards/totais. `getSince()` continua sendo usado para chart de série
     * temporal e demais queries que aceitam Carbon — os dois coexistem.
     *
     * Fase 97 Plan 01 — passou a devolver também a janela ANTERIOR (mesmo
     * nº de dias, imediatamente antes de `from`) para os deltas de KPI
     * (faturamento %, margem pp). Nunca "N dias atuais vs mês inteiro
     * anterior" — janelas sempre do mesmo tamanho (CONTEXT §Claude's Discretion).
     *
     * @return array{from: string, to: string, prev_from: string, prev_to: string}
     */
    private function getPeriodRange(string $period): array
    {
        $days = match ($period) {
            '1'   => 1,
            '7'   => 7,
            '30'  => 30,
            // Fase 97 Plan 03 — '60'/'90' adicionados: o redesign do painel de
            // filtros (FiltrosDashboard.jsx) oferece esse recorte (mockup do
            // usuário), mas sem este case o match caía no `default => 30` e
            // filtrar "Últimos 60 dias" silenciosamente devolvia 30 dias —
            // bug de correção de dados (Rule 1), não mudança de escopo:
            // '1'/'180' continuam aceitos por compat com links antigos.
            '60'  => 60,
            '90'  => 90,
            '180' => 180,
            default => 30,
        };

        $from = now()->subDays($days);
        $to   = now();

        // Janela anterior: termina no dia anterior a `from` e tem o MESMO
        // número de dias — comparação simétrica (apples-to-apples).
        $prevTo   = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'prev_from' => $prevFrom->toDateString(),
            'prev_to'   => $prevTo->toDateString(),
        ];
    }

    private function adminDashboard(Request $request, Carbon $since, string $period)
    {
        // v15.5 hotfix 2026-07-09 — endpoint agregador pesado (168 empresas
        // Performance + metrics + NPS + meetings + score da equipe). Após Phase
        // 72/73 (nps_pendentes + calculadora dual-path), o teto default 128 MB
        // do PHP-FPM foi ultrapassado no VPS. Elevar para 512 MB apenas neste
        // método é o padrão pragmático para endpoints admin heavy — precisa de
        // otimização real (caching + queries batch por template) mas isso
        // desbloqueia enquanto planejamos.
        ini_set('memory_limit', '512M');

        $companyFilter = $request->get('company_id');
        $consultorFilter = $request->get('consultor_id');
        $estrategistaFilter = $request->get('estrategista_id') ?? $request->get('mentor_id'); // back-compat com chamadas antigas
        $grupoFilter = $request->get('group_id');
        // Phase 58 DASH-01/02 — Filter marketplace opcional (whitelist ja
        // validada em ecf()/mercadolivre() antes de chegar aqui).
        $marketplaceFilter = $request->get('marketplace');

        // Hotfix 2026-06-19 — alinha o universo do Dashboard ao de /companies:
        // (1) Phase 35 D-03 — exclui empresas com MlbEmpresa (dupla contagem com /mlb/empresas).
        // (2) Phase 37 Plan 37-06 (REQ-37-07) — restringe a empresas com >=1 contrato ATIVO
        //     em servico de setor=Performance (Gestao+Mentoria). Sem o filtro, o
        //     dashboard contava 115 empresas enquanto /companies mostrava 104.
        // Phase 61 Plan 61-01 — eager-load `mlToken` pra
        // `MetricsProviderFactory::caseFor()` avaliar caso ADR DATA-04 sem
        // N+1 (mitigação T-61-01-02 do threat model). Sempre carregado —
        // custo é 1 query extra mesmo com flag OFF; irrisório vs os loops
        // existentes de gross/account cache.
        $companiesQuery = Company::with(['latestMetrics', 'consultor', 'estrategista', 'mlToken'])
            ->where('active', true)
            ->whereDoesntHave('mlbEmpresa')
            ->whereHas('contratosServico', fn ($q) =>
                $q->where('contratos_servico.ativo', true)
                  ->whereHas('servico', fn ($qs) =>
                      $qs->where('setor', Servico::SETOR_PERFORMANCE)
                  )
            );

        if ($companyFilter) $companiesQuery->where('id', $companyFilter);
        if ($consultorFilter) $companiesQuery->whereHas('consultor', fn($q) => $q->where('users.id', $consultorFilter));
        if ($estrategistaFilter) $companiesQuery->whereHas('estrategista', fn($q) => $q->where('users.id', $estrategistaFilter));
        // Grupo nomeado (company_groups) — filtra o dashboard só para as empresas
        // daquele grupo, funcionando como uma carteira.
        // (Antes usava a hierarquia parent_company_id da empresa principal.)
        if ($grupoFilter) {
            $companiesQuery->where('company_group_id', $grupoFilter);
        }
        // Phase 58 DASH-01/02 — Filter marketplace opcional. Usa coluna flat
        // companies.marketplace (indice existente Phase 18.5) por
        // performance; migracao pra pivot CompanyMarketplace fica pra v14+
        // (CONTEXT §2).
        if ($marketplaceFilter) {
            $companiesQuery->where('marketplace', $marketplaceFilter);
        }

        $companies = $companiesQuery->get();

        $metrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
            ->select(self::ADMAN_METRIC_COLS) // exclui raw_data (ver const) — evita OOM
            ->where('reference_date', '>=', $since->toDateString())
            ->orderBy('reference_date')
            ->get();

        // Fase 97 Plan 01 (D3) — "empresa nova no mês": contrato ATIVO em
        // serviço de setor Performance cuja `data_contratacao` cai no mês
        // corrente. NÃO usa `companies.created_at` (cadastro no sistema pode
        // ser anterior ao início real do atendimento).
        // Fase 97 Plan 02 (DASH-97-7) — guarda também os IDS (não só o count),
        // reusados mais abaixo para montar os cards detalhados de
        // `novas_empresas` a partir do MESMO critério (evita 2 fontes de
        // verdade divergindo entre o número do KPI e a lista de cards).
        $mesInicioAtual = Carbon::now()->startOfMonth();
        $novasEmpresasIds = Company::whereIn('id', $companies->pluck('id'))
            ->whereHas('contratosServico', fn ($q) =>
                $q->where('contratos_servico.ativo', true)
                  ->whereHas('servico', fn ($qs) =>
                      $qs->where('setor', Servico::SETOR_PERFORMANCE)
                  )
                  ->whereBetween('data_contratacao', [
                      $mesInicioAtual->toDateString(),
                      Carbon::now()->toDateString(),
                  ])
            )
            ->pluck('id');
        $novasEmpresasCount = $novasEmpresasIds->count();

        // ─── Janela período-anterior (Fase 97 Plan 01) ───────────────────────
        //
        // Delta % de faturamento e delta pp de margem comparam a janela atual
        // com a janela IMEDIATAMENTE ANTERIOR de mesmo tamanho. Fonte: SUM/AVG
        // direto em `adman_metrics` para os DOIS lados da comparação (o cache
        // híbrido Adman não cobre a janela anterior, então usar fontes
        // diferentes pros dois lados distorceria o delta).
        [$currentFromN, , $prevFromN, $prevToN] = array_values($this->getPeriodRange($period));

        // Limite superior EXCLUSIVO (< $currentFromN, não <= $prevToN): a
        // coluna `reference_date` é `date`-cast mas persiste com sufixo
        // " 00:00:00" no SQLite (confirmado em debug — bug pré-existente,
        // fora do escopo desta fase, ver deferred-items.md). Um `whereBetween`
        // com string pura "Y-m-d" no limite superior comparava
        // "2026-06-19 00:00:00" > "2026-06-19" lexicograficamente e excluía o
        // último dia da janela anterior. `<` contra o dia seguinte evita a
        // ambiguidade independente do sufixo de horário armazenado.
        $prevMetrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
            ->select(self::ADMAN_METRIC_COLS) // exclui raw_data (ver const) — evita OOM
            ->where('reference_date', '>=', $prevFromN)
            ->where('reference_date', '<', $currentFromN)
            ->get();

        $totalRevenueAtualMetrics    = (float) $metrics->sum('revenue');
        $totalRevenueAnteriorMetrics = (float) $prevMetrics->sum('revenue');

        $totalRevenueDeltaPct = $totalRevenueAnteriorMetrics > 0
            ? round((($totalRevenueAtualMetrics - $totalRevenueAnteriorMetrics) / $totalRevenueAnteriorMetrics) * 100, 2)
            : null;

        // Margem de contribuição PONDERADA por faturamento — substitui a
        // média simples de `contribution_margin_pct`. SUM(revenue*margin) /
        // SUM(revenue), considerando só registros com margem conhecida
        // (não-nula) tanto no numerador quanto no denominador, pra não
        // diluir a média com dias sem leitura de margem.
        $pesarMargem = function ($colecao) {
            $comMargem = $colecao->filter(fn ($m) => $m->contribution_margin_pct !== null && $m->revenue !== null);
            $sumRevenue = (float) $comMargem->sum('revenue');
            if ($sumRevenue <= 0) {
                return 0.0;
            }
            $sumRevenueMargem = (float) $comMargem->sum(fn ($m) => (float) $m->revenue * (float) $m->contribution_margin_pct);
            return $sumRevenueMargem / $sumRevenue;
        };

        $avgMarginPonderado         = $pesarMargem($metrics);
        $avgMarginPonderadoAnterior = $pesarMargem($prevMetrics);
        $avgMarginDeltaPp           = round($avgMarginPonderado - $avgMarginPonderadoAnterior, 2);

        // ─── Cards do período (Faturamento, Invest. Ads, TACOS médio) ────────
        //
        // Política HIBRIDA per-empresa (Phase 18 W4-T3) — substitui a antiga
        // política tudo-ou-nada (revogada).
        //
        // Comportamento atual: para CADA empresa, decisão individual:
        //  - cache hit → usa valor EXATO da Adman (vindo de getCachedGrossBillingsMany)
        //  - cache miss → cai em SUM(adman_metrics.revenue) APENAS para essa empresa
        // O total da Dashboard mistura as duas fontes; empresas com cust_id
        // corrompido (que W4-T1 ainda não limpou) ainda contribuem com o valor
        // DB em vez de zero. cards_exatos vira granular: true sse TODAS as
        // empresas com cust_id válido tiveram cache hit E period === '30'.
        //
        // ─── Por que abandonar o tudo-ou-nada? ───────────────────────────────
        // O comentário antigo argumentava "totais oscilantes em ±R$ 20M entre
        // requests, conforme a composição cache-hit muda". Esse problema foi
        // ESTABILIZADO pela Phase 16, que padronizou cache TTL 24h e job de
        // refresh diário (RefreshGrossBillingCacheJob). Com cache estável
        // dentro do mesmo dia, hits e misses tendem a permanecer constantes —
        // a oscilação intra-dia é mínima. Já o custo do tudo-ou-nada ficou
        // visível na auditoria W3-T2 (AUDIT-OUTPUT-30d.txt): 1 empresa sem
        // cache zerava o pool inteiro → 168 empresas iam para SUM DB, mas só
        // 4/172 têm 30 dias completos em adman_metrics → diff de R$ 39,3M
        // (71,79%) vs Adman. O usuário rejeitou backfill (custo 5h+ Adman),
        // então a precisão tem que vir do cache híbrido.
        //
        // ─── Por que cust_id (não só adman_account_id) ──────────────────────
        // Empresas com apenas ml_store_id (cadastradas via Comercial pós-
        // Phase 13) eram silenciosamente excluídas com a lookup antiga
        // (if !$c->adman_account_id continue) → apareciam zeradas no dashboard.
        // cust_id casa com a chave usada por RefreshGrossBillingCacheJob
        // (writer) e AdmanService::syncCompany. Accessor Company::cust_id
        // prioriza ml_store_id ?? adman_account_id (commit f9d0547).
        //
        // ─── Cache strategy por range (Phase 18 W2-T3 — preservada) ─────────
        // RefreshGrossBillingCacheJob (Phase 16) pré-aquece o cache APENAS
        // para range 30d. Quando $period é 1d/7d/180d, o cache híbrido NÃO
        // se aplica — cai no fallback DB completo (decisão intacta) porque
        // a única coisa que muda em ranges curtos é que TODAS as empresas
        // têm cache miss, e o overhead de consultar cache antes de cair em
        // DB seria desperdício. Na prática: $period === '30' → híbrido;
        // demais → fallback DB tudo-ou-nada.
        [$dateFromN, $dateToN] = array_values($this->getPeriodRange($period));

        // Batch read: gross + account metrics em 2 round-trips Redis.
        $custIds = $companies->pluck('cust_id')->filter()->values()->all();
        $grossBatch   = $this->adman->getCachedGrossBillingsMany($custIds, $dateFromN, $dateToN);
        $accountBatch = $this->adman->getCachedAccountMetricsMany($custIds, $dateFromN, $dateToN);

        // Detecta cache /accounts/metrics completo (account ainda usa tudo-ou-
        // nada porque alimenta avg_tacos + total_ad_investment que dependem
        // de denominador estável; mistura cache+DB faria TACOS oscilar entre
        // requests). Empresas sem cust_id são ignoradas — contribuem 0 nos
        // dois modos.
        $accountCacheCompleto = true;
        foreach ($companies as $c) {
            $custId = $c->cust_id;
            if (!$custId) continue;
            $a = $accountBatch[$custId] ?? ['value' => null];
            if ($a['value'] === null) $accountCacheCompleto = false;
        }

        // Para o gross billing (faturamento) seguimos a política híbrida —
        // não decidimos cache completo antes do loop; cada empresa é avaliada
        // individualmente abaixo. Mas mantemos a flag $grossCacheCompleto
        // INFORMATIVA (usada pra disparar warm-up e log abaixo).
        $grossCacheCompleto = true;
        foreach ($companies as $c) {
            $custId = $c->cust_id;
            if (!$custId) continue;
            $g = $grossBatch[$custId] ?? ['value' => null];
            if ($g['value'] === null) $grossCacheCompleto = false;
        }

        // Se algo está faltando, dispara warm-up do cache em background — não
        // afeta este request, mas próximas requests podem ter cache completo.
        // Observação W2-T3: o Job só pré-aquece range 30d, então para
        // $period != '30' o cache continua frio mesmo após o warm-up.
        if (!$grossCacheCompleto || !$accountCacheCompleto) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatchIfQueued();
        }

        // Revenue por empresa no $period:
        //  - $period === '30' → HÍBRIDO per-empresa (cache hit = Adman exato;
        //    cache miss = SUM DB só dessa empresa)
        //  - $period !== '30' → fallback DB completo (cache não cobre esses
        //    ranges; consultar cache empresa-a-empresa seria custo zero
        //    em ganho — preserva decisão W2-T3)
        $revenueByCompany = [];
        $acosByCompany    = [];
        $tacosByCompany   = [];
        $marginByCompany  = [];

        // cacheHitsCount e custIdsValidos alimentam cards_exatos granular
        // (refinamento W4-T3 da flag introduzida em W2-T3).
        $cacheHitsCount  = 0;
        $custIdsValidos  = 0; // empresas com cust_id não-nulo

        if ($period === '30') {
            // Híbrido per-empresa. Pré-buscamos SUM(adman_metrics) só uma vez
            // (única query agregada) para evitar N queries em cache miss.
            $sumDbPorEmpresa = AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFromN, $dateToN])
                ->whereNotNull('revenue')
                ->selectRaw('company_id, SUM(revenue) as total')
                ->groupBy('company_id')
                ->pluck('total', 'company_id');

            $missesNoHibrido = 0;
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) {
                    // Empresa sem cust_id: usa SUM DB (não conta no
                    // denominador de cards_exatos — fica fora da medida).
                    $revenueByCompany[$c->id] = (float) ($sumDbPorEmpresa[$c->id] ?? 0);
                    continue;
                }

                $custIdsValidos++;
                $entry = $grossBatch[$custId] ?? ['value' => null];

                if ($entry['value'] !== null) {
                    // Cache hit → Adman exato
                    $revenueByCompany[$c->id] = (float) $entry['value'];
                    $cacheHitsCount++;
                } else {
                    // Cache miss para essa empresa → SUM DB apenas dela
                    $revenueByCompany[$c->id] = (float) ($sumDbPorEmpresa[$c->id] ?? 0);
                    $missesNoHibrido++;
                }
            }

            if ($missesNoHibrido > 0) {
                Log::info('[Dashboard] revenue hibrido per-empresa: ' . $cacheHitsCount
                    . ' cache hits, ' . $missesNoHibrido . ' cache misses (period=30)');
            }
        } else {
            // Period ≠ 30 → fallback DB tudo-ou-nada (decisão W2-T3 intacta:
            // cache só cobre 30d via RefreshGrossBillingCacheJob).
            $sumDb = AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFromN, $dateToN])
                ->whereNotNull('revenue')
                ->selectRaw('company_id, SUM(revenue) as total')
                ->groupBy('company_id')
                ->pluck('total', 'company_id');

            foreach ($companies as $c) {
                $revenueByCompany[$c->id] = (float) ($sumDb[$c->id] ?? 0);
                if ($c->cust_id) $custIdsValidos++;
            }
            // cacheHitsCount fica em 0 → cards_exatos será false (fallback DB)
            Log::info('[Dashboard] revenue em fallback DB tudo-ou-nada (period=' . $period . ')');
        }

        // ACOS / TACOS / margem / investimento POR EMPRESA — agora HÍBRIDO
        // per-empresa (Plano 03 Phase 19, 2026-06-05). Antes era tudo-ou-nada
        // do account cache: 1 empresa sem cache cold zerava ACOS/TACOS/margem
        // de TODAS — o que distorcia "Invest. Ads 30d" e "TACOS médio" porque
        // o fallback agregado lia SUM(ad_spend) da base inteira, e a base tem
        // 33 empresas Shopee/Amazon com sync histórico incompleto (Phase 18.5).
        //
        // Cada empresa decide individualmente:
        //  - cache hit → valores Adman exatos (investment + acos + tacos + margem)
        //  - cache miss → SUM(ad_spend) só dessa empresa + TACOS recalculado
        //    como (ad_spend / revenue) * 100. ACOS/margem ficam null (não há
        //    fórmula confiável só com adman_metrics; o front cai em
        //    latestMetrics da empresa como fallback secundário).
        //
        // Pré-fetch SUM(ad_spend) per-empresa em 1 query agregada — evita N
        // queries em cache miss e mantém o pattern do bloco de revenue acima.
        $adSpendDbPorEmpresa = AdmanMetric::query()
            ->whereIn('company_id', $companies->pluck('id'))
            ->whereBetween('reference_date', [$dateFromN, $dateToN])
            ->whereNotNull('ad_spend')
            ->selectRaw('company_id, SUM(ad_spend) as total')
            ->groupBy('company_id')
            ->pluck('total', 'company_id');

        $investmentByCompany = [];
        $investHitsCount     = 0;  // empresas com cache hit no account
        $investMissesCount   = 0;  // empresas com cust_id mas cache miss no account

        foreach ($companies as $c) {
            $custId   = $c->cust_id;
            $accEntry = $custId ? ($accountBatch[$custId] ?? null) : null;

            if ($custId && $accEntry && $accEntry['value'] !== null) {
                // Cache hit → Adman exato
                $v = $accEntry['value'];
                $investmentByCompany[$c->id] = (float) ($v['investment'] ?? 0);
                $acosByCompany[$c->id]       = $v['acos']              ?? null;
                $tacosByCompany[$c->id]      = $v['tacos']             ?? null;
                $marginByCompany[$c->id]     = $v['percentage_margin'] ?? null;
                $investHitsCount++;
            } else {
                // Cache miss → SUM(ad_spend) só dessa empresa
                $adSpendEmpresa = (float) ($adSpendDbPorEmpresa[$c->id] ?? 0);
                $revenueEmpresa = (float) ($revenueByCompany[$c->id] ?? 0);

                $investmentByCompany[$c->id] = $adSpendEmpresa;
                // TACOS dela: (ad_spend / revenue) × 100. Sem revenue → null
                // para não poluir o avg final com 0% artificial.
                $tacosByCompany[$c->id] = $revenueEmpresa > 0
                    ? ($adSpendEmpresa / $revenueEmpresa) * 100
                    : null;
                // ACOS exige ads_revenue (não temos em adman_metrics); fica null.
                $acosByCompany[$c->id]   = null;
                // Margem: idem — depende de cost que não está agregado por dia.
                $marginByCompany[$c->id] = null;

                if ($custId) $investMissesCount++;
            }
        }

        if ($investMissesCount > 0) {
            Log::info('[Dashboard] invest hibrido per-empresa: ' . $investHitsCount
                . ' cache hits, ' . $investMissesCount . ' cache misses (period=' . $period . ')');
        }

        // Gera série temporal CONTÍNUA: todas as datas do período no eixo X,
        // preenchendo 0 onde não houve sync. Antes o chart pulava dias
        // (algumas empresas com sync OK, outras não) e ficava visualmente
        // desbalanceado — picos altos contrastando com gaps zerados.
        $byDate     = $metrics->groupBy(fn($m) => $m->reference_date->toDateString());
        $tacosByDate = $byDate->map(fn($g) => round($g->avg('tacos') ?? 0, 2));
        $revByDate   = $byDate->map(fn($g) => (float) $g->sum('revenue'));
        // Fase 97 Plan 01 — margem ponderada por dia (mesmo eixo do revenue_chart).
        $marginByDate = $byDate->map(fn($g) => round($pesarMargem($g), 2));

        $revenueChart = collect();
        $tacosChart   = collect();
        $marginChart  = collect();
        $cursor = $since->copy()->startOfDay();
        $end    = now()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $lbl = $cursor->format('d/m');
            $revenueChart->push(['date' => $lbl, 'revenue' => (float) ($revByDate[$key] ?? 0)]);
            $tacosChart->push(['date' => $lbl, 'tacos' => (float) ($tacosByDate[$key] ?? 0)]);
            $marginChart->push(['date' => $lbl, 'margin' => (float) ($marginByDate[$key] ?? 0)]);
            $cursor->addDay();
        }
        $revenueChart = $revenueChart->values();
        $tacosChart   = $tacosChart->values();
        $marginChart  = $marginChart->values();

        // 2026-07-13 — só o modelo PRINCIPAL alimenta os widgets da home.
        // Phase 96 Plan 04 (AB-96-3 · call-site #5) — resposta invalidada
        // pelo admin some dos widgets NPS do dashboard.
        $npsResponses = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
            ->principal()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();

        // Phase 73 Plan 01 (SC#1) — media NPS dimensao 'empresa' via
        // NpsScoreCalculator para surveys v15; fallback score_empresa legado
        // para surveys pre-v15 (Phase 31 preservado — dual-path).
        // Closure $notaDe reusada logo abaixo pelos buckets positivas/negativas.
        $calculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        $notaDe = function ($s) use ($calculator) {
            if ($s->template_id !== null && $s->response) {
                return $calculator->compute($s->response, 'empresa');
            }
            return $s->response?->score_empresa;
        };
        $notasEmpresa = $npsResponses->map($notaDe)->filter(fn($n) => $n !== null);

        // Fase 116 Plan 05 · NPS não respondido conta nota 1 (D7 — dimensão
        // empresa) — mescla as notas imputadas ao mesmo recorte do widget
        // ($companies + janela $since), restrito ao modelo PRINCIPAL
        // (templateIds = [principalId()]) porque $npsResponses acima também
        // só olha ->principal(). Cast explícito pra Collection base ANTES do
        // merge(): $notasEmpresa é Eloquent\Collection (herda o tipo de
        // $npsResponses via ->map()/->filter()) e o merge() dela assume
        // Model::getKey() — armadilha documentada nos Plans 116-03/116-04.
        $notasImputadasEmpresa = $this->npsImputation->notasDaEmpresa(
            $companies->pluck('id'),
            'empresa',
            $since,
            now(),
            null,
            collect([NpsTemplate::principalId()]),
        )->pluck('nota');
        $notasEmpresa = collect($notasEmpresa->all())->merge($notasImputadasEmpresa);

        // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no mês
        // também conta nota 1, mesma régua do bônus. Ramo ADITIVO e
        // DISJUNTO dos ramos acima (`notasDaEmpresaSemLink()` rejeita
        // internamente qualquer empresa que já tenha survey na competência).
        // Piso retroativo (DEC-09-B): esta é leitura em JANELA ROLANTE —
        // nunca alcança mais que mês anterior + corrente.
        $notasSemLinkEmpresa = $this->npsSemLink->notasDaEmpresaSemLink(
            $companies->pluck('id'),
            'empresa',
            $since,
            now(),
            null,
            collect([NpsTemplate::principalId()]),
            $this->npsSemLink->pisoRetroativo(),
        )->pluck('nota');
        $notasEmpresa = $notasEmpresa->merge($notasSemLinkEmpresa);

        $avgNps = $notasEmpresa->isNotEmpty()
            ? round((float) $notasEmpresa->avg(), 2)
            : 0;

        $meetings = Meeting::whereIn('company_id', $companies->pluck('id'))
            ->where('scheduled_at', '>=', $since)
            ->where('status', 'completed')
            ->get();

        $absenteeismRate = $meetings->count() > 0
            ? $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count() / $meetings->count() * 100
            : 0;

        // Card 'Faturamento' = soma do que foi escolhido acima (cache Adman OU
        // SUM DB, tudo-ou-nada). Determinístico por request.
        $totalRevenue = array_sum($revenueByCompany);

        // Card 'Invest. Ads' = soma per-empresa do híbrido acima (cache hit usa
        // Adman exato; cache miss usa SUM(ad_spend) só da empresa). Determinístico
        // por request — não depende mais do estado tudo-ou-nada do account cache.
        $totalAdInvestment = array_sum($investmentByCompany);

        // Card 'TACOS médio' = média simples dos TACOS per-empresa (consistência
        // com o cálculo anterior). Empresas sem revenue (TACOS null) ficam fora
        // do denominador para não poluir com 0% artificial.
        $tacosValues = array_filter($tacosByCompany, fn($v) => $v !== null);
        $avgTacos    = !empty($tacosValues) ? array_sum($tacosValues) / count($tacosValues) : 0;

        // Fase 97 Plan 01 — avg_margin agora é a margem PONDERADA por
        // faturamento (não mais média simples de contribution_margin_pct);
        // calculada acima em $avgMarginPonderado (compartilhada com o delta pp).
        $avgMargin = $avgMarginPonderado;
        $productsWithoutCost = $metrics->avg(fn($m) => $m->products_without_cost_pct) ?? 0;

        $lastSyncDate = $metrics->max('reference_date');

        // Phase 73 Plan 01 (SC#1) — buckets simplificados escala 1-5.
        // Classificacao ternaria legacy (herdada do NPS 0-10 classico)
        // REMOVIDA. v15 opera:
        //   nota >= 4 → resposta positiva (Excelente/Bom)
        //   nota <= 3 → resposta negativa (Precisa melhorar)
        // Reusa $notaDe declarada acima (dual-path v15 vs legacy).
        // Chaves de props Inertia mudaram: 'promotores/neutros/detratores'
        // → 'positivas/negativas'; frontend Plan 73-03 consome os novos nomes.
        $npsDistribution = [
            'positivas' => $npsResponses->filter(function ($s) use ($notaDe) {
                $n = $notaDe($s);
                return $n !== null && $n >= 4;
            })->count(),
            'negativas' => $npsResponses->filter(function ($s) use ($notaDe) {
                $n = $notaDe($s);
                return $n !== null && $n <= 3;
            })->count(),
        ];

        // Fase 97 Plan 02 (DASH-97-5) — widget "NPS ruim": respostas da
        // dimensão empresa com nota BAIXA, do MESMO recorte usado acima
        // ($npsResponses já filtra ->principal() + whereIn($companies) +
        // janela $since). Régua de "nota ruim" = MESMA do bucket 'negativas'
        // acima (nota <= 3 na escala 1-5 do sistema) — o "≤5"/"≥6" do mockup
        // é herança da escala 0-10 antiga e NÃO se aplica aqui (usar ≤3 evita
        // marcar quase todas as respostas como ruins).
        // Invalidação (Fase 96): `$s->response` já vem eager-loaded com
        // `->valida()` (linha acima) — uma resposta invalidada pelo admin tem
        // `response=null` aqui, `$notaDe($s)` retorna null e cai fora do
        // filtro automaticamente (T-97-02-02), sem precisar checar
        // `invalidated_at` de novo.
        $companiesById = $companies->keyBy('id');
        $npsRuins = $npsResponses
            ->filter(function ($s) use ($notaDe) {
                $n = $notaDe($s);
                return $n !== null && $n <= 3;
            })
            ->sortByDesc(fn ($s) => $s->completed_at)
            ->values()
            ->map(function ($s) use ($notaDe, $companiesById) {
                $company = $companiesById->get($s->company_id);
                return [
                    // Ids para o link "Abrir NPS completo →" (Plan 97-04).
                    'survey_id'    => $s->id,
                    'company_id'   => $s->company_id,
                    'company_name' => $company?->name,
                    'data'         => $s->completed_at?->format('d/m/Y'),
                    'nota'         => $notaDe($s),
                    'comment'      => $s->response?->comment,
                    'analista'     => $company?->consultor->first()?->name,
                    'estrategista' => $company?->estrategista->first()?->name,
                ];
            })
            ->values();

        // Ranking "Analistas e Mentores" + filtros: só time de consultoria.
        // Antes era `role != admin`, que deixava publicadores (role=consultor +
        // publication_role=publicador) vazarem para o ranking. Mesma regra da
        // página Desempenho (PerformanceController::index).
        $users = User::where('active', true)
            ->whereIn('role', ['consultor', 'mentor'])
            ->whereNull('publication_role')
            ->get();
        // Hotfix 2026-06-19 — dropdown reflete o universo do Dashboard
        // (Performance + sem MlbEmpresa), igual ao $companiesQuery acima.
        $allCompanies = Company::where('active', true)
            ->whereDoesntHave('mlbEmpresa')
            ->whereHas('contratosServico', fn ($q) =>
                $q->where('contratos_servico.ativo', true)
                  ->whereHas('servico', fn ($qs) =>
                      $qs->where('setor', Servico::SETOR_PERFORMANCE)
                  )
            )
            // Phase 58 DASH-01/02 — mantem o dropdown consistente com o
            // universo filtrado pelo marketplace (quando presente).
            ->when($marketplaceFilter, fn ($q, $mp) => $q->where('marketplace', $mp))
            ->orderBy('name')
            ->get(['id', 'name']);

        // ─── Fonte da verdade: cargo no setor Performance via pivot ──────────
        //
        // (Quick task 260610-f69) Os filtros "Analistas" e "Estrategistas" do
        // dashboard precisam refletir a TAXONOMIA NOVA — quem é o quê depende
        // do cargo guardado em `user_setores.cargo_id` → `cargos.slug`
        // (∈ {`analista`, `estrategista`} no setor Performance). O campo
        // `users.role` é legacy (admin/consultor/mentor) e divergiu da
        // realidade: Nathalia, Rubens, Débora e Douglas têm `role=consultor`
        // mesmo sendo Estrategistas reais — caíam no select errado.
        //
        // Usamos `whereExists` no pivot (não `whereHas('setores')->whereHas('cargos')`)
        // porque queremos filtrar pelo cargo GUARDADO no pivot deste user,
        // não por qualquer cargo do setor. O whereHas aninhado matchearia
        // "users em setor que contém o cargo X", o que retornaria todos os
        // membros de Performance (já que Performance tem ambos os cargos).
        $analistas = User::where('active', true)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'analista');
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $estrategistas = User::where('active', true)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'estrategista');
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        // ─── Cross-filter: combinações analista↔estrategista que coabitam ────
        //
        // Lista de pares (analista_id, estrategista_id) que dividem ao menos
        // uma empresa na pivot `company_users`. O frontend filtra dinamicamente
        // o select oposto quando um já está selecionado — selecionar um
        // analista mostra apenas estrategistas que trabalham com ele (e vice-
        // versa). `cu_a.role='consultor'` aqui é o slot do Analista na pivot
        // (legacy enum value — ver migração 2026_05_07 que adicionou 'analista'
        // pra outro uso no módulo Sugadores; o vínculo Empresa↔Analista do
        // setor Performance continua usando 'consultor').
        $combinacoes = DB::table('company_users as cu_a')
            ->join('company_users as cu_e', function ($j) {
                $j->on('cu_e.company_id', '=', 'cu_a.company_id')
                  ->where('cu_e.role', '=', 'estrategista');
            })
            ->where('cu_a.role', '=', 'consultor')
            ->select('cu_a.user_id as analista_id', 'cu_e.user_id as estrategista_id')
            ->distinct()
            ->get();

        // Grupos nomeados (company_groups) — opções do filtro de grupo do dashboard.
        // Funciona como carteira: selecionar mostra só as empresas daquele grupo.
        $grupos = \App\Models\CompanyGroup::orderBy('name')->get(['id', 'name']);

        // Quick 260623 — buildRanking() ainda existe pra back-compat (callers
        // possiveis), mas o widget "Ranking Analistas e Mentores" foi removido
        // do Dashboard/Admin.jsx em favor do "Desempenho da equipe" (BarChart
        // por score, alimentado por performance_equipe acima). $ranking nao e
        // mais incluido no payload Inertia — economiza 1 query agregada por
        // request.

        // ─── Carteira por profissional — MOVIDA pra aba "Carteira" (quick 260610-lj6) ───
        //
        // O widget consolidado de carteiras (antes embutido no Dashboard Admin)
        // agora vive em `PortfolioController::renderCarteirasConsolidadas`, sob
        // a rota `portfolio.own` quando o usuário logado é admin. Não-admin
        // continua vendo a própria carteira pessoal (Portfolio/Show.jsx).
        //
        // Aqui não calculamos mais `$userPortfolios` para não onerar o Dashboard
        // com queries de portfolio que não são renderizadas nesta página.

        $totalNetBilling    = $metrics->sum('net_billing');
        $totalSoldQuantity  = $metrics->sum('sold_quantity');
        $totalAdSpend       = $metrics->sum('ad_spend');
        $avgProfitShare     = $metrics->avg('profit_share') ?? 0;

        // Último sync Adman bem-sucedido (qualquer empresa) — alimenta o badge
        // "Atualizado em DD/MM HH:mm · D-1 da Adman" no Dashboard. Filtra
        // `error_message IS NULL` para considerar apenas execuções de sucesso.
        $admanLastSyncAt = AdmanSyncLog::query()
            ->whereNull('error_message')
            ->latest('created_at')
            ->value('created_at');
        $admanLastSync = $admanLastSyncAt
            ? [
                'iso'   => $admanLastSyncAt->toIso8601String(),
                'label' => $admanLastSyncAt->copy()->setTimezone(config('app.timezone'))->format('d/m H:i'),
            ]
            : null;

        // Phase 18 (W2-T3 + refinado em W4-T3) — Flag de exatidão dos cards
        // Adman-dependentes. Critério granular: TODAS as empresas com cust_id
        // válido tiveram cache hit no gross billing E o período é 30d
        // (alinhado com RefreshGrossBillingCacheJob). Empresas sem cust_id
        // não contam no denominador (são intrinsecamente fallback DB).
        //
        // Diferença vs W2-T3: a flag antiga era "grossCacheCompleto" (true
        // sse 100% das empresas com cust_id tiveram cache REAL). Continua a
        // mesma exigência conceitual, mas agora o cálculo é explícito pela
        // contagem de hits no loop híbrido acima. Permite que o cache híbrido
        // per-empresa (W4-T3) gere `cards_exatos = false` honestamente
        // quando alguma empresa caiu em fallback DB, sem zerar o total
        // global como o tudo-ou-nada fazia.
        //
        // Para demais ranges (1/7/180d), mesmo com cache hot, marcamos como
        // aproximado porque o cache só pré-aquece 30d. Frontend (W5-T1)
        // consome essa prop para mostrar "≈ aproximado" nos cards.
        // Plano 03 Phase 19: cards_exatos agora exige cache hit per-empresa em
        // AMBAS as fontes (gross E account), via cacheHitsCount + investHitsCount
        // (alimentados nos loops híbridos acima). $accountCacheCompleto ainda
        // existe para a métrica do warm-up dispatch, mas a flag de exatidão
        // usa a contagem granular per-empresa.
        $cardsExatos = ($period === '30')
            && ($custIdsValidos > 0)
            && ($cacheHitsCount === $custIdsValidos)
            && ($investHitsCount === $custIdsValidos);

        // Phase 74 D-05 — Performance da equipe: 1 nota por membro do setor
        // Performance (analistas + estrategistas via cargo slug). Lider de setor
        // ve so a equipe que ele lidera; admin ve todos. Alimenta o widget que
        // substituiu NPS Distribuicao em Dashboard/Admin.jsx.
        // Motor v2: DesempenhoScoreService::compute($u, $mesEmCurso) retorna
        // nota_final (0-5) + faixa_bonus (slug). DESEMP-10 filtra users sem
        // carteira antes do ranking.
        $scoreService = app(\App\Services\DesempenhoScoreService::class);
        $mesReferenciaPerf = Carbon::now()->startOfMonth();

        // Fase 97 Plan 02 (DASH-97-1 · Riscos §2) — "Score da equipe" ANTES
        // ignorava por completo o recorte de filtros (company_id/consultor_id/
        // estrategista_id/group_id/marketplace), mostrando SEMPRE todo o setor.
        // Deriva o conjunto de user_id vinculados às empresas de $companies
        // (recorte já aplicado acima, linha ~373) via pivot `company_users`,
        // papel 'consultor' (=Analista) ou 'estrategista'. Quando NENHUM filtro
        // está ativo, $companies já é TODO o universo Performance — então este
        // conjunto coincide com a visão ampla de hoje (não regride).
        $userIdsDoRecorte = DB::table('company_users')
            ->whereIn('company_id', $companies->pluck('id'))
            ->whereIn('role', ['consultor', 'estrategista'])
            ->distinct()
            ->pluck('user_id');

        $perfMembrosQuery = User::where('active', true)
            ->whereIn('id', $userIdsDoRecorte)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            });
        $atual = $request->user();
        if (!$atual->isAdmin() && $atual->isLider()) {
            $setoresIds = $atual->setoresLiderados()->pluck('setores.id');
            $perfMembrosQuery->whereExists(function ($q) use ($setoresIds) {
                $q->from('user_setores as us2')
                  ->whereColumn('us2.user_id', 'users.id')
                  ->whereIn('us2.setor_id', $setoresIds);
            });
        }
        // Ajuste 2026-07-09 — envia também `score` (0-100 = nota_final × 20)
        // e `classificacao` (mapeamento do faixa_bonus para labels legados)
        // para o widget BarChart em Dashboard/Admin.jsx que ainda consome
        // esses campos. Preserva compat visual do widget sem reescrita.
        $faixaParaClassificacao = [
            'maximo'        => 'excelente',
            'intermediario' => 'bom',
            'basico'        => 'atencao',
            'sem_bonus'     => 'critico',
        ];

        $perfMembros = $perfMembrosQuery->orderBy('name')->get(['id', 'name'])
            ->map(function ($u) use ($scoreService, $mesReferenciaPerf, $faixaParaClassificacao) {
                // Ajuste 2026-07-10 (audit performance-lentidao): cacheado.
                // Antes: 11 users × até 4 HTTP calls por empresa = 70s cold.
                $r = $scoreService->computeCached($u, $mesReferenciaPerf);
                $notaFinal   = $r['nota_final'] ?? null;
                $faixaSlug   = $r['faixa_bonus'] ?? null;
                return [
                    'id'              => $u->id,
                    'name'            => $u->name,
                    'nota_final'      => $notaFinal,
                    'faixa_bonus'     => $faixaSlug,
                    'faixa_promovida' => (bool) ($r['faixa_promovida'] ?? false),
                    'sem_carteira'    => (bool) ($r['sem_carteira'] ?? false),
                    // Campos legados pra compat visual do widget Admin.jsx:
                    'score'           => $notaFinal !== null ? round($notaFinal * 20, 0) : null,
                    'classificacao'   => $faixaParaClassificacao[$faixaSlug] ?? 'critico',
                    // Fase 97 Plan 02 (D1) — breakdown pro card "Score da
                    // equipe" (barra + composição). Chaves escolhidas
                    // (decisão do executor, ver 97-02-PLAN.md Task 1):
                    //   'carteira' => nº de empresas na carteira do profissional
                    //                 (empresas_carteira, já resolvido por
                    //                 CarteiraContextService — não é um
                    //                 componente de nota, é contexto).
                    //   'nps'      => pontos_componentes.nps (0-5, mesma escala
                    //                 da nota_final, usado no cálculo da média).
                    //   'margem'   => pontos_componentes.margem (0-5, idem).
                    //   'tacos'    => SEMPRE null. O DesempenhoScoreService
                    //                 (nota oficial D1) NÃO calcula um
                    //                 componente de TACoS — os 3 componentes
                    //                 reais da média são NPS/faturamento/margem
                    //                 (o real 3º componente vem exposto abaixo
                    //                 em 'faturamento'). Mantemos a chave
                    //                 'tacos' só por compat com o layout de 4
                    //                 barras do mock; o card (Plan 97-04) deve
                    //                 tratar null como indisponível ou trocar o
                    //                 rótulo por "Faturamento" ao consumir a
                    //                 chave 'faturamento' real.
                    'breakdown' => [
                        'carteira'    => $r['empresas_carteira'] ?? 0,
                        'nps'         => $r['pontos_componentes']['nps'] ?? null,
                        'margem'      => $r['pontos_componentes']['margem'] ?? null,
                        'faturamento' => $r['pontos_componentes']['faturamento'] ?? null,
                        'tacos'       => null,
                    ],
                ];
            })
            // DESEMP-10 — remove users sem carteira do ranking.
            ->reject(fn ($r) => $r['sem_carteira'] === true)
            ->sortByDesc(fn ($r) => $r['nota_final'] ?? -1)
            ->values();

        // Phase 61 Plan 61-01 — enriquecimento condicional com `source` por
        // empresa (dicionário id→enum) e `source_counts` agregado. Quando
        // flag ON, percorre `$companies` UMA vez chamando `caseFor()`
        // (barato — sem HTTP) e alimenta:
        //  - `$sourceByCompanyId` consumido no map de companies_performance
        //  - `$sourceCounts` mesclado em stats.source_counts
        // Quando flag OFF, ambos ficam null → chaves não aparecem no payload.
        $sourceByCompanyId = null;
        $sourceCounts      = null;
        if ($this->unifiedMetricsEnabled()) {
            $sourceByCompanyId = [];
            $sourceCounts      = ['adman' => 0, 'ml' => 0, 'unified' => 0, 'none' => 0];
            foreach ($companies as $c) {
                $src = $this->factoryToSource($c);
                $sourceByCompanyId[$c->id] = $src;
                $sourceCounts[$src]++;
            }
        }

        // Phase 72 Plan 02 — SC#3: injeta lista de empresas pendentes de NPS no
        // mes corrente para o widget do Dashboard (Plan 72-03 renderiza).
        // Fase 97 Plan 02 (DASH-97-1 · Riscos §2) — trocado de `forCarteira`
        // (que para admin ignorava QUALQUER filtro e trazia TODAS as empresas
        // do sistema) para `forCompanies($companies)`, que respeita o mesmo
        // recorte (company_id/consultor_id/estrategista_id/group_id/
        // marketplace) já aplicado a todos os outros widgets do dashboard.
        $npsPendentes = $this->npsPending->forCompanies($companies);

        // Fase 97 Plan 02 (DASH-97-7) — cards detalhados de "empresas novas
        // no mês" (mesmo critério D3 de `$novasEmpresasIds` acima). Reusa as
        // Company já carregadas em `$companies` (consultor/estrategista
        // eager-loaded) — filtra pelos ids em vez de rodar uma 2ª query
        // completa de Company, evitando 2 fontes de verdade divergentes.
        $novasEmpresas = $companies
            ->whereIn('id', $novasEmpresasIds)
            ->sortBy('name')
            ->values()
            ->map(function ($c) use ($revenueByCompany, $tacosByCompany, $marginByCompany) {
                $faturamentoParcial = (float) ($revenueByCompany[$c->id] ?? 0);
                $margem = $marginByCompany[$c->id] ?? null;

                // Fase 97 Plan 02 — regra do "status" do card (decisão do
                // executor — o mockup não define o cálculo, só o rótulo):
                //   'ramp-up'  → ainda sem faturamento apurado no período
                //                (empresa recém-iniciada, dado ainda não
                //                chegou ou ainda não há venda).
                //   'atencao'  → já fatura, mas margem de contribuição
                //                negativa.
                //   'saudavel' → demais casos (fatura e margem >= 0, ou
                //                margem ainda indisponível mas já com
                //                faturamento).
                $status = $faturamentoParcial <= 0
                    ? 'ramp-up'
                    : (($margem !== null && $margem < 0) ? 'atencao' : 'saudavel');

                return [
                    'id'                  => $c->id,
                    'name'                => $c->name,
                    'grupo'               => $c->grupo?->name,
                    'status'              => $status,
                    'faturamento_parcial' => $faturamentoParcial,
                    'tacos'               => $tacosByCompany[$c->id] ?? null,
                    'analista'            => $c->consultor->first()?->name,
                    'estrategista'        => $c->estrategista->first()?->name,
                ];
            })
            ->values();

        return Inertia::render('Dashboard/Admin', [
            'stats' => [
                'total_companies'          => $companies->count(),
                'avg_tacos'                => round($avgTacos, 2),
                'avg_nps'                  => round($avgNps, 1),
                'absenteeism_rate'         => round($absenteeismRate, 2),
                'total_revenue'            => $totalRevenue,
                // Phase 18 (W2-T2) — sufixo `_30d` na chave da prop é legacy
                // (back-compat com Admin.jsx); o valor agora reflete $period.
                'total_ad_investment_30d'  => $totalAdInvestment,
                'total_net_billing'        => $totalNetBilling,
                'total_sold_quantity'      => (int) $totalSoldQuantity,
                'total_ad_spend'           => $totalAdSpend,
                'avg_margin'               => round($avgMargin, 2),
                'avg_profit_share'         => round($avgProfitShare, 2),
                'products_without_cost_pct' => round($productsWithoutCost, 2),
                'last_sync_date'           => $lastSyncDate?->format('d/m/Y'),
                // Fase 97 Plan 01 — deltas de KPI vs. janela período-anterior
                // (mesmo nº de dias) + contagem de empresas novas no mês (D3).
                'total_revenue_delta_pct'  => $totalRevenueDeltaPct,
                'avg_margin_delta_pp'      => $avgMarginDeltaPp,
                'novas_empresas_count'     => $novasEmpresasCount,
                // Phase 61 Plan 61-01 — ADICIONA metadados só quando flag ON.
                // Se null, Inertia serializa como null (aceito nos testes que
                // usam ->missing() semanticamente pra "chave ausente ou null"
                // — mas usamos array_filter abaixo pra garantir OMISSÃO real
                // quando flag OFF, preservando payload legacy bit-a-bit).
                ...($sourceCounts !== null ? ['source_counts' => $sourceCounts] : []),
            ],
            'revenue_chart'  => $revenueChart,
            'tacos_chart'    => $tacosChart,
            // Fase 97 Plan 01 — série diária de margem ponderada (mesmo eixo
            // de datas do revenue_chart), para a aba "Margem" do gráfico de
            // evolução do redesign (D4).
            'margin_chart'   => $marginChart,
            'nps_distribution' => $npsDistribution,
            // Quick 260623 — alimenta widget "Performance da equipe" que
            // substituiu NPS Distribuicao no Dashboard/Admin.jsx.
            'performance_equipe' => $perfMembros,
            'period'         => $period,
            // Phase 18 (W1-T1) — chaves em snake_case alinhadas com os query params lidos
            // nas linhas 68-70 (mesma fonte de verdade). Antes usava compact() que produzia
            // camelCase no Inertia e quebrava o spread `...filters` em Admin.jsx (Bug 2 do
            // dia 2026-06-02: empresa sumia ao trocar período).
            'filters'        => [
                'company_id'      => $companyFilter,
                'consultor_id'    => $consultorFilter,
                'estrategista_id' => $estrategistaFilter,
                'group_id'        => $grupoFilter,
                // Fase 97 Plan 01 — corrige a base do bug do marketplace
                // sumindo (CONTEXT Riscos §1): o front precisa receber de
                // volta o recorte atual para preservá-lo ao reaplicar filtros.
                'marketplace'     => $marketplaceFilter,
            ],
            // Fase 97 Plan 01 — nome da rota atual ('dashboard' ou
            // 'mercadolivre.dashboard'), para o front (Plan 97-03) navegar
            // preservando o contexto ML ao aplicar filtros (Riscos §1).
            'dashboard_route_name' => $request->route()?->getName(),
            'users'          => $users->map(fn($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role]),
            // (Quick task 260610-f69) Fonte da verdade nova pros filtros do
            // dashboard: vem do cargo no setor Performance via pivot, não do
            // legacy users.role. `analistas` e `estrategistas` alimentam os
            // selects; `combinacoes` habilita o cross-filter no frontend.
            'analistas'      => $analistas,
            'estrategistas'  => $estrategistas,
            'combinacoes'    => $combinacoes,
            'companies_list' => $allCompanies,
            'grupos_list'    => $grupos,
            // 'ranking' removido (quick 260623) — widget legado substituido por
            // 'performance_equipe' (BarChart score). Ver comentario perto da
            // chamada do buildRanking() acima.
            'companies_performance' => $companies->map(fn($c) => [
                'id'       => $c->id,
                'name'     => $c->name,
                // ACOS/TACOS/margem do cache /accounts/metrics (range do
                // $period, exato da Adman quando cache hot); fallback
                // latestMetrics (1 dia) só se cache cold.
                'acos'     => $acosByCompany[$c->id]   ?? null,
                'tacos'    => $tacosByCompany[$c->id]  ?? $c->latestMetrics?->tacos,
                'revenue'  => (float) ($revenueByCompany[$c->id] ?? 0),
                'margin'   => $marginByCompany[$c->id] ?? $c->latestMetrics?->contribution_margin_pct,
                // Phase 18 W5-T3 — flag persistida; frontend usa para badge
                // "Cust ID Invalido" na lista de performance per empresa.
                'cust_id_status' => $c->cust_id_status,
                'consultor' => $c->consultor->first()?->name,
                'estrategista' => $c->estrategista->first()?->name,
                // Phase 61 Plan 61-01 — ADICIONA `source` só quando flag ON.
                // Spread condicional preserva payload legacy bit-a-bit (chave
                // literalmente ausente quando flag OFF).
                ...($sourceByCompanyId !== null ? ['source' => $sourceByCompanyId[$c->id] ?? 'none'] : []),
            ]),
            'adman_last_sync' => $admanLastSync,
            // Phase 18 (W2-T3) — true quando cards Adman-dependentes refletem
            // valores exatos do cache /performance (period=30 + cache hot).
            // Frontend consome em W5-T1 para indicador "≈ aproximado".
            'cards_exatos'    => $cardsExatos,
            // Phase 72 Plan 02 — SC#3: empresas pendentes de NPS no mes corrente
            // (shape [{company_id, name, template_id, template_nome,
            // month_reference, dias_atraso}]). Plan 72-03 renderiza o widget.
            'nps_pendentes'   => $npsPendentes,
            // Fase 97 Plan 02 (DASH-97-5) — respostas de nota baixa (<=3) do
            // recorte, excluindo invalidadas (Fase 96). Widget "NPS ruim"
            // (Plan 97-04) renderiza o carrossel.
            'nps_ruins'       => $npsRuins,
            // Fase 97 Plan 02 (DASH-97-7) — cards das empresas com contrato
            // ativo iniciado no mês corrente (D3). Widget "Novas empresas no
            // mês" (Plan 97-04) renderiza.
            'novas_empresas'  => $novasEmpresas,
        ]);
    }

    private function buildRanking($users, Carbon $since): \Illuminate\Support\Collection
    {
        return $users->map(function ($u) use ($since) {
            $companyIds = $u->isMentor()
                ? $u->estrategistaCompanies()->pluck('companies.id')
                : $u->consultorCompanies()->pluck('companies.id');

            if ($companyIds->isEmpty()) {
                $companyIds = $u->companies()->pluck('companies.id');
            }

            // Phase 96 Plan 04 (AB-96-3 · call-site #7) — resposta invalidada
            // pelo admin some do ranking "Desempenho da equipe".
            $surveys = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
                ->whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $since)
                ->get();

            // Phase 73 Plan 01 (SC#1) — media por dimensao via NpsScoreCalculator
            // para surveys v15 (template_id != null); fallback na coluna legada
            // nps_responses.score_* para surveys pre-v15 (Phase 31 preservado,
            // dual-path). Taxonomia mantida: Estrategista (isMentor) →
            // dimensao 'estrategista'; Analista (consultor) → 'analista'.
            // Escala 1-5 uniforme, round(1) preserva precisao historica do widget.
            // 2026-07-13 — dimensão por CARGO canônico (não isMentor()).
            $dimensao   = $u->dimensaoNpsDesempenho();
            $scoreField = $dimensao === 'estrategista' ? 'score_estrategista' : 'score_analista';  // fallback legacy

            // Fase 116 Plan 05 · notas imputadas (não respondido = nota 1) da
            // MESMA carteira ($companyIds) já usada acima — ranking não
            // filtra por modelo (templateIds=null), diferente do widget admin.
            $notasImputadas = $this->npsImputation->notasDaEmpresa($companyIds, $dimensao, $since, now())->pluck('nota');

            // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no
            // mês também conta nota 1, mesma régua do bônus. MESMA carteira
            // ($companyIds) já usada acima; templateIds=null (o ranking hoje
            // não filtra por modelo — preservado). Piso retroativo
            // (DEC-09-B): leitura em JANELA ROLANTE, nunca alcança mais que
            // mês anterior + corrente. Concatenado ANTES do guard abaixo —
            // senão a pessoa só com nota D1 (sem nenhuma resposta real nem
            // imputada do ramo C) continuaria caindo no sentinela null.
            $notasSemLink = $this->npsSemLink->notasDaEmpresaSemLink(
                $companyIds,
                $dimensao,
                $since,
                now(),
                null,
                null,
                $this->npsSemLink->pisoRetroativo(),
            )->pluck('nota');
            $notasImputadas = $notasImputadas->merge($notasSemLink);

            // Guard ajustado: pessoa só com notas imputadas (sem nenhuma
            // resposta real) não pode mais cair no sentinela null antigo.
            $avgNps = ($surveys->count() > 0 || $notasImputadas->isNotEmpty())
                ? $this->avgNotaDimensao($surveys, $dimensao, $scoreField, $notasImputadas)
                : null;

            $meetingsTotal = Meeting::whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('scheduled_at', '>=', $since)
                ->count();

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'role'            => $u->role,
                'companies_count' => $companyIds->count(),
                'avg_nps'         => $avgNps,
                'total_meetings'  => $meetingsTotal,
                'nps_responses'   => $surveys->count(),
            ];
        })->sortByDesc(fn($u) => $u['avg_nps'] ?? -1)->values();
    }

    private function userDashboard(User $user, Carbon $since, string $period)
    {
        $companies = $user->companies()->with(['latestMetrics', 'goals'])->get();

        // Phase 58 DASH-01/02 — filter marketplace opcional (carteira ja
        // restrita; filtro em Collection, sem re-query). Baixo impacto
        // pratico pois usuarios comuns ja tem carteira pequena.
        $marketplaceFilter = request()->get('marketplace');
        if ($marketplaceFilter && in_array($marketplaceFilter, ['meli', 'shopee', 'amazon'], true)) {
            $companies = $companies->where('marketplace', $marketplaceFilter)->values();
        }

        // Sugadores na carteira do usuário (pendentes)
        $sugadoresCarteira = Sugador::pendentes()
            ->whereIn('company_id', $companies->pluck('id'))
            ->count();

        $metrics = AdmanMetric::whereIn('company_id', $companies->pluck('id'))
            ->select(self::ADMAN_METRIC_COLS) // exclui raw_data (ver const) — evita OOM
            ->where('reference_date', '>=', $since->toDateString())
            ->get();

        // 30d cache com política tudo-ou-nada (mesma estratégia do
        // adminDashboard, agora via cust_id = adman_account_id ?: ml_store_id).
        // TODO Phase 18+ — userDashboard não foi tocado nesta fase; mantém o
        // range 30d hardcoded até decisão sobre escopo de extensão para
        // dashboards de consultor/mentor.
        $dateFrom30d = now()->subDays(30)->toDateString();
        $dateTo30d   = now()->toDateString();

        $custIds30d = $companies->pluck('cust_id')->filter()->values()->all();
        $grossBatch30d   = $this->adman->getCachedGrossBillingsMany($custIds30d, $dateFrom30d, $dateTo30d);
        $accountBatch30d = $this->adman->getCachedAccountMetricsMany($custIds30d, $dateFrom30d, $dateTo30d);

        $grossCacheCompleto   = true;
        $accountCacheCompleto = true;
        foreach ($companies as $c) {
            $custId = $c->cust_id;
            if (!$custId) continue;
            $g = $grossBatch30d[$custId]   ?? ['value' => null];
            $a = $accountBatch30d[$custId] ?? ['value' => null];
            if ($g['value'] === null) $grossCacheCompleto   = false;
            if ($a['value'] === null) $accountCacheCompleto = false;
        }

        if (!$grossCacheCompleto || !$accountCacheCompleto) {
            \App\Jobs\RefreshGrossBillingCacheJob::dispatchIfQueued();
        }

        $revenue30dByCompany = [];
        $tacos30dByCompany   = [];

        if ($grossCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) {
                    $revenue30dByCompany[$c->id] = 0.0;
                    continue;
                }
                $revenue30dByCompany[$c->id] = (float) $grossBatch30d[$custId]['value'];
            }
        } else {
            $sumDb30d = AdmanMetric::query()
                ->whereIn('company_id', $companies->pluck('id'))
                ->whereBetween('reference_date', [$dateFrom30d, $dateTo30d])
                ->whereNotNull('revenue')
                ->selectRaw('company_id, SUM(revenue) as total')
                ->groupBy('company_id')
                ->pluck('total', 'company_id');

            foreach ($companies as $c) {
                $revenue30dByCompany[$c->id] = (float) ($sumDb30d[$c->id] ?? 0);
            }
            Log::info('[Dashboard] revenue 30d (userDashboard) em fallback DB (cache Adman incompleto)');
        }

        if ($accountCacheCompleto) {
            foreach ($companies as $c) {
                $custId = $c->cust_id;
                if (!$custId) continue;
                $tacos30dByCompany[$c->id] = $accountBatch30d[$custId]['value']['tacos'] ?? null;
            }
        }

        // 2026-07-13 — só o modelo PRINCIPAL alimenta os widgets do dashboard.
        // Phase 96 Plan 04 (AB-96-3 · call-site #6) — resposta invalidada
        // pelo admin some dos widgets NPS do dashboard do usuário.
        $npsResponses = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
            ->principal()
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();

        $meetings = Meeting::whereIn('company_id', $companies->pluck('id'))
            ->where('scheduled_at', '>=', $since)
            ->where('status', 'completed')
            ->get();

        $absenteeismRate = $meetings->count() > 0
            ? $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count() / $meetings->count() * 100
            : 0;

        $myNpsSurveys = NpsSurvey::with(['company', 'response'])
            ->where('generated_by', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $myPpas = $user->isMentor()
            ? Ppa::with('company')->where('mentor_id', $user->id)->orderBy('created_at', 'desc')->take(5)->get()
            : collect();

        // Phase 73 Plan 01 (SC#1) — taxonomia mantida (idem buildRanking acima):
        //   Estrategista (isMentor) → dimensao 'estrategista'; Analista → 'analista'.
        // Fonte de leitura mudou: NpsScoreCalculator para surveys v15
        // (template_id != null); fallback direto na coluna legacy score_* para
        // surveys pre-v15 (Phase 31 preservado, dual-path). Helper
        // avgNotaDimensao centraliza a logica.
        // 2026-07-13 — dimensão por CARGO canônico (não isMentor()).
        $dimensao   = $user->dimensaoNpsDesempenho();
        $scoreField = $dimensao === 'estrategista' ? 'score_estrategista' : 'score_analista';  // fallback legacy

        // Fase 116 Plan 05 · notas imputadas (não respondido = nota 1) da
        // MESMA carteira ($companies) e dimensão já usadas em stats.avg_nps
        // abaixo. $npsResponses acima só olha o modelo PRINCIPAL
        // (->principal()) — repassar o mesmo templateIds pra não misturar
        // modelos (mesmo racional do widget adminDashboard).
        $notasImputadasUsuario = $this->npsImputation->notasDaEmpresa(
            $companies->pluck('id'),
            $dimensao,
            $since,
            now(),
            null,
            collect([NpsTemplate::principalId()]),
        )->pluck('nota');

        // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no mês
        // também conta nota 1, mesma régua do bônus. MESMA carteira
        // ($companies) e dimensão já usadas acima; templateIds=[principalId()]
        // espelha o `->principal()` de $npsResponses (mesmo racional do
        // widget admin). Piso retroativo (DEC-09-B): leitura em JANELA
        // ROLANTE, nunca alcança mais que mês anterior + corrente.
        $notasSemLinkUsuario = $this->npsSemLink->notasDaEmpresaSemLink(
            $companies->pluck('id'),
            $dimensao,
            $since,
            now(),
            null,
            collect([NpsTemplate::principalId()]),
            $this->npsSemLink->pisoRetroativo(),
        )->pluck('nota');
        $notasImputadasUsuario = $notasImputadasUsuario->merge($notasSemLinkUsuario);

        // Phase 72 Plan 02 — SC#3: empresas pendentes de NPS restritas a
        // carteira do proprio usuario (forCarteira filtra por
        // $user->companies() quando nao e admin).
        $npsPendentes = $this->npsPending->forCarteira($user);

        return Inertia::render('Dashboard/User', [
            'stats' => [
                'total_companies' => $companies->count(),
                'avg_tacos' => round($metrics->avg('tacos') ?? 0, 2),
                'avg_nps' => $this->avgNotaDimensao($npsResponses, $dimensao, $scoreField, $notasImputadasUsuario),
                'absenteeism_rate' => round($absenteeismRate, 2),
                'total_revenue' => $metrics->sum('revenue'),
            ],
            'period' => $period,
            'companies' => $companies->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'tacos' => $tacos30dByCompany[$c->id] ?? $c->latestMetrics?->tacos,
                'revenue' => (float) ($revenue30dByCompany[$c->id] ?? 0),
                'goals' => $c->goals->where('active', true)->values(),
            ]),
            'my_surveys' => $myNpsSurveys,
            'my_ppas' => $myPpas,
            'sugadores_pendentes_carteira' => $sugadoresCarteira,
            // Phase 72 Plan 02 — SC#3: pendencias NPS da carteira propria
            // (widget renderizado em Plan 72-03).
            'nps_pendentes' => $npsPendentes,
        ]);
    }

    /**
     * Phase 73 Plan 01 (SC#1) — media da nota por dimensao com dual-path v15
     * vs legacy. Surveys v15 (template_id != null) leem via
     * NpsScoreCalculator::compute($response, $dimensao) respeitando o
     * template_snapshot; surveys legacy (Phase 31, template_id === null) caem
     * no fallback direto na coluna nps_responses.$scoreFieldLegacy.
     *
     * Escala 1-5 uniforme; round(1) preserva a precisao historica dos widgets
     * "Desempenho da equipe" (buildRanking) e "avg_nps" do userDashboard.
     *
     * Fase 116 Plan 05 — ganhou o 4º parâmetro opcional `$notasImputadas`:
     * quando informado, as notas do NPS efetivamente disparado e NÃO
     * respondido (nota 1 — `NpsImputationService`) são mescladas às notas
     * reais ANTES do `avg()`, preservando o `round(..., 1)` e a sentinela
     * `0.0` de coleção vazia.
     *
     * Fase 119.1 Plan 09 — `$notasImputadas` agora pode trazer notas de DOIS
     * ramos: as do `NpsImputationService` (Fase 116, survey disparado sem
     * resposta) E as do `NpsSemLinkService` (D1, empresa elegível sem
     * NENHUM link) — os chamadores (`buildRanking()`, `userDashboard()`)
     * concatenam os dois ANTES de passar pra cá. A assinatura deste método
     * NÃO muda: ele só soma o que recebe.
     *
     * @param  iterable<int, \App\Models\NpsSurvey>  $surveys
     * @param  string  $dimensao          Uma das constantes NpsTemplateQuestion::DIMENSOES
     * @param  string  $scoreFieldLegacy  Coluna legacy correspondente em nps_responses (fallback pre-v15)
     * @param  \Illuminate\Support\Collection|null  $notasImputadas  Notas (float) do não respondido — Fase 116
     * @return float  Media 1-5 com 1 decimal; 0.0 quando colecao vazia ou todas notas null.
     */
    private function avgNotaDimensao(iterable $surveys, string $dimensao, string $scoreFieldLegacy, ?Collection $notasImputadas = null): float
    {
        $calculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        $notas = collect($surveys)->map(function ($s) use ($calculator, $dimensao, $scoreFieldLegacy) {
            if ($s->template_id !== null && $s->response) {
                return $calculator->compute($s->response, $dimensao);
            }
            return $s->response?->$scoreFieldLegacy;
        })->filter(fn($n) => $n !== null);

        // Fase 116 Plan 05 — $notas já é Collection base (collect() global
        // sempre retorna Illuminate\Support\Collection, mesmo partindo de
        // Eloquent\Collection), então o merge() aqui é seguro sem cast extra.
        if ($notasImputadas !== null && $notasImputadas->isNotEmpty()) {
            $notas = $notas->merge($notasImputadas);
        }

        return $notas->isNotEmpty()
            ? round((float) $notas->avg(), 1)
            : 0.0;
    }
}
