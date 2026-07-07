<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\AdmanSyncLog;
use App\Models\Company;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\Servico;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(private AdmanService $adman) {}

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
     * Phase 58 DASH-03 — Shell Shopee. Renderiza direto (bypass pipeline)
     * para evitar KPIs zerados enganosos (CONTEXT §2).
     */
    public function shopee(Request $request)
    {
        return Inertia::render('Dashboard/ShopeeShell', [
            'marketplace' => 'shopee',
            'label'       => 'Shopee',
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
     * @return array{from: string, to: string}
     */
    private function getPeriodRange(string $period): array
    {
        $days = match ($period) {
            '1'   => 1,
            '7'   => 7,
            '30'  => 30,
            '180' => 180,
            default => 30,
        };

        return [
            'from' => now()->subDays($days)->toDateString(),
            'to'   => now()->toDateString(),
        ];
    }

    private function adminDashboard(Request $request, Carbon $since, string $period)
    {
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
        $companiesQuery = Company::with(['latestMetrics', 'consultor', 'estrategista'])
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
            ->where('reference_date', '>=', $since->toDateString())
            ->orderBy('reference_date')
            ->get();

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

        $revenueChart = collect();
        $tacosChart   = collect();
        $cursor = $since->copy()->startOfDay();
        $end    = now()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $lbl = $cursor->format('d/m');
            $revenueChart->push(['date' => $lbl, 'revenue' => (float) ($revByDate[$key] ?? 0)]);
            $tacosChart->push(['date' => $lbl, 'tacos' => (float) ($tacosByDate[$key] ?? 0)]);
            $cursor->addDay();
        }
        $revenueChart = $revenueChart->values();
        $tacosChart   = $tacosChart->values();

        $npsResponses = NpsSurvey::with('response')
            ->whereIn('company_id', $companies->pluck('id'))
            ->where('status', 'completed')
            ->where('completed_at', '>=', $since)
            ->get();

        // Phase 31 (Plan 05 — D-09): escala 1-5 substituiu 0-10. O card
        // 'NPS Médio' agora mostra a média de score_empresa (dimensao geral
        // "A ECF esta atendendo suas expectativas?" — D-07).
        $avgNps = $npsResponses->avg(fn($s) => $s->response?->score_empresa) ?? 0;

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

        $avgMargin = $metrics->avg('contribution_margin_pct') ?? 0;
        $productsWithoutCost = $metrics->avg(fn($m) => $m->products_without_cost_pct) ?? 0;

        $lastSyncDate = $metrics->max('reference_date');

        // Phase 31 (Plan 05 — D-09): mapeamento 1-5 → categorias do widget:
        //   score_empresa == 5    → promotor   (Excelente)
        //   score_empresa == 4    → neutro     (Bom)
        //   score_empresa 1-3     → detrator   (Ruim)
        // As chaves promotores/neutros/detratores são preservadas porque o
        // Pie de Dashboard/Admin.jsx ainda consome esse shape — labels
        // visuais são re-rotulados no JSX para "Excelente/Bom/Ruim".
        $npsDistribution = [
            'promotores' => $npsResponses->filter(fn($s) => (int) ($s->response?->score_empresa ?? 0) === 5)->count(),
            'neutros'    => $npsResponses->filter(fn($s) => (int) ($s->response?->score_empresa ?? 0) === 4)->count(),
            'detratores' => $npsResponses->filter(fn($s) => (int) ($s->response?->score_empresa ?? 0) >= 1
                                                        && (int) ($s->response?->score_empresa ?? 0) <= 3)->count(),
        ];

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

        // Quick 260623 — Performance da equipe: 1 score por membro do setor
        // Performance (analistas + estrategistas via cargo slug). Lider de setor
        // ve so a equipe que ele lidera; admin ve todos. Alimenta o widget que
        // substituiu NPS Distribuicao em Dashboard/Admin.jsx.
        $scoreService = app(\App\Services\PortfolioScoreService::class);
        $perfMembrosQuery = User::where('active', true)
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
        $perfMembros = $perfMembrosQuery->orderBy('name')->get(['id', 'name'])
            ->map(function ($u) use ($scoreService) {
                $r = $scoreService->compute($u);
                return [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'score'         => (float) $r['score'],
                    'classificacao' => $r['classificacao'],
                ];
            })
            ->sortByDesc('score')
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
            ],
            'revenue_chart'  => $revenueChart,
            'tacos_chart'    => $tacosChart,
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
            ],
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
            ]),
            'adman_last_sync' => $admanLastSync,
            // Phase 18 (W2-T3) — true quando cards Adman-dependentes refletem
            // valores exatos do cache /performance (period=30 + cache hot).
            // Frontend consome em W5-T1 para indicador "≈ aproximado".
            'cards_exatos'    => $cardsExatos,
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

            $surveys = NpsSurvey::with('response')
                ->whereIn('company_id', $companyIds)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $since)
                ->get();

            // Phase 31 (Plan 05) — taxonomia nova:
            //   isMentor() == true  → user é Estrategista → score_estrategista
            //   else                → user é Analista (consultor) → score_analista
            // Escala agora é 1-5 (era 0-10). round(1) preserva a precisao.
            $scoreField = $u->isMentor() ? 'score_estrategista' : 'score_analista';
            $avgNps = $surveys->count() > 0
                ? round($surveys->avg(fn($s) => $s->response?->$scoreField ?? 0), 1)
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

        $npsResponses = NpsSurvey::with('response')
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

        // Phase 31 (Plan 05) — taxonomia nova (idem buildRanking acima):
        //   Estrategista (isMentor) → score_estrategista; Analista → score_analista.
        $scoreField = $user->isMentor() ? 'score_estrategista' : 'score_analista';

        return Inertia::render('Dashboard/User', [
            'stats' => [
                'total_companies' => $companies->count(),
                'avg_tacos' => round($metrics->avg('tacos') ?? 0, 2),
                'avg_nps' => round($npsResponses->avg(fn($s) => $s->response?->$scoreField ?? 0) ?? 0, 1),
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
        ]);
    }
}
