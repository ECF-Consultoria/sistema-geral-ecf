<?php

namespace App\Console\Commands;

use App\Models\PoloAdsBonificacao;
use App\Services\AdmanService;
use App\Services\Polos\AdsCampanhasPolos;
use App\Services\Polos\BonificacoesAdsPolos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Lê na Adman o bônus de ADS de cada empresa dos polos (TKT-0021, DEV-47).
 *
 * Uma vez por dia, de manhã (08:15, routes/console.php): o saldo só cai com o gasto de ADS,
 * e às 08:15 o dado do dia já está pronto quando o time começa. Mesmo universo e mesmo ritmo
 * do `polos:ads-status` — uma chamada leve por empresa a ~10 rpm (~30 min para ~260) — e a
 * MESMA trava do sync de faturamento: as varreduras da Adman nunca rodam juntas, senão 429.
 *
 *   php artisan polos:ads-bonus
 */
class AtualizarBonusAdsPolos extends Command
{
    protected $signature = 'polos:ads-bonus';

    protected $description = 'Lê na Adman o bônus de ADS (saldo e vencimento) de cada empresa dos polos.';

    public function handle(AdmanService $adman): int
    {
        $lock = Cache::lock('polos-sync-faturamento:handle', 7200);
        if (! $lock->get()) {
            $this->line('[' . now()->format('Y-m-d H:i') . '] polos:ads-bonus — sync de faturamento rodando; pulado.');
            return self::SUCCESS;
        }

        try {
            $inicio  = now();
            $servico = new BonificacoesAdsPolos($adman);
            $custs   = array_keys(AdsCampanhasPolos::mapaIds());
            $pausa   = (int) config('services.adman.polos_pausa_ms', 7000) * 1000;

            foreach ($custs as $i => $cust) {
                if ($i > 0 && $pausa > 0) {
                    usleep($pausa);
                }
                $servico->atualizar((string) $cust);
            }

            // Resumo por RECONSULTA ao banco, não pelo retorno do loop.
            $lidos      = PoloAdsBonificacao::where('verificado_em', '>=', $inicio)->get(['cust_id', 'saldo']);
            $comBonus   = $lidos->where('saldo', '>', 0)->count();
            $semLeitura = collect($custs)->map(fn ($c) => (string) $c)->diff($lidos->pluck('cust_id'));

            $this->line('[' . now()->format('Y-m-d H:i') . "] polos:ads-bonus — lidas {$lidos->count()}/" . count($custs)
                . " ({$comBonus} com bônus) em " . (int) round($inicio->diffInSeconds(now()) / 60) . ' min.');
            if ($semLeitura->isNotEmpty()) {
                $this->line('  Sem leitura (Adman não enxerga a conta ou falhou): '
                    . $semLeitura->take(30)->implode(', ') . ($semLeitura->count() > 30 ? ' …' : ''));
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
