<?php

namespace App\Services\Mlb\Publicacao\Builders;

/**
 * Monta o corpo do item para o POST /items do Mercado Livre a partir dos dados
 * do rascunho (shape interno do wizard).
 *
 * Existem dois modelos de publicação no ML — Clássico e User Products (preço por
 * variação). A diferença central: no User Products o TÍTULO não é enviado (o ML
 * o deriva). Por isso a montagem é uma Strategy, escolhida por conta.
 */
interface ItemBuilder
{
    /** Converte os dados do rascunho no payload do item para o ML. */
    public function montar(array $dados): array;

    /** Identificador do modelo ('classic' | 'user_product') — p/ log/depuração. */
    public function modelo(): string;
}
