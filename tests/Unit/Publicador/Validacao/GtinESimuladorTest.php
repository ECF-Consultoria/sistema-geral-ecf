<?php

namespace Tests\Unit\Publicador\Validacao;

use App\Support\Publicador\Validacao\Gtin;
use App\Support\Publicador\Validacao\SimuladorVoceRecebe;
use PHPUnit\Framework\TestCase;

class GtinESimuladorTest extends TestCase
{
    public function test_gtin_gs1(): void
    {
        $this->assertTrue(Gtin::valido('7896553367645'), 'o GTIN do print');
        $this->assertFalse(Gtin::valido('7896553367646'), 'TC-38: dígito errado');
        $this->assertTrue(Gtin::valido('96385074'), 'GTIN-8');
        $this->assertTrue(Gtin::valido('036000291452'), 'UPC-12');
        $this->assertTrue(Gtin::valido('17896553367642'), 'GTIN-14');
        $this->assertFalse(Gtin::valido('78965533676'), '11 dígitos');
        $this->assertFalse(Gtin::valido('789655336764A'));
        $this->assertFalse(Gtin::valido('0000000000000'), 'zeros reservados');
        $this->assertTrue(Gtin::todosValidos('7896553367645, 96385074'), 'multivalorado: separados por vírgula');
        $this->assertFalse(Gtin::todosValidos('7896553367645,7896553367646'));
    }

    public function test_tc105_voce_recebe_do_print(): void
    {
        $r = SimuladorVoceRecebe::calcular(150.0, 16.5, 62.35);

        $this->assertSame(71.15, $r['voce_recebe']);
        $this->assertSame(47.43, $r['percentual']);
        $this->assertSame(['preco' => 150.0, 'tarifa' => 16.5, 'frete' => 62.35], array_intersect_key($r, array_flip(['preco', 'tarifa', 'frete'])));
    }

    public function test_sem_frete_conhecido_ainda_calcula_e_marca_estimativa(): void
    {
        $r = SimuladorVoceRecebe::calcular(150.0, 16.5, null);

        $this->assertSame(133.5, $r['voce_recebe']);
        $this->assertFalse($r['frete_conhecido']);
    }
}
