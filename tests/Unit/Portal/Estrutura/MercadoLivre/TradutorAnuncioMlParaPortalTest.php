<?php

namespace Tests\Unit\Portal\Estrutura\MercadoLivre;

use App\Services\Portal\Estrutura\Produtos\MercadoLivre\TradutorAnuncioMlParaPortal;
use PHPUnit\Framework\TestCase;

/**
 * O anúncio do ML → linhas do cadastro, ficha, fotos e retrato (28/10/2026). Os formatos dos
 * atributos (`value_struct`, `values`, `SELLER_PACKAGE_*`) são os das respostas reais gravadas em
 * `tests/fixtures-ml/alavancas/doc/produtos/items_multiget.json` e `tests/Fixtures/phase134/`.
 */
class TradutorAnuncioMlParaPortalTest extends TestCase
{
    private function caixa(): array
    {
        return [
            ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_name' => '12 cm', 'value_struct' => ['number' => 12, 'unit' => 'cm']],
            ['id' => 'SELLER_PACKAGE_WIDTH', 'value_name' => '45 cm', 'value_struct' => ['number' => 45, 'unit' => 'cm']],
            ['id' => 'SELLER_PACKAGE_LENGTH', 'value_name' => '600 mm', 'value_struct' => ['number' => 600, 'unit' => 'mm']],
            ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => '4500 g', 'value_struct' => ['number' => 4500, 'unit' => 'g']],
        ];
    }

    private function cadeira(): array
    {
        return [
            'id' => 'MLB111', 'title' => 'Cadeira Escritório Giratória Preta', 'category_id' => 'MLB193945', 'status' => 'active',
            'listing_type_id' => 'gold_special', 'price' => 399.9, 'available_quantity' => 7, 'sold_quantity' => 31,
            'seller_custom_field' => null,
            'attributes' => [
                ['id' => 'BRAND', 'name' => 'Marca', 'value_id' => null, 'value_name' => 'Ergo'],
                ['id' => 'MODEL', 'name' => 'Modelo', 'value_name' => 'ER-200'],
                ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => 'CAD-ER200'],
                ['id' => 'SEAT_DEPTH', 'name' => 'Profundidade do assento', 'value_name' => '48 cm', 'value_struct' => ['number' => 48, 'unit' => 'cm']],
                ['id' => 'GTIN', 'name' => 'GTIN', 'value_name' => '7891234567895'],
                ['id' => 'WITH_ARMRESTS', 'name' => 'Com braços', 'value_id' => '242085', 'value_name' => 'Sim'],
                ...$this->caixa(),
            ],
            'pictures' => [
                ['id' => 'P1', 'secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg'],
                ['id' => 'P2', 'secure_url' => 'https://http2.mlstatic.com/D_2-O.jpg'],
                ['id' => 'P3', 'url' => 'http://http2.mlstatic.com/D_3-O.jpg'],
            ],
            'variations' => [
                ['id' => 1, 'available_quantity' => 4, 'picture_ids' => ['P1', 'P2'],
                    'attribute_combinations' => [['id' => 'COLOR', 'value_name' => 'Preto']],
                    'attributes' => [['id' => 'SELLER_SKU', 'value_name' => 'CAD-ER200-PT']]],
                ['id' => 2, 'available_quantity' => 3, 'picture_ids' => ['P3'],
                    'attribute_combinations' => [['id' => 'COLOR', 'value_name' => 'Cinza']],
                    'attributes' => []],
            ],
            'shipping' => ['mode' => 'me2', 'logistic_type' => 'xd_drop_off', 'free_shipping' => true, 'dimensions' => null],
            'sale_terms' => [['id' => 'WARRANTY_TIME', 'name' => 'Tempo de garantia', 'value_name' => '90 dias']],
        ];
    }

    public function test_variacoes_viram_linhas_com_eixo_estoque_ref_e_fotos_de_cada_uma(): void
    {
        $r = TradutorAnuncioMlParaPortal::traduzir($this->cadeira(), "Cadeira confortável.\r\nMedidas: 60x45x110 cm.");

        $this->assertCount(2, $r['linhas']);
        [$preto, $cinza] = $r['linhas'];

        $this->assertSame('CAD-ER200-PT', $preto['codigo']);
        $this->assertSame('MLB111-2', $cinza['codigo'], 'variação sem SKU usa o MLB e a posição');
        $this->assertSame('CAD-ER200', $preto['grupo'], 'o grupo é o SKU do anúncio');
        $this->assertSame('CAD-ER200', $cinza['grupo']);
        $this->assertSame(['cor', 'Preto', 4], [$preto['eixo'], $preto['valor'], $preto['estoque']]);
        $this->assertSame(['cor', 'Cinza', 3], [$cinza['eixo'], $cinza['valor'], $cinza['estoque']]);
        $this->assertSame('Cadeira Escritório Giratória Preta', $preto['nome']);
        $this->assertSame('MLB193945', $preto['categoria_ml_id']);
        $this->assertArrayNotHasKey('titulo', $preto, 'título de venda vazio: a IA reescreve pela metodologia');
        $this->assertSame("Cadeira confortável.\nMedidas: 60x45x110 cm.", $preto['descricao']);
        $this->assertArrayNotHasKey('descricao', $cinza, 'a descrição é do produto: só na 1ª linha');

        $this->assertSame(['P1', 'P2'], array_column($r['fotos_por_linha'][0], 'id'));
        $this->assertSame(['https://http2.mlstatic.com/D_3-O.jpg'], array_column($r['fotos_por_linha'][1], 'url'), 'http vira https');
    }

    public function test_caixa_do_vendedor_vira_volume_em_cm_e_kg(): void
    {
        $r = TradutorAnuncioMlParaPortal::traduzir($this->cadeira(), null);

        $this->assertSame([['c' => 60.0, 'l' => 45.0, 'a' => 12.0, 'kg' => 4.5]], $r['linhas'][0]['volumes'], 'mm → cm, g → kg');
        $this->assertSame($r['linhas'][0]['volumes'], $r['linhas'][1]['volumes']);
        $this->assertSame(['c' => 60.0, 'l' => 45.0, 'a' => 12.0, 'kg' => 4.5], $r['retrato']['caixa']);
    }

    public function test_sem_caixa_do_vendedor_usa_shipping_dimensions_e_sem_nenhuma_avisa(): void
    {
        $item = $this->cadeira();
        $item['attributes'] = array_values(array_filter($item['attributes'], fn ($a) => ! str_starts_with($a['id'], 'SELLER_PACKAGE_')));
        $item['shipping']['dimensions'] = '10x20x30,1500';

        $r = TradutorAnuncioMlParaPortal::traduzir($item, null);
        $this->assertSame([['c' => 30.0, 'l' => 20.0, 'a' => 10.0, 'kg' => 1.5]], $r['linhas'][0]['volumes'], 'altura x largura x comprimento, peso em gramas');

        $item['shipping']['dimensions'] = null;
        $r = TradutorAnuncioMlParaPortal::traduzir($item, null);
        $this->assertArrayNotHasKey('volumes', $r['linhas'][0], 'medida que o anúncio não tem não é inventada');
        $this->assertContains('O anúncio não tem as medidas da caixa: informe-as no produto.', $r['avisos']);
    }

    public function test_anuncio_sem_variacoes_e_uma_linha_com_o_sku_ou_o_mlb(): void
    {
        $item = $this->cadeira();
        unset($item['variations']);
        $r = TradutorAnuncioMlParaPortal::traduzir($item, null);

        $this->assertCount(1, $r['linhas']);
        $this->assertSame('CAD-ER200', $r['linhas'][0]['codigo']);
        $this->assertSame('CAD-ER200', $r['linhas'][0]['grupo']);
        $this->assertSame(7, $r['linhas'][0]['estoque']);
        $this->assertArrayNotHasKey('eixo', $r['linhas'][0]);
        $this->assertCount(3, $r['fotos_por_linha'][0]);

        $item['attributes'] = array_values(array_filter($item['attributes'], fn ($a) => $a['id'] !== 'SELLER_SKU'));
        $r = TradutorAnuncioMlParaPortal::traduzir($item, null);
        $this->assertSame('MLB111', $r['linhas'][0]['codigo'], 'sem SKU, o MLB');
    }

    public function test_varias_caracteristicas_juntas_viram_outro_com_os_valores_e_aviso(): void
    {
        $item = $this->cadeira();
        $item['variations'][0]['attribute_combinations'][] = ['id' => 'VOLTAGE', 'value_name' => '127V'];
        $item['variations'][1]['attribute_combinations'][] = ['id' => 'VOLTAGE', 'value_name' => '220V'];

        $r = TradutorAnuncioMlParaPortal::traduzir($item, null);

        $this->assertSame(['outro', 'Preto · 127V'], [$r['linhas'][0]['eixo'], $r['linhas'][0]['valor']]);
        $this->assertNotEmpty(array_filter($r['avisos'], fn ($a) => str_contains($a, 'mais de uma característica')));
    }

    public function test_ficha_leva_os_atributos_menos_sku_caixa_gtin_e_modelo_que_fica_no_retrato(): void
    {
        $r = TradutorAnuncioMlParaPortal::traduzir($this->cadeira(), null);

        $this->assertSame(['BRAND', 'SEAT_DEPTH', 'WITH_ARMRESTS'], array_keys($r['atributos']));
        $this->assertSame('ER-200', $r['retrato']['modelo']);
        $this->assertSame('Ergo', $r['retrato']['marca']);
        $this->assertSame(31, $r['retrato']['vendidos']);
        $this->assertSame(['Tempo de garantia: 90 dias'], $r['retrato']['garantia']);
        $this->assertSame(3, $r['retrato']['fotos']);
    }

    public function test_entrada_da_ficha_por_tipo_de_campo(): void
    {
        $lista = ['tipo' => 'lista', 'multivalor' => false, 'valores' => [['id' => '242085', 'nome' => 'Sim'], ['id' => '10', 'nome' => 'Couro sintético']]];
        $this->assertSame(['valor' => '242085'], TradutorAnuncioMlParaPortal::entradaDaFicha($lista, ['value_id' => '242085', 'value_name' => 'Sim']));
        $this->assertSame(['valor' => '10'], TradutorAnuncioMlParaPortal::entradaDaFicha($lista, ['value_id' => null, 'value_name' => 'couro sintetico']), 'casa pelo nome sem acento');
        $this->assertSame(['valor' => 'Veludo', 'proprio' => true], TradutorAnuncioMlParaPortal::entradaDaFicha($lista, ['value_name' => 'Veludo']));

        $multi = ['tipo' => 'lista', 'multivalor' => true, 'valores' => [['id' => '1', 'nome' => 'Sala'], ['id' => '2', 'nome' => 'Quarto']]];
        $this->assertSame(['valor' => ['1', 'Varanda'], 'proprios' => ['Varanda']],
            TradutorAnuncioMlParaPortal::entradaDaFicha($multi, ['values' => [['id' => '1', 'name' => 'Sala'], ['id' => null, 'name' => 'Varanda']]]));

        $medida = ['tipo' => 'numero_unidade', 'valores' => []];
        $this->assertSame(['valor' => '48', 'unidade' => 'cm'], TradutorAnuncioMlParaPortal::entradaDaFicha($medida, ['value_name' => '48 cm', 'value_struct' => ['number' => 48, 'unit' => 'cm']]));
        $this->assertSame(['valor' => '1,5', 'unidade' => 'm'], TradutorAnuncioMlParaPortal::entradaDaFicha($medida, ['value_name' => '1,5 m']));

        $texto = ['tipo' => 'texto', 'valores' => [], 'nao_se_aplica' => true];
        $this->assertSame(['nao_se_aplica' => true], TradutorAnuncioMlParaPortal::entradaDaFicha($texto, ['value_id' => '-1', 'value_name' => null]));
        $this->assertNull(TradutorAnuncioMlParaPortal::entradaDaFicha(['tipo' => 'texto', 'valores' => []], ['value_id' => '-1', 'value_name' => null]),
            'campo que não aceita "Não se aplica" fica vazio');
    }
}
