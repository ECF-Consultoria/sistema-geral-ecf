<?php

namespace App\Jobs\Publicador;

use App\Models\PubProduto;
use App\Services\Publicador\CombosDoPlanejamentoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A Fase 1 de um produto do Portal está no ar: os Combos ACEITOS no Planejamento para ele viram fase (o Kit N da
 * família) sozinhos — decisão do usuário de 20/10/2026. Sugestão não aceita nunca entra. Quem decide o que nasce é o
 * `CombosDoPlanejamentoService`, que confere tudo de novo aqui (o produto pode ter mudado desde o agendamento).
 *
 * Fila `high`, como o resto do fluxo Portal → Publicador. Sem nova tentativa: o próximo Sincronizar agenda de
 * novo, e o kit que já nasceu nunca é duplicado (KIT-04).
 */
class CriarCombosDoPlanejamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $produtoId)
    {
        $this->onQueue('high');
    }

    public function handle(CombosDoPlanejamentoService $combos): void
    {
        $base = PubProduto::find($this->produtoId);
        if ($base !== null) {
            $combos->criarOsQueFaltam($base);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] Combos do Planejamento do produto {$this->produtoId} não viraram kit: ".$e->getMessage());
    }
}
