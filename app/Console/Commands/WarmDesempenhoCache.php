<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
 *
 * Fase 106 Plan 01 (SC1 — fix timeout do mês fechado): o warm agendado
 * passou a aquecer DOIS alvos na mesma execução — o mês corrente
 * (comportamento original) MAIS a competência do último mês fechado
 * (via `MetricPeriodResolver::resolve(['period_key'=>'last_closed_month'])`)
 * — "Bônus atual" deixa de depender de band-aid manual em prod. Ganha
 * também `--mes=YYYY-MM` (catch-up de UMA competência específica, sem
 * tocar o mês corrente) e `--user=*` array (restringe a IDs específicos —
 * insumo do dispatch sob-demanda do Plan 106-02).
 */
class WarmDesempenhoCache extends Command
{
    protected $signature = 'desempenho:warm-cache
        {--mes= : Aquece só esta competência YYYY-MM (catch-up/sob-demanda) — NÃO aquece o mês corrente automaticamente}
        {--user=* : Aquecer só estes user IDs (debug ou dispatch sob-demanda)}';

    protected $description = 'Aquece o cache do compute() de desempenho pra todos analista/estrategista (roda a cada 8min via schedule).';

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricPeriodResolver $periodResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $mesOpt = $this->option('mes');

        if ($mesOpt !== null) {
            // T-106-01 (ASVS V5) — nunca deixar o valor cru chegar ao Carbon;
            // formato inválido falha graciosamente (FAILURE), sem 500.
            // Valida também o RANGE do mês (01-12): Carbon::createFromFormat
            // NÃO rejeita mês 13 — faz overflow silencioso pro ano seguinte
            // (ex.: '2026-13' vira '2027-01'), o que aqueceria a competência
            // ERRADA em vez de falhar. O regex sozinho não pega isso.
            if (preg_match('/^\d{4}-(\d{2})$/', $mesOpt, $matches) !== 1 || (int) $matches[1] < 1 || (int) $matches[1] > 12) {
                $this->error("[Desempenho] --mes inválido: '{$mesOpt}'. Formato esperado: YYYY-MM (mês 01-12).");

                return self::FAILURE;
            }

            try {
                $mesesAlvo = [Carbon::createFromFormat('Y-m', $mesOpt)->startOfMonth()];
            } catch (\Throwable $e) {
                $this->error("[Desempenho] --mes inválido: '{$mesOpt}'. {$e->getMessage()}");

                return self::FAILURE;
            }
        } else {
            // SC1 — 2 alvos: mês corrente (comportamento original) + último
            // mês fechado (nunca fixar um valor — recalculado a cada execução,
            // ver Pitfall 1 do 106-RESEARCH.md).
            $mesesAlvo = [
                Carbon::now()->startOfMonth(),
                Carbon::parse(
                    $this->periodResolver->resolve(['period_key' => 'last_closed_month'])['bonus_competence_month'] . '-01'
                )->startOfMonth(),
            ];
        }

        // T-106-02 — IDs sempre coeridos a int antes do whereIn. Array vazio
        // é falsy: sem --user, o filtro não aplica (comportamento agendado
        // preservado, mesmo escopo de sempre).
        $userIds = array_map('intval', (array) $this->option('user'));

        $users = User::query()
            ->where('active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('user_setores as us')
                  ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                  ->whereColumn('us.user_id', 'users.id')
                  ->whereIn('c.slug', ['analista', 'estrategista']);
            })
            ->when($userIds, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->get();

        $this->info("[Desempenho] Warming cache — {$users->count()} users elegíveis × " . count($mesesAlvo) . ' competência(s).');

        $ok   = 0;
        $fail = 0;
        $t0   = microtime(true);

        foreach ($mesesAlvo as $mesReferencia) {
            foreach ($users as $user) {
                $tUser = microtime(true);
                try {
                    // computeCached() faz Cache::remember internamente — se cache
                    // ainda quente do run anterior, retorna instantâneo.
                    //
                    // Fase 120 (AGRE-02/C-02) — guard do Cache::remember: com
                    // shadow ligado (`incluirEmpresasScore: true`), se o cache
                    // já estiver quente com um payload ANTERIOR à Fase 120 (ou
                    // populado por leitura interativa, shadow desligado), o
                    // closure de `Cache::remember` NÃO roda e o shadow seria
                    // SILENCIOSAMENTE pulado neste ciclo. Por isso: recomputa
                    // só quando falta `empresas_score` no payload cacheado.
                    // `Cache::forget()` incondicional foi REJEITADO — recomputaria
                    // ~70s por user a cada ciclo de 8min, exatamente o custo que
                    // este warm existe pra evitar. `desempenho:consolidar-mes`
                    // não sofre desse problema porque chama `compute()` direto,
                    // sem `Cache::remember`.
                    $resultado = $this->scoreService->computeCached($user, $mesReferencia, null, incluirEmpresasScore: true);

                    if (! array_key_exists('empresas_score', $resultado)) {
                        Cache::forget($this->scoreService->cacheKey($user->id, $mesReferencia));
                        $resultado = $this->scoreService->computeCached($user, $mesReferencia, null, incluirEmpresasScore: true);
                    }

                    $elapsed = round(microtime(true) - $tUser, 2);
                    $this->line("  ✓ user={$user->id} ({$user->name}) mes={$mesReferencia->format('Y-m')} — {$elapsed}s");
                    $ok++;
                } catch (\Throwable $e) {
                    Log::warning("[Desempenho] Warm cache falhou pra user {$user->id} mes {$mesReferencia->format('Y-m')}", [
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("  ✗ user={$user->id} mes={$mesReferencia->format('Y-m')} — {$e->getMessage()}");
                    $fail++;
                }
            }
        }

        $total = round(microtime(true) - $t0, 2);
        $this->info("[Desempenho] Warm cache concluído em {$total}s — OK={$ok}, FAIL={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
