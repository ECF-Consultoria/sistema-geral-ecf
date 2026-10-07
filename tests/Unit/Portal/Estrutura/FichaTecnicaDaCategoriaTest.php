<?php

namespace Tests\Unit\Portal\Estrutura;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria as F;
use PHPUnit\Framework\TestCase;

/**
 * A definição da ficha técnica: função pura, atributos crus → grupos para a tela.
 * Sem banco e sem HTTP. Os modos de falha que isto impede: campo de sistema ou de
 * variação aparecendo para o cliente, tipo mapeado errado, obrigatório perdido no
 * meio de uma lista longa, e rótulo que revela a origem dos campos indo para a tela.
 */
class FichaTecnicaDaCategoriaTest extends TestCase
{
    /** Um atributo de cada tipo, mais os que precisam ser descartados. */
    private static function atributos(): array
    {
        return [
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais',
                'values' => [['id' => '101', 'name' => 'Madeira'], ['id' => '102', 'name' => 'Metal']]],
            ['id' => 'WIDTH', 'name' => 'Largura', 'value_type' => 'number_unit', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões',
                'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']], 'default_unit' => 'cm'],
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais', 'value_max_length' => 60],
            ['id' => 'WEIGHT_CAP', 'name' => 'Capacidade', 'value_type' => 'number', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões'],
            // Sem grupo: cai em "Outras características". `tags` como lista também é lido.
            ['id' => 'WITH_DRAWER', 'name' => 'Com gaveta', 'value_type' => 'boolean', 'tags' => ['catalog_required']],

            // ── descartados ──
            ['id' => 'ITEM_CONDITION', 'name' => 'Condição', 'value_type' => 'list', 'tags' => ['hidden' => true]],
            ['id' => 'MODEL_X', 'name' => 'Interno', 'value_type' => 'string', 'tags' => ['read_only' => true]],
            ['id' => 'FIXO', 'name' => 'Fixo', 'value_type' => 'string', 'tags' => ['fixed' => true]],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            ['id' => 'VOLTAGE', 'name' => 'Voltagem', 'value_type' => 'list', 'tags' => ['variation_attribute']],
            ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_type' => 'string', 'tags' => []],
            ['id' => 'PACKAGE_WEIGHT', 'name' => 'Peso da embalagem', 'value_type' => 'number_unit', 'tags' => []],
            ['id' => 'SIZE_GRID_ID', 'name' => 'Grade', 'value_type' => 'grid_id', 'tags' => []],
            ['id' => 'ESTRANHO', 'name' => 'Tipo desconhecido', 'value_type' => 'pack_unit', 'tags' => []],
        ];
    }

    private static function campo(array $grupos, string $id): array
    {
        return F::camposPorId($grupos)[$id];
    }

    public function test_agrupa_pelo_nome_do_grupo_e_cai_em_outras_caracteristicas(): void
    {
        $grupos = F::daAtributos(self::atributos());

        $this->assertSame(['Principais', 'Dimensões', F::GRUPO_PADRAO], array_column($grupos, 'grupo'));
        $this->assertSame(['WIDTH', 'WEIGHT_CAP'], array_column($grupos[1]['campos'], 'id'));
        $this->assertSame(['WITH_DRAWER'], array_column($grupos[2]['campos'], 'id'));
    }

    public function test_obrigatorios_ficam_em_destaque_no_grupo_e_grupo_com_obrigatorio_vem_primeiro(): void
    {
        // Entrada com o grupo "Dimensões" (sem obrigatório) ANTES do grupo com obrigatórios.
        $atributos = [
            ['id' => 'A', 'name' => 'A', 'value_type' => 'string', 'attribute_group_name' => 'Dimensões'],
            ['id' => 'B', 'name' => 'B', 'value_type' => 'string', 'attribute_group_name' => 'Principais'],
            ['id' => 'C', 'name' => 'C', 'value_type' => 'string', 'attribute_group_name' => 'Principais', 'tags' => ['required' => true]],
        ];

        $grupos = F::daAtributos($atributos);

        $this->assertSame(['Principais', 'Dimensões'], array_column($grupos, 'grupo'));
        $this->assertSame(['C', 'B'], array_column($grupos[0]['campos'], 'id'));
        $this->assertTrue($grupos[0]['campos'][0]['obrigatorio']);
        $this->assertFalse($grupos[0]['campos'][1]['obrigatorio']);
    }

    public function test_descarta_sistema_variacao_o_que_a_ficha_ja_cobre_e_tipo_desconhecido(): void
    {
        $ids = array_keys(F::camposPorId(F::daAtributos(self::atributos())));

        sort($ids);
        $this->assertSame(['BRAND', 'MATERIAL', 'WEIGHT_CAP', 'WIDTH', 'WITH_DRAWER'], $ids);
    }

    public function test_mapeia_value_type_para_o_tipo_da_tela(): void
    {
        $grupos = F::daAtributos(self::atributos());

        $this->assertSame(F::TIPO_TEXTO, self::campo($grupos, 'BRAND')['tipo']);
        $this->assertSame(F::TIPO_NUMERO, self::campo($grupos, 'WEIGHT_CAP')['tipo']);
        $this->assertSame(F::TIPO_NUMERO_UNIDADE, self::campo($grupos, 'WIDTH')['tipo']);
        $this->assertSame(F::TIPO_SIM_NAO, self::campo($grupos, 'WITH_DRAWER')['tipo']);
        $this->assertSame(F::TIPO_LISTA, self::campo($grupos, 'MATERIAL')['tipo']);
    }

    public function test_lista_traz_valores_numero_unidade_traz_unidades_e_texto_traz_o_maximo(): void
    {
        $grupos = F::daAtributos(self::atributos());

        $this->assertSame([['id' => '101', 'nome' => 'Madeira'], ['id' => '102', 'nome' => 'Metal']], self::campo($grupos, 'MATERIAL')['valores']);
        $this->assertSame([['id' => 'cm', 'nome' => 'cm'], ['id' => 'mm', 'nome' => 'mm']], self::campo($grupos, 'WIDTH')['unidades']);
        $this->assertSame('cm', self::campo($grupos, 'WIDTH')['unidade_padrao']);
        $this->assertSame(60, self::campo($grupos, 'BRAND')['max']);
        $this->assertNull(self::campo($grupos, 'WEIGHT_CAP')['max']);
        $this->assertTrue(self::campo($grupos, 'BRAND')['obrigatorio']);
        $this->assertFalse(self::campo($grupos, 'WIDTH')['obrigatorio']);
    }

    public function test_lista_sem_opcoes_vira_texto_e_unidade_padrao_desconhecida_vira_a_primeira(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'VAZIA', 'name' => 'Vazia', 'value_type' => 'list', 'values' => []],
            ['id' => 'ALT', 'name' => 'Altura', 'value_type' => 'number_unit',
                'allowed_units' => [['id' => 'm', 'name' => 'm'], ['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'km'],
        ]);

        $this->assertSame(F::TIPO_TEXTO, self::campo($grupos, 'VAZIA')['tipo']);
        $this->assertSame('m', self::campo($grupos, 'ALT')['unidade_padrao']);
    }

    public function test_o_que_cita_a_origem_dos_campos_nao_chega_a_tela(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'OK', 'name' => 'Material', 'value_type' => 'string', 'attribute_group_name' => 'Principais',
                // Texto de ajuda que cita a plataforma: nunca é repassado.
                'hint' => 'Preencha como no Mercado Livre', 'tooltip' => 'Aparece no anúncio'],
            ['id' => 'RUIM', 'name' => 'Aparece no Mercado Livre', 'value_type' => 'string'],
            ['id' => 'GRUPO', 'name' => 'Cor da tampa', 'value_type' => 'string', 'attribute_group_name' => 'Dados do anúncio'],
            ['id' => 'OPCOES', 'name' => 'Acabamento', 'value_type' => 'list',
                'values' => [['id' => '1', 'name' => 'Fosco'], ['id' => '2', 'name' => 'Para publicar'], ['id' => 'MLB1', 'name' => 'Brilho']]],
        ]);

        $json = json_encode($grupos, JSON_UNESCAPED_UNICODE);

        $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publicar|mlb/iu', $json);
        $this->assertArrayNotHasKey('RUIM', F::camposPorId($grupos));
        $this->assertSame(F::GRUPO_PADRAO, collect($grupos)->first(fn ($g) => in_array('GRUPO', array_column($g['campos'], 'id'), true))['grupo']);
        $this->assertSame([['id' => '1', 'nome' => 'Fosco']], self::campo($grupos, 'OPCOES')['valores']);
        $this->assertArrayNotHasKey('hint', self::campo($grupos, 'OK'));
    }

    public function test_resposta_vazia_ou_malformada_devolve_lista_vazia(): void
    {
        $this->assertSame([], F::daAtributos([]));
        $this->assertSame([], F::daAtributos(['x', null, 3, ['sem_id' => true]]));
    }
}
