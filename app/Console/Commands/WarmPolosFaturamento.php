<?php

namespace App\Console\Commands;

use App\Jobs\SyncPolosFaturamentoJob;
use Illuminate\Console\Command;

/**
 * Aquece o cache de faturamento Adman dos polos ativos, de forma SÍNCRONA
 * (sem precisar de queue worker). Útil no localhost: rode quando o /polos
 * aparecer zerado (o cache da Adman é por dia e expira ao virar a data).
 *
 *   php artisan polos:warm            → mês corrente
 *   php artisan polos:warm --mes=202606
 */
class WarmPolosFaturamento extends Command
{
    protected $signature = 'polos:warm {--mes= : Mês YYYYMM (padrão: corrente)}';

    protected $description = 'Aquece o cache de faturamento Adman dos polos ativos (síncrono, ~12 min).';

    public function handle(): int
    {
        $mes = (string) $this->option('mes');
        $de  = null;
        $ate = null;
        if (preg_match('/^\d{6}$/', $mes)) {
            $de  = substr($mes, 0, 4) . '-' . substr($mes, 4, 2) . '-01';
            $ate = date('Y-m-t', strtotime($de));
        }

        $this->info('Aquecendo faturamento Adman dos polos (~7s por empresa). Pode levar ~12 min…');
        SyncPolosFaturamentoJob::dispatchSync($de, $ate);
        $this->info('Concluído. Recarregue o /polos.');

        return self::SUCCESS;
    }
}
