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
 * Uma fatia da leitura dos anúncios do Mercado Livre para o Mapeamento
 * Estrutural (`AnunciosMercadoLivreService::passo()`).
 *
 * Cada Job trabalha ~45 s, guarda o progresso e despacha o próximo com a
 * mesma `rodada` — um lote de 500 SKUs não prende um worker da fila `high`
 * por minutos seguidos. NÃO grava nada no módulo: quem grava é a confirmação
 * da prévia (`AnunciosMercadoLivreService::aplicar()`). Uma tentativa só —
 * reler é um clique, e repetir sozinho esconderia um token inválido.
 */
class ImportarAnunciosMlEstruturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 85;

    public function __construct(public int $companyId, public string $rodada)
    {
        // Fila `high`, não `default`. Medido em produção em 25/09: a `default`
        // tinha 157 jobs do sync do acervo na frente, e a leitura que o cliente
        // acabou de pedir não começou nunca — a tela ficou em "0 lidos" até
        // declarar "parou no meio". Mesmo precedente do `ResolveOnboardingPassoJob`.
        $this->onQueue('high');
    }

    public function handle(AnunciosMercadoLivreService $servico): void
    {
        $empresa = Company::find($this->companyId);

        if (! $empresa) {
            return;
        }

        if (! $servico->passo($empresa, $this->rodada)) {
            self::dispatch($this->companyId, $this->rodada);

            return;
        }

        Log::info("[Estrutura] leitura dos anúncios do ML concluída — empresa {$empresa->id} ({$empresa->name})");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Estrutura] job de leitura do ML falhou — empresa {$this->companyId}: {$e->getMessage()}");
    }
}
