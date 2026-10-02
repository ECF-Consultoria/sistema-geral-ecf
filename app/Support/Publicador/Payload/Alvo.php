<?php

namespace App\Support\Publicador\Payload;

/**
 * Um tipo de anúncio a publicar a partir do rascunho (decisão D1, `16` §5.1):
 * o par Clássico + Premium sai do MESMO rascunho, cada um com o seu título
 * ("mesmo SKU, títulos diferentes" — ADR PORTAL-03). No modelo User Products o
 * título do alvo vira o `family_name` dos itens daquele alvo.
 *
 * `ativo = false`: o tipo já está no ar (importado ou publicado) e não se
 * publica de novo — é a régua do Mapeamento que diz.
 */
final class Alvo
{
    public function __construct(
        public readonly string $listingTypeId,
        public readonly ?string $titulo,
        public readonly bool $ativo = true,
    ) {}
}
