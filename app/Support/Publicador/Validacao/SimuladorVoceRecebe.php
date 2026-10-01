<?php

namespace App\Support\Publicador\Validacao;

/**
 * "Você recebe" do Seller Center (`02` E10, H-14 confirmada em 01/10):
 * preço − tarifa (`listing_prices.sale_fee_amount`) − frete do vendedor
 * (`shipping_options/free … coverage.all_country.list_cost`, já com o
 * desconto aplicado). O print: 150 − 16,50 − 62,35 = 71,15 (47,43%).
 *
 * É ESTIMATIVA — tarifa e frete vêm da API, nunca de percentual fixo (RN-85).
 * Sem frete conhecido, calcula sem ele e diz isso.
 */
final class SimuladorVoceRecebe
{
    /** @return array{preco: float, tarifa: float, frete: ?float, voce_recebe: float, percentual: float, frete_conhecido: bool} */
    public static function calcular(float $preco, float $tarifa, ?float $frete): array
    {
        $recebe = round($preco - $tarifa - ($frete ?? 0.0), 2);

        return [
            'preco' => $preco,
            'tarifa' => $tarifa,
            'frete' => $frete,
            'voce_recebe' => $recebe,
            'percentual' => $preco > 0 ? round($recebe / $preco * 100, 2) : 0.0,
            'frete_conhecido' => $frete !== null,
        ];
    }
}
