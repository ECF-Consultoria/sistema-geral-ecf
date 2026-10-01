<?php

namespace App\Support\Publicador\Payload;

/**
 * Um `POST /items` do plano: o payload exato e de onde ele veio (alvo e
 * variante), para mapear erro do ML de volta à tela e gravar o `ml_item_id`
 * no lugar certo (`04` §3, `09` §3).
 */
final class ItemPlano
{
    /** @param list<string> $imagensPendentes  fotos do item ainda sem id no ML (o upload vem antes do POST — V-IMG-08) */
    public function __construct(
        public readonly int $indice,
        public readonly string $listingTypeId,
        public readonly string $varianteChave,
        public readonly string $rotulo,
        public readonly array $payload,
        public readonly array $imagensPendentes,
    ) {}
}
