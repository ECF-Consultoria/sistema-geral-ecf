<?php

namespace App\Jobs\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A IA monta o campo Modelo ou um título a partir dos termos mais buscados da
 * categoria (`PalavrasChaveService`). Em Job porque a NVIDIA leva de segundos
 * a minutos; a tela acompanha o pedido pelo cache.
 *
 * Sem nova tentativa: o serviço da IA já troca de modelo quando o principal
 * falha. Prazo abaixo do `timeout` para o pedido terminar em ERRO com mensagem
 * antes de o worker matar o processo (a lição do "Anunciar por IA", 29/09).
 */
class GerarPalavrasChaveIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const PRAZO_S = 240;

    public function __construct(
        public int $rascunhoId,
        public string $alvo,
        public string $pedido,
        public array $escolhidos = [],
        // O título da tela no momento do pedido: no Modelo, o(s) ativo(s) (o serviço soma aos gravados);
        // no título de um tipo, o do OUTRO tipo, que o resultado não pode repetir (09/10/2026).
        public ?string $titulo = null,
    ) {
        // Clique de pessoa: fila `high`. No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(PalavrasChaveService $servico): void
    {
        $r = PubRascunho::find($this->rascunhoId);
        if (! $r) {
            $servico->falhou($this->rascunhoId, $this->alvo, $this->pedido, 'O rascunho não existe mais.');

            return;
        }

        try {
            $servico->executar($r, $this->alvo, $this->pedido, $this->escolhidos, microtime(true) + self::PRAZO_S, $this->titulo);
            Log::info("[Publicador] IA de palavras-chave ({$this->alvo}) pronta para o rascunho {$r->id}.");
        } catch (\Throwable $e) {
            // O erro já está no pedido, com a mensagem para a tela; o Job termina sem nova tentativa.
            Log::warning("[Publicador] IA de palavras-chave ({$this->alvo}) falhou no rascunho {$r->id}: {$e->getMessage()}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] IA de palavras-chave ({$this->alvo}) do rascunho {$this->rascunhoId} quebrou: ".$e->getMessage());
        app(PalavrasChaveService::class)->falhou($this->rascunhoId, $this->alvo, $this->pedido, 'A IA não terminou a tempo. Tente de novo.');
    }
}
