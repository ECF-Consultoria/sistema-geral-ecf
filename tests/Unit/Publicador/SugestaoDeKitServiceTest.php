<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\SugestaoDeKitService;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-03 (§6 da ETAPA-3): a parte PURA do `SugestaoDeKitService`.
 *
 * O que está em jogo: o usuário confirma vínculos, nunca corrige palpites errados.
 * Por isso cada regra aqui é uma recusa — substring livre não casa, kit misto não
 * casa, ambiguidade não casa. Os dois literais do critério de aceite da §9
 * (`CAD-CB2` sugere, `Combit 4 Cadeira Escritório + 1 MESA REDONDA` não) estão
 * provados aqui na camada de heurística e em `SugestaoDeKitPortalTest` na camada
 * de fato do Portal.
 */
class SugestaoDeKitServiceTest extends TestCase
{
    // ─── normalizar ───

    public function test_normalizar_tira_acento_caixa_e_espaco_sobrando(): void
    {
        $this->assertSame('cadeira escritorio', SugestaoDeKitService::normalizar('  Cadeira   Escritório  '));
        $this->assertSame('cadeira escritorio', SugestaoDeKitService::normalizar('CADEIRA ESCRITORIO'));
    }

    // ─── porSku ───

    public function test_por_sku_casa_prefixo_seguido_de_separador(): void
    {
        $this->assertTrue(SugestaoDeKitService::porSku('CAD-CB2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD_KIT2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD.2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD 2', 'CAD'));
        $this->assertTrue(SugestaoDeKitService::porSku('CAD/2', 'CAD'));
    }

    public function test_por_sku_nao_casa_substring_livre_nem_o_proprio_sku(): void
    {
        $this->assertFalse(SugestaoDeKitService::porSku('CADEIRA', 'CAD'), 'prefixo sem separador nunca casa');
        $this->assertFalse(SugestaoDeKitService::porSku('CAD', 'CAD'), 'o proprio sku nao e kit de si mesmo');
        $this->assertFalse(SugestaoDeKitService::porSku('MESA-CB2', 'CAD'));
        $this->assertFalse(SugestaoDeKitService::porSku('XCAD-CB2', 'CAD'), 'prefixo tem de ser no comeco');
    }

    public function test_por_sku_ignora_caixa_e_sku_vazio(): void
    {
        $this->assertTrue(SugestaoDeKitService::porSku('cad-cb2', 'CAD'));
        $this->assertFalse(SugestaoDeKitService::porSku('CAD-CB2', ''), 'sku vazio nao casa com nada');
        $this->assertFalse(SugestaoDeKitService::porSku('', 'CAD'));
    }

    // ─── porNome ───

    public function test_por_nome_casa_os_tres_padroes_de_prefixo_e_devolve_o_n(): void
    {
        $this->assertSame(2, SugestaoDeKitService::porNome('Combo 2 Cadeira Escritório', 'Cadeira Escritório'));
        $this->assertSame(4, SugestaoDeKitService::porNome('Kit 4 Cadeira', 'Cadeira'));
        $this->assertSame(4, SugestaoDeKitService::porNome('4 unidades Cadeira', 'Cadeira'));
        $this->assertSame(6, SugestaoDeKitService::porNome('6 un Cadeira', 'Cadeira'));
    }

    public function test_por_nome_compara_sem_acento_e_sem_caixa(): void
    {
        $this->assertSame(2, SugestaoDeKitService::porNome('KIT 2 CADEIRA ESCRITORIO', 'Cadeira Escritório'));
        $this->assertSame(3, SugestaoDeKitService::porNome('kit 3 - Cadeira Escritório', 'cadeira escritorio'));
        $this->assertSame(3, SugestaoDeKitService::porNome('Kit 3: Cadeira', 'Cadeira'));
    }

    public function test_por_nome_nao_casa_sem_prefixo_nem_com_outro_produto(): void
    {
        $this->assertNull(SugestaoDeKitService::porNome('Cadeira Escritório', 'Cadeira Escritório'), 'sem prefixo nao e kit');
        $this->assertNull(SugestaoDeKitService::porNome('Kit 2 Mesa Redonda', 'Cadeira'), 'o resto tem de comecar pelo nome do base');
        $this->assertNull(SugestaoDeKitService::porNome('Cadeira Kit 2', 'Cadeira'), 'o prefixo e ancorado no comeco');
        $this->assertNull(SugestaoDeKitService::porNome('Kit Cadeira', 'Cadeira'), 'sem N nao ha quantidade para sugerir');
    }

    public function test_por_nome_recusa_kit_de_uma_unidade(): void
    {
        // Kit de 1 unidade não é kit, e `quantidade_kit = 1` é o valor das BASES
        // (unique `pubprod_base_qtd_uq` do 175-01). Sugerir isso seria palpite errado.
        $this->assertNull(SugestaoDeKitService::porNome('Kit 1 Cadeira', 'Cadeira'));
        $this->assertNull(SugestaoDeKitService::porNome('Kit 0 Cadeira', 'Cadeira'));
    }

    // ─── ehMisto ───

    public function test_eh_misto_pega_o_literal_da_spec_e_a_conjuncao(): void
    {
        $this->assertTrue(SugestaoDeKitService::ehMisto('Combit 4 Cadeira Escritório + 1 MESA REDONDA'));
        $this->assertTrue(SugestaoDeKitService::ehMisto('Kit 2 Cadeira e Mesa'));
        $this->assertTrue(SugestaoDeKitService::ehMisto('Pote e Tampa'));
    }

    public function test_eh_misto_nao_dispara_em_palavra_que_so_contem_e(): void
    {
        $this->assertFalse(SugestaoDeKitService::ehMisto('Kit 2 Mesa de Jantar'), '"de" nao e a conjuncao');
        $this->assertFalse(SugestaoDeKitService::ehMisto('Kit 2 Cadeira Escritório'));
        $this->assertFalse(SugestaoDeKitService::ehMisto('Combo 2 Estante'), '"e" no comeco de palavra nao conta');
    }

    // ─── escolher ───

    public function test_escolher_devolve_so_quando_ha_exatamente_um_candidato(): void
    {
        $a = ['base_id' => 1, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => 2, 'origem' => 'sku'];
        $b = ['base_id' => 2, 'base_sku' => 'MES', 'base_nome' => 'Mesa', 'quantidade' => 2, 'origem' => 'sku'];

        $this->assertNull(SugestaoDeKitService::escolher([]));
        $this->assertSame($a, SugestaoDeKitService::escolher([$a]));
        $this->assertNull(SugestaoDeKitService::escolher([$a, $b]), 'ambiguidade nunca vira palpite');
    }

    public function test_escolher_trata_o_mesmo_base_casado_duas_vezes_como_um(): void
    {
        // O mesmo base casado por SKU E por nome é UM candidato, não dois.
        $porNome = ['base_id' => 7, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => 2, 'origem' => 'nome'];
        $porSku = ['base_id' => 7, 'base_sku' => 'CAD', 'base_nome' => 'Cadeira', 'quantidade' => null, 'origem' => 'sku'];

        $escolhido = SugestaoDeKitService::escolher([$porNome, $porSku]);

        $this->assertNotNull($escolhido);
        $this->assertSame(7, $escolhido['base_id']);
    }

    // ─── Os dois literais da §9, na camada de heurística ───

    public function test_aceite_da_spec_cad_cb2_casa_com_cad_e_o_combit_misto_nao_casa_com_nada(): void
    {
        // 1) `CAD-CB2` é kit de `CAD` (quantidade vazia: o SKU não diz o N).
        $this->assertTrue(SugestaoDeKitService::porSku('CAD-CB2', 'CAD'));
        $this->assertNull(SugestaoDeKitService::porNome('Kit de CAD', 'CAD'), 'sem N a quantidade fica vazia');

        // 2) O combit misto é recusado antes de qualquer casamento.
        $misto = 'Combit 4 Cadeira Escritório + 1 MESA REDONDA';
        $this->assertTrue(SugestaoDeKitService::ehMisto($misto));
        $this->assertNull(SugestaoDeKitService::porNome($misto, 'Cadeira Escritório'), 'nem o prefixo "Combit" casa');
    }
}
