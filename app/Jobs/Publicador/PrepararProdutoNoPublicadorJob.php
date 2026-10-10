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
 * O cliente salvou o produto no Portal e a espera passou (09/10/2026): sincroniza SÓ este produto
 * com o Publicador (as regras do "Sincronizar do Portal") e, com a ficha completa, encadeia a IA
 * (título → Modelo → descrição). Quem decide tudo é o `PreparoIaDoRascunhoService`.
 *
 * Fila do preparo (`PreparoIaAgenda::fila()`, padrão `publicador-ia`, 10/10/2026): nem a `default` — que
 * fica dezenas de minutos atrás das sincronizações do Adman e do Acervo (308 + 220 jobs no teste de 10/10)
 * —, nem a `high` — onde a cadeia de 5–7 min por produto segurava a publicação e o código de acesso do
 * Portal numa importação grande (learnings publicador-ml §22). Sem nova tentativa; `timeout` igual ao dos
 * irmãos do Publicador, abaixo do `retry_after` de produção. Adiar (editor em uso) é um Job NOVO com
 * espera, nunca `release()` — com `tries = 1` o `release` estouraria as tentativas (learnings §6).
 */
class PrepararProdutoNoPublicadorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $companyId,
        public int $estruturaProdutoId,
        public string $marca,
        public int $adiamentos = 0,
    ) {
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(PreparoIaDoRascunhoService $servico): void
    {
        $servico->preparar($this->companyId, $this->estruturaProdutoId, $this->marca, $this->adiamentos);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Publicador] Preparo pela IA quebrou no produto {$this->estruturaProdutoId} do Portal (empresa {$this->companyId}): ".$e->getMessage());
    }
}
