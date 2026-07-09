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
 * Phase 46 (Wave 1) — comando diário do snapshot de desempenho.
 *
 * Phase 74 D-08 (Wave 3) — este comando PRESERVA o schedule 13:30 BRT (D-1)
 * mas foi reescrito internamente para usar o `DesempenhoScoreService` v2
 * (motor 4 parâmetros + faixa de bônus). Cada snapshot diário é gravado
 * com `mes_referencia = NULL` (modo D-02) para diferenciar do fechamento
 * mensal do `desempenho:consolidar-mes` (mes_referencia = YYYY-MM-01).
 *
 * O compute() do motor v2 é chamado com `$mesReferencia = Carbon::today()
 * ->startOfMonth()` — score do mês em curso, parcial. Isso alimenta os
 * deltas rolling (delta_vs_ontem / delta_vs_semana_passada) do
 * `PerformanceController::index()` sem quebrar contrato: as colunas legadas
 * `score` (int 0-100) e `classificacao` (varchar) continuam preenchidas
 * como projeções da `nota_final` (0-5 × 20) e do slug da `faixa_bonus`.
 *
 * DESEMP-10 (Sem carteira): users cuja carteira esteja vazia no mês são
 * PULADOS — não grava row (mesmo padrão do comando mensal).
 *
 * Itera os mesmos users do PerformanceController::index() — filtro canônico
 * via user_setores → cargos.slug IN ['analista','estrategista'] (fonte da
 * verdade desde quick 260610-f69; `users.role` é legacy).
 *
 * Após o lote: popula `ranking_pos` do dia via UPDATE … ROW_NUMBER() OVER
 * (ORDER BY score DESC) — MariaDB nativo, fallback iterativo para SQLite.
 * Ranking DIÁRIO filtra por `ref_date` + `mes_referencia IS NULL`.
 */
class SnapshotDesempenhoScores extends Command
{
    protected $signature = 'desempenho:snapshot-scores
        {--data= : Data de referência YYYY-MM-DD (padrão: hoje)}
        {--user= : Snapshot apenas para 1 user (ID) — útil pra debug pontual}';

    protected $description = 'Grava snapshot diário do score de desempenho (motor v2 4-parâmetros; mês em curso parcial).';

    public function __construct(private DesempenhoScoreService $scoreService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // `ref_date` no Model tem cast `date` → Carbon. Manter como string `Y-m-d` em
        // todas as comparações (DB::table) e passar Carbon ao updateOrCreate (Eloquent)
        // pra evitar mismatch (SQLite tende a armazenar como 'YYYY-MM-DD 00:00:00'
        // quando o cast hidrata Carbon — vide whereDate() em testes).
        $refDateStr = $this->option('data')
            ? Carbon::createFromFormat('Y-m-d', $this->option('data'))->toDateString()
            : Carbon::today()->toDateString();
        $refDateCarbon = Carbon::createFromFormat('Y-m-d', $refDateStr)->startOfDay();

        // Motor v2 exige Carbon do mês (não do dia). O snapshot diário representa
        // o mês em curso parcial — comparamos com o mês corrente do refDate.
        $mesReferencia = Carbon::createFromFormat('Y-m-d', $refDateStr)->startOfMonth();

        // Mesmo filtro do PerformanceController::index — fonte canônica do cargo.
        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->when($this->option('user'), fn($q, $id) => $q->where('id', (int) $id))
            ->get();

        $this->info("[Desempenho] Snapshot diário {$refDateStr} (motor v2) — {$users->count()} users elegíveis.");

        $ok = 0;
        $fail = 0;
        $semCarteira = 0;

        foreach ($users as $user) {
            try {
                $result = $this->scoreService->compute($user, $mesReferencia);

                // DESEMP-10 — sem carteira: pula o user (não grava row).
                if (($result['sem_carteira'] ?? false) === true) {
                    Log::info("[Desempenho] User {$user->id} ({$user->name}) sem carteira em {$refDateStr} — pulando snapshot diário.");
                    $semCarteira++;
                    continue;
                }

                // Shape das colunas legadas preservado por compat (D-02):
                //  - `score`         (int 0-100) = nota_final * 20 arredondado
                //  - `classificacao` (varchar)   = slug da faixa_bonus (ou '')
                //  - `breakdown_json`            = shape completo do compute()
                $atributos = [
                    'mes_referencia'       => null, // DIÁRIO — flag do modo D-02
                    'score'                => (int) round(($result['nota_final'] ?? 0.0) * 20),
                    'classificacao'        => $result['faixa_bonus'] ?? '',
                    'tem_base_comparativa' => $result['nota_final'] !== null,
                    'empresas_carteira'    => (int) ($result['empresas_carteira'] ?? 0),
                    'empresas_eligiveis'   => (int) ($result['empresas_com_baseline'] ?? 0),
                    'breakdown_json'       => $result,
                ];

                // Lookup explícito por whereDate pra robustez SQLite + MariaDB.
                // Filtra ADICIONALMENTE por mes_referencia IS NULL — dois snapshots
                // podem coexistir no mesmo dia (diário + mensal do dia 1).
                $snap = DesempenhoScoreSnapshot::query()
                    ->where('user_id', $user->id)
                    ->whereDate('ref_date', $refDateStr)
                    ->whereNull('mes_referencia')
                    ->first();

                if ($snap) {
                    $snap->fill($atributos)->save();
                } else {
                    DesempenhoScoreSnapshot::create(array_merge($atributos, [
                        'user_id'  => $user->id,
                        'ref_date' => $refDateCarbon,
                    ]));
                }
                $ok++;
            } catch (\Throwable $e) {
                Log::error("[Desempenho] Falha snapshot diário user {$user->id} ({$user->name}): {$e->getMessage()}");
                $fail++;
                continue;
            }
        }

        // 2º passo — popular ranking_pos do dia (apenas snapshots diários).
        $this->popularRankingPos($refDateStr);

        $this->info("[Desempenho] OK: {$ok} · Falhas: {$fail} · Sem carteira: {$semCarteira}");
        return self::SUCCESS;
    }

    /**
     * Popula `ranking_pos` para todos os snapshots DIÁRIOS de uma data
     * (mes_referencia IS NULL), ordenando por `score` DESC (1 = melhor).
     *
     * MariaDB/MySQL 8 têm ROW_NUMBER() nativo; SQLite (testes) usa fallback
     * iterativo pra manter uma única estratégia portável em todos os drivers.
     * Filtro `mes_referencia IS NULL` isola o ranking diário do mensal
     * (que é populado por `ConsolidarMesDesempenho::popularRankingPosMensal`).
     *
     * @param string $refDate Data no formato 'Y-m-d'
     */
    private function popularRankingPos(string $refDate): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MariaDB 10.2+ / MySQL 8+: ROW_NUMBER() nativo, 1 query.
            DB::statement(<<<SQL
                UPDATE desempenho_score_snapshots ds
                JOIN (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id ASC) AS pos
                    FROM desempenho_score_snapshots
                    WHERE DATE(ref_date) = ?
                      AND mes_referencia IS NULL
                ) r ON r.id = ds.id
                SET ds.ranking_pos = r.pos
                WHERE DATE(ds.ref_date) = ?
                  AND ds.mes_referencia IS NULL
            SQL, [$refDate, $refDate]);
            return;
        }

        // Fallback portável (SQLite testes): N updates iterativos.
        $snaps = DesempenhoScoreSnapshot::query()
            ->whereDate('ref_date', $refDate)
            ->whereNull('mes_referencia')
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
