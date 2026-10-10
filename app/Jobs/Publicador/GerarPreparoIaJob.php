<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * UMA etapa do preparo pela IA (`titulo`, `modelo` ou `descricao`), encadeada por `Bus::chain` na
 * ordem título → Modelo → descrição (09/10/2026). A falha da IA numa etapa NÃO interrompe a cadeia:
 * o serviço registra o erro e devolve normalmente, e a próxima etapa roda (a descrição não depende
 * do título; o Modelo, sem título nenhum, é pulado).
 *
 * Só uma quebra fora da IA (banco, timeout do worker) para a cadeia; aí o `failed()` registra e,
 * se a descrição ainda viria, a põe na fila sozinha — a falha do título não pode impedir a descrição.
 *
 * `valorPronto` = valor já gerado de uma escrita adiada (editor em uso): ao acordar, só grava,
 * sem chamar a IA de novo.
 *
 * Fila do preparo (`PreparoIaAgenda::fila()`, padrão `publicador-ia`, 10/10/2026), declarada AQUI e não só
 * no `Bus::chain`: o elo que declara fila fica nela (`$next->queue ?: $this->chainQueue`). Na `default` a
 * cadeia esperava o Adman e o Acervo; na `high` ela segurava a publicação e o código de acesso do Portal
 * (ver `PrepararProdutoNoPublicadorJob`).
 */
class GerarPreparoIaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $restantes  as etapas que vêm depois desta na cadeia
     */
    public function __construct(
        public int $rascunhoId,
        public string $etapa,
        public string $hash,
        public ?string $valorPronto = null,
        public int $adiamentos = 0,
        public array $restantes = [],
    ) {
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(PreparoIaDoRascunhoService $servico): void
    {
        $servico->executarEtapa($this->rascunhoId, $this->etapa, $this->hash, $this->valorPronto, $this->adiamentos);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] Preparo pela IA ({$this->etapa}) quebrou no rascunho {$this->rascunhoId}: ".$e->getMessage());
        $servico = app(PreparoIaDoRascunhoService::class);
        $servico->marcarEtapa($this->rascunhoId, $this->hash, $this->etapa, PreparoIaDoRascunhoService::ERRO);
        if ($this->etapa !== PreparoIaDoRascunhoService::DESCRICAO && in_array(PreparoIaDoRascunhoService::DESCRICAO, $this->restantes, true)) {
            self::dispatch($this->rascunhoId, PreparoIaDoRascunhoService::DESCRICAO, $this->hash);
        }
    }
}
