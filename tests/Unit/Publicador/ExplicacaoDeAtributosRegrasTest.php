<?php

namespace Tests\Unit\Publicador;

use App\Services\Publicador\ExplicacaoDeAtributos;
use PHPUnit\Framework\TestCase;

/**
 * As partes puras da explicação dos campos (08/10/2026): o glossário cabe na regra (curto e
 * NEUTRO — o mesmo texto vai ao Portal), o filtro de sigilo do Portal, a regra do texto da IA e o
 * texto montado enquanto a IA não escreveu.
 */
class ExplicacaoDeAtributosRegrasTest extends TestCase
{
    private static function glossario(): array
    {
        return require dirname(__DIR__, 3).'/config/publicador_glossario.php';
    }

    public function test_glossario_curto_neutro_e_aceito_pela_propria_regra_da_ia(): void
    {
        $g = self::glossario();
        $this->assertNotEmpty($g['atributos']);
        $this->assertNotEmpty($g['portal_campos'], 'os campos fixos da ficha do Portal');
        foreach ([...$g['atributos'], ...$g['campos'], ...$g['portal_campos']] as $id => $texto) {
            $this->assertIsString($texto, $id);
            $this->assertLessThanOrEqual(ExplicacaoDeAtributos::LIMITE, mb_strlen($texto), "{$id} passou de 220 caracteres");
            $this->assertFalse(ExplicacaoDeAtributos::revelaDestino($texto), "{$id} entrega o destino do cadastro: {$texto}");
            $this->assertSame($texto, ExplicacaoDeAtributos::limparTextoDaIa($texto), "{$id} não passaria na regra do texto");
        }
    }

    public function test_glossario_cobre_as_siglas_e_codigos_que_a_equipe_perguntou(): void
    {
        $g = self::glossario()['atributos'];
        foreach (['SELLER_SKU', 'GTIN', 'EMPTY_GTIN_REASON', 'MPN', 'AGID', 'INMETRO_CERTIFICATION_REGISTRATION_NUMBER', 'MODEL', 'BRAND', 'LINE',
            'ALPHANUMERIC_MODEL', 'UNITS_PER_PACK', 'SALE_FORMAT', 'IS_KIT', 'PRODUCT_DATA_SOURCE',
            'SELLER_PACKAGE_HEIGHT', 'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_LENGTH', 'SELLER_PACKAGE_WEIGHT'] as $id) {
            $this->assertArrayHasKey($id, $g, $id);
        }
        // O exemplo do pedido: diz o que é E quando deixar vazio.
        $this->assertStringContainsString('part number', $g['MPN']);
        $this->assertStringContainsString('pode deixar vazio', $g['MPN']);
        $this->assertStringContainsString('EAN', $g['GTIN']);
        $this->assertStringContainsString('UPC', $g['GTIN']);
        $this->assertArrayHasKey('estoque', self::glossario()['campos']);
    }

    public function test_portal_campos_cobre_os_campos_fixos_da_ficha_e_o_estoque_vem_do_editor(): void
    {
        $portal = self::glossario()['portal_campos'];
        foreach (['nome', 'familia', 'ambientes', 'categoria', 'ref', 'eixo', 'valor', 'custo', 'volumes',
            'comprimento', 'largura', 'altura', 'peso', 'descricao', 'estoque_produto'] as $chave) {
            $this->assertArrayHasKey($chave, $portal, $chave);
        }
        // Um texto só para o Estoque nas duas telas: ele mora em `campos`, não aqui.
        $this->assertArrayNotHasKey('estoque', $portal);
    }

    public function test_sigilo_pega_plataforma_com_e_sem_acento_e_poupa_palavras_neutras(): void
    {
        foreach ([
            'Informe a marca no seu anúncio.', 'Use o mesmo código do anuncio original.', 'Exigido pelo Mercado Livre.', 'Campo do mercadolivre',
            'Aparece no MLB do produto.', 'Antes de publicar, confira.', 'Publicação com fotos.', 'Regra do marketplace.', 'Dados do vendedor do ML.',
            'Visível no ML.', 'Como na Shopee.', 'Exigido pela plataforma.', 'Preço de mercado.',
        ] as $texto) {
            $this->assertTrue(ExplicacaoDeAtributos::revelaDestino($texto), $texto);
        }
        foreach ([
            'Capacidade do copo, em ml.', 'Quantas unidades vêm em cada embalagem.', 'O nome que o comprador vê.', 'Produtos vendidos no Brasil.',
            'Mercadoria importada.', 'Código de barras (EAN-13).',
        ] as $texto) {
            $this->assertFalse(ExplicacaoDeAtributos::revelaDestino($texto), $texto);
        }
    }

    public function test_regra_do_texto_da_ia_descarta_longo_html_vazio_e_quem_cita_plataforma_ou_loja(): void
    {
        $this->assertSame('Altura do encosto da cadeira.', ExplicacaoDeAtributos::limparTextoDaIa("  \"Altura do  encosto\n da cadeira.\" "));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa(str_repeat('a', 221)));
        $this->assertNotNull(ExplicacaoDeAtributos::limparTextoDaIa(str_repeat('a', 220)));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('Use <b>negrito</b>.'));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('Medida &nbsp; do assento.'));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('   '));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa(['texto' => 'x']));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('Aparece no anúncio do produto.'));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('Mostrado na página da loja.'));
        $this->assertNull(ExplicacaoDeAtributos::limparTextoDaIa('Exigido pelo site.'));
    }

    public function test_texto_montado_pelo_tipo_e_pela_unidade(): void
    {
        $this->assertSame('Largura do assento, em centímetros.', ExplicacaoDeAtributos::provisorio(['nome' => 'Largura do assento', 'tipo' => 'number_unit', 'unidades' => ['cm', 'm']]));
        $this->assertSame('Peso máximo suportado, em quilos.', ExplicacaoDeAtributos::provisorio(['nome' => 'Peso máximo suportado', 'tipo' => 'number_unit', 'unidade_padrao' => 'kg', 'unidades' => ['g', 'kg']]));
        $this->assertSame('Potência, em xyz.', ExplicacaoDeAtributos::provisorio(['nome' => 'Potência', 'tipo' => 'number_unit', 'unidades' => ['xyz']]));
        $this->assertSame('Quantidade de baterias, em número.', ExplicacaoDeAtributos::provisorio(['nome' => 'Quantidade de baterias', 'tipo' => 'number']));
        $this->assertSame('É giratória? Responda Sim ou Não.', ExplicacaoDeAtributos::provisorio(['nome' => 'É giratória', 'tipo' => 'boolean']));
        $this->assertSame('Tipo de gola. Escolha na lista a opção que descreve o produto.', ExplicacaoDeAtributos::provisorio(['nome' => 'Tipo de gola', 'tipo' => 'list']));
        $this->assertSame('Material principal do produto.', ExplicacaoDeAtributos::provisorio(['nome' => 'Material principal', 'tipo' => 'string']));
        $this->assertSame('X_SEM_NOME do produto.', ExplicacaoDeAtributos::provisorio(['id' => 'X_SEM_NOME']));
    }
}
