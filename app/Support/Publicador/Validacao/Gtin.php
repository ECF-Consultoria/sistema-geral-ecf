<?php

namespace App\Support\Publicador\Validacao;

/**
 * GTIN (EAN/UPC/ITF-14) pelas regras GS1 (`03` §7, [ML·S4]): só dígitos, 8, 12,
 * 13 ou 14 dígitos, dígito verificador certo, sem a sequência de zeros. O ML
 * responde 7711 para dígito errado (medido em 01/10). O atributo é
 * multivalorado: vários GTINs separados por vírgula.
 */
final class Gtin
{
    public static function valido(string $gtin): bool
    {
        $gtin = trim($gtin);
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $gtin) || ltrim($gtin, '0') === '') {
            return false;
        }

        $digitos = array_map('intval', str_split($gtin));
        $verificador = array_pop($digitos);
        $soma = 0;
        foreach (array_reverse($digitos) as $i => $d) {
            $soma += $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $soma % 10) % 10 === $verificador;
    }

    public static function todosValidos(string $valor): bool
    {
        $partes = array_filter(array_map('trim', explode(',', $valor)), fn ($p) => $p !== '');

        return $partes !== [] && array_reduce($partes, fn (bool $ok, string $g) => $ok && self::valido($g), true);
    }
}
