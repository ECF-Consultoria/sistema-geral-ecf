<?php

namespace Tests\Unit\Bling;

use App\Services\Bling\TradutorBlingParaPortal;
use PHPUnit\Framework\TestCase;
use Tests\Support\BlingFixtures;

/**
 * Prova do tradutor Bling → Portal sem rede e sem banco (`PHPUnit\Framework\TestCase` puro —
 * um traço de banco acidental aqui, dos que a suíte de Feature usa, já é erro de arquitetura,
 * não só de estilo).
 */
final class TradutorBlingParaPortalTest extends TestCase
{
    // ─── Task 2: formato S (produto simples) ───

    public function test_produto_simples_vira_exatamente_uma_linha(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(1, $r['linhas']);
        $this->assertCount(0, $r['recusadas']);
    }

    public function test_linha_simples_tem_codigo_nome_e_grupo_igual_ao_codigo(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertSame($produto['codigo'], $linha['codigo']);
        $this->assertSame($produto['nome'], $linha['nome']);
        $this->assertSame($produto['codigo'], $linha['grupo']);
    }

    public function test_preco_do_bling_nao_sai_na_linha_assercao_negativa_dupla(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');
        $this->assertIsFloat($produto['preco']); // a fixture tem de ter um preço não nulo

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayNotHasKey('preco', $linha);
        $this->assertNotContains($produto['preco'], array_values($linha));
    }

    public function test_descricao_sem_html_e_com_no_maximo_5000_caracteres(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayHasKey('descricao', $linha);
        $this->assertStringNotContainsString('<', $linha['descricao']);
        $this->assertStringNotContainsString('>', $linha['descricao']);
        $this->assertLessThanOrEqual(5000, mb_strlen($linha['descricao']));
    }

    public function test_volumes_converte_dimensoes_e_peso_em_cm_e_kg(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayHasKey('volumes', $linha);
        $this->assertCount(1, $linha['volumes']);
        $volume = $linha['volumes'][0];
        $this->assertSame((float) $produto['dimensoes']['profundidade'], $volume['c']);
        $this->assertSame((float) $produto['dimensoes']['largura'], $volume['l']);
        $this->assertSame((float) $produto['dimensoes']['altura'], $volume['a']);
        $this->assertSame((float) $produto['pesoBruto'], $volume['kg']);
    }

    public function test_sem_custo_no_payload_a_chave_custo_nao_existe(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');
        unset($produto['fornecedor'], $produto['precoCusto']);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayNotHasKey('custo', $linha);
    }

    public function test_codigo_vazio_recusa_o_produto_e_nao_gera_linha(): void
    {
        $produto = BlingFixtures::ler('produto-formato-s');
        $produto['codigo'] = '';

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(0, $r['linhas']);
        $this->assertCount(1, $r['recusadas']);
        $this->assertNotSame('', $r['recusadas'][0]['motivo']);
    }
}
