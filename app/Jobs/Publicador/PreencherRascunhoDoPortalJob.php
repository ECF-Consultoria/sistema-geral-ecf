<?php

namespace App\Jobs\Publicador;

use App\Models\PubProduto;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\ResumoDoSincronizar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Preenche o rascunho de UM produto com a ficha do Portal (Fase 172-12). Em Job porque um produto com
 * muitas cores/fotos e schema a ler estoura o tempo de um request (Pitfall 8). Nunca publica e nunca
 * escreve no ML: só o `PortalParaRascunhoService`, que trabalha no banco e no disco local.
 *
 * Sem nova tentativa: o serviço é idempotente, mas a tela já mostra o aviso e a pessoa sincroniza de novo.
 * `timeout` igual ao dos Jobs irmãos do Publicador (abaixo do retry_after de produção, 2000 s).
 */
class PreencherRascunhoDoPortalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public int $produtoId, public string $pedido)
    {
        // Clique de pessoa: fila `high`. No construtor porque `Queueable` já declara `$queue`.
        $this->onQueue('high');
    }

    public function handle(PortalParaRascunhoService $servico, ResumoDoSincronizar $resumo): void
    {
        $produto = PubProduto::find($this->produtoId);
        if (! $produto) {
            $resumo->registrar($this->pedido, $this->produtoId, self::vazio($this->produtoId, 'Um produto sumiu antes de ser preenchido.'));

            return;
        }

        $r = $servico->preencher($produto);
        $resumo->registrar($this->pedido, $this->produtoId, $r);
        Log::info("[Publicador] Produto {$produto->id} ({$produto->nome}) preenchido pelo Portal: {$r['campos_preenchidos']} campo(s), {$r['fotos_trazidas']} foto(s).");
    }

    public function failed(\Throwable $e): void
    {
        $nome = PubProduto::query()->whereKey($this->produtoId)->value('nome') ?? "#{$this->produtoId}";
        Log::error("[Publicador] Preencher pelo Portal quebrou no produto {$this->produtoId} ({$nome}): ".$e->getMessage());
        app(ResumoDoSincronizar::class)->registrar($this->pedido, $this->produtoId, self::vazio($this->produtoId, "Não foi possível preencher {$nome}: tente sincronizar de novo."));
    }

    /** Resumo de um produto que não foi preenchido, só com o aviso. */
    private static function vazio(int $produtoId, string $aviso): array
    {
        return ['produto_id' => $produtoId, 'rascunho_id' => null, 'variantes' => 0, 'campos_preenchidos' => 0, 'campos_mantidos' => 0,
            'fotos_trazidas' => 0, 'fotos_nao_trazidas' => [], 'avisos' => [$aviso]];
    }
}
