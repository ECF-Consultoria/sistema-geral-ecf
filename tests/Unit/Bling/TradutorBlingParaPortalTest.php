<?php

namespace Tests\Unit\Bling;

use App\Services\Bling\TradutorBlingParaPortal;
use App\Services\Portal\Estrutura\Produtos\NormalizadorDeLinha;
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

    // ─── Task 1 (184-05): eixo composto e unidade de medida ───

    /** @return array<string, mixed> */
    private function produtoVComVariacao(?string $nomeDaVariacao, bool $omitirNome = false): array
    {
        $variacao = [];
        if (! $omitirNome) {
            $variacao['nome'] = $nomeDaVariacao;
        }

        return [
            'id'        => 900,
            'codigo'    => 'PAI-T1',
            'nome'      => 'Produto Teste T1',
            'formato'   => 'V',
            'variacoes' => [
                ['id' => 901, 'codigo' => 'VAR1', 'variacao' => $variacao],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function produtoSComDimensoes(float $largura, float $altura, float $profundidade, float $peso, mixed $unidadeMedida): array
    {
        return [
            'id'         => 910,
            'codigo'     => 'SKU-T1',
            'nome'       => 'Produto Simples T1',
            'formato'    => 'S',
            'dimensoes'  => [
                'largura'      => $largura,
                'altura'       => $altura,
                'profundidade' => $profundidade,
                'unidadeMedida' => $unidadeMedida,
            ],
            'pesoBruto' => $peso,
        ];
    }

    public function test_t1_variacao_com_um_atributo_reconhecido_nao_gera_aviso(): void
    {
        $produto = $this->produtoVComVariacao('Cor:Azul');

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertSame('cor', $linha['eixo']);
        $this->assertSame('Azul', $linha['valor']);
        $this->assertEmpty($r['avisos']);
    }

    public function test_t1_variacao_com_dois_atributos_entra_como_outro_com_aviso(): void
    {
        $produto = $this->produtoVComVariacao('Cor:Preto;Tamanho:M');

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertSame('outro', $linha['eixo']);
        $this->assertSame('Preto · M', $linha['valor']);
        $this->assertNotEmpty($r['avisos']);
        $this->assertStringContainsString('VAR1', $r['avisos'][0]);
    }

    public function test_t1_eixo_fora_da_lista_entra_como_outro_com_aviso(): void
    {
        $produto = $this->produtoVComVariacao('Aroma:Baunilha');

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertSame('outro', $linha['eixo']);
        $this->assertSame('Baunilha', $linha['valor']);
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_t1_variacao_nome_vazio_ou_ausente_omite_eixo_e_valor_com_aviso(): void
    {
        foreach ([$this->produtoVComVariacao(''), $this->produtoVComVariacao(null, omitirNome: true)] as $produto) {
            $r = TradutorBlingParaPortal::paraLinhas($produto);
            $linha = $r['linhas'][0];

            $this->assertArrayNotHasKey('eixo', $linha);
            $this->assertArrayNotHasKey('valor', $linha);
            $this->assertNotEmpty($r['avisos']);
        }
    }

    public function test_t1_valor_da_variacao_com_mais_de_80_caracteres_e_cortado(): void
    {
        $produto = $this->produtoVComVariacao('Cor:'.str_repeat('A', 90));

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertSame(NormalizadorDeLinha::MAX_VALOR, mb_strlen($linha['valor']));
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_t1_unidade_metro_multiplica_cada_medida_por_100(): void
    {
        $produto = $this->produtoSComDimensoes(largura: 1, altura: 2, profundidade: 3, peso: 1, unidadeMedida: 0);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertSame(300.0, $linha['volumes'][0]['c']); // profundidade É o comprimento
        $this->assertSame(100.0, $linha['volumes'][0]['l']);
        $this->assertSame(200.0, $linha['volumes'][0]['a']);
    }

    public function test_t1_unidade_milimetro_divide_cada_medida_por_10(): void
    {
        $produto = $this->produtoSComDimensoes(largura: 100, altura: 200, profundidade: 300, peso: 1, unidadeMedida: 2);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertSame(30.0, $linha['volumes'][0]['c']);
        $this->assertSame(10.0, $linha['volumes'][0]['l']);
        $this->assertSame(20.0, $linha['volumes'][0]['a']);
    }

    public function test_t1_unidade_centimetro_mantem_a_medida(): void
    {
        $produto = $this->produtoSComDimensoes(largura: 10, altura: 20, profundidade: 30, peso: 1, unidadeMedida: 1);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertSame(30.0, $linha['volumes'][0]['c']);
        $this->assertSame(10.0, $linha['volumes'][0]['l']);
        $this->assertSame(20.0, $linha['volumes'][0]['a']);
    }

    public function test_t1_unidade_desconhecida_e_lida_como_centimetro_com_aviso(): void
    {
        $produto = $this->produtoSComDimensoes(largura: 10, altura: 20, profundidade: 30, peso: 1, unidadeMedida: 99);

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertSame(30.0, $linha['volumes'][0]['c']);
        $this->assertNotEmpty($r['avisos']);
        $this->assertStringContainsString('centímetro', implode(' ', $r['avisos']));
    }

    public function test_t1_medida_fora_de_faixa_depois_da_conversao_omite_a_chave_volumes(): void
    {
        // unidadeMedida metro: largura 11 m -> 1100 cm, acima do teto de 999,99 cm do Portal.
        $produto = $this->produtoSComDimensoes(largura: 11, altura: 1, profundidade: 1, peso: 1, unidadeMedida: 0);

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertArrayNotHasKey('volumes', $linha);
        $this->assertNotEmpty($r['avisos']);
    }

    // ─── Task 2 (184-05): volumes > 1 e as recusas do mapa de campos ───

    /** @return array<string, mixed> */
    private function produtoSBasico(array $extra = []): array
    {
        return array_merge([
            'id'           => 920,
            'codigo'       => 'SKU-T2',
            'nome'         => 'Produto Teste T2',
            'formato'      => 'S',
            'descricaoCurta' => 'Descrição curta do produto.',
            'dimensoes'    => ['largura' => 10, 'altura' => 10, 'profundidade' => 10, 'unidadeMedida' => 1],
            'pesoBruto'    => 1,
        ], $extra);
    }

    public function test_t2_volumes_igual_a_um_mantem_a_medida(): void
    {
        $produto = $this->produtoSBasico(['volumes' => 1]);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayHasKey('volumes', $linha);
        $this->assertCount(1, $linha['volumes']);
    }

    public function test_t2_volumes_zero_ou_ausente_mantem_a_medida(): void
    {
        foreach ([$this->produtoSBasico(['volumes' => 0]), $this->produtoSBasico()] as $produto) {
            $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

            $this->assertArrayHasKey('volumes', $linha);
            $this->assertCount(1, $linha['volumes']);
        }
    }

    public function test_t2_volumes_acima_de_um_omite_a_medida_com_aviso_citando_a_quantidade(): void
    {
        $produto = $this->produtoSBasico(['volumes' => 6]);

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertArrayNotHasKey('volumes', $linha);
        $this->assertNotEmpty($r['avisos']);
        $aviso = implode(' ', $r['avisos']);
        $this->assertStringContainsString('6', $aviso);
        $this->assertStringContainsString('medida precisa ser informada no Portal', $aviso);
    }

    public function test_t2_custo_acima_do_teto_do_normalizador_omite_a_chave_com_aviso(): void
    {
        $produto = $this->produtoSBasico(['fornecedor' => ['precoCusto' => NormalizadorDeLinha::MAX_CUSTO + 1]]);

        $r = TradutorBlingParaPortal::paraLinhas($produto);
        $linha = $r['linhas'][0];

        $this->assertArrayNotHasKey('custo', $linha);
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_t2_descricao_complementar_sem_descricao_curta_nao_entra(): void
    {
        $produto = $this->produtoSBasico([
            'descricaoComplementar' => '<p>Só a complementar, sem a curta.</p>',
        ]);
        unset($produto['descricaoCurta']);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayNotHasKey('descricao', $linha);
    }

    public function test_t2_produto_inativo_ou_servico_e_recusado_com_motivo(): void
    {
        foreach ([
            $this->produtoSBasico(['situacao' => 'Inativo']),
            $this->produtoSBasico(['tipo' => 'S']),
        ] as $produto) {
            $r = TradutorBlingParaPortal::paraLinhas($produto);

            $this->assertCount(0, $r['linhas']);
            $this->assertCount(1, $r['recusadas']);
            $this->assertNotSame('', $r['recusadas'][0]['motivo']);
        }
    }

    public function test_t2_linha_produto_e_marca_nunca_entram_na_linha(): void
    {
        $produto = $this->produtoSBasico([
            'linhaProduto' => 'Vestuario',
            'marca'        => 'Generico',
            'categoria'    => ['id' => 123],
        ]);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        $this->assertArrayNotHasKey('familia', $linha);
        $this->assertArrayNotHasKey('categoria_ml_id', $linha);
        $this->assertArrayNotHasKey('categoria_texto', $linha);
        foreach (array_keys($linha) as $chave) {
            $this->assertStringNotContainsStringIgnoringCase('imagem', $chave);
            $this->assertStringNotContainsStringIgnoringCase('foto', $chave);
            $this->assertStringNotContainsStringIgnoringCase('marca', $chave);
        }
    }

    public function test_t2_midia_e_imagens_nunca_entram_na_linha(): void
    {
        $produto = $this->produtoSBasico([
            'midia'   => ['imagens' => ['internas' => []]],
            'imagens' => ['internas' => []],
        ]);

        $linha = TradutorBlingParaPortal::paraLinhas($produto)['linhas'][0];

        foreach (array_keys($linha) as $chave) {
            $this->assertStringNotContainsStringIgnoringCase('imagem', $chave);
            $this->assertStringNotContainsStringIgnoringCase('foto', $chave);
            $this->assertStringNotContainsStringIgnoringCase('midia', $chave);
        }
    }
}
