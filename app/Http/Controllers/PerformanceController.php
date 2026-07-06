<?php

namespace App\Http\Controllers;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Meeting;
use App\Models\NpsSurvey;
use App\Models\Ppa;
use App\Models\Publicacao;
use App\Models\User;
use App\Services\PortfolioScoreService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PerformanceController extends Controller
{
    public function __construct(private PortfolioScoreService $scoreService) {}

    public function index(Request $request)
    {
        // Phase 49 UAT 2026-06-30: /performance é exclusivamente consultoria.
        // Publicações tem rota própria /publicacao/desempenho via indexPublicacao().
        // Param ?setor=polos é IGNORADO aqui — qualquer tentativa retorna ranking de consultoria.
        $setor = 'consultoria';

        $period = $request->get('period', '30');

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

        // ── Quick 260623 redesign performance — ranking por SCORE ──
        // Conforme metodologia-desempenho-carteira.md: ranking por score
        // composto, NAO por faturamento bruto ou NPS isolado. Cada user passa
        // pelo PortfolioScoreService que aplica os pesos do brief.
        //
        // Identifica cargo (analista/estrategista) via user_setores → cargos
        // (fonte da verdade desde quick 260610-f69). users.role eh legacy.
        $cargosPorUser = DB::table('user_setores as us')
            ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
            ->whereIn('c.slug', ['analista', 'estrategista'])
            ->select('us.user_id', 'c.slug')
            ->get()
            ->keyBy('user_id');

        $rankingRaw = $users->map(function ($u) use ($cargosPorUser) {
            $resultado = $this->scoreService->compute($u);
            $cargoSlug = $cargosPorUser->get($u->id)?->slug ?? ($u->isMentor() ? 'estrategista' : 'analista');
            $m = $resultado['metricas'];
            return [
                'id'                  => $u->id,
                'name'                => $u->name,
                'role'                => $u->role,
                'cargo_slug'          => $cargoSlug,
                'cargo_label'         => $cargoSlug === 'estrategista' ? 'Estrategista' : 'Analista',
                'empresas_carteira'   => $resultado['empresas_carteira'],
                'empresas_eligiveis'  => $resultado['empresas_eligiveis'],
                'tem_base_comparativa'=> $resultado['tem_base_comparativa'],
                'score'               => $resultado['score'],
                'classificacao'       => $resultado['classificacao'],
                'crescimento_ajustado_pct'   => $m['crescimento_ajustado_pct'],
                'empresas_em_crescimento_pct'=> $m['empresas_em_crescimento']['pct'] ?? null,
                'atingimento_meta_pct'       => $m['atingimento_meta']['pct'] ?? null,
                'execucao_ads_pct'           => $m['execucao_ads']['pct'] ?? null,
                'recuperacao_pct'            => $m['recuperacao']['pct'] ?? null,
                'avg_nps'                    => $m['qualidade']['avg_nps'] ?? null,
                'total_meetings'             => $m['qualidade']['meetings'] ?? 0,
                'absenteeism_rate'           => $m['qualidade']['absenteismo_pct'] ?? 0,
                'faturamento_atual'          => $m['faturamento']['atual'] ?? 0.0,
                'faturamento_anterior'       => $m['faturamento']['anterior'] ?? 0.0,
            ];
        });

        // Tendencia (subindo/estavel/descendo) baseada no crescimento ajustado.
        $rankingRaw = $rankingRaw->map(function ($r) {
            $cr = $r['crescimento_ajustado_pct'];
            $r['tendencia'] = $cr === null ? 'sem_dado'
                : ($cr >= 5 ? 'subindo'
                    : ($cr <= -5 ? 'descendo' : 'estavel'));
            return $r;
        });

        // Ordena por score DESC; bases comparativas insuficientes vao pro fim.
        $ranking = $rankingRaw
            ->sortByDesc(fn ($r) => ($r['tem_base_comparativa'] ? 1 : 0) * 1000 + $r['score'])
            ->values()
            ->map(function ($r, $idx) {
                $r['posicao'] = $idx + 1;
                return $r;
            });

        // ── Phase 46 Plan 46-02 — enriquece ranking com deltas longitudinais ──
        // Lê snapshots da tabela `desempenho_score_snapshots` (populada pelo
        // schedule `desempenho:snapshot-scores` às 13:30 BRT, Wave 1).
        //   - delta_vs_ontem          = score_hoje − snapshot mais recente STRICTLY < hoje
        //   - delta_vs_semana_passada = score_hoje − snapshot mais recente <= today-7d
        // 2 queries totais (sem N+1): pegamos TODOS os snapshots elegíveis dos users
        // do ranking, agrupamos por user e mantemos só o mais recente.
        // IMPORTANTE: usar whereDate em vez de where('ref_date', $str) — SQLite (testes)
        // armazena `date` casted como 'YYYY-MM-DD 00:00:00' e a comparação string falha.
        // whereDate funciona idêntico em MariaDB prod (DATE(ref_date) = ?).
        $userIds = $ranking->pluck('id')->all();
        $ontem          = collect();
        $semanaPassada  = collect();
        if (!empty($userIds)) {
            $hojeStr      = now()->toDateString();
            $semanaCorte  = now()->subDays(7)->toDateString();

            $snapshotsOntem = DesempenhoScoreSnapshot::query()
                ->whereIn('user_id', $userIds)
                ->whereDate('ref_date', '<', $hojeStr)
                ->orderBy('ref_date', 'desc')
                ->get(['user_id', 'ref_date', 'score']);
            // groupBy + first() mantem 1 por user (mais recente por causa do orderBy DESC).
            $ontem = $snapshotsOntem->groupBy('user_id')->map(fn ($g) => $g->first());

            $snapshotsSemana = DesempenhoScoreSnapshot::query()
                ->whereIn('user_id', $userIds)
                ->whereDate('ref_date', '<=', $semanaCorte)
                ->orderBy('ref_date', 'desc')
                ->get(['user_id', 'ref_date', 'score']);
            $semanaPassada = $snapshotsSemana->groupBy('user_id')->map(fn ($g) => $g->first());
        }

        $ranking = $ranking->map(function ($r) use ($ontem, $semanaPassada) {
            $scoreHoje = (float) $r['score'];
            $sOntem    = $ontem->get($r['id']);
            $sSemana   = $semanaPassada->get($r['id']);
            $r['delta_vs_ontem']          = $sOntem  ? round($scoreHoje - (float) $sOntem->score, 1)  : null;
            $r['delta_vs_semana_passada'] = $sSemana ? round($scoreHoje - (float) $sSemana->score, 1) : null;
            return $r;
        });

        // Filtra por cargo pós-cálculo (cargo_slug já presente em cada item do ranking)
        if ($cargo !== null) {
            $ranking = $ranking->filter(fn ($r) => $r['cargo_slug'] === $cargo)->values();
        }

        return Inertia::render('Performance/Index', [
            'ranking' => $ranking,
            'period'  => $period,
            'setor'   => 'consultoria',
            'cargo'   => $cargo,
        ]);
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
     * Puxa dados REAIS via PortfolioScoreService + queries dedicadas por
     * empresa da carteira (revenue AdmanMetric, NPS NpsSurvey, meta Goal).
     */
    public function dashboardCarteira(Request $request): \Inertia\Response
    {
        $user = $request->user();

        // ── Score + métricas agregadas de carteira ──
        $data = $this->scoreService->compute($user);

        // ── Empresas em carteira (todas, ativas) ──
        $companies = $user->companies()->where('active', true)->get();
        $companyIds = $companies->pluck('id');
        $atualFrom = $data['periodo']['from'];
        $atualTo   = $data['periodo']['to'];

        // Revenue + revenue anterior + ads por empresa (últimos 30d)
        $metricsByCompany = \App\Models\AdmanMetric::whereIn('company_id', $companyIds)
            ->whereBetween('reference_date', [$atualFrom, $atualTo])
            ->selectRaw('company_id, SUM(revenue) as rev, SUM(revenue_prev_period) as rev_prev, SUM(ad_spend) as ads')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // NPS score mais recente por empresa (últimos 60d completed)
        $npsField = $user->isMentor() ? 'score_estrategista' : 'score_analista';
        $npsByCompany = \App\Models\NpsSurvey::with('response')
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(60))
            ->get()
            ->groupBy('company_id')
            ->map(fn ($group) => $group->sortByDesc('completed_at')->first());

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

            $nps = null;
            $survey = $npsByCompany->get($c->id);
            if ($survey && $survey->response) {
                $nps = (int) $survey->response->{$user->isMentor() ? 'score_estrategista' : 'score_analista'} ?? null;
            }

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
                'ml'          => $c->marketplace === 'meli',
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
        $recentSurveys = \App\Models\NpsSurvey::with(['response', 'company'])
            ->whereIn('company_id', $companyIds)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(4)
            ->get();

        $npsRespostas = $recentSurveys->map(function ($s) use ($npsField) {
            $nota = $s->response?->$npsField;
            if ($nota === null) return null;
            $classe = $nota >= 9 ? 'Promotor' : ($nota >= 7 ? 'Neutro' : 'Detrator');
            return [
                'empresa' => $s->company?->name ?? '—',
                'nota'    => (int) $nota,
                'classe'  => $classe,
                'quando'  => optional($s->completed_at)->diffForHumans(),
            ];
        })->filter()->values();

        // ── Metas widget: 3 metas agregadas da carteira ──
        $metricas = $data['metricas'];
        $atingimento = $metricas['atingimento_meta'];
        $emCresc = $metricas['empresas_em_crescimento'];

        $metasWidget = [];
        // Faturamento mensal (meta agregada portfolio)
        if ($atingimento['target_value'] && $atingimento['target_value'] > 0) {
            $metasWidget[] = [
                'icone'    => 'dollar',
                'nome'     => 'Faturamento vs meta',
                'atual'    => $this->fmtBRL((float) $atingimento['realized_value']),
                'objetivo' => $this->fmtBRL((float) $atingimento['target_value']),
                'percent'  => (int) min(100, round((float) $atingimento['pct'])),
            ];
        }
        // Empresas em crescimento
        if ($emCresc['total'] > 0) {
            $metasWidget[] = [
                'icone'    => 'trend',
                'nome'     => 'Empresas em crescimento',
                'atual'    => (string) $emCresc['count'],
                'objetivo' => "{$emCresc['total']} empresas",
                'percent'  => (int) ($emCresc['pct'] ?? 0),
            ];
        }
        // Qualidade (NPS médio)
        $qual = $metricas['qualidade'];
        if ($qual['avg_nps'] !== null) {
            $notaMax = 5.0; // escala NPS interna 0-5
            $pct = (int) min(100, round(($qual['avg_nps'] / $notaMax) * 100));
            $metasWidget[] = [
                'icone'    => 'check',
                'nome'     => 'Qualidade NPS média',
                'atual'    => number_format($qual['avg_nps'], 1, ',', '.'),
                'objetivo' => '5,0',
                'percent'  => $pct,
            ];
        }

        return Inertia::render('Performance/Dashboard', [
            'pessoa' => [
                'nome'   => $user->name,
                'funcao' => $user->isMentor() ? 'Estrategista' : 'Analista de Performance',
                'role_key' => $user->isMentor() ? 'mentor' : ($user->isConsultor() ? 'consultor' : 'other'),
                'iniciais' => $this->iniciais($user->name),
            ],
            'periodo' => 'Últimos 30 dias',
            'kpis' => [
                'faturamento_total'       => (float) $metricas['faturamento']['atual'],
                'empresas_em_carteira'    => $data['empresas_carteira'],
                'empresas_conectadas_ml'  => (int) $companies->where('marketplace', 'meli')->count(),
                'crescimento_percent'     => $metricas['crescimento_ajustado_pct'],  // null se sem base
                'crescimento_delta_valor' => (float) $metricas['faturamento']['atual'] - (float) $metricas['faturamento']['anterior'],
                'crescimento_mediana'     => $metricas['crescimento_mediano_empresa_pct'],
                'score'                   => $data['score'],
                'score_label'             => $data['classificacao'],
                'empresas_em_crescimento' => $emCresc['count'],
                'tem_base_comparativa'    => $data['tem_base_comparativa'],
            ],
            'nps' => [
                'media'     => $qual['avg_nps'],
                'respostas' => $npsRespostas,
            ],
            'metas' => $metasWidget,
            'empresas' => $empresas,
        ]);
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
        $ref    = Carbon::createFromFormat('Y-m', $mesRef)->startOfMonth();
        $primeiro = $ref->copy()->startOfMonth()->toDateString();
        $ultimo   = $ref->copy()->endOfMonth()->toDateString();

        $users = User::where('active', true)
            ->whereIn('publication_role', ['publicador', 'lider'])
            ->orderBy('name')
            ->get(['id', 'name', 'publication_role', 'publication_meta']);

        $hoje      = Carbon::today();
        $primeiroC = $ref->copy()->startOfMonth();
        $ultimoC   = $ref->copy()->endOfMonth();

        $diasDecorridos = $this->diasUteis($primeiroC, $hoje->lt($ultimoC) ? $hoje : $ultimoC);
        $diasRestantes  = $hoje->lt($ultimoC) ? $this->diasUteis($hoje->copy()->addDay(), $ultimoC) : 0;
        $diasTotal      = max($diasDecorridos + $diasRestantes, 1);

        $rawRanking = $users->map(function ($u) use ($primeiro, $ultimo, $diasDecorridos, $diasTotal, $mesRef) {
            $meta       = $this->metaParaMes($u->id, $mesRef);
            $feito      = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->count();
            $vendas     = Publicacao::where('user_id', $u->id)->whereBetween('data', [$primeiro, $ultimo])->where('vendido', true)->count();

            $percentual_meta = $meta > 0 ? $feito / $meta : 0.0;
            $conversao_raw   = $feito > 0 ? $vendas / $feito : 0.0;

            $mediaAtual = $diasDecorridos > 0 ? round($feito / $diasDecorridos, 1) : 0.0;
            $projecao   = (int) round($mediaAtual * $diasTotal);

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
                'pub_role'        => $u->publication_role,
                'meta'            => $meta,
                'feito'           => $feito,
                'vendas'          => $vendas,
                'percentual'      => $meta > 0 ? round($percentual_meta * 100, 1) : 0.0,
                'conversao'       => round($conversao_raw * 100, 1),
                'projecao'        => $projecao,
                'status'          => $status,
                // campos intermediários para normalização
                '_pct_meta'       => $percentual_meta,
                '_conversao_raw'  => $conversao_raw,
            ];
        });

        // Normalização por grupo e cálculo do score final
        $maxVendas    = max((int) $rawRanking->max('vendas'),   1);
        $maxConversao = max($rawRanking->max('_conversao_raw'), 0.0001);

        $ranking = $rawRanking->map(function ($u) use ($maxVendas, $maxConversao) {
            $pctMeta      = min($u['_pct_meta'],      1.0); // limita em 1 para não distorcer
            $vendasNorm   = $u['vendas']           / $maxVendas;
            $conversaoNorm = $u['_conversao_raw']  / $maxConversao;

            $score = round(($pctMeta * 0.4 + $vendasNorm * 0.4 + $conversaoNorm * 0.2) * 100, 1);

            return [
                'id'              => $u['id'],
                'name'            => $u['name'],
                'pub_role'        => $u['pub_role'],
                'meta'            => $u['meta'],
                'feito'           => $u['feito'],
                'vendas'          => $u['vendas'],
                'percentual'      => $u['percentual'],
                'conversao'       => $u['conversao'],
                'projecao'        => $u['projecao'],
                'status'          => $u['status'],
                'score_final'     => $score,
            ];
        })->sortByDesc('score_final')->values();

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

    private function metaParaMes(int $userId, string $mes): int
    {
        $registro = DB::table('mlb_meta_historico')
            ->where('user_id', $userId)
            ->where('mes_inicio', '<=', $mes)
            ->orderByDesc('mes_inicio')
            ->value('meta');

        if ($registro !== null) return (int) $registro;

        return (int) (User::find($userId)?->publication_meta ?? 220);
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

    public function show(Request $request, User $user): \Inertia\Response
    {
        $period = $request->get('period', '30');
        $since = match ($period) {
            '7'   => now()->subDays(7),
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '180' => now()->subDays(180),
            default => now()->subDays(30),
        };

        $companyIds = $user->isMentor()
            ? $user->estrategistaCompanies()->pluck('companies.id')
            : $user->consultorCompanies()->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            $companyIds = $user->companies()->pluck('companies.id');
        }

        // Phase 31 (Plan 05) — taxonomia nova (idem ranking acima).
        $scoreField = $user->isMentor() ? 'score_estrategista' : 'score_analista';

        $companies = Company::whereIn('id', $companyIds)
            ->where('active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($c) use ($since, $scoreField) {
                $surveys = NpsSurvey::with('response')
                    ->where('company_id', $c->id)
                    ->where('status', 'completed')
                    ->where('completed_at', '>=', $since)
                    ->get();

                $avgNps = $surveys->count() > 0
                    ? round($surveys->avg(fn($s) => $s->response?->$scoreField ?? 0), 1)
                    : null;

                $meetings = Meeting::where('company_id', $c->id)
                    ->where('status', 'completed')
                    ->where('scheduled_at', '>=', $since)
                    ->get();

                $absences = $meetings->filter(fn($m) => !$m->consultant_present || !$m->mentor_present)->count();
                $absenteeism = $meetings->count() > 0
                    ? round($absences / $meetings->count() * 100, 1)
                    : null;

                $metric = AdmanMetric::where('company_id', $c->id)
                    ->where('reference_date', '>=', $since)
                    ->latest('reference_date')
                    ->first();

                $ppa = Ppa::where('company_id', $c->id)
                    ->latest('created_at')
                    ->first();

                return [
                    'id'               => $c->id,
                    'name'             => $c->name,
                    'avg_nps'          => $avgNps,
                    'nps_responses'    => $surveys->count(),
                    'total_meetings'   => $meetings->count(),
                    'absenteeism_rate' => $absenteeism,
                    'revenue'          => $metric?->revenue,
                    'revenue_growth'   => $metric?->revenue_prev_period > 0 ? round($metric->revenue_growth, 1) : null,
                    'tacos'            => $metric?->tacos,
                    'ppa_status'       => $ppa?->status,
                ];
            });

        // Resumo agregado
        $withNps = $companies->whereNotNull('avg_nps');
        $summary = [
            'avg_nps'          => $withNps->count() > 0 ? round($withNps->avg('avg_nps'), 1) : null,
            'total_revenue'    => $companies->sum('revenue') ?: null,
            'avg_tacos'        => $companies->whereNotNull('tacos')->avg('tacos') ? round($companies->whereNotNull('tacos')->avg('tacos'), 2) : null,
            'total_meetings'   => $companies->sum('total_meetings'),
            'companies_count'  => $companies->count(),
        ];

        return Inertia::render('Performance/Show', [
            'profile_user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
            'companies'    => $companies->values(),
            'summary'      => $summary,
            'period'       => $period,
        ]);
    }
}
