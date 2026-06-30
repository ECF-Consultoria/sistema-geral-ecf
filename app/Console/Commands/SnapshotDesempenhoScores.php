<?php

namespace App\Console\Commands;

use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\PortfolioScoreService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 46 — grava snapshot diário do score de desempenho de cada
 * analista/estrategista. Roda às 13:30 BRT (cascata D-1, depois de
 * SyncPolosFaturamentoJob 13:00).
 *
 * Itera os mesmos users do PerformanceController::index() — filtro canônico
 * via user_setores → cargos.slug IN ['analista','estrategista'] (fonte da
 * verdade desde quick 260610-f69; `users.role` é legacy).
 *
 * Para cada user:
 *   - chama PortfolioScoreService::compute() — mesma engine do /performance
 *   - persiste 1 linha via updateOrCreate(['user_id','ref_date']) → idempotente
 *
 * Após o lote: popula `ranking_pos` da data via UPDATE … ROW_NUMBER() OVER
 * (ORDER BY score DESC). Fallback iterativo pra SQLite (testes), MariaDB usa
 * window function nativa.
 */
class SnapshotDesempenhoScores extends Command
{
    protected $signature = 'desempenho:snapshot-scores
        {--data= : Data de referência YYYY-MM-DD (padrão: hoje)}
        {--user= : Snapshot apenas para 1 user (ID) — útil pra debug pontual}';

    protected $description = 'Grava snapshot diário do score de desempenho de cada analista/estrategista (cascata D-1).';

    public function __construct(private PortfolioScoreService $scoreService)
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

        $this->info("[Desempenho] Snapshot {$refDateStr} — {$users->count()} users elegíveis.");

        $ok = 0;
        $fail = 0;

        foreach ($users as $user) {
            try {
                $result = $this->scoreService->compute($user);

                // Lookup explícito por whereDate pra robustez SQLite + MariaDB.
                $snap = DesempenhoScoreSnapshot::query()
                    ->where('user_id', $user->id)
                    ->whereDate('ref_date', $refDateStr)
                    ->first();

                $atributos = [
                    'score'                => (int) round($result['score']),
                    'classificacao'        => $result['classificacao'],
                    'tem_base_comparativa' => (bool) $result['tem_base_comparativa'],
                    'empresas_carteira'    => (int) $result['empresas_carteira'],
                    'empresas_eligiveis'   => (int) $result['empresas_eligiveis'],
                    'breakdown_json'       => $result['metricas'] ?? [],
                ];

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
                Log::error("[Desempenho] Falha snapshot user {$user->id} ({$user->name}): {$e->getMessage()}");
                $fail++;
                continue;
            }
        }

        // 2º passo — popular ranking_pos da data.
        $this->popularRankingPos($refDateStr);

        $this->info("[Desempenho] OK: {$ok} · Falhas: {$fail}");
        return self::SUCCESS;
    }

    /**
     * Popula `ranking_pos` para todos os snapshots de uma data, ordenando por
     * `score` DESC (1 = melhor). MariaDB/MySQL 8 e PostgreSQL têm ROW_NUMBER();
     * SQLite (testes) também desde 3.25 mas vamos via fallback iterativo pra
     * mantermos uma única estratégia portável em todos os drivers.
     *
     * @param string $refDate Data no formato 'Y-m-d'
     */
    private function popularRankingPos(string $refDate): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MariaDB 10.2+ / MySQL 8+: ROW_NUMBER() nativo, 1 query.
            // DATE(ref_date) normaliza pra comparação robusta com o input string.
            DB::statement(<<<SQL
                UPDATE desempenho_score_snapshots ds
                JOIN (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY score DESC, id ASC) AS pos
                    FROM desempenho_score_snapshots
                    WHERE DATE(ref_date) = ?
                ) r ON r.id = ds.id
                SET ds.ranking_pos = r.pos
                WHERE DATE(ds.ref_date) = ?
            SQL, [$refDate, $refDate]);
            return;
        }

        // Fallback portável (SQLite testes + qualquer outro): N updates iterativos.
        // whereDate normaliza ref_date pro formato YYYY-MM-DD independente de como
        // o driver armazena (SQLite tende a usar TEXT com '00:00:00' anexado).
        // Tiebreaker por id ASC garante determinismo.
        $snaps = DesempenhoScoreSnapshot::query()
            ->whereDate('ref_date', $refDate)
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
