<?php

namespace App\Services\Portal\Estrutura\Produtos;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Texto de volumes da planilha de Produtos <-> lista de volumes.
 *
 * Formato real da planilha: `186×43×12 · 27.8 | 97×42×12 · 12.1` — o `×` é
 * U+00D7, o `·` é U+00B7 e o decimal vem com PONTO. A ORDEM C×L×A é a digitada:
 * nunca reordenar (maior lado e soma independem dela, mas o cliente confere a
 * medida na mesma ordem em que escreveu).
 */
final class VolumesTexto
{
    private const NUM = '\d+(?:[.,]\d+)?';

    /**
     * @return array{volumes: list<array{c: float, l: float, a: float, kg: float}>, valido: bool}
     */
    public static function interpretar(?string $texto): array
    {
        $vazio = ['volumes' => [], 'valido' => true];
        $falha = ['volumes' => [], 'valido' => false];

        $limpo = trim((string) $texto);
        if ($limpo === '' || Str::lower(Str::ascii($limpo)) === 'sem medidas') {
            return $vazio;
        }

        $n   = self::NUM;
        $x   = '\s*[x\x{00D7}*]\s*';
        $sep = '(?:\s*(?:[\x{00B7}\-]|kg)\s*|\s+)';
        $re  = "/^\s*($n)$x($n)$x($n)$sep($n)\s*(?:kg)?\s*$/iu";

        $volumes = [];
        foreach (preg_split('/[|;\r\n]+/', $limpo) as $parte) {
            if (trim($parte) === '') {
                continue;
            }
            if (! preg_match($re, $parte, $m)) {
                return $falha;
            }

            try {
                [$c, $l, $a, $kg] = array_map(
                    fn ($v) => NumeroBr::interpretar($v, NumeroBr::MEDIDA),
                    array_slice($m, 1, 4)
                );
            } catch (InvalidArgumentException) {
                return $falha;
            }

            if ($c <= 0 || $l <= 0 || $a <= 0 || $kg <= 0) {
                return $falha;
            }

            $volumes[] = ['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg];
        }

        return $volumes === [] ? $vazio : ['volumes' => $volumes, 'valido' => true];
    }

    /**
     * @param list<array{c: float|int, l: float|int, a: float|int, kg: float|int}> $volumes
     */
    public static function formatar(array $volumes): string
    {
        $partes = array_map(function (array $v) {
            return self::num($v['c']).'×'.self::num($v['l']).'×'.self::num($v['a'])
                .' · '.self::num($v['kg']);
        }, $volumes);

        return implode(' | ', $partes);
    }

    private static function num(float|int $n): string
    {
        $t = number_format((float) $n, 3, ',', '');

        return rtrim(rtrim($t, '0'), ',');
    }
}
