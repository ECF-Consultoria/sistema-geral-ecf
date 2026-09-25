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
 * Lê os anúncios da empresa no Mercado Livre para o Mapeamento Estrutural.
 *
 * Fora do request porque a API pode levar minutos numa conta grande (2.688
 * anúncios ≈ 135 chamadas de multiget). NÃO grava nada no módulo: deixa o
 * texto pronto no cache, e quem grava é a confirmação da prévia
 * (`AnunciosMercadoLivreService::aplicar()`). Uma tentativa só — reler é um
 * clique, e repetir sozinho esconderia um token inválido.
 */
class ImportarAnunciosMlEstruturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $companyId)
    {
    }

    public function handle(AnunciosMercadoLivreService $servico): void
    {
        $empresa = Company::find($this->companyId);

        if (! $empresa) {
            return;
        }

        $servico->ler($empresa);
        Log::info("[Estrutura] leitura dos anúncios do ML concluída — empresa {$empresa->id} ({$empresa->name})");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Estrutura] job de leitura do ML falhou — empresa {$this->companyId}: {$e->getMessage()}");
    }
}
