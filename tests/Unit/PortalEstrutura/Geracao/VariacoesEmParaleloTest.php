<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\VariacoesEmParalelo;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VariacoesEmParaleloTest extends TestCase
{
    private static function v(int $id, int $ordem, ?string $eixo = null, ?string $valor = null): array
    {
        return ['id' => $id, 'ordem' => $ordem, 'eixo' => $eixo, 'valor' => $valor, 'extra' => 'x' . $id];
    }

    /** @return list<array{0:int,1:int}> pares de ids */
    private static function ids(array $pares): array
    {
        return array_map(fn ($p) => [$p[0]['id'], $p[1]['id']], $pares);
    }

    public static function casos(): array
    {
        $v = [self::class, 'v'];

        return [
            '1x1' => [[$v(1, 1)], [$v(2, 1)], [[1, 2]]],
            '1xN casa com todas' => [[$v(1, 1)], [$v(2, 1), $v(3, 2), $v(4, 3)], [[1, 2], [1, 3], [1, 4]]],
            'Nx1 na ordem de a' => [[$v(1, 1), $v(2, 2)], [$v(3, 1)], [[1, 3], [2, 3]]],
            '2x2 por eixo e valor, nunca cruzado' => [
                [$v(1, 1, 'Cor', 'Natural'), $v(2, 2, 'Cor', 'Preto')],
                [$v(3, 1, 'Cor', 'Preto'), $v(4, 2, 'Cor', 'Natural')],
                [[1, 4], [2, 3]],
            ],
            '2x2 sem valor, por posição' => [
                [$v(1, 1), $v(2, 2)],
                [$v(3, 1), $v(4, 2)],
                [[1, 3], [2, 4]],
            ],
            '2x2 sem valor respeita ordem e depois id' => [
                [$v(2, 2), $v(1, 1)],
                [$v(4, 2), $v(3, 1)],
                [[1, 3], [2, 4]],
            ],
            'misto: a com valor, b sem' => [
                [$v(1, 1, 'Cor', 'Natural'), $v(2, 2, 'Cor', 'Preto')],
                [$v(3, 1), $v(4, 2)],
                [[1, 3], [2, 4]],
            ],
            'sobra ignorada' => [
                [$v(1, 1, 'Cor', 'Natural'), $v(2, 2, 'Cor', 'Preto')],
                [$v(3, 1, 'Cor', 'Preto'), $v(4, 2, 'Cor', 'Branco')],
                [[2, 3]],
            ],
            'valor sem caixa e sem acento' => [
                [$v(1, 1, 'Cor', 'Café'), $v(2, 2, 'Cor', 'Preto')],
                [$v(3, 1, 'Cor', 'Preto'), $v(4, 2, 'Cor', 'cafe')],
                [[1, 4], [2, 3]],
            ],
            'eixos diferentes não casam pelo valor' => [
                [$v(1, 1, 'Cor', 'Natural'), $v(2, 2, 'Cor', 'Preto')],
                [$v(3, 1, 'Material', 'Natural'), $v(4, 2, 'Material', 'Preto')],
                [],
            ],
            'lista vazia em a' => [[], [$v(1, 1)], []],
            'lista vazia em b' => [[$v(1, 1)], [], []],
        ];
    }

    #[DataProvider('casos')]
    public function test_casar(array $a, array $b, array $esperado): void
    {
        $this->assertSame($esperado, self::ids(VariacoesEmParalelo::casar($a, $b)));
    }

    public function test_chaves_extras_passam_intactas(): void
    {
        $pares = VariacoesEmParalelo::casar([self::v(1, 1)], [self::v(2, 1)]);

        $this->assertSame('x1', $pares[0][0]['extra']);
        $this->assertSame('x2', $pares[0][1]['extra']);
    }

    public static function tamanhos(): array
    {
        return ['2x2' => [2, 2], '3x3' => [3, 3], '2x3' => [2, 3], '3x2' => [3, 2]];
    }

    #[DataProvider('tamanhos')]
    public function test_nunca_cartesiano(int $n, int $m): void
    {
        $valores = ['Natural', 'Preto', 'Branco'];
        foreach ([false, true] as $comValor) {
            $a = $b = [];
            for ($i = 0; $i < $n; $i++) {
                $a[] = self::v(100 + $i, $i + 1, $comValor ? 'Cor' : null, $comValor ? $valores[$i] : null);
            }
            for ($j = 0; $j < $m; $j++) {
                $b[] = self::v(200 + $j, $j + 1, $comValor ? 'Cor' : null, $comValor ? $valores[($j + 1) % 3] : null);
            }

            $pares = self::ids(VariacoesEmParalelo::casar($a, $b));

            $this->assertLessThanOrEqual(min($n, $m), count($pares));
            $this->assertSame(array_unique(array_column($pares, 0)), array_column($pares, 0));
            $this->assertSame(array_unique(array_column($pares, 1)), array_column($pares, 1));
        }
    }
}
