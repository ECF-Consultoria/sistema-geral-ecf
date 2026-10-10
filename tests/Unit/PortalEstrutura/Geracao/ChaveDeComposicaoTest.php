<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChaveDeComposicaoTest extends TestCase
{
    public function test_chave_e_ordenada_por_id_independente_da_ordem_de_montagem(): void
    {
        $this->assertSame('v12*1+v30*4', ChaveDeComposicao::de([30 => 4, 12 => 1]));
        $this->assertSame('v12*1+v30*4', ChaveDeComposicao::de([12 => 1, 30 => 4]));
        $this->assertSame('v7*2', ChaveDeComposicao::de([7 => 2]));
    }

    public static function chaves(): array
    {
        return [
            'duas variações'             => ['v12*1+v30*4', true],
            'três variações'             => ['v1*1+v2*1+v3*1', true],
            // 09/10/2026: o "Montar kit" aceita até 6 produtos; o teto da chave subiu de 3 para 6.
            'quatro variações'           => ['v1*1+v2*1+v3*1+v4*1', true],
            'seis variações (teto)'      => ['v1*1+v2*1+v3*1+v4*1+v5*2+v6*1', true],
            'sete variações'             => ['v1*1+v2*1+v3*1+v4*1+v5*1+v6*1+v7*1', false],
            'seis com ids de 8 dígitos'  => ['v12345678*999+v12345679*1+v12345680*1+v12345681*1+v12345682*1+v12345683*1', true],
            'prefixo errado'             => ['x12*1', false],
            'termina em mais'            => ['v12*1+', false],
            'vazia'                      => ['', false],
            'mais de 100 caracteres'     => ['v' . str_repeat('1', 99) . '*1', false],
            'injeção ao redor'           => ["v1*1\nv2*1", false],
        ];
    }

    #[DataProvider('chaves')]
    public function test_valida(string $chave, bool $esperado): void
    {
        $this->assertSame($esperado, ChaveDeComposicao::valida($chave));
    }

    public function test_itens_e_o_inverso_de_de(): void
    {
        $this->assertSame([12 => 1, 30 => 4], ChaveDeComposicao::itens('v12*1+v30*4'));
        $this->assertSame([], ChaveDeComposicao::itens('lixo'));
        $this->assertSame([5 => 3], ChaveDeComposicao::itens(ChaveDeComposicao::de([5 => 3])));

        $seis = [9 => 1, 3 => 4, 7 => 1, 1 => 2, 5 => 1, 11 => 1];
        $chave = ChaveDeComposicao::de($seis);
        ksort($seis);
        $this->assertSame($seis, ChaveDeComposicao::itens($chave));
        $this->assertSame([], ChaveDeComposicao::itens(ChaveDeComposicao::de([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1])));
    }

    public function test_a_expressao_da_rota_e_a_mesma_da_classe(): void
    {
        $this->assertSame('/^v\d+\*\d+(\+v\d+\*\d+){0,5}$/D', ChaveDeComposicao::expressao());
        $this->assertSame(6, ChaveDeComposicao::MAXIMO_COMPONENTES);
    }
}
