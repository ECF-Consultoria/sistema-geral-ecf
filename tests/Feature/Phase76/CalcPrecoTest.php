<?php

namespace Tests\Feature\Phase76;

use App\Http\Controllers\MlbAnuncioController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 76 Plan 01 — Port PHP de calcPreco() e helper montarProdutosDoCliente().
 *
 * calcPreco() é private — testado via ReflectionMethod::setAccessible(true).
 * montarProdutosDoCliente() é private — testado via reflection, exercitando
 * os casos de dados null, produto com SKU, produto sem SKU e join de preços.
 *
 * @group phase76
 */
class CalcPrecoTest extends TestCase
{
    // ─── Helpers de reflexão ───

    /** Resolve o método privado calcPreco() via reflection. */
    private function calcPreco(mixed ...$args): mixed
    {
        $controller = app(MlbAnuncioController::class);
        $method     = new ReflectionMethod($controller, 'calcPreco');
        $method->setAccessible(true);

        return $method->invoke($controller, ...$args);
    }

    /** Resolve o método privado montarProdutosDoCliente() via reflection. */
    private function montarProdutos(?array $dados): array
    {
        $controller = app(MlbAnuncioController::class);
        $method     = new ReflectionMethod($controller, 'montarProdutosDoCliente');
        $method->setAccessible(true);

        return $method->invoke($controller, $dados);
    }

    // ─── Testes de calcPreco() ───

    /**
     * Caso normal: custo=100, frete=20, comissao=0.115, imposto=0.19, mc=0, ll=0.
     * d = 1 - 0.115 - 0.19 = 0.695
     * preco = (100 + 20) / 0.695 ≈ 172.6619...
     */
    public function test_calc_preco_caso_normal(): void
    {
        $result = $this->calcPreco(100.0, 20.0, 0.115, 0.19, 0.0, 0.0);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(172.66, $result, 0.01);
    }

    /**
     * Caso null por custo zero: calcPreco(0, 20, 0.115, 0.19, 0, 0) === null.
     * Custo <= 0 → retorna null (sem produto = sem preço).
     */
    public function test_calc_preco_null_quando_custo_zero(): void
    {
        $result = $this->calcPreco(0.0, 20.0, 0.115, 0.19, 0.0, 0.0);

        $this->assertNull($result);
    }

    /**
     * Caso null por denominador negativo: calcPreco(100, 20, 0.6, 0.5, 0, 0).
     * d = 1 - 0.6 - 0.5 = -0.1 <= 0 → retorna null.
     */
    public function test_calc_preco_null_quando_denominador_negativo(): void
    {
        $result = $this->calcPreco(100.0, 20.0, 0.6, 0.5, 0.0, 0.0);

        $this->assertNull($result);
    }

    /**
     * mc + ll reduzem o denominador:
     * calcPreco(100, 0, 0.115, 0.19, 0.10, 0.10)
     * d = 1 - 0.115 - 0.19 - 0.10 - 0.10 = 0.495
     * preco = 100 / 0.495 ≈ 202.02
     */
    public function test_calc_preco_com_mc_e_ll(): void
    {
        $result = $this->calcPreco(100.0, 0.0, 0.115, 0.19, 0.10, 0.10);

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(202.02, $result, 0.01);
    }

    /**
     * Denominador exatamente zero: calcPreco(100, 0, 0.5, 0.5, 0, 0).
     * d = 0 → retorna null.
     */
    public function test_calc_preco_null_quando_denominador_zero(): void
    {
        $result = $this->calcPreco(100.0, 0.0, 0.5, 0.5, 0.0, 0.0);

        $this->assertNull($result);
    }

    // ─── Testes de montarProdutosDoCliente() ───

    /**
     * Dados null → retorna [] sem quebrar.
     */
    public function test_montar_produtos_null_retorna_vazio(): void
    {
        $result = $this->montarProdutos(null);

        $this->assertSame([], $result);
    }

    /**
     * Dados sem produtos → retorna [].
     */
    public function test_montar_produtos_sem_itens_retorna_vazio(): void
    {
        $result = $this->montarProdutos([]);

        $this->assertSame([], $result);
    }

    /**
     * Produto com SKU casa preços por SKU; preco_anunciado_c = calcPreco(...) * (1 + acrescimo).
     *
     * Produto: sku='SKU01', produto='Cadeira', custo=100, frete_classico=20, acrescimo=0.20
     * preco_classico = (100+20)/0.695 ≈ 172.66
     * preco_anunciado_c = round(172.66 * 1.20, 2) ≈ 207.19
     */
    public function test_montar_produtos_casa_precos_por_sku(): void
    {
        $dados = [
            'itens' => [
                'planilha_produtos' => [
                    'produtos' => [
                        [
                            'sku'          => 'SKU01',
                            'produto'      => 'Cadeira',
                            'curva'        => 'A',
                            'altura'       => '80',
                            'largura'      => '60',
                            'profundidade' => '60',
                            'peso_kg'      => '5',
                            'estoque'      => '10',
                            'especificacoes' => 'Madeira maciça',
                            'descricao'    => 'Cadeira confortável',
                        ],
                    ],
                ],
                'precificacao' => [
                    'classico'            => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'             => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0,
                    'lucro_liquido'       => 0,
                    'acrescimo'           => 0.20,
                    'produtos'            => [
                        ['sku' => 'SKU01', 'custo' => '100', 'frete_classico' => '20', 'frete_premium' => '25'],
                    ],
                ],
            ],
        ];

        $result = $this->montarProdutos($dados);

        $this->assertCount(1, $result);
        $produto = $result[0];

        $this->assertSame('SKU01', $produto['sku']);
        $this->assertSame('Cadeira', $produto['produto']);
        $this->assertTrue($produto['tem_dimensoes']);
        $this->assertTrue($produto['tem_preco']);

        // preco_classico ≈ (100+20)/0.695
        $this->assertNotNull($produto['preco_classico']);
        $this->assertEqualsWithDelta(172.66, $produto['preco_classico'], 0.01);

        // preco_anunciado_c = round(preco_classico * 1.20, 2)
        $this->assertNotNull($produto['preco_anunciado_c']);
        $this->assertEqualsWithDelta(round(172.66 * 1.20, 2), $produto['preco_anunciado_c'], 0.01);
    }

    /**
     * Produto com custo zero: tem_preco=false, preco_classico/preco_premium = null.
     */
    public function test_montar_produtos_sem_custo_tem_preco_false(): void
    {
        $dados = [
            'itens' => [
                'planilha_produtos' => [
                    'produtos' => [
                        [
                            'sku'     => 'SKU02',
                            'produto' => 'Mesa',
                            'altura'  => '75', 'largura' => '120', 'profundidade' => '60', 'peso_kg' => '10',
                            'estoque' => '5',
                            'especificacoes' => '', 'descricao' => '',
                        ],
                    ],
                ],
                'precificacao' => [
                    'classico'  => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'   => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0,
                    'lucro_liquido'       => 0,
                    'acrescimo'           => 0.20,
                    'produtos'            => [],
                ],
            ],
        ];

        $result = $this->montarProdutos($dados);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['tem_preco']);
        $this->assertNull($result[0]['preco_classico']);
        $this->assertNull($result[0]['preco_premium']);
        $this->assertNull($result[0]['preco_anunciado_c']);
        $this->assertNull($result[0]['preco_anunciado_p']);
    }

    /**
     * Produto sem nome e sem SKU é filtrado (pular linha em branco).
     */
    public function test_montar_produtos_filtra_linha_sem_nome_e_sem_sku(): void
    {
        $dados = [
            'itens' => [
                'planilha_produtos' => [
                    'produtos' => [
                        ['sku' => '', 'produto' => '', 'altura' => ''],  // deve pular
                        ['sku' => 'SKU03', 'produto' => 'Sofá', 'altura' => '90', 'largura' => '200', 'profundidade' => '80', 'peso_kg' => '15', 'estoque' => '2', 'especificacoes' => '', 'descricao' => ''],
                    ],
                ],
                'precificacao' => [
                    'classico' => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'  => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0, 'lucro_liquido' => 0, 'acrescimo' => 0.20,
                    'produtos' => [],
                ],
            ],
        ];

        $result = $this->montarProdutos($dados);

        // Só o SKU03 deve estar no resultado
        $this->assertCount(1, $result);
        $this->assertSame('SKU03', $result[0]['sku']);
    }

    /**
     * tem_dimensoes = false quando alguma dimensão está vazia.
     */
    public function test_montar_produtos_tem_dimensoes_false_quando_campo_vazio(): void
    {
        $dados = [
            'itens' => [
                'planilha_produtos' => [
                    'produtos' => [
                        [
                            'sku'          => 'SKU04',
                            'produto'      => 'Estante',
                            'altura'       => '180',
                            'largura'      => '',   // vazio
                            'profundidade' => '30',
                            'peso_kg'      => '8',
                            'estoque'      => '3',
                            'especificacoes' => '',
                            'descricao'    => '',
                        ],
                    ],
                ],
                'precificacao' => [
                    'classico' => ['comissao' => 0.115, 'imposto' => 0.19],
                    'premium'  => ['comissao' => 0.165, 'imposto' => 0.19],
                    'margem_contribuicao' => 0, 'lucro_liquido' => 0, 'acrescimo' => 0.20,
                    'produtos' => [],
                ],
            ],
        ];

        $result = $this->montarProdutos($dados);

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['tem_dimensoes']);
    }
}
