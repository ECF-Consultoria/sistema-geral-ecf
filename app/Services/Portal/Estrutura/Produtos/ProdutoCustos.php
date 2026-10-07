<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Custo das ofertas ligadas a um produto (Fase 167, D-10): o custo mora na
 * variação e a Precificação lê de lá. Uma só verdade — o custo digitado antes
 * em `estrutura_precificacoes.custo` não vence o do produto.
 */
final class ProdutoCustos
{
    /**
     * Custo da variação de cada oferta ligada da empresa (null = variação sem custo).
     *
     * @return array<int, ?float> oferta_id => custo
     */
    public static function daEmpresa(Company $empresa): array
    {
        $r = [];
        $linhas = DB::table('estrutura_ofertas as o')
            ->join('estrutura_produto_variacoes as v', 'v.id', '=', 'o.variacao_id')
            ->where('o.company_id', $empresa->id)
            ->get(['o.id as oferta_id', 'v.custo']);

        foreach ($linhas as $l) {
            $r[(int) $l->oferta_id] = $l->custo === null ? null : (float) $l->custo;
        }

        return $r;
    }
}
