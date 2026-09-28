<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Portal\Estrutura\AnunciosMercadoLivreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Lê os pedidos pagos da loja nos últimos 7 dias e guarda as unidades por
 * anúncio (`AnunciosMercadoLivreService::lerVendas7d()`), para a estação do
 * produto não esperar 14 s pela parte lenta das métricas.
 *
 * Fila `high`: é disparado ao abrir a página, com alguém olhando — na
 * `default` de produção ficaria atrás do sync do acervo. Uma tentativa: se
 * falhar, a próxima abertura da página dispara de novo (a trava expira em
 * 5 min).
 */
class AquecerPedidosMlEstruturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $companyId)
    {
        $this->onQueue('high');
    }

    public function handle(AnunciosMercadoLivreService $servico): void
    {
        $empresa = Company::find($this->companyId);

        if (! $empresa) {
            return;
        }

        try {
            $vendas = $servico->lerVendas7d($empresa);
            Log::info("[Estrutura] pedidos de 7 dias lidos — empresa {$empresa->id} ({$empresa->name}): ".count($vendas).' anúncios com venda');
        } catch (\Throwable $e) {
            Log::warning("[Estrutura] pedidos de 7 dias falharam — empresa {$empresa->id} ({$empresa->name}): {$e->getMessage()}");
        }
    }
}
