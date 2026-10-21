<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;

/**
 * A foto de cada variação nos cartões de sugestão do Planejamento (21/10/2026: sugestões e criadas na mesma
 * tela, no visual dos cartões das Criadas). Mesma régua de {@see OfertasCriadas}: a capa da galeria da
 * variação, na miniatura, e, para quem não tem galeria, a miniatura do acervo do primeiro anúncio ativo com
 * MLB da oferta simples dela. Sem nenhuma das duas, fica de fora (o cartão mostra "sem foto"). Nada é buscado
 * na API.
 */
class FotosDasVariacoes
{
    /**
     * @param  list<int>  $variacoes
     * @return array<int, string> variacao_id => URL
     */
    public function capas(Company $empresa, array $variacoes): array
    {
        $variacoes = array_values(array_unique(array_filter(array_map('intval', $variacoes))));
        if ($variacoes === []) {
            return [];
        }

        $fotos = [];
        EstruturaProdutoVariacaoImagem::query()
            ->where('company_id', $empresa->id)
            ->whereIn('variacao_id', $variacoes)
            ->orderBy('variacao_id')->orderBy('ordem')->orderBy('id')
            ->get(['id', 'variacao_id'])
            ->each(function (EstruturaProdutoVariacaoImagem $i) use (&$fotos) {
                $fotos[(int) $i->variacao_id] ??= VariacaoImagensService::urlMiniatura((int) $i->variacao_id, (int) $i->id);
            });

        $semGaleria = array_values(array_diff($variacoes, array_keys($fotos)));
        if ($semGaleria === []) {
            return $fotos;
        }

        // Oferta simples da variação → anúncio ativo com MLB → miniatura do acervo.
        $variacaoDaOferta = EstruturaOferta::query()
            ->where('company_id', $empresa->id)
            ->where('fase', EstruturaOferta::FASE_SIMPLES)
            ->whereIn('variacao_id', $semGaleria)
            ->pluck('variacao_id', 'id');
        if ($variacaoDaOferta->isEmpty()) {
            return $fotos;
        }

        $anuncios = EstruturaAnuncio::query()
            ->whereIn('oferta_id', $variacaoDaOferta->keys()->all())
            ->where('status', '!=', EstruturaAnuncio::STATUS_INATIVO)
            ->whereNotNull('codigo_mlb')
            ->orderBy('id')
            ->get(['oferta_id', 'codigo_mlb']);
        if ($anuncios->isEmpty()) {
            return $fotos;
        }

        $miniaturas = MlAcervoItem::query()
            ->where('company_id', $empresa->id)
            ->whereIn('ml_item_id', $anuncios->pluck('codigo_mlb')->unique()->values()->all())
            ->whereNotNull('thumbnail')
            ->pluck('thumbnail', 'ml_item_id');

        foreach ($anuncios as $a) {
            $variacao = (int) ($variacaoDaOferta[$a->oferta_id] ?? 0);
            $miniatura = $miniaturas[$a->codigo_mlb] ?? null;
            if ($variacao > 0 && $miniatura && ! isset($fotos[$variacao])) {
                $fotos[$variacao] = preg_replace('#^http://#', 'https://', $miniatura);
            }
        }

        return $fotos;
    }
}
