<?php

namespace Tests\Unit\Publicador;

use App\Services\Incubadora\Publicador\TermosMaisBuscadosService;
use App\Services\Publicador\DescricaoIaService;
use PHPUnit\Framework\TestCase;

/**
 * Demo 23/10/2026: o anúncio da #459 saiu com "Olá! Seja bem-vindo à Dev 02 Testes API." (N2), e a lista de
 * termos mais buscados de Sofás mostrou "poltrona erotica para motel" ao cliente (N3).
 */
class DemoDescricaoETermosTest extends TestCase
{
    public function test_tira_a_saudacao_do_comeco_e_a_linha_com_o_nome_da_loja(): void
    {
        $texto = "Olá! Seja bem-vindo à Dev 02 Testes API.\n\nSua sala merece conforto.\n\nObrigado por escolher a Dev 02 Testes API!\nAté logo!";

        $this->assertSame("Sua sala merece conforto.\n\nAté logo!", DescricaoIaService::semSaudacao($texto, 'Dev 02 Testes API'));
    }

    public function test_saudacao_na_mesma_linha_do_texto_sai_so_ela(): void
    {
        $this->assertSame('Cadeira executiva giratória', DescricaoIaService::semSaudacao('Olá! Cadeira executiva giratória', ''));
        $this->assertSame('Mesa firme.', DescricaoIaService::semSaudacao("Seja muito bem-vindo(a) à Loja X! Mesa firme.", ''));
    }

    public function test_ola_no_meio_do_texto_e_palavra_parecida_ficam(): void
    {
        $texto = "Oito lugares para a família.\nDiga olá ao conforto.";

        $this->assertSame($texto, DescricaoIaService::semSaudacao($texto, ''));
    }

    public function test_nome_de_loja_curto_demais_nao_apaga_linha(): void
    {
        $this->assertSame("Lado A e lado B.", DescricaoIaService::semSaudacao("Lado A e lado B.", 'A'));
    }

    public function test_termo_sexual_e_improprio_e_os_normais_nao(): void
    {
        foreach (['poltrona erotica para motel', 'cadeira de posições eróticas', 'poltronas sexuais', 'cadeira tantra', 'sex shop'] as $t) {
            $this->assertTrue(TermosMaisBuscadosService::improprio($t), $t);
        }
        foreach (['poltrona decorativa', 'sofá retrátil', 'cadeira sexta-feira', 'mesa de cabeceira', 'poltrona do papai'] as $t) {
            $this->assertFalse(TermosMaisBuscadosService::improprio($t), $t);
        }
    }
}
