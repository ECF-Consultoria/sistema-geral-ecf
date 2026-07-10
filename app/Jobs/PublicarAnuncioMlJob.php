<?php

namespace App\Jobs;

use App\Models\MlAnuncioRascunho;
use App\Services\Mlb\Publicacao\MlPublicacaoService;
use Illuminate\Bus\Queueable;
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
 */
class PublicarAnuncioMlJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $rascunhoId) {}

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
