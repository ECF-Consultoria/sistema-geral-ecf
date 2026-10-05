<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\VolumesTexto;
use PHPUnit\Framework\TestCase;

class VolumesTextoTest extends TestCase
{
    public function test_dois_volumes_no_formato_da_planilha(): void
    {
        $r = VolumesTexto::interpretar("186\u{00D7}43\u{00D7}12 \u{00B7} 27.8 | 97\u{00D7}42\u{00D7}12 \u{00B7} 12.1");

        $this->assertTrue($r['valido']);
        $this->assertSame([
            ['c' => 186.0, 'l' => 43.0, 'a' => 12.0, 'kg' => 27.8],
            ['c' => 97.0, 'l' => 42.0, 'a' => 12.0, 'kg' => 12.1],
        ], $r['volumes']);
    }

    public function test_variacoes_de_escrita(): void
    {
        $r = VolumesTexto::interpretar('65,5x47x10,5 - 9kg');
        $this->assertTrue($r['valido']);
        $this->assertSame([['c' => 65.5, 'l' => 47.0, 'a' => 10.5, 'kg' => 9.0]], $r['volumes']);

        $r = VolumesTexto::interpretar("10*20*30 5;1X2X3 0,5\n4x5x6 kg 2");
        $this->assertTrue($r['valido']);
        $this->assertCount(3, $r['volumes']);
    }

    public function test_vazio_e_sem_medidas_sao_validos_sem_volumes(): void
    {
        foreach (['', '  ', 'SEM MEDIDAS', 'sem medidas', null] as $t) {
            $this->assertSame(['volumes' => [], 'valido' => true], VolumesTexto::interpretar($t));
        }
    }

    public function test_lixo_e_medida_zero_sao_invalidos(): void
    {
        foreach (['abc', "10\u{00D7}20 \u{00B7} 3", '10x20x30 · 0', '0x1x1 · 2', '10x20x30'] as $t) {
            $this->assertSame(['volumes' => [], 'valido' => false], VolumesTexto::interpretar($t), $t);
        }
    }

    public function test_formatar_usa_virgula_e_sem_zeros_a_direita(): void
    {
        $txt = VolumesTexto::formatar([
            ['c' => 186, 'l' => 43, 'a' => 12, 'kg' => 27.8],
            ['c' => 97, 'l' => 42, 'a' => 12, 'kg' => 12.1],
        ]);

        $this->assertSame("186\u{00D7}43\u{00D7}12 \u{00B7} 27,8 | 97\u{00D7}42\u{00D7}12 \u{00B7} 12,1", $txt);
    }
}
