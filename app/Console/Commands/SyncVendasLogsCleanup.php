<?php

namespace App\Console\Commands;

use App\Models\MlbSyncVendasLog;
use Illuminate\Console\Command;

/**
 * Mantém a tabela mlb_sync_vendas_logs saudável:
 *  1. Encerra logs travados em "running" — quando o worker é reiniciado/morto
 *     no meio do SyncTodasVendasAdmanJob (tries=1), o log fica "running" pra
 *     sempre. Um sync legítimo leva ~30min; tudo acima de `--stale-hours`
 *     (default 1h) é considerado travado e vira `failed`.
 *  2. Retenção: remove logs já encerrados (completed/failed) com mais de
 *     `--keep-days` (default 30) — evita lista infinita no /dev/desenvolvimento.
 */
class SyncVendasLogsCleanup extends Command
{
    protected $signature = 'mlb:sync-vendas-logs-cleanup
                            {--stale-hours=1 : Marca como failed logs presos em running há mais de N horas}
                            {--keep-days=30 : Remove logs encerrados com mais de N dias}';

    protected $description = 'Encerra logs de sync de vendas travados em running e remove logs antigos (retenção)';

    public function handle(): int
    {
        $staleHours = max(1, (int) $this->option('stale-hours'));
        $keepDays   = max(1, (int) $this->option('keep-days'));

        // 1) Encerra travados: running parado há mais que o esperado → failed.
        $encerrados = MlbSyncVendasLog::where('status', MlbSyncVendasLog::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHours($staleHours))
            ->update([
                'status'      => MlbSyncVendasLog::STATUS_FAILED,
                'finished_at' => now(),
            ]);

        // 2) Retenção: remove logs já encerrados com mais de N dias.
        $removidos = MlbSyncVendasLog::whereIn('status', [
                MlbSyncVendasLog::STATUS_COMPLETED,
                MlbSyncVendasLog::STATUS_FAILED,
            ])
            ->where('started_at', '<', now()->subDays($keepDays))
            ->delete();

        $this->info("Logs travados encerrados (running > {$staleHours}h → failed): {$encerrados}");
        $this->info("Logs antigos removidos (> {$keepDays} dias): {$removidos}");

        return self::SUCCESS;
    }
}
