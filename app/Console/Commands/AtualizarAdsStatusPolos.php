<?php

namespace App\Console\Commands;

use App\Models\PoloAdsStatus;
use App\Services\AdmanService;
use App\Services\Polos\AdsCampanhasPolos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Relê na Adman se o ADS de cada empresa dos polos está ligado (TKT-0003).
 *
 * O `polos:warm` das 13:00 já faz essa leitura junto com o faturamento; esta é a segunda do
 * dia (17:30, routes/console.php), para uma campanha pausada à tarde não esperar o dia
 * seguinte. Só uma chamada leve por empresa, mas no mesmo ritmo de ~10 rpm da Adman
 * (~30 min para ~260 empresas) — e com a MESMA trava do sync de faturamento: as duas
 * varreduras nunca rodam juntas, senão viram 429.
 *
 *   php artisan polos:ads-status
 */
class AtualizarAdsStatusPolos extends Command
{
    protected $signature = 'polos:ads-status';

    protected $description = 'Relê na Adman se o ADS (campanhas) de cada empresa dos polos está ligado ou desligado.';

    public function handle(AdmanService $adman): int
    {
        $lock = Cache::lock('polos-sync-faturamento:handle', 7200);
        if (! $lock->get()) {
            $this->line('[' . now()->format('Y-m-d H:i') . '] polos:ads-status — sync de faturamento rodando; pulado.');
            return self::SUCCESS;
        }

        try {
            $inicio    = now();
            $servico   = new AdsCampanhasPolos($adman);
            $mapa      = AdsCampanhasPolos::mapaIds();
            $pausa     = (int) config('services.adman.polos_pausa_ms', 7000) * 1000;

            $i = 0;
            foreach ($mapa as $cust => $ids) {
                if ($i++ > 0 && $pausa > 0) {
                    usleep($pausa);
                }
                $servico->atualizar((string) $cust, $ids);
            }

            // Resumo por RECONSULTA ao banco, não pelo retorno do loop.
            $lidos     = PoloAdsStatus::where('verificado_em', '>=', $inicio)->get(['cust_id', 'campanhas_ativas']);
            $desligado = $lidos->where('campanhas_ativas', 0)->count();
            $semLeitura = collect(array_keys($mapa))->map(fn ($c) => (string) $c)->diff($lidos->pluck('cust_id'));

            $this->line('[' . now()->format('Y-m-d H:i') . "] polos:ads-status — lidas {$lidos->count()}/" . count($mapa)
                . " ({$desligado} desligado) em " . (int) round($inicio->diffInSeconds(now()) / 60) . ' min.');
            if ($semLeitura->isNotEmpty()) {
                $this->line('  Sem leitura (Adman não enxerga a conta ou falhou — seguem manuais): '
                    . $semLeitura->take(30)->implode(', ') . ($semLeitura->count() > 30 ? ' …' : ''));
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
