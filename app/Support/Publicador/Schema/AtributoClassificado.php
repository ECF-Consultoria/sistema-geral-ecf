<?php

namespace App\Support\Publicador\Schema;

/**
 * Um atributo da categoria já classificado para ESTE rascunho (`03` §4):
 * papel, obrigatoriedade efetiva, onde aparece na tela e como o valor é
 * aceito. É o que o formulário dinâmico desenha e o que as validações
 * consultam — nenhum atributo é campo fixo.
 */
final class AtributoClassificado
{
    // Papel: onde o valor mora.
    public const SYSTEM = 'SYSTEM';                  // read_only / inferred / fixed: não editável, nunca enviado
    public const VARIATION_AXIS = 'VARIATION_AXIS';  // eixo escolhido
    public const VARIANT_DATA = 'VARIANT_DATA';      // variation_attribute: um valor por variante (SKU, GTIN…)
    public const PRODUCT = 'PRODUCT';                // um valor para o produto

    // Obrigatoriedade efetiva.
    public const REQUIRED = 'REQUIRED';
    public const RECOMMENDED = 'RECOMMENDED';        // catalog_required sem required: afeta a exposição
    public const OPTIONAL = 'OPTIONAL';

    // Onde aparece na tela.
    public const SECAO_PRINCIPAIS = 'PRINCIPAIS';    // grupo MAIN do technical_specs (E3)
    public const SECAO_FICHA = 'FICHA';              // os demais grupos visíveis (E8)
    public const SECAO_AVANCADO = 'AVANCADO';        // hidden e editável, recolhido
    public const SECAO_EIXO = 'EIXO';                // editor de variações (E4)
    public const SECAO_VARIANTE = 'VARIANTE';        // grade de variantes (E5)
    public const SECAO_EMBALAGEM = 'EMBALAGEM';      // SELLER_PACKAGE_* (E10)
    public const SECAO_CONDICAO = 'CONDICAO';        // ITEM_CONDITION, dirigido pela condição (E3)
    public const SECAO_OCULTO = 'OCULTO';            // sistema, ou used_hidden em produto usado

    /**
     * @param  list<array{id: string, name: string}>  $valores
     * @param  list<string>  $unidades
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        public readonly string $papel,
        public readonly string $obrigatoriedade,
        public readonly string $secao,
        public readonly ?string $grupo,
        public readonly string $valueType,
        public readonly array $valores,
        public readonly array $unidades,
        public readonly ?string $unidadePadrao,
        public readonly bool $aceitaTextoLivre,
        public readonly bool $aceitaNaoSeAplica,
        public readonly bool $podeSerEixo,
        public readonly bool $definePicture,
        public readonly bool $multivalor,
        public readonly int $maxLength,
        public readonly ?string $dica,
        public readonly ?string $exemplo,
        public readonly ?string $tooltip,
        public readonly ?string $componente,
        public readonly array $tags,
    ) {}

    public function obrigatorio(): bool
    {
        return $this->obrigatoriedade === self::REQUIRED;
    }

    public function editavel(): bool
    {
        return $this->papel !== self::SYSTEM;
    }
}
