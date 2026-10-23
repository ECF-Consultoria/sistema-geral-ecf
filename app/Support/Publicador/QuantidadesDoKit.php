<?php

namespace App\Support\Publicador;

/**
 * Os campos de QUANTIDADE da ficha num kit de N unidades (11/10/2026).
 *
 * O usuário viu no Mercado Livre um kit de 2 com as medidas do produto iguais às da unidade e, na ficha,
 * "Quantidade de caixas de embalagem: 2 · Quantidade de peças: 2 · Quantidade de lugares: 2 · Quantidade de
 * embalagens: 2", e perguntou se o nosso deveria ser assim em vez de somar as dimensões. As duas coisas
 * convivem: as medidas que o comprador vê são as do PRODUTO (ficam as da unidade); o pacote oculto do vendedor
 * é o que soma (`PacoteDoKit`); e estes quatro campos descrevem o kit para o comprador.
 *
 * Eles eram copiados da unidade: o Kit 2 da Poltrona Opala foi publicado dizendo "1 peça, 1 lugar", com
 * embalagens e caixas em "não se aplica".
 *
 * A conta é a da unidade × N, e a unidade sem valor (vazio ou "não se aplica") conta como 1. Só estes quatro:
 * outros campos de quantidade da ficha (gavetas, prateleiras, posições) são de cada unidade e não multiplicam.
 *
 * Função pura.
 */
final class QuantidadesDoKit
{
    /** Embalagens, caixas, peças e lugares — os campos que crescem com o kit. */
    public const CAMPOS = ['UNITS_PER_PACK', 'PACKAGING_BOXES_NUMBER', 'PIECES_NUMBER', 'SEATERS_NUMBER'];

    /** O `value_id` do "não se aplica" do Mercado Livre. */
    private const NAO_SE_APLICA = '-1';

    /**
     * "É kit" (23/10/2026, teste do Publicador, P-12): era copiado da peça e todo kit, combo e composto ia ao ML com
     * "Não" — inclusive o Kit 2 publicado. Num kit de 2 unidades para cima, é "Sim".
     */
    public const E_KIT = 'IS_KIT';

    /**
     * O valor "Sim" do "É kit", pela definição da categoria (o `value_id` do Sim). Nulo quando a categoria não tem o
     * atributo ou a lista dele não tem "Sim".
     *
     * @param  ?array  $definicao  o atributo `IS_KIT` no schema da categoria
     * @return array{value_id: ?string, value_name: string, value_number: null}|null
     */
    public static function eKit(?array $definicao): ?array
    {
        foreach ((array) ($definicao['values'] ?? []) as $opcao) {
            if (mb_strtolower(trim((string) ($opcao['name'] ?? ''))) === 'sim') {
                return ['value_id' => isset($opcao['id']) ? (string) $opcao['id'] : null, 'value_name' => (string) $opcao['name'], 'value_number' => null];
            }
        }

        return null;
    }

    public static function ehCampo(string $atributo): bool
    {
        return in_array($atributo, self::CAMPOS, true);
    }

    /**
     * O valor do campo no kit. Nulo = não dá para calcular (a unidade tem texto que não é número, ou a lista
     * da categoria não tem a opção do total): quem chama mantém o da unidade e marca para revisão.
     *
     * @param  array{value_id?: ?string, value_name?: ?string, value_number?: ?float}  $daUnidade  o que o rascunho da unidade tem (vazio = nunca preenchido)
     * @param  ?array  $definicao  o atributo no schema da categoria (`value_type`, `values`); nulo = schema não guardado
     * @return array{value_id: ?string, value_name: string, value_number: ?float}|null
     */
    public static function doKit(array $daUnidade, int $quantidade, ?array $definicao): ?array
    {
        if ($quantidade < 2) {
            return null;
        }

        $nome = trim((string) ($daUnidade['value_name'] ?? ''));
        if ($nome === '') {
            $unidade = 1;
        } elseif (preg_match('/^\d+$/', $nome)) {
            $unidade = (int) $nome;
        } else {
            return null;
        }
        $total = $unidade * $quantidade;

        $idDaUnidade = (string) ($daUnidade['value_id'] ?? '');
        $temOpcaoEscolhida = $idDaUnidade !== '' && $idDaUnidade !== self::NAO_SE_APLICA;
        $ehLista = $definicao !== null ? ($definicao['value_type'] ?? null) === 'list' : $temOpcaoEscolhida;

        if (! $ehLista) {
            return ['value_id' => null, 'value_name' => (string) $total,
                'value_number' => isset($daUnidade['value_number']) ? (float) $total : null];
        }

        foreach ((array) ($definicao['values'] ?? []) as $opcao) {
            if (trim((string) ($opcao['name'] ?? '')) === (string) $total && isset($opcao['id'])) {
                return ['value_id' => (string) $opcao['id'], 'value_name' => (string) $opcao['name'], 'value_number' => null];
            }
        }

        return null;
    }
}
