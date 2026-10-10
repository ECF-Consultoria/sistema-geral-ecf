<?php

namespace App\Jobs\Publicador;

use App\Services\Publicador\Alavancas\PromocaoAutomaticaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cria UM ciclo da promoção automática pós-publicação (10/10/2026) — `PromocaoAutomaticaService::executar`.
 * A escrita sai pelo `EscritorAlavancas` (o único caminho; teste de fonte), então nunca se repete às
 * cegas: 5xx/rede viram "confira no Seller Center", nunca um segundo POST.
 *
 * `tries = 1`: anúncio ainda não ativo NÃO é `release()` (contaria como tentativa e falharia) — o
 * serviço despacha um Job NOVO com atraso crescente, até `publicador.promocao_automatica.tentativas_max`
 * (mesmo padrão do preparo pela IA, learnings §16). Fila `high`, como o lote das Alavancas.
 * `timeout` muito abaixo do `retry_after` de produção (2000 s): o envio tem no máximo 3 tentativas de 423.
 */
class CriarPromocaoAutomaticaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $promocaoId)
    {
        // No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(PromocaoAutomaticaService $servico): void
    {
        $servico->executar($this->promocaoId);
    }

    public function failed(\Throwable $e): void
    {
        app(PromocaoAutomaticaService::class)->interrompida($this->promocaoId, $e->getMessage());
    }
}
