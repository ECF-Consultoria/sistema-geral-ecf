<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * A família de cada oferta, para a Precificação agrupar a lista (11/10/2026).
 *
 * O usuário: a Precificação era "uma lista inteira sem saber o que é"; queria o produto unitário com as
 * variações dele embaixo, e levantou o problema do kit — "vou pegar um produto de um e de outro", então o
 * kit teria dois produtos-pai na tela.
 *
 * A família é a do cadastro de Produtos (`estrutura_produtos.familia_id`), que a oferta alcança pela variação.
 * Oferta composta não tem variação própria: herda a do componente. O conjunto (kit, combit) fica na família
 * do PRIMEIRO componente que tiver uma — aparece uma vez só, e cada componente aponta para ele pelo
 * "também entra em" que a tela já recebia.
 *
 * Oferta antiga da Lista SKUs (sem variação) e produto sem família no cadastro ficam em "Sem família".
 */
final class FamiliasDasOfertas
{
    /**
     * @return array<int, array{id: int, nome: string}|null> id da oferta → família (null = sem família)
     */
    public static function daEmpresa(Company $empresa, EstruturaConjunto $conjunto): array
    {
        $porVariacao = DB::table('estrutura_produto_variacoes as v')
            ->join('estrutura_produtos as p', 'p.id', '=', 'v.produto_id')
            ->join('estrutura_familias as f', 'f.id', '=', 'p.familia_id')
            ->where('v.company_id', $empresa->id)
            ->get(['v.id as variacao_id', 'f.id as familia_id', 'f.nome as familia_nome'])
            ->mapWithKeys(fn ($l) => [(int) $l->variacao_id => ['id' => (int) $l->familia_id, 'nome' => (string) $l->familia_nome]])
            ->all();

        return self::resolver($conjunto->ofertas(), $porVariacao);
    }

    /**
     * Função pura: a família própria (pela variação) e, sem ela, a do primeiro componente que tiver.
     *
     * @param  array<int, array{id: int, variacao_id: ?int, componentes: array<int, array{id: int}>}>  $ofertas
     * @param  array<int, array{id: int, nome: string}>  $porVariacao  id da variação → família
     * @return array<int, array{id: int, nome: string}|null>
     */
    public static function resolver(array $ofertas, array $porVariacao): array
    {
        $propria = [];
        foreach ($ofertas as $o) {
            $propria[$o['id']] = ($o['variacao_id'] ?? null) !== null ? ($porVariacao[$o['variacao_id']] ?? null) : null;
        }

        $saida = [];
        foreach ($ofertas as $o) {
            $familia = $propria[$o['id']];

            if ($familia === null) {
                foreach ($o['componentes'] ?? [] as $c) {
                    if (($propria[$c['id']] ?? null) !== null) {
                        $familia = $propria[$c['id']];
                        break;
                    }
                }
            }

            $saida[$o['id']] = $familia;
        }

        return $saida;
    }

    /**
     * Os blocos na ordem da tela por família: famílias pelo nome, "sem família" por último; dentro da família,
     * os produtos (com os combos) antes dos conjuntos; e, entre iguais, a ordem que já vinha (a de vendas).
     *
     * @param  array<int, array{principal: array{id: int, fase: string}}>  $blocos
     * @param  array<int, array{id: int, nome: string}|null>  $familias
     * @return array<int, array>
     */
    public static function ordenar(array $blocos, array $familias): array
    {
        $chaves = [];
        foreach (array_values($blocos) as $i => $b) {
            $f = $familias[$b['principal']['id']] ?? null;
            $conjunto = in_array($b['principal']['fase'], ['kit', 'combit'], true);
            $chaves[$i] = [$f === null ? 1 : 0, $f === null ? '' : mb_strtolower($f['nome']), $f['id'] ?? 0, $conjunto ? 1 : 0, $i];
        }

        $blocos = array_values($blocos);
        $ordem = array_keys($blocos);
        usort($ordem, fn (int $a, int $b) => $chaves[$a] <=> $chaves[$b]);

        return array_map(fn (int $i) => $blocos[$i], $ordem);
    }
}
