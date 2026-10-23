<?php

namespace Tests\Unit;

use App\Services\Portal\Estrutura\Produtos\TermosDoTituloNaPlanilha;
use PHPUnit\Framework\TestCase;

/**
 * Os títulos prontos da seta do Título no "meus produtos" (21/10/2026): o nome do produto com as palavras
 * novas de cada termo mais buscado da categoria, o título de hoje em primeiro, dentro do limite.
 */
class TermosDoTituloNaPlanilhaTest extends TestCase
{
    private const CATEGORIA = [
        'limite'  => 60,
        'termos'  => ['Mesa De Jantar 4 Lugares', 'Mesa Redonda Madeira', 'Mesa De Jantar'],
        'caminho' => ['Casa, Móveis e Decoração', 'Mesas de Jantar'],
    ];

    public function test_o_nome_com_cada_termo_primeiro_os_que_tem_palavra_do_nome(): void
    {
        // "redonda" é do nome e não da categoria: o termo com ela sobe. "mesa" e "jantar" são da categoria.
        $this->assertSame(
            ['Mesa Jantar Redonda Madeira', 'Mesa Jantar Redonda De 4 Lugares'],
            TermosDoTituloNaPlanilha::titulosProntos('Mesa Jantar Redonda', '', self::CATEGORIA),
            '"Mesa De Jantar" só acrescentaria um "De" solto: fica de fora',
        );
    }

    public function test_o_titulo_de_hoje_vem_primeiro_e_nao_se_repete(): void
    {
        $prontos = TermosDoTituloNaPlanilha::titulosProntos('Mesa Jantar Redonda', 'mesa jantar redonda madeira', self::CATEGORIA);

        $this->assertSame('Mesa Jantar Redonda Madeira', $prontos[0], 'o título de hoje, com a maiúscula de cada palavra');
        $this->assertSame(1, count(array_keys($prontos, 'Mesa Jantar Redonda Madeira')));
    }

    public function test_respeita_o_limite_e_o_maximo(): void
    {
        $categoria = ['limite' => 30] + self::CATEGORIA;
        foreach (TermosDoTituloNaPlanilha::titulosProntos('Mesa Jantar Redonda', '', $categoria) as $titulo) {
            $this->assertLessThanOrEqual(30, mb_strlen($titulo), $titulo);
        }
        $this->assertCount(1, TermosDoTituloNaPlanilha::titulosProntos('Mesa Jantar Redonda', '', self::CATEGORIA, 1));
    }

    public function test_sem_nome_sem_termos_ou_sem_titulo_novo_nao_ha_seta(): void
    {
        $this->assertSame([], TermosDoTituloNaPlanilha::titulosProntos('', '', self::CATEGORIA));
        $this->assertSame([], TermosDoTituloNaPlanilha::titulosProntos('Mesa Jantar', '', ['termos' => []] + self::CATEGORIA));
        // Só o título de hoje não é escolha: a seta com uma opção só não aparece.
        $this->assertSame([], TermosDoTituloNaPlanilha::titulosProntos('Mesa', 'Mesa Azul', ['termos' => ['Mesa']] + self::CATEGORIA));
    }

    public function test_a_validacao_so_sugere_e_diz_como_usar(): void
    {
        $v = TermosDoTituloNaPlanilha::validacao("'Listas'!\$A\$2:\$A\$4", false, 60);

        $this->assertSame('list', $v->getType());
        $this->assertFalse($v->getShowErrorMessage(), 'o título digitado, fora da lista, também vale');
        $this->assertTrue($v->getShowInputMessage());
        $this->assertStringContainsString('termos mais buscados', $v->getPrompt());
        $this->assertStringContainsString('Até 60 caracteres', $v->getPrompt());
        $this->assertLessThanOrEqual(255, mb_strlen($v->getPrompt()), 'o Excel corta a dica em 255');
        $this->assertLessThanOrEqual(255, mb_strlen(TermosDoTituloNaPlanilha::comoUsarASeta(true, 255)));
        $this->assertStringContainsString('títulos prontos', TermosDoTituloNaPlanilha::validacao('x', true, null)->getPrompt());
    }
}
