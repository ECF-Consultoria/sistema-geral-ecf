<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O kit AUTOMÁTICO ficou pronto (ou o juiz terminou): as imagens entram sozinhas no anúncio, na frente do grupo
 * (decisões do usuário de 20/10/2026). Quem decide é o `CriativosAutomaticosService::aplicar`, que confere tudo de
 * novo — este Job pode chegar cedo (juiz ainda rodando) e sair sem fazer nada; o próximo gatilho o põe de volta.
 *
 * Fila `creative`, como os outros Jobs das imagens automáticas. Único por kit: o kit pronto e o veredito do juiz
 * chegam quase juntos, e enquanto um está na fila os outros pedidos são descartados — ele roda com o estado mais
 * novo. A espera curta junta esses gatilhos. Sem nova tentativa: o `aplicar` nunca lança.
 */
class AplicarCriativosAutomaticosJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 23/10/2026 (P-07): `ShouldBeUnique` só soltava a reserva DEPOIS do `handle()` — o veredito do juiz que chegasse no
     * meio era descartado e o kit ficava pronto sem nunca entrar. Agora a reserva sai quando a execução começa, e duas
     * execuções do mesmo kit não andam juntas (a 2ª volta para a fila em 10 s). Tentativas só para essa espera.
     */
    public int $tries = 6;

    public int $maxExceptions = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /** Quanto o pedido fica reservado: cobre a espera e uma execução. */
    public int $uniqueFor = 600;

    public function __construct(public int $kitId)
    {
        $this->onQueue('creative');
        // 21/10/2026: era 20 s. Medido em produção, os vereditos do juiz chegam com 1–2 s de diferença, e o `aplicar`
        // confere de novo (juiz pendente ou reprovada ainda refeita → espera o próximo gatilho). 5 s bastam para juntar.
        $this->delay(now()->addSeconds(5));
    }

    public function uniqueId(): string
    {
        return 'aplicar-criativos-auto:'.$this->kitId;
    }

    public function handle(CriativosAutomaticosService $criativos): void
    {
        $trava = Cache::lock('aplicar-criativos-auto:execucao:'.$this->kitId, $this->timeout);
        if (! $trava->get()) {
            $this->release(10);

            return;
        }
        try {
            $resultado = $criativos->aplicar($this->kitId);
        } finally {
            $trava->release();
        }
        if (! in_array($resultado, ['nao_pronto', 'esperando_juiz', 'esperando_regeneracao', 'nao_automatico', 'desligado'], true)) {
            Log::info("[Creative] Imagens automáticas: aplicar o kit {$this->kitId} → {$resultado}.");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Creative] Imagens automáticas: o kit {$this->kitId} não entrou no anúncio: ".$e->getMessage());
    }
}
