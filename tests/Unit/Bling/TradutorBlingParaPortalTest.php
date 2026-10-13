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

    // ─── Task 3: formato V (produto com variações) ───

    public function test_produto_com_variacoes_vira_uma_linha_por_variacao(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(3, $r['linhas']);
        $this->assertCount(0, $r['recusadas']);
    }

    public function test_as_tres_linhas_compartilham_o_mesmo_grupo_igual_ao_codigo_do_pai(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');

        $linhas = TradutorBlingParaPortal::paraLinhas($produto)['linhas'];

        foreach ($linhas as $linha) {
            $this->assertSame($produto['codigo'], $linha['grupo']);
        }
    }

    public function test_pai_sem_codigo_usa_a_ref_da_primeira_variacao_como_grupo(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');
        $produto['codigo'] = '';

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(0, $r['recusadas']);
        foreach ($r['linhas'] as $linha) {
            $this->assertSame($produto['variacoes'][0]['codigo'], $linha['grupo']);
        }
    }

    public function test_variacao_cor_azul_vira_eixo_cor_e_valor_azul(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');

        $linhas = TradutorBlingParaPortal::paraLinhas($produto)['linhas'];
        $azul = current(array_filter($linhas, fn (array $l) => $l['codigo'] === 'CAM-BAS-AZ'));

        $this->assertNotFalse($azul);
        $this->assertSame('cor', $azul['eixo']);
        $this->assertSame('Azul', $azul['valor']);
    }

    public function test_variacao_com_codigo_vazio_e_recusada_e_as_outras_duas_seguem(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');
        $produto['variacoes'][1]['codigo'] = '';

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(2, $r['linhas']);
        $this->assertCount(1, $r['recusadas']);
    }

    public function test_codigo_repetido_entre_variacoes_recusa_a_segunda_ocorrencia(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');
        $produto['variacoes'][1]['codigo'] = $produto['variacoes'][0]['codigo'];

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(2, $r['linhas']);
        $this->assertCount(1, $r['recusadas']);
        $this->assertSame($produto['variacoes'][1]['id'], (int) $r['recusadas'][0]['bling_produto_id']);
    }

    public function test_vinculos_tem_um_item_por_variacao_com_o_pai_correto(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');

        $vinculos = TradutorBlingParaPortal::paraLinhas($produto)['vinculos'];

        $this->assertCount(3, $vinculos);
        foreach ($vinculos as $i => $vinculo) {
            $this->assertSame((string) $produto['id'], $vinculo['bling_pai_id']);
            $this->assertSame((string) $produto['variacoes'][$i]['id'], $vinculo['bling_produto_id']);
        }
    }

    public function test_sem_dimensoes_no_pai_nenhuma_linha_tem_volumes_ou_estoque(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');
        $this->assertArrayNotHasKey('dimensoes', $produto);

        $linhas = TradutorBlingParaPortal::paraLinhas($produto)['linhas'];

        foreach ($linhas as $linha) {
            $this->assertArrayNotHasKey('volumes', $linha);
            $this->assertArrayNotHasKey('estoque', $linha);
        }
    }

    public function test_formato_kit_e_recusado_com_motivo_e_nao_gera_linha(): void
    {
        $produto = BlingFixtures::ler('produto-formato-v');
        $produto['formato'] = 'E';

        $r = TradutorBlingParaPortal::paraLinhas($produto);

        $this->assertCount(0, $r['linhas']);
        $this->assertNotEmpty($r['recusadas']);
    }
}
