<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\Fila\ConferenciaEmLoteService;
use App\Services\Publicador\Fila\ResumoRapidoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Um produto do "Conferir selecionados" da publicação em lote (10/10/2026): abre o rascunho, aplica a regra do
 * frete grátis obrigatório do editor e confere com o Mercado Livre (`ConferenciaEmLoteService::executar`).
 *
 * Fila `high` (clique de pessoa), espaçado pelo `delay` de quem enfileira. Um por produto (`ShouldBeUnique`):
 * dois cliques em "Conferir selecionados" não dobram as chamadas ao ML; a trava vale mais que o maior atraso de
 * um lote de 100 (100 × 10 s). Sem nova tentativa: a conferência já repete o que vale repetir (429, 5xx) e grava
 * ERRO com mensagem — repetir o Job inteiro só dobraria as chamadas.
 */
class ConferirEmLoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public int $produtoId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return 'lote:'.$this->produtoId;
    }

    public function handle(ConferenciaEmLoteService $lote): void
    {
        $resultado = $lote->executar($this->produtoId);
        Log::info("[Publicador] Lote: conferência do produto {$this->produtoId}: {$resultado}.");
    }

    public function failed(\Throwable $e): void
    {
        // A linha não pode ficar em "conferindo…" para sempre.
        Cache::forget(ResumoRapidoService::chaveConferindo($this->produtoId));
        Log::error("[Publicador] Lote: a conferência do produto {$this->produtoId} quebrou: ".$e->getMessage());
    }
}
