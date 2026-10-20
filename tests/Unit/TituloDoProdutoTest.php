<?php

namespace Tests\Unit;

use App\Support\Publicador\Payload\Alvo;
use App\Support\TituloDoProduto;
use PHPUnit\Framework\TestCase;

/**
 * Toda palavra do título começa com maiúscula, inclusive acentuada e as de ligação; o resto de cada
 * palavra não muda (19/10/2026, corrigido pelo usuário em 20/10: "tem que ser todos"). A mesma regra
 * da tela (`tests/js/titulo-do-produto.test.js`).
 */
class TituloDoProdutoTest extends TestCase
{
    public function test_toda_palavra_comeca_com_maiuscula(): void
    {
        // O exemplo do usuário (20/10/2026).
        $this->assertSame('Puff Pera Com Enchimento', TituloDoProduto::capitalizar('Puff pera com enchimento'));
        $this->assertSame('Cadeira Gamer', TituloDoProduto::capitalizar('cadeira gamer'));
        $this->assertSame('Ébano', TituloDoProduto::capitalizar('ébano'));
        $this->assertSame('Poltrona Ônix De Veludo', TituloDoProduto::capitalizar('poltrona ônix de veludo'));
    }

    public function test_o_resto_de_cada_palavra_e_o_que_comeca_por_numero_ficam(): void
    {
        $this->assertSame('Mesa MDF 160x90 Branca', TituloDoProduto::capitalizar('mesa MDF 160x90 branca'));
        $this->assertSame('Cadeira GAMER PRETA', TituloDoProduto::capitalizar('cadeira GAMER PRETA'));
        $this->assertSame('2 Cadeiras', TituloDoProduto::capitalizar('2 cadeiras'));
        $this->assertSame('  Poltrona  Azul', TituloDoProduto::capitalizar('  poltrona  azul'), 'os espaços ficam (quem apara é quem grava)');
        $this->assertSame('', TituloDoProduto::capitalizar(''));
        $this->assertNull(TituloDoProduto::capitalizar(null));
    }

    public function test_o_alvo_do_publicador_sai_sempre_com_a_maiuscula_em_toda_palavra(): void
    {
        $this->assertSame('Cadeira Gamer', (new Alvo('gold_special', 'cadeira gamer'))->titulo);
        $this->assertSame('Puff Pera Com Enchimento', (new Alvo('gold_pro', 'Puff pera com enchimento', false))->titulo);
        $this->assertNull((new Alvo('gold_special', null))->titulo);
    }
}
