<?php

namespace App\Services\Portal\Estrutura;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * A ordem do trabalho: dos produtos que MAIS vendem para os que menos.
 *
 * Um produto que vende 4.500 e está sem Premium é o primeiro buraco a tapar —
 * a lista de ofertas e a proposta da agenda seguem a MESMA ordem, senão a
 * agenda proporia primeiro o que a tela mostra por último.
 *
 * Vendas = `sold_quantity` (vitalício) dos anúncios da oferta no acervo do
 * ML, somado — cada anúncio vende por si, então aqui somar é certo (ao
 * contrário do estoque). O bloco (produto + combos) soma as ofertas dele.
 * Empate (inclusive tudo zero, sem conta conectada) mantém a ordem de
 * cadastro: o `usort` do PHP 8 é estável.
 */
final class OrdemPorVendas
{
    /**
     * Uma consulta: anúncio da oferta × acervo pelo MLB (índice único
     * `company_id + ml_item_id`).
     *
     * @return array<int, int> id da oferta → vendas
     */
    public static function vendasPorOferta(Company $empresa): array
    {
        return DB::table('estrutura_anuncios as a')
            ->join('estrutura_ofertas as o', 'o.id', '=', 'a.oferta_id')
            ->join('ml_acervo_itens as m', fn ($j) => $j->on('m.company_id', '=', 'o.company_id')->on('m.ml_item_id', '=', 'a.codigo_mlb'))
            ->where('o.company_id', $empresa->id)
            ->groupBy('a.oferta_id')
            ->selectRaw('a.oferta_id, SUM(COALESCE(m.sold_quantity, 0)) as vendas')
            ->pluck('vendas', 'a.oferta_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Os blocos do conjunto, do que mais vende para o que menos, cada um com
     * `vendas` (a soma das ofertas dele).
     *
     * @param  array<int, int>  $vendas
     * @return array<int, array{principal: int, ofertas: array<int, int>, vendas: int}>
     */
    public static function blocos(EstruturaConjunto $conjunto, array $vendas): array
    {
        $blocos = array_map(fn ($b) => [...$b, 'vendas' => array_sum(array_map(fn ($id) => $vendas[$id] ?? 0, $b['ofertas']))], $conjunto->blocos());
        usort($blocos, fn ($a, $b) => $b['vendas'] <=> $a['vendas']);

        return $blocos;
    }
}
