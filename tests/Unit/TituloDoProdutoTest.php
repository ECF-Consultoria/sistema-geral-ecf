<?php

namespace Tests\Unit;

use App\Support\Publicador\Payload\Alvo;
use App\Support\TituloDoProduto;
use PHPUnit\Framework\TestCase;

/**
 * A 1ª letra do título é sempre maiúscula, inclusive acentuada; o resto não muda (19/10/2026).
 * A mesma regra da tela (`tests/js/titulo-do-produto.test.js`).
 */
class TituloDoProdutoTest extends TestCase
{
    public function test_a_primeira_letra_vira_maiuscula_e_o_resto_fica(): void
    {
        $this->assertSame('Cadeira gamer', TituloDoProduto::capitalizar('cadeira gamer'));
        $this->assertSame('Ébano', TituloDoProduto::capitalizar('ébano'));
        $this->assertSame('Ônix preto', TituloDoProduto::capitalizar('ônix preto'));
        $this->assertSame('Cadeira GAMER pRETA', TituloDoProduto::capitalizar('Cadeira GAMER pRETA'));
        $this->assertSame('Mesa 160x90 MDF', TituloDoProduto::capitalizar('mesa 160x90 MDF'));
    }

    public function test_numero_vazio_e_nulo_ficam_como_estao(): void
    {
        $this->assertSame('2 cadeiras', TituloDoProduto::capitalizar('2 cadeiras'));
        $this->assertSame('', TituloDoProduto::capitalizar(''));
        $this->assertNull(TituloDoProduto::capitalizar(null));
        $this->assertSame('  Poltrona', TituloDoProduto::capitalizar('  poltrona'));
    }

    public function test_o_alvo_do_publicador_sai_sempre_com_a_maiuscula(): void
    {
        $this->assertSame('Cadeira gamer', (new Alvo('gold_special', 'cadeira gamer'))->titulo);
        $this->assertSame('Ébano', (new Alvo('gold_pro', 'ébano', false))->titulo);
        $this->assertNull((new Alvo('gold_special', null))->titulo);
    }
}
