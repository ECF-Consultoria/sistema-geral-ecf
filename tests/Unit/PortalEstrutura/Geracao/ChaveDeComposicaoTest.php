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
            'três variações (teto)'      => ['v1*1+v2*1+v3*1', true],
            'quatro variações'           => ['v1*1+v2*1+v3*1+v4*1', false],
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
    }
}
