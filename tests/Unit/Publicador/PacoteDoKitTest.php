<?php

namespace Tests\Unit\Publicador;

use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Support\Publicador\PacoteDoKit;
use App\Support\Publicador\Portal\PortalValorDeAtributo;
use PHPUnit\Framework\TestCase;

/**
 * 10/10/2026 — o Kit 2 da Poltrona Opala foi publicado com a caixa de UMA poltrona (90 × 85 × 70 cm, 12 kg).
 * O usuário: "se o peso é 12 kg, deveria ir para 24; se a altura é 50, deveria ir para 100".
 *
 * A conta do kit é a mesma que o Portal já faz para um conjunto: caixas empilhadas.
 */
class PacoteDoKitTest extends TestCase
{
    public function test_altura_e_peso_crescem_com_a_quantidade_e_comprimento_e_largura_nao(): void
    {
        foreach (['SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WEIGHT', 'PACKAGE_HEIGHT', 'PACKAGE_WEIGHT'] as $id) {
            $this->assertTrue(PacoteDoKit::cresce($id), $id);
        }
        // Largura e comprimento são os da unidade; as medidas do PRODUTO (a ficha) não são do pacote.
        foreach (['SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH', 'PACKAGE_WIDTH', 'PACKAGE_LENGTH', 'HEIGHT', 'WEIGHT', 'WIDTH', 'BRAND'] as $id) {
            $this->assertFalse(PacoteDoKit::cresce($id), $id);
        }
    }

    public function test_o_exemplo_do_usuario_peso_12_vira_24_e_altura_50_vira_100(): void
    {
        $this->assertSame('24 kg', PacoteDoKit::texto('12 kg', 2));
        $this->assertSame('100 cm', PacoteDoKit::texto('50 cm', 2));
        // O formato que o rascunho guarda: centímetros e gramas inteiros.
        $this->assertSame('180 cm', PacoteDoKit::texto('90 cm', 2));
        $this->assertSame('24000 g', PacoteDoKit::texto('12000 g', 2));
        $this->assertSame('36000 g', PacoteDoKit::texto('12000 g', 3));
        $this->assertSame(12000.0, PacoteDoKit::numero(6000.0, 2));
    }

    public function test_decimal_com_virgula_ou_ponto_sem_zeros_sobrando(): void
    {
        $this->assertSame('3 kg', PacoteDoKit::texto('1,5 kg', 2));
        $this->assertSame('2.25 m', PacoteDoKit::texto('0.75 m', 3));
        $this->assertSame('90 cm', PacoteDoKit::texto('  45  cm ', 2));
        $this->assertSame('24000 g', PacoteDoKit::texto('12000g', 2));
        $this->assertSame('180', PacoteDoKit::texto('90', 2), 'sem unidade continua sem unidade');
    }

    public function test_o_que_nao_da_para_ler_volta_como_veio(): void
    {
        foreach (['abc', '', '90 cm x 2', 'cerca de 90 cm', '-5 cm'] as $valor) {
            $this->assertSame($valor, PacoteDoKit::texto($valor, 2), $valor);
        }
        $this->assertNull(PacoteDoKit::texto(null, 2));
        $this->assertNull(PacoteDoKit::numero(null, 2));
        // Uma unidade não é kit.
        $this->assertSame('90 cm', PacoteDoKit::texto('90 cm', 1));
        $this->assertSame(5.0, PacoteDoKit::numero(5.0, 1));
    }

    /**
     * A trava contra as duas contas se separarem: N caixas iguais empilhadas pela regra do Portal
     * (`LogisticaProduto::pacote`) dão exatamente o que o kit grava. Se a regra de lá mudar, este teste
     * avisa que o kit ficou para trás.
     */
    public function test_e_a_mesma_conta_que_o_portal_faz_para_n_caixas_iguais(): void
    {
        $caixa = ['c' => 70, 'l' => 85, 'a' => 90, 'kg' => 12];
        $daUnidade = PortalValorDeAtributo::pacoteParaAtributos(LogisticaProduto::pacote([$caixa]));

        foreach ([2, 3, 5] as $n) {
            $doPortal = PortalValorDeAtributo::pacoteParaAtributos(LogisticaProduto::pacote(array_fill(0, $n, $caixa)));

            $doKit = [];
            foreach ($daUnidade as $id => $valor) {
                $doKit[$id] = PacoteDoKit::cresce($id) ? PacoteDoKit::texto($valor, $n) : $valor;
            }

            $this->assertSame($doPortal, $doKit, "kit de {$n}");
        }

        // O caso real: 2 poltronas de 90 × 85 × 70 cm e 12 kg.
        $this->assertSame(
            ['SELLER_PACKAGE_LENGTH' => '70 cm', 'SELLER_PACKAGE_WIDTH' => '85 cm', 'SELLER_PACKAGE_HEIGHT' => '180 cm', 'SELLER_PACKAGE_WEIGHT' => '24000 g'],
            array_map(fn (string $id) => PacoteDoKit::cresce($id) ? PacoteDoKit::texto($daUnidade[$id], 2) : $daUnidade[$id], array_combine(array_keys($daUnidade), array_keys($daUnidade))),
        );
    }
}
