<?php

namespace App\Jobs\Publicador;

use App\Models\PubPublicacao;
use App\Services\Publicador\PublicacaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Publica em fatias (D9): cada execução trabalha ~45 s e, se faltar, volta
 * para a fila. Um Job longo passaria do `retry_after` (90 s) da fila e seria
 * REENTREGUE — e a reentrega de um POST duplicaria o anúncio. Duas defesas:
 *
 * - a trava por publicação: duas execuções nunca trabalham juntas (a que não
 *   pega a trava volta para a fila e tenta depois);
 * - o item é gravado SENT antes do POST: o que uma execução morta deixou no
 *   meio vai para a reconciliação por SKU, nunca para um POST novo.
 *
 * Fila `high` (clique de pessoa).
 */
class PublicarRascunhoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A fatia (45 s) + um POST lento cabem com folga. */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public array $backoff = [30];

    public function __construct(public int $publicacaoId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    /** Pode voltar para a fila várias vezes (trava ocupada, item esperando a reconciliação) — mas não para sempre. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PublicacaoService $servico): void
    {
        $p = PubPublicacao::find($this->publicacaoId);
        if (! $p || $p->status !== PubPublicacao::RUNNING) {
            return;
        }

        $trava = Cache::lock("publicador:publicacao:{$p->id}", (int) config('publicador.trava_segundos', 600));
        if (! $trava->get()) {
            // Outra execução está nesta publicação (ou morreu segurando a trava): tenta depois.
            $this->release(20);

            return;
        }

        try {
            $terminou = $servico->executarFatia($p, (int) config('publicador.fatia_segundos', 45));
        } finally {
            $trava->release();
        }

        if (! $terminou) {
            // A próxima fatia é este mesmo Job de volta à fila (não um dispatch novo:
            // no driver `sync` um dispatch aqui dentro recursaria sem pausa).
            $this->release(15);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] publicação {$this->publicacaoId} quebrou: ".$e->getMessage());

        $p = PubPublicacao::find($this->publicacaoId);
        if ($p) {
            app(PublicacaoService::class)->abandonar($p, 'A publicação foi interrompida. O que já foi criado está guardado; tente publicar de novo para enviar o que faltou.');
        }
    }
}
