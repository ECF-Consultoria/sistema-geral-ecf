<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\Erros\MapeadorErrosMl;
use PHPUnit\Framework\TestCase;

/**
 * As partes puras da melhoria de 03/10/2026: o corte do Modelo (120) e do
 * título que a IA devolve, e o aviso 4053 tratado como ruído.
 */
class PalavrasChaveTest extends TestCase
{
    public function test_modelo_normaliza_tira_repetidos_e_corta_no_ultimo_termo_inteiro(): void
    {
        $bruto = 'Cadeira Escritório, cadeira escritorio; Cadeira Home-Office, cadeira giratória, , cadeira presidente';

        $this->assertSame('cadeira escritorio, cadeira home office, cadeira giratoria, cadeira presidente', PalavrasChaveService::ajustarModelo($bruto));
        $this->assertSame('cadeira escritorio, cadeira home office', PalavrasChaveService::ajustarModelo($bruto, 45));
        $this->assertSame('', PalavrasChaveService::ajustarModelo('   '));
    }

    public function test_modelo_nunca_passa_de_120_e_pula_o_termo_que_nao_cabe(): void
    {
        $termos = array_map(fn ($i) => "cadeira modelo numero {$i}", range(1, 20));
        $saida = PalavrasChaveService::ajustarModelo(implode(', ', $termos));
        $this->assertLessThanOrEqual(120, strlen($saida));
        // Termos de 23-24 caracteres: sobra menos que ", termo" — chegou o mais perto possível.
        $this->assertGreaterThan(120 - strlen(', cadeira modelo numero 5'), strlen($saida));

        // Um termo enorme no meio não fecha a lista: os seguintes ainda entram.
        $comGigante = 'cadeira, '.str_repeat('x', 130).', mesa';
        $this->assertSame('cadeira, mesa', PalavrasChaveService::ajustarModelo($comGigante));
    }

    public function test_modelo_descarta_termo_cujas_palavras_ja_estao_todas_no_titulo(): void
    {
        // O exemplo do usuário (08/10/2026), literal, mais três termos que trazem palavra nova.
        $titulo = 'Puff Redondo Sala Quarto Enchimento Fofao Banqueta Descanso';
        $bruto = 'puff azul, puff redondo, puff sala, puff para sala, puff azul marinho, puff redondo de chao, puff com enchimento, puff, '
            .'puff azul marinho, puff redondo de chao, puff para quarto infantil';

        $saida = PalavrasChaveService::ajustarModelo($bruto, 120, $titulo);

        $this->assertSame('puff azul, puff azul marinho, puff redondo de chao, puff para quarto infantil', $saida);
        $this->assertLessThanOrEqual(120, strlen($saida));
        foreach (explode(', ', $saida) as $termo) {
            $this->assertTrue(PalavrasChaveService::trazPalavraNova($termo, PalavrasChaveService::palavrasDoTitulo($titulo)), $termo);
        }
        // Sem título, nada é filtrado (o Modelo antes do título continua funcionando).
        $this->assertStringStartsWith('puff azul, puff redondo, puff sala', PalavrasChaveService::ajustarModelo($bruto));
    }

    public function test_palavra_nova_ignora_acento_caixa_plural_e_palavra_de_ligacao(): void
    {
        $doTitulo = PalavrasChaveService::palavrasDoTitulo('Mesas Jantar Cores Madeira / Cadeira Estofada');

        $this->assertFalse(PalavrasChaveService::trazPalavraNova('mesa de jantar', $doTitulo), 'mesas × mesa e "de" é ligação');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('cor da madeira', $doTitulo), 'cores × cor');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('cadeiras estofadas', $doTitulo), 'o plural do título, das duas partes');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('mésa para a jantár', $doTitulo));
        $this->assertTrue(PalavrasChaveService::trazPalavraNova('mesa redonda', $doTitulo));
        $this->assertTrue(PalavrasChaveService::trazPalavraNova('mesa 4 lugares', $doTitulo), 'número também é termo novo');
        $this->assertFalse(PalavrasChaveService::trazPalavraNova('de para com', $doTitulo), 'só ligação não é palavra nova');
        $this->assertSame([], PalavrasChaveService::palavrasDoTitulo('  '));
    }

    public function test_titulo_tira_caracteres_especiais_e_corta_na_palavra(): void
    {
        $this->assertSame('Cadeira Escritório Giratória Ergonômica', PalavrasChaveService::ajustarTitulo('Cadeira Escritório - Giratória (Ergonômica)!', 60));
        $this->assertSame('Cadeira Escritório', PalavrasChaveService::ajustarTitulo('Cadeira Escritório Giratória', 20));
        $this->assertSame('Supercalifragilisti', PalavrasChaveService::ajustarTitulo('Supercalifragilisticexpialidocious', 19));
    }

    public function test_aviso_4053_e_ruido_mas_o_erro_nao(): void
    {
        $this->assertTrue(MapeadorErrosMl::ehRuido(['cause_id' => 4053, 'code' => 'shipping.lost_me1_by_user', 'type' => 'warning']));
        $this->assertTrue(MapeadorErrosMl::ehRuido(['code' => 'shipping.lost_me1_by_user', 'type' => 'warning']));
        // Se um dia o ML voltar a mandar como erro (bloqueava em 10/07, N-16), aparece.
        $this->assertFalse(MapeadorErrosMl::ehRuido(['cause_id' => 4053, 'code' => 'shipping.lost_me1_by_user', 'type' => 'error']));
        $this->assertFalse(MapeadorErrosMl::ehRuido(['cause_id' => 350, 'code' => 'item.shipping.mandatory_free_shipping', 'type' => 'warning']));
        $this->assertFalse(MapeadorErrosMl::ehRuido([]));
    }
}
