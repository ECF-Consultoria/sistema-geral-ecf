<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\QuantidadesDoKit as Q;
use PHPUnit\Framework\TestCase;

/**
 * 11/10/2026 — os campos de quantidade da ficha num kit. O usuário viu no Mercado Livre um kit de 2 com
 * "Quantidade de caixas de embalagem: 2 · Quantidade de peças: 2 · Quantidade de lugares: 2 · Quantidade de
 * embalagens: 2"; o nosso Kit 2 saía dizendo "1 peça, 1 lugar", copiado da unidade.
 *
 * As definições abaixo são as da categoria Poltronas (MLB458191), como o Mercado Livre as entrega.
 */
class QuantidadesDoKitTest extends TestCase
{
    private const NUMERO = ['id' => 'PIECES_NUMBER', 'value_type' => 'number'];

    private const LUGARES = ['id' => 'SEATERS_NUMBER', 'value_type' => 'list', 'values' => [
        ['id' => '4485163', 'name' => '1'], ['id' => '4501850', 'name' => '2'], ['id' => '4412704', 'name' => '3'], ['id' => '4604555', 'name' => '4'],
        ['id' => '13237454', 'name' => '5'], ['id' => '13240402', 'name' => '6'],
    ]];

    public function test_sao_so_os_quatro_campos_que_crescem_com_o_kit(): void
    {
        foreach (['UNITS_PER_PACK', 'PACKAGING_BOXES_NUMBER', 'PIECES_NUMBER', 'SEATERS_NUMBER'] as $id) {
            $this->assertTrue(Q::ehCampo($id), $id);
        }
        // Gavetas, posições e as medidas são de cada unidade: não multiplicam.
        foreach (['DRAWERS_NUMBER', 'RECLINING_POSITIONS_NUMBER', 'NIGHTSTANDS_NUMBER', 'HEIGHT', 'SELLER_PACKAGE_HEIGHT', 'BRAND'] as $id) {
            $this->assertFalse(Q::ehCampo($id), $id);
        }
    }

    public function test_numero_e_o_da_unidade_vezes_n(): void
    {
        $this->assertSame(['value_id' => null, 'value_name' => '2', 'value_number' => null], Q::doKit(['value_name' => '1'], 2, self::NUMERO));
        $this->assertSame('6', Q::doKit(['value_name' => '2'], 3, self::NUMERO)['value_name'], 'unidade com 2 peças, kit de 3');
        // A coluna numérica acompanha quando a unidade a usa.
        $this->assertSame(['value_id' => null, 'value_name' => '4', 'value_number' => 4.0], Q::doKit(['value_name' => '2', 'value_number' => 2.0], 2, self::NUMERO));
    }

    public function test_unidade_sem_valor_ou_em_nao_se_aplica_conta_como_um(): void
    {
        // Como o rascunho da Poltrona Opala guarda "Quantidade de embalagens": value_id -1, sem nome.
        $this->assertSame(['value_id' => null, 'value_name' => '2', 'value_number' => null], Q::doKit(['value_id' => '-1', 'value_name' => null], 2, self::NUMERO));
        // Campo que a unidade nunca preencheu.
        $this->assertSame('3', Q::doKit([], 3, self::NUMERO)['value_name']);
    }

    public function test_lista_escolhe_a_opcao_do_total_pelo_id_da_categoria(): void
    {
        $umLugar = ['value_id' => '4485163', 'value_name' => '1'];

        $this->assertSame(['value_id' => '4501850', 'value_name' => '2', 'value_number' => null], Q::doKit($umLugar, 2, self::LUGARES));
        $this->assertSame('13240402', Q::doKit(['value_id' => '4412704', 'value_name' => '3'], 2, self::LUGARES)['value_id'], '3 lugares × 2 = 6');
        $this->assertSame('4412704', Q::doKit([], 3, self::LUGARES)['value_id'], 'nunca preenchido: vale N');
    }

    public function test_o_que_nao_da_para_calcular_devolve_nulo(): void
    {
        // A lista não tem a opção do total (4 lugares × 2 = 8, e a lista de exemplo vai até 6).
        $this->assertNull(Q::doKit(['value_id' => '4604555', 'value_name' => '4'], 2, self::LUGARES));
        // Lista sem o schema da categoria guardado: não se inventa id de opção.
        $this->assertNull(Q::doKit(['value_id' => '4485163', 'value_name' => '1'], 2, null));
        // Texto que não é número.
        $this->assertNull(Q::doKit(['value_name' => '1 lugar'], 2, self::NUMERO));
        $this->assertNull(Q::doKit(['value_name' => 'dois'], 2, self::NUMERO));
        // Uma unidade não é kit.
        $this->assertNull(Q::doKit(['value_name' => '1'], 1, self::NUMERO));
    }

    public function test_numero_sem_schema_guardado_ainda_multiplica(): void
    {
        $this->assertSame('2', Q::doKit(['value_name' => '1'], 2, null)['value_name']);
        $this->assertSame('2', Q::doKit(['value_id' => '-1', 'value_name' => null], 2, null)['value_name'], '"não se aplica" não é opção de lista');
    }
}
