<?php

namespace App\Jobs\Publicador;

use App\Models\PubPublicacao;
use App\Services\Publicador\FreteDoPublicadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A terceira conferência de frete (11/10/2026): logo depois de publicar, lê no Mercado Livre o frete de cada
 * anúncio criado e compara com o que a Precificação usou (`FreteDoPublicadoService`).
 *
 * Fora do caminho da publicação, de propósito: o anúncio já está no ar, e uma leitura que falha não pode
 * desfazer nem atrasar nada. Só leitura; tenta de novo sozinho se o Mercado Livre não responder.
 */
class ConferirFreteDoPublicadoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $publicacaoId)
    {
        // Ninguém fica olhando a tela esperando por isto: fila comum. No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(FreteDoPublicadoService $fretes): void
    {
        $p = PubPublicacao::find($this->publicacaoId);
        if ($p === null) {
            return;
        }

        $resumo = $fretes->conferir($p);
        // Algum anúncio ficou sem resposta do Mercado Livre: a fila tenta de novo (o que já foi lido é regravado igual).
        if ($resumo['sem_resposta'] > 0 && $this->attempts() < $this->tries) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] frete do publicado: publicação {$this->publicacaoId} não foi conferida: {$e->getMessage()}");
    }
}
