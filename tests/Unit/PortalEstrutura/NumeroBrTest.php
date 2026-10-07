<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\NumeroBr;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumeroBrTest extends TestCase
{
    public static function validos(): array
    {
        return [
            'virgula'          => ['27,8', NumeroBr::MEDIDA, 27.8],
            'ponto'            => ['27.8', NumeroBr::MEDIDA, 27.8],
            'milhar br'        => ['1.234,50', NumeroBr::DINHEIRO, 1234.5],
            'milhar us'        => ['1,234.50', NumeroBr::DINHEIRO, 1234.5],
            'reais'            => ['R$ 1.234,50', NumeroBr::DINHEIRO, 1234.5],
            'ponto milhar din' => ['1.234', NumeroBr::DINHEIRO, 1234.0],
            // BE-IN-07: milhar não começa com 0.
            'zero ponto din'   => ['0.500', NumeroBr::DINHEIRO, 0.5],
            'zero ponto 3 din' => ['0.123', NumeroBr::DINHEIRO, 0.123],
            'milhar 2 grupos'  => ['12.345.678', NumeroBr::DINHEIRO, 12345678.0],
            'ponto medida'     => ['1.234', NumeroBr::MEDIDA, 1.234],
            'int'              => [12, NumeroBr::MEDIDA, 12.0],
            'com kg'           => ['9 kg', NumeroBr::MEDIDA, 9.0],
        ];
    }

    #[DataProvider('validos')]
    public function test_interpreta(mixed $entrada, string $tipo, float $esperado): void
    {
        $this->assertEqualsWithDelta($esperado, NumeroBr::interpretar($entrada, $tipo), 0.00001);
    }

    public function test_vazio_e_nulo_viram_null(): void
    {
        $this->assertNull(NumeroBr::interpretar(''));
        $this->assertNull(NumeroBr::interpretar('   '));
        $this->assertNull(NumeroBr::interpretar(null));
    }

    #[DataProvider('invalidos')]
    public function test_recusa(mixed $entrada): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use só números. Exemplo: 27,8');
        NumeroBr::interpretar($entrada);
    }

    public static function invalidos(): array
    {
        return [['abc'], ['-5'], ['1,2,3'], [-3], ['1.2.3']];
    }
}
