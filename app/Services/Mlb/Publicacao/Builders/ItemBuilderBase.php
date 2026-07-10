<?php

namespace App\Services\Mlb\Publicacao\Builders;

/**
 * Base compartilhada dos builders: monta os campos comuns aos dois modelos.
 * O título é adicionado (Clássico) ou omitido (User Products) por cada subclasse.
 */
abstract class ItemBuilderBase implements ItemBuilder
{
    /** Campos comuns aos dois modelos (sem o título). */
    protected function montarComum(array $d): array
    {
        $payload = [
            'category_id'        => $d['category_id']        ?? null,
            'price'              => $d['price']              ?? null,
            'currency_id'        => $d['currency_id']        ?? 'BRL',
            'available_quantity' => $d['available_quantity'] ?? null,
            'buying_mode'        => $d['buying_mode']        ?? 'buy_it_now',
            'condition'          => $d['condition']          ?? 'new',
            'listing_type_id'    => $d['listing_type_id']    ?? 'gold_special',
            'attributes'         => $d['attributes']         ?? [],
            'pictures'           => $d['pictures']           ?? [],
        ];

        // Opcionais entram apenas quando presentes (garantia, variações, frete).
        foreach (['sale_terms', 'variations', 'shipping'] as $k) {
            if (! empty($d[$k])) {
                $payload[$k] = $d[$k];
            }
        }

        // Remove chaves nulas para não enviar lixo ao ML.
        return array_filter($payload, fn ($v) => $v !== null);
    }
}
