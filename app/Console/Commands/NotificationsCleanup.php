<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;

/**
 * Cleanup diário de notificações lidas com mais de 30 dias (POLL-04 da Phase 12).
 *
 * Roda às 04:00 (antes do calculate-goal-results das 06:00 e do sync Adman),
 * em janela exclusiva via `withoutOverlapping()`. Delete idempotente —
 * notificações unread (`read_at IS NULL`) e lidas dentro da janela de 30 dias
 * permanecem intocadas. Alinhado com a regra da aba "Todas" do
 * `NotificacaoController::index` (janela rolling de 30 dias).
 */
class NotificationsCleanup extends Command
{
    /** @var string */
    protected $signature = 'notifications:cleanup';

    /** @var string */
    protected $description = 'Remove notificações lidas com mais de 30 dias (POLL-04).';

    public function handle(): int
    {
        // Cutoff fixo no início da execução pra evitar drift entre query e log.
        $cutoff = now()->subDays(30);

        $count = DatabaseNotification::whereNotNull('read_at')
            ->where('read_at', '<', $cutoff)
            ->delete();

        $this->info("Removidas {$count} notificações lidas com mais de 30 dias.");
        Log::info("[NotificationsCleanup] removed={$count}");

        return self::SUCCESS;
    }
}
