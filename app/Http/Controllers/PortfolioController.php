<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\PortfolioGoal;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsPendingService;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PortfolioController extends Controller
{
    public function __construct(
        private AdmanService $adman,
        private DesempenhoScoreService $scoreService,
        private MetricsProviderFactory $metricsFactory,
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
        // Regras de comparação:
        //  - Mês em curso: dia 1..HOJE vs dia 1..MESMO_DIA do mês anterior
        //    (janela justa dia-a-dia, evita queda artificial no início do mês).
        //  - Mês fechado (passado): mês calendário completo vs mês calendário
        //    anterior completo (ambos fechados, comparação natural).
        $hoje         = now();
        $mesQuery     = $request->query('mes');
        $mesCorrente  = $hoje->copy()->startOfMonth();

        if ($mesQuery && preg_match('/^\d{4}-\d{2}$/', $mesQuery)) {
            $mesSelecionado = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
        } else {
            $mesSelecionado = $mesCorrente->copy();
        }
        $ehMesEmCurso = $mesSelecionado->equalTo($mesCorrente);

        if ($ehMesEmCurso) {
            $inicioMes   = $mesSelecionado->copy();
            $fimMes      = $hoje->copy()->endOfDay();
            $inicioAnter = $mesSelecionado->copy()->subMonth();
            $fimAnter    = $inicioAnter->copy()
                ->setDay(min($hoje->day, $inicioAnter->daysInMonth))
                ->endOfDay();
        } else {
            $inicioMes   = $mesSelecionado->copy();
            $fimMes      = $mesSelecionado->copy()->endOfMonth();
            $inicioAnter = $mesSelecionado->copy()->subMonth();
            $fimAnter    = $inicioAnter->copy()->endOfMonth();
        }

        $dateFrom     = $inicioMes->toDateString();
        $dateTo       = $fimMes->toDateString();
        $dateFromPrev = $inicioAnter->toDateString();
        $dateToPrev   = $fimAnter->toDateString();

        // Empresas da carteira ativas + eager-load mlToken pra badge OAuth.
        $rawCompanies = $user->companies()
            ->with('mlToken')
            ->where('active', true)
            ->orderBy('name')
            ->get();
        $companyIds = $rawCompanies->pluck('id');

        // Metrics agregados por empresa nas duas janelas (Adman é canônico
        // pra margem — ML não expõe custo unitário).
        //
        // Ajuste 2026-07-09 (zero-margin): SUM(contribution_margin) devolve
        // NULL quando não há linhas (empresa sem sync Adman no período) e
        // devolve 0 quando as linhas existem mas o campo está null/0 em todas.
        // O cast para float trata ambos como 0.0 — indistinguível na UI.
        // Fix: contar quantas linhas TÊM contribution_margin não-null via
        // `margem_dias` — se 0, tratamos como "sem dados" (null na UI, "—").
        $atualPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$dateFrom, $dateTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');
        $anteriorPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$dateFromPrev, $dateToPrev])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(contribution_margin) as margem, SUM(CASE WHEN contribution_margin IS NOT NULL THEN 1 ELSE 0 END) as margem_dias')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // Ajuste 2026-07-13 (audit LOJASINVAL + AVF2K): recorte por DIAS
        // COMUNS. Substitui o fix Tomelin (2026-07-10) que só cobria gap
        // NO FIM da janela atual. Agora considera QUALQUER gap em QUALQUER
        // posição de qualquer uma das duas janelas.
        //
        // Motivação:
        //  - AVF2K: sync Adman falhou em 12-13/06 (incidente rate-limit)
        //    → janela anterior tem gap NO FIM, fix Tomelin cobria
        //  - LOJASINVAL: empresa cadastrada em 16/06 → 01-15/06 nunca teve
        //    sync → janela anterior tem gap NO INÍCIO → fix Tomelin NÃO
        //    cobria → variação estourava (+3856%)
        //
        // Solução: comparar SÓ os dias que têm margem em AMBAS as janelas.
        // Se junho tem dado só nos dias 12 e 13 e julho tem 1-12, compara
        // apenas o dia 12 vs dia 12 (offset alinhado por DAY(reference_date)).
        //
        // 2 queries por request: um SELECT que agrega dias com margem em
        // cada janela; depois interseção em PHP; depois SUM só pros dias
        // comuns (feito no closure do map).
        // Helper cross-DB (SQLite não tem DAY()): agrega em PHP após pluck.
        // Carbon lida com string ISO OU objeto Carbon (cast 'date' do model).
        $extrairDiasDoMes = function ($rows) {
            return $rows->pluck('reference_date')
                ->map(fn ($d) => Carbon::parse($d)->day)
                ->unique()
                ->values()
                ->all();
        };

        $diasComMargemAtualPorEmpresa    = collect();
        $diasComMargemAnteriorPorEmpresa = collect();

        if (! $companyIds->isEmpty()) {
            $diasComMargemAtualPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
                ->whereBetween('reference_date', [$dateFrom, $dateTo])
                ->whereNotNull('contribution_margin')
                ->get(['company_id', 'reference_date'])
                ->groupBy('company_id')
                ->map($extrairDiasDoMes);

            $diasComMargemAnteriorPorEmpresa = AdmanMetric::whereIn('company_id', $companyIds)
                ->whereBetween('reference_date', [$dateFromPrev, $dateToPrev])
                ->whereNotNull('contribution_margin')
                ->get(['company_id', 'reference_date'])
                ->groupBy('company_id')
                ->map($extrairDiasDoMes);
        }

        // Cache Adman gross (fonte preferencial de revenue no mês atual —
        // mais completa que SUM DB local; hotfix 2026-06-19).
        $custIds = $rawCompanies->map(fn ($c) => $c->cust_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $grossAtual = $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo);

        $empresas = $rawCompanies->map(function ($c) use ($atualPorEmpresa, $anteriorPorEmpresa, $grossAtual, $diasComMargemAtualPorEmpresa, $diasComMargemAnteriorPorEmpresa, $inicioMes, $inicioAnter) {
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

            // Recorte por DIAS COMUNS (ajuste 2026-07-13): se as duas janelas
            // têm gap em posições diferentes, considera SÓ os dias que têm
            // margem em AMBAS. Ex: junho só tem dado em 06-12 e 06-13, julho
            // tem 01-12 → compara apenas o dia 12 vs dia 12. Substitui o fix
            // Tomelin (só-gap-no-fim) por versão que cobre gap em qualquer
            // posição, mais robusta pra empresas cadastradas em meio de mês.
            if ($temMargemAtual && $temMargemAnterior) {
                $diasAtual  = $diasComMargemAtualPorEmpresa->get($c->id, []);
                $diasAnter  = $diasComMargemAnteriorPorEmpresa->get($c->id, []);
                $diasComuns = array_values(array_intersect($diasAtual, $diasAnter));

                // Se AMBAS as janelas cobrem exatamente os mesmos dias, o SUM
                // original já está comparável — não precisa reconsultar.
                $mudouAtual = count($diasComuns) !== count($diasAtual);
                $mudouAnter = count($diasComuns) !== count($diasAnter);

                if (empty($diasComuns)) {
                    // Sem dia coincidente → não dá pra comparar. Preserva
                    // exibição dos SUMs originais (info factual do que existe),
                    // mas anula a variação — UI mostra "—" com tooltip.
                    $margemAnterior = null;
                } elseif ($mudouAtual || $mudouAnter) {
                    // Reagrega SÓ nos dias comuns. Converte cada offset (dia do
                    // mês) em data completa (Y-m-d) pra cada janela — cross-DB.
                    $datasAtual = array_map(
                        fn ($d) => $inicioMes->copy()->setDay($d)->toDateString(),
                        $diasComuns,
                    );
                    $datasAnter = array_map(
                        fn ($d) => $inicioAnter->copy()->setDay($d)->toDateString(),
                        $diasComuns,
                    );

                    // `whereIn` não bate pois o cast 'date' serializa com
                    // timestamp ('YYYY-MM-DD 00:00:00'). whereDate normaliza.
                    $margemAtual = (float) AdmanMetric::where('company_id', $c->id)
                        ->where(function ($q) use ($datasAtual) {
                            foreach ($datasAtual as $d) {
                                $q->orWhereDate('reference_date', $d);
                            }
                        })
                        ->whereNotNull('contribution_margin')
                        ->sum('contribution_margin');

                    $margemAnterior = (float) AdmanMetric::where('company_id', $c->id)
                        ->where(function ($q) use ($datasAnter) {
                            foreach ($datasAnter as $d) {
                                $q->orWhereDate('reference_date', $d);
                            }
                        })
                        ->whereNotNull('contribution_margin')
                        ->sum('contribution_margin');
                }
            }

            // Só calcula variação quando temos dados válidos EM AMBAS as janelas
            // E a baseline anterior é > 0. Caso contrário mostra "—" (evita o
            // artificial -100% quando a empresa acabou de ativar no mês atual).
            $margemVarPct = null;
            if ($margemAtual !== null && $margemAnterior !== null && $margemAnterior > 0) {
                $margemVarPct = round((($margemAtual - $margemAnterior) / $margemAnterior) * 100, 2);
            }

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

            return [
                'id'                          => $c->id,
                'name'                        => $c->name,
                'faturamento'                 => $revenue !== null ? round($revenue, 2) : null,
                'margem_contribuicao'         => $margemAtual !== null ? round($margemAtual, 2) : null,
                'margem_contribuicao_anterior'=> $margemAnterior !== null ? round($margemAnterior, 2) : null,
                'margem_variacao_pct'         => $margemVarPct,
                'motivo_sem_margem'           => $motivoSemMargem,
                'has_ml_oauth'                => (bool) ($c->mlToken && $c->mlToken->status === 'active'),
            ];
        })->values();

        // Totais consolidados da carteira — só empresas com dados válidos entram
        // (nulls filtrados pelo ?? 0 no sum, mas contamos separado pra flag).
        $totalFaturamento    = (float) $empresas->sum('faturamento');
        $totalMargemAtual    = (float) $empresas->sum(fn ($e) => $e['margem_contribuicao'] ?? 0);
        $totalMargemAnterior = (float) $empresas->sum(fn ($e) => $e['margem_contribuicao_anterior'] ?? 0);

        // Ajuste 2026-07-10 (audit Gabriela): variação % agora é a MÉDIA das
        // variações POR EMPRESA — mesma fórmula que DesempenhoScoreService::
        // computeVarMargem usa no ranking. Antes usava variação do total
        // agregado (SUM/SUM), que pesava empresas grandes e podia divergir do
        // ranking em sinal (Gabriela: ranking +18,2% vs carteira -16,6%).
        // Alinhamento com ranking = fonte de verdade da nota / bônus.
        // Totais em R$ acima continuam agregados (info factual de volume).
        $variacoesPorEmpresa = $empresas
            ->pluck('margem_variacao_pct')
            ->filter(fn ($v) => $v !== null);

        $variacaoMargemPct = $variacoesPorEmpresa->isNotEmpty()
            ? round((float) $variacoesPorEmpresa->avg(), 2)
            : null;

        // Contadores pra UI expor transparência sobre qualidade dos dados.
        $empresasSemMargem = (int) $empresas->whereNull('margem_contribuicao')->count();

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
            ],
            'empresas' => $empresas,
            'periodo' => [
                'em_curso'         => $ehMesEmCurso,
                'mes_selecionado'  => $mesSelecionado->format('Y-m'),
                'meses_disponiveis' => $mesesDisponiveis,
                'dia_atual'        => $ehMesEmCurso ? $hoje->day : $mesSelecionado->daysInMonth,
                'dias_no_mes'      => $mesSelecionado->daysInMonth,
                'mes_label'        => mb_strtolower($mesSelecionado->translatedFormat('F Y')),
                'range_atual'      => sprintf('%s até %s', $inicioMes->format('d/m'), $fimMes->format('d/m')),
                'range_anterior'   => sprintf('%s até %s', $inicioAnter->format('d/m'), $fimAnter->format('d/m')),
            ],
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
        $period = $request->get('period', '30');
        $days   = match ($period) {
            '1'   => 1,
            '7'   => 7,
            '180' => 180,
            default => 30,
        };
        $since = now()->subDays($days);

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
        $dateFrom = $since->toDateString();
        $dateTo   = now()->toDateString();

        $portfolios = $todos->map(function ($item) use ($dateFrom, $dateTo) {
            $u    = $item['user'];
            $tipo = $item['tipo'];

            $companies = ($tipo === 'estrategista')
                ? $u->estrategistaCompanies()->where('active', true)->get(['companies.id', 'companies.adman_account_id', 'companies.ml_store_id'])
                : $u->consultorCompanies()->where('active', true)->get(['companies.id', 'companies.adman_account_id', 'companies.ml_store_id']);

            if ($companies->isEmpty()) return null;

            $companyIds = $companies->pluck('id');
            $custIds    = $companies->map(fn ($c) => $c->cust_id)->filter()->unique()->values()->all();

            // SUM DB (fallback completo) + cache Adman pra empresas com custId.
            $sumDb = AdmanMetric::whereIn('company_id', $companyIds)
                ->where('reference_date', '>=', $dateFrom)
                ->selectRaw('company_id, SUM(revenue) as rev, SUM(ad_spend) as ads, AVG(contribution_margin_pct) as margem')
                ->groupBy('company_id')
                ->get()
                ->keyBy('company_id');

            $gross   = $this->adman->getCachedGrossBillingsMany($custIds, $dateFrom, $dateTo);
            $account = $this->adman->getCachedAccountMetricsMany($custIds, $dateFrom, $dateTo);

            $totalRevenue = 0.0;
            $totalAdSpend = 0.0;
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
            // estavel; SUM DB ja era a fonte do campo aqui).
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

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'tipo'            => $tipo,
                'role'            => $u->role,
                'companies_count' => $companyIds->count(),
                'avg_tacos'       => $tacosCarteira,
                'total_revenue'   => round($totalRevenue, 2),
                'avg_margin'      => $avgMargin !== null ? round((float) $avgMargin, 2) : null,
                'total_ad_spend'  => round($totalAdSpend, 2),
            ];
        })->filter()->sortBy('name')->values();

        // Phase 61 Plan 61-01 — enriquecimento condicional com `source_counts`
        // por profissional. Quando flag ON, agrega `{adman, ml, unified, none}`
        // varrendo a carteira de cada user via `factoryToSource(Company)`
        // (ADR DATA-04). Recomputa a lista de empresas por user — o closure
        // de $portfolios já retornou payload sem acesso à coleção original.
        if ($this->unifiedMetricsEnabled()) {
            $portfolios = $portfolios->map(function ($row) use ($todos) {
                $item = $todos->first(fn ($t) => $t['user']->id === $row['id']);
                if (! $item) {
                    return $row;
                }
                $u    = $item['user'];
                $tipo = $item['tipo'];
                $companies = ($tipo === 'estrategista')
                    ? $u->estrategistaCompanies()->where('active', true)->with('mlToken')->get()
                    : $u->consultorCompanies()->where('active', true)->with('mlToken')->get();

                $sourceCounts = ['adman' => 0, 'ml' => 0, 'unified' => 0, 'none' => 0];
                foreach ($companies as $c) {
                    $sourceCounts[$this->factoryToSource($c)]++;
                }

                return array_merge($row, ['source_counts' => $sourceCounts]);
            });
        }

        return Inertia::render('Portfolio/Carteiras', [
            'user_portfolios' => $portfolios,
            'period'          => $period,
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
        $companies = $rawCompanies->map(function ($c) use ($isMesAtual, $sumDbAtual, $grossAtual, $grossAnterior, $sumDbAnteriorPorEmpresa, $accountAtual, $margemAnteriorPorEmpresa, $ultimoDiaComMargemPorEmpresa, $dateFromAnterior, $dateToAnterior, $dateTo) {
            $activeGrant = $c->grants->where('status', 'active')->first();
            $custId      = $c->cust_id; // accessor: adman_account_id ?: ml_store_id

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
        $totalRevenueAnterior = 0.0;
        foreach ($rawCompanies as $c) {
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
                $scoresPares->put($par->id, $resultadoPar);
            }

            if ($scoresPares->count() >= 2) {
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
        $npsDim = $user->isMentor() ? 'estrategista' : 'analista';
        $npsCalculator = app(\App\Services\Nps\NpsScoreCalculator::class);
        $npsHistory = NpsSurvey::with(['response.answers', 'response.survey'])
            ->principal()
            ->whereIn('company_id', $companyIdsAll)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->orderBy('month_reference')
            ->get()
            ->groupBy(fn ($s) => $s->month_reference?->format('Y-m') ?? $s->completed_at?->format('Y-m'))
            ->map(function ($rows, $month) use ($npsDim, $npsCalculator) {
                $scores = $rows
                    ->map(fn ($s) => $s->response ? $npsCalculator->compute($s->response, $npsDim) : null)
                    ->filter(fn ($v) => $v !== null)
                    ->values();
                return [
                    'month'       => $month,
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
