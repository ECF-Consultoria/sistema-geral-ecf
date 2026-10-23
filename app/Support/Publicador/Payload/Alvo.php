<?php

namespace App\Support\Publicador\Payload;

use App\Support\TituloDoProduto;

/**
 * Um tipo de anúncio a publicar a partir do rascunho (decisão D1, `16` §5.1):
 * o par Clássico + Premium sai do MESMO rascunho, cada um com o seu título
 * ("mesmo SKU, títulos diferentes" — ADR PORTAL-03). No modelo User Products o
 * título do alvo vira o `family_name` dos itens daquele alvo.
 *
 * `ativo = false`: o tipo já está no ar (importado ou publicado) e não se
 * publica de novo — é a régua do Mapeamento que diz.
 *
 * 19/10/2026: o título sai daqui sempre com a 1ª letra de cada palavra maiúscula (`TituloDoProduto`).
 * Todo caminho passa por este objeto — o que o editor grava, o que se confere, o
 * planejado que vem do Portal e o `family_name` do payload —, por isso a regra mora nele.
 */
final class Alvo
{
    public readonly ?string $titulo;

    public function __construct(
        public readonly string $listingTypeId,
        ?string $titulo,
        public readonly bool $ativo = true,
    ) {
        $this->titulo = TituloDoProduto::capitalizar($titulo);
    }
}
