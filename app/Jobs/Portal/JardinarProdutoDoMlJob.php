<?php

namespace App\Jobs\Portal;

use App\Models\Company;
use App\Models\EstruturaProdutoOrigemMl;
use App\Services\Portal\Estrutura\Produtos\MercadoLivre\JardinagemDaFicha;
use App\Services\Publicador\PreparoIaAgenda;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * A IA completa a ficha do produto trazido do Mercado Livre (28/10/2026). Na fila do preparo do
 * Publicador (`publicador-ia`), a que tem o worker da IA — nunca na `high`, que publica anúncio e
 * manda o código de acesso do Portal (learnings `publicador-ml.md` §22).
 */
class JardinarProdutoDoMlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Novas tentativas só quando a ficha da categoria não carregou (queda passageira do catálogo). */
    public int $tries = 3;

    public int $timeout = 300;

    private const ESPERA_S = 120;

    public function __construct(public int $companyId, public int $origemId)
    {
        $this->onQueue(PreparoIaAgenda::fila());
    }

    public function handle(JardinagemDaFicha $jardinagem): void
    {
        $empresa = Company::find($this->companyId);
        $origem = EstruturaProdutoOrigemMl::query()->where('company_id', $this->companyId)->find($this->origemId);
        if (! $empresa || ! $origem) {
            return;
        }

        $r = $jardinagem->jardinar($empresa, $origem);
        if (($r['ficha_indisponivel'] ?? false) && $this->attempts() < $this->tries) {
            $this->release(self::ESPERA_S);

            return;
        }
        Log::info("[Estrutura Produtos ML] jardinagem do produto {$origem->produto_id}: ".(isset($r['erro']) ? "erro — {$r['erro']}" : count($r['campos_ia'] ?? []).' campo(s) pela IA')
            ." — empresa {$empresa->id} ({$empresa->name})");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Estrutura Produtos ML] jardinagem da origem {$this->origemId} falhou — empresa {$this->companyId}: {$e->getMessage()}");
    }
}
