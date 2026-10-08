<?php

namespace App\Services\Publicador;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
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
 *
 * Fase 172 (D-06): o produto AGRUPADO fica ancorado na oferta da 1ª cor. Excluir essa cor não
 * solta o grupo: ele é reancorado na próxima oferta Simples livre do mesmo produto do Portal, e só
 * a variante da cor excluída congela o preço que exibia. Sem outra cor livre, cada variante congela
 * o SEU preço (o da sua oferta, como `precos_por_variante`), nunca o da cor âncora em todas.
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

        if ($produto->estrutura_produto_id !== null) {
            $this->soltarCorDoGrupo($produto, $oferta);

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
        $titulos = $this->congelarTitulos($r, $e['titulos']);
        $precos = 0;
        foreach ($r->variantes()->get() as $variante) {
            $precos += $this->congelarPrecos($r, $variante, $e['precos']);
        }

        Log::info("[Publicador] Oferta {$oferta->id} ({$oferta->sku}) excluída no Portal: produto {$produto->id} solto do Portal; {$titulos} título(s) e {$precos} preço(s) congelados no rascunho {$r->id}.");
    }

    /**
     * A cor âncora de um grupo saiu do Portal. Com outra cor livre, o grupo passa a ancorar nela e
     * continua ligado ao Portal (título e preços ao vivo); só a variante da cor excluída congela o
     * seu preço. Sem outra cor livre, o grupo fica solto e cada variante congela o preço da SUA cor.
     * `sku`/`nome` do grupo são os do produto do Portal: ficam como estão.
     */
    private function soltarCorDoGrupo(PubProduto $produto, EstruturaOferta $oferta): void
    {
        $r = $produto->rascunho;
        // Lido ANTES de reancorar: os preços que a tela mostrava, por SKU de cor, e os da âncora.
        $e = $r !== null ? $this->efetivos->daProduto($produto) : null;
        $skuExcluido = EstruturaOferta::normalizarSku($oferta->sku);

        $proxima = EstruturaOferta::query()
            ->where('company_id', $produto->company_id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->where('id', '!=', $oferta->id)
            ->whereIn('variacao_id', EstruturaProdutoVariacao::query()
                ->where('company_id', $produto->company_id)
                ->where('produto_id', $produto->estrutura_produto_id)
                ->select('id'))
            ->whereNotIn('id', PubProduto::query()->whereNotNull('oferta_id')->select('oferta_id'))
            ->orderBy('id')->first();

        if ($proxima !== null) {
            $produto->update(['oferta_id' => $proxima->id]);
        }

        if ($r === null) {
            Log::info("[Publicador] Oferta {$oferta->id} ({$oferta->sku}) excluída no Portal: grupo {$produto->id} "
                .($proxima !== null ? "reancorado na oferta {$proxima->id}" : 'solto do Portal').', sem rascunho.');

            return;
        }

        $porVariante = (array) ($e['precos_por_variante'] ?? []);
        // Mesma leitura do `comEfetivos`: SKU da variante, senão o SKU do rascunho.
        $skuDoRascunho = $r->atributos()->where('attribute_id', 'SELLER_SKU')->value('value_name');
        $titulos = $proxima === null ? $this->congelarTitulos($r, $e['titulos']) : 0;
        $precos = 0;
        foreach ($r->variantes()->with('atributos')->get() as $variante) {
            $sku = EstruturaOferta::normalizarSku($variante->atributos->firstWhere('attribute_id', 'SELLER_SKU')?->value_name ?? $skuDoRascunho);
            if ($proxima !== null && ($sku === null || $sku !== $skuExcluido)) {
                continue; // a cor continua com o preço ao vivo da SUA oferta
            }
            $precos += $this->congelarPrecos($r, $variante, ($sku !== null ? ($porVariante[$sku] ?? null) : null) ?? $e['precos']);
        }

        Log::info("[Publicador] Oferta {$oferta->id} ({$oferta->sku}) excluída no Portal: grupo {$produto->id} "
            .($proxima !== null ? "reancorado na oferta {$proxima->id}" : 'solto do Portal')
            ."; {$titulos} título(s) e {$precos} preço(s) congelados no rascunho {$r->id}.");
    }

    /** @param array<string, ?string> $efetivos */
    private function congelarTitulos(PubRascunho $r, array $efetivos): int
    {
        $titulos = 0;
        foreach ($r->alvos()->get() as $alvo) {
            $efetivo = $efetivos[$alvo->listing_type_id] ?? null;
            if (trim((string) $alvo->titulo) === '' && $efetivo !== null) {
                $alvo->update(['titulo' => $efetivo]);
                $titulos++;
            }
        }

        return $titulos;
    }

    /** Só o preço VAZIO de cada alvo recebe o efetivo (o digitado vence). @param array<string, ?float> $efetivos */
    private function congelarPrecos(PubRascunho $r, $variante, array $efetivos): int
    {
        $precos = 0;
        foreach ($r->alvos()->get() as $alvo) {
            $efetivo = $efetivos[$alvo->listing_type_id] ?? null;
            $digitado = $variante->precos()->where('alvo_id', $alvo->id)->whereNotNull('preco')->exists();
            if (! $digitado && $efetivo !== null) {
                $variante->precos()->updateOrCreate(['alvo_id' => $alvo->id], ['preco' => round((float) $efetivo, 2)]);
                $precos++;
            }
        }

        return $precos;
    }
}
