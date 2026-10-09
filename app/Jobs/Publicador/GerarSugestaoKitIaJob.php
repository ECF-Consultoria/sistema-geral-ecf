<?php

namespace App\Jobs\Publicador;

use App\Models\PubRascunho;
use App\Services\Publicador\SugestaoKitIaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A IA reescreve o título ou a descrição do KIT a partir dos da Fase 1
 * (`SugestaoKitIaService`, §4 da ETAPA-3 da Fase 175). Em Job porque o
 * provedor leva de segundos a minutos; a tela acompanha o pedido pelo cache.
 *
 * Cópia estrutural do `GerarPalavrasChaveIaJob`, pelos MESMOS motivos:
 * sem nova tentativa (o serviço de IA já troca de modelo quando o principal
 * falha) e com prazo abaixo do `timeout`, para o pedido terminar em ERRO com
 * mensagem antes de o worker matar o processo (a lição do "Anunciar por IA",
 * 29/09).
 *
 * ⚠️ `$rascunhoBaseId` é o rascunho do produto BASE: no momento do painel o
 * rascunho do kit ainda não existe.
 */
class GerarSugestaoKitIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const PRAZO_S = 240;

    public function __construct(
        public int $rascunhoBaseId,
        public string $alvo,
        public int $quantidade,
        public string $pedido,
    ) {
        // Clique de pessoa: fila `high`, NUNCA a `default` (represada em
        // produção). No construtor porque `Queueable` já declara `$queue` e
        // redeclarar a propriedade é erro fatal de PHP.
        $this->onQueue('high');
    }

    public function handle(SugestaoKitIaService $servico): void
    {
        $base = PubRascunho::with('produto')->find($this->rascunhoBaseId);
        if (! $base) {
            $servico->falhou($this->rascunhoBaseId, $this->alvo, $this->quantidade, $this->pedido, 'O anúncio da Fase 1 não existe mais.');

            return;
        }

        try {
            $servico->executar($base, $this->alvo, $this->quantidade, $this->pedido, microtime(true) + self::PRAZO_S);
            Log::info("[Publicador] IA do kit ({$this->alvo}, {$this->quantidade} un.) pronta para o rascunho {$base->id}.");
        } catch (\Throwable $e) {
            // O erro já está no pedido, com a mensagem para a tela; o Job termina sem nova tentativa.
            Log::warning("[Publicador] IA do kit ({$this->alvo}, {$this->quantidade} un.) falhou no rascunho {$base->id}: {$e->getMessage()}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] IA do kit ({$this->alvo}) do rascunho {$this->rascunhoBaseId} quebrou: ".$e->getMessage());
        app(SugestaoKitIaService::class)->falhou(
            $this->rascunhoBaseId,
            $this->alvo,
            $this->quantidade,
            $this->pedido,
            'A IA não terminou a tempo. Tente de novo.',
        );
    }
}
