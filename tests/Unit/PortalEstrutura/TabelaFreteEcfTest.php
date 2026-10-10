<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf;
use Tests\TestCase;

/**
 * A tabela de custos de envio do ML vigente desde 24/08/2026 (reputação verde ou sem
 * reputação), conferida célula a célula contra a página oficial em 09/10/2026:
 * https://www.mercadolivre.com.br/ajuda/custos-envio-reputacao-verde-sem-reputacao_48392
 */
class TabelaFreteEcfTest extends TestCase
{
    public function test_vigencia_e_a_de_24_de_agosto(): void
    {
        $this->assertSame('2026-08-24', config('estrutura_produtos.frete.vigente_desde'));
    }

    public function test_valor_publico_conferido_na_pagina_do_ml(): void
    {
        // Faixa de preço 79–99,99, até 0,3 kg.
        $this->assertSame(12.95, TabelaFreteEcf::valor(0.3, 85));
        // Linha "a partir de R$ 200", até 0,3 kg.
        $this->assertSame(21.65, TabelaFreteEcf::valor(0.2, 250));
    }

    /**
     * O caso da conta #459 (sondagem de 01/10): 15×15×20 com 500 g fatura 750 g e cai na
     * linha "de 0,5 a 1 kg". A cotação real deu 8,45 · 14,45 · 21,35 a R$ 50 · 79 · 150 —
     * e é isso que a tabela nova diz (a de 02/03 dava 7,95 · 13,85 · 20,75).
     */
    public function test_meio_quilo_cubado_bate_com_a_cotacao_real_da_459(): void
    {
        $this->assertSame(8.45, TabelaFreteEcf::valor(0.75, 50));
        $this->assertSame(8.45, TabelaFreteEcf::valor(0.75, 78.99));
        $this->assertSame(14.45, TabelaFreteEcf::valor(0.75, 79));
        $this->assertSame(21.35, TabelaFreteEcf::valor(0.75, 150));
    }

    /** A tabela de 24/08 ganhou a linha "de 9 a 10 kg" (a de 02/03 ia de 9 direto a 11). */
    public function test_faixa_de_9_a_10_kg_existe(): void
    {
        $this->assertSame(12, TabelaFreteEcf::faixas(9.5, 85)['linha']);
        $this->assertSame(38.25, TabelaFreteEcf::valor(9.5, 85));
        $this->assertSame(65.85, TabelaFreteEcf::valor(10, 250), 'exatamente 10 kg fica em "de 9 a 10"');
        $this->assertSame(41.65, TabelaFreteEcf::valor(10.5, 85), 'de 10 a 11 kg');
        $this->assertSame(30.25, TabelaFreteEcf::valor(9, 85), 'exatamente 9 kg fica em "de 8 a 9"');
    }

    public function test_peso_exato_fica_na_faixa_de_baixo(): void
    {
        $this->assertSame(['linha' => 0, 'coluna' => 3], TabelaFreteEcf::faixas(0.3, 85));
        $this->assertSame(1, TabelaFreteEcf::faixas(0.3001, 85)['linha']);
        // exatamente 5 kg cai em "de 4 a 5" (limite inferior 4 = índice 7).
        $this->assertSame(7, TabelaFreteEcf::faixas(5, 100)['linha']);
    }

    public function test_limite_de_preco_cai_na_coluna_de_cima(): void
    {
        $this->assertSame(3, TabelaFreteEcf::faixas(1, 79)['coluna']);
        $this->assertSame(2, TabelaFreteEcf::faixas(1, 78.99)['coluna']);
        $this->assertSame(2, TabelaFreteEcf::coluna(78.99));
        $this->assertSame(3, TabelaFreteEcf::coluna(79));
    }

    public function test_peso_acima_de_150_usa_a_ultima_linha(): void
    {
        $this->assertSame(29, TabelaFreteEcf::faixas(400, 250)['linha']);
        $this->assertSame(262.85, TabelaFreteEcf::valor(400, 250));
    }

    public function test_forma_da_tabela_30_por_8(): void
    {
        $tabela = config('estrutura_produtos.frete.tabela');

        $this->assertCount(30, $tabela);
        foreach ($tabela as $linha) {
            $this->assertCount(8, $linha);
        }
        $this->assertCount(30, config('estrutura_produtos.frete.faixas_peso'));
        $this->assertCount(8, config('estrutura_produtos.frete.faixas_preco'));
        // Linhas conferidas na página: a primeira, a nova de 9 a 10 kg e a última.
        $this->assertSame([5.65, 6.85, 8.15, 12.95, 14.95, 16.95, 19.05, 21.65], $tabela[0]);
        $this->assertSame([7.05, 9.45, 10.85, 38.25, 45.05, 51.95, 58.75, 65.85], $tabela[12]);
        $this->assertSame([8.75, 12.85, 14.45, 167.05, 193.35, 218.45, 243.45, 262.85], $tabela[29]);
    }

    /** "*Os produtos de menos de R$ 19 pagam no máximo metade do preço do produto." */
    public function test_abaixo_de_19_reais_o_custo_e_no_maximo_metade_do_preco(): void
    {
        $this->assertSame(5.0, TabelaFreteEcf::valor(0.3, 10), '5,65 da tabela, teto de R$ 5,00');
        $this->assertSame(5.65, TabelaFreteEcf::valor(0.3, 15), 'teto de 7,50 acima da tabela: vale a tabela');
        $this->assertSame(5.0, TabelaFreteEcf::valor(400, 10), 'até a linha de mais de 150 kg');
        $this->assertSame(6.85, TabelaFreteEcf::valor(0.3, 19), 'a partir de R$ 19 não há teto');

        // Aplicar duas vezes não muda nada (serve ao valor da tabela e ao da API).
        $this->assertSame(5.0, TabelaFreteEcf::comTeto(TabelaFreteEcf::comTeto(8.75, 10), 10));
        $this->assertSame(8.75, TabelaFreteEcf::comTeto(8.75, 19));
        $this->assertNull(TabelaFreteEcf::comTeto(null, 10));
    }

    public function test_frete_gratis_obrigatorio_a_partir_de_79_vem_do_config(): void
    {
        $this->assertFalse(TabelaFreteEcf::gratisObrigatorio(78.99));
        $this->assertTrue(TabelaFreteEcf::gratisObrigatorio(79));

        config(['estrutura_produtos.frete.gratis_obrigatorio_a_partir' => 99]);
        $this->assertFalse(TabelaFreteEcf::gratisObrigatorio(79));
    }

    public function test_tabela_vazia_devolve_null(): void
    {
        config(['estrutura_produtos.frete.tabela' => []]);

        $this->assertNull(TabelaFreteEcf::valor(1, 100));
    }

    public function test_nenhum_limite_do_ml_fica_em_codigo(): void
    {
        $arquivos = glob(base_path('app/Services/Portal/Estrutura/Produtos/*.php'));
        $this->assertNotEmpty($arquivos);

        foreach ($arquivos as $arquivo) {
            $codigo = '';
            foreach (token_get_all(file_get_contents($arquivo)) as $t) {
                if (is_array($t)) {
                    if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $codigo .= $t[1];
                } else {
                    $codigo .= $t;
                }
            }

            $this->assertDoesNotMatchRegularExpression('/\b(79|6000)\b/', $codigo, basename($arquivo));
        }
    }
}
