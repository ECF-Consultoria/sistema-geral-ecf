<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Encadeado DEPOIS do `PlanejarKitCriativosJob` das imagens automáticas (10/10/2026): o kit planejado já despacha
 * a geração das imagens — "planejar e gerar na mesma passada", porque as referências efêmeras somem em 48 h. A
 * chave é conferida de novo (`CriativosAutomaticosService::gerar`): desligar no meio para a geração.
 *
 * Fila `creative`. Sem nova tentativa: o despachante só enfileira, e uma 2ª passada repagaria imagem.
 */
class GerarCriativosAutomaticosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $kitId)
    {
        $this->onQueue('creative');
    }

    public function handle(CriativosAutomaticosService $criativos): void
    {
        $resultado = $criativos->gerar($this->kitId);
        Log::info("[Creative] Imagens automáticas: kit {$this->kitId} — {$resultado}.");
    }
}
