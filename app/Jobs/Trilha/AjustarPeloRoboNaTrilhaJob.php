<?php

namespace App\Jobs\Trilha;

use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\AjusteDoRoboNaTrilha;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * O "Pedir ajuste ao robô" da revisão da trilha (4ª entrega, fatia 4b, desenho §19.14, D51): refaz o título (com o
 * Modelo) e/ou a descrição do anúncio com os geradores do preparo e grava pelo `executarEtapa` do Publicador. A regra mora
 * em {@see AjusteDoRoboNaTrilha::executar()}.
 *
 * Fila da IA (`PreparoIaAgenda::fila()`, `publicador-ia`), NUNCA a `high`: a `high` de produção tem UM worker, que publica
 * anúncio e manda o código de acesso do cliente, e cada ajuste faz até três chamadas pagas à IA (learnings da trilha §36,
 * a mesma razão da importação do ML).
 *
 * Dois modos, a mesma classe: `refazer` (o pedido: gera e grava) e `acompanhar` (zero IA: a parte que o Publicador adiou
 * — editor em uso, anúncio na fila — tem o valor guardado no Job adiado DELE; aqui só se lê o desfecho, a cada
 * `adiar_min` + 1 minuto, até {@see AjusteDoRoboNaTrilha::MAX_ACOMPANHAMENTOS}).
 *
 * Sem nova tentativa (`tries 1`): repetir o Job inteiro gastaria a IA de novo; quebrou ou estourou o tempo, a parte que
 * ainda andava vira `erro` ({@see failed()}) e a pessoa pede de novo. `timeout` abaixo do worker da IA (600 s); as
 * chamadas dividem um orçamento de 480 s.
 */
class AjustarPeloRoboNaTrilhaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const REFAZER = 'refazer';

    public const ACOMPANHAR = 'acompanhar';

    public int $tries = 1;

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    /**
     * @param  list<string>  $partes  `titulo` e/ou `descricao` — nunca texto do pedido
     */
    public function __construct(
        public int $companyId,
        public string $item,
        public int $pubProdutoId,
        public string $pedido,
        public array $partes,
        public string $modo = self::REFAZER,
        public int $vez = 0,
    ) {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(AjusteDoRoboNaTrilha $ajuste): void
    {
        $resultado = $this->modo === self::ACOMPANHAR
            ? $ajuste->acompanhar($this->companyId, $this->item, $this->pubProdutoId, $this->pedido, $this->partes, $this->vez)
            : $ajuste->executar($this->companyId, $this->item, $this->pubProdutoId, $this->pedido, $this->partes);
        Log::info("[Trilha] Ajuste do robô ({$this->modo}): item {$this->item} (anúncio {$this->pubProdutoId}) da empresa {$this->companyId}: {$resultado}.");
    }

    public function failed(\Throwable $e): void
    {
        AjusteDoRoboNaTrilha::quebrou($this->pubProdutoId, $this->pedido);
        Log::error("[Trilha] Ajuste do robô ({$this->modo}) do item {$this->item} (anúncio {$this->pubProdutoId}) da empresa {$this->companyId} quebrou: ".$e->getMessage());
    }
}
