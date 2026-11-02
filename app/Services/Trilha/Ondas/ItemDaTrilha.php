<?php

namespace App\Services\Trilha\Ondas;

use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;

/**
 * A régua INVERSA da trilha (2ª entrega, 02/11/2026): de um pub_produto do Publicador para o item da trilha que
 * ele representa. O sentido de ida (item → pub_produtos) é o de `OfertasDaTrilha::agrupar()` com
 * `SituacaoDasOfertas::pubsDaSimples()/pubsDoCombo()/pubsDaOferta()`; esta classe é a volta, para a trava do robô
 * saber de qual onda o rascunho depende. As duas têm de concordar — `Tests\Feature\Trilha\ItemDaTrilhaTest`
 * confere com os mesmos dados.
 *
 * | o pub é                                                      | item            |
 * |--------------------------------------------------------------|-----------------|
 * | grupo das cores (`estrutura_produto_id`, sem base)           | `s-{p}`         |
 * | Kit N (`produto_base_id`) de uma Simples do produto          | `c-{p}-{N}`     |
 * | `oferta_id` de uma Simples com variação (legado por cor)     | `s-{p}`         |
 * | `oferta_id` de um combo de 1 componente × N ≥ 2 de uma cor   | `c-{p}-{N}`     |
 * | `oferta_id` de outra oferta composta (Kit, Combit…)          | `o-{oferta}`    |
 * | `oferta_id` de uma Simples sem variação                      | `o-{oferta}`, sem produto |
 * | nenhum dos anteriores (produto criado no Publicador)         | null            |
 */
final class ItemDaTrilha
{
    /**
     * @return array{chave: string, sem_produto: bool}|null
     */
    public static function doPub(PubProduto $pub): ?array
    {
        if ($pub->produto_base_id !== null) {
            $base = PubProduto::query()->find((int) $pub->produto_base_id, ['id', 'company_id', 'oferta_id', 'estrutura_produto_id', 'produto_base_id']);
            $daBase = $base !== null && $base->produto_base_id === null ? self::doPub($base) : null;
            $n = (int) $pub->quantidade_kit;
            if ($daBase === null || ! str_starts_with($daBase['chave'], 's-') || $n < 2) {
                return null;
            }

            return ['chave' => 'c-'.substr($daBase['chave'], 2).'-'.$n, 'sem_produto' => false];
        }

        if ($pub->estrutura_produto_id !== null) {
            return ['chave' => 's-'.(int) $pub->estrutura_produto_id, 'sem_produto' => false];
        }

        if ($pub->oferta_id === null) {
            return null;
        }
        $oferta = EstruturaOferta::query()->find((int) $pub->oferta_id, ['id', 'company_id', 'variacao_id', 'fase']);
        if ($oferta === null) {
            return null;
        }

        if ($oferta->fase === EstruturaOferta::FASE_SIMPLES) {
            $produto = self::produtoDaSimples($oferta);

            return $produto !== null
                ? ['chave' => 's-'.$produto, 'sem_produto' => false]
                : ['chave' => 'o-'.$oferta->id, 'sem_produto' => true];
        }

        // O Combo N do produto: UM componente (a simples de uma cor do produto) com quantidade N ≥ 2 — a régua de
        // `OfertasDaTrilha::agrupar()` (e de `PlanejamentoDaFaseService::linhasDeCombo()`).
        if ($oferta->fase === EstruturaOferta::FASE_COMBO) {
            $linhas = EstruturaOfertaComponente::query()->where('oferta_id', $oferta->id)->get(['componente_id', 'quantidade']);
            if ($linhas->count() === 1 && (int) $linhas->first()->quantidade >= 2) {
                $componente = EstruturaOferta::query()->find((int) $linhas->first()->componente_id, ['id', 'company_id', 'variacao_id', 'fase']);
                $produto = $componente !== null && $componente->fase === EstruturaOferta::FASE_SIMPLES ? self::produtoDaSimples($componente) : null;
                if ($produto !== null) {
                    return ['chave' => 'c-'.$produto.'-'.(int) $linhas->first()->quantidade, 'sem_produto' => false];
                }
            }
        }

        return ['chave' => 'o-'.$oferta->id, 'sem_produto' => false];
    }

    /** O produto da oferta simples (pela variação, da mesma empresa), ou null quando ela não tem variação. */
    private static function produtoDaSimples(EstruturaOferta $oferta): ?int
    {
        if ($oferta->variacao_id === null) {
            return null;
        }
        $produto = EstruturaProdutoVariacao::query()
            ->whereKey((int) $oferta->variacao_id)
            ->where('company_id', (int) $oferta->company_id)
            ->value('produto_id');

        return $produto !== null ? (int) $produto : null;
    }
}
