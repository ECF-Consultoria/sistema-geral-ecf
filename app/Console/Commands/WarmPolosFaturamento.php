<?php

namespace App\Console\Commands;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Support\CustId;
use Illuminate\Console\Command;

/**
 * Atualiza o faturamento/ADS Adman de TODOS os polos M1–M4 do mês, de forma SÍNCRONA e
 * sem teto de tempo. É o que o agendamento diário roda (13:00 BRT, routes/console.php):
 * fora da fila, porque na `default` o job esperava horas e o teto de 25 min do worker
 * cortava metade do roster. Também serve à mão, no localhost ou na VPS.
 *
 *   php artisan polos:warm            → mês corrente
 *   php artisan polos:warm --mes=202606
 *
 * O resumo do fim vem de RECONSULTA ao banco (snapshots gravados desde o início), não do
 * que o job diz ter feito — e lista quem ficou sem atualizar, que quase sempre é cust
 * recusado pela Adman ("Customer not found" / "not mentored", learnings §8).
 */
class WarmPolosFaturamento extends Command
{
    protected $signature = 'polos:warm {--mes= : Mês YYYYMM (padrão: corrente)}';

    protected $description = 'Atualiza o faturamento Adman de todos os polos M1–M4 (síncrono, sem teto; ~50 min).';

    public function handle(): int
    {
        $mes = (string) $this->option('mes');
        $de  = null;
        $ate = null;
        if (preg_match('/^\d{6}$/', $mes)) {
            $de  = substr($mes, 0, 4) . '-' . substr($mes, 4, 2) . '-01';
            $ate = date('Y-m-t', strtotime($de));
        } else {
            $mes = now()->format('Ym');
        }

        $inicio = now();
        $this->line("[{$inicio->format('Y-m-d H:i')}] polos:warm {$mes} — iniciando (~11s por empresa).");

        // orcamento: null → varre o roster inteiro (só é seguro fora do worker de fila).
        SyncPolosFaturamentoJob::dispatchSync($de, $ate, null);

        $roster = MlbEmpresa::whereIn('fase', ['M1', 'M2', 'M3', 'M4'])
            ->where('projeto', 'POLOS')
            ->whereNull('arquivado_em')
            ->pluck('cust_id')
            ->map(fn ($c) => CustId::normaliza((string) $c))
            ->filter(fn ($c) => $c !== '')
            ->unique()
            ->values();

        $atualizados = PoloFaturamentoSnapshot::where('mes', $mes)
            ->where('synced_at', '>=', $inicio)
            ->pluck('cust_id')
            ->flip();

        $faltaram = $roster->reject(fn ($c) => $atualizados->has($c))->values();
        $minutos  = (int) round($inicio->diffInSeconds(now()) / 60);

        $this->line("[" . now()->format('Y-m-d H:i') . "] polos:warm {$mes} — atualizadas "
            . ($roster->count() - $faltaram->count()) . "/{$roster->count()} em {$minutos} min.");

        if ($faltaram->isNotEmpty()) {
            $this->line('  Sem atualizar (Adman recusou ou falhou): ' . $faltaram->take(30)->implode(', ')
                . ($faltaram->count() > 30 ? ' …' : ''));
        }

        return self::SUCCESS;
    }
}
