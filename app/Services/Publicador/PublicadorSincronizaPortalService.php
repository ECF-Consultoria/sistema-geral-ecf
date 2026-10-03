<?php

namespace App\Services\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "Sincronizar do Portal" (D16): traz para o Publicador as ofertas da Lista SKUs da
 * Company que ainda não viraram produto. SÓ ACRESCENTA — nunca apaga nem altera um
 * produto existente. O produto nasce na MlbEmpresa onde se clicou (Q8).
 */
class PublicadorSincronizaPortalService
{
    /**
     * @return array{criados: int, ids: list<int>}
     */
    public function sincronizar(?MlbEmpresa $empresa, Company $company): array
    {
        $ofertas = EstruturaOferta::query()->where('company_id', $company->id)
            ->orderBy('id')->get(['id', 'company_id', 'sku', 'nome']);

        $jaTem = $ofertas->isEmpty() ? collect() : PubProduto::query()
            ->whereIn('oferta_id', $ofertas->pluck('id'))->pluck('oferta_id')->flip();

        $ids = [];
        foreach ($ofertas as $oferta) {
            if ($jaTem->has($oferta->id)) {
                continue;
            }

            try {
                $produto = PubProduto::create([
                    'oferta_id' => $oferta->id,
                    'company_id' => $company->id,
                    'mlb_empresa_id' => $empresa?->id,
                    'sku' => $oferta->sku,
                    'nome' => $oferta->nome ?: $oferta->sku,
                    'origem' => PubProduto::ORIGEM_PORTAL,
                ]);
                $ids[] = $produto->id;
            } catch (QueryException $e) {
                // Corrida no unique pubprod_oferta_uq: outra requisição criou antes — pula.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        Cache::forever('publicador.portal_sincronizado_em.company-'.$company->id, now()->toIso8601String());
        $criados = count($ids);
        Log::info("[Publicador] Sincronizar do Portal: empresa {$company->id} ({$company->name}) — {$criados} produto(s) novo(s).");

        return ['criados' => $criados, 'ids' => $ids];
    }
}
