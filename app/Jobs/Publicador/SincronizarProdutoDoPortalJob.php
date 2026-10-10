<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Publicador\PreparoIaDoRascunhoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * O cliente salvou o produto no Portal e o produto chega ao Publicador LOGO (decisão do usuário, 10/10/2026: "o
 * Publicador ou vai instantâneo ou na hora de sincronizar"): segundos depois do save, um Sincronizar SÓ deste
 * produto, com as regras do botão "Sincronizar do Portal" e SEM IA — título, Modelo e descrição continuam
 * esperando o cliente parar de mexer (`PrepararProdutoNoPublicadorJob`, 2 minutos sem save). Quem decide é o
 * `PreparoIaDoRascunhoService::sincronizarAgora`.
 *
 * Apaga a marca de "já agendado" (`PreparoIaAgenda::chaveDoSincronizar`) ANTES de sincronizar: um save que chegar
 * durante a sincronização agenda outra, e nenhum save fica de fora. Fila `high` (10/10/2026): na `default` o
 * "logo" virava meia hora atrás das sincronizações do Adman e do Acervo (ver `PrepararProdutoNoPublicadorJob`).
 * Sem nova tentativa: o preparo da IA sincroniza de novo antes de gerar.
 */
class SincronizarProdutoDoPortalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $companyId, public int $estruturaProdutoId)
    {
        $this->onQueue('high');
    }

    public function handle(PreparoIaDoRascunhoService $servico): void
    {
        Cache::forget(PreparoIaAgenda::chaveDoSincronizar($this->estruturaProdutoId));
        $servico->sincronizarAgora($this->companyId, $this->estruturaProdutoId);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] Sincronizar ao salvar no Portal quebrou no produto {$this->estruturaProdutoId} (empresa {$this->companyId}): ".$e->getMessage());
    }
}
