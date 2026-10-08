<?php

namespace App\Jobs\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\DescricaoIaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A IA (MAG T8) escreve a descrição do anúncio a partir da ficha e da descrição do cliente
 * (`DescricaoIaService`). Em Job porque a NVIDIA leva de segundos a minutos; a tela acompanha o
 * pedido pelo cache. O Job nunca grava no rascunho.
 *
 * Sem nova tentativa; prazo abaixo do `timeout` para o pedido terminar em ERRO com mensagem
 * antes de o worker matar o processo.
 */
class GerarDescricaoIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const PRAZO_S = 240;

    public function __construct(
        public int $rascunhoId,
        public string $pedido,
    ) {
        // Clique de pessoa: fila `high`. No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(DescricaoIaService $servico): void
    {
        $r = PubRascunho::find($this->rascunhoId);
        if (! $r) {
            $servico->falhou($this->rascunhoId, $this->pedido, 'O rascunho não existe mais.');

            return;
        }

        try {
            $servico->executar($r, $this->pedido, microtime(true) + self::PRAZO_S);
            Log::info("[Publicador] IA de descrição pronta para o rascunho {$r->id}.");
        } catch (\Throwable $e) {
            // O erro já está no pedido, com a mensagem para a tela; o Job termina sem nova tentativa.
            Log::warning("[Publicador] IA de descrição falhou no rascunho {$r->id}: {$e->getMessage()}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] IA de descrição do rascunho {$this->rascunhoId} quebrou: ".$e->getMessage());
        app(DescricaoIaService::class)->falhou($this->rascunhoId, $this->pedido, 'A IA não terminou a tempo. Tente de novo.');
    }
}
