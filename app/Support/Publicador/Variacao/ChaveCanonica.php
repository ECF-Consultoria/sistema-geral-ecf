<?php

namespace App\Support\Publicador\Variacao;

use Illuminate\Support\Str;

/**
 * A identidade de um valor de eixo e de uma combinação (`05` §3).
 *
 * Valor com `value_id` é o id; valor livre é o texto normalizado — " M ", "m"
 * e "M" são o mesmo valor. A chave da combinação ordena os eixos pelo
 * `attribute_id` (o customizado vira `~custom`, que fica por último), e não
 * pela posição na tela: reordenar os eixos não muda a identidade de nada.
 */
final class ChaveCanonica
{
    /** Chave da variante única de um produto sem eixos. */
    public const UNICA = '__single__';

    /** Como o eixo customizado (fora do schema) entra na chave. */
    public const EIXO_CUSTOM = '~custom';

    public static function valor(?string $valueId, ?string $valueName): string
    {
        $valueId = trim((string) $valueId);

        return $valueId !== '' ? "id:{$valueId}" : 'txt:'.self::texto($valueName);
    }

    /** Minúsculo, sem acento, sem espaço sobrando — a comparação de texto livre. */
    public static function texto(?string $texto): string
    {
        $limpo = preg_replace('/\s+/u', ' ', trim((string) $texto));

        return Str::ascii(mb_strtolower($limpo));
    }

    /** @param array<string, string> $valores  chave do eixo → chave do valor */
    public static function combinacao(array $valores): string
    {
        if ($valores === []) {
            return self::UNICA;
        }

        ksort($valores, SORT_STRING);

        return implode('|', array_map(fn ($eixo, $valor) => "{$eixo}={$valor}", array_keys($valores), $valores));
    }

    /** A chave pode passar de 191 caracteres (3 eixos de texto livre): no banco o índice é o hash. */
    public static function hash(string $chave): string
    {
        return hash('sha256', $chave);
    }
}
