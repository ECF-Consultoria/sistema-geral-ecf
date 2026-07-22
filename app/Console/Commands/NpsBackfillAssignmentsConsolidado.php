<?php

namespace App\Console\Commands;

use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Backfill idempotente — debug `nps-assignment-consolidado` (2026-07-22).
 *
 * Cria as `nps_score_assignments` que faltam para respostas NPS já
 * respondidas cujo responsável (analista/estrategista) é CONSOLIDADO
 * (`company_users.servico_id = NULL`) — a régua antiga (`Company::
 * consultorDoServico()`/`estrategistaDoServico()`) não achava esse
 * responsável e a resposta nascia sem atribuição, sumindo do bônus.
 *
 * Reusa `NpsSnapshotService::backfillAssignments()` (mesma fonte de verdade
 * do submit — `registrar()` — via `Company::responsavelDoServicoOuConsolidado()`).
 * Nunca reprocessa scores/covered_services (que já estão congelados
 * corretamente); só a etapa 3 (assignments), e só o que falta.
 *
 * Idempotente: rodar de novo depois de mais respostas chegarem não duplica
 * nada — `backfillAssignments()` pula (response, servico, role) que já
 * tenha assignment gravado.
 *
 * Depois de aplicar, busta o cache do bônus (`DesempenhoScoreService::
 * cacheKey()`) dos usuários/meses afetados — mesma régua de
 * `NpsController::bustarCacheDoBonus()` (competência = mês de
 * completed_at MENOS 1 mês, NPSWIN-03/Fase 105).
 *
 * Uso:
 *   php artisan nps:backfill-assignments-consolidado --dry-run   # só mostra o diff
 *   php artisan nps:backfill-assignments-consolidado             # pede confirmação
 *   php artisan nps:backfill-assignments-consolidado --force     # sem confirmação
 *
 * @see app/Services/Nps/NpsSnapshotService.php (backfillAssignments)
 * @see .planning/debug/nps-assignment-consolidado.md
 */
class NpsBackfillAssignmentsConsolidado extends Command
{
    protected $signature = 'nps:backfill-assignments-consolidado
        {--dry-run : Só mostra o diff, sem gravar}
        {--force   : Pula a confirmação interativa}';

    protected $description = 'Cria nps_score_assignments faltantes para respostas com responsável CONSOLIDADO (debug nps-assignment-consolidado)';

    public function __construct(private NpsSnapshotService $snapshotService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $this->info('[NPS Backfill Consolidado] Analisando respostas...' . ($dryRun ? ' (DRY-RUN)' : ''));

        // ─── Passo 1: preview SEMPRE em modo dry-run (read-only) ─────────────
        [$candidatos, $stats, $detalheTotal] = $this->analisar();

        $this->exibirDiff($detalheTotal);
        $this->exibirStats($stats);

        if ($stats['criados'] === 0) {
            $this->info('[NPS Backfill Consolidado] Nada a corrigir.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[NPS Backfill Consolidado] DRY-RUN — nada foi gravado.');

            return self::SUCCESS;
        }

        if (! $force) {
            $confirmado = $this->confirm(
                'Criar ' . $stats['criados'] . ' assignment(s) faltante(s) em nps_score_assignments '
                . 'e bustar o cache do bônus dos usuários/meses afetados?',
                false
            );

            if (! $confirmado) {
                $this->warn('[NPS Backfill Consolidado] Operação cancelada pelo operador — nada foi gravado.');

                return self::SUCCESS;
            }
        }

        // ─── Passo 2: gravação real + cache-bust ─────────────────────────────
        $statsReais = $this->aplicar($candidatos);

        $this->info('[NPS Backfill Consolidado] Concluído:');
        $this->exibirStats($statsReais);

        Log::info('[NPS Backfill Consolidado] execução concluída', $statsReais);

        return self::SUCCESS;
    }

    /**
     * Passo 1 (read-only): roda `backfillAssignments($response, dryRun: true)`
     * em toda resposta com template (v15), agregando os stats e coletando os
     * IDs de resposta que TÊM algo a criar (para o passo 2 não precisar
     * re-escanear tudo).
     *
     * @return array{0: array<int>, 1: array, 2: array}
     */
    private function analisar(): array
    {
        $stats = [
            'criados'               => 0,
            'pulos_ja_existentes'   => 0,
            'pulos_sem_responsavel' => 0,
        ];
        $detalheTotal   = [];
        $responseIdsComPendencia = [];

        NpsResponse::query()
            ->whereHas('survey', fn ($q) => $q->whereNotNull('template_id'))
            ->with('survey.company')
            ->orderBy('id')
            ->chunkById(200, function ($responses) use (&$stats, &$detalheTotal, &$responseIdsComPendencia) {
                foreach ($responses as $response) {
                    $resultado = $this->snapshotService->backfillAssignments($response, dryRun: true);

                    $stats['criados']               += $resultado['criados'];
                    $stats['pulos_ja_existentes']    += $resultado['pulos_ja_existentes'];
                    $stats['pulos_sem_responsavel']  += $resultado['pulos_sem_responsavel'];

                    if ($resultado['criados'] > 0) {
                        $responseIdsComPendencia[] = $response->id;
                        $detalheTotal = array_merge($detalheTotal, $resultado['detalhe']);
                    }
                }
            });

        return [$responseIdsComPendencia, $stats, $detalheTotal];
    }

    /**
     * Passo 2: reprocessa (dry-run=false) só as respostas identificadas no
     * passo 1 e busta o cache do bônus de cada usuário/competência afetado.
     *
     * @param  array<int>  $responseIds
     */
    private function aplicar(array $responseIds): array
    {
        $stats = [
            'criados'                  => 0,
            'pulos_ja_existentes'      => 0,
            'pulos_sem_responsavel'    => 0,
            'caches_bustados'          => 0,
        ];

        $scoreService = app(DesempenhoScoreService::class);

        NpsResponse::query()
            ->whereIn('id', $responseIds)
            ->with('survey')
            ->chunkById(200, function ($responses) use (&$stats, $scoreService) {
                foreach ($responses as $response) {
                    $resultado = $this->snapshotService->backfillAssignments($response, dryRun: false);

                    $stats['criados']              += $resultado['criados'];
                    $stats['pulos_ja_existentes']   += $resultado['pulos_ja_existentes'];
                    $stats['pulos_sem_responsavel'] += $resultado['pulos_sem_responsavel'];

                    if ($resultado['criados'] > 0) {
                        $stats['caches_bustados'] += $this->bustarCacheDoBonus($response, $scoreService);
                    }
                }
            });

        return $stats;
    }

    /**
     * Busta o cache do bônus dos usuários que acabaram de ganhar assignment
     * nesta resposta — mesma régua de `NpsController::bustarCacheDoBonus()`
     * (Fase 96/105): competência = mês de `completed_at` MENOS 1 mês
     * (NPSWIN-03 — a competência fechada M lê o NPS coletado em M+1).
     * Duplicado aqui (em vez de chamar o método privado do controller) para
     * não editar `NpsController.php` neste debug — arquivo compartilhado com
     * outra sessão ativa no módulo NPS.
     */
    private function bustarCacheDoBonus(NpsResponse $response, DesempenhoScoreService $scoreService): int
    {
        $mesCompletado = $response->survey?->completed_at?->copy()->startOfMonth();
        if (! $mesCompletado) {
            return 0;
        }

        $mesCompetencia = $mesCompletado->copy()->subMonthNoOverflow()->startOfMonth();

        $userIds = NpsScoreAssignment::where('nps_response_id', $response->id)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            Cache::forget($scoreService->cacheKey($userId, $mesCompetencia));
        }

        return $userIds->count();
    }

    private function exibirDiff(array $detalhe): void
    {
        if (empty($detalhe)) {
            return;
        }

        $this->info('[NPS Backfill Consolidado] Diff (ANTES de gravar):');
        $this->table(
            ['nps_response_id', 'company_id', 'servico_id', 'role', 'user_id', 'average_score'],
            collect($detalhe)->map(fn ($d) => [
                $d['nps_response_id'],
                $d['company_id'],
                $d['servico_id'],
                $d['role'],
                $d['user_id'],
                number_format($d['average_score'], 2),
            ])->toArray()
        );
    }

    private function exibirStats(array $stats): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->toArray()
        );
    }
}
