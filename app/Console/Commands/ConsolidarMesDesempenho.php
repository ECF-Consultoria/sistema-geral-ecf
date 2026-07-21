<?php

namespace App\Console\Commands;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 74 D-08 / DESEMP-09 — Consolida snapshot MENSAL FECHADO do módulo
 * Desempenho.
 *
 * v18.0 · Fase 105 (D2, NPSWIN-03): roda no ÚLTIMO DIA de cada mês às 14:00
 * BRT (não mais no dia 1) — após a coleta de NPS do mês corrente (M+1)
 * fechar. A competência congelada é sempre `today->subMonthNoOverflow()`
 * (mês anterior ao hoje = M), cujo NPS acabou de terminar de ser coletado
 * em M+1. Rodar no dia 1 (timing antigo) congelava a competência M no
 * INÍCIO da coleta de M+1, quando quase nenhuma resposta existia ainda —
 * pior que o bug original de janela (105-CONTEXT.md, Pitfall 3 do
 * RESEARCH). Ver `routes/console.php` (bloco `desempenho-consolidar-mes`).
 *
 * Diferente do `desempenho:snapshot-scores` (diário, mes_referencia=NULL),
 * este comando grava rows com `mes_referencia = YYYY-MM-01` e representa
 * o resultado FINAL do mês encerrado — insumo canônico do ranking mensal
 * do `/performance`, do widget "Desempenho da equipe" do Dashboard admin
 * e da regra DESEMP-08 (promoção por 2 meses consecutivos em
 * `intermediario` → `maximo`).
 *
 * Idempotência: `updateOrCreate(['user_id', 'mes_referencia'])` — rerun no
 * mesmo mês NÃO duplica rows. `--mes=YYYY-MM` permite reprocessamento
 * manual para catch-up pós-incident (DESEMP-09).
 *
 * DESEMP-10 (Sem carteira): users cuja carteira esteja vazia no mês são
 * PULADOS — não grava row, `Log::info` estruturado registra o evento.
 *
 * Constraint SPEC (memory): batch mensal itera ~15-20 users × ~30 empresas
 * × queries de metrics/NPS/margem → `ini_set('memory_limit','512M')` no
 * handle preserva margem contra PHP-FPM 256MB default.
 *
 * Após o lote: popula `ranking_pos` do mês via ROW_NUMBER() OVER ORDER BY
 * score DESC — filtrando `mes_referencia = ?` (não `ref_date`) para isolar
 * o ranking mensal do diário coexistente na mesma tabela.
 */
class ConsolidarMesDesempenho extends Command
{
    protected $signature = 'desempenho:consolidar-mes
        {--mes= : YYYY-MM (default = mês anterior ao hoje)}';

    protected $description = 'Consolida snapshot mensal fechado do mês passado (último dia do mês, após a coleta de NPS de M+1 fechar — v18.0 D2).';

    public function __construct(private DesempenhoScoreService $scoreService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Constraint SPEC: batch mensal costuma exigir mais RAM do que os 256MB
        // padrão do PHP-FPM (queries agregadas por empresa + provider ML/Adman).
        // Bump conservador de 512M evita OOM na cascata do último dia do mês
        // 14:00 BRT (v18.0 D2 — antes rodava dia 1).
        ini_set('memory_limit', '512M');

        // ── Deriva $mes (Carbon do 1º dia do mês alvo) ───────────────────────
        $mesOption = $this->option('mes');
        if ($mesOption) {
            try {
                // Rule 1 (bug pré-existente exposto pela v18.0 D2): NÃO usar
                // createFromFormat('Y-m', ...) — sem o dia explícito, o PHP
                // preenche o dia com o de "hoje" (agora tipicamente o último
                // dia do mês, 28-31) e ESTOURA para o mês seguinte quando o
                // mês alvo tem menos dias (ex.: hoje=31, --mes=2026-06 vira
                // 2026-07-01, não 2026-06-01). Ancorar no dia 1 explícito
                // elimina o overflow.
                $mes = Carbon::createFromFormat('Y-m-d', $mesOption . '-01')->startOfMonth();
            } catch (\Throwable $e) {
                $this->error("[Desempenho Mensal] Formato inválido para --mes: '{$mesOption}' (esperado YYYY-MM).");
                return self::FAILURE;
            }
        } else {
            // Default: mês ANTERIOR ao hoje (consolidação do mês encerrado).
            // subMonthNoOverflow evita edge case do dia 31 caindo em mês curto.
            $mes = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        }

        $mesStr = $mes->toDateString();
        $mesLabel = $mes->format('Y-m');

        // ── Users elegíveis (mesmo filtro do SnapshotDesempenhoScores) ───────
        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->get();

        $this->info("[Desempenho Mensal] Consolidando mês {$mesLabel} — {$users->count()} users elegíveis.");

        $ok = 0;
        $fail = 0;
        $semCarteira = 0;

        foreach ($users as $user) {
            try {
                $result = $this->scoreService->compute($user, $mes);

                // DESEMP-10 — sem carteira: pula (não grava row).
                if (($result['sem_carteira'] ?? false) === true) {
                    Log::info('[Desempenho Mensal] User sem carteira — pulando snapshot', [
                        'user_id'        => $user->id,
                        'user_name'      => $user->name,
                        'mes_referencia' => $mesStr,
                        'motivo'         => $result['motivo'] ?? null,
                    ]);
                    $semCarteira++;
                    continue;
                }

                DesempenhoScoreSnapshot::updateOrCreate(
                    [
                        'user_id'        => $user->id,
                        'mes_referencia' => $mesStr,
                    ],
                    [
                        'ref_date'             => $mes,
                        'score'                => (int) round(($result['nota_final'] ?? 0.0) * 20),
                        'classificacao'        => $result['faixa_bonus'] ?? '',
                        'tem_base_comparativa' => $result['nota_final'] !== null,
                        'empresas_carteira'    => (int) ($result['empresas_carteira'] ?? 0),
                        'empresas_eligiveis'   => (int) ($result['empresas_com_baseline'] ?? 0),
                        'breakdown_json'       => $result,
                    ]
                );
                $ok++;
            } catch (\Throwable $e) {
                Log::error("[Desempenho Mensal] Falha user {$user->id} ({$user->name}) mês {$mesLabel}: {$e->getMessage()}");
                $fail++;
                continue;
            }
        }

        // 2º passo — popular ranking_pos do mês (apenas rows mensais).
        $this->popularRankingPosMensal($mesStr);

        $this->info("[Desempenho Mensal] Mes {$mesLabel} — OK: {$ok} · Falhas: {$fail} · Sem carteira: {$semCarteira}");
        return self::SUCCESS;
    }

    /**
     * Popula `ranking_pos` para todos os snapshots MENSAIS de um mês
     * (mes_referencia = $mesStr), ordenando por `score` DESC (1 = melhor).
     *
     * MariaDB/MySQL 8: ROW_NUMBER() nativo, 1 query.
     * SQLite (testes) + fallback portável: N updates iterativos.
     *
     * Filtro por `mes_referencia = ?` (não `ref_date`) — em rows mensais
     * `ref_date = mes_referencia` mas o índice canonical (Plan 74-01 D-03)
     * é sobre `mes_referencia` e isola o ranking mensal do diário.
     *
     * @param string $mesStr Data no formato 'Y-m-01'
     */
    private function popularRankingPosMensal(string $mesStr): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(<<<SQL
                UPDATE desempenho_score_snapshots ds
                JOIN (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id ASC) AS pos
                    FROM desempenho_score_snapshots
                    WHERE DATE(mes_referencia) = ?
                ) r ON r.id = ds.id
                SET ds.ranking_pos = r.pos
                WHERE DATE(ds.mes_referencia) = ?
            SQL, [$mesStr, $mesStr]);
            return;
        }

        // Fallback portável (SQLite testes).
        $snaps = DesempenhoScoreSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->orderByDesc('score')
            ->orderBy('id')
            ->get(['id']);

        foreach ($snaps as $idx => $snap) {
            DB::table('desempenho_score_snapshots')
                ->where('id', $snap->id)
                ->update(['ranking_pos' => $idx + 1]);
        }
    }
}
