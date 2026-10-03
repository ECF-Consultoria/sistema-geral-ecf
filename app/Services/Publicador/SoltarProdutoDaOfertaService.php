<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\PubProduto;
use Illuminate\Support\Facades\Log;

/**
 * D27: a oferta some pela Lista SKUs do Portal; o produto do Publicador fica.
 *
 * O vínculo cai sozinho pela FK `pubprod_oferta_fk` (SET NULL). Este serviço só
 * garante que o produto não perca, em silêncio, o que herdava ao vivo da oferta
 * (D16): sku e nome, e o título planejado e o preço da Precificação que o
 * rascunho usava onde a equipe não tinha digitado. Depois de solto, o produto se
 * comporta como cadastrado no Publicador (D15).
 *
 * Só preenche o VAZIO. Não usa `tocar()` nem `salvar()`: o que iria ao ML é o
 * mesmo (`comEfetivos` já preenchia exatamente esses vazios), então revisão,
 * `updated_at` e status do rascunho não mudam e a conferência continua valendo.
 */
class SoltarProdutoDaOfertaService
{
    public function __construct(private DadosEfetivosService $efetivos) {}

    /** Chamado dentro da transação de `EstruturaOfertaService::excluir`, com a oferta e a Precificação ainda no banco. */
    public function antesDeExcluir(EstruturaOferta $oferta): void
    {
        $produto = PubProduto::where('oferta_id', $oferta->id)->first();
        if ($produto === null) {
            return;
        }

        // Congela o que a oferta exibia ao vivo; `origem` fica (é a origem histórica, a tela deriva o selo de oferta_id).
        $produto->update([
            'sku' => trim((string) $oferta->sku) !== '' ? $oferta->sku : $produto->sku,
            'nome' => trim((string) ($oferta->nome ?: $oferta->sku)) !== '' ? ($oferta->nome ?: $oferta->sku) : $produto->nome,
        ]);

        $r = $produto->rascunho;
        if ($r === null) {
            Log::info("[Publicador] Oferta {$oferta->id} ({$oferta->sku}) excluída no Portal: produto {$produto->id} solto do Portal, sem rascunho.");

            return;
        }

        $e = $this->efetivos->daOferta($oferta);

        $titulos = 0;
        foreach ($r->alvos()->get() as $alvo) {
            $efetivo = $e['titulos'][$alvo->listing_type_id] ?? null;
            if (trim((string) $alvo->titulo) === '' && $efetivo !== null) {
                $alvo->update(['titulo' => $efetivo]);
                $titulos++;
            }
        }

        $precos = 0;
        $alvos = $r->alvos()->get();
        foreach ($r->variantes()->get() as $variante) {
            foreach ($alvos as $alvo) {
                $efetivo = $e['precos'][$alvo->listing_type_id] ?? null;
                $digitado = $variante->precos()->where('alvo_id', $alvo->id)->whereNotNull('preco')->exists();
                if (! $digitado && $efetivo !== null) {
                    $variante->precos()->updateOrCreate(['alvo_id' => $alvo->id], ['preco' => round((float) $efetivo, 2)]);
                    $precos++;
                }
            }
        }

        Log::info("[Publicador] Oferta {$oferta->id} ({$oferta->sku}) excluída no Portal: produto {$produto->id} solto do Portal; {$titulos} título(s) e {$precos} preço(s) congelados no rascunho {$r->id}.");
    }
}
