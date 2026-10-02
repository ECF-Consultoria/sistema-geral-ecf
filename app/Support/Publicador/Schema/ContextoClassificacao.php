<?php

namespace App\Support\Publicador\Schema;

/**
 * O que, do rascunho, muda a classificação de um atributo (`03` §4):
 * a condição (`new_required`, `used_hidden`), os eixos escolhidos (papel
 * VARIATION_AXIS) e os atributos que o `/attributes/conditional` devolveu
 * para a revisão atual.
 */
final class ContextoClassificacao
{
    /**
     * @param  'new'|'used'|'refurbished'  $condicao
     * @param  list<string>  $eixos  attribute_ids escolhidos como eixo
     * @param  list<string>  $condicionais  attribute_ids devolvidos pelo /attributes/conditional
     */
    public function __construct(
        public readonly string $condicao = 'new',
        public readonly array $eixos = [],
        public readonly array $condicionais = [],
    ) {}

    /**
     * Recondicionado vai ao ML como `condition: new` + `ITEM_CONDITION`
     * (H-04, aceito pelo validate em 01/10) — para `new_required` conta como novo.
     */
    public function contaComoNovo(): bool
    {
        return $this->condicao !== 'used';
    }
}
