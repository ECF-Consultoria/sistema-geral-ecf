<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\BonusInvalidacao;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\PortfolioGoal;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\FinancialSourceResolver;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Desempenho\CompanyScoreSnapshotReader;
use App\Services\Desempenho\WarmDesempenhoDispatcher;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsPendingService;
use App\Services\Portfolio\CarteiraContextService;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortfolioController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private DesempenhoScoreService $scoreService,
        private MetricsProviderFactory $metricsFactory,
        private CarteiraContextService $carteiraContext,
        private MetricPeriodResolver $periodResolver,
        private AdmanMetricDiffService $admanDiffService,
        private MetricDiffDispatcher $diffDispatcher,
        // Leitura pura de `desempenho_company_score_snapshots` — a MESMA fonte
        // que `Performance/Show` consome. A carteira nunca calcula score por
        // empresa ao vivo (ver `.planning/learnings/desempenho-bonificacao.md` §5).
        private CompanyScoreSnapshotReader $companyScoreReader,
        // Gate quente/frio do score (Fase 106, extraído em 2026-08-07): esta
        // tela NUNCA computa desempenho frio de forma síncrona — 110s medidos.
        private WarmDesempenhoDispatcher $warmDispatcher,
        // Fase 136 (D-10) — fonte ÚNICA do desempate de fonte financeira.
        private FinancialSourceResolver $financialSourceResolver,
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

    /**
     * Fase 90 (CART-07) — parse do filtro de contexto `?contexto=` com
     * whitelist explicita (ASVS V5). Nomeado `contexto` (nao `setor`) de
     * proposito: `renderCarteirasConsolidadas()` ja usa `$setoresFiltro` pra
     * outro conceito (setor ORGANIZACIONAL do profissional, via user_setores)
     * — nao confundir com o setor do SERVICO/vinculo filtrado aqui (90-RESEARCH.md
     * "Duas nocoes de 'setor'"). Valor fora da whitelist cai em 'todos', nunca
     * repassado cru ao CarteiraContextService.
     *
     * @return array{param: string, setor: ?string}
     */
    private function contextoFiltro(Request $request): array
    {
        return match ($request->query('contexto')) {
            'performance' => ['param' => 'performance', 'setor' => Servico::SETOR_PERFORMANCE],
            'shopee'      => ['param' => 'shopee', 'setor' => Servico::SETOR_SHOPEE],
            default       => ['param' => 'todos', 'setor' => null],
        };
    }

    /**
     * Fase 109 (SHOP-CAR-01/02) — resolve a fonte financeira VENCEDORA de
     * cada empresa a partir dos vínculos ELEGÍVEIS do profissional (não do
     * vínculo bruto). REGRA DE DESEMPATE — atualizada pela Fase 136 (D-10,
     * 2026-08-11), substituindo o texto travado em 2026-07-23: quando a
     * MESMA empresa tem vínculo performance elegível E vínculo shopee
     * elegível do mesmo profissional, a fonte vencedora é 'adman' **só
     * quando a empresa tem `cust_id`** (conta Adman de fato) — nunca soma as
     * duas. Sem `cust_id`, 'shopee' vence. Antes desta fase 'adman' vencia
     * incondicionalmente, e a mesma empresa aparecia com marketplace
     * divergente entre esta tela e o Desempenho (ver
     * `.planning/learnings/desempenho-bonificacao.md` §0.04). A regra vive
     * em `FinancialSourceResolver` — fonte ÚNICA, também usada por
     * `CompanyScoreService::computeEmpresasScore()` e
     * `DesempenhoScoreService::computeUniverso()`.
     *
     * Única query nova de toda a correção de D-10: os outros 2 call-sites já
     * carregavam `Company` para outro propósito; este método não carregava
     * nenhum antes.
     *
     * @param  Collection  $vinculos  Vínculos já resolvidos por CarteiraContextService::forUser().
     * @return Collection<int, string>  company_id => 'adman'|'shopee'
     */
    private function fontesFinanceirasPorEmpresa(Collection $vinculos): Collection
    {
        $vinculosElegiveis = $vinculos->where('financial_metrics_eligible', true);
        $companyIds        = $vinculosElegiveis->pluck('company_id')->unique();

        $companies = Company::whereIn('id', $companyIds)
            ->get(['id', 'adman_account_id', 'ml_store_id'])
            ->keyBy('id');

        return $this->financialSourceResolver->resolverPorEmpresa($vinculosElegiveis, $companies);
    }

    /**
     * Fase 109 — vínculos ajustados pra `CarteiraContextService::contadores()`
     * de DISPLAY (chip "X vínculo(s) sem fonte financeira" / resumo da
     * carteira): Shopee SEMPRE conta como "sem fonte financeira" aqui — MESMA
     * regra de display já aplicada em `servicos[].financial_metrics_eligible`
     * (regressão travada — `CarteirasConsolidadasContextoTest::
     * test_resumo_individual_expoe_contadores_de_vinculos`), mesmo sendo a
     * única fonte da empresa. `CarteiraContextService` (Plano 01) NÃO muda —
     * só esta leitura local ajusta o que o controller repassa a `contadores()`.
     *
     * @param  Collection  $vinculos
     * @return Collection
     */
    private function vinculosParaContadoresDisplay(Collection $vinculos): Collection
    {
        return $vinculos->map(function (array $v) {
            if ($v['setor'] === Servico::SETOR_SHOPEE) {
                $v['financial_metrics_eligible'] = false;
            }
            return $v;
        });
    }

    /**
     * Fase 109 — dentre os `$companyIds` informados, quais têm QUALQUER
     * linha histórica em `shopee_metrics` (não só na janela do período).
     * Necessário pra distinguir "revenue Shopee genuinamente zero na janela"
     * de "empresa nunca sincronizou Shopee" — sem isso, `SUM(revenue)` sem
     * NENHUMA linha devolve 0.0 (indistinguível de dado ausente na UI, que
     * precisa mostrar "—", não "R$ 0,00").
     *
     * @param  Collection  $companyIds
     * @return Collection<int, int>  company_ids com ao menos 1 linha em shopee_metrics.
     */
    private function companyIdsComDadosShopee(Collection $companyIds): Collection
    {
        if ($companyIds->isEmpty()) {
            return collect();
        }

        return ShopeeMetric::whereIn('company_id', $companyIds)->distinct()->pluck('company_id');
    }

    // Carteira individual de um profissional. Acesso (quick 260623):
    //  - Admin: qualquer user (compat).
    //  - Líder de setor: somente users vinculados (user_setores) ao(s) setor(es)
    //    que ele lidera.
    //  - Próprio user (auto-visualização também funciona).
    //
    // Ajuste 2026-07-09 (v3) — Admin/Líder abrindo carteira de OUTRO profissional
    // vai para a nova página `Portfolio/AdminCarteira.jsx`, enxuta e focada nos
    // dados que o admin precisa (total faturamento, variação margem, listagem
    // de empresas com badge ML + variação % individual). Portfolio/Show.jsx
    // legada (1000+ linhas de shape v1 + widgets do próprio profissional) fica
    // reservada só para auto-visualização (`/portfolio`).
    public function show(Request $request, User $user)
    {
        $atual = $request->user();
        $autorizado = $atual->isAdmin()
            || $atual->id === $user->id
            || (
                $atual->isLider()
                && DB::table('user_setores as us')
                    ->whereIn('us.setor_id', $atual->setoresLiderados()->pluck('setores.id'))
                    ->where('us.user_id', $user->id)
                    ->exists()
            );
        abort_unless($autorizado, 403);

        // Admin/líder abrindo OUTRO profissional → view enxuta dedicada.
        if ($atual->id !== $user->id) {
            return $this->renderCarteiraProfissional($request, $user);
        }

        return $this->renderPortfolio($request, $user);
    }

    /**
     * Fase 3 do plano de otimização (2026-07-21) — CARTEIRA DE TRANSPARÊNCIA
     * (§8.3): uma linha por empresa com fonte, faturamento, margem R$, margem %,
     * variações (badges), status de qualidade e invalidação — tudo alinhado ao
     * contrato de período (`?modo=`/`?mes=`) e SEM misturar fonte.
     *
     * Página NOVA e ADITIVA (Portfolio/Transparencia) — NÃO altera as telas de
     * carteira existentes (renderPortfolio/renderCarteiraProfissional). O usuário
     * avalia antes de decidir se ela vira a carteira canônica.
     *
     * Fonte única dos números: `AdmanMetricDiffService::compute()`, que devolve
     * `revenue`/`contribution_margin_value`/`contribution_margin_pct` — cada um
     * com `value` + `diff_pct` + `diff_source` — de fonte CONSISTENTE nas duas
     * janelas (nunca ML no atual e Adman no baseline). `contribution_margin_pct`
     * é a margem-como-%-da-receita (percentageMargin); `contribution_margin_value`
     * é o valor em R$ (profitMargin) — os dois expostos e rotulados na tela.
     */
    public function transparencia(Request $request, User $user): \Inertia\Response
    {
        $atual = $request->user();
        $autorizado = $atual->isAdmin()
            || $atual->id === $user->id
            || (
                $atual->isLider()
                && DB::table('user_setores as us')
                    ->whereIn('us.setor_id', $atual->setoresLiderados()->pluck('setores.id'))
                    ->where('us.user_id', $user->id)
                    ->exists()
            );
        abort_unless($autorizado, 403);

        // ── Período — MESMO contrato do ranking/carteira (?modo=/?mes=) ───────
        $modo = $request->query('modo');
        if (! in_array($modo, ['em_curso', 'bonus_atual'], true)) {
            $modo = null;
        }
        $mesQuery    = $request->query('mes');
        $mesCorrente = now()->startOfMonth();

        if ($modo === 'bonus_atual') {
            $periodo = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
            $mesSelecionado = Carbon::parse($periodo['bonus_competence_month'] . '-01')->startOfMonth();
        } elseif ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            $mesSelecionado = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
            $periodo = $this->periodResolver->resolve(['period_key' => $mesSelecionado->format('Y-m')]);
        } else {
            $mesSelecionado = $mesCorrente->copy();
            $periodo = $this->periodResolver->resolve(['period_key' => 'current_month']);
        }
        $ehMesEmCurso = $mesSelecionado->equalTo($mesCorrente);

        $bonusMeta = null;
        if ($periodo['is_closed']) {
            $bonusMeta = [
                'competence_month' => $periodo['bonus_competence_month'] ?? $mesSelecionado->format('Y-m'),
                'payment_month'    => $periodo['bonus_payment_month'] ?? $mesSelecionado->copy()->addMonthNoOverflow()->format('Y-m'),
            ];
        }

        // ── Vínculos (contexto de serviço) + invalidadas da competência ──────
        $contextoFiltro = $this->contextoFiltro($request);
        $vinculos       = $this->carteiraContext->forUser($user, ['active' => true, 'setor' => $contextoFiltro['setor']]);
        $porEmpresa     = $vinculos->groupBy('company_id');
        $invalidadas    = BonusInvalidacao::companyIdsInvalidadas($mesSelecionado);

        // Fase 109 (SHOP-CAR-01/02) — fonte financeira VENCEDORA por empresa
        // (desempate travado: adman vence quando a MESMA empresa tem vínculo
        // performance E shopee elegíveis do mesmo profissional).
        $fontesPorEmpresa        = $this->fontesFinanceirasPorEmpresa($vinculos);
        $companyIdsShopee        = $fontesPorEmpresa->filter(fn ($f) => $f === 'shopee')->keys();
        $companyIdsComDadosShopee = $this->companyIdsComDadosShopee($companyIdsShopee);

        $companies = Company::whereIn('id', $porEmpresa->keys())
            ->with('mlToken')
            ->orderBy('name')
            ->get();

        $empresas = $companies->map(function ($c) use ($porEmpresa, $invalidadas, $periodo, $fontesPorEmpresa, $companyIdsComDadosShopee) {
            $vs         = $porEmpresa->get($c->id, collect());
            $ehElegivel = $vs->where('financial_metrics_eligible', true)->isNotEmpty();
            $invalidada = $invalidadas->contains($c->id);
            $fonteFinanceiraVencedora = $fontesPorEmpresa->get($c->id);

            // Fase 109 — 'elegivel' preserva o significado v17 (mesma regra
            // de renderCarteiraProfissional): vínculo Shopee sempre false
            // aqui, mesmo quando é a única fonte da empresa.
            $servicos = $vs->map(fn ($v) => [
                'servico_nome' => $v['servico_nome'],
                'setor'        => $v['setor'],
                'role_label'   => $v['role_label'],
                'elegivel'     => $v['financial_metrics_eligible'] && $v['setor'] !== Servico::SETOR_SHOPEE,
            ])->values();

            // Fonte de dados COMBINADA da empresa (badge §8.3) — reflete de onde
            // vêm os números: ML OAuth, Adman (adman_account_id), ambos, só
            // Shopee (vínculo shopee sem fonte financeira) ou sem fonte alguma.
            $temMl     = (bool) ($c->mlToken && $c->mlToken->status === 'active');
            $temAdman  = ! empty($c->adman_account_id);
            $temShopee = $vs->contains(fn ($v) => ($v['setor'] ?? null) === 'shopee');
            // Quick 260724-dho — o badge tem que refletir a fonte do VÍNCULO
            // do profissional ($fonteFinanceiraVencedora), não a plataforma
            // GLOBAL da empresa. Sem essa checagem primeiro, uma empresa com
            // mlToken ativo (de OUTRO profissional/serviço) fazia o badge
            // mostrar ML mesmo quando este profissional só presta Shopee.
            if ($fonteFinanceiraVencedora === 'shopee') {
                $fonte = 'shopee';
            } elseif ($ehElegivel && ($temMl || $temAdman)) {
                $fonte = ($temMl && $temAdman) ? 'ml_adman' : ($temMl ? 'ml' : 'adman');
            } elseif ($temShopee) {
                $fonte = 'shopee';
            } else {
                // Elegível mas sem ML/Adman conectado (ex.: empresa nova) OU sem
                // vínculo financeiro — nas duas situações não há fonte de dados.
                $fonte = 'sem_fonte';
            }

            $base = [
                'id'           => $c->id,
                'name'         => $c->name,
                // Idem — não pinta o SVG do ML quando a fonte vencedora do
                // vínculo é Shopee, mesmo com mlToken global ativo.
                'has_ml_oauth' => $temMl && $fonteFinanceiraVencedora !== 'shopee',
                'servicos'     => $servicos,
                'fonte'        => $fonte,
                'invalidada'   => $invalidada,
            ];

            // Empresa sem vínculo financeiro elegível (ex.: só-Shopee): não entra
            // no financeiro — status 'sem_fonte' (não é problema de sync).
            if (! $ehElegivel) {
                return $base + [
                    'faturamento'         => null,
                    'faturamento_var_pct' => null,
                    'margem_rs'           => null,
                    'margem_rs_var_pct'   => null,
                    'margem_pct'          => null,
                    'margem_pct_var_pct'  => null,
                    'diff_source'         => null,
                    'status'              => 'sem_fonte',
                ];
            }

            // Fase 109 — Shopee: faturamento+investimento via ShopeeMetricDiffService
            // (roteado pelo dispatcher), margem sempre null (arquitetura future-ready).
            // 'sem_dados' quando a empresa nunca sincronizou Shopee (distingue de
            // "revenue zero real na janela") — nunca cai em 'sem_fonte' (isso é só
            // pra empresa SEM vínculo elegível, gate acima).
            if ($fonteFinanceiraVencedora === 'shopee') {
                $temDados = $companyIdsComDadosShopee->contains($c->id);
                if (! $temDados) {
                    return $base + [
                        'faturamento'         => null,
                        'faturamento_var_pct' => null,
                        'margem_rs'           => null,
                        'margem_rs_var_pct'   => null,
                        'margem_pct'          => null,
                        'margem_pct_var_pct'  => null,
                        'diff_source'         => null,
                        'status'              => 'sem_dados',
                    ];
                }

                $r      = $this->diffDispatcher->compute($c, $periodo, 'shopee');
                $rev    = $r['metrics']['revenue'] ?? [];
                $revVal = $rev['value'] ?? null;

                return $base + [
                    'faturamento'         => $revVal !== null ? round((float) $revVal, 2) : null,
                    'faturamento_var_pct' => $rev['diff_pct'] ?? null,
                    'margem_rs'           => null,
                    'margem_rs_var_pct'   => null,
                    'margem_pct'          => null,
                    'margem_pct_var_pct'  => null,
                    'diff_source'         => $rev['diff_source'] ?? null,
                    'status'              => $invalidada ? 'invalidada' : (($rev['diff_pct'] ?? null) === null ? 'sem_baseline' : 'completo'),
                ];
            }

            $r    = $this->diffDispatcher->compute($c, $periodo, $fonteFinanceiraVencedora ?? 'adman');
            $rev  = $r['metrics']['revenue'] ?? [];
            $mRs  = $r['metrics']['contribution_margin_value'] ?? [];
            $mPct = $r['metrics']['contribution_margin_pct'] ?? [];

            $revVal = $rev['value'] ?? null;
            $status = $invalidada
                ? 'invalidada'
                : ($revVal === null
                    ? 'sem_dados'
                    : ((($rev['diff_pct'] ?? null) === null && ($mRs['diff_pct'] ?? null) === null)
                        ? 'sem_baseline'
                        : ((($mPct['value'] ?? null) === null) ? 'parcial' : 'completo')));

            return $base + [
                'faturamento'         => $revVal !== null ? round((float) $revVal, 2) : null,
                'faturamento_var_pct' => $rev['diff_pct'] ?? null,
                'margem_rs'           => isset($mRs['value']) ? round((float) $mRs['value'], 2) : null,
                'margem_rs_var_pct'   => $mRs['diff_pct'] ?? null,
                'margem_pct'          => isset($mPct['value']) ? round((float) $mPct['value'], 2) : null,
                'margem_pct_var_pct'  => $mPct['diff_pct'] ?? null,
                'diff_source'         => $rev['diff_source'] ?? ($mRs['diff_source'] ?? null),
                'status'              => $status,
            ];
        })->values();

        // Meses do filtro (últimos 6) — o filtro é SÓ o mês: mês corrente = em
        // curso; mês passado = base do bônus (o usuário deduz pelo mês).
        $mesesDisponiveis = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $mesCorrente->copy()->subMonths($i);
            $mesesDisponiveis[] = [
                'value'    => $m->format('Y-m'),
                'label'    => mb_strtolower($m->translatedFormat('F/Y')),
                'em_curso' => $m->equalTo($mesCorrente),
            ];
        }

        return Inertia::render('Portfolio/Transparencia', [
            'profissional' => ['id' => $user->id, 'name' => $user->name],
            'modo'         => $modo,
            'contexto'     => $contextoFiltro['param'],
            'empresas'     => $empresas,
            'meses_disponiveis' => $mesesDisponiveis,
            'resumo'       => [
                'total_empresas'    => $empresas->count(),
                'total_faturamento' => round((float) $empresas->sum(fn ($e) => $e['faturamento'] ?? 0), 2),
                'total_margem_rs'   => round((float) $empresas->sum(fn ($e) => $e['margem_rs'] ?? 0), 2),
                'invalidadas'       => $empresas->where('invalidada', true)->count(),
                'por_status'        => $empresas->countBy('status'),
            ],
            'periodo' => [
                'mes_selecionado' => $mesSelecionado->format('Y-m'),
                'em_curso'        => $ehMesEmCurso,
                'current_start'   => $periodo['current_start'],
                'current_end'     => $periodo['current_end'],
                'baseline_start'  => $periodo['baseline_start'],
                'baseline_end'    => $periodo['baseline_end'],
                'comparison_mode' => $periodo['comparison_mode'],
                'is_closed'       => $periodo['is_closed'],
            ],
            'bonus' => $bonusMeta,
        ]);
    }

    /**
     * Página enxuta da carteira de um profissional (analista/estrategista).
     *
     * Usada por 2 fluxos:
     *  - Admin/líder abrindo carteira de OUTRO profissional em /admin/users/{id}/portfolio
     *  - Profissional abrindo a PRÓPRIA carteira em /portfolio (ajuste 2026-07-09 v5 —
     *    antes ia pro Portfolio/Show.jsx legado que estava com tela preta)
     *
     * Requisitos explícitos (2026-07-09):
     *  - Total de faturamento somado de todas empresas em carteira
     *  - Variação % da margem de contribuição vs mesmo intervalo mês anterior
     *  - Listagem de empresas com faturamento, badge ML SVG quando OAuth ativo,
     *    e % variação de margem individual vs mesmo intervalo mês anterior
     *
     * Comparação SEMPRE dia-a-dia acumulada (janela justa mesmo em mês em curso).
     */
    private function renderCarteiraProfissional(Request $request, User $user): \Inertia\Response
    {
        // Ajuste 2026-07-09 — filtro de mês (?mes=YYYY-MM). Permite ao dono
        // da empresa auditar meses FECHADOS pós-consolidação de bônus. Quando
        // ausente, usa o mês em curso (comportamento original).
        //
        // Fase 103 (CAR-01) — período resolvido via `MetricPeriodResolver`
        // (Fase 100), ÚNICO ponto de resolução de período do núcleo. Nenhum
        // `now()`/`subMonth()`/`endOfMonth()` inline pra montar a janela
        // (T-103-01 — a whitelist regex abaixo roda ANTES de repassar
        // `$mesSelecionado` ao resolver, nunca a string crua da query).
        // `?mes=YYYY-MM` é traduzido 1:1 pro `period_key` `closed_period`;
        // ausente ou igual ao mês corrente usa `current_month` (mesma regra
        // de comparação dia-a-dia acumulada de antes da migração).
        // Fase 104 (UIP-02/UIP-03, blocker 2 do plan-check) — `?modo=bonus_atual`
        // é SIMÉTRICO ao PerformanceController (Plan 104-01 Task 1): sem isso,
        // o toggle "Bônus atual" cairia no mês em curso rotulado como bônus.
        // `?modo=` é conveniência de UI que decide QUAL mês vira `$mesSelecionado`;
        // não cria segundo score — só decide a janela repassada aos cálculos
        // já existentes abaixo.
        $modo = $request->query('modo');
        if (!in_array($modo, ['em_curso', 'bonus_atual'], true)) {
            $modo = null;
        }

        $hoje         = now();
        $mesQuery     = $request->query('mes');
        $mesCorrente  = $hoje->copy()->startOfMonth();

        if ($modo === 'bonus_atual') {
            $periodo = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
            $mesSelecionado = Carbon::parse($periodo['bonus_competence_month'] . '-01')->startOfMonth();
        } elseif ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            $mesSelecionado = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
            $periodo = $this->periodResolver->resolve(['period_key' => $mesSelecionado->format('Y-m')]);
        } else {
            $mesSelecionado = $mesCorrente->copy();
            $periodo = $this->periodResolver->resolve(['period_key' => 'current_month']);
        }
        $ehMesEmCurso = $mesSelecionado->equalTo($mesCorrente);

        // `bonus` só existe quando o período resolvido é FECHADO — mesma regra
        // do PerformanceController::index() (Plan 104-01 Task 1). `last_closed_month`
        // já popula bonus_competence_month/bonus_payment_month; `closed_period`
        // (?mes=YYYY-MM específico) não popula essas chaves, então deriva da
        // própria competência selecionada + mês seguinte (pagamento).
        $bonusMeta = null;
        if ($periodo['is_closed']) {
            $bonusMeta = [
                'competence_month' => $periodo['bonus_competence_month'] ?? $mesSelecionado->format('Y-m'),
                'payment_month'    => $periodo['bonus_payment_month'] ?? $mesSelecionado->copy()->addMonthNoOverflow()->format('Y-m'),
            ];
        }

        $inicioMes   = Carbon::parse($periodo['current_start']);
        $fimMes      = Carbon::parse($periodo['current_end']);
        $inicioAnter = Carbon::parse($periodo['baseline_start']);
        $fimAnter    = Carbon::parse($periodo['baseline_end']);

        $dateFrom     = $periodo['current_start'];
        $dateTo       = $periodo['current_end'];
        $dateFromPrev = $periodo['baseline_start'];
        $dateToPrev   = $periodo['baseline_end'];

        // Fase 90 (CART-07) — filtro `?contexto=` (todos/performance/shopee),
        // vale pras DUAS telas de carteira (SC3). Default 'todos' preserva
        // 100% do comportamento da Fase 89 (regressao zero).
        $contextoFiltro = $this->contextoFiltro($request);

        // Fase 89 (CART-01/02/03/04/05) — origem por VÍNCULO via
        // CarteiraContextService, não mais `$user->companies()` (consolidado,
        // sem distinguir setor de serviço). `forUser()` é a ÚNICA porta pra
        // resolver vínculos — nunca reimplementar o join direto em
        // `company_users.servico_id` aqui (perde o ramo legado CTX-05).
        $vinculos   = $this->carteiraContext->forUser($user, ['active' => true, 'setor' => $contextoFiltro['setor']]);
        $porEmpresa = $vinculos->groupBy('company_id');

        // O service não expõe cust_id/mlToken (granularidade de vínculo, não
        // de empresa) — carregamos os models pra exibição separadamente.
        $rawCompanies = Company::whereIn('id', $porEmpresa->keys())
            ->with('mlToken')
            ->orderBy('name')
            ->get();

        // 2026-08-07 — gate da TABELA (camada distinta da nota, gateada mais
        // abaixo). O laço de montagem chama `MetricDiffDispatcher::compute()`
        // por empresa, que é HTTP síncrono à Adman: 157s medidos para as 25
        // empresas do user 20 numa competência fria (19 delas acima de 1s
        // cada). Blindar só a nota deixou esta página levando 115s — foi a
        // medição PÓS-DEPLOY que expôs este segundo fan-out.
        //
        // Zerar `$rawCompanies` é o que efetivamente evita o custo: todo o
        // pipeline abaixo (custIds → getCachedGrossBillingsMany → map com o
        // dispatcher) passa a operar sobre coleção vazia, sem tocar a rede, e
        // o shape das props continua válido. O front mostra "calculando…" no
        // lugar da tabela e polla.
        $aquecendoCarteira = $this->warmDispatcher->gateCarteira($rawCompanies, $periodo, $mesSelecionado);
        if ($aquecendoCarteira) {
            $rawCompanies = collect();
        }

        // Dedup financeiro (CART-04/05): a lista de company_id consultada em
        // AdmanMetric contém SÓ empresas com AO MENOS UM vínculo elegível do
        // profissional, e ->unique() garante que uma empresa com 2 vínculos
        // elegíveis do MESMO profissional entra 1x — AdmanMetric é por-EMPRESA,
        // nunca por-vínculo (ver 89-RESEARCH.md §Algoritmo de dedup financeiro).
        $companyIdsElegiveis = $vinculos
            ->where('financial_metrics_eligible', true)
            ->pluck('company_id')
            ->unique()
            ->values();

        // Fase 109 (SHOP-CAR-01/02) — fonte financeira VENCEDORA por empresa
        // (desempate travado). Só empresas 'adman' entram nas queries
        // AdmanMetric abaixo — empresa 'shopee' é lida à parte (dispatcher),
        // nunca herda revenue/margem/ad_spend do Adman.
        $fontesPorEmpresa           = $this->fontesFinanceirasPorEmpresa($vinculos);
        $companyIdsAdminElegiveis   = $companyIdsElegiveis->filter(fn ($id) => $fontesPorEmpresa->get($id) === 'adman')->values();
        $companyIdsShopeeElegiveis  = $companyIdsElegiveis->filter(fn ($id) => $fontesPorEmpresa->get($id) === 'shopee')->values();
        $companyIdsComDadosShopee   = $this->companyIdsComDadosShopee($companyIdsShopeeElegiveis);

        // Metrics agregados por empresa nas duas janelas (Adman é canônico
        // pra margem — ML não expõe custo unitário).
        //
        // Ajuste 2026-07-09 (zero-margin): SUM(contribution_margin) devolve
        // NULL quando não há linhas (empresa sem sync Adman no período) e
        // devolve 0 quando as linhas existem mas o campo está null/0 em todas.
        // O cast para float trata ambos como 0.0 — indistinguível na UI.
        // Fix: contar quantas linhas TÊM contribution_margin não-null via
        // `margem_dias` — se 0, tratamos como "sem dados" (null na UI, "—").
        //
        // CART-03 — SUM(ad_spend) adicionado à janela ATUAL (campo novo nesta
        // fase; a janela anterior não precisa de ad_spend, só serve pra
        // variação de margem/revenue).
        $atualPorEmpresa = AdmanMetric::whereIn('company_id', $companyIdsAdminElegiveis)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $anteriorPorEmpresa = AdmanMetric::whereIn('company_id', $companyIdsAdminElegiveis)
            ->whereBetween('reference_date', [$dateFromPrev, $dateToPrev])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Fase 103 (CAR-02) — o recorte por dias-comuns (fix Tomelin/
        // LOJASINVAL/AVF2K) e o cálculo de variação de margem migraram pra
        // dentro do `AdmanMetricDiffService::compute()` (Fase 101), chamado
        // por empresa elegível mais abaixo — os MESMOS guards, agora só
        // num lugar (não mais duplicado aqui). Ver bloco "Variação de
        // margem" dentro do `map()` abaixo.

        // Cache Adman gross + account metrics (fonte preferencial de revenue/
        // ad_spend/tacos no mês atual — mais completa que SUM DB local;
        // hotfix 2026-06-19). CART-03 — restrito a $custIdsElegiveis (só
        // empresas com vínculo financeiro elegível), mesmo padrão de
        // renderCarteirasConsolidadas() no mesmo arquivo.
        $custIdsElegiveis = $rawCompanies
            ->filter(fn ($c) => $companyIdsAdminElegiveis->contains($c->id))
            ->map(fn ($c) => $c->cust_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $grossAtual   = $this->adman->getCachedGrossBillingsMany($custIdsElegiveis, $dateFrom, $dateTo);
        $accountAtual = $this->adman->getCachedAccountMetricsMany($custIdsElegiveis, $dateFrom, $dateTo);

        // Fase 3 (2026-07-21) — empresas invalidadas para bônus na competência
        // (alimenta o status/badge da tabela, igual à transparência).
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mesSelecionado);

        $empresas = $rawCompanies->map(function ($c) use ($atualPorEmpresa, $anteriorPorEmpresa, $grossAtual, $accountAtual, $companyIdsElegiveis, $porEmpresa, $periodo, $invalidadas, $fontesPorEmpresa, $companyIdsComDadosShopee) {
            $ehElegivel = $companyIdsElegiveis->contains($c->id);
            $fonteFinanceiraVencedora = $fontesPorEmpresa->get($c->id);

            // Vínculos desta empresa — shape público CART-01/02: 1 entrada
            // por vínculo de serviço (ex.: Performance + Shopee separados).
            // Fase 109 (regressão travada — CarteiraIndividualContextoTest/
            // CarteiraPeriodoDiffTest) — 'financial_metrics_eligible' aqui
            // preserva o significado v17: vínculo Shopee é SEMPRE exibido
            // como false neste array (elegibilidade financeira "completa",
            // com margem, nunca existiu pra Shopee) — mesmo quando é a ÚNICA
            // fonte da empresa. O financeiro real da empresa Shopee (sem
            // margem) é decidido por `$fonteFinanceiraVencedora` mais abaixo,
            // não por este campo de display.
            $servicos = $porEmpresa->get($c->id, collect())->map(fn ($v) => [
                'servico_id'                  => $v['servico_id'],
                'servico_nome'                => $v['servico_nome'],
                'setor'                       => $v['setor'],
                'role'                        => $v['role'],
                'role_label'                  => $v['role_label'],
                'financial_metrics_eligible'  => $v['financial_metrics_eligible'] && $v['setor'] !== Servico::SETOR_SHOPEE,
            ])->values();

            // Fase 3 (2026-07-21) — fonte de dados COMBINADA + invalidação, os
            // MESMOS campos da tabela de transparência (aditivo — as chaves
            // antigas seguem intactas para os testes/consumidores existentes).
            $temMl     = (bool) ($c->mlToken && $c->mlToken->status === 'active');
            $temAdman  = ! empty($c->adman_account_id);
            $temShopee = $servicos->contains(fn ($s) => ($s['setor'] ?? null) === 'shopee');
            // Quick 260724-dho — mesma correção da transparencia(): o badge
            // reflete a fonte do VÍNCULO ($fonteFinanceiraVencedora), não a
            // plataforma global da empresa (evita mostrar ML quando este
            // profissional só presta Shopee pra empresa).
            if ($fonteFinanceiraVencedora === 'shopee') {
                $fonte = 'shopee';
            } elseif ($ehElegivel && ($temMl || $temAdman)) {
                $fonte = ($temMl && $temAdman) ? 'ml_adman' : ($temMl ? 'ml' : 'adman');
            } elseif ($temShopee) {
                $fonte = 'shopee';
            } else {
                $fonte = 'sem_fonte';
            }
            $invalidada = $invalidadas->contains($c->id);
            // Idem has_ml_oauth — não pinta o SVG do ML quando a fonte
            // vencedora do vínculo deste profissional é Shopee.
            $temMlBadge = $temMl && $fonteFinanceiraVencedora !== 'shopee';

            // CART-04 — empresa SEM vínculo elegível (ex.: profissional só
            // responde por Shopee nessa empresa): financeiro inteiro null.
            // NÃO é problema de qualidade de sync — a UI deriva "sem fonte
            // financeira" dos vínculos, não polui o banner de motivo_sem_margem.
            if (! $ehElegivel) {
                return [
                    'id'                           => $c->id,
                    'name'                         => $c->name,
                    'faturamento'                  => null,
                    'margem_contribuicao'          => null,
                    'margem_contribuicao_anterior' => null,
                    'margem_variacao_pct'          => null,
                    'motivo_sem_margem'            => null,
                    'ad_spend'                     => null,
                    'tacos'                        => null,
                    'has_ml_oauth'                 => $temMlBadge,
                    'servicos'                     => $servicos,
                    // Campos §8.3 (aditivo)
                    'fonte'                        => $fonte,
                    'faturamento_var_pct'          => null,
                    'margem_rs'                    => null,
                    'margem_rs_var_pct'            => null,
                    'margem_pct'                   => null,
                    'margem_pct_var_pct'           => null,
                    'status'                       => 'sem_fonte',
                    'invalidada'                   => $invalidada,
                ];
            }

            // Fase 109 (SHOP-CAR-01/02) — empresa cuja fonte financeira
            // VENCEDORA é 'shopee': faturamento+investimento via
            // ShopeeMetricDiffService (dispatcher), margem sempre null
            // (Shopee ainda não fornece). 'sem_dados' quando a empresa nunca
            // sincronizou Shopee (distingue de "revenue zero real na
            // janela") — NUNCA cai em 'sem_fonte' (isso é só pra empresa sem
            // vínculo elegível, gate acima).
            if ($fonteFinanceiraVencedora === 'shopee') {
                $temDadosShopee = $companyIdsComDadosShopee->contains($c->id);

                if (! $temDadosShopee) {
                    return [
                        'id'                           => $c->id,
                        'name'                         => $c->name,
                        'faturamento'                  => null,
                        'margem_contribuicao'          => null,
                        'margem_contribuicao_anterior' => null,
                        'margem_variacao_pct'          => null,
                        'motivo_sem_margem'            => 'Shopee ainda não fornece margem',
                        'ad_spend'                     => null,
                        'tacos'                        => null,
                        'has_ml_oauth'                 => $temMlBadge,
                        'servicos'                     => $servicos,
                        'fonte'                        => $fonte,
                        'faturamento_var_pct'          => null,
                        'margem_rs'                    => null,
                        'margem_rs_var_pct'            => null,
                        'margem_pct'                   => null,
                        'margem_pct_var_pct'           => null,
                        'status'                       => 'sem_dados',
                        'invalidada'                   => $invalidada,
                    ];
                }

                $rShopee   = $this->diffDispatcher->compute($c, $periodo, 'shopee');
                $revShopee = $rShopee['metrics']['revenue'] ?? [];
                $invShopee = $rShopee['investment'] ?? [];
                $revValShopee = $revShopee['value'] ?? null;
                $adSpendShopee = $invShopee['value'] ?? null;

                return [
                    'id'                           => $c->id,
                    'name'                         => $c->name,
                    'faturamento'                  => $revValShopee !== null ? round((float) $revValShopee, 2) : null,
                    'margem_contribuicao'          => null,
                    'margem_contribuicao_anterior' => null,
                    'margem_variacao_pct'          => null,
                    'motivo_sem_margem'            => 'Shopee ainda não fornece margem',
                    'ad_spend'                     => $adSpendShopee !== null ? round((float) $adSpendShopee, 2) : null,
                    'tacos'                        => null,
                    'has_ml_oauth'                 => $temMlBadge,
                    'servicos'                     => $servicos,
                    'fonte'                        => $fonte,
                    'faturamento_var_pct'          => $revShopee['diff_pct'] ?? null,
                    'margem_rs'                    => null,
                    'margem_rs_var_pct'            => null,
                    'margem_pct'                   => null,
                    'margem_pct_var_pct'           => null,
                    'status'                       => $invalidada
                        ? 'invalidada'
                        : ((($revShopee['diff_pct'] ?? null) === null) ? 'sem_baseline' : 'completo'),
                    'invalidada'                   => $invalidada,
                ];
            }

            $custId       = $c->cust_id;
            $rowAtual     = $atualPorEmpresa->get($c->id);
            $rowAnterior  = $anteriorPorEmpresa->get($c->id);

            // Revenue prioriza cache Adman; fallback SUM DB local.
            $revenue = null;
            if ($custId && isset($grossAtual[$custId]) && ($grossAtual[$custId]['value'] ?? null) !== null) {
                $revenue = (float) $grossAtual[$custId]['value'];
            }
            if ($revenue === null) {
                $revenue = $rowAtual ? (float) $rowAtual->rev : null;
            }

            // Margem: distingue "sem dados Adman" (null) de "zero real"
            // (empresa cadastrada mas margem calculada = 0). margem_dias > 0
            // significa que houve pelo menos 1 dia com contribution_margin
            // reportado pela Adman; sem isso, tratamos como null (UI mostra "—"
            // com badge "sem dados").
            $temMargemAtual    = $rowAtual !== null && (int) $rowAtual->margem_dias > 0;
            $temMargemAnterior = $rowAnterior !== null && (int) $rowAnterior->margem_dias > 0;
            $margemAtual       = $temMargemAtual    ? (float) $rowAtual->margem    : null;
            $margemAnterior    = $temMargemAnterior ? (float) $rowAnterior->margem : null;

            // Fase 103 (CAR-02, Pitfall 1 do 103-RESEARCH.md) — variação de
            // margem delegada ao `AdmanMetricDiffService::compute()` (Fase
            // 101), chamado pra TODA empresa elegível (independente do que
            // $atualPorEmpresa/$anteriorPorEmpresa acharam localmente — o
            // diff service tem sua PRÓPRIA leitura ao vivo da Adman + seu
            // próprio fallback local, com os MESMOS guards que existiam
            // aqui — margem_dias + interseção de dias-comuns — fix Luiz/
            // Tomelin/LOJASINVAL/AVF2K) e o gate ADM-02 (usa `.diff` nativo
            // da Adman quando `comparison_mode=previous_equal_length_window`
            // E o dado está presente; senão cai no calculated_fallback,
            // mesma matemática de antes). Lê SEMPRE `contribution_margin_value`
            // (mapeado de `profitMargin.diff` — variação % do VALOR EM R$
            // da margem, a mesma semântica que este campo `margem_variacao_pct`
            // sempre teve aqui) — NUNCA `contribution_margin_pct`
            // (`percentageMargin.diff`, variação da margem-como-%-da-receita,
            // métrica DIFERENTE usada pela Fase 102 no score de bônus).
            // Confundir os dois muda silenciosamente o que esta tela reporta
            // — já auditada 3× por divergência de número (Tomelin/Gabriela/
            // LOJASINVAL).
            $resultadoDiff = $this->diffDispatcher->compute($c, $periodo, 'adman');
            $margemVarPct  = $resultadoDiff['metrics']['contribution_margin_value']['diff_pct'] ?? null;

            // Fase 3 (2026-07-21) — campos §8.3 do MESMO diff service (fonte
            // consistente). Além das variações + margem %, agora também os
            // VALORES de faturamento e margem R$ vêm do diff service — que
            // devolve os valores NATIVOS da Adman (faturamento bruto / margem
            // contribuição), batendo exatamente com a tela da Adman. Antes a
            // carteira usava soma local (`SUM(revenue)`/`SUM(contribution_margin)`)
            // que divergia (faturamento ~10k a menos; margem R$ ~2× maior). Fix
            // validado contra PETSHOPBRASIL (2026-07-22). Em teste (sem HTTP) o
            // diff service cai no fallback de soma local — mesmo valor de antes.
            $fatVarPct  = $resultadoDiff['metrics']['revenue']['diff_pct'] ?? null;
            $revValDiff = $resultadoDiff['metrics']['revenue']['value'] ?? null;
            $margRsDiff = $resultadoDiff['metrics']['contribution_margin_value']['value'] ?? null;
            $margPctVal = $resultadoDiff['metrics']['contribution_margin_pct']['value'] ?? null;
            $margPctVar = $resultadoDiff['metrics']['contribution_margin_pct']['diff_pct'] ?? null;

            // Valor final exibido: diff service (nativo Adman) com fallback pras
            // fontes antigas se o diff vier null (empresa sem endpoint).
            $faturamentoFinal = $revValDiff !== null ? (float) $revValDiff : $revenue;
            $margemFinal      = $margRsDiff !== null ? (float) $margRsDiff : $margemAtual;

            // Motivo pt-BR para tooltip/badge quando margem é null — ajuda o
            // admin/analista a entender POR QUE algumas empresas aparecem sem
            // dados de margem (sync não rodou / conexão Adman ausente / etc).
            $motivoSemMargem = null;
            if (! $temMargemAtual) {
                if ($rowAtual === null) {
                    $motivoSemMargem = 'Sem sync Adman no período';
                } else {
                    $motivoSemMargem = 'Adman não reportou margem no período';
                }
            }

            // CART-03 — ad_spend/tacos, campos NOVOS nesta função (não
            // existiam antes da Fase 89). Mesmo padrão de
            // renderCarteirasConsolidadas():552-571 no mesmo arquivo: cache
            // Adman (investment/tacos) com fallback SUM DB / cálculo local.
            $adSpend = null;
            if ($custId && isset($accountAtual[$custId]['value']['investment'])) {
                $adSpend = (float) $accountAtual[$custId]['value']['investment'];
            }
            if ($adSpend === null) {
                $adSpend = $rowAtual ? (float) $rowAtual->ads : null;
            }

            $tacos = null;
            if ($custId && isset($accountAtual[$custId]['value']['tacos'])) {
                $tacos = (float) $accountAtual[$custId]['value']['tacos'];
            } elseif ($revenue !== null && $revenue > 0 && $adSpend !== null) {
                $tacos = round(($adSpend / $revenue) * 100, 2);
            }

            return [
                'id'                          => $c->id,
                'name'                        => $c->name,
                'faturamento'                 => $faturamentoFinal !== null ? round($faturamentoFinal, 2) : null,
                'margem_contribuicao'         => $margemFinal !== null ? round($margemFinal, 2) : null,
                'margem_contribuicao_anterior'=> $margemAnterior !== null ? round($margemAnterior, 2) : null,
                'margem_variacao_pct'         => $margemVarPct,
                'motivo_sem_margem'           => $motivoSemMargem,
                'ad_spend'                    => $adSpend !== null ? round($adSpend, 2) : null,
                'tacos'                       => $tacos,
                'has_ml_oauth'                => $temMlBadge,
                'servicos'                    => $servicos,
                // Campos §8.3 (aditivo) — mesma tabela da transparência.
                'fonte'                       => $fonte,
                'faturamento_var_pct'         => $fatVarPct,
                'margem_rs'                   => $margemFinal !== null ? round($margemFinal, 2) : null,
                'margem_rs_var_pct'           => $margemVarPct,
                'margem_pct'                  => $margPctVal !== null ? round((float) $margPctVal, 2) : null,
                'margem_pct_var_pct'          => $margPctVar,
                'status'                      => $invalidada
                    ? 'invalidada'
                    : ($faturamentoFinal === null
                        ? 'sem_dados'
                        : (($fatVarPct === null && $margemVarPct === null)
                            ? 'sem_baseline'
                            : ($margPctVal === null ? 'parcial' : 'completo'))),
                'invalidada'                  => $invalidada,
            ];
        })->values();

        // Totais consolidados da carteira — só empresas com dados válidos entram
        // (nulls filtrados pelo ?? 0 no sum, mas contamos separado pra flag).
        $totalFaturamento    = (float) $empresas->sum('faturamento');
        $totalMargemAtual    = (float) $empresas->sum(fn ($e) => $e['margem_contribuicao'] ?? 0);
        $totalMargemAnterior = (float) $empresas->sum(fn ($e) => $e['margem_contribuicao_anterior'] ?? 0);

        // Ajuste 2026-07-10 (audit Gabriela): variação % é a MÉDIA das variações
        // POR EMPRESA (não o total agregado SUM/SUM, que pesava empresas grandes
        // e divergia do ranking em sinal).
        // 2026-07-22 (unificação margem %): a margem de contribuição em R$ saiu da
        // carteira — o que importa é a margem % e a variação DELA. Este KPI agora
        // lê `margem_pct_var_pct` (variação do percentageMargin da Adman), a MESMA
        // métrica que DesempenhoScoreService::computeVarMargem usa no bônus desde
        // a Fase 102 (antes lia `margem_variacao_pct` = variação da margem R$, que
        // divergia do bônus quando a receita mudava entre as janelas).
        $variacoesPorEmpresa = $empresas
            ->pluck('margem_pct_var_pct')
            ->filter(fn ($v) => $v !== null);

        $variacaoMargemPct = $variacoesPorEmpresa->isNotEmpty()
            ? round((float) $variacoesPorEmpresa->avg(), 2)
            : null;

        // Fase 107 (cards de cima) — MARGEM MÉDIA da carteira: média SIMPLES da
        // margem % (percentageMargin) por empresa. É o NÍVEL da margem (não a
        // variação), o que o card "Margem média" deve mostrar.
        $margensPct = $empresas->pluck('margem_pct')->filter(fn ($v) => $v !== null);
        $margemMediaPct = $margensPct->isNotEmpty()
            ? round((float) $margensPct->avg(), 2)
            : null;

        // Fase 107 — VARIAÇÃO DA QUANTIDADE DE CLIENTES no mês. company_users não
        // rastreia saídas (a linha é apagada quando o cliente sai) e tem VÁRIAS
        // linhas por empresa (uma por serviço), então contar `created_at` inflaria.
        // O sinal limpo vem do snapshot DIÁRIO durável (`empresas_carteira` =
        // empresas ÚNICAS, mesma base do CarteiraContextService): pega a última
        // contagem ANTES do início deste mês (≈ fim do mês passado) e faz
        // net = contagem viva − aquela. Positivo = entraram; negativo = saíram.
        // (Snapshots MENSAIS não existem — o consolidar-mes não roda; os diários
        // do `desempenho:snapshot-scores` sim.) Null quando não há base anterior.
        $contagemAnterior = DesempenhoScoreSnapshot::diario()
            ->where('user_id', $user->id)
            ->whereDate('ref_date', '<', $mesCorrente->toDateString())
            ->orderByDesc('ref_date')
            ->value('empresas_carteira');
        $clientesVariacao = $contagemAnterior !== null
            ? $empresas->count() - (int) $contagemAnterior
            : null;

        // Contadores pra UI expor transparência sobre qualidade dos dados.
        // Fase 89 (correção do plan-checker) — conta SÓ empresas ELEGÍVEIS
        // com margem null. Empresa Shopee-only tem margem null POR DESENHO
        // (sem fonte financeira/margem) — não é problema de sync, não deve
        // inflar esse contador (que alimenta o banner rosa "sem dados de
        // margem"). Fase 109 — restrito a $companyIdsAdminElegiveis (fonte
        // 'adman'); empresa cuja fonte vencedora é 'shopee' NUNCA entra aqui.
        $empresasSemMargem = (int) $empresas
            ->filter(fn ($e) => $companyIdsAdminElegiveis->contains($e['id']))
            ->whereNull('margem_contribuicao')
            ->count();

        // CART-03 — total_ad_spend (soma dos ad_spend não-null) e tacos_medio
        // (média SIMPLES dos tacos por empresa não-null — mesmo racional do
        // hotfix 2026-06-23 de alinhar com o Dashboard/renderCarteirasConsolidadas).
        $totalAdSpend = (float) $empresas->sum(fn ($e) => $e['ad_spend'] ?? 0);
        $tacosPorEmpresa = $empresas->pluck('tacos')->filter(fn ($v) => $v !== null);
        $tacosMedio = $tacosPorEmpresa->isNotEmpty()
            ? round((float) $tacosPorEmpresa->avg(), 2)
            : null;

        // Cargo pt-BR pra header (mesmo padrão do resto do módulo).
        $cargoSlug = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->where('us.user_id', $user->id)
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->value('c.slug');
        $cargoLabel = $cargoSlug === 'estrategista' ? 'Estrategista' : ($cargoSlug === 'analista' ? 'Analista' : 'Profissional');

        // Meses disponíveis pro filtro (últimos 6 meses — mesma janela do ranking).
        $mesesDisponiveis = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $mesCorrente->copy()->subMonths($i);
            $mesesDisponiveis[] = [
                'value'    => $m->format('Y-m'),
                'label'    => mb_strtolower($m->translatedFormat('F/Y')),
                'em_curso' => $m->equalTo($mesCorrente),
            ];
        }

        // Fase 90 (CART-07) — contadores de vinculos (empresas_unicas ja
        // coberto por total_empresas acima) via CarteiraContextService,
        // reaproveitando $vinculos ja resolvido (nao reinventar contagem).
        // Fase 109 — Shopee sempre conta "sem fonte financeira" no resumo
        // (ver vinculosParaContadoresDisplay()).
        $contadoresResumo = $this->carteiraContext->contadores($this->vinculosParaContadoresDisplay($vinculos));

        // ─── Ponto do mês (2026-08-06) ───────────────────────────────────────
        // A carteira ganhou a nota/faixa do profissional como âncora, no lugar
        // do KPI "Margem média". Precedência IDÊNTICA à de
        // `PerformanceController::show()`: snapshot mensal congelado primeiro,
        // `computeCached()` só como fallback.
        //
        // Não é detalhe de implementação — é o que impede as duas telas de
        // mostrarem números diferentes para a mesma competência fechada. A
        // margem é frágil na fronteira (learning §2: 0,24 p.p. entre duas
        // leituras da mesma competência já tirou o bônus de alguém), então ler
        // ao vivo aqui produziria divergência sem nenhuma mudança de dado.
        // Como efeito colateral bom, mês fechado nem chega a computar.
        //
        // `compute()` puro NUNCA entra aqui: sem cache esta página espera HTTP
        // da Adman por empresa e vai a dezenas de segundos (learning §5).
        $snapMensal = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesSelecionado->toDateString())
            ->first();

        // 2026-08-07 — gate quente/frio (mesma regra do ranking, Fase 106, agora
        // no `WarmDesempenhoDispatcher`). Sem ele esta tela chamava
        // `computeCached()` direto e, no cache frio, pagava o fan-out HTTP
        // síncrono à Adman por empresa: 110s medidos para um profissional de 25
        // empresas. Era o "carregando infinito" ao trocar o mês.
        //
        // Snapshot mensal congelado tem precedência e NÃO passa pelo gate —
        // leitura de tabela, custo zero.
        $aquecendoScore = false;

        if ($snapMensal && is_array($snapMensal->breakdown_json) && isset($snapMensal->breakdown_json['componentes'])) {
            $scoreDoMes = $snapMensal->breakdown_json;
        } elseif ($this->warmDispatcher->gateIndividual($user, $mesSelecionado)) {
            $aquecendoScore = true;
            $scoreDoMes     = ['calculando' => true];
        } else {
            $scoreDoMes = $this->scoreService->computeCached($user, $mesSelecionado);
        }

        // ─── Pontos por empresa ──────────────────────────────────────────────
        // Só existem em competência FECHADA já consolidada. `is_closed` sozinho
        // não basta (mês fechado sem consolidação, ou anterior à Fase 122, não
        // tem linha gravada), por isso a flag deriva da EXISTÊNCIA de linhas —
        // mesma regra do `PerformanceController::show()`. Em mês em curso a
        // tela mostra aviso explícito; jamais número calculado na hora.
        $linhasScore = collect();
        if ($periodo['is_closed']) {
            $linhasScore = $this->companyScoreReader->paraUsuario($user->id, $mesSelecionado);
        }
        $temDetalheEmpresas = $linhasScore->isNotEmpty();

        // Mapa company_id → pontos, para casar com as linhas operacionais já
        // montadas em $empresas sem refazer nenhuma consulta.
        $pontosPorEmpresa = $linhasScore->keyBy('company_id');

        $empresas = $empresas->map(function (array $e) use ($pontosPorEmpresa) {
            $linha = $pontosPorEmpresa->get($e['id']);

            $e['nps_pontos']         = $linha['nps_pontos']         ?? null;
            $e['faturamento_pontos'] = $linha['faturamento_pontos'] ?? null;
            $e['margem_pontos']      = $linha['margem_pontos']      ?? null;
            $e['nota_empresa']       = $linha['nota_empresa']       ?? null;

            return $e;
        });

        return Inertia::render('Portfolio/AdminCarteira', [
            'profissional' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'cargo_label' => $cargoLabel,
            ],
            'resumo' => [
                'total_empresas'        => $empresas->count(),
                'empresas_ml_oauth'     => (int) $empresas->where('has_ml_oauth', true)->count(),
                'empresas_sem_margem'   => $empresasSemMargem,
                'total_faturamento'     => round($totalFaturamento, 2),
                'total_margem_atual'    => round($totalMargemAtual, 2),
                'total_margem_anterior' => round($totalMargemAnterior, 2),
                'variacao_margem_pct'   => $variacaoMargemPct,
                // Fase 107 (cards de cima): margem média (nível) + variação de clientes.
                'margem_media_pct'      => $margemMediaPct,
                'clientes_variacao'     => $clientesVariacao,
                'total_ad_spend'        => round($totalAdSpend, 2),
                'tacos_medio'           => $tacosMedio,
                'vinculos_servico'              => $contadoresResumo['vinculos_servico'],
                'vinculos_sem_fonte_financeira'  => $contadoresResumo['vinculos_sem_fonte_financeira'],
            ],
            'contexto' => $contextoFiltro['param'],
            'empresas' => $empresas,
            // Ponto do mês (2026-08-06) — o mesmo shape que `Performance/Show`
            // consome em `resultado`, para as duas telas formatarem igual.
            'score' => [
                'nota_final'         => $scoreDoMes['nota_final']         ?? null,
                'faixa_bonus'        => $scoreDoMes['faixa_bonus']        ?? null,
                'faixa_promovida'    => $scoreDoMes['faixa_promovida']    ?? false,
                'score_status'       => $scoreDoMes['score_status']       ?? null,
                'pontos_componentes' => $scoreDoMes['pontos_componentes'] ?? null,
                'sem_carteira'       => $scoreDoMes['sem_carteira']       ?? false,
                // true = nota ainda sendo calculada em background; o front
                // mostra "calculando…" no lugar do número em vez de exibir
                // "—", que se confunde com "sem nota".
                'calculando'         => $aquecendoScore,
            ],
            // Mesmo nome de prop do ranking e do /performance/{id}, de propósito.
            // As DUAS camadas contam: a nota e a tabela têm caches distintos e
            // podem estar frias em momentos diferentes — a tela só volta ao
            // normal quando nenhuma das duas está aquecendo.
            'aquecendo' => $aquecendoScore || $aquecendoCarteira,
            // Separada porque é a tabela que some, não a página inteira.
            'aquecendo_tabela' => $aquecendoCarteira,
            'tem_detalhe_empresas' => $temDetalheEmpresas,
            'periodo' => [
                // Campos de display já existentes (Fase 89, PRESERVADOS).
                'em_curso'         => $ehMesEmCurso,
                'mes_selecionado'  => $mesSelecionado->format('Y-m'),
                'meses_disponiveis' => $mesesDisponiveis,
                'dia_atual'        => $ehMesEmCurso ? $hoje->day : $mesSelecionado->daysInMonth,
                'dias_no_mes'      => $mesSelecionado->daysInMonth,
                'mes_label'        => mb_strtolower($mesSelecionado->translatedFormat('F Y')),
                'range_atual'      => sprintf('%s até %s', $inicioMes->format('d/m'), $fimMes->format('d/m')),
                'range_anterior'   => sprintf('%s até %s', $inicioAnter->format('d/m'), $fimAnter->format('d/m')),
                // Fase 103 (CAR-03) — datas cruas do MetricPeriodResolver,
                // pra cards/séries que precisam da janela resolvida sem
                // reparsear os campos de display acima. Não constrói
                // seletor/toggle novo aqui (Fase 104 — Pitfall 3 do
                // 103-RESEARCH.md).
                'current_start'    => $periodo['current_start'],
                'current_end'      => $periodo['current_end'],
                'baseline_start'   => $periodo['baseline_start'],
                'baseline_end'     => $periodo['baseline_end'],
                'mode'             => $periodo['mode'],
                'comparison_mode'  => $periodo['comparison_mode'],
                'is_current_month' => $periodo['is_current_month'],
                'is_closed'        => $periodo['is_closed'],
            ],
            // Fase 104 (UIP-02/UIP-03) — bloco bonus (competence_month/
            // payment_month) presente só em período fechado; null em curso.
            'bonus' => $bonusMeta,
        ]);
    }

    // Aba "Carteira" no sidebar — bifurca por papel (quick 260610-lj6 + 260623):
    //  - admin → visão consolidada de TODOS analistas/estrategistas (cards)
    //  - líder de setor → visão consolidada FILTRADA pelos setores que ele lidera
    //  - profissional → carteira pessoal enxuta (Portfolio/AdminCarteira)
    //
    // Ajuste 2026-07-09 (v5) — profissional agora vê a MESMA página enxuta
    // que o admin vê pra ele. O Portfolio/Show.jsx legado (1000+ linhas) estava
    // renderizando tela preta pra estrategista/analista após a migração ao
    // shape v2. A view nova cobre 100% das necessidades operacionais (total
    // faturamento, variação margem, listagem com badge ML + variação individual).
    public function own(Request $request)
    {
        $user = $request->user();
        if ($user->isAdmin()) {
            return $this->renderCarteirasConsolidadas($request);
        }
        if ($user->isLider()) {
            $setoresIds = $user->setoresLiderados()->pluck('setores.id')->all();
            return $this->renderCarteirasConsolidadas($request, $setoresIds);
        }
        return $this->renderCarteiraProfissional($request, $user);
    }

    /**
     * Visão consolidada de carteiras: cards de TODOS analistas e estrategistas
     * com métricas agregadas (TACOS, faturamento, margem, ad spend) da carteira
     * de cada um. Usado pela aba Carteira quando o user logado é admin.
     *
     * Fonte da verdade pra Analista vs Estrategista: cargo (slug) no pivot
     * user_setores → cargos, NÃO users.role (legacy). Lógica originalmente
     * implementada no DashboardController (quick 260610-f69) e migrada pra cá
     * no quick 260610-lj6 — bifurcação admin/não-admin na aba Carteira.
     */
    private function renderCarteirasConsolidadas(Request $request, ?array $setoresFiltro = null): \Inertia\Response
    {
        // Fase 103 Plan 02 (CAR-01) — a janela ROLANTE em dias (?period=
        // 1/7/30/180, sem baseline) e substituida pela janela MENSAL do
        // MetricPeriodResolver (mesmo resolvedor unico ja usado por
        // renderCarteiraProfissional desde a Fase 103 Plan 01). `$period`
        // (linha abaixo) e MANTIDO como atribuicao isolada — nao alimenta
        // mais o calculo de janela, mas ainda alimenta o echo legado
        // `'period' => $period` no payload (mais abaixo), que a
        // Carteiras.jsx le ate a Fase 104 (known-gap 103<->104: o seletor
        // visual rolante 1/7/30/180 continua exibido, mas o backend ja usa
        // a janela mensal do resolver).
        $period = $request->get('period', '30');

        // Fase 104 (UIP-02/UIP-03, blocker 2 do plan-check) — `?modo=bonus_atual`
        // SIMÉTRICO ao PerformanceController + renderCarteiraProfissional
        // (Plan 104-01). Sem isso, o toggle "Bônus atual" cairia no mês em
        // curso rotulado como bônus.
        $modo = $request->query('modo');
        if (!in_array($modo, ['em_curso', 'bonus_atual'], true)) {
            $modo = null;
        }

        // T-103-03 — whitelist regex ANTES de repassar ao resolver; fora do
        // formato YYYY-MM cai em current_month, nunca string crua (evita
        // InvalidArgumentException->500).
        $mesQuery = $request->query('mes');
        if ($modo === 'bonus_atual') {
            $periodo = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
        } elseif ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            $periodo = $this->periodResolver->resolve(['period_key' => $mesQuery]);
        } else {
            $periodo = $this->periodResolver->resolve(['period_key' => 'current_month']);
        }

        // Meses do seletor (últimos 6) — mesmo formato de `transparencia()`.
        // O mês corrente vem marcado `em_curso` para a tela rotular sozinha,
        // sem precisar de um segmento próprio no controle.
        $mesCorrenteOwn   = now()->startOfMonth();
        $mesesDisponiveis = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $mesCorrenteOwn->copy()->subMonths($i);
            $mesesDisponiveis[] = [
                'value'    => $m->format('Y-m'),
                'label'    => mb_strtolower($m->translatedFormat('F/Y')),
                'em_curso' => $m->equalTo($mesCorrenteOwn),
            ];
        }

        // `bonus` só existe quando o período resolvido é FECHADO — mesma regra
        // do PerformanceController/renderCarteiraProfissional (Plan 104-01).
        $bonusMeta = null;
        if ($periodo['is_closed']) {
            $competenciaFallback = Carbon::parse($periodo['current_start'])->format('Y-m');
            $bonusMeta = [
                'competence_month' => $periodo['bonus_competence_month'] ?? $competenciaFallback,
                'payment_month'    => $periodo['bonus_payment_month'] ?? Carbon::parse($periodo['current_start'])->addMonthNoOverflow()->format('Y-m'),
            ];
        }

        // Quick 260623 — quando $setoresFiltro != null, restringe analistas e
        // estrategistas àqueles vinculados aos setores informados. Usado pra
        // líder de setor ver consolidação só dos membros do(s) setor(es) que
        // ele lidera. Admin chama sem filtro = vê todos.
        $aplicarFiltroSetor = function ($q) use ($setoresFiltro) {
            if (!empty($setoresFiltro)) {
                $q->whereIn('us.setor_id', $setoresFiltro);
            }
        };

        $analistas = User::where('active', 1)
            ->whereExists(function ($q) use ($aplicarFiltroSetor) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'analista');
                $aplicarFiltroSetor($q);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $estrategistas = User::where('active', 1)
            ->whereExists(function ($q) use ($aplicarFiltroSetor) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->where('c.slug', 'estrategista');
                $aplicarFiltroSetor($q);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        $todos = $analistas->map(fn($u) => ['user' => $u, 'tipo' => 'analista'])
            ->concat($estrategistas->map(fn($u) => ['user' => $u, 'tipo' => 'estrategista']));

        // Hotfix 2026-06-23 — Carteiras consolidadas divergia do /portfolio/show:
        // somava só AdmanMetric.revenue/ad_spend (DB local incompleto), enquanto
        // o renderPortfolio individual usa cache Adman gross + investment (mais
        // completo). Ex.: Ana Julia mostrava R$ 16,8M aqui vs R$ 20,6M no
        // individual. Mesma estrategia do hotfix cust_id aplicada antes.
        //
        // Fase 103 Plan 02 (CAR-01) — janela agora vem do resolver
        // (current_start..current_end), nao mais de $since=now()->subDays().
        $dateFrom = $periodo['current_start'];
        $dateTo   = $periodo['current_end'];

        // Fase 90 (CART-06/07) — filtro `?contexto=` (todos/performance/shopee).
        // NAO confundir com $setoresFiltro acima (organizacional, ja existente).
        $contextoFiltro = $this->contextoFiltro($request);

        // Acumula, POR REFERENCIA, os vinculos de TODOS os cards efetivamente
        // exibidos — usado so no final pra montar `totais` (uniao de
        // company_id, nunca soma de empresas_unicas entre cards, que infla a
        // contagem quando uma empresa e compartilhada por 2 profissionais).
        $vinculosExibidosTotal = collect();

        $portfolios = $todos->map(function ($item) use ($dateFrom, $dateTo, $contextoFiltro, &$vinculosExibidosTotal) {
            $u    = $item['user'];
            $tipo = $item['tipo'];
            $role = $tipo === 'estrategista' ? 'estrategista' : 'consultor';

            // Fase 90 (CART-06) — mesma receita da Fase 89
            // (renderCarteiraProfissional): origem por VINCULO via
            // CarteiraContextService, nunca mais estrategistaCompanies()/
            // consultorCompanies() (consolidado por empresa, sem distinguir
            // setor de servico — causa raiz do card Shopee-only herdando
            // faturamento ML gerido por outro profissional).
            $vinculos = $this->carteiraContext->forUser($u, [
                'role'   => $role,
                'active' => true,
                'setor'  => $contextoFiltro['setor'],
            ]);

            // Card 100% fora do contexto (ou profissional sem NENHUM vinculo)
            // desaparece — decisao travada no plano (nao zera, some).
            if ($vinculos->isEmpty()) {
                return null;
            }

            $vinculosExibidosTotal = $vinculosExibidosTotal->concat($vinculos);

            // Contadores prontos (Fase 88) — nao reinventar dedup aqui.
            // Fase 109 — Shopee sempre conta "sem fonte financeira" no chip
            // (mesma regra da carteira individual, vinculosParaContadoresDisplay()).
            $contadores = $this->carteiraContext->contadores($this->vinculosParaContadoresDisplay($vinculos));

            // Dedup financeiro (mesma receita CART-04/05 da Fase 89):
            // AdmanMetric e por-EMPRESA, nunca por-vinculo — ->unique() evita
            // consultar 2x uma empresa onde o profissional tem 2 vinculos
            // elegiveis (ex.: Performance via slot legado + servico_id
            // preenchido, hipotetico, ou Gestao+Mentoria).
            $companyIdsElegiveis = $vinculos
                ->where('financial_metrics_eligible', true)
                ->pluck('company_id')
                ->unique()
                ->values();

            // Fase 109 (SHOP-CAR-01/02) — fonte financeira VENCEDORA por
            // empresa (desempate travado). Calculado aqui (antes do gate de
            // vazio) porque source_counts abaixo também precisa: empresa
            // 'shopee' NUNCA conta como fonte ADR DATA-04 do profissional
            // (ela não é ML/Adman — bug espelhado que este contador já
            // guardava antes da Fase 109 abrir a elegibilidade Shopee).
            $fontesPorEmpresaCard = $this->fontesFinanceirasPorEmpresa($vinculos);
            $companyIdsAdmanCard  = $companyIdsElegiveis->filter(fn ($id) => $fontesPorEmpresaCard->get($id) === 'adman')->values();
            $companyIdsShopeeCard = $companyIdsElegiveis->filter(fn ($id) => $fontesPorEmpresaCard->get($id) === 'shopee')->values();

            // source_counts (Phase 61, flag unified_metrics_enabled) —
            // restrito a $companyIdsAdmanCard (fonte 'adman'), pra nao
            // voltar a contar empresa Shopee-only como fonte financeira do
            // profissional (bug espelhado do bloco principal).
            $sourceCounts = null;
            if ($this->unifiedMetricsEnabled()) {
                $sourceCounts = ['adman' => 0, 'ml' => 0, 'unified' => 0, 'none' => 0];
                if ($companyIdsAdmanCard->isNotEmpty()) {
                    $companiesFonte = Company::whereIn('id', $companyIdsAdmanCard)->with('mlToken')->get();
                    foreach ($companiesFonte as $c) {
                        $sourceCounts[$this->factoryToSource($c)]++;
                    }
                }
            }

            // Profissional so-Shopee (ou filtro Shopee ativo): card existe,
            // contadores presentes, mas financeiro e 0/null — nunca herda
            // faturamento/margem de ML de empresa gerida por outro profissional.
            if ($companyIdsElegiveis->isEmpty()) {
                $card = [
                    'id'              => $u->id,
                    'name'            => $u->name,
                    'tipo'            => $tipo,
                    'role'            => $u->role,
                    'avg_tacos'       => null,
                    'total_revenue'   => 0.0,
                    'avg_margin'      => null,
                    'total_ad_spend'  => 0.0,
                    ...$contadores,
                ];
                if ($sourceCounts !== null) {
                    $card['source_counts'] = $sourceCounts;
                }

                return $card;
            }

            // Fase 109 — $companyIdsAdmanCard/$companyIdsShopeeCard já
            // resolvidos acima (junto de source_counts).
            $totalRevenue    = 0.0;
            $totalAdSpend    = 0.0;
            $avgMargin       = null;
            $tacosCarteira   = null;

            if ($companyIdsAdmanCard->isNotEmpty()) {
                // O service nao expoe cust_id/adman_account_id/ml_store_id
                // (granularidade de vinculo, nao de empresa) — carregamos os
                // models separadamente, so pras empresas elegiveis 'adman'.
                $companies = Company::whereIn('id', $companyIdsAdmanCard)
                    ->get(['id', 'adman_account_id', 'ml_store_id']);

                $companyIds = $companies->pluck('id');
                $custIds    = $companies->map(fn ($c) => $c->cust_id)->filter()->unique()->values()->all();

                // SUM DB (fallback completo) + cache Adman pra empresas com custId.
                // Fase 103 Plan 02 (CAR-03) — janela FECHADA dos dois lados
                // (current_start..current_end do resolver), nao mais so limite
                // inferior (coerencia com a soma current_month/closed_period).
                $sumDb = AdmanMetric::whereIn('company_id', $companyIds)
                    ->whereBetween('reference_date', [$dateFrom, $dateTo])
                    ->selectRaw('company_id, SUM(revenue) as rev, SUM(ad_spend) as ads, AVG(contribution_margin_pct) as margem')
                    ->groupBy('company_id')
                    ->get()
                    ->keyBy('company_id');

                $gross   = $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo);
                $account = $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo);

                $tacosPorEmpresa = [];
                foreach ($companies as $c) {
                    $row = $sumDb->get($c->id);

                    // Revenue: cache gross com fallback DB
                    $rev = null;
                    if ($c->cust_id && isset($gross[$c->cust_id]['value']) && $gross[$c->cust_id]['value'] !== null) {
                        $rev = (float) $gross[$c->cust_id]['value'];
                    }
                    if ($rev === null) {
                        $rev = (float) ($row->rev ?? 0);
                    }
                    $totalRevenue += $rev;

                    // Ad spend: cache investment com fallback DB
                    $ads = null;
                    if ($c->cust_id && isset($account[$c->cust_id]['value']['investment'])) {
                        $ads = (float) $account[$c->cust_id]['value']['investment'];
                    }
                    if ($ads === null) {
                        $ads = (float) ($row->ads ?? 0);
                    }
                    $totalAdSpend += $ads;

                    // TACOS desta empresa: prioriza cache (mais preciso), fallback DB.
                    $tacosEmpresa = null;
                    if ($c->cust_id && isset($account[$c->cust_id]['value']['tacos'])) {
                        $tacosEmpresa = (float) $account[$c->cust_id]['value']['tacos'];
                    } elseif ($rev > 0) {
                        $tacosEmpresa = ($ads / $rev) * 100;
                    }
                    if ($tacosEmpresa !== null) {
                        $tacosPorEmpresa[] = $tacosEmpresa;
                    }
                }

                // Margem media via DB (cache nao expoe margem por empresa de forma
                // estavel; SUM DB ja era a fonte do campo aqui). Shopee nunca
                // contribui pra esta media (nao tem margem).
                $avgMargin = $sumDb->avg('margem');

                // TACOS médio da carteira: média SIMPLES dos TACOS per-empresa
                // (mesma estratégia do DashboardController::adminDashboard, pra que
                // /portfolio e /dashboard mostrem o mesmo número pro mesmo user).
                // Hotfix 2026-06-23 — antes usava razão dos totais (ad_spend/rev),
                // matematicamente mais correto como "TACOS efetivo agregado" mas
                // divergia do Dashboard. Pragmaticamente: alinhar pra evitar dúvida.
                $tacosCarteira = !empty($tacosPorEmpresa)
                    ? round(array_sum($tacosPorEmpresa) / count($tacosPorEmpresa), 2)
                    : null;
            }

            // Fase 109 — caminho Shopee: SUM(shopee_metrics.revenue/ad_expense)
            // na janela atual, somado ao total (sem HTTP, sem margem — Shopee
            // nunca contribui pra avg_margin/avg_tacos).
            if ($companyIdsShopeeCard->isNotEmpty()) {
                $shopeeAgregado = ShopeeMetric::whereIn('company_id', $companyIdsShopeeCard)
                    ->whereDate('reference_date', '>=', $dateFrom)
                    ->whereDate('reference_date', '<=', $dateTo)
                    ->selectRaw('SUM(revenue) as rev, SUM(CASE WHEN ad_expense IS NOT NULL THEN ad_expense ELSE 0 END) as ads')
                    ->first();

                $totalRevenue += (float) ($shopeeAgregado->rev ?? 0);
                $totalAdSpend += (float) ($shopeeAgregado->ads ?? 0);
            }

            $card = [
                'id'              => $u->id,
                'name'            => $u->name,
                'tipo'            => $tipo,
                'role'            => $u->role,
                'avg_tacos'       => $tacosCarteira,
                'total_revenue'   => round($totalRevenue, 2),
                'avg_margin'      => $avgMargin !== null ? round((float) $avgMargin, 2) : null,
                'total_ad_spend'  => round($totalAdSpend, 2),
                ...$contadores,
            ];
            if ($sourceCounts !== null) {
                $card['source_counts'] = $sourceCounts;
            }

            return $card;
        })->filter()->sortBy('name')->values();

        // Totais agregados do topo (CART-07/SC4) — UNIAO de company_id de
        // TODOS os vinculos exibidos (nunca soma de empresas_unicas entre
        // cards, que infla a contagem quando 2 profissionais compartilham a
        // mesma empresa). vinculos_servico e aditivamente correto (cada
        // vinculo pertence a exatamente 1 profissional).
        $totais = [
            'empresas_unicas'  => $vinculosExibidosTotal->pluck('company_id')->unique()->count(),
            'vinculos_servico' => $vinculosExibidosTotal->count(),
        ];

        return Inertia::render('Portfolio/Carteiras', [
            'user_portfolios' => $portfolios,
            // `period` (echo legado 1/7/30/180) — a Carteiras.jsx ainda le
            // esse campo pro seletor visual rolante ate a Fase 104. O
            // seletor visual e substituido pela janela mensal do resolver
            // ja no BACKEND desta fase — mismatch UI intencional, resolvido
            // na Fase 104 (known-gap 103<->104, ver 103-02-SUMMARY.md).
            'period'          => $period,
            // Fase 103 Plan 02 (CAR-03) — janela do MetricPeriodResolver
            // (current_month por default, closed_period via ?mes=YYYY-MM).
            // Escopo MINIMO (decisao travada 3 do 103-02-PLAN.md): so as 4
            // datas + metadados do resolver, SEM baseline/variacao nova por
            // card (isso e Fase 104).
            // 2026-08-05: ganhou `mes_selecionado`/`meses_disponiveis`. A tela
            // perdeu o toggle "Em curso / Bônus atual" e passou a ter seletor
            // de mês, como as demais — sem estas duas chaves ela ficaria sem
            // NENHUM controle de período (o `?mes=` já era aceito aqui, só não
            // havia como escolher pela interface).
            'periodo'         => [
                'mes_selecionado'  => Carbon::parse($periodo['current_start'])->format('Y-m'),
                'meses_disponiveis' => $mesesDisponiveis,
                'current_start'    => $periodo['current_start'],
                'current_end'      => $periodo['current_end'],
                'baseline_start'   => $periodo['baseline_start'],
                'baseline_end'     => $periodo['baseline_end'],
                'mode'             => $periodo['mode'],
                'comparison_mode'  => $periodo['comparison_mode'],
                'is_current_month' => $periodo['is_current_month'],
                'is_closed'        => $periodo['is_closed'],
            ],
            // Fase 104 (UIP-02/UIP-03) — bloco bonus (competence_month/
            // payment_month) presente só em período fechado; null em curso.
            'bonus'           => $bonusMeta,
            'contexto'        => $contextoFiltro['param'],
            'totais'          => $totais,
        ]);
    }

    private function renderPortfolio(Request $request, User $user): \Inertia\Response
    {
        $period = $request->get('period', now()->format('Y-m'));
        [$year, $month] = explode('-', $period);
        $year = (int) $year;
        $month = (int) $month;

        $refDate    = Carbon::create($year, $month, 1);
        $isMesAtual = $refDate->isSameMonth(now());

        // Janelas pro faturamento — Ajuste 2026-07-09: comparação ACUMULADA
        // dia-a-dia quando o mês está em curso, alinhando com a mesma lógica
        // do DesempenhoScoreService. Se hoje é dia 9 de julho, comparamos
        // "01/jul até 09/jul" com "01/jun até 09/jun" — janelas do mesmo
        // tamanho, evitando queda artificial por diferença de dias.
        // Mês passado (fechado): usa o mês calendário inteiro (comportamento
        // original), pois ambos meses são completos.
        if ($isMesAtual) {
            $diaAtual         = now()->day;
            $refInicioAtual   = now()->startOfMonth();
            $refInicioAnter   = $refInicioAtual->copy()->subMonth();
            $dateFrom         = $refInicioAtual->toDateString();
            $dateTo           = now()->toDateString();
            $dateFromAnterior = $refInicioAnter->toDateString();
            $dateToAnterior   = $refInicioAnter->copy()
                ->setDay(min($diaAtual, $refInicioAnter->daysInMonth))
                ->toDateString();
        } else {
            $dateFrom         = $refDate->copy()->startOfMonth()->toDateString();
            $dateTo           = $refDate->copy()->endOfMonth()->toDateString();
            $dateFromAnterior = $refDate->copy()->subMonth()->startOfMonth()->toDateString();
            $dateToAnterior   = $refDate->copy()->subMonth()->endOfMonth()->toDateString();
        }

        // Empresas da carteira (todas as roles). Phase 61 Plan 61-01 —
        // eager-load `mlToken` pra `MetricsProviderFactory::caseFor()` avaliar
        // caso ADR DATA-04 sem N+1 (mitigação T-61-01-02 do threat model).
        $rawCompanies = $user->companies()
            ->with(['latestMetrics', 'grants', 'mlToken'])
            ->where('active', true)
            ->withPivot('role')
            ->orderBy('name')
            ->get();

        // Pre-calcula SUM DB por empresa (fallback / mês passado)
        // Ajuste 2026-07-09: também trazemos SUM(contribution_margin) do mês
        // corrente E do mês anterior para calcular variação % por empresa
        // (usado na coluna "Margem" da tabela de carteira).
        $companyIdsAll = $rawCompanies->pluck('id');

        // Fase 109 (SHOP-CAR-01/02) — self-view ESPELHA o tratamento
        // financeiro de renderCarteiraProfissional: injeta faturamento+
        // investimento Shopee no bloco financeiro por empresa, MESMA janela
        // ($dateFrom/$dateTo), pras empresas cuja fonte VENCEDORA (desempate
        // travado) é 'shopee'. Decisão travada 2026-07-23 — RESTRIÇÕES: (1)
        // SÓ o bloco financeiro muda aqui, nada de sugadores/PPA/NPS/meta
        // abaixo; (2) NÃO conserta o bug legado de `$user->companies()`
        // (:1427) — empresas fora de qualquer vínculo CarteiraContextService
        // (não resolvidas em $fontesPorEmpresaSelf) seguem 100% no caminho
        // Adman/SUM antigo, sem mudança de comportamento.
        $vinculosSelf         = $this->carteiraContext->forUser($user, ['active' => true]);
        $fontesPorEmpresaSelf = $this->fontesFinanceirasPorEmpresa($vinculosSelf);
        $companyIdsShopeeSelf = $companyIdsAll->filter(fn ($id) => $fontesPorEmpresaSelf->get($id) === 'shopee')->values();
        $companyIdsComDadosShopeeSelf = $this->companyIdsComDadosShopee($companyIdsShopeeSelf);

        $shopeeSumAtualSelf = ShopeeMetric::whereIn('company_id', $companyIdsShopeeSelf)
            ->whereDate('reference_date', '>=', $dateFrom)
            ->whereDate('reference_date', '<=', $dateTo)
            ->selectRaw('company_id, SUM(revenue) as total, SUM(CASE WHEN ad_expense IS NOT NULL THEN ad_expense ELSE 0 END) as ads, SUM(CASE WHEN ad_expense IS NOT NULL THEN 1 ELSE 0 END) as ads_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $shopeeSumAnteriorSelf = ShopeeMetric::whereIn('company_id', $companyIdsShopeeSelf)
            ->whereDate('reference_date', '>=', $dateFromAnterior)
            ->whereDate('reference_date', '<=', $dateToAnterior)
            ->selectRaw('company_id, SUM(revenue) as total')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $sumDbAtual = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total, SUM(ad_spend) as ads, AVG(tacos) as tacos, AVG(contribution_margin_pct) as margem, SUM(contribution_margin) as margem_abs')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $sumDbAnterior = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFromAnterior, $dateToAnterior])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as total, SUM(contribution_margin) as margem_abs')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Margem absoluta anterior separada (para calcular variação por empresa
        // na função map abaixo). $sumDbAnterior mudou de collection de floats
        // (keyBy pluck) para collection de objetos — preservamos ambas as
        // vistas para não quebrar código que já lia .pluck('total').
        $revAnteriorPorEmpresa = $sumDbAnterior->mapWithKeys(
            fn ($row, $id) => [$id => (float) ($row->total ?? 0)]
        );
        $margemAnteriorPorEmpresa = $sumDbAnterior->mapWithKeys(
            fn ($row, $id) => [$id => (float) ($row->margem_abs ?? 0)]
        );
        // Preserva o nome legado usado logo abaixo (sumDbAnterior antes era
        // pluck('total', 'company_id') — mantemos essa view compatível).
        $sumDbAnterior = $revAnteriorPorEmpresa;

        // Cache batch da Adman (somente no mês atual — mês passado é histórico).
        // Hotfix 2026-06-19 auditoria — usa o accessor cust_id (adman_account_id
        // OU ml_store_id) em vez de pluck cru de adman_account_id. Antes, empresas
        // com APENAS ml_store_id (ex: DINMAP) ficavam fora do batch e o lookup
        // posterior caia no DB com valor parcial — Portfolio Gustavo perdia
        // ~R$ 816K em revenue real comparado ao Dashboard (que ja usa cust_id).
        $custIds = $rawCompanies->map(fn ($c) => $c->cust_id)
            ->filter(fn ($id) => !empty($id))
            ->unique()
            ->values()
            ->all();
        $grossAtual    = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo) : [];
        // Hotfix 2026-06-19 — Portfolio agora consome account metrics do cache
        // (mesma fonte que o Dashboard). Antes, ad_spend vinha SO do SUM DB, que
        // tem fracao minima dos dados reais (Gustavo: cache=R$ 106K, DB=R$ 1.380).
        $accountAtual  = $isMesAtual ? $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo) : [];
        $grossAnterior = $isMesAtual ? $this->adman->getCachedGrossBillingsMany($custIds, $dateFromAnterior, $dateToAnterior) : [];

        // Pre-calcula SUM DB anterior por empresa pra detectar queda MoM
        // (consumido na derivacao de status + acao_recomendada abaixo).
        $sumDbAnteriorPorEmpresa = $sumDbAnterior; // keyed by company_id

        // Ajuste 2026-07-10 (fix Tomelin — audit-ranking-margem-tomelin):
        // mesmo bug já corrigido em renderCarteiraProfissional. No mês em curso
        // a Adman atrasa `profitMargin` 1-3 dias em relação a `revenue`, então
        // a janela ATUAL soma menos dias que a ANTERIOR (histórico fechado, sem
        // lag), invertendo o sinal da variação. Descobrimos o último dia com
        // margem disponível por empresa e recortamos a MESMA quantidade de dias
        // do fim da janela anterior antes de comparar.
        $ultimoDiaComMargemPorEmpresa = collect();
        if ($isMesAtual) {
            $ultimoDiaComMargemPorEmpresa = AdmanMetric::whereIn('company_id', $companyIdsAll)
                ->whereBetween('reference_date', [$dateFrom, $dateTo])
                ->whereNotNull('contribution_margin')
                ->selectRaw('company_id, MAX(reference_date) as ultimo_dia')
                ->groupBy('company_id')
                ->pluck('ultimo_dia', 'company_id');
        }

        // Mapeia cada empresa pro array final
        $companies = $rawCompanies->map(function ($c) use ($isMesAtual, $sumDbAtual, $grossAtual, $grossAnterior, $sumDbAnteriorPorEmpresa, $accountAtual, $margemAnteriorPorEmpresa, $ultimoDiaComMargemPorEmpresa, $dateFromAnterior, $dateToAnterior, $dateTo, $fontesPorEmpresaSelf, $shopeeSumAtualSelf, $shopeeSumAnteriorSelf, $companyIdsComDadosShopeeSelf) {
            $activeGrant = $c->grants->where('status', 'active')->first();
            $custId      = $c->cust_id; // accessor: adman_account_id ?: ml_store_id

            // Fase 109 (SHOP-CAR-01/02) — empresa cuja fonte financeira
            // VENCEDORA (desempate travado) é 'shopee': bloco financeiro
            // 100% via shopee_metrics (SUM local, sem HTTP), margem sempre
            // null. 'sem_dados' fica pro caller decidir via faturamento null
            // (mesma semântica de renderCarteiraProfissional — não confundir
            // "revenue zero real na janela" com "nunca sincronizou Shopee").
            if ($fontesPorEmpresaSelf->get($c->id) === 'shopee') {
                $temDadosShopee = $companyIdsComDadosShopeeSelf->contains($c->id);
                $rowShopeeAtual    = $temDadosShopee ? $shopeeSumAtualSelf->get($c->id) : null;
                $rowShopeeAnterior = $temDadosShopee ? $shopeeSumAnteriorSelf->get($c->id) : null;

                $revenueShopee     = $rowShopeeAtual ? (float) $rowShopeeAtual->total : null;
                $revAnteriorShopee = $rowShopeeAnterior ? (float) $rowShopeeAnterior->total : 0.0;
                $adSpendShopee     = ($rowShopeeAtual && (int) $rowShopeeAtual->ads_dias > 0) ? (float) $rowShopeeAtual->ads : null;

                $quedaMomPctShopee = ($revenueShopee !== null && $revAnteriorShopee > 0)
                    ? round((($revenueShopee - $revAnteriorShopee) / $revAnteriorShopee) * 100, 2)
                    : null;

                return [
                    'id'                           => $c->id,
                    'name'                         => $c->name,
                    'role'                         => $c->pivot->role,
                    'tacos'                        => null,
                    'revenue'                      => $revenueShopee,
                    'revenue_anterior'             => $revAnteriorShopee > 0 ? $revAnteriorShopee : null,
                    'queda_mom_pct'                => $quedaMomPctShopee,
                    'contribution_margin_pct'      => null,
                    'contribution_margin_abs'      => 0.0,
                    'contribution_margin_prev'     => null,
                    'contribution_margin_var_pct'  => null,
                    'has_ml_oauth'                 => false,
                    'ad_spend'                     => $adSpendShopee,
                    'grant_status'                 => $activeGrant?->status,
                    'grant_expires_at'             => $activeGrant?->expires_at?->toDateString(),
                    'grant_days_remaining'         => $activeGrant?->days_remaining,
                    '_grant_ok'                    => $activeGrant && $activeGrant->status === 'active',
                    '_ad_spend_num'                => $adSpendShopee ?? 0.0,
                ];
            }

            // Faturamento da empresa no período: prioriza cache Adman (mês atual),
            // fallback SUM DB. Hotfix 2026-06-19 — usa cust_id (accessor) em vez
            // de adman_account_id puro pra incluir empresas com APENAS ml_store_id
            // (que entravam no DB com valor parcial — ex: DINMAP perdia R$ 816K).
            $revenue = null;
            if ($isMesAtual && $custId) {
                $entry = $grossAtual[$custId] ?? null;
                if ($entry && ($entry['value'] ?? null) !== null) {
                    $revenue = $entry['value'];
                }
            }
            if ($revenue === null) {
                $m = $sumDbAtual->get($c->id);
                $revenue = $m ? (float) $m->total : null;
            }

            $sumRow = $sumDbAtual->get($c->id);
            // Hotfix 2026-06-19 — ad_spend agora prioriza cache Adman (chave
            // 'investment' do getCachedAccountMetricsMany), mesma fonte do
            // DashboardController::adminDashboard. Antes vinha SO do SUM DB,
            // que tem fracao minima dos dados (Gustavo: cache=R$ 106K, DB=R$ 1.380).
            $adSpendCache = null;
            if ($isMesAtual && $custId) {
                $accEntry = $accountAtual[$custId]['value'] ?? null;
                if ($accEntry && isset($accEntry['investment'])) {
                    $adSpendCache = (float) $accEntry['investment'];
                }
            }
            $adSpendNum = $adSpendCache !== null
                ? $adSpendCache
                : ($sumRow ? (float) $sumRow->ads : ((float) ($c->latestMetrics?->ad_spend ?? 0)));
            $grantDays  = $activeGrant?->days_remaining;
            $grantOk    = $activeGrant && $activeGrant->status === 'active';

            // Faturamento anterior pra detectar queda MoM (usado em status/acao).
            // Hotfix 2026-06-19 — usa cust_id (mesmo motivo do revenue atual).
            $revAnterior = null;
            if ($isMesAtual && $custId) {
                $revAnterior = $grossAnterior[$custId]['value'] ?? null;
            }
            if ($revAnterior === null) {
                $revAnterior = (float) ($sumDbAnteriorPorEmpresa[$c->id] ?? 0);
            }
            $quedaMomPct = ($revenue !== null && $revAnterior > 0)
                ? round((($revenue - $revAnterior) / $revAnterior) * 100, 2)
                : null;

            // Ajuste 2026-07-09 · variação de margem de contribuição por empresa:
            // ABSOLUTA (`SUM(contribution_margin)` — valor em R$) vs mesmo range
            // no mês anterior. Fonte SEMPRE Adman (ML não expõe custo unitário;
            // gap conhecido documentado no DESEMP-05). Descartar quando margem
            // anterior <= 0 (mês em que não houve venda com margem cadastrada).
            $margemAtualAbs    = $sumRow ? (float) $sumRow->margem_abs : 0.0;
            $margemAnteriorAbs = (float) ($margemAnteriorPorEmpresa[$c->id] ?? 0.0);

            // Recorte de dias-fim (fix Tomelin 2026-07-10): se a janela atual
            // tem dias finais sem margem (lag da Adman), recorta a MESMA
            // quantidade de dias do fim da janela anterior antes de comparar.
            if ($isMesAtual && $margemAtualAbs > 0 && $margemAnteriorAbs > 0 && $ultimoDiaComMargemPorEmpresa->has($c->id)) {
                $ultimoDia    = Carbon::parse($ultimoDiaComMargemPorEmpresa->get($c->id))->startOfDay();
                $fimAtualCarbon = Carbon::parse($dateTo)->startOfDay();
                $diasSemDados = (int) $ultimoDia->diffInDays($fimAtualCarbon);

                if ($diasSemDados > 0) {
                    $fimAnterRecortado = Carbon::parse($dateToAnterior)->subDays($diasSemDados)->toDateString();

                    $rowAnteriorRecortado = AdmanMetric::where('company_id', $c->id)
                        ->whereBetween('reference_date', [$dateFromAnterior, $fimAnterRecortado])
                        ->whereNotNull('revenue')
                        ->selectRaw('SUM(contribution_margin) as margem_abs, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
                        ->first();

                    if ($rowAnteriorRecortado !== null && (int) $rowAnteriorRecortado->margem_dias > 0) {
                        $margemAnteriorAbs = (float) $rowAnteriorRecortado->margem_abs;
                    } else {
                        $margemAnteriorAbs = 0.0; // sem baseline comparável após o recorte
                    }
                }
            }

            $margemVariacaoPct = $margemAnteriorAbs > 0
                ? round((($margemAtualAbs - $margemAnteriorAbs) / $margemAnteriorAbs) * 100, 2)
                : null;

            // Badge ML: conexão OAuth ativa via mlToken.status === 'active'.
            // Empresas com dados só do Adman API NÃO ganham badge (mesma regra
            // do dashboard PerformanceController::dashboardCarteira).
            $hasMlOauth = (bool) ($c->mlToken && $c->mlToken->status === 'active');

            return [
                'id'                  => $c->id,
                'name'                => $c->name,
                'role'                => $c->pivot->role,
                'tacos'               => $sumRow ? round((float) $sumRow->tacos, 2)  : $c->latestMetrics?->tacos,
                'revenue'             => $revenue,
                'revenue_anterior'    => $revAnterior > 0 ? (float) $revAnterior : null,
                'queda_mom_pct'       => $quedaMomPct,
                'contribution_margin_pct'    => $sumRow ? round((float) $sumRow->margem, 2) : $c->latestMetrics?->contribution_margin_pct,
                'contribution_margin_abs'    => $margemAtualAbs,
                'contribution_margin_prev'   => $margemAnteriorAbs > 0 ? $margemAnteriorAbs : null,
                'contribution_margin_var_pct'=> $margemVariacaoPct,
                'has_ml_oauth'        => $hasMlOauth,
                'ad_spend'            => $adSpendNum,
                'grant_status'        => $activeGrant?->status,
                'grant_expires_at'    => $activeGrant?->expires_at?->toDateString(),
                'grant_days_remaining'=> $grantDays,
                // status + acao derivados abaixo (depois que a meta de revenue
                // por empresa estiver indexada — precisa do $goals carregado).
                '_grant_ok'           => $grantOk,
                '_ad_spend_num'       => $adSpendNum,
            ];
        });

        // Metas de carteira com último resultado
        $portfolioGoals = PortfolioGoal::where('user_id', $user->id)
            ->active()
            ->with('latestResult')
            ->get()
            ->map(fn($g) => [
                'id'              => $g->id,
                'metric'          => $g->metric,
                'metric_label'    => $g->metric_label,
                'target_value'    => $g->target_value,
                'value_type'      => $g->value_type,
                'aggregation'     => $g->aggregation,
                'period_type'     => $g->period_type,
                'role'            => $g->role,
                'description'     => $g->description,
                'baseline_value'  => $g->baseline_value,
                'baseline_period' => $g->baseline_period,
                'latest_result'=> $g->latestResult ? [
                    'period'         => $g->latestResult->period,
                    'realized_value' => $g->latestResult->realized_value,
                    'target_value'   => $g->latestResult->target_value,
                    'achieved'       => $g->latestResult->achieved,
                    'companies_count'=> $g->latestResult->companies_count,
                ] : null,
            ]);

        // Metas por empresa com último resultado
        $companyIds = $companies->pluck('id');
        $goals = Goal::where('active', true)
            ->whereIn('company_id', $companyIds)
            ->with(['results' => fn($q) => $q->orderBy('period', 'desc')->limit(1)])
            ->get()
            ->map(fn($g) => [
                'id'           => $g->id,
                'company_id'   => $g->company_id,
                'metric'       => $g->metric,
                'metric_label' => $g->metric_label,
                'target_value' => $g->target_value,
                'value_type'   => $g->value_type,
                'period_type'  => $g->period_type,
                'latest_result'=> $g->results->first() ? [
                    'period'         => $g->results->first()->period,
                    'realized_value' => $g->results->first()->realized_value,
                    'achieved'       => $g->results->first()->achieved,
                ] : null,
            ]);

        // ── Quick 260619 redesign — derivar Status + Acao recomendada por empresa ──
        // Indexa metas de revenue por company_id para consulta O(1) no map.
        $metasRevenuePorEmpresa = collect($goals)
            ->filter(fn ($g) => $g['metric'] === 'revenue')
            ->keyBy('company_id');

        $companies = $companies->map(function ($c) use ($metasRevenuePorEmpresa) {
            $meta             = $metasRevenuePorEmpresa->get($c['id']);
            $metaTarget       = $meta['target_value'] ?? null;
            $metaRealizado    = $meta['latest_result']['realized_value'] ?? $c['revenue'];
            $metaAchievedPct  = ($metaTarget && $metaTarget > 0 && $metaRealizado !== null)
                ? round(((float) $metaRealizado / (float) $metaTarget) * 100, 1)
                : null;

            // Regras de status (briefing — defaults aprovados):
            //   Critico   : grant inativo OU achieved < 50%
            //   Atencao   : 50-79% OU queda MoM >=10% OU sem meta cadastrada
            //   Saudavel  : achieved >=80% E grant ativo
            $status = 'atencao';
            if (!$c['_grant_ok'] || ($metaAchievedPct !== null && $metaAchievedPct < 50)) {
                $status = 'critico';
            } elseif ($metaAchievedPct !== null && $metaAchievedPct >= 80 && $c['_grant_ok']) {
                $status = 'saudavel';
            } elseif ($c['queda_mom_pct'] !== null && $c['queda_mom_pct'] <= -10) {
                $status = 'atencao';
            }

            // Regras de acao priorizada (quick 260623 v3 — "Ativar Ads" removido):
            //   1) Renovar grant       (grant expira em <=15d)
            //   2) Atingir meta        (achieved < 50%)
            //   3) Recuperar queda     (queda MoM >= 10%)
            //   4) Manter ritmo        (saudavel)
            //   5) —                   (nenhum acionavel claro)
            $acao = '—';
            if ($c['grant_days_remaining'] !== null && $c['grant_days_remaining'] <= 15) {
                $acao = 'Renovar grant';
            } elseif ($metaAchievedPct !== null && $metaAchievedPct < 50) {
                $acao = 'Atingir meta';
            } elseif ($c['queda_mom_pct'] !== null && $c['queda_mom_pct'] <= -10) {
                $acao = 'Recuperar queda';
            } elseif ($status === 'saudavel') {
                $acao = 'Manter ritmo';
            }

            return array_merge($c, [
                'status'               => $status,
                'acao_recomendada'     => $acao,
                'meta_target_revenue'  => $metaTarget !== null ? (float) $metaTarget : null,
                'meta_achieved_pct'    => $metaAchievedPct,
            ]);
        });

        // Faturamento total da carteira no período atual (usa o mesmo cache
        // Adman 30d quando mês atual; SUM DB pra mês passado).
        $totalRevenueAtual = (float) $companies->sum('revenue');

        // Faturamento da carteira no PERÍODO ANTERIOR (mês passado ou janela
        // 30-60d atrás). Mesma lógica de fallback. Hotfix 2026-06-19 — cust_id.
        // Fase 109 — empresa cuja fonte VENCEDORA é 'shopee' lê o anterior de
        // $shopeeSumAnteriorSelf (nunca do Adman, mesmo que tenha cust_id).
        $totalRevenueAnterior = 0.0;
        foreach ($rawCompanies as $c) {
            if ($fontesPorEmpresaSelf->get($c->id) === 'shopee') {
                $rowShopeeAnt = $shopeeSumAnteriorSelf->get($c->id);
                $totalRevenueAnterior += $rowShopeeAnt ? (float) $rowShopeeAnt->total : 0.0;
                continue;
            }

            $rev = null;
            if ($isMesAtual && $c->cust_id) {
                $entry = $grossAnterior[$c->cust_id] ?? null;
                if ($entry && ($entry['value'] ?? null) !== null) {
                    $rev = $entry['value'];
                }
            }
            if ($rev === null) {
                $rev = (float) ($sumDbAnterior[$c->id] ?? 0);
            }
            $totalRevenueAnterior += $rev;
        }

        // Crescimento % vs período anterior. Null se não há baseline (anterior=0)
        // pra UI mostrar "—" em vez de Infinity/NaN.
        $revenueGrowthPct = null;
        if ($totalRevenueAnterior > 0) {
            $revenueGrowthPct = round((($totalRevenueAtual - $totalRevenueAnterior) / $totalRevenueAnterior) * 100, 2);
        }

        // Ajuste 2026-07-09 · Totais de margem de contribuição da carteira +
        // variação vs mesmo período do mês anterior. Média das % de variação
        // por empresa (mesma metodologia do DesempenhoScoreService, mas exposta
        // no shape da carteira para exibição na UI).
        $totalMargemAbsAtual    = (float) $companies->sum('contribution_margin_abs');
        $totalMargemAbsAnterior = (float) $companies->sum(fn ($c) => $c['contribution_margin_prev'] ?? 0);
        $margemGrowthPct        = null;
        if ($totalMargemAbsAnterior > 0) {
            $margemGrowthPct = round((($totalMargemAbsAtual - $totalMargemAbsAnterior) / $totalMargemAbsAnterior) * 100, 2);
        }
        $margemVarMediaEmpresas = $companies
            ->pluck('contribution_margin_var_pct')
            ->filter(fn ($v) => $v !== null)
            ->avg();

        $summary = [
            'total_companies'         => $companies->count(),
            'avg_tacos'               => $companies->whereNotNull('tacos')->avg('tacos'),
            'total_revenue'           => $totalRevenueAtual,
            'total_revenue_anterior'  => $totalRevenueAnterior,
            'revenue_growth_pct'      => $revenueGrowthPct,
            'avg_margin'              => $companies->whereNotNull('contribution_margin_pct')->avg('contribution_margin_pct'),
            // Novas keys (Ajuste 2026-07-09) — expostas para o Portfolio/Show.jsx
            // renderizar os KPIs "Total Faturamento", "Variação de Margem" etc.
            'total_margin_abs'                    => round($totalMargemAbsAtual, 2),
            'total_margin_abs_anterior'           => round($totalMargemAbsAnterior, 2),
            'margin_growth_pct'                   => $margemGrowthPct,
            'margin_growth_avg_per_company_pct'   => $margemVarMediaEmpresas !== null ? round((float) $margemVarMediaEmpresas, 2) : null,
            'companies_ml_oauth'                  => (int) $companies->where('has_ml_oauth', true)->count(),
            'total_ad_spend'          => (float) $companies->sum('ad_spend'),
        ];

        $availablePeriods = collect();
        for ($i = 0; $i < 12; $i++) {
            $availablePeriods->push(now()->subMonths($i)->format('Y-m'));
        }

        // Quick 260617-prt — Cards de alerta da carteira (4 grupos).
        // Calculados aqui pra UI consumir direto sem N+1 client-side.
        $alertas = [
            // Grants expirando em ate 30 dias (inclui ja expirados ou sem grant ativo? — so os ATIVOS proximos do limite).
            'grants_expirando_30d' => $companies
                ->filter(fn($c) => $c['grant_status'] === 'active'
                    && $c['grant_days_remaining'] !== null
                    && $c['grant_days_remaining'] <= 30)
                ->sortBy('grant_days_remaining')
                ->values()
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'grant_expires_at' => $c['grant_expires_at'],
                    'grant_days_remaining' => $c['grant_days_remaining'],
                ])
                ->all(),

            // Empresas em queda MoM — revenue atual < revenue anterior (ambos > 0).
            'empresas_em_queda' => $rawCompanies
                ->map(function ($c) use ($isMesAtual, $sumDbAtual, $sumDbAnterior, $grossAtual, $grossAnterior) {
                    $revAtual = null;
                    $revAnt = null;
                    // Hotfix 2026-06-19 — cust_id (mesmo motivo do bloco principal).
                    if ($isMesAtual && $c->cust_id) {
                        $revAtual = $grossAtual[$c->cust_id]['value'] ?? null;
                        $revAnt = $grossAnterior[$c->cust_id]['value'] ?? null;
                    }
                    if ($revAtual === null) {
                        $m = $sumDbAtual->get($c->id);
                        $revAtual = $m ? (float) $m->total : null;
                    }
                    if ($revAnt === null) {
                        $revAnt = (float) ($sumDbAnterior[$c->id] ?? 0);
                    }
                    if ($revAtual === null || $revAtual <= 0 || $revAnt <= 0 || $revAtual >= $revAnt) {
                        return null;
                    }
                    $queda_pct = round((($revAtual - $revAnt) / $revAnt) * 100, 2);
                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'revenue_atual' => (float) $revAtual,
                        'revenue_anterior' => (float) $revAnt,
                        'queda_pct' => $queda_pct,
                    ];
                })
                ->filter()
                ->sortBy('queda_pct') // queda mais severa primeiro (negativo menor)
                ->values()
                ->all(),

            // Quick 260623 v3 — alerta 'empresas_sem_ad_spend' DESCONTINUADO.
            // Premissa errada (cache Adman MISS + sync ainda nao rodou geravam
            // falso positivo). ECF gerencia Ads em 100% das empresas. Frontend
            // ja foi removido; chave fica como array vazio pra compat.
            'empresas_sem_ad_spend' => [],

            // Top 3 empresas por faturamento no periodo.
            'top_3_revenue' => $companies
                ->filter(fn($c) => $c['revenue'] !== null && $c['revenue'] > 0)
                ->sortByDesc('revenue')
                ->take(3)
                ->values()
                ->map(fn($c) => [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'revenue' => $c['revenue'],
                ])
                ->all(),
        ];

        // ── Quick 260619 redesign — serie temporal diaria do faturamento ──
        // Somatorio diario de revenue (AdmanMetric) de TODAS as empresas da
        // carteira no [dateFrom, dateTo]. Frontend plota como linha "Realizado"
        // junto com Meta Acumulada (target / dias_periodo * dia_index) e
        // Projecao (realizado_ate_hoje + media_diaria * dias_restantes).
        $serieRealizado = AdmanMetric::query()
            ->whereIn('company_id', $companyIdsAll)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->whereNotNull('revenue')
            ->selectRaw('reference_date as data, SUM(revenue) as total')
            ->groupBy('reference_date')
            ->orderBy('reference_date')
            ->get()
            ->keyBy(fn ($r) => (string) $r->data);

        // Phase 48 — meta_carteira_calculada via soma das metas individuais ativas.
        // PortfolioGoal revenue foi descontinuado. Usa Goal.metric='revenue' das
        // empresas da carteira (mesmo criterio do DesempenhoScoreService).
        // Realizado = soma do revenue das empresas QUE TEM meta ativa (like-for-like).
        $goalsRevenue = Goal::where('active', true)
            ->where('metric', 'revenue')
            ->whereIn('company_id', $companyIds)
            ->get(['company_id', 'target_value']);
        $metaCarteiraTarget = $goalsRevenue->isNotEmpty()
            ? (float) $goalsRevenue->sum('target_value')
            : null;
        $metaCarteiraTargetsPorEmpresa = $goalsRevenue->pluck('target_value', 'company_id');
        $metaCarteiraRealizado = $metaCarteiraTarget !== null
            ? (float) $companies
                ->filter(fn ($c) => $metaCarteiraTargetsPorEmpresa->has($c['id']))
                ->sum('revenue')
            : $totalRevenueAtual;
        $metaCarteiraAchieved  = ($metaCarteiraTarget && $metaCarteiraTarget > 0)
            ? round(($metaCarteiraRealizado / $metaCarteiraTarget) * 100, 1)
            : null;
        $metaCarteiraRestante  = $metaCarteiraTarget !== null
            ? max(0.0, $metaCarteiraTarget - $metaCarteiraRealizado)
            : null;

        // Constroi a serie [dateFrom .. dateTo] preenchendo gaps com 0.
        $inicio        = Carbon::parse($dateFrom);
        $fim           = Carbon::parse($dateTo);
        $diasNoPeriodo = $inicio->diffInDays($fim) + 1;
        $hoje          = now()->startOfDay();
        $revenueTimeseries = [];
        $acumulado     = 0.0;
        $dia           = 0;
        for ($d = $inicio->copy(); $d->lte($fim); $d->addDay()) {
            $dia++;
            $key       = $d->toDateString();
            $realDia   = isset($serieRealizado[$key]) ? (float) $serieRealizado[$key]->total : 0.0;
            $acumulado += $realDia;
            $metaAcum  = $metaCarteiraTarget !== null
                ? round(($metaCarteiraTarget / max(1, $diasNoPeriodo)) * $dia, 2)
                : null;
            $isFuturo  = $d->gt($hoje);
            $revenueTimeseries[] = [
                'date'              => $key,
                'realizado'         => $isFuturo ? null : round($acumulado, 2),
                'realizado_dia'     => $isFuturo ? null : round($realDia, 2),
                'meta_acumulada'    => $metaAcum,
                'is_futuro'         => $isFuturo,
            ];
        }
        // Projecao = realizado_hoje + media_diaria_realizada * dias_restantes.
        // Preenche `projecao` em todos os pontos: ate hoje copia realizado, depois extrapola.
        $diasAteHoje      = min($diasNoPeriodo, max(1, $inicio->diffInDays($hoje->isAfter($fim) ? $fim : $hoje) + 1));
        $mediaDiariaReal  = $acumulado > 0 ? ($acumulado / $diasAteHoje) : 0.0;
        $projecaoAcumulada = 0.0;
        foreach ($revenueTimeseries as $idx => &$pt) {
            if ($pt['is_futuro']) {
                $projecaoAcumulada += $mediaDiariaReal;
                $pt['projecao'] = round(($revenueTimeseries[$idx - 1]['projecao'] ?? $acumulado) + $mediaDiariaReal, 2);
            } else {
                $pt['projecao'] = $pt['realizado'];
            }
        }
        unset($pt);

        // ── Periodo vigente da amostra (ex: "01/06 a 22/06") ──
        $amostraFim = $hoje->isAfter($fim) ? $fim : $hoje;
        $periodoAmostra = [
            'from'       => $dateFrom,
            'to'         => $amostraFim->toDateString(),
            'from_label' => $inicio->format('d/m'),
            'to_label'   => $amostraFim->format('d/m'),
            'mes_label'  => $refDate->locale('pt_BR')->isoFormat('MMMM [de] YYYY'),
            'is_mes_atual' => $isMesAtual,
        ];

        // ── Prioridade do dia ── distinct de empresas que aparecem em qualquer
        // alerta acionavel (grants expirando, em queda, sem ad spend). Top 3 NAO
        // entra (e ranking positivo). Distinct evita inflar count quando a mesma
        // empresa cai em 2 alertas.
        //
        // Hotfix 260623 — expõe LISTA detalhada (não só count) pra UI mostrar
        // quais empresas exigem ação. Cada item: {id, name, motivo}. Quando a
        // mesma empresa cai em 2 motivos, junta com " · ".
        $motivosPorId = [];
        foreach ($alertas['grants_expirando_30d'] as $a) {
            $motivosPorId[$a['id']]['name'] = $a['name'];
            $motivosPorId[$a['id']]['motivos'][] = 'Grant vence em ' . ($a['grant_days_remaining'] ?? '?') . 'd';
        }
        foreach ($alertas['empresas_em_queda'] as $a) {
            $motivosPorId[$a['id']]['name'] = $a['name'];
            $motivosPorId[$a['id']]['motivos'][] = 'Queda ' . round($a['queda_pct'] ?? 0, 1) . '%';
        }
        // Quick 260623 v3 — motivo "Sem Ads" descontinuado junto com o alerta.
        $prioridadeListaDetalhada = collect($motivosPorId)
            ->map(fn ($v, $id) => [
                'id'      => $id,
                'name'    => $v['name'],
                'motivos' => $v['motivos'],
            ])
            ->values()
            ->all();
        $prioridadeDoDia = count($prioridadeListaDetalhada);

        // Limpa flags internos (_grant_ok / _ad_spend_num) antes do payload.
        $companies = $companies->map(function ($c) {
            unset($c['_grant_ok'], $c['_ad_spend_num']);
            return $c;
        });

        // Phase 61 Plan 61-01 — enriquecimento condicional com `source`
        // (ADR DATA-04 vocabulário: adman|ml|unified|none). Passa por
        // `MetricsProviderFactory::caseFor()` — chamada barata (só accessors
        // denormalizados, SEM HTTP). Chave ADICIONA metadados no payload;
        // não substitui nenhum campo existente (coexistência com legado).
        if ($this->unifiedMetricsEnabled()) {
            $rawCompaniesById = $rawCompanies->keyBy('id');
            $companies = $companies->map(function ($c) use ($rawCompaniesById) {
                $company = $rawCompaniesById->get($c['id']);
                $c['source'] = $company ? $this->factoryToSource($company) : 'none';
                return $c;
            });
        }

        // ── Phase 74 D-05 — Nota final v2 + comparacao contextual ────────────
        // DesempenhoScoreService v2: 4 parâmetros (NPS/var faturamento/var margem/
        // absenteísmo standby) com nota_final em escala 0-5 e faixa_bonus editável.
        // Comparação contextual usa `nota_final` e componentes.* (nps_medio,
        // var_faturamento_pct, var_margem_pct) em vez do shape v1.
        $mesReferencia = Carbon::now()->startOfMonth();
        // Ajuste 2026-07-10 (audit performance-lentidao): cacheado.
        $performanceProfissional = $this->scoreService->computeCached($user, $mesReferencia);

        // Comparacao contextual com pares do mesmo cargo (analista x analista
        // ou estrategista x estrategista). Identifica cargo via user_setores
        // (fonte da verdade desde quick 260610-f69; users.role eh legacy).
        $cargoSlug = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->where('us.user_id', $user->id)
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->value('c.slug');

        $comparacaoContextual = null;
        if ($cargoSlug) {
            $paresIds = DB::table('user_setores as us')
                ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                ->join('users as u', 'u.id', '=', 'us.user_id')
                ->where('c.slug', $cargoSlug)
                ->where('u.active', true)
                ->pluck('us.user_id')
                ->unique()
                ->values();

            // Calcula nota v2 de cada par (N+1 mas N tipicamente <= 10).
            $scoresPares = collect();
            foreach (User::whereIn('id', $paresIds)->get() as $par) {
                // Ajuste 2026-07-10 (audit performance-lentidao): cacheado.
                $resultadoPar = $this->scoreService->computeCached($par, $mesReferencia);
                // Sem carteira → filtra do grupo (não entra na mediana).
                if (($resultadoPar['sem_carteira'] ?? false) === true) {
                    continue;
                }
                // Fase 92 — pendência da Fase 91 (Distorção A/B): blocked não
                // tem nota calculável (nota_final=null por definição, D-91-01),
                // não pode entrar na amostra de pares. Sem este guard, o
                // `?? 0.0` da linha abaixo transformaria um blocked real em
                // nota 0.0 na comparação, e tamanho_amostra contaria alguém
                // que a mediana já exclui.
                if (($resultadoPar['score_status'] ?? null) === 'blocked') {
                    continue;
                }
                $scoresPares->put($par->id, $resultadoPar);
            }

            // Fase 92 — self-view do próprio profissional blocked: com o
            // guard acima ele nunca entra em $scoresPares, então NÃO monta
            // comparação nenhuma para si mesmo (evita $minhaNota virar 0.0
            // fantasma via fallback $performanceProfissional). O front usa
            // `performance_profissional.score_status` (já passado à view)
            // para exibir "sua nota ainda não é oficial".
            if (($performanceProfissional['score_status'] ?? null) === 'blocked') {
                $comparacaoContextual = null;
            } elseif ($scoresPares->count() >= 2) {
                $meuResultado = $scoresPares->get($user->id) ?? $performanceProfissional;
                $minhaNota    = (float) ($meuResultado['nota_final'] ?? 0.0);

                $notasAll = $scoresPares
                    ->pluck('nota_final')
                    ->filter(fn ($n) => $n !== null)
                    ->map(fn ($n) => (float) $n)
                    ->sort()
                    ->values();

                $abaixoOuIgual = $notasAll->filter(fn ($n) => $n <= $minhaNota)->count();
                $percentil = $notasAll->count() > 0
                    ? (int) round(($abaixoOuIgual / $notasAll->count()) * 100)
                    : 0;

                // Helper: mediana de um componente do compute v2 (ignora nulls).
                // $caminho = 'nps_medio' | 'var_faturamento_pct' | 'var_margem_pct'.
                $medianaPares = function (string $caminho) use ($scoresPares) {
                    $valores = $scoresPares->map(function ($r) use ($caminho) {
                        $v = $r['componentes'][$caminho] ?? null;
                        return is_numeric($v) ? (float) $v : null;
                    })->filter(fn ($v) => $v !== null)->values()->all();
                    if (empty($valores)) return null;
                    sort($valores);
                    $count = count($valores);
                    $mid   = (int) floor($count / 2);
                    return $count % 2 === 1 ? (float) $valores[$mid] : (float) (($valores[$mid - 1] + $valores[$mid]) / 2);
                };

                $relativo = function (?float $meu, ?float $mediana): string {
                    if ($meu === null || $mediana === null) return 'sem_dado';
                    if ($mediana == 0.0) {
                        if ($meu > 0)  return 'acima';
                        if ($meu < 0)  return 'abaixo';
                        return 'na_media';
                    }
                    $delta = ($meu - $mediana) / abs($mediana);
                    if ($delta >= 0.05)  return 'acima';
                    if ($delta <= -0.05) return 'abaixo';
                    return 'na_media';
                };

                $meuComponente = fn (string $caminho) => is_numeric($meuResultado['componentes'][$caminho] ?? null)
                    ? (float) $meuResultado['componentes'][$caminho]
                    : null;

                $notaMediana = $notasAll->count() > 0 ? (float) $notasAll->median() : 0.0;

                $comparacaoContextual = [
                    'cargo_slug'      => $cargoSlug,
                    'cargo_label'     => $cargoSlug === 'analista' ? 'Analista' : 'Estrategista',
                    'tamanho_amostra' => $scoresPares->count(),
                    'percentil'       => $percentil,
                    'classificacao_top' => $percentil >= 80 ? 'Top 20%'
                        : ($percentil >= 60 ? 'Top 40%'
                            : ($percentil >= 40 ? 'Mediana'
                                : 'Inferior')),
                    'medianas' => [
                        'nota_final'          => round($notaMediana, 2),
                        'nps_medio'           => $medianaPares('nps_medio'),
                        'var_faturamento_pct' => $medianaPares('var_faturamento_pct'),
                        'var_margem_pct'      => $medianaPares('var_margem_pct'),
                    ],
                    'relativo' => [
                        'nota_final'          => $relativo($minhaNota, $notaMediana),
                        'nps_medio'           => $relativo($meuComponente('nps_medio'),           $medianaPares('nps_medio')),
                        'var_faturamento_pct' => $relativo($meuComponente('var_faturamento_pct'), $medianaPares('var_faturamento_pct')),
                        'var_margem_pct'      => $relativo($meuComponente('var_margem_pct'),      $medianaPares('var_margem_pct')),
                    ],
                ];
            }
        }

        // ── Phase 48 — Historico NPS mensal do profissional ──
        // Agrupa surveys completadas por month_reference, calculando a media da
        // nota do profissional (dimensao estrategista ou analista conforme cargo).
        //
        // 2026-07-13 — dois ajustes:
        //  1. `->principal()` — só o modelo principal conta.
        //  2. Dual-path via NpsScoreCalculator — o modelo principal é v15, cujas
        //     notas vivem no snapshot `nps_response_answers`, NÃO nas colunas
        //     `score_*` legadas (que ficam null). Ler direto o campo daria zero.
        // 2026-07-13 — dimensão por CARGO canônico (user_setores→cargos), não
        // por isMentor(): estrategistas não têm role='mentor' e cairiam em
        // 'analista', recebendo a nota errada.
        $npsDim = $user->dimensaoNpsDesempenho();
        $npsCalculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        // Phase 96 Plan 04 (AB-96-3 · call-site #8) — resposta invalidada pelo
        // admin some do histórico NPS mensal do profissional.
        $rowsPorMes = NpsSurvey::with(['response' => fn ($q) => $q->valida()->with(['answers', 'survey'])])
            ->principal()
            ->whereIn('company_id', $companyIdsAll)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->orderBy('month_reference')
            ->get()
            ->groupBy(fn ($s) => $s->month_reference?->format('Y-m') ?? $s->completed_at?->format('Y-m'));

        // Fase 116 · NPS não respondido conta como nota mínima (1) — histórico
        // mensal da carteira do profissional. Implementação PRÓPRIA deste
        // controller (não compartilha código com PerformanceController) — a
        // leitura sai INTEIRA do NpsImputationService (nenhuma resolução de
        // responsável/competência/invalidação é reimplementada aqui). O mês
        // de cada linha imputada vem do DISPARO (`competencia_nps`), não de
        // uma resposta — por isso um mês pode aparecer aqui mesmo sem
        // NENHUM survey completed (a query acima nunca traria esse mês, já
        // que só busca status=completed). Sem filtro de data — a query real
        // acima também não filtra por período, varre o histórico inteiro.
        $notasImputadasPorMes = app(\App\Services\Nps\NpsImputationService::class)
            ->notasDoUsuario($user, Carbon::createFromDate(1970, 1, 1), now())
            ->groupBy(fn ($nota) => $nota->competencia_nps->format('Y-m'));

        // Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM link no mês
        // também conta nota 1 no histórico mensal do profissional, mesma
        // régua do bônus (`NpsSemLinkService`). Nenhuma regra de
        // elegibilidade/responsável/invalidação é reimplementada aqui.
        // Piso retroativo (DEC-09-B): esta é leitura em JANELA ROLANTE
        // (início relativo a `now()`), então o piso INTERNO ao serviço
        // garante que ela nunca alcance mais que mês anterior + corrente —
        // o histórico antigo (a query 1970→now acima é só o teto superior
        // da janela, o piso é quem corta o início de verdade) não é
        // reescrito por este ramo.
        $semLinkService     = app(\App\Services\Desempenho\NpsSemLinkService::class);
        $pisoRetroativo     = $semLinkService->pisoRetroativo();
        $notasSemLinkPorMes = $semLinkService
            ->notasDoUsuarioNaJanela($user, Carbon::createFromDate(1970, 1, 1), now(), null, $pisoRetroativo)
            ->groupBy(fn ($nota) => $nota->competencia_nps->format('Y-m'));

        $mesesHistorico = $rowsPorMes->keys()
            ->merge($notasImputadasPorMes->keys())
            ->merge($notasSemLinkPorMes->keys())
            ->unique()->sort()->values();

        $npsHistory = $mesesHistorico
            ->map(function ($month) use ($rowsPorMes, $notasImputadasPorMes, $notasSemLinkPorMes, $npsDim, $npsCalculator) {
                $rows = $rowsPorMes->get($month, collect());
                $scoresReais = $rows
                    ->map(fn ($s) => $s->response ? $npsCalculator->compute($s->response, $npsDim) : null)
                    ->filter(fn ($v) => $v !== null)
                    ->values();

                $scoresImputados = $notasImputadasPorMes->get($month, collect())
                    ->map(fn ($nota) => (float) $nota->nota);

                $scoresSemLink = $notasSemLinkPorMes->get($month, collect())
                    ->map(fn ($nota) => (float) $nota->nota);

                // Cast explícito pra Collection base ANTES do merge — Eloquent
                // Collection::merge() assume itens com getKey() (Models) e
                // quebra ao receber floats/nulls (armadilha corrigida no
                // Plan 116-03, NpsController::index()).
                $scores = collect($scoresReais->all())
                    ->merge($scoresImputados->all())
                    ->merge($scoresSemLink->all());

                return [
                    // `month` é o mês de COLETA (`month_reference` / `competencia_nps`,
                    // ambos gravados com o mês do DISPARO) — contrato preservado,
                    // é a chave que a suíte e qualquer consumidor já usam.
                    'month'       => $month,
                    // 2026-08-14 — mês AVALIADO, que é o que o widget exibe: o
                    // NPS coletado em agosto avalia julho. Mesma régua M/M+1 do
                    // bônus (`NpsJanelaResolver::mesDeColeta()`), lida ao
                    // contrário. Campo ADITIVO: nenhuma nota muda de bucket,
                    // muda só o nome do bucket na tela.
                    'competencia' => \Carbon\Carbon::parse($month . '-01')
                        ->subMonthNoOverflow()->format('Y-m'),
                    'avg'         => $scores->isNotEmpty() ? round((float) $scores->avg(), 2) : null,
                    'count'       => $scores->count(),
                    'ultima_nota' => $scores->isNotEmpty() ? round((float) $scores->last(), 2) : null,
                ];
            })
            ->values()
            ->all();

        // ── Phase 48 — Contadores diferenciais por cargo ──
        // sugador_counters: apenas para analista (cargo='analista' em user_setores).
        // ppa_counters    : apenas para estrategista (cargo='estrategista').
        // Outros cargos e admin recebem null em ambos. cargo_slug expoe o cargo
        // derivado de user_setores (fonte da verdade; NAO usar users.role).
        $sugadorCounters = null;
        $ppaCounters     = null;

        if ($cargoSlug === 'analista') {
            // Empresas onde o user e consultor (analista) — role='consultor' no pivot.
            $analystCompanyIds = $user->consultorCompanies()
                ->where('active', true)
                ->pluck('companies.id');

            $sugadorCounters = [
                'resolvidos'     => Sugador::whereIn('company_id', $analystCompanyIds)
                    ->whereIn('status', [
                        Sugador::STATUS_RESOLVIDO,
                        Sugador::STATUS_MOVIDO,
                        Sugador::STATUS_AUTO_RESOLVIDO,
                    ])
                    ->count(),
                'pendentes'      => Sugador::whereIn('company_id', $analystCompanyIds)
                    ->whereIn('status', [Sugador::STATUS_PENDENTE, Sugador::STATUS_EM_ACAO])
                    ->count(),
                'nao_resolvidos' => Sugador::whereIn('company_id', $analystCompanyIds)
                    ->where('status', Sugador::STATUS_IGNORADO)
                    ->count(),
            ];
        } elseif ($cargoSlug === 'estrategista') {
            // PPAs onde este user e o mentor (estrategista responsavel).
            $ppaCounters = [
                'concluidos_mes' => Ppa::where('mentor_id', $user->id)
                    ->whereNotNull('completed_at')
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)
                    ->count(),
                'em_andamento'   => Ppa::where('mentor_id', $user->id)
                    ->whereNull('completed_at')
                    ->count(),
                'total'          => Ppa::where('mentor_id', $user->id)->count(),
            ];
        }

        // Phase 72 Plan 02 — SC#2 + SC#3: injeta pendencias de NPS restritas
        // a carteira do profissional visualizado (owner do portfolio, nao
        // necessariamente o request user — admin/lider pode ver carteira de
        // terceiros). NpsPendingService::forCarteira filtra internamente por
        // $user->companies() quando nao e admin — semantica correta pro
        // widget "empresas pendentes de NPS" no Portfolio/Show (Plan 72-03).
        $npsPendentes = app(NpsPendingService::class)->forCarteira($user);

        return Inertia::render('Portfolio/Show', [
            'portfolio_user'      => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
            'companies'           => $companies,
            'goals'               => $goals,
            'summary'             => $summary,
            'period'              => $period,
            'available_periods'   => $availablePeriods,
            'has_metric_data'     => $companies->contains(fn($c) => $c['revenue'] !== null),
            'portfolio_goal_metrics' => PortfolioGoal::$metricLabels,
            // Quick 260617-prt — alertas pre-calculados.
            'alertas'             => $alertas,
            // Quick 260619 redesign Carteira UI — dados novos pro novo layout.
            'revenue_timeseries'  => $revenueTimeseries,
            // Phase 48 — meta calculada via Goals individuais (PortfolioGoal revenue removido).
            'meta_carteira_calculada' => [
                'target_value'   => $metaCarteiraTarget,
                'realized_value' => $metaCarteiraRealizado,
                'achieved_pct'   => $metaCarteiraAchieved,
                'restante'       => $metaCarteiraRestante,
                'has_goal'       => $metaCarteiraTarget !== null,
            ],
            'periodo_amostra'     => $periodoAmostra,
            'prioridade_do_dia'   => $prioridadeDoDia,
            'prioridade_lista'    => $prioridadeListaDetalhada,
            // Quick 260623 redesign performance — substitui comparacao_equipe antigo.
            'performance_profissional' => $performanceProfissional,
            'comparacao_contextual'    => $comparacaoContextual,
            // Phase 48 — props diferenciais por cargo.
            'nps_history'             => $npsHistory,
            'sugador_counters'        => $sugadorCounters,
            'ppa_counters'            => $ppaCounters,
            'cargo_slug'              => $cargoSlug,
            // Phase 72 Plan 02 — SC#2 + SC#3: pendencias NPS da carteira
            // visualizada (badge/widget Plan 72-03). Shape padrao
            // NpsPendingService::forCarteira (ver PHASE-72-01-SUMMARY).
            'nps_pendentes'           => $npsPendentes,
        ]);
    }

    // CRUD de metas de carteira (admin only)
    public function storeGoal(Request $request, User $user)
    {
        $data = $request->validate([
            'role'         => 'required|in:consultor,mentor',
            'metric'       => 'required|in:' . implode(',', array_keys(PortfolioGoal::$metricLabels)),
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'aggregation'  => 'required|in:avg,sum',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
        ]);

        $data['user_id'] = $user->id;

        if (in_array($data['metric'], PortfolioGoal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        } else {
            $data['value_type'] = $data['value_type'] ?? 'currency';
        }

        $goal = PortfolioGoal::create([...$data, 'active' => true]);

        // Captura snapshot baseline do período atual para rastrear progresso futuro
        $basePeriod = now()->format('Y-m');
        [$by, $bm] = explode('-', $basePeriod);
        $companies = $goal->getCarteira($basePeriod);
        $values = $companies
            ->map(fn($c) => PortfolioGoal::extractMetricValue($goal->metric, $c->id, (int)$by, (int)$bm))
            ->filter()
            ->values();

        if ($values->isNotEmpty()) {
            $baseline = $goal->aggregation === 'sum' ? $values->sum() : $values->avg();
            $goal->update(['baseline_value' => round($baseline, 4), 'baseline_period' => $basePeriod]);
        }

        return back()->with('success', 'Meta de carteira criada.');
    }

    public function updateGoal(Request $request, PortfolioGoal $goal)
    {
        $data = $request->validate([
            'target_value' => 'required|numeric|min:0',
            'value_type'   => 'nullable|in:currency,percentage',
            'aggregation'  => 'required|in:avg,sum',
            'period_type'  => 'required|in:monthly,quarterly,yearly',
            'description'  => 'nullable|string',
        ]);

        if (in_array($goal->metric, PortfolioGoal::$percentageOnlyMetrics)) {
            $data['value_type'] = 'percentage';
        }

        $goal->update($data);

        return back()->with('success', 'Meta de carteira atualizada.');
    }

    public function destroyGoal(PortfolioGoal $goal)
    {
        $goal->update(['active' => false]);
        return back()->with('success', 'Meta de carteira removida.');
    }
}
