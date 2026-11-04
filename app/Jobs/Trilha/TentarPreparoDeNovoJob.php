<?php

namespace App\Jobs\Trilha;

use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\PreparoNaTrilha;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * O [Tentar de novo] da "Preparação automática" da trilha (retorno do 1º teste, R4, 04/11/2026): pede ao PRÓPRIO preparo
 * do Publicador (`avaliarIa`) que refaça só as etapas que pararam. A regra mora em {@see PreparoNaTrilha::executar()}.
 *
 * Fila da IA (`PreparoIaAgenda::fila()`, `publicador-ia`) — a mesma das cadeias que o `avaliarIa` despacha —, nunca a
 * `high` (um worker só em produção, que publica). O Job em si não chama a IA: só decide e encadeia; quem chama são os
 * `GerarPreparoIaJob` de sempre. Sem nova tentativa (`tries 1`): o pedido repetido pela fila só acharia as etapas já
 * `rodando` (o preparo não as refaz), mas não há razão para arriscar. Quebrou: a marca "pedido" sai ({@see failed()}) e a
 * linha volta a "parou".
 */
class TentarPreparoDeNovoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $companyId,
        public string $item,
        public int $pubProdutoId,
    ) {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(PreparoNaTrilha $preparo): void
    {
        $preparo->executar($this->companyId, $this->item, $this->pubProdutoId);
    }

    public function failed(\Throwable $e): void
    {
        PreparoNaTrilha::esquecer($this->pubProdutoId);
        Log::error("[Trilha] Tentar de novo: o preparo do item {$this->item} (anúncio {$this->pubProdutoId}) da empresa {$this->companyId} quebrou: ".$e->getMessage());
    }
}
