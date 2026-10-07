<?php

namespace Tests\Unit\Phase169;

use App\Models\PubProduto;
use App\Models\PubProdutoFatoCriativo;
use App\Services\Publicador\Criativos\ContextoCriativoDoPublicador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 169, Plano 01, Task 2 — `ContextoCriativoDoPublicador::fatosHumanos()`
 * isoladamente, sem rascunho nenhum: só a tabela nova (Task 1) + um
 * `PubProduto`. A fiação até o `CreativeContext`/`ProductTruth` é coberta em
 * `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` e
 * `tests/Unit/Phase160/ProductTruthBuilderTest.php`.
 */
class ContextoCriativoDoPublicadorFatosHumanosTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_nenhuma_linha_devolve_as_duas_listas_vazias(): void
    {
        $produto = PubProduto::create(['sku' => 'SKU-FH-01', 'nome' => 'Produto sem fato']);

        $fatos = ContextoCriativoDoPublicador::fatosHumanos($produto->id);

        $this->assertSame(['beneficios' => [], 'medidas' => []], $fatos);
    }

    public function test_separa_beneficios_e_medidas_por_tipo_em_ordem_estavel(): void
    {
        $produto = PubProduto::create(['sku' => 'SKU-FH-02', 'nome' => 'Produto com fatos']);

        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produto->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'          => '  Estrutura reforçada  ',
        ]);
        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produto->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_MEDIDA,
            'texto'          => 'Largura 80cm',
        ]);
        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produto->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'          => 'Fácil de montar',
        ]);

        $fatos = ContextoCriativoDoPublicador::fatosHumanos($produto->id);

        // trim() aplicado e ordem estável (por id, ordem de criação).
        $this->assertSame(['Estrutura reforçada', 'Fácil de montar'], $fatos['beneficios']);
        $this->assertSame(['Largura 80cm'], $fatos['medidas']);
    }

    public function test_so_le_fatos_do_produto_pedido_nunca_de_outro(): void
    {
        $produtoA = PubProduto::create(['sku' => 'SKU-FH-03A', 'nome' => 'Produto A']);
        $produtoB = PubProduto::create(['sku' => 'SKU-FH-03B', 'nome' => 'Produto B']);

        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produtoA->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'          => 'Fato do produto A',
        ]);
        PubProdutoFatoCriativo::create([
            'pub_produto_id' => $produtoB->id,
            'tipo'           => PubProdutoFatoCriativo::TIPO_BENEFICIO,
            'texto'          => 'Fato do produto B',
        ]);

        $fatosA = ContextoCriativoDoPublicador::fatosHumanos($produtoA->id);

        $this->assertSame(['Fato do produto A'], $fatosA['beneficios']);
    }
}
