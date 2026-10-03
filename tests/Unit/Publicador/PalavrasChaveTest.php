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
