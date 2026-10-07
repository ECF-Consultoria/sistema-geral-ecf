<?php

namespace Tests\Unit\Publicador\Payload;

use App\Support\Publicador\Payload\OrdemCapaPorAlvo;
use PHPUnit\Framework\TestCase;

/**
 * Função pura de rotação de capa por alvo (D6, CAPA-01..04). Cobre a regra
 * desenhada no plano 168-01: índice 0 nunca rotaciona, índice N rotaciona N
 * posições à esquerda, nunca duplica/descarta foto, é determinística.
 */
class OrdemCapaPorAlvoTest extends TestCase
{
    public function test_indice_zero_nunca_rotaciona(): void
    {
        $this->assertSame(['a1', 'a2'], OrdemCapaPorAlvo::aplicar(['a1', 'a2'], 0));
    }

    public function test_indice_um_rotaciona_uma_posicao(): void
    {
        $this->assertSame(['a2', 'a1'], OrdemCapaPorAlvo::aplicar(['a1', 'a2'], 1));
    }

    public function test_indice_dois_rotaciona_duas_posicoes_generaliza_para_mais_de_dois_alvos(): void
    {
        $this->assertSame(['a3', 'a1', 'a2'], OrdemCapaPorAlvo::aplicar(['a1', 'a2', 'a3'], 2));
    }

    public function test_uma_foto_so_nao_rotaciona(): void
    {
        $this->assertSame(['a1'], OrdemCapaPorAlvo::aplicar(['a1'], 1));
    }

    public function test_lista_vazia_nao_rotaciona(): void
    {
        $this->assertSame([], OrdemCapaPorAlvo::aplicar([], 1));
    }

    public function test_nunca_duplica_nem_descarta_foto(): void
    {
        foreach ([['a1', 'a2'], ['a1', 'a2', 'a3'], ['a1'], []] as $fotos) {
            foreach ([0, 1, 2, 3] as $indice) {
                $this->assertCount(count($fotos), OrdemCapaPorAlvo::aplicar($fotos, $indice));
                $this->assertEqualsCanonicalizing($fotos, OrdemCapaPorAlvo::aplicar($fotos, $indice));
            }
        }
    }

    public function test_e_deterministica(): void
    {
        $this->assertSame(
            OrdemCapaPorAlvo::aplicar(['a1', 'a2', 'a3'], 1),
            OrdemCapaPorAlvo::aplicar(['a1', 'a2', 'a3'], 1),
        );
    }
}
