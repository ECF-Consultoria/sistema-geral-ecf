<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aquece o cache do `DesempenhoScoreService::computeCached()` pra todos os
 * profissionais analista/estrategista antes do TTL expirar.
 *
 * Ajuste 2026-07-10 (audit performance-lentidao):
 * O `compute()` cru gasta ~70s pra 11 users em cold cache (99% em HTTP
 * síncrono à API ML). `computeCached()` reduz warm hits a <1s, mas o
 * primeiro user que abre o dashboard após 10min sem atividade ainda cai
 * nesses 70s. Este command roda em background a cada 8min (2min de margem
 * antes do TTL de 10min) e pré-computa/cacheia — assim nenhum user
 * chega a ver cold miss.
 *
 * Filtro canônico: user_setores → cargos.slug IN ['analista','estrategista'].
 * Mesmo escopo do PerformanceController::index e do SnapshotDesempenhoScores.
 *
 * Este comando NÃO grava snapshot — só popula cache Redis (compute()
 * agregado). Snapshot mensal fechado continua no command
 * `desempenho:consolidar-mes`; snapshot diário do mês em curso continua
 * no `desempenho:snapshot-scores`.
 */
class WarmDesempenhoCache extends Command
{
    protected $signature = 'desempenho:warm-cache
        {--user= : Aquecer apenas para 1 user (ID) — útil pra debug pontual}';

    protected $description = 'Aquece o cache do compute() de desempenho pra todos analista/estrategista (roda a cada 8min via schedule).';

    public function __construct(private DesempenhoScoreService $scoreService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesReferencia = Carbon::now()->startOfMonth();

        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->when($this->option('user'), fn ($q, $id) => $q->where('id', (int) $id))
            ->get();

        $this->info("[Desempenho] Warming cache — {$users->count()} users elegíveis.");

        $ok   = 0;
        $fail = 0;
        $t0   = microtime(true);

        foreach ($users as $user) {
            $tUser = microtime(true);
            try {
                // computeCached() faz Cache::remember internamente — se cache
                // ainda quente do run anterior, retorna instantâneo.
                $this->scoreService->computeCached($user, $mesReferencia);
                $elapsed = round(microtime(true) - $tUser, 2);
                $this->line("  ✓ user={$user->id} ({$user->name}) — {$elapsed}s");
                $ok++;
            } catch (\Throwable $e) {
                Log::warning("[Desempenho] Warm cache falhou pra user {$user->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ user={$user->id} — {$e->getMessage()}");
                $fail++;
            }
        }

        $total = round(microtime(true) - $t0, 2);
        $this->info("[Desempenho] Warm cache concluído em {$total}s — OK={$ok}, FAIL={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
