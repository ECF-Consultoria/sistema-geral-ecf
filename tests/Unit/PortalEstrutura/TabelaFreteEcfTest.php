<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf;
use Tests\TestCase;

class TabelaFreteEcfTest extends TestCase
{
    public function test_valor_publico_conferido_fora_da_planilha(): void
    {
        // Faixa de preço 79–99,99, até 0,3 kg, reputação verde.
        $this->assertSame(12.35, TabelaFreteEcf::valor(0.3, 85));
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
    }

    public function test_peso_acima_de_150_usa_a_ultima_linha(): void
    {
        $this->assertSame(28, TabelaFreteEcf::faixas(400, 250)['linha']);
        $this->assertSame(261.95, TabelaFreteEcf::valor(400, 250));
    }

    public function test_forma_da_tabela_29_por_8(): void
    {
        $tabela = config('estrutura_produtos.frete.tabela');

        $this->assertCount(29, $tabela);
        foreach ($tabela as $linha) {
            $this->assertCount(8, $linha);
        }
        $this->assertCount(29, config('estrutura_produtos.frete.faixas_peso'));
        $this->assertCount(8, config('estrutura_produtos.frete.faixas_preco'));
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
