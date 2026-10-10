<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\PalavrasChaveService;
use App\Support\Publicador\RegrasDoTitulo;
use App\Support\Publicador\TermosVetados;
use Tests\TestCase;

/**
 * Termos que o Mercado Livre veta (10/10/2026): o anúncio de teste da #459 foi pausado por "criado-mudo" no título
 * que a IA escreveu (infração de linguagem). A IA troca pelo aceito; a conferência trava o digitado (V-TIT-05/V-DES-05,
 * no `BloqueiosSemSchemaTest`).
 */
class TermosVetadosTest extends TestCase
{
    public function test_acha_o_termo_com_qualquer_caixa_acento_e_separador_e_nunca_pedaco_de_palavra(): void
    {
        $this->assertSame(['criado-mudo'], TermosVetados::encontrar('Mesa Cabeceira Munique Criado Mudo Madeira'));
        $this->assertSame(['criado-mudo'], TermosVetados::encontrar('CRIADO-MUDO com gaveta'));
        $this->assertSame(['criado-mudo'], TermosVetados::encontrar('criádo mudo'), 'acento a mais não escapa');
        $this->assertSame(['criados-mudos'], TermosVetados::encontrar('Par de criados-mudos'), 'o plural é outro termo');
        $this->assertSame([], TermosVetados::encontrar('Mesa de cabeceira com gaveta'));
        $this->assertSame([], TermosVetados::encontrar('recriado mudou'), 'pedaço de outra palavra não conta');
        $this->assertSame([], TermosVetados::encontrar(null));
    }

    public function test_troca_pelo_aceito_seguindo_a_caixa_do_original(): void
    {
        $this->assertSame('Mesa Cabeceira Munique Mesa De Cabeceira Madeira', TermosVetados::trocar('Mesa Cabeceira Munique Criado Mudo Madeira'));
        $this->assertSame('mesa de cabeceira de madeira', TermosVetados::trocar('criado-mudo de madeira'));
        $this->assertSame('Lindo MESA DE CABECEIRA', TermosVetados::trocar('Lindo CRIADO-MUDO'));
        $this->assertSame('Mesa de cabeceira com gaveta', TermosVetados::trocar('Criado-mudo com gaveta'));
        $this->assertSame('Mesas de cabeceira em par', TermosVetados::trocar('Criados-mudos em par'));
        $this->assertSame('Recriado mudou', TermosVetados::trocar('Recriado mudou'));
        $this->assertSame('mesa de cabeceira', TermosVetados::troca('criado-mudo'));
    }

    public function test_o_titulo_e_o_modelo_da_ia_saem_sem_o_termo(): void
    {
        $titulo = RegrasDoTitulo::limpar('Mesa Cabeceira Munique Criado Mudo Madeira Gaveta Quarto', [], '');
        $this->assertSame([], TermosVetados::encontrar($titulo), $titulo);
        $this->assertStringContainsString('Mesa De Cabeceira', $titulo);

        $modelo = PalavrasChaveService::ajustarModelo('criado mudo quarto, criado-mudo pequeno, mesinha lateral');
        $this->assertSame([], TermosVetados::encontrar($modelo), $modelo);
        $this->assertStringContainsString('mesa de cabeceira quarto', $modelo);
    }

    public function test_termo_novo_entra_pela_config(): void
    {
        config(['publicador.termos_vetados' => [...TermosVetados::PADRAO, 'palavra feia' => 'palavra boa']]);

        $this->assertSame(['palavra feia'], TermosVetados::encontrar('Uma Palavra-Feia aqui'));
        $this->assertSame('Uma Palavra Boa aqui', TermosVetados::trocar('Uma Palavra Feia aqui'));
        $this->assertSame(['criado-mudo'], TermosVetados::encontrar('criado mudo'), 'os conhecidos continuam');
    }
}
