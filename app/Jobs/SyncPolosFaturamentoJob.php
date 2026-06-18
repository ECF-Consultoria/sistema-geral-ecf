<?php

namespace App\Jobs;

use App\Models\MlbEmpresa;
use App\Services\AdmanService;
use App\Support\CustId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Aquece o cache de gross_billing da Adman para os polos ativos (M2–M4) de um
 * mês — alimenta o botão "Sincronizar" do /polos.
 *
 * Roda em background porque o throttle da Adman (~7s/cust_id) leva ~12 min para
 * ~85 polos. forceRefresh=true: "sincronizar os dados do dia" deve puxar o valor
 * atual da Adman, não reusar o cache do início do dia.
 *
 * Requer worker de fila ativo (`php artisan queue:work`) — na VPS o Supervisor
 * já roda; no localhost o dev precisa subir o worker para o job processar.
 */
class SyncPolosFaturamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Warm longo: ~7s/cust_id. 1800s cobre ~250 polos com folga.
    public int $timeout = 1800;

    /**
     * @param  string|null  $de   Início do mês 'YYYY-MM-01' (null = mês corrente)
     * @param  string|null  $ate  Fim do mês 'YYYY-MM-DD' (null = mês corrente)
     */
    public function __construct(
        private ?string $de = null,
        private ?string $ate = null,
    ) {}

    public function handle(AdmanService $adman): void
    {
        // Default: mês corrente (usado pelo warm agendado, que roda todo dia).
        // $this->de/$this->ate vêm do construtor (mês selecionado no botão Sincronizar);
        // sem eles o job aquecia sempre o mês corrente, ignorando o mês pedido.
        $de  = $this->de  ?? now()->startOfMonth()->toDateString();
        $ate = $this->ate ?? now()->endOfMonth()->toDateString();

        $custIds = MlbEmpresa::whereIn('fase', ['M2', 'M3', 'M4'])
            ->where('projeto', 'POLOS')
            ->pluck('cust_id')
            ->map(fn ($c) => CustId::normaliza((string) $c))
            ->filter(fn ($id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($custIds)) {
            Log::info('[Polos] Sync: nenhum polo ativo (M2–M4) para aquecer.');
            return;
        }

        Log::info('[Polos] Sync iniciado: ' . count($custIds) . " polos ({$de}..{$ate})");

        $ok = 0;
        $comValor = 0;
        foreach ($custIds as $i => $custId) {
            // Throttle Adman (~10 rpm): 7s entre chamadas, exceto a primeira.
            if ($i > 0) {
                usleep(7_000_000);
            }
            try {
                $val = $adman->fetchGrossBilling($custId, $de, $ate, 1440, forceRefresh: true);
                if ($val !== null) {
                    $ok++;
                    if ($val > 0) {
                        $comValor++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[Polos] Sync: falha cust={$custId}: " . $e->getMessage());
            }
        }

        Log::info("[Polos] Sync concluído: {$ok}/" . count($custIds) . " ok · {$comValor} com faturamento>0 ({$de}..{$ate})");
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Polos] SyncPolosFaturamentoJob falhou: ' . $e->getMessage());
    }
}
