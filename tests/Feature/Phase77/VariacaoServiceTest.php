<?php

namespace Tests\Feature\Phase77;

use App\Services\Mlb\Publicacao\MlVariacaoService;
use Tests\TestCase;

/**
 * Suíte de testes do MlVariacaoService (Phase 77, Plan 02).
 *
 * Cobre WIZ-04 e WIZ-07:
 *   - atributosDeVariacao(): filtra atributos com allow_variations=true
 *   - montar(): produz variations[] no shape do POST /items com available_quantity=0 no raiz
 *   - validar(): detecta combinação repetida, GTIN duplicado, foto faltando, combinação vazia
 *
 * Service puro — sem banco, sem HTTP (instanciado diretamente com new).
 *
 * @group phase77
 */
class VariacaoServiceTest extends TestCase
{
    // Sem RefreshDatabase — service puro, não usa banco de dados

    private MlVariacaoService $service;

    // ─── Dados de atributos fake (espelham o shape da API ML) ───

    private const ATRIBUTOS_MISTOS = [
        [
            'id'   => 'BRAND',
            'name' => 'Marca',
            'tags' => ['required' => true, 'allow_variations' => false, 'catalog_required' => false],
        ],
        [
            'id'   => 'COLOR',
            'name' => 'Cor',
            'tags' => ['required' => false, 'allow_variations' => true],
        ],
        [
            'id'   => 'SIZE',
            'name' => 'Tamanho',
            'tags' => ['required' => true, 'allow_variations' => true],
        ],
        [
            'id'   => 'MATERIAL',
            'name' => 'Material',
            'tags' => ['required' => false, 'allow_variations' => false],
        ],
    ];

    // ─── Mapa de atributos de variação (filtrado de ATRIBUTOS_MISTOS) ───

    private const MAPA_VARIACAO = [
        'COLOR' => [
            'id'   => 'COLOR',
            'name' => 'Cor',
            'tags' => ['allow_variations' => true],
        ],
        'SIZE' => [
            'id'   => 'SIZE',
            'name' => 'Tamanho',
            'tags' => ['allow_variations' => true],
        ],
    ];

    // ─── Duas variações válidas e distintas para os testes de montar() ───

    private function duasVariacoesValidas(): array
    {
        return [
            [
                'attribute_combinations' => [
                    ['id' => 'COLOR', 'name' => 'Cor',     'value_id' => '52049', 'value_name' => 'Azul'],
                    ['id' => 'SIZE',  'name' => 'Tamanho', 'value_id' => '10',    'value_name' => 'M'],
                ],
                'attributes' => [
                    ['id' => 'GTIN',       'value_name' => '7891111111111'],
                    ['id' => 'SELLER_SKU', 'value_name' => 'SKU-AZUL-M'],
                ],
                'picture_ids'        => ['MLB-123-1'],
                'price'              => 99.90,
                'available_quantity' => 10,
            ],
            [
                'attribute_combinations' => [
                    ['id' => 'COLOR', 'name' => 'Cor',     'value_id' => '52050', 'value_name' => 'Vermelho'],
                    ['id' => 'SIZE',  'name' => 'Tamanho', 'value_id' => '10',    'value_name' => 'M'],
                ],
                'attributes' => [
                    ['id' => 'GTIN',       'value_name' => '7892222222222'],
                    ['id' => 'SELLER_SKU', 'value_name' => 'SKU-VERM-M'],
                ],
                'picture_ids'        => ['MLB-456-1'],
                'price'              => 99.90,
                'available_quantity' => 5,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MlVariacaoService();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // atributosDeVariacao()
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function atributos_de_variacao_retorna_apenas_os_com_allow_variations_true(): void
    {
        $resultado = $this->service->atributosDeVariacao(self::ATRIBUTOS_MISTOS);

        // Deve conter COLOR e SIZE (allow_variations=true)
        $this->assertArrayHasKey('COLOR', $resultado);
        $this->assertArrayHasKey('SIZE', $resultado);

        // Não deve conter BRAND nem MATERIAL (allow_variations=false ou ausente)
        $this->assertArrayNotHasKey('BRAND', $resultado);
        $this->assertArrayNotHasKey('MATERIAL', $resultado);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // montar()
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function montar_com_duas_variacoes_validas_produz_shape_ml_correto(): void
    {
        $resultado = $this->service->montar($this->duasVariacoesValidas(), self::MAPA_VARIACAO);

        // available_quantity=0 no item raiz (regra: variações controlam o estoque)
        $this->assertSame(0, $resultado['available_quantity']);

        // Deve ter 2 variações
        $this->assertCount(2, $resultado['variations']);

        // Primeira variação: attribute_combinations + attributes(GTIN+SKU) + picture_ids
        $v1 = $resultado['variations'][0];
        $this->assertArrayHasKey('attribute_combinations', $v1);
        $this->assertArrayHasKey('attributes', $v1);
        $this->assertArrayHasKey('picture_ids', $v1);
        $this->assertArrayHasKey('price', $v1);
        $this->assertArrayHasKey('available_quantity', $v1);

        // GTIN e SELLER_SKU devem estar em attributes, não em attribute_combinations
        $attrIds = array_column($v1['attributes'], 'id');
        $this->assertContains('GTIN', $attrIds);
        $this->assertContains('SELLER_SKU', $attrIds);

        $attrComboIds = array_column($v1['attribute_combinations'], 'id');
        $this->assertNotContains('GTIN', $attrComboIds);
        $this->assertNotContains('SELLER_SKU', $attrComboIds);

        // picture_ids é array
        $this->assertIsArray($v1['picture_ids']);
        $this->assertNotEmpty($v1['picture_ids']);
    }

    /** @test */
    public function montar_com_variacoes_vazias_retorna_available_quantity_zero_e_array_vazio(): void
    {
        $resultado = $this->service->montar([], self::MAPA_VARIACAO);

        $this->assertSame(0, $resultado['available_quantity']);
        $this->assertIsArray($resultado['variations']);
        $this->assertEmpty($resultado['variations']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // validar()
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function validar_detecta_combinacao_repetida(): void
    {
        // Duas variações com exatamente o mesmo attribute_combinations
        $variacoes = [
            [
                'attribute_combinations' => [
                    ['id' => 'COLOR', 'value_id' => '52049', 'value_name' => 'Azul'],
                    ['id' => 'SIZE',  'value_id' => '10',    'value_name' => 'M'],
                ],
                'attributes'         => [],
                'picture_ids'        => ['MLB-111'],
                'price'              => 99.90,
                'available_quantity' => 5,
            ],
            [
                'attribute_combinations' => [
                    ['id' => 'SIZE',  'value_id' => '10',    'value_name' => 'M'],
                    ['id' => 'COLOR', 'value_id' => '52049', 'value_name' => 'Azul'],
                ],
                'attributes'         => [],
                'picture_ids'        => ['MLB-222'],
                'price'              => 99.90,
                'available_quantity' => 5,
            ],
        ];

        $erros = $this->service->validar($variacoes);

        $this->assertNotEmpty($erros);
        $codes = array_column($erros, 'code');
        $this->assertContains('combinacao_repetida', $codes);
    }

    /** @test */
    public function validar_detecta_gtin_duplicado(): void
    {
        $variacoes = [
            [
                'attribute_combinations' => [['id' => 'COLOR', 'value_id' => '52049', 'value_name' => 'Azul']],
                'attributes'             => [['id' => 'GTIN', 'value_name' => '7891234567890']],
                'picture_ids'            => ['MLB-111'],
                'price'                  => 99.90,
                'available_quantity'     => 5,
            ],
            [
                'attribute_combinations' => [['id' => 'COLOR', 'value_id' => '52050', 'value_name' => 'Vermelho']],
                'attributes'             => [['id' => 'GTIN', 'value_name' => '7891234567890']], // mesmo GTIN
                'picture_ids'            => ['MLB-222'],
                'price'                  => 99.90,
                'available_quantity'     => 5,
            ],
        ];

        $erros = $this->service->validar($variacoes);

        $this->assertNotEmpty($erros);
        $codes = array_column($erros, 'code');
        $this->assertContains('gtin_duplicado', $codes);
    }

    /** @test */
    public function validar_detecta_foto_faltando(): void
    {
        $variacoes = [
            [
                'attribute_combinations' => [['id' => 'COLOR', 'value_id' => '52049', 'value_name' => 'Azul']],
                'attributes'             => [],
                'picture_ids'            => [],  // sem foto
                'price'                  => 99.90,
                'available_quantity'     => 5,
            ],
        ];

        $erros = $this->service->validar($variacoes);

        $this->assertNotEmpty($erros);
        $codes = array_column($erros, 'code');
        $this->assertContains('foto_faltando', $codes);
    }

    /** @test */
    public function validar_detecta_combinacao_vazia(): void
    {
        $variacoes = [
            [
                'attribute_combinations' => [],  // vazio — sem combinação
                'attributes'             => [],
                'picture_ids'            => ['MLB-111'],
                'price'                  => 99.90,
                'available_quantity'     => 5,
            ],
        ];

        $erros = $this->service->validar($variacoes);

        $this->assertNotEmpty($erros);
        $codes = array_column($erros, 'code');
        $this->assertContains('combinacao_vazia', $codes);
    }

    /** @test */
    public function validar_retorna_array_vazio_para_variacoes_validas(): void
    {
        $erros = $this->service->validar($this->duasVariacoesValidas());

        $this->assertEmpty($erros, 'Variações válidas e distintas não devem gerar erros: ' . json_encode($erros));
    }

    /** @test */
    public function validar_erros_seguem_shape_code_campo_mensagem(): void
    {
        $variacoes = [
            [
                'attribute_combinations' => [['id' => 'COLOR', 'value_id' => '52049', 'value_name' => 'Azul']],
                'attributes'             => [],
                'picture_ids'            => [],  // foto faltando → gera erro
                'price'                  => 99.90,
                'available_quantity'     => 5,
            ],
        ];

        $erros = $this->service->validar($variacoes);

        $this->assertNotEmpty($erros);
        $erro = $erros[0];

        // Shape idêntico ao MlItemPayloadValidator::traduzir()
        $this->assertArrayHasKey('code', $erro);
        $this->assertArrayHasKey('campo', $erro);
        $this->assertArrayHasKey('mensagem', $erro);
        $this->assertIsString($erro['code']);
        $this->assertIsString($erro['mensagem']);
    }
}
