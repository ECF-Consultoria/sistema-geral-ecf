<?php

namespace Tests\Unit\Publicador\Portal;

use App\Support\Publicador\Portal\VariantesPorCor;
use App\Support\Publicador\Variacao\ChaveCanonica;
use PHPUnit\Framework\TestCase;

/**
 * Planejamento × Fase N (09/10/2026): a variante do kit é a cor do Portal pelo NOME do valor do eixo,
 * com a régua do Sincronizar. O vínculo é derivado, nunca gravado.
 */
class VariantesPorCorTest extends TestCase
{
    private static function v(string $chave, array $nomes, bool $orfa = false): array
    {
        return ['chave' => $chave, 'nomes' => $nomes, 'orfa' => $orfa];
    }

    public function test_casa_cada_variante_com_a_sua_cor_sem_caixa_nem_acento(): void
    {
        $r = VariantesPorCor::casar(
            [self::v('COLOR=id:1', ['Preto']), self::v('COLOR=id:2', ['Azul Marinho']), self::v('COLOR=id:3', ['Avelã'])],
            [10 => 'PRETO', 11 => 'azul  marinho', 12 => 'avela'],
        );

        $this->assertSame(['COLOR=id:1' => 10, 'COLOR=id:2' => 11, 'COLOR=id:3' => 12], $r);
    }

    public function test_variante_unica_casa_com_o_produto_de_uma_cor_so(): void
    {
        $this->assertSame([ChaveCanonica::UNICA => 7], VariantesPorCor::casar([self::v(ChaveCanonica::UNICA, [])], [7 => '']));
        $this->assertSame([], VariantesPorCor::casar([self::v(ChaveCanonica::UNICA, [])], [7 => 'Preto', 8 => 'Azul']), 'várias cores: a única não é nenhuma delas');
    }

    public function test_orfa_dois_eixos_e_cor_que_nao_existe_nao_casam(): void
    {
        $r = VariantesPorCor::casar(
            [self::v('a', ['Preto'], orfa: true), self::v('b', ['Azul', 'P']), self::v('c', ['Verde']), self::v('d', ['Branco'])],
            [1 => 'Preto', 2 => 'Azul', 3 => 'Branco'],
        );

        $this->assertSame(['d' => 3], $r);
    }

    public function test_cada_cor_casa_uma_vez_so(): void
    {
        $r = VariantesPorCor::casar([self::v('a', ['Preto']), self::v('b', ['preto'])], [1 => 'Preto']);

        $this->assertSame(['a' => 1], $r, 'a segunda variante de mesmo nome não ganha a mesma cor');
    }

    public function test_sem_cores_ou_sem_variantes_nada_casa(): void
    {
        $this->assertSame([], VariantesPorCor::casar([], [1 => 'Preto']));
        $this->assertSame([], VariantesPorCor::casar([self::v('a', ['Preto'])], []));
    }
}
