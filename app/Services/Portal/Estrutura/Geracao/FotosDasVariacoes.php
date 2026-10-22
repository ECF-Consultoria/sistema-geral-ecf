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

        foreach ($this->miniaturasDosAnuncios($empresa, $variacaoDaOferta->keys()->all()) as $oferta => $miniatura) {
            $variacao = (int) ($variacaoDaOferta[$oferta] ?? 0);
            if ($variacao > 0 && ! isset($fotos[$variacao])) {
                $fotos[$variacao] = $miniatura;
            }
        }

        return $fotos;
    }

    /**
     * A foto das ofertas simples sem produto cadastrado (as "avulsas" da janela de montar, 22/10/2026): não há
     * galeria, então vale só a miniatura do acervo do primeiro anúncio ativo com MLB. Sem ela, fica de fora.
     *
     * @param  list<int>  $ofertas
     * @return array<int, string> oferta_id => URL
     */
    public function capasDasOfertas(Company $empresa, array $ofertas): array
    {
        $ofertas = array_values(array_unique(array_filter(array_map('intval', $ofertas))));
        if ($ofertas === []) {
            return [];
        }

        // Só as da empresa: o id veio de fora deste serviço.
        $daEmpresa = EstruturaOferta::query()->where('company_id', $empresa->id)->whereIn('id', $ofertas)->pluck('id')->all();

        return $this->miniaturasDosAnuncios($empresa, $daEmpresa);
    }

    /**
     * Oferta → primeiro anúncio não inativo com MLB → miniatura do acervo, já em https.
     *
     * @param  list<int>  $ofertas
     * @return array<int, string> oferta_id => URL
     */
    private function miniaturasDosAnuncios(Company $empresa, array $ofertas): array
    {
        if ($ofertas === []) {
            return [];
        }

        $anuncios = EstruturaAnuncio::query()
            ->whereIn('oferta_id', $ofertas)
            ->where('status', '!=', EstruturaAnuncio::STATUS_INATIVO)
            ->whereNotNull('codigo_mlb')
            ->orderBy('id')
            ->get(['oferta_id', 'codigo_mlb']);
        if ($anuncios->isEmpty()) {
            return [];
        }

        $miniaturas = MlAcervoItem::query()
            ->where('company_id', $empresa->id)
            ->whereIn('ml_item_id', $anuncios->pluck('codigo_mlb')->unique()->values()->all())
            ->whereNotNull('thumbnail')
            ->pluck('thumbnail', 'ml_item_id');

        $fotos = [];
        foreach ($anuncios as $a) {
            $miniatura = $miniaturas[$a->codigo_mlb] ?? null;
            if ($miniatura && ! isset($fotos[(int) $a->oferta_id])) {
                $fotos[(int) $a->oferta_id] = preg_replace('#^http://#', 'https://', $miniatura);
            }
        }

        return $fotos;
    }
}
