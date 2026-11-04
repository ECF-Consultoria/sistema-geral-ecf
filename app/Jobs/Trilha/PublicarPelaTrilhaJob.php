<?php

namespace App\Jobs\Trilha;

use App\Services\Trilha\PublicacaoNaTrilha;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * O "Aprovar e publicar" da revisão da trilha (4ª entrega, fatia 4d, desenho §19.13, D48/D49): confere o anúncio com o
 * Mercado Livre e, aprovado, o põe na FILA de publicação da loja. A regra mora em {@see PublicacaoNaTrilha::executar()},
 * que refaz a guarda da #459 antes de conferir e antes de agendar — o Job não confia no controller.
 *
 * Fila `high` (clique de pessoa, como o `ConferirRascunhoJob`): a conferência são várias chamadas ao ML (fotos e um
 * `/items/validate` por anúncio) e a requisição não pode esperar. Nunca publica: quem publica é a fila
 * (`AgendadorDaFila`), na passada dela.
 *
 * Sem nova tentativa (`tries 1`): a conferência já repete o que vale repetir (429, 5xx) e grava ERRO com mensagem;
 * repetir o Job inteiro só dobraria as chamadas. Quebrou ou estourou o tempo: a situação vira `erro` ({@see failed()}).
 * Dois cliques não enfileiram dois Jobs: o pedido devolve o `conferindo` recente sem enfileirar outro.
 */
class PublicarPelaTrilhaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $companyId,
        public string $item,
        public int $pubProdutoId,
        public int $userId,
        public int $revisao,
        public bool $ciente,
    ) {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(PublicacaoNaTrilha $publicacao): void
    {
        $resultado = $publicacao->executar($this->companyId, $this->item, $this->pubProdutoId, $this->userId, $this->revisao, $this->ciente);
        Log::info("[Trilha] Publicar: item {$this->item} (anúncio {$this->pubProdutoId}) da empresa {$this->companyId}: {$resultado}.");
    }

    public function failed(\Throwable $e): void
    {
        PublicacaoNaTrilha::quebrou($this->pubProdutoId);
        Log::error("[Trilha] Publicar: a conferência do item {$this->item} (anúncio {$this->pubProdutoId}) da empresa {$this->companyId} quebrou: ".$e->getMessage());
    }
}
