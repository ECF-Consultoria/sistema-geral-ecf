<?php

namespace Tests\Unit\Publicador\Schema;

use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/** `03` §3–5 — a categoria monta o formulário; nenhum atributo é campo fixo. */
class ClassificadorAtributosTest extends TestCase
{
    use CarregaSchemas;

    private static function classificar(string $categoria, ?ContextoClassificacao $ctx = null, ?callable $ajustar = null)
    {
        return (new ClassificadorAtributos())->classificar(self::schema($categoria, $ajustar), $ctx ?? new ContextoClassificacao());
    }

    public function test_tc30_marca_e_modelo_obrigatorios_nas_caracteristicas_principais(): void
    {
        $c = self::classificar(self::CADEIRA);

        foreach (['BRAND', 'MODEL'] as $id) {
            $a = $c->atributo($id);
            $this->assertSame(A::PRODUCT, $a->papel);
            $this->assertSame(A::REQUIRED, $a->obrigatoriedade);
            $this->assertSame(A::SECAO_PRINCIPAIS, $a->secao);
        }
        $this->assertStringContainsString('Genérica', $c->atributo('BRAND')->dica);
    }

    public function test_numericos_e_booleanos_do_print_na_ficha_tecnica(): void
    {
        $c = self::classificar(self::CADEIRA);

        $encosto = $c->atributo('BACKREST_HEIGHT');
        $this->assertSame(A::REQUIRED, $encosto->obrigatoriedade);
        $this->assertSame(A::SECAO_FICHA, $encosto->secao);
        $this->assertSame('number_unit', $encosto->valueType);
        $this->assertSame('cm', $encosto->unidadePadrao);
        $this->assertSame(['"', 'cm', 'm', 'mm'], $encosto->unidades);
        $this->assertSame('81 cm', $encosto->exemplo);

        $giratoria = $c->atributo('IS_SWIVEL');
        $this->assertSame('boolean', $giratoria->valueType);
        $this->assertFalse($giratoria->aceitaTextoLivre);
        $this->assertSame(['242084' => 'Não', '242085' => 'Sim'], array_column($giratoria->valores, 'name', 'id'));
    }

    public function test_opcional_aceita_nao_se_aplica_e_obrigatorio_nao(): void
    {
        $c = self::classificar(self::CADEIRA);

        $inmetro = $c->atributo('INMETRO_CERTIFICATION_REGISTRATION_NUMBER');
        $this->assertSame(A::OPTIONAL, $inmetro->obrigatoriedade);
        $this->assertTrue($inmetro->aceitaNaoSeAplica);
        $this->assertSame('PRODUCT_REGISTRIES', $inmetro->grupo);

        $this->assertFalse($c->atributo('BACKREST_HEIGHT')->aceitaNaoSeAplica, 'H-06: N/A em obrigatório dá erro 100');
    }

    public function test_eixo_escolhido_muda_o_papel_e_nao_aceita_nao_se_aplica(): void
    {
        $semEixo = self::classificar(self::CADEIRA);
        $this->assertSame(A::PRODUCT, $semEixo->atributo('COLOR')->papel);
        $this->assertTrue($semEixo->atributo('COLOR')->podeSerEixo);
        $this->assertTrue($semEixo->atributo('UPHOLSTERY_MATERIAL')->definePicture, 'H-21');

        $comEixos = self::classificar(self::CADEIRA, new ContextoClassificacao(eixos: ['COLOR', 'UPHOLSTERY_MATERIAL']));
        $this->assertSame(A::VARIATION_AXIS, $comEixos->atributo('COLOR')->papel);
        $this->assertSame(A::VARIATION_AXIS, $comEixos->atributo('UPHOLSTERY_MATERIAL')->papel);
        $this->assertFalse($comEixos->atributo('COLOR')->aceitaNaoSeAplica);
        $this->assertSame(['COLOR', 'UPHOLSTERY_MATERIAL'], array_keys($comEixos->candidatosAEixo()));
    }

    public function test_sku_e_gtin_sao_dados_da_variante_e_o_gtin_obedece_ao_condicional(): void
    {
        $c = self::classificar(self::CADEIRA);
        foreach (['SELLER_SKU', 'GTIN', 'EMPTY_GTIN_REASON'] as $id) {
            $this->assertSame(A::VARIANT_DATA, $c->atributo($id)->papel, $id);
        }
        $this->assertSame(A::OPTIONAL, $c->atributo('GTIN')->obrigatoriedade);

        $condicional = self::classificar(self::CADEIRA, new ContextoClassificacao(condicionais: ['GTIN']));
        $this->assertSame(A::REQUIRED, $condicional->atributo('GTIN')->obrigatoriedade);
    }

    public function test_tc41_read_only_e_fixed_sao_do_sistema_mesmo_obrigatorios(): void
    {
        $cadeira = self::classificar(self::CADEIRA);
        $this->assertSame(A::SYSTEM, $cadeira->atributo('PACKAGE_HEIGHT')->papel);

        $pastilha = self::classificar(self::PASTILHA);
        $this->assertSame(A::SYSTEM, $pastilha->atributo('VEHICLE_TYPE')->papel, 'N-08: required + fixed');
        $this->assertSame(A::OPTIONAL, $pastilha->atributo('VEHICLE_TYPE')->obrigatoriedade, 'o ML preenche: não se cobra da pessoa');
        $this->assertSame(A::SYSTEM, $pastilha->atributo('GTIN')->papel, 'N-08: GTIN read_only na pastilha');
        $this->assertNotContains('VEHICLE_TYPE', $pastilha->idsDaSecao(A::SECAO_PRINCIPAIS));
    }

    public function test_tc34_new_required_so_obriga_produto_novo(): void
    {
        $ajustar = function (array $f) {
            foreach ($f['atributos'] as &$a) {
                if ($a['id'] === 'OFFICE_CHAIR_TYPE') {
                    $a['tags']['new_required'] = true;
                }
            }

            return $f;
        };

        $usado = self::classificar(self::CADEIRA, new ContextoClassificacao(condicao: 'used'), $ajustar);
        $novo = self::classificar(self::CADEIRA, new ContextoClassificacao(condicao: 'new'), $ajustar);

        $this->assertSame(A::OPTIONAL, $usado->atributo('OFFICE_CHAIR_TYPE')->obrigatoriedade);
        $this->assertSame(A::REQUIRED, $novo->atributo('OFFICE_CHAIR_TYPE')->obrigatoriedade);
    }

    public function test_h28_gtin_some_para_usado(): void
    {
        $this->assertSame(A::SECAO_VARIANTE, self::classificar(self::CADEIRA)->atributo('GTIN')->secao);
        $this->assertSame(A::SECAO_OCULTO, self::classificar(self::CADEIRA, new ContextoClassificacao(condicao: 'used'))->atributo('GTIN')->secao);
    }

    public function test_catalog_required_sem_required_e_recomendado(): void
    {
        $c = self::classificar(self::CADEIRA, ajustar: function (array $f) {
            foreach ($f['atributos'] as &$a) {
                if ($a['id'] === 'OFFICE_CHAIR_TYPE') {
                    $a['tags']['catalog_required'] = true;
                }
            }

            return $f;
        });

        $this->assertSame(A::RECOMMENDED, $c->atributo('OFFICE_CHAIR_TYPE')->obrigatoriedade);
    }

    public function test_h07_texto_livre_vem_do_allow_custom_value(): void
    {
        $this->assertTrue(self::classificar(self::PASTILHA)->atributo('BRAND')->aceitaTextoLivre, 'COMBO com allow_custom_value');
        $this->assertFalse(self::classificar(self::CADEIRA)->atributo('REQUIRES_ASSEMBLY')->aceitaTextoLivre);
        $this->assertTrue(self::classificar(self::CADEIRA)->atributo('MODEL')->aceitaTextoLivre, 'sem lista, é texto');
    }

    public function test_limites_saem_da_categoria(): void
    {
        $cadeira = self::classificar(self::CADEIRA)->limites;
        $this->assertSame(60, $cadeira['max_title_length']);
        $this->assertSame(12, $cadeira['max_pictures_per_item']);
        $this->assertSame(10, $cadeira['max_pictures_per_item_var']);
        $this->assertSame(100, $cadeira['max_variations_allowed']);

        $this->assertSame(200, self::classificar(self::PASTILHA)->limites['max_title_length']);
        $this->assertSame(8.0, self::classificar(self::CAMISETA)->limites['minimum_price']);
    }

    public function test_tc74_tabela_de_medidas_e_detectada_e_bloqueada(): void
    {
        $camiseta = self::classificar(self::CAMISETA);
        $this->assertTrue($camiseta->flags['needs_size_grid']);
        $this->assertContains('tabela_de_medidas', array_column($camiseta->bloqueiosFase2, 'motivo'));

        $this->assertFalse(self::classificar(self::CADEIRA)->flags['needs_size_grid']);
        $this->assertSame([], self::classificar(self::CADEIRA)->bloqueiosFase2);
    }

    public function test_tc72_tc73_folha_e_listing_allowed(): void
    {
        $this->assertTrue(self::classificar(self::CADEIRA)->flags['folha']);
        $this->assertTrue(self::classificar(self::CADEIRA)->flags['listing_allowed']);

        $pai = self::classificar(self::CADEIRA, ajustar: function (array $f) {
            $f['categoria']['children_categories'] = [['id' => 'MLB1', 'name' => 'Filha']];
            $f['categoria']['settings']['listing_allowed'] = false;

            return $f;
        });
        $this->assertFalse($pai->flags['folha']);
        $this->assertFalse($pai->flags['listing_allowed']);
    }

    public function test_tc70_o_mesmo_codigo_monta_formularios_diferentes(): void
    {
        $principais = fn (string $cat) => self::classificar($cat)->idsDaSecao(A::SECAO_PRINCIPAIS);

        $this->assertContains('UPHOLSTERY_MATERIAL', $principais(self::CADEIRA));
        $this->assertContains('VOLTAGE', $principais(self::FURADEIRA));
        $this->assertContains('GENDER', $principais(self::CAMISETA));
        $this->assertContains('PART_NUMBER', $principais(self::PASTILHA));
        $this->assertNotContains('VOLTAGE', $principais(self::CADEIRA));
        $this->assertNotEquals($principais(self::CADEIRA), $principais(self::FURADEIRA));

        // Nenhum `if categoria ==` no núcleo: o formulário vem só dos dados.
        foreach (glob(dirname(__DIR__, 4).'/app/Support/Publicador/Schema/*.php') as $arquivo) {
            $this->assertDoesNotMatchRegularExpression('/MLB\d{3,}/', file_get_contents($arquivo), basename($arquivo));
        }
    }

    public function test_grupos_seguem_o_technical_specs_e_ocultos_vao_para_avancado(): void
    {
        $c = self::classificar(self::CADEIRA);

        $this->assertSame('MAIN', $c->grupos[0]['id']);
        $this->assertSame('Características principais', $c->grupos[0]['label']);
        $this->assertSame(A::SECAO_AVANCADO, $c->atributo('LUMBAR_SUPPORT_TYPE')->secao, 'hidden e editável');
        $this->assertSame(A::SECAO_EMBALAGEM, $c->atributo('SELLER_PACKAGE_WEIGHT')->secao);
        $this->assertSame(A::SECAO_CONDICAO, $c->atributo('ITEM_CONDITION')->secao);
        foreach ($c->grupos as $g) {
            $this->assertNotContains('PACKAGE_HEIGHT', $g['atributos'], 'atributo de sistema não aparece');
        }
    }

    public function test_rn22_hash_estavel_e_muda_quando_a_categoria_muda(): void
    {
        $this->assertSame(self::schema(self::CADEIRA)->hash(), self::schema(self::CADEIRA)->hash());
        $this->assertNotSame(self::schema(self::CADEIRA)->hash(), self::schema(self::CADEIRA, function (array $f) {
            $f['categoria']['settings']['max_title_length'] = 61;

            return $f;
        })->hash());
        $this->assertSame('MLB-OFFICE_CHAIRS', self::schema(self::CADEIRA)->dominio(), 'H-16');
    }
}
