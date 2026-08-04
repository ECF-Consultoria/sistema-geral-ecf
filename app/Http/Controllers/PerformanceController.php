<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\Publicacao;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotReader;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\PlanoMetasPublicacaoService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PerformanceController extends Controller
{
    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricPeriodResolver $periodResolver,
        private CompanyScoreSnapshotReader $companyScoreReader,
    ) {}

    public function index(Request $request)
    {
        // Ranking completo é EXCLUSIVO de admin (decisão 2026-07-21). O link
        // continua no menu para quem tem `core.performance` (admin + líder de
        // Performance), mas o não-admin que clicar é redirecionado ao PRÓPRIO
        // desempenho individual — cada um vê a sua página, não o ranking geral.
        if (! $request->user()->isAdmin()) {
            return redirect()->route('performance.show', $request->user());
        }

        // Phase 49 UAT 2026-06-30: /performance é exclusivamente consultoria.
        // Publicações tem rota própria /publicacao/desempenho via indexPublicacao().
        // Param ?setor=polos é IGNORADO aqui — qualquer tentativa retorna ranking de consultoria.
        $setor = 'consultoria';

        $period = $request->get('period', '30');

        // Fase 92 (DESEMP-08 SC3) — filtro de auditoria ?contexto=, VIEW-ONLY.
        // Whitelist explícita (mesmo padrão de PortfolioController::contextoFiltro,
        // Fase 90). NUNCA repassar o valor cru: nem pra query, nem pro
        // computeCached()/compute() (DESEMP-02 proíbe um segundo score por setor).
        // O `param` resolvido só é ecoado como prop pra filtragem client-side
        // (Plan 92-02) sobre os metadados já presentes no payload.
        $contextoFiltro = $this->contextoFiltro($request);

        // Filtro opcional por cargo (analista/estrategista); null = Geral (todos)
        $cargo = $request->get('cargo');
        if (!in_array($cargo, ['analista', 'estrategista'], true)) {
            $cargo = null; // ignora valores inválidos e 'geral'
        }

        $since = match ($period) {
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };

        // Fonte canônica: cargo via user_setores → cargos (desde quick 260610-f69).
        // Alinhado ao widget "Desempenho da equipe" do DashboardController (Phase 45 fix).
        $users = User::where('active', true)
            ->whereExists(function ($q) {
                $q->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->get(['id', 'name', 'role']);

        // ── Phase 74 D-05/D-07 — ranking por NOTA FINAL (motor v2) ──
        // Cada user passa pelo DesempenhoScoreService v2 (4 parâmetros média
        // direta em escalas naturais). Estratégia: prefere snapshot MENSAL do
        // último mês fechado (DESEMP-14); fallback a compute() do mês em curso
        // parcial quando o user ainda não tem snapshot mensal (transição).
        //
        // Ajuste 2026-07-09 — filtro de mês (?mes=YYYY-MM). Se ausente ou
        // igual ao mês em curso, calcula ao vivo (parcial). Se passado (mês
        // fechado), carrega snapshot mensal — permite auditar rankings de
        // meses passados pra consolidação de bônus.
        //
        // DESEMP-10 · users com sem_carteira=true são REMOVIDOS do ranking.
        // Identifica cargo (analista/estrategista) via user_setores → cargos
        // (fonte da verdade desde quick 260610-f69). users.role eh legacy.
        //
        // Fase 104 (UIP-02/UIP-03) — `?modo=` é conveniência de UI que decide
        // QUAL mês vira `$mesReferencia`; NÃO cria segundo score (DESEMP-02),
        // só escolhe o parâmetro passado ao `computeCached()` já existente.
        // `$periodoResolvido` é a ÚNICA chamada a `MetricPeriodResolver::resolve()`
        // desta página (1× nível de página) — fonte tanto de `$mesReferencia`
        // (quando `modo=bonus_atual`) quanto do payload `periodo`/`bonus` mais
        // abaixo. NÃO ler periodo do sub-array de `compute()` (só 6 chaves,
        // sem `is_closed`/`bonus_*`).
        // Fase 1 do plano de otimização (2026-07-21): a resolução de período
        // saiu daqui pro helper `resolveContextoPeriodo()` — FONTE ÚNICA
        // compartilhada com `show()`. `$bonus`/`$nps` já vêm prontos.
        $ctx              = $this->resolveContextoPeriodo($request);
        $mesReferencia    = $ctx['mesReferencia'];
        $mesCorrente      = $ctx['mesCorrente'];
        $periodoResolvido = $ctx['periodo'];
        $ehMesEmCurso     = $ctx['ehMesEmCurso'];
        $bonusMeta        = $ctx['bonus'];

        $cargosPorUser = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->select('us.user_id', 'c.slug')
            ->get()
            ->keyBy('user_id');

        // Snapshot mensal do mês selecionado (se existir) — evita recomputar
        // em meses passados fechados. Para o mês em curso, snapshot ainda
        // não existe (fecha só dia 1 do mês seguinte) → compute() live.
        $snapshotsMensal = DesempenhoScoreSnapshot::mensal()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('mes_referencia', $mesReferencia->toDateString())
            ->get()
            ->keyBy('user_id');

        // Fase 106 Plan 02 (PERF-01 · SC2/SC3) — gate quente/frio. Em período
        // FECHADO, cada profissional sem cache pronto (`isCached`) vira
        // placeholder `calculando:true` em vez de pagar o compute() síncrono
        // (~14 users × chamadas Adman/ML => timeout). `$usuariosFrios` é
        // coletado aqui e usado LOGO ABAIXO (fora do map) pra disparar o warm
        // sob-demanda uma única vez, com lock anti-empilhamento (Task 2).
        $usuariosFrios = [];

        $rankingRaw = $users->map(function ($u) use ($cargosPorUser, $snapshotsMensal, $mesReferencia, $ehMesEmCurso, $periodoResolvido, &$usuariosFrios) {
            $cargoSlug = $cargosPorUser->get($u->id)?->slug ?? ($u->isMentor() ? 'estrategista' : 'analista');

            // Gate SC2/SC3 — profissional sem cache pronto NÃO é computado ao
            // vivo na tela (evita o fan-out ML/Adman de ~14s/user síncrono).
            // Frio: devolve placeholder `calculando:true` e coleta o ID pro
            // warm sob-demanda em background; o front faz poll e preenche.
            //
            // 2026-07-22: gate estendido ao MÊS EM CURSO também (antes só
            // período fechado). A Fase 106 assumia "mês em curso está sempre
            // aquecido pelo warm agendado" — mas quando o warm não completa
            // (ex.: diff frio deixando o compute lento), o ranking Em curso
            // caía no compute ao vivo e travava ~87s pra 6 users frios.
            if (! $this->scoreService->isCached($u, $mesReferencia)) {
                $usuariosFrios[] = $u->id;

                return [
                    'id'                    => $u->id,
                    'name'                  => $u->name,
                    'role'                  => $u->role,
                    'cargo_slug'            => $cargoSlug,
                    'cargo_label'           => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
                    'empresas_carteira'     => 0,
                    'empresas_com_baseline' => 0,
                    'sem_carteira'          => false,
                    'motivo'                => null,
                    'nota_final'            => null,
                    'faixa_bonus'           => null,
                    'faixa_promovida'       => false,
                    'componentes'           => [
                        'nps_medio'           => null,
                        'var_faturamento_pct' => null,
                        'var_margem_pct'      => null,
                        'absenteismo_pct'     => null,
                    ],
                    'pontos_componentes'    => null,
                    'mes_referencia'        => $mesReferencia->toDateString(),
                    'empresas_unicas'               => 0,
                    'vinculos_servico'               => 0,
                    'vinculos_financeiros'           => 0,
                    'vinculos_sem_fonte_financeira'  => 0,
                    'score_status'                   => 'calculando',
                    'componentes_disponiveis'        => null,
                    'calculando'                     => true,
                ];
            }

            // Mês em curso → compute live. Mês passado → prefere snapshot mensal
            // fechado; se não existe (user sem snapshot naquele mês), compute
            // como fallback.
            $snap = $snapshotsMensal->get($u->id);
            if (! $ehMesEmCurso && $snap) {
                $resultado = $snap->breakdown_json ?? [];
                if (! isset($resultado['componentes'])) {
                    $resultado = $this->scoreService->computeCached($u, $mesReferencia);
                }
            } else {
                // Ajuste 2026-07-10 (audit performance-lentidao): usa versão
                // cacheada — antes cold cache demorava 70s pra 11 users;
                // agora resposta subsequente <1s.
                $resultado = $this->scoreService->computeCached($u, $mesReferencia);
            }

            $componentes = $resultado['componentes'] ?? [];

            return [
                'id'                    => $u->id,
                'name'                  => $u->name,
                'role'                  => $u->role,
                'cargo_slug'            => $cargoSlug,
                'cargo_label'           => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
                'empresas_carteira'     => (int) ($resultado['empresas_carteira'] ?? 0),
                'empresas_com_baseline' => (int) ($resultado['empresas_com_baseline'] ?? 0),
                'sem_carteira'          => (bool) ($resultado['sem_carteira'] ?? false),
                'motivo'                => $resultado['motivo'] ?? null,
                'nota_final'            => $resultado['nota_final'] ?? null,
                'faixa_bonus'           => $resultado['faixa_bonus'] ?? null,
                'faixa_promovida'       => (bool) ($resultado['faixa_promovida'] ?? false),
                'componentes'           => [
                    'nps_medio'           => $componentes['nps_medio']           ?? null,
                    'var_faturamento_pct' => $componentes['var_faturamento_pct'] ?? null,
                    'var_margem_pct'      => $componentes['var_margem_pct']      ?? null,
                    'absenteismo_pct'     => $componentes['absenteismo_pct']     ?? null,
                ],
                'pontos_componentes'    => $resultado['pontos_componentes'] ?? null,
                'mes_referencia'        => $resultado['mes_referencia'] ?? $mesReferencia->toDateString(),
                // ── Fase 92 (DESEMP-08) — passthrough dos 6 metadados de elegibilidade
                // já calculados pelo DesempenhoScoreService (Fase 91). NÃO recomputa —
                // lê direto de $resultado (compute cacheado ou breakdown_json do snapshot
                // mensal, que também já contém estas chaves).
                'empresas_unicas'               => (int) ($resultado['empresas_unicas'] ?? 0),
                'vinculos_servico'               => (int) ($resultado['vinculos_servico'] ?? 0),
                'vinculos_financeiros'           => (int) ($resultado['vinculos_financeiros'] ?? 0),
                'vinculos_sem_fonte_financeira'  => (int) ($resultado['vinculos_sem_fonte_financeira'] ?? 0),
                'score_status'                   => (string) ($resultado['score_status'] ?? 'blocked'),
                'componentes_disponiveis'        => $resultado['componentes_disponiveis'] ?? null,
                'calculando'                     => false,
            ];
        });

        // ── Fase 106 Plan 02 (SC2) — expõe se há warm em andamento ──────────
        $aquecendo = ! empty($usuariosFrios);

        // Dispatch do warm sob-demanda (Task 2) — só quando há ≥1 frio.
        // Lock de 3min por mês evita empilhar job a cada poll de 6s do front
        // (RESEARCH Pitfall 3, T-106-03): só o 1º request dentro da janela
        // adquire o lock e dispara; requests seguintes (mesmo mês) veem o
        // lock já ocupado e não disparam 2º job. `$usuariosFrios` vem da
        // query interna já filtrada (nunca de input cru — T-106-04);
        // `$mesReferencia` já validado por preg_match no bloco de resolução
        // de mês acima.
        if (! empty($usuariosFrios)) {
            $lockKey = 'desempenho.warm.lock.' . $mesReferencia->format('Y-m');
            if (Cache::add($lockKey, true, now()->addMinutes(3))) {
                Artisan::queue('desempenho:warm-cache', [
                    '--mes'  => $mesReferencia->format('Y-m'),
                    '--user' => array_values(array_unique($usuariosFrios)),
                ]);
            }
        }

        // DESEMP-10 · remove users sem carteira antes do sort.
        $rankingRaw = $rankingRaw->reject(fn ($r) => $r['sem_carteira'] === true);

        // Ordena por nota_final DESC (nulls last — nunca à frente de nota real).
        $ranking = $rankingRaw
            ->sortByDesc(fn ($r) => $r['nota_final'] ?? -1)
            ->values()
            ->map(function ($r, $idx) {
                $r['posicao'] = $idx + 1;
                return $r;
            });

        // ── Phase 46 Plan 46-02 — enriquece ranking com deltas longitudinais ──
        // Phase 74 D-02 · filtro adicional `mes_referencia IS NULL` — o snapshot
        // DIÁRIO é o único elegível para deltas rolling; o snapshot mensal
        // fechado (mes_referencia populada) é comparado à parte via
        // `delta_vs_mes_passado`. Sem esse filtro, o cálculo mistura os 2 modos.
        //   - delta_vs_ontem          = nota_atual − snapshot diário mais recente STRICTLY < hoje
        //   - delta_vs_semana_passada = nota_atual − snapshot diário mais recente <= today-7d
        //   - delta_vs_mes_passado    = nota_atual − snapshot mensal M-2 (2 meses atrás)
        // O `score` em row diário representa nota_final×20 (motor v2) — o delta
        // volta a ser expressa em escala 0-5 dividindo por 20 e arredondando.
        $userIds = $ranking->pluck('id')->all();
        $ontem          = collect();
        $semanaPassada  = collect();
        $mesRetrasado   = collect();
        if (!empty($userIds)) {
            $hojeStr        = now()->toDateString();
            $semanaCorte    = now()->subDays(7)->toDateString();
            $mesRetrasadoStr = Carbon::now()->subMonths(2)->startOfMonth()->toDateString();

            $snapshotsOntem = DesempenhoScoreSnapshot::query()
                ->whereIn('user_id', $userIds)
                ->whereNull('mes_referencia')
                ->whereDate('ref_date', '<', $hojeStr)
                ->orderBy('ref_date', 'desc')
                ->get(['user_id', 'ref_date', 'score']);
            $ontem = $snapshotsOntem->groupBy('user_id')->map(fn ($g) => $g->first());

            $snapshotsSemana = DesempenhoScoreSnapshot::query()
                ->whereIn('user_id', $userIds)
                ->whereNull('mes_referencia')
                ->whereDate('ref_date', '<=', $semanaCorte)
                ->orderBy('ref_date', 'desc')
                ->get(['user_id', 'ref_date', 'score']);
            $semanaPassada = $snapshotsSemana->groupBy('user_id')->map(fn ($g) => $g->first());

            // Mensal M-2 (mês retrasado) — usado como baseline pro delta mensal.
            $snapshotsMesRetrasado = DesempenhoScoreSnapshot::mensal()
                ->whereIn('user_id', $userIds)
                ->whereDate('mes_referencia', $mesRetrasadoStr)
                ->get(['user_id', 'mes_referencia', 'score']);
            $mesRetrasado = $snapshotsMesRetrasado->keyBy('user_id');
        }

        $ranking = $ranking->map(function ($r) use ($ontem, $semanaPassada, $mesRetrasado) {
            // Nota final volta pra escala 0-5; scores legados são 0-100 (score/20).
            $notaHoje = $r['nota_final'] !== null ? (float) $r['nota_final'] : null;

            $delta = function ($snap) use ($notaHoje) {
                if (! $snap || $notaHoje === null) return null;
                $notaSnap = ((float) $snap->score) / 20.0;
                return round($notaHoje - $notaSnap, 2);
            };

            $r['delta_vs_ontem']          = $delta($ontem->get($r['id']));
            $r['delta_vs_semana_passada'] = $delta($semanaPassada->get($r['id']));
            $r['delta_vs_mes_passado']    = $delta($mesRetrasado->get($r['id']));
            return $r;
        });

        // Filtra por cargo pós-cálculo (cargo_slug já presente em cada item do ranking)
        if ($cargo !== null) {
            $ranking = $ranking->filter(fn ($r) => $r['cargo_slug'] === $cargo)->values();
        }

        // Meses disponíveis pro filtro — últimos 6 meses (mês corrente +
        // 5 anteriores), permitindo consulta histórica pra auditar bônus.
        $mesesDisponiveis = [];
        for ($i = 0; $i < 6; $i++) {
            $m = $mesCorrente->copy()->subMonths($i);
            $mesesDisponiveis[] = [
                'value' => $m->format('Y-m'),
                'label' => mb_strtolower($m->translatedFormat('F/Y')),
                'em_curso' => $m->equalTo($mesCorrente),
            ];
        }

        return Inertia::render('Performance/Index', [
            'ranking'            => $ranking,
            'period'             => $period,
            'setor'              => 'consultoria',
            'cargo'              => $cargo,
            'mes_selecionado'    => $mesReferencia->format('Y-m'),
            'mes_em_curso'       => $ehMesEmCurso,
            'meses_disponiveis'  => $mesesDisponiveis,
            // Fase 92 (DESEMP-08 SC3) — filtro de auditoria view-only; front
            // decide o que destacar/filtrar usando os metadados já presentes
            // em cada linha do `ranking` (sem novo round-trip ao backend).
            'contexto'           => $contextoFiltro['param'],
            // Fase 104 (UIP-02/UIP-03) — objeto periodo do MetricPeriodResolver
            // (shape completo, 14 chaves incl. is_closed/bonus_*), substitui a
            // string estática que só existia no dashboardCarteira (linha 563,
            // fora de escopo). `bonus` só não-null em período fechado.
            'periodo'            => $periodoResolvido,
            'bonus'              => $bonusMeta,
            // Fase 106 Plan 02 (SC2) — true quando ≥1 profissional está frio
            // (calculando em background); front usa pra exibir aviso/poll.
            'aquecendo'          => $aquecendo,
        ]);
    }

    /**
     * Fase 92 (DESEMP-08 SC3) — resolve `?contexto=` via whitelist explícita.
     * Espelha `PortfolioController::contextoFiltro()` (Fase 90). Valores
     * aceitos: 'todos' (default/inválido) | 'performance' | 'shopee'.
     *
     * IMPORTANTE: o retorno é usado SÓ como prop de exibição — `setor` nunca
     * é passado para `DesempenhoScoreService::computeCached()`/`compute()`
     * nem para nenhuma query do ranking (proibido criar um segundo score por
     * setor — DESEMP-02).
     */
    private function contextoFiltro(Request $request): array
    {
        return match ($request->query('contexto')) {
            'performance' => ['param' => 'performance', 'setor' => Servico::SETOR_PERFORMANCE],
            'shopee'      => ['param' => 'shopee',      'setor' => Servico::SETOR_SHOPEE],
            default       => ['param' => 'todos',       'setor' => null],
        };
    }

    /**
     * Ranking de desempenho do setor de publicação.
     * Acessível via GET /publicacao/desempenho (permission: mlb.dashboard).
     * Reutiliza a lógica de indexPolos() — mesma page, setor='polos'.
     */
    public function indexPublicacao(Request $request): \Inertia\Response
    {
        return $this->indexPolos($request);
    }

    /**
     * Dashboard operacional da Carteira do Analista/Estrategista.
     *
     * Chamado por DashboardController::mercadolivre() quando o user é
     * Analista (consultor) ou Estrategista (mentor) — mas NÃO líder de
     * Performance nem admin (esses seguem no dashboard admin tradicional).
     *
     * Phase 74 D-05 — puxa dados REAIS via DesempenhoScoreService v2 +
     * queries dedicadas por empresa da carteira (revenue AdmanMetric, NPS
     * NpsSurvey, meta Goal). `data` traz shape novo (nota_final/faixa_bonus/
     * componentes.*); tela decompõe internamente derivadas legadas para o
     * Dashboard.jsx transicionar (Plan 74-06 reescreve o front pra shape puro).
     */
    public function dashboardCarteira(Request $request): \Inertia\Response
    {
        $user = $request->user();

        // ── Score + métricas agregadas de carteira ──
        $mesReferencia = Carbon::now()->startOfMonth();
        // Ajuste 2026-07-10 (audit performance-lentidao): usa cache Redis.
        $data = $this->scoreService->computeCached($user, $mesReferencia);

        // ── Empresas em carteira (todas, ativas) ──
        // Eager-load mlToken pra detectar conexão OAuth ativa (badge ML).
        $companies = $user->companies()->with('mlToken')->where('active', true)->get();
        $companyIds = $companies->pluck('id');
        $companiesById = $companies->keyBy('id');
        // Janela rolling 30d ainda usada nas queries de metrics por empresa
        // (independente do compute — a tabela do dashboard mostra "atividade
        // recente" da carteira, não a estatística mensal fechada).
        $atualFrom = Carbon::now()->subDays(30)->toDateString();
        $atualTo   = Carbon::now()->toDateString();

        // Revenue + revenue anterior + ads por empresa (últimos 30d)
        $metricsByCompany = \App\Models\AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$atualFrom, $atualTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // ── NPS dos 3 widgets: UMA passagem de dados ─────────────────────────
        // Phase 80 Plan 03 (DEC-80-E) — coluna NPS por empresa, últimas respostas
        // e heatmap derivam todos do mesmo dual-path (atribuições da Fase 79 +
        // legado), então contam a MESMA história. Uma única chamada com a janela
        // mais larga (a do heatmap: 6 meses); os recortes das outras duas saem em
        // memória. Antes eram 3 queries `->principal()` independentes — e nenhuma
        // delas enxergava a resposta de um NPS Shopee.
        $seisMesesAtras = now()->copy()->subMonths(5)->startOfMonth();
        $notasNps = $this->notasNpsDoUsuarioPorResposta($user, $companyIds, $seisMesesAtras);

        // Coluna NPS da tabela de empresas: nota da resposta mais recente por
        // empresa na janela de 60d (`$notasNps` já vem ordenado por data desc,
        // então o `first()` de cada grupo É a mais recente).
        $npsByCompany = $notasNps
            ->filter(fn ($l) => $l['completed_at'] >= now()->subDays(60))
            ->groupBy('company_id')
            ->map(fn ($grupo) => $grupo->first()['nota']);

        // Meta por empresa (Goal ativa por empresa; se >1 pega a de faturamento)
        $goalsByCompany = \App\Models\Goal::whereIn('company_id', $companyIds)
            ->where('active', true)
            ->get()
            ->groupBy('company_id')
            ->map(fn ($group) => $group->first());

        // Monta lista de empresas pra tabela
        $empresas = $companies->map(function ($c) use ($metricsByCompany, $npsByCompany, $goalsByCompany) {
            $row = $metricsByCompany->get($c->id);
            $rev     = (float) ($row->rev ?? 0);
            $revPrev = (float) ($row->rev_prev ?? 0);
            $ads     = (float) ($row->ads ?? 0);

            $crescimento = $revPrev > 0
                ? round((($rev - $revPrev) / $revPrev) * 100, 1)
                : null;

            $goal = $goalsByCompany->get($c->id);
            $metaPct = null;
            if ($goal && $goal->target_value > 0) {
                $metaPct = (int) min(100, round(($rev / (float) $goal->target_value) * 100));
            }

            // Nota da resposta mais recente (60d) que é DA PESSOA — via atribuição
            // do serviço pelo qual ela responde, ou legado. null = sem resposta.
            $nps = $npsByCompany->get($c->id);

            // Status heurístico: baseado em crescimento + meta
            $status = 'saudavel';
            if ($crescimento !== null && $crescimento < 0) {
                $status = 'critico';
            } elseif ($metaPct !== null && $metaPct < 60) {
                $status = 'atencao';
            } elseif ($crescimento !== null && $crescimento < 5) {
                $status = 'atencao';
            }

            // Ação heurística leve
            $acao = null;
            if ($crescimento !== null && $crescimento < 0) $acao = 'Investigar queda';
            elseif ($metaPct !== null && $metaPct < 60)    $acao = 'Acelerar meta';
            elseif ($rev > 0 && $ads == 0)                 $acao = 'Considerar Ads';
            elseif (! $c->marketplace || $c->marketplace !== 'meli') $acao = 'Conectar ML';
            else                                            $acao = 'Manter ritmo';

            return [
                'id'          => $c->id,
                'nome'        => $c->name,
                // Badge ML só aparece se a empresa tem conexão OAuth ML ativa
                // (mlToken.status === 'active'). Empresas com dados vindos só
                // do Adman API NÃO ganham badge.
                'ml'          => (bool) ($c->mlToken && $c->mlToken->status === 'active'),
                'status'      => $status,
                'faturamento' => $rev,
                'meta'        => $metaPct,     // null se sem goal
                'crescimento' => $crescimento, // null se sem base
                'nps'         => $nps,          // null se sem resposta
                'ads'         => $ads,
                'acao'        => $acao,
            ];
        })->values();

        // ── NPS widget: últimas 4 respostas completed ──
        // Phase 80 Plan 03 (DEC-80-E) — deriva do dual-path de apresentação, não
        // mais de uma query `->principal()` própria: a resposta de um NPS Shopee
        // (modelo NÃO-principal) NUNCA aparecia aqui, mesmo com o bônus já correto
        // desde os planos 80-01/80-02. Cada item ganha `area` (o slug do setor);
        // o front traduz para "Mercado Livre"/"Shopee".
        $npsRespostas = $notasNps
            ->take(4)
            ->map(fn ($l) => [
                'empresa' => $companiesById->get($l['company_id'])?->name ?? '—',
                'nota'    => $l['nota'],
                'quando'  => optional($l['completed_at'])->diffForHumans(),
                'area'    => $l['area'],
            ])
            ->values();

        // ── NPS heatmap (empresa × mês) ─────────────────────────────────
        // Ajuste 2026-07-09: novo widget visual — linhas = empresas, colunas =
        // últimos 6 meses, células = média das notas NPS. Intensidade da cor
        // proporcional à nota (baixa vermelho → alta laranja/amarelo ECF).
        //
        // Escopo: últimos 6 meses (janela suficiente pra ver tendências sem
        // sobrecarregar o widget) — é a janela de `$notasNps`, montada lá em cima.
        // Phase 80 Plan 03: as notas vêm do dual-path (atribuições + legado), então
        // um mês com resposta de NPS Shopee deixa de aparecer vazio para quem
        // responde pelo Shopee.
        //
        // Agrupa por (company_id, mês YYYY-MM) → média das notas do range.
        $matriz = $notasNps
            ->map(fn ($l) => [
                'company_id' => $l['company_id'],
                'mes'        => $l['completed_at']->format('Y-m'),
                'nota'       => $l['nota'],
            ])
            ->groupBy(fn ($r) => $r['company_id'] . '|' . $r['mes'])
            ->map(fn ($group) => round($group->avg('nota'), 2));

        // Reorganiza: {company_id: {mes: media}}
        $notasPorEmpresaMes = [];
        foreach ($matriz as $chave => $media) {
            [$cid, $mes] = explode('|', $chave);
            $notasPorEmpresaMes[$cid][$mes] = $media;
        }

        // Meses do range (6 meses) — sempre presentes na UI mesmo sem dados.
        // Bugfix 2026-07-09: usar $mesCursor em vez de $data pra NÃO sobrescrever
        // o $data original (retorno do scoreService->compute lá em cima) — isso
        // quebrava a tela do estrategista/analista com 500 (Carbon usado como array).
        $mesesHeatmap = collect();
        for ($i = 5; $i >= 0; $i--) {
            $mesCursor = now()->copy()->subMonths($i);
            $mesesHeatmap->push([
                'chave' => $mesCursor->format('Y-m'),
                'label' => mb_strtolower($mesCursor->translatedFormat('M/y')),
            ]);
        }

        // Empresas com pelo menos 1 nota no range (para não poluir com linhas
        // vazias). Se todas na carteira sem notas, mostra as 10 primeiras
        // ativas para o widget não ficar completamente vazio.
        $companiesComDados = collect(array_keys($notasPorEmpresaMes));
        $empresasHeatmap = $companies->whereIn('id', $companiesComDados)->values();
        if ($empresasHeatmap->isEmpty()) {
            $empresasHeatmap = $companies->take(10)->values();
        }

        $heatmap = [
            'meses'    => $mesesHeatmap->values(),
            'empresas' => $empresasHeatmap->map(fn ($c) => [
                'id'    => $c->id,
                'name'  => $c->name,
                'notas' => $notasPorEmpresaMes[$c->id] ?? [],
            ])->values(),
        ];

        // ── Métricas derivadas legadas (transição pra Plan 74-06) ────────────
        // Shape v2 do compute() não expõe atingimento_meta/empresas_em_crescimento
        // /faturamento agregados; recalculamos inline pra manter o payload
        // atual do Dashboard.jsx funcionando enquanto Plan 74-06 reescreve o front.
        $componentes = $data['componentes'] ?? [];
        $npsMedio    = $componentes['nps_medio'] ?? null;

        $totalRevenue = (float) $companies->sum(function ($c) use ($metricsByCompany) {
            return (float) ($metricsByCompany->get($c->id)?->rev ?? 0);
        });
        $totalRevenuePrev = (float) $companies->sum(function ($c) use ($metricsByCompany) {
            return (float) ($metricsByCompany->get($c->id)?->rev_prev ?? 0);
        });
        $emCrescimentoCount = $companies->filter(function ($c) use ($metricsByCompany) {
            $row = $metricsByCompany->get($c->id);
            if (! $row) return false;
            return ((float) $row->rev_prev > 0) && ((float) $row->rev > (float) $row->rev_prev);
        })->count();

        // Atingimento agregado a partir das Goals ativas de empresas da carteira.
        $metaTarget    = (float) $goalsByCompany->sum(fn ($g) => (float) ($g->target_value ?? 0));
        $metaRealizado = (float) $companies
            ->filter(fn ($c) => $goalsByCompany->has($c->id))
            ->sum(fn ($c) => (float) ($metricsByCompany->get($c->id)?->rev ?? 0));
        $metaPct = $metaTarget > 0 ? round(($metaRealizado / $metaTarget) * 100, 1) : null;

        // Metas widget — só aparece se houver ≥1 Goal atribuída à carteira
        // (regra UAT 2026-07-07 preservada).
        $metasWidget = [];
        if ($goalsByCompany->count() > 0) {
            if ($metaTarget > 0) {
                $metasWidget[] = [
                    'icone'    => 'dollar',
                    'nome'     => 'Faturamento vs meta',
                    'atual'    => $this->fmtBRL($metaRealizado),
                    'objetivo' => $this->fmtBRL($metaTarget),
                    'percent'  => (int) min(100, round((float) $metaPct)),
                ];
            }
            if ($companies->count() > 0) {
                $metasWidget[] = [
                    'icone'    => 'trend',
                    'nome'     => 'Empresas em crescimento',
                    'atual'    => (string) $emCrescimentoCount,
                    'objetivo' => "{$companies->count()} empresas",
                    'percent'  => $companies->count() > 0
                        ? (int) round(($emCrescimentoCount / $companies->count()) * 100)
                        : 0,
                ];
            }
            if ($npsMedio !== null && $npsMedio > 0) {
                $pct = (int) min(100, round(($npsMedio / 5.0) * 100));
                $metasWidget[] = [
                    'icone'    => 'check',
                    'nome'     => 'Qualidade NPS média',
                    'atual'    => number_format($npsMedio, 1, ',', '.'),
                    'objetivo' => '5,0',
                    'percent'  => $pct,
                ];
            }
        }

        // Delta faturamento vs mês anterior — usa componente do compute (var_faturamento_pct).
        $varFatPct = $componentes['var_faturamento_pct'] ?? null;

        return Inertia::render('Performance/Dashboard', [
            'pessoa' => [
                'nome'   => $user->name,
                'funcao' => $user->isMentor() ? 'Estrategista' : 'Analista de Performance',
                'role_key' => $user->isMentor() ? 'mentor' : ($user->isConsultor() ? 'consultor' : 'other'),
                'iniciais' => $this->iniciais($user->name),
            ],
            'periodo' => 'Últimos 30 dias',
            // Phase 74 — payload v2 (Plan 74-06 renderiza; enquanto isso, kpis
            // legados abaixo mantêm compat com Dashboard.jsx atual).
            'desempenho' => $data,
            'kpis' => [
                'faturamento_total'       => $totalRevenue,
                'empresas_em_carteira'    => $data['empresas_carteira'] ?? 0,
                'empresas_conectadas_ml'  => (int) $companies->where('marketplace', 'meli')->count(),
                'crescimento_percent'     => $varFatPct,
                'crescimento_delta_valor' => $totalRevenue - $totalRevenuePrev,
                'crescimento_mediana'     => $varFatPct,
                'nota_final'              => $data['nota_final'] ?? null,
                'faixa_bonus'             => $data['faixa_bonus'] ?? null,
                'faixa_promovida'         => (bool) ($data['faixa_promovida'] ?? false),
                'empresas_em_crescimento' => $emCrescimentoCount,
                'sem_carteira'            => (bool) ($data['sem_carteira'] ?? false),
                'motivo'                  => $data['motivo'] ?? null,
            ],
            'nps' => [
                'media'     => $npsMedio,
                'respostas' => $npsRespostas,
                'heatmap'   => $heatmap,
            ],
            'metas' => $metasWidget,
            'empresas' => $empresas,
        ]);
    }

    // ─── NPS dos widgets de carteira (Phase 80 Plan 03 · DEC-80-E) ──────────

    /**
     * Notas de NPS do user, UMA LINHA POR RESPOSTA — insumo dos 3 widgets do
     * `dashboardCarteira` (coluna NPS por empresa, últimas respostas e heatmap).
     *
     * ⚠ ISTO É APRESENTAÇÃO, NÃO A RÉGUA DO BÔNUS. ⚠
     * O número OFICIAL da pessoa é `desempenho.componentes.nps_medio`, calculado
     * pelo `DesempenhoScoreService::computeNpsMedio` (planos 80-01/80-02) e servido
     * no headline `nps.media`. Este helper existe SÓ para detalhar de onde aquela
     * média veio — se os dois divergirem, **o service ganha** e a divergência é bug
     * daqui. Não promover este método a fonte de verdade nem alimentar bônus,
     * snapshot ou consolidação mensal com ele.
     *
     * Espelha o dual-path do service, agora ampliado pra união DISJUNTA de QUATRO
     * ramos (Fase 116 + Fase 119.1 Plan 09):
     *  - (A) atribuições congeladas da Fase 79 (`nps_score_assignments`) do user,
     *        deduped 1× por (`nps_response_id`, `role`) — qualquer modelo conta;
     *  - (B) legado: surveys `->principal()` da carteira, PULANDO as respostas que
     *        já têm atribuição no papel correspondente à dimensão do cargo do user
     *        (DEC-80-B1 — o snapshot é autoritativo por papel);
     *  - (C) Fase 116 (NPSFLOOR) — notas imputadas: NPS efetivamente disparado e
     *        NUNCA respondido conta nota 1 (leitura via `NpsImputationService`);
     *  - (D) Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL que passou o mês sem
     *        NENHUM link (nem disparado) também conta nota 1 (leitura via
     *        `NpsSemLinkService`), com piso retroativo (DEC-09-B): a janela é
     *        ROLANTE, então nunca alcança mais que mês anterior + corrente.
     * Disjunção por construção: (A)/(B) só existem para survey `status=completed`;
     * (C) só existe para survey disparado que nunca chegou a completed; (D) só
     * existe quando NÃO há survey algum na competência (garantido dentro do
     * serviço, via `NpsElegibilidadeService::surveyExistenteNaCompetencia()`) —
     * nenhum survey/competência pode contar duas vezes.
     *
     * DIFERENÇA DELIBERADA vs o service (e por que ela não vira divergência de
     * número): aqui o ramo (A) TAMBÉM é filtrado por `$companyIds` (a carteira ativa
     * exibida na tela), porque os 3 widgets só sabem renderizar empresas dessa lista
     * — uma nota órfã viraria linha fantasma no heatmap. O service NÃO filtra (o
     * congelamento manda), então uma atribuição de empresa fora da carteira ativa
     * conta no bônus e não aparece aqui. Na prática `User::companies()` não filtra
     * por `servico_id`, então toda empresa com atribuição do user está na carteira;
     * o recorte só morde empresa INATIVA. É exatamente o tipo de assimetria que
     * proíbe usar este helper como régua (T-80-11). Os ramos (C) e (D) seguem a
     * mesma regra — filtrados por `$companyIds`.
     *
     * Mês/data vem SEMPRE de `nps_surveys.completed_at` (DEC-80-B0) — nunca de
     * `assigned_at` (data da gravação: um backfill migraria a resposta de mês).
     * Exceção: os ramos (C) e (D) não têm `completed_at` (nunca houve resposta) —
     * usam a data SINTÉTICA `competencia_nps->copy()->endOfMonth()` (fim do mês do
     * disparo/da competência), documentado no próprio bloco do ramo.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $companyIds  carteira ativa exibida
     * @param  Carbon  $desde  início da janela mais larga (heatmap: 6 meses)
     * @return \Illuminate\Support\Collection<int, array{company_id:int, completed_at:Carbon, nota:float, area:?string, imputada:bool}>
     */
    private function notasNpsDoUsuarioPorResposta(User $user, \Illuminate\Support\Collection $companyIds, Carbon $desde): \Illuminate\Support\Collection
    {
        if ($companyIds->isEmpty()) {
            return collect();
        }

        // ── (A) Atribuições congeladas — todas as áreas (ML + Shopee) ────────
        // Dedup por (resposta, papel): as N linhas do mesmo par vêm do MESMO
        // `nps_response_score` (o NpsSnapshotService itera serviços DENTRO da
        // dimensão), logo `average_score` é idêntico e `MAX()` é determinístico.
        // `MAX()` nas demais colunas satisfaz o ONLY_FULL_GROUP_BY do MariaDB —
        // company_id/completed_at são constantes dentro do par (mesma resposta).
        // `MAX(service_setor)` só desempata no caso raro de a MESMA pessoa
        // responder por 2 serviços de setores diferentes na MESMA resposta; o
        // rótulo fica determinístico e a nota (que é a mesma) não muda.
        $atribuidas = \App\Models\NpsScoreAssignment::query()
            ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
            ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('nps_score_assignments.user_id', $user->id)
            ->whereIn('nps_score_assignments.company_id', $companyIds)
            ->where('s.status', 'completed')
            ->where('s.completed_at', '>=', $desde)
            // Phase 96 Plan 04 (AB-96-3 · call-site #3) — resposta invalidada
            // pelo admin some dos widgets de carteira. Ver NpsResponse::scopeValida().
            ->whereNull('r.invalidated_at')
            ->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')
            // selectRaw só com nomes de coluna literais — zero interpolação de
            // variável; todos os valores entram por bind (where/whereIn).
            ->selectRaw(
                'nps_score_assignments.nps_response_id as response_id,'
                .' MAX(nps_score_assignments.company_id) as company_id,'
                .' MAX(nps_score_assignments.service_setor) as service_setor,'
                .' MAX(nps_score_assignments.average_score) as average_score,'
                .' MAX(s.completed_at) as completed_at'
            )
            ->get();

        $linhas = $atribuidas->map(fn ($row) => [
            'company_id'   => (int) $row->company_id,
            'completed_at' => Carbon::parse($row->completed_at),
            'nota'         => round((float) $row->average_score, 2),
            'area'         => (string) $row->service_setor,
            // Fase 116 — uniformiza o shape com o ramo (C) (nota imputada).
            'imputada'     => false,
        ]);

        // ── (B) Legado — só as respostas que o snapshot não cobriu ───────────
        // ⚠ O `->principal()` PERMANECE AQUI — não "limpar". Ele É o isolamento
        // por serviço neste ramo: sem ele, a resposta de um NPS Shopee entra no
        // escopo legado do analista de ML da MESMA empresa (é da carteira dele, é
        // completed, é do range), ele não tem atribuição nela, cai no fallback e
        // recebe uma nota que não é dele. Mesma armadilha documentada em
        // `DesempenhoScoreService::notasLegado` (DEC-80-D).
        $dim = $user->dimensaoNpsDesempenho();

        // Phase 96 Plan 04 (AB-96-3 · call-site #4) — eager-load só carrega a
        // response quando ela NÃO está invalidada (foreach abaixo já pula
        // response null).
        $surveysLegado = NpsSurvey::with(['response' => fn ($q) => $q->valida()->with(['answers', 'survey'])])
            ->principal()
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $desde)
            ->get();

        // Set de skip derivado DOS SURVEYS JÁ CARREGADOS (nunca de uma 2ª query
        // com filtro de data próprio: dois filtros divergem e a resposta escapa,
        // sendo contada pelos DOIS ramos). Mapa dimensão → papel espelha
        // NpsSnapshotService::DIMENSAO_ROLE.
        $papelDaDimensao = $dim === 'estrategista' ? 'estrategista' : 'consultor';
        $responseIds     = $surveysLegado->pluck('response.id')->filter()->values();

        // ->flip() + ->has() = lookup O(1) por hash (nunca ->contains() no loop).
        $cobertasNoPapel = $responseIds->isEmpty()
            ? collect()
            : \App\Models\NpsScoreAssignment::whereIn('nps_response_id', $responseIds)
                ->where('role', $papelDaDimensao)
                ->pluck('nps_response_id')
                ->flip();

        $calculator = app(\App\Services\Nps\NpsScoreCalculator::class);

        foreach ($surveysLegado as $survey) {
            $response = $survey->response;
            if ($response === null || $cobertasNoPapel->has($response->id)) {
                continue;
            }

            // v15 (canonical) → fallback nas colunas legacy `score_*` (Phase 68).
            $nota = $calculator->compute($response, $dim);
            if ($nota === null) {
                $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
                $legacyScore = $response->{$legacyField} ?? null;
                if ($legacyScore !== null && $legacyScore > 0) {
                    $nota = (float) $legacyScore;
                }
            }

            if ($nota === null) {
                continue;
            }

            $linhas->push([
                'company_id'   => (int) $survey->company_id,
                'completed_at' => $survey->completed_at,
                // Legado não tem serviço → sem rótulo de área (o front omite o badge).
                'nota'         => round((float) $nota, 2),
                'area'         => null,
                // Fase 116 — uniformiza o shape com o ramo (C) (nota imputada).
                'imputada'     => false,
            ]);
        }

        // ── (C) Fase 116 (NPSFLOOR) — notas imputadas: NPS efetivamente ──────
        // disparado e NUNCA respondido conta nota 1 (D4, piso da escala 1-5).
        // Delega 100% para NpsImputationService — nenhuma lógica de resolução
        // de responsável/competência/invalidação é reimplementada aqui (mesmo
        // ponto de extensão já usado por DesempenhoScoreService::notasImputadas,
        // Plan 116-02). Disjunção por construção: (A)/(B) exigem
        // `s.status = 'completed'`; este ramo só existe para survey que NUNCA
        // chegou a completed — nenhum survey conta duas vezes.
        $imputadas = app(\App\Services\Nps\NpsImputationService::class)
            ->notasDoUsuario($user, $desde, now(), null)
            ->filter(fn ($nota) => $companyIds->contains((int) $nota->company_id));

        foreach ($imputadas as $nota) {
            $linhas->push([
                'company_id'   => (int) $nota->company_id,
                // Linha imputada não tem completed_at (nunca houve resposta) —
                // usa a data SINTÉTICA `competencia_nps->endOfMonth()` (fim do
                // mês do disparo) para ordenação/janela de 60d/heatmap, igual
                // às demais notas.
                'completed_at' => $nota->competencia_nps->copy()->endOfMonth(),
                'nota'         => (float) $nota->nota,
                'area'         => $nota->service_setor,
                'imputada'     => true,
            ]);
        }

        // ── (D) Fase 119.1 Plan 09 (D1) — empresa ELEGÍVEL sem NENHUM ────────
        // link no mês conta nota 1, mesma régua do bônus. Delega 100% para
        // `NpsSemLinkService` — nenhuma regra de elegibilidade/responsável/
        // invalidação é reimplementada aqui. Existe SÓ quando não há survey
        // algum na competência (garantido dentro do serviço, via
        // `surveyExistenteNaCompetencia()`) — disjunto de (A)/(B) que exigem
        // `completed` e de (C) que exige survey disparado sem resposta.
        // Piso retroativo (DEC-09-B): esta é leitura em JANELA ROLANTE (início
        // relativo a `now()`), então nunca alcança mais que mês anterior +
        // corrente — o histórico antigo não é reescrito.
        $semLink = app(\App\Services\Desempenho\NpsSemLinkService::class);
        $piso    = $semLink->pisoRetroativo();
        $notasSemLink = $semLink
            ->notasDoUsuarioNaJanela($user, $desde, now(), null, $piso)
            ->filter(fn ($nota) => $companyIds->contains((int) $nota->company_id));

        foreach ($notasSemLink as $nota) {
            $linhas->push([
                'company_id'   => (int) $nota->company_id,
                // Mesma data SINTÉTICA do ramo (C) — é o que faz a linha
                // entrar no recorte de 60d da coluna NPS e no heatmap.
                'completed_at' => $nota->competencia_nps->copy()->endOfMonth(),
                'nota'         => (float) $nota->nota,
                'area'         => $nota->service_setor,
                'imputada'     => true,
            ]);
        }

        return $linhas->sortByDesc(fn ($l) => $l['completed_at'])->values();
    }

    private function fmtBRL(float $v): string
    {
        if (abs($v) >= 1_000_000) return 'R$ ' . number_format($v / 1_000_000, 2, ',', '.') . 'M';
        if (abs($v) >= 1_000)     return 'R$ ' . number_format($v / 1_000, 0, ',', '.') . 'K';
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    private function iniciais(?string $nome): string
    {
        if (! $nome) return '?';
        $parts = preg_split('/\s+/', trim($nome));
        $ini = mb_substr($parts[0] ?? '', 0, 1);
        if (count($parts) > 1) $ini .= mb_substr($parts[count($parts) - 1], 0, 1);
        return mb_strtoupper($ini);
    }

    /**
     * Phase 46 Plan 46-02 — endpoint JSON com a curva de evolução do score
     * de um user nos últimos N dias.
     *
     * Consumido pelo frontend (drawer/gráfico individual) via fetch — Wave 3.
     * Mesmo gate de permissão de /performance (`permission:core.performance`),
     * aplicado na rota em routes/web.php.
     *
     * Query params:
     *   - period: 7..365 (clamp; default 30; valores não-numericos viram 30)
     *
     * Payload:
     *   {
     *     "user_id": 42,
     *     "period":  30,
     *     "serie":   [{"date":"YYYY-MM-DD","score":75,"ranking_pos":2}, ...]
     *   }
     *
     * Série ordenada ASC por date — Recharts consome direto.
     */
    public function evolucao(Request $request, User $user): JsonResponse
    {
        // CR-01 (123-07): mesma regra de autorização de show() — sem isto,
        // qualquer não-admin com core.performance trocava o {user} na URL
        // e lia a série de evolução (score/ranking_pos) de outro profissional.
        abort_unless(
            $this->autorizadoParaVerDesempenhoDe($request, $user),
            403,
            'Você só pode ver o seu próprio desempenho ou o de quem está sob sua liderança.'
        );

        // Clamp period: aceita 7..365; default 30; valores nao-numericos viram 30.
        $raw = $request->query('period', 30);
        $period = is_numeric($raw) ? (int) $raw : 30;
        $period = max(7, min($period, 365));

        $since = now()->subDays($period)->toDateString();

        $serie = DesempenhoScoreSnapshot::where('user_id', $user->id)
            ->whereDate('ref_date', '>=', $since)
            ->orderBy('ref_date', 'asc')
            ->get(['ref_date', 'score', 'ranking_pos'])
            ->map(fn ($s) => [
                'date'        => $s->ref_date->toDateString(),
                'score'       => (int) $s->score,
                'ranking_pos' => $s->ranking_pos !== null ? (int) $s->ranking_pos : null,
            ])
            ->values();

        return response()->json([
            'user_id' => $user->id,
            'period'  => $period,
            'serie'   => $serie,
        ]);
    }

    private function indexPolos(Request $request): \Inertia\Response
    {
        $mesRef = $request->get('mes', now()->format('Y-m'));
        // Valida 'YYYY-MM' com mês 01-12 antes do Carbon — evita 500 (formato inválido)
        // e o overflow silencioso de mês (ex.: '2026-13' viraria 2027-01).
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesRef)) {
            $mesRef = now()->format('Y-m');
        }
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        // Fonte CANÔNICA de publicadores (mesma do Meu Painel): pivot user_setores
        // → cargos do setor 'publicacao'. Substitui a coluna legada publication_role
        // (que dessincronizava o roster entre as duas telas). cargo_slug alimenta o
        // rótulo de papel (publicador/líder).
        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->from('user_setores')
                  ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
                  ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
                  ->whereColumn('user_setores.user_id', 'users.id')
                  ->where('setores.slug', 'publicacao')
                  ->whereIn('cargos.slug', ['publicador', 'lider-de-publicacao']);
            })
            ->select(['id', 'name', 'avatar_url'])
            ->addSelect(['cargo_slug' => DB::table('user_setores')
                ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
                ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
                ->whereColumn('user_setores.user_id', 'users.id')
                ->where('setores.slug', 'publicacao')
                ->whereIn('cargos.slug', ['publicador', 'lider-de-publicacao'])
                ->select('cargos.slug')
                ->limit(1)])
            ->orderBy('name')
            ->get();

        $hoje      = Carbon::today();
        $primeiroC = $ref->copy()->startOfMonth();
        $ultimoC   = $ref->copy()->endOfMonth();

        $diasDecorridos = $this->diasUteis($primeiroC, $hoje->lt($ultimoC) ? $hoje : $ultimoC);
        $diasRestantes  = $hoje->lt($ultimoC) ? $this->diasUteis($hoje->copy()->addDay(), $ultimoC) : 0;
        $diasTotal      = max($diasDecorridos + $diasRestantes, 1);

        // Plano de Metas do Time de Publicação — nota 0-5 + faixa de bônus é a
        // régua canônica (a MESMA do "Meu Painel"). O ranking passa a ser ordenado
        // pela nota do plano (antes: score normalizado por grupo, exclusivo daqui).
        $plano = new PlanoMetasPublicacaoService();

        $ranking = $users->map(function ($u) use ($primeiro, $ultimo, $diasDecorridos, $diasTotal, $mesRef, $plano) {
            $meta       = $this->metaParaMes($u->id, $mesRef);
            $feito      = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('tipo', '!=', 'variacao')->count();
            $vendas     = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('tipo', '!=', 'variacao')->where('vendido', true)->count();

            $pm = $plano->compute($u->id, $mesRef, $feito, $vendas, $diasDecorridos);

            $mediaAtual = $diasDecorridos > 0 ? round($feito / $diasDecorridos, 1) : 0.0;
            $projecao   = (int) round($mediaAtual * $diasTotal);

            // Semáforo operacional (mantém a leitura "no trilho?" vs a meta configurada).
            if ($feito >= $meta) {
                $status = 'Acima da meta';
            } elseif ($projecao >= $meta * 0.95) {
                $status = 'No alvo';
            } else {
                $status = 'Abaixo da meta';
            }

            return [
                'id'              => $u->id,
                'name'            => $u->name,
                'pub_role'        => $u->cargo_slug === 'lider-de-publicacao' ? 'lider' : 'publicador',
                'foto'            => $u->avatar_url,
                'meta'            => $meta,
                'feito'           => $feito,
                'vendas'          => $vendas,
                'percentual'      => $meta > 0 ? round($feito / $meta * 100, 1) : 0.0,
                'conversao'       => $pm['indicadores']['conversao']['valor'] ?? 0.0,
                'projecao'        => $projecao,
                'status'          => $status,
                // Plano de Metas: nota 0-5, faixa de bônus e detalhamento por indicador.
                'nota'            => $pm['nota'],
                'faixa'           => $pm['faixa'],
                'travas'          => $pm['travas'],
                'indicadores'     => $pm['indicadores'],
                'score_final'     => $pm['score100'], // 0-100 p/ reuso dos gauges
            ];
        })
        ->sort(fn ($a, $b) => [$b['nota'], $b['feito']] <=> [$a['nota'], $a['feito']])
        ->values();

        // ── Evolução mês a mês (coluna "Evolução" do dashboard) ──
        // Compara a posição atual com a do mês anterior (mesma nota do plano).
        // delta > 0 = subiu no ranking; < 0 = caiu; 0 = manteve. Sem produção no
        // mês anterior → sem base → delta null e a UI mostra "—".
        $mesAnterior = Carbon::createFromFormat('Y-m', $mesRef)->subMonthNoOverflow()->format('Y-m');
        $posAnterior = $this->posicoesDoMes($users, $mesAnterior);

        $ranking = $ranking->values()->map(function ($u, $idx) use ($posAnterior) {
            $posAtual        = $idx + 1;
            $anterior        = $posAnterior[$u['id']] ?? null;
            $u['posicao']        = $posAtual;
            $u['evolucao_delta'] = $anterior !== null ? $anterior - $posAtual : null;
            return $u;
        });

        // Compatibilidade SQLite (testes) vs MySQL (produção).
        $formatExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', data)"
            : "DATE_FORMAT(data, '%Y-%m')";
        $meses = Publicacao::selectRaw("{$formatExpr} as mes")
            ->distinct()->orderByDesc('mes')->pluck('mes')->toArray();
        $atual = now()->format('Y-m');
        if (!in_array($atual, $meses)) array_unshift($meses, $atual);

        return Inertia::render('Performance/Index', [
            'ranking' => $ranking,
            'setor'   => 'polos',
            'mes'     => $mesRef,
            'meses'   => $meses,
        ]);
    }

    /**
     * Calcula a posição (1-based) de cada publicador no ranking de um mês, usando
     * a MESMA nota do Plano de Metas (PlanoMetasPublicacaoService). Retorna
     * [user_id => posição]. Sem produção no mês → [] (sem base para evolução).
     * Como o mês já está fechado, a produtividade usa os dias úteis totais do mês.
     *
     * @param  \Illuminate\Support\Collection  $users  publicadores ativos (id)
     */
    private function posicoesDoMes($users, string $mesRef): array
    {
        $ref       = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro  = $ref->copy()->startOfMonth()->toDateString();
        $ultimo    = $ref->copy()->endOfMonth()->toDateString();
        $diasUteis = $this->diasUteis($ref->copy()->startOfMonth(), $ref->copy()->endOfMonth());

        $plano = new PlanoMetasPublicacaoService();

        $raw = $users->map(function ($u) use ($primeiro, $ultimo, $mesRef, $diasUteis, $plano) {
            $feito  = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('tipo', '!=', 'variacao')->count();
            $vendas = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('tipo', '!=', 'variacao')->where('vendido', true)->count();
            $pm     = $plano->compute($u->id, $mesRef, $feito, $vendas, $diasUteis);

            return ['id' => $u->id, 'feito' => $feito, 'nota' => $pm['nota']];
        });

        // Sem produção no mês → nada a comparar.
        if ((int) $raw->sum('feito') === 0) {
            return [];
        }

        return $raw
            ->sort(fn ($a, $b) => [$b['nota'], $b['feito']] <=> [$a['nota'], $a['feito']])
            ->values()
            ->mapWithKeys(fn ($u, $i) => [$u['id'] => $i + 1])
            ->all();
    }

    private function metaParaMes(int $userId, string $mes): int
    {
        $registro = DB::table('mlb_meta_historico')
            ->where('user_id', $userId)
            ->where('mes_inicio', '<=', $mes)
            ->orderByDesc('mes_inicio')
            ->value('meta');

        if ($registro !== null) return (int) $registro;

        // Fallback CANÔNICO (igual ao MlbController): meta do cargo no setor Publicação.
        $meta = DB::table('user_setores')
            ->join('setores', 'setores.id', '=', 'user_setores.setor_id')
            ->join('cargos', 'cargos.id', '=', 'user_setores.cargo_id')
            ->where('user_setores.user_id', $userId)
            ->where('setores.slug', 'publicacao')
            ->value('cargos.meta_publicacoes');

        return (int) ($meta ?? 220);
    }

    private function diasUteis(Carbon $start, Carbon $end): int
    {
        if ($start->gt($end)) return 0;
        $count   = 0;
        $current = $start->copy()->startOfDay();
        $endDay  = $end->copy()->startOfDay();
        while ($current->lte($endDay)) {
            if ($current->isWeekday()) $count++;
            $current->addDay();
        }
        return $count;
    }

    /**
     * Phase 74 D-19/Plan 74-06 · view individual do analista/estrategista.
     *
     * Renderiza `Performance/Show.jsx` (reescrito) com o shape v2 do
     * DesempenhoScoreService — 4 parâmetros (NPS/Faturamento/Margem/Absenteísmo)
     * + faixa de bônus. Toggle de mês via query param `?mes=YYYY-MM-01` permite
     * o usuário navegar em meses fechados anteriores (default = último fechado
     * ou o mês em curso quando ainda não há consolidação).
     *
     * Fonte do resultado:
     *  - Snapshot mensal do mês selecionado quando existe (`breakdown_json`);
     *  - Fallback ao `compute()` on-the-fly (mês em curso parcial).
     *
     * Fonte dos meses disponíveis (para o toggle):
     *  - `desempenho_score_snapshots` filtrado por `user_id` + `mes_referencia`
     *     não-null (D-02 da Fase 123) — a existência da linha já é o sinal
     *     correto de competência fechada e consolidada, sem corte fixo de
     *     data. O corte antigo (DESEMP-14, 1º de agosto de 2026) deixava o
     *     dropdown vazio em produção: `desempenho:consolidar-mes` congela o
     *     mês ANTERIOR no último dia do mês, então a primeira competência a
     *     passar naquele corte só existiria em 30 de setembro de 2026.
     */
    /**
     * Resolve o CONTEXTO DE PERÍODO das telas de desempenho a partir de
     * `?modo=`/`?mes=YYYY-MM` — FONTE ÚNICA (Fase 1 do plano de otimização
     * 2026-07-21). Reusado por `index()` (ranking) e `show()` (individual) para
     * o MESMO contrato de período/bônus; o `MetricPeriodResolver` é a única
     * fonte das janelas (nenhum controller monta baseline à mão).
     *
     *  - `bonus_atual` → último mês fechado (competência do bônus).
     *  - `?mes=YYYY-MM` (mês fechado específico) → aquele mês.
     *  - default → mês em curso (current_month, operacional).
     *
     * @return array{modo: ?string, mesReferencia: Carbon, mesCorrente: Carbon,
     *   periodo: array, ehMesEmCurso: bool, bonus: ?array, nps: ?array}
     */
    private function resolveContextoPeriodo(Request $request): array
    {
        $modo = $request->query('modo');
        if (! in_array($modo, ['em_curso', 'bonus_atual'], true)) {
            $modo = null;
        }

        $mesQuery    = $request->query('mes');
        $mesCorrente = Carbon::now()->startOfMonth();

        if ($modo === 'bonus_atual') {
            $periodo       = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
            $mesReferencia = Carbon::parse($periodo['bonus_competence_month'] . '-01')->startOfMonth();
        } elseif ($mesQuery && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesQuery)) {
            // WR-01 (123-07): regex antiga (\d{4}-\d{2}) aceitava mês 00-99 e
            // deixava o overflow silencioso do Carbon estourar o intervalo
            // (ex.: '2026-13' viraria 2027-01; '9999-99' quebra o
            // MetricPeriodResolver::resolve() e derrubava a rota com 500).
            // Mesmo padrão já usado em indexPolos() (linha ~994).
            $mesReferencia = Carbon::createFromFormat('Y-m-d', $mesQuery . '-01')->startOfMonth();
            $periodo       = $this->periodResolver->resolve(['period_key' => $mesReferencia->format('Y-m')]);
        } else {
            $mesReferencia = $mesCorrente->copy();
            $periodo       = $this->periodResolver->resolve(['period_key' => 'current_month']);
        }

        $ehMesEmCurso = $mesReferencia->equalTo($mesCorrente);

        // Bônus só existe em período FECHADO. Competência = mês financeiro M;
        // pagamento = M+1 (após encerrar a coleta de NPS de M+1).
        $bonus = null;
        $nps   = null;
        if ($periodo['is_closed']) {
            $bonus = [
                'competence_month' => $periodo['bonus_competence_month'] ?? $mesReferencia->format('Y-m'),
                'payment_month'    => $periodo['bonus_payment_month'] ?? $mesReferencia->copy()->addMonthNoOverflow()->format('Y-m'),
            ];
            // Janela de NPS: a competência M usa o NPS coletado em M+1 (Fase 105).
            $mesNps = $mesReferencia->copy()->addMonthNoOverflow();
            $nps = [
                'collection_month' => $mesNps->format('Y-m'),
                'collection_start' => $mesNps->copy()->startOfMonth()->toDateString(),
                'collection_end'   => $mesNps->copy()->endOfMonth()->toDateString(),
                // 'fechada' quando o mês de coleta já terminou; senão ainda 'coletando'.
                'status'           => now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay())
                    ? 'fechada' : 'coletando',
            ];
        }

        return compact('modo', 'mesReferencia', 'mesCorrente', 'periodo', 'ehMesEmCurso', 'bonus', 'nps');
    }

    /**
     * CR-01 (123-07/123-VERIFICATION.md): checagem de autorização por
     * usuário-alvo, extraída para reuso em show() e evolucao() — evita
     * duplicar a query. Corpo IDÊNTICO ao de
     * `PortfolioController::transparencia()` (linhas ~214-226), que já está
     * em produção para o mesmo tipo de dado (financeiro/compensação de
     * terceiro): admin, OU o próprio usuário, OU líder de um setor do qual
     * o usuário-alvo é MEMBRO via `user_setores`.
     *
     * Não é `admin || dono` cru: isso quebraria o líder do setor Performance
     * (que ganha `core.performance` via `Permissions::AUTO_LIDERANCA_PERFORMANCE`
     * — ver `app/Models/User.php:232-240` — precisamente para "visão
     * consolidada da equipe") e o botão "Desempenho" de
     * `Portfolio/Transparencia.jsx:51`, que hoje qualquer líder acessando a
     * transparência de um membro da equipe usa para voltar a
     * `performance.show` daquele mesmo membro.
     */
    private function autorizadoParaVerDesempenhoDe(Request $request, User $user): bool
    {
        $atual = $request->user();

        return $atual->isAdmin()
            || $atual->id === $user->id
            || (
                $atual->isLider()
                && DB::table('user_setores as us')
                    ->whereIn('us.setor_id', $atual->setoresLiderados()->pluck('setores.id'))
                    ->where('us.user_id', $user->id)
                    ->exists()
            );
    }

    public function show(Request $request, User $user): \Inertia\Response
    {
        // CR-01 (123-07): não-admin com core.performance (líder do setor
        // Performance ou setor com a permission via setor_permissoes) trocava
        // o {user} na URL e lia nota, faixa de bônus e — desde a Fase 123 —
        // nome de empresa cliente, faturamento em R$, margem e nota por
        // empresa de QUALQUER profissional. Precisa ser a PRIMEIRA linha,
        // antes de qualquer leitura de dado.
        abort_unless(
            $this->autorizadoParaVerDesempenhoDe($request, $user),
            403,
            'Você só pode ver o seu próprio desempenho ou o de quem está sob sua liderança.'
        );

        // Fase 2 do plano de otimização (2026-07-21) — MESMO contrato de período
        // do ranking (`?modo=em_curso|bonus_atual`, `?mes=YYYY-MM`) via a fonte
        // única `resolveContextoPeriodo()`. Substitui o seletor antigo de mês.
        $ctx           = $this->resolveContextoPeriodo($request);
        $mesReferencia = $ctx['mesReferencia'];

        // Resolve cargo canônico via user_setores → cargos (padrão do projeto).
        $cargoRow = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->where('us.user_id', $user->id)
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->select('c.slug', 'c.nome')
            ->first();
        $cargoSlug  = $cargoRow?->slug ?? ($user->isMentor() ? 'estrategista' : 'analista');
        $cargoLabel = $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista';

        // Tenta usar snapshot mensal do mês selecionado; senão compute() cacheado.
        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesReferencia->toDateString())
            ->first();

        if ($snap && is_array($snap->breakdown_json) && isset($snap->breakdown_json['componentes'])) {
            $resultado = $snap->breakdown_json;
        } else {
            // Ajuste 2026-07-10 (audit performance-lentidao): cacheado.
            $resultado = $this->scoreService->computeCached($user, $mesReferencia);
        }

        // Meses fechados disponíveis para o dropdown, derivados dos dados
        // (D-02 da Fase 123) — existe linha mensal, logo o mês está fechado
        // e consolidado. O corte fixo antigo (DESEMP-14, 1º de agosto de
        // 2026) deixava o dropdown vazio em produção: `desempenho:consolidar-mes`
        // congela o mês ANTERIOR no último dia do mês, então a primeira
        // competência a passar naquele corte só existiria em 30 de setembro
        // de 2026. Em 'Y-m' pra bater com o `?mes=` do contrato novo.
        $mesesFechados = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->orderByDesc('mes_referencia')
            ->pluck('mes_referencia')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->unique()
            ->values();

        // Empresas invalidadas para bônus nesta competência (item 3/4) que
        // saíram da conta DESTE profissional.
        $invalidadasComp   = BonusInvalidacao::companyIdsInvalidadas($mesReferencia);
        $invalidadasDoUser = $invalidadasComp->isEmpty()
            ? 0
            : $user->companies()->pluck('companies.id')->intersect($invalidadasComp)->count();

        // Detalhe por empresa (D-01/D-03/D-06 da Fase 123) — leitura pura de
        // `desempenho_company_score_snapshots` via `CompanyScoreSnapshotReader`.
        // Esta tela NUNCA aciona o shadow de cálculo por empresa nem lê
        // via serviço de compute — reabriria o fan-out de HTTP por empresa
        // que já produziu página de 70s neste módulo (ver
        // `.planning/learnings/desempenho-bonificacao.md` §5). O guard
        // `periodo.is_closed` é a D-01 literal — no modo "Em curso" a seção
        // nem é consultada, custo zero de API nos dois ramos. `is_closed`
        // sozinho NÃO implica detalhe disponível (2026-05/2026-04 são meses
        // fechados sem nenhuma consolidação, e snapshot anterior à Fase 122
        // também não tem linhas) — por isso `$temDetalheEmpresas` é derivado
        // da EXISTÊNCIA de linhas, nunca de `is_closed` isolado.
        $empresasScore = collect();
        if ($ctx['periodo']['is_closed']) {
            $empresasScore = $this->companyScoreReader->paraUsuario($user->id, $mesReferencia);
        }
        $temDetalheEmpresas = $empresasScore->isNotEmpty();
        $resumoEmpresas     = $this->companyScoreReader->resumo($empresasScore);

        return Inertia::render('Performance/Show', [
            'user' => [
                'id'          => $user->id,
                'name'        => $user->name,
                'role'        => $user->role,
                'cargo_slug'  => $cargoSlug,
                'cargo_label' => $cargoLabel,
            ],
            'resultado'             => $resultado,
            'mes_selecionado'       => $mesReferencia->toDateString(),
            'modo'                  => $ctx['modo'],
            'meses_disponiveis'     => $mesesFechados,
            'periodo'               => $ctx['periodo'],
            'bonus'                 => $ctx['bonus'],
            'nps_window'            => $ctx['nps'],
            'empresas_invalidadas'  => $invalidadasDoUser,
            'empresas_score'        => $empresasScore->values()->all(),
            'tem_detalhe_empresas'  => $temDetalheEmpresas,
            'empresas_score_resumo' => $resumoEmpresas,
        ]);
    }
}
