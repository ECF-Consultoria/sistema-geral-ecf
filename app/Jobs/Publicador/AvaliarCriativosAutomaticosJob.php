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
 * O último elo do preparo pela IA (10/10/2026): com o texto pronto, avalia se o produto recebe as imagens por IA
 * automáticas (`CriativosAutomaticosService::avaliar` — DESLIGADO por padrão) e, passando, planeja o kit e encadeia
 * a geração.
 *
 * Fila `creative` (a do Creative Engine; nunca `high` nem `default`). Na cadeia de texto (fila `default`) ele
 * mantém a própria fila: o `chainQueue` só vale para quem não tem uma. Sem nova tentativa: o serviço não lança, e
 * repetir poderia gastar cota duas vezes.
 */
class AvaliarCriativosAutomaticosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $rascunhoId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('creative');
    }

    public function handle(CriativosAutomaticosService $criativos): void
    {
        $resultado = $criativos->avaliar($this->rascunhoId);
        if ($resultado !== 'desligado') {
            Log::info("[Publicador] Imagens automáticas do rascunho {$this->rascunhoId}: {$resultado}.");
        }
    }
}
