<?php

namespace App\Jobs\Publicador;

use App\Models\PubJardinagem;
use App\Services\Publicador\Jardinagem\PropostaDaJardinagemService;
use App\Services\Publicador\PreparoIaAgenda;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * O título da nova oferta do anúncio sem tração (Jardinagem, 23/10/2026). Na fila da IA do Publicador
 * (`publicador.preparo_ia.fila`) porque a IA leva de segundos a minutos; a tela mostra "gerando" até o
 * Job gravar a proposta na linha.
 *
 * Sem nova tentativa (o serviço da IA já troca de modelo quando o principal falha). Prazo abaixo do
 * `timeout` para terminar em ERRO com mensagem antes de o worker matar o processo.
 */
class GerarPropostaDaJardinagemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    private const PRAZO_S = 240;

    public function __construct(public int $jardinagemId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(PropostaDaJardinagemService $servico): void
    {
        $j = PubJardinagem::find($this->jardinagemId);
        if ($j === null || ! $j->aberta() || $j->diagnostico !== PubJardinagem::SEM_TRACAO) {
            return;
        }

        $servico->gerar($j, microtime(true) + self::PRAZO_S);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador Jardinagem] título novo da linha {$this->jardinagemId} quebrou: ".$e->getMessage());
        app(PropostaDaJardinagemService::class)->falhou($this->jardinagemId, 'A IA não terminou a tempo. Tente de novo.');
    }
}
