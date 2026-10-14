<?php

namespace Tests\Unit\Bling;

use App\Services\Bling\SaldoDoBling;
use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
use PHPUnit\Framework\TestCase;
use Tests\Support\BlingFixtures;

/**
 * Prova de `SaldoDoBling::normalizar()` sem rede e sem banco (`PHPUnit\Framework\TestCase` puro —
 * mesma régua do `TradutorBlingParaPortalTest`).
 */
final class SaldoDoBlingTest extends TestCase
{
    public function test_saldo_virtual_sem_depositos_escolhidos_usa_o_total_virtual(): void
    {
        $saldos = BlingFixtures::ler('estoques-saldos')['data'];
        $comSaldoPositivo = $saldos[0]; // saldoVirtualTotal: 40, saldoFisicoTotal: 42

        $r = SaldoDoBling::normalizar($comSaldoPositivo, null, SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertSame(40, $r['estoque']);
    }

    public function test_saldo_fisico_sem_depositos_escolhidos_usa_o_total_fisico(): void
    {
        $saldos = BlingFixtures::ler('estoques-saldos')['data'];
        $comSaldoPositivo = $saldos[0]; // saldoFisicoTotal: 42

        $r = SaldoDoBling::normalizar($comSaldoPositivo, null, SaldoDoBling::PREFERENCIA_FISICO);

        $this->assertSame(42, $r['estoque']);
    }

    public function test_depositos_escolhidos_somam_so_os_depositos_da_lista(): void
    {
        $saldoDoProduto = [
            'saldoVirtualTotal' => 112,
            'saldoFisicoTotal'  => 114,
            'depositos'         => [
                ['id' => 10, 'saldoVirtual' => 5, 'saldoFisico' => 6],
                ['id' => 20, 'saldoVirtual' => 7, 'saldoFisico' => 8],
                ['id' => 30, 'saldoVirtual' => 100, 'saldoFisico' => 100],
            ],
        ];

        $r = SaldoDoBling::normalizar($saldoDoProduto, '10,20', SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertSame(12, $r['estoque']); // 5 + 7, ignorando o depósito 30
    }

    public function test_saldo_negativo_nunca_grava_negativo(): void
    {
        $saldos = BlingFixtures::ler('estoques-saldos')['data'];
        $comSaldoNegativo = $saldos[1]; // saldoVirtualTotal: -3, saldoFisicoTotal: -3

        $r = SaldoDoBling::normalizar($comSaldoNegativo, null, SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertSame(0, $r['estoque']);
    }

    /** Asserção NEGATIVA: produto ausente devolve NULL, nunca 0 — são estados diferentes (T-184-27). */
    public function test_produto_ausente_da_resposta_devolve_null_e_nao_zero(): void
    {
        $r = SaldoDoBling::normalizar([], null, SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertNull($r['estoque']);
        $this->assertNotSame(0, $r['estoque']);
    }

    /** Asserção NEGATIVA: depósito escolhido que não existe na resposta devolve NULL, nunca 0. */
    public function test_deposito_escolhido_que_nao_existe_na_resposta_devolve_null_com_aviso(): void
    {
        $saldos = BlingFixtures::ler('estoques-saldos')['data'];
        $comSaldoPositivo = $saldos[0]; // depósitos 101 e 102, nunca o 99

        $r = SaldoDoBling::normalizar($comSaldoPositivo, '99', SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertNull($r['estoque']);
        $this->assertNotSame(0, $r['estoque']);
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_saldo_acima_do_teto_e_cortado_no_teto_com_aviso(): void
    {
        $saldoDoProduto = ['saldoVirtualTotal' => NormalizadorDeLinha::MAX_ESTOQUE + 100];

        $r = SaldoDoBling::normalizar($saldoDoProduto, null, SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertSame(NormalizadorDeLinha::MAX_ESTOQUE, $r['estoque']);
        $this->assertNotEmpty($r['avisos']);
    }

    /** Piso, nunca arredondamento: vender o que não tem é pior que deixar de vender uma unidade. */
    public function test_saldo_fracionario_e_piso_para_o_inteiro(): void
    {
        $saldoDoProduto = ['saldoVirtualTotal' => 12.7];

        $r = SaldoDoBling::normalizar($saldoDoProduto, null, SaldoDoBling::PREFERENCIA_VIRTUAL);

        $this->assertSame(12, $r['estoque']);
    }

    public function test_depositos_para_ignorar_saem_da_soma_quando_nenhum_deposito_e_escolhido(): void
    {
        $saldos     = BlingFixtures::ler('estoques-saldos')['data'];
        $depositos  = BlingFixtures::ler('depositos')['data'];
        $comSaldoPositivo = $saldos[0]; // depósito 101 (saldoVirtual 28) + 102 (saldoVirtual 12) = 40

        $idsAIgnorar = SaldoDoBling::depositosParaIgnorar($depositos);
        $this->assertSame([102], $idsAIgnorar); // só o 102 tem desconsiderarSaldo: true

        $r = SaldoDoBling::normalizar($comSaldoPositivo, null, SaldoDoBling::PREFERENCIA_VIRTUAL, $idsAIgnorar);

        $this->assertSame(28, $r['estoque']); // 40 - 12 (depósito 102 descontado)
    }
}
