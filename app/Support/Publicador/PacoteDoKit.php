<?php

namespace App\Support\Publicador;

/**
 * O pacote de um kit de N unidades iguais (10/10/2026).
 *
 * O usuário criou o Kit 2 da Poltrona Opala pelo "Criar fase" e o pacote saiu igual ao da unidade
 * (90 × 85 × 70 cm, 12 kg), e assim foi publicado: "se o peso é 12 kg, deveria ir para 24; se a altura é 50,
 * deveria ir para 100". Até aqui o clone só copiava a caixa da unidade e a marcava para revisão.
 *
 * A regra é a MESMA que o Portal usa para qualquer conjunto (D-17: `LogisticaProduto::pacote` sobre os
 * volumes repetidos pelo `ConjuntoLogistico`): as caixas empilhadas — altura somada, peso somado, comprimento
 * e largura os da unidade. É uma das três arrumações do guia de envios do Mercado Pago/Mercado Livre (lado a lado no
 * comprimento, lado a lado na largura ou empilhado na altura); o volume, e com ele o peso cubado, é o mesmo
 * nas três. A Precificação do Portal já cobra o frete do Combo N por essa conta: com a caixa da unidade no
 * anúncio, o preço considerava N volumes e o anúncio ia com um.
 *
 * Continua sendo estimativa de como a pessoa embala: quem chama mantém o `revisar` do atributo.
 *
 * Função pura.
 */
final class PacoteDoKit
{
    /** O que cresce com a quantidade. Comprimento e largura ficam os da unidade. */
    private const CRESCEM = ['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT', 'PACKAGE_HEIGHT', 'PACKAGE_WEIGHT'];

    public static function cresce(string $atributo): bool
    {
        return in_array($atributo, self::CRESCEM, true);
    }

    /**
     * "90 cm" × 2 = "180 cm"; "12000 g" × 2 = "24000 g"; "1,5 kg" × 2 = "3 kg". Valor que não é
     * "número unidade" (ou quantidade menor que 2) volta como veio.
     */
    public static function texto(?string $valor, int $quantidade): ?string
    {
        if ($valor === null || $quantidade < 2 || ! preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(\p{L}+)?\s*$/u', $valor, $m)) {
            return $valor;
        }

        $numero = rtrim(rtrim(number_format((float) str_replace(',', '.', $m[1]) * $quantidade, 2, '.', ''), '0'), '.');
        $unidade = $m[2] ?? '';

        return $unidade === '' ? $numero : "{$numero} {$unidade}";
    }

    /** O mesmo para a coluna numérica do atributo (`value_number`), quando ela está preenchida. */
    public static function numero(?float $valor, int $quantidade): ?float
    {
        return $valor === null || $quantidade < 2 ? $valor : $valor * $quantidade;
    }
}
