<?php

namespace App\Jobs;

use App\Models\MlAnuncioRascunho;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Publica um rascunho de anúncio no Mercado Livre de forma assíncrona.
 *
 * Roda na fila para (a) não travar a tela e (b) permitir throttle/retry em massa.
 * O MlPublicacaoService já é idempotente por status — não republica anúncio pronto.
 *
 * BULK-03: ShouldBeUnique garante que duplo-clique ou re-enfileiramento do mesmo
 * rascunho não cria dois jobs simultâneos. O MlPublicacaoService é a 2ª camada
 * anti-duplicata (lock por STATUS_PUBLICANDO), mas o unique lock evita a corrida.
 */
class PublicarAnuncioMlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $rascunhoId) {}

    /**
     * Chave de unicidade por rascunho — dois dispatches do mesmo rascunhoId
     * não criam dois jobs simultâneos (BULK-03).
     */
    public function uniqueId(): string
    {
        return (string) $this->rascunhoId;
    }

    /**
     * TTL do lock de unicidade em segundos.
     *
     * 300s (5 min) > timeout(120s) + backoff máximo (300s no 3º retry) com folga.
     * Sem este TTL, um crash do worker deixaria o lock órfão e o rascunho nunca
     * seria reenfileirado (ShouldBeUnique bloquearia indefinidamente).
     */
    public function uniqueFor(): int
    {
        return 300;
    }

    /** Espera crescente entre tentativas (rate limit do ML). */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(MlPublicacaoService $service): void
    {
        $rascunho = MlAnuncioRascunho::find($this->rascunhoId);

        if (! $rascunho) {
            Log::warning("[MLB Publicacao] Rascunho {$this->rascunhoId} não encontrado — job ignorado.");

            return;
        }

        $service->publicar($rascunho);
    }

    /** Falha definitiva: marca o rascunho como erro para a UI mostrar. */
    public function failed(\Throwable $e): void
    {
        MlAnuncioRascunho::find($this->rascunhoId)?->update([
            'status'            => MlAnuncioRascunho::STATUS_ERRO,
            'validation_errors' => [['code' => 'job_failed', 'campo' => null, 'mensagem' => $e->getMessage()]],
        ]);

        Log::error("[MLB Publicacao] Job de publicação do rascunho {$this->rascunhoId} falhou definitivamente: {$e->getMessage()}");
    }
}
