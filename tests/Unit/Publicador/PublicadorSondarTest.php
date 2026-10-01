<?php

namespace Tests\Unit\Publicador;

use App\Console\Commands\PublicadorSondar;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A sondagem da Fase 0 roda contra a conta real do ML e grava fixtures que vão
 * para o git. O que estes testes seguram: ela nunca escreve no ML, nunca grava
 * dado pessoal, e cada cenário do `/items/validate` isola a pergunta certa.
 */
class PublicadorSondarTest extends TestCase
{
    public function test_so_leitura_validate_e_condicionais_passam(): void
    {
        PublicadorSondar::garantirSomenteLeitura('GET', '/users/me');
        PublicadorSondar::garantirSomenteLeitura('POST', '/items/validate');
        PublicadorSondar::garantirSomenteLeitura('POST', '/categories/MLB1234/attributes/conditional');

        $this->addToAssertionCount(3);
    }

    #[DataProvider('escritas')]
    public function test_qualquer_escrita_no_ml_e_recusada(string $metodo, string $caminho): void
    {
        $this->expectException(\LogicException::class);

        PublicadorSondar::garantirSomenteLeitura($metodo, $caminho);
    }

    public static function escritas(): array
    {
        return [
            'criar anúncio'           => ['POST', '/items'],
            'criar com barra no fim'  => ['POST', '/items/'],
            'criar com query'         => ['POST', '/items?foo=1'],
            'descrição'               => ['POST', '/items/MLB1/description'],
            'subir foto'              => ['POST', '/pictures/items/upload'],
            'editar'                  => ['PUT', '/items/MLB1'],
            'apagar'                  => ['DELETE', '/items/MLB1'],
        ];
    }

    public function test_dado_pessoal_sai_em_qualquer_profundidade(): void
    {
        $limpo = PublicadorSondar::sanitizar([
            'id' => 1,
            'email' => 'x@y.com',
            'tags' => ['normal', 'user_product_seller'],
            'status' => ['site_status' => 'active', 'billing' => ['allow' => true]],
            'origem' => ['address' => ['street_name' => 'Rua X'], 'zip_code' => '00000-000', 'tipo' => 'drop_off'],
            'lista' => [['phone' => ['number' => '999'], 'ok' => 1]],
        ]);

        $this->assertSame([
            'id' => 1,
            'tags' => ['normal', 'user_product_seller'],
            'status' => ['site_status' => 'active', 'billing' => ['allow' => true]],
            'origem' => ['tipo' => 'drop_off'],
            'lista' => [['ok' => 1]],
        ], $limpo);
    }

    public function test_cada_cenario_isola_uma_pergunta(): void
    {
        $cat = ['name' => 'Furadeiras', 'path_from_root' => [['id' => 'MLB1'], ['id' => 'MLB2'], ['id' => 'MLB3']]];
        $attrs = [
            ['id' => 'BRAND', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'MODEL', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'POWER', 'value_type' => 'number_unit', 'default_unit' => 'W', 'tags' => ['required' => true]],
            ['id' => 'VOLTAGE', 'value_type' => 'list', 'tags' => ['required' => true, 'allow_variations' => true],
                'values' => [['id' => '1', 'name' => '127V'], ['id' => '2', 'name' => '220V']]],
            ['id' => 'INTERNO', 'value_type' => 'string', 'tags' => ['required' => true, 'read_only' => true]],
        ];

        $c = (new PublicadorSondar())->cenarios('MLB3', $cat, $attrs);
        $ids = fn (array $p) => array_column($p['attributes'], 'id');

        // H-02: título × family_name
        $this->assertArrayHasKey('title', $c['base_legado']);
        $this->assertArrayNotHasKey('family_name', $c['base_legado']);
        $this->assertArrayNotHasKey('title', $c['base_up']);
        $this->assertArrayHasKey('family_name', $c['base_up']);

        // Formato dos valores; read_only nunca vai.
        $attrsBase = array_column($c['base_legado']['attributes'], null, 'id');
        $this->assertSame('10 W', $attrsBase['POWER']['value_name']);
        $this->assertSame('1', $attrsBase['VOLTAGE']['value_id']);
        $this->assertNotContains('INTERNO', $ids($c['base_legado']));

        // H-05: embalagem presente, ausente e sem unidade.
        $this->assertContains('SELLER_PACKAGE_WEIGHT', $ids($c['base_legado']));
        $this->assertNotContains('SELLER_PACKAGE_WEIGHT', $ids($c['sem_embalagem']));
        $semUnidade = array_column($c['embalagem_sem_unidade']['attributes'], null, 'id');
        $this->assertSame('500', $semUnidade['SELLER_PACKAGE_WEIGHT']['value_name']);

        // H-06 e H-03
        $na = array_column($c['na_em_obrigatorio']['attributes'], null, 'id');
        $this->assertSame('-1', $na['POWER']['value_id']);
        $this->assertNull($na['POWER']['value_name']);
        $this->assertSame('MLB2', $c['categoria_nao_folha']['category_id']);

        // Variações: o eixo sai do item e vai para as combinações; SKU por variação.
        $vars = $c['variacoes_legado_soma'];
        $this->assertNotContains('VOLTAGE', $ids($vars));
        $this->assertNotContains('SELLER_SKU', $ids($vars));
        $this->assertCount(2, $vars['variations']);
        $this->assertSame(4, $vars['available_quantity']);
        $this->assertSame(0, $c['variacoes_legado_zero']['available_quantity']);
        $this->assertArrayNotHasKey('available_quantity', $c['variacoes_legado_sem_qtd']);
        $this->assertNotSame($c['variacoes_precos_diferentes']['variations'][0]['price'], $c['variacoes_precos_diferentes']['variations'][1]['price']);
        $this->assertArrayNotHasKey('title', $c['variacoes_com_family_name']);
        $this->assertSame(['name' => 'Estampa', 'value_name' => 'Lisa'], $c['variacoes_eixo_customizado']['variations'][0]['attribute_combinations'][1]);
    }

    public function test_em_conta_up_os_cenarios_partem_do_family_name_e_o_eixo_vira_atributo(): void
    {
        $cat = ['name' => 'Furadeiras', 'settings' => ['minimum_price' => 8], 'path_from_root' => [['id' => 'MLB2'], ['id' => 'MLB3']]];
        $attrs = [
            ['id' => 'BRAND', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'MODEL', 'value_type' => 'string', 'tags' => ['required' => true]],
            ['id' => 'POWER', 'value_type' => 'number_unit', 'default_unit' => 'W', 'tags' => ['required' => true]],
            ['id' => 'VOLTAGE', 'value_type' => 'list', 'tags' => ['allow_variations' => true],
                'values' => [['id' => '1', 'name' => '127V'], ['id' => '2', 'name' => '220V']]],
        ];

        $c = (new PublicadorSondar())->cenarios('MLB3', $cat, $attrs, up: true);
        $attrsDe = fn (string $cenario) => array_column($c[$cenario]['attributes'], null, 'id');

        // Todo cenário derivado parte da base UP: family_name, nunca title.
        foreach (['sem_fotos', 'sem_embalagem', 'na_em_obrigatorio', 'categoria_nao_folha', 'numero_sem_unidade', 'preco_abaixo_minimo'] as $cenario) {
            $this->assertArrayNotHasKey('title', $c[$cenario], $cenario);
            $this->assertArrayHasKey('family_name', $c[$cenario], $cenario);
        }
        $this->assertArrayHasKey('title', $c['base_legado']);

        // O nome longo mexe no campo do modelo.
        $this->assertGreaterThan(120, mb_strlen($c['nome_longo']['family_name']));

        // UP: variante = item com o eixo como atributo comum; nada de variations legado.
        $this->assertSame('1', $attrsDe('up_variante_eixos')['VOLTAGE']['value_id']);
        $this->assertArrayNotHasKey('variations', $c['up_variante_eixos']);
        $this->assertArrayNotHasKey('variacoes_legado_soma', $c);
        $this->assertArrayHasKey('variations', $c['variacoes_com_family_name']);

        // Unidade e mínimo da categoria.
        $this->assertSame('10', $attrsDe('numero_sem_unidade')['POWER']['value_name']);
        $this->assertSame('10 pol', $attrsDe('unidade_invalida')['POWER']['value_name']);
        $this->assertSame(7.0, $c['preco_abaixo_minimo']['price']);
    }
}
