<?php

namespace App\Services\Trilha;

use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use Illuminate\Support\Collection;

/**
 * Junta as linhas de `estrutura_ofertas` nos itens da trilha, do jeito que o Publicador as agrupa — senão um
 * produto de 3 cores apareceria 3 vezes e o combo dele 3×N vezes:
 *
 * - **Simples do produto** (`s-{produtoId}`): as ofertas simples de TODAS as cores do produto. No Publicador é UM
 *   pub_produto do grupo (`estrutura_produto_id`), com as cores como variantes.
 * - **Combo N do produto** (`c-{produtoId}-{N}`): as ofertas `combo` de UM componente (a simples de uma cor do
 *   produto) com quantidade N ≥ 2 — a mesma régua de `PlanejamentoDaFaseService::linhasDeCombo()`. No Publicador
 *   são as cores do Kit N, a "fase N".
 * - **Kit, Combit e o combo fora da regra** (`o-{ofertaId}`): uma linha de `estrutura_ofertas` cada.
 * - **Oferta sem produto** (`o-{ofertaId}`, `sem_produto`): a simples sem `variacao_id`, vinda da Lista SKUs ou de
 *   importação.
 *
 * Função pura sobre o que quem chama já carregou: nenhuma consulta.
 */
class OfertasDaTrilha
{
    /**
     * @param  Collection<int, EstruturaProduto>  $produtos  com `variacoes` carregadas (na ordem do Portal)
     * @param  Collection<int, EstruturaOferta>  $ofertas  id, variacao_id, sku, fase, nome
     * @param  Collection<int, object>  $componentes  oferta_id, componente_id, quantidade (ordem de inserção)
     * @return array{simples: array<int, array>, sem_produto: list<array>, combos: list<array>, avulsas: list<array>}
     *         cada item: id, tipo, nome, sku, skus, componentes, oferta_ids, produto_id, quantidade,
     *         depende_de (os itens Simples de que um Combo depende), sem_produto
     */
    public static function agrupar(Collection $produtos, Collection $ofertas, Collection $componentes): array
    {
        $produtoPorId = $produtos->keyBy('id');
        $produtoDaVariacao = [];
        $posicaoDaVariacao = [];
        foreach ($produtos as $p) {
            foreach ($p->variacoes->values() as $i => $v) {
                $produtoDaVariacao[(int) $v->id] = (int) $p->id;
                $posicaoDaVariacao[(int) $v->id] = $i;
            }
        }

        $porId = $ofertas->keyBy('id');
        $linhas = $componentes->groupBy('oferta_id');
        $produtoDaSimples = function (?EstruturaOferta $o) use ($produtoDaVariacao): ?int {
            if ($o === null || $o->fase !== EstruturaOferta::FASE_SIMPLES || $o->variacao_id === null) {
                return null;
            }

            return $produtoDaVariacao[(int) $o->variacao_id] ?? null;
        };

        $simplesDoProduto = [];
        $semProduto = [];
        $combos = [];
        $avulsas = [];
        foreach ($ofertas as $o) {
            if ($o->fase === EstruturaOferta::FASE_SIMPLES) {
                $produtoId = $produtoDaSimples($o);
                if ($produtoId === null) {
                    $semProduto[] = $o;
                } else {
                    $simplesDoProduto[$produtoId][] = $o;
                }

                continue;
            }

            $doCombo = $linhas->get($o->id, collect());
            if ($o->fase === EstruturaOferta::FASE_COMBO && $doCombo->count() === 1) {
                $linha = $doCombo->first();
                $produtoId = $produtoDaSimples($porId->get($linha->componente_id));
                if ($produtoId !== null && (int) $linha->quantidade >= 2) {
                    $combos[$produtoId][(int) $linha->quantidade][] = $o;

                    continue;
                }
            }
            $avulsas[] = $o;
        }

        // A simples → o item da trilha que ela é (para o Combo saber de qual Simples depende).
        $itemDaSimples = [];
        foreach ($simplesDoProduto as $produtoId => $lista) {
            foreach ($lista as $o) {
                $itemDaSimples[(int) $o->id] = "s-{$produtoId}";
            }
        }
        foreach ($semProduto as $o) {
            $itemDaSimples[(int) $o->id] = "o-{$o->id}";
        }

        // Ordem das cores: a da variação no Portal; empate (duas ofertas da mesma cor), a mais antiga.
        $naOrdemDasCores = function (array $lista, callable $variacaoDe) use ($posicaoDaVariacao): array {
            usort($lista, fn ($a, $b) => [$posicaoDaVariacao[$variacaoDe($a)] ?? PHP_INT_MAX, (int) $a->id]
                <=> [$posicaoDaVariacao[$variacaoDe($b)] ?? PHP_INT_MAX, (int) $b->id]);

            return $lista;
        };

        $saida = ['simples' => [], 'sem_produto' => [], 'combos' => [], 'avulsas' => []];

        foreach ($produtos as $p) {
            $lista = $naOrdemDasCores($simplesDoProduto[(int) $p->id] ?? [], fn ($o) => (int) $o->variacao_id);
            $saida['simples'][(int) $p->id] = self::simples($p, $lista);
        }

        foreach ($semProduto as $o) {
            $saida['sem_produto'][] = self::daOferta($o, [[
                'produto_id' => null,
                'quantidade' => 1,
                'sku'        => (string) $o->sku,
                'nome'       => self::nomeDaOferta($o),
            ]], [], true);
        }

        foreach ($combos as $produtoId => $porQuantidade) {
            ksort($porQuantidade);
            $p = $produtoPorId->get($produtoId);
            foreach ($porQuantidade as $n => $lista) {
                $lista = $naOrdemDasCores($lista, function ($o) use ($linhas, $porId) {
                    $componente = $porId->get($linhas->get($o->id)->first()->componente_id);

                    return (int) $componente->variacao_id;
                });
                $saida['combos'][] = self::comboDoProduto($p, (int) $n, $lista);
            }
        }

        foreach ($avulsas as $o) {
            $componentesDaOferta = [];
            $dependeDe = [];
            foreach ($linhas->get($o->id, collect()) as $linha) {
                $componente = $porId->get($linha->componente_id);
                $produtoId = $produtoDaSimples($componente);
                $produto = $produtoId !== null ? $produtoPorId->get($produtoId) : null;
                $componentesDaOferta[] = [
                    'produto_id' => $produtoId,
                    'quantidade' => (int) $linha->quantidade,
                    'sku'        => (string) ($componente?->sku ?? ''),
                    'nome'       => $produto !== null ? (string) $produto->nome : ($componente !== null ? self::nomeDaOferta($componente) : ''),
                ];
                if ($componente !== null && isset($itemDaSimples[(int) $componente->id])) {
                    $dependeDe[$itemDaSimples[(int) $componente->id]] = true;
                }
            }
            // Só o Combo espera a simples ir ao ar (a regra da fase N); Kit e Combit não.
            $saida['avulsas'][] = self::daOferta($o, $componentesDaOferta, $o->fase === EstruturaOferta::FASE_COMBO ? array_keys($dependeDe) : [], false);
        }

        return $saida;
    }

    /** O código do produto ("Grupo"); sem ele, o 1º SKU das cores. */
    private static function skuDoProduto(EstruturaProduto $p, array $skus): string
    {
        $codigo = trim((string) $p->codigo);

        return $codigo !== '' ? $codigo : (string) ($skus[0] ?? '');
    }

    /**
     * A Simples do produto. Os SKUs são os das cores (a oferta simples de cada uma, ou o código da variação que
     * ainda não tem oferta — o mesmo de `RetratoDoCatalogo`).
     *
     * @param  list<EstruturaOferta>  $simples  as ofertas simples do produto, na ordem das cores
     */
    private static function simples(EstruturaProduto $p, array $simples): array
    {
        $daVariacao = [];
        foreach ($simples as $o) {
            $daVariacao[(int) $o->variacao_id] ??= $o;
        }
        $skus = [];
        foreach ($p->variacoes as $v) {
            $skus[] = (string) (($daVariacao[(int) $v->id] ?? null)?->sku ?? $v->codigo);
        }
        $sku = self::skuDoProduto($p, $skus);

        return [
            'id'          => "s-{$p->id}",
            'tipo'        => EstruturaOferta::FASES[EstruturaOferta::FASE_SIMPLES],
            'nome'        => (string) $p->nome,
            'sku'         => $sku,
            'skus'        => $skus,
            'componentes' => [['produto_id' => (int) $p->id, 'quantidade' => 1, 'sku' => $sku, 'nome' => (string) $p->nome]],
            'oferta_ids'  => array_map(fn ($o) => (int) $o->id, $simples),
            'produto_id'  => (int) $p->id,
            'quantidade'  => 1,
            'depende_de'  => [],
            'sem_produto' => false,
        ];
    }

    /**
     * O Combo N do produto: o SKU principal é o da 1ª cor; o nome, "Combo N · produto" — ou o da própria oferta
     * quando o produto tem uma cor só (aí não há o que juntar e o nome escolhido no Planejamento vale).
     *
     * @param  list<EstruturaOferta>  $combos  na ordem das cores
     */
    private static function comboDoProduto(EstruturaProduto $p, int $n, array $combos): array
    {
        $skusDoProduto = $p->variacoes->map(fn ($v) => (string) $v->codigo)->all();
        $proprio = trim((string) ($combos[0]->nome ?? ''));
        $nome = $p->variacoes->count() === 1 && $proprio !== '' ? $proprio : "Combo {$n} · {$p->nome}";

        return [
            'id'          => "c-{$p->id}-{$n}",
            'tipo'        => EstruturaOferta::FASES[EstruturaOferta::FASE_COMBO],
            'nome'        => $nome,
            'sku'         => (string) $combos[0]->sku,
            'skus'        => array_map(fn ($o) => (string) $o->sku, $combos),
            'componentes' => [['produto_id' => (int) $p->id, 'quantidade' => $n, 'sku' => self::skuDoProduto($p, $skusDoProduto), 'nome' => (string) $p->nome]],
            'oferta_ids'  => array_map(fn ($o) => (int) $o->id, $combos),
            'produto_id'  => (int) $p->id,
            'quantidade'  => $n,
            'depende_de'  => ["s-{$p->id}"],
            'sem_produto' => false,
        ];
    }

    /**
     * @param  list<array{produto_id: ?int, quantidade: int, sku: string, nome: string}>  $componentes
     * @param  list<string>  $dependeDe
     */
    private static function daOferta(EstruturaOferta $o, array $componentes, array $dependeDe, bool $semProduto): array
    {
        return [
            'id'          => "o-{$o->id}",
            'tipo'        => EstruturaOferta::FASES[$o->fase] ?? EstruturaOferta::FASES[EstruturaOferta::FASE_SIMPLES],
            'nome'        => self::nomeDaOferta($o),
            'sku'         => (string) $o->sku,
            'skus'        => [(string) $o->sku],
            'componentes' => $componentes,
            'oferta_ids'  => [(int) $o->id],
            'produto_id'  => null,
            'quantidade'  => null,
            'depende_de'  => $dependeDe,
            'sem_produto' => $semProduto,
        ];
    }

    private static function nomeDaOferta(EstruturaOferta $o): string
    {
        $nome = trim((string) $o->nome);

        return $nome !== '' ? $nome : (string) $o->sku;
    }
}
