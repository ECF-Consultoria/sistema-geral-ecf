<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\Portal\ComposicaoDoPortal;
use PHPUnit\Framework\TestCase;

/** Contas de composição Combo/Kit/Combit (172-04, D-07/D-12). */
class ComposicaoDoPortalTest extends TestCase
{
    private function item(?string $tipo, int $q, ?float $custo): array
    {
        return ['tipo' => $tipo, 'quantidade' => $q, 'custo' => $custo];
    }

    public function test_estoque_de_combo_e_piso_da_divisao(): void
    {
        $this->assertSame(2, ComposicaoDoPortal::estoque([['estoque' => 9, 'quantidade' => 4]]));
    }

    public function test_estoque_de_kit_e_o_menor_piso(): void
    {
        $this->assertSame(2, ComposicaoDoPortal::estoque([['estoque' => 10, 'quantidade' => 1], ['estoque' => 9, 'quantidade' => 4]]));
    }

    public function test_estoque_desconhecido_em_qualquer_componente_e_desconhecido(): void
    {
        $this->assertNull(ComposicaoDoPortal::estoque([['estoque' => 10, 'quantidade' => 1], ['estoque' => null, 'quantidade' => 2]]));
        $this->assertNull(ComposicaoDoPortal::estoque([]));
    }

    public function test_estoque_com_quantidade_zero_conta_como_um_e_zero_e_zero(): void
    {
        $this->assertSame(7, ComposicaoDoPortal::estoque([['estoque' => 7, 'quantidade' => 0]]));
        $this->assertSame(0, ComposicaoDoPortal::estoque([['estoque' => 0, 'quantidade' => 3]]));
    }

    public function test_principal_mesa_com_4_cadeiras_e_a_mesa(): void
    {
        $itens = [$this->item('cadeira', 4, 100.0), $this->item('mesa', 1, 50.0)];
        $pares = [['a' => 'cadeira', 'b' => 'mesa', 'repete' => 'a']];
        $this->assertSame(1, ComposicaoDoPortal::principal($itens, $pares));
    }

    public function test_principal_com_repete_b_e_o_lado_a(): void
    {
        $itens = [$this->item('mesa', 1, 10.0), $this->item('cadeira', 4, 500.0)];
        $pares = [['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'b']];
        $this->assertSame(0, ComposicaoDoPortal::principal($itens, $pares));
    }

    public function test_principal_acha_o_par_nos_dois_sentidos(): void
    {
        // Itens na ordem contrária à do par guardado.
        $itens = [$this->item('mesa', 1, 10.0), $this->item('cadeira', 4, 500.0)];
        $pares = [['a' => 'cadeira', 'b' => 'mesa', 'repete' => 'a']];
        $this->assertSame(0, ComposicaoDoPortal::principal($itens, $pares));
    }

    public function test_principal_com_ambos_ou_nulo_ou_sem_par_usa_maior_custo_total(): void
    {
        $itens = [$this->item('a', 1, 30.0), $this->item('b', 2, 20.0)];
        $this->assertSame(1, ComposicaoDoPortal::principal($itens, [['a' => 'a', 'b' => 'b', 'repete' => 'ambos']]));
        $this->assertSame(1, ComposicaoDoPortal::principal($itens, [['a' => 'a', 'b' => 'b', 'repete' => null]]));
        $this->assertSame(1, ComposicaoDoPortal::principal($itens, []));
    }

    public function test_principal_de_kit_de_3_sem_par_e_o_de_maior_custo_total(): void
    {
        $itens = [$this->item('gabinete', 1, 200.0), $this->item('espelho', 1, 80.0), $this->item('lixeira', 1, 30.0)];
        $this->assertSame(0, ComposicaoDoPortal::principal($itens, []));
    }

    public function test_principal_empate_fica_com_o_menor_indice(): void
    {
        $itens = [$this->item('x', 1, 10.0), $this->item('y', 1, 10.0)];
        $this->assertSame(0, ComposicaoDoPortal::principal($itens, []));
    }

    public function test_principal_sem_custo_conhecido_e_o_indice_zero(): void
    {
        $itens = [$this->item(null, 1, null), $this->item(null, 3, null)];
        $this->assertSame(0, ComposicaoDoPortal::principal($itens, []));
    }

    public function test_pacote_do_grupo_iguais_nao_diverge(): void
    {
        $p = ['c' => 10.0, 'l' => 10.0, 'a' => 5.0, 'peso_real' => 1.0];
        $this->assertSame(['pacote' => $p, 'divergem' => false], ComposicaoDoPortal::pacoteDoGrupo([$p, $p, null]));
    }

    public function test_pacote_do_grupo_diferentes_fica_com_o_mais_pesado(): void
    {
        $leve = ['c' => 10.0, 'l' => 10.0, 'a' => 5.0, 'peso_real' => 1.0];
        $pesado = ['c' => 20.0, 'l' => 10.0, 'a' => 5.0, 'peso_real' => 3.0];
        $r = ComposicaoDoPortal::pacoteDoGrupo([$leve, $pesado]);
        $this->assertSame($pesado, $r['pacote']);
        $this->assertTrue($r['divergem']);
    }

    public function test_pacote_do_grupo_todos_nulos(): void
    {
        $this->assertSame(['pacote' => null, 'divergem' => false], ComposicaoDoPortal::pacoteDoGrupo([null, null]));
    }

    public function test_pacote_do_conjunto_reusa_a_soma_da_168(): void
    {
        $itens = [
            ['produto_id' => 1, 'produto_nome' => 'Mesa', 'quantidade' => 1, 'volumes' => [['c' => 100, 'l' => 50, 'a' => 10, 'kg' => 20]], 'custo' => 1.0],
            ['produto_id' => 2, 'produto_nome' => 'Cadeira', 'quantidade' => 2, 'volumes' => [['c' => 40, 'l' => 60, 'a' => 5, 'kg' => 3]], 'custo' => 1.0],
        ];
        $this->assertSame(['c' => 100.0, 'l' => 60.0, 'a' => 20.0, 'peso_real' => 26.0], ComposicaoDoPortal::pacoteDoConjunto($itens));
    }

    public function test_pacote_do_conjunto_com_item_sem_volumes_e_nulo(): void
    {
        $itens = [
            ['produto_id' => 1, 'produto_nome' => 'Mesa', 'quantidade' => 1, 'volumes' => [['c' => 1, 'l' => 1, 'a' => 1, 'kg' => 1]], 'custo' => null],
            ['produto_id' => 2, 'produto_nome' => 'Cadeira', 'quantidade' => 1, 'volumes' => [], 'custo' => null],
        ];
        $this->assertNull(ComposicaoDoPortal::pacoteDoConjunto($itens));
    }

    public function test_descricoes_junta_so_as_nao_vazias(): void
    {
        $this->assertSame('Mesa: x', ComposicaoDoPortal::descricoes([['nome' => 'Mesa', 'descricao' => 'x'], ['nome' => 'Cadeira', 'descricao' => '']]));
        $this->assertSame("Mesa: x\n\nCadeira: y", ComposicaoDoPortal::descricoes([['nome' => 'Mesa', 'descricao' => 'x'], ['nome' => 'Cadeira', 'descricao' => ' y ']]));
        $this->assertNull(ComposicaoDoPortal::descricoes([['nome' => 'Mesa', 'descricao' => null]]));
    }
}
