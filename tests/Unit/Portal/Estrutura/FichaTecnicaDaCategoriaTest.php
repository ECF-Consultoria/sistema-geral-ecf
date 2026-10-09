<?php

namespace Tests\Unit\Portal\Estrutura;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria as F;
use App\Support\Publicador\Schema\AtributoClassificado as A;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * A definição da ficha técnica: função pura, atributos crus → grupos para a tela.
 * Sem banco e sem HTTP. Os modos de falha que isto impede: campo de sistema ou de
 * variação aparecendo para o cliente, tipo mapeado errado, obrigatório perdido no
 * meio de uma lista longa, e rótulo que revela a origem dos campos indo para a tela.
 */
class FichaTecnicaDaCategoriaTest extends TestCase
{
    use CarregaSchemas;

    /** Um atributo de cada tipo, mais os que precisam ser descartados. */
    private static function atributos(): array
    {
        return [
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais',
                'values' => [['id' => '101', 'name' => 'Madeira'], ['id' => '102', 'name' => 'Metal']]],
            ['id' => 'SEAT_HEIGHT', 'name' => 'Altura do assento', 'value_type' => 'number_unit', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões',
                'allowed_units' => [['id' => 'cm', 'name' => 'cm'], ['id' => 'mm', 'name' => 'mm']], 'default_unit' => 'cm'],
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true],
                'attribute_group_id' => 'MAIN', 'attribute_group_name' => 'Principais', 'value_max_length' => 60],
            ['id' => 'WEIGHT_CAP', 'name' => 'Capacidade', 'value_type' => 'number', 'tags' => [],
                'attribute_group_id' => 'DIM', 'attribute_group_name' => 'Dimensões'],
            // Sem grupo: cai em "Outras características". `tags` como lista também é lido.
            ['id' => 'WITH_DRAWER', 'name' => 'Com gaveta', 'value_type' => 'boolean', 'tags' => ['catalog_required']],
            // Eixo do portal que a categoria deixa variar: entra MARCADO; quem tira é o produto que varia por cor.
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],

            // ── descartados ──
            ['id' => 'ITEM_CONDITION', 'name' => 'Condição', 'value_type' => 'list', 'tags' => ['hidden' => true]],
            ['id' => 'MODEL_X', 'name' => 'Interno', 'value_type' => 'string', 'tags' => ['read_only' => true]],
            ['id' => 'FIXO', 'name' => 'Fixo', 'value_type' => 'string', 'tags' => ['fixed' => true]],
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
        $this->assertSame(['SEAT_HEIGHT', 'WEIGHT_CAP'], array_column($grupos[1]['campos'], 'id'));
        $this->assertSame(['WITH_DRAWER', 'COLOR'], array_column($grupos[2]['campos'], 'id'));
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
        $this->assertSame(['BRAND', 'COLOR', 'MATERIAL', 'SEAT_HEIGHT', 'WEIGHT_CAP', 'WITH_DRAWER'], $ids);

        // A cor só sai no produto que varia por ela.
        $doProdutoPorCor = array_keys(F::camposPorId(F::doProduto(F::daAtributos(self::atributos()), ['cor'])));
        sort($doProdutoPorCor);
        $this->assertSame(['BRAND', 'MATERIAL', 'SEAT_HEIGHT', 'WEIGHT_CAP', 'WITH_DRAWER'], $doProdutoPorCor);
    }

    public function test_mapeia_value_type_para_o_tipo_da_tela(): void
    {
        $grupos = F::daAtributos(self::atributos());

        $this->assertSame(F::TIPO_TEXTO, self::campo($grupos, 'BRAND')['tipo']);
        $this->assertSame(F::TIPO_NUMERO, self::campo($grupos, 'WEIGHT_CAP')['tipo']);
        $this->assertSame(F::TIPO_NUMERO_UNIDADE, self::campo($grupos, 'SEAT_HEIGHT')['tipo']);
        $this->assertSame(F::TIPO_SIM_NAO, self::campo($grupos, 'WITH_DRAWER')['tipo']);
        $this->assertSame(F::TIPO_LISTA, self::campo($grupos, 'MATERIAL')['tipo']);
    }

    public function test_lista_traz_valores_numero_unidade_traz_unidades_e_texto_traz_o_maximo(): void
    {
        $grupos = F::daAtributos(self::atributos());

        $this->assertSame([['id' => '101', 'nome' => 'Madeira'], ['id' => '102', 'nome' => 'Metal']], self::campo($grupos, 'MATERIAL')['valores']);
        $this->assertSame([['id' => 'cm', 'nome' => 'cm'], ['id' => 'mm', 'nome' => 'mm']], self::campo($grupos, 'SEAT_HEIGHT')['unidades']);
        $this->assertSame('cm', self::campo($grupos, 'SEAT_HEIGHT')['unidade_padrao']);
        $this->assertSame(60, self::campo($grupos, 'BRAND')['max']);
        $this->assertNull(self::campo($grupos, 'WEIGHT_CAP')['max']);
        $this->assertTrue(self::campo($grupos, 'BRAND')['obrigatorio']);
        $this->assertFalse(self::campo($grupos, 'SEAT_HEIGHT')['obrigatorio']);
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

    // ═══ Quem tem opção vira lista, qualquer que seja o `value_type` ═══════════

    /**
     * O modo de falha que isto impede: o catálogo manda a maior parte das opções em
     * atributo `string` COM `values`. Lendo `values` só no `list`, esses campos viravam
     * texto livre e o cliente digitava valor fora da lista ("REDONDO" onde a opção é
     * "Redonda") — que a plataforma recusa na publicação.
     */
    public function test_atributo_string_com_opcoes_vira_lista_e_nao_texto_livre(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'SHAPE', 'name' => 'Forma', 'value_type' => 'string', 'tags' => ['required' => true],
                'values' => [['id' => '1', 'name' => 'Quadrada'], ['id' => '2', 'name' => 'Redonda']]],
            ['id' => 'FABRIC_DESIGN', 'name' => 'Desenho do tecido', 'value_type' => 'string', 'tags' => [],
                'values' => [['id' => '10', 'name' => 'Liso'], ['id' => '11', 'name' => 'Listras']]],
            // Sem opção nenhuma, segue texto livre.
            ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => []],
        ]);

        $this->assertSame(F::TIPO_LISTA, self::campo($grupos, 'SHAPE')['tipo']);
        $this->assertSame([['id' => '1', 'nome' => 'Quadrada'], ['id' => '2', 'nome' => 'Redonda']], self::campo($grupos, 'SHAPE')['valores']);
        $this->assertSame(F::TIPO_LISTA, self::campo($grupos, 'FABRIC_DESIGN')['tipo']);
        $this->assertSame(F::TIPO_TEXTO, self::campo($grupos, 'MODEL')['tipo'], 'sem opção, texto livre');
    }

    public function test_numero_e_sim_nao_com_opcoes_mantem_o_proprio_controle(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'LEGS_NUMBER', 'name' => 'Quantidade de pés', 'value_type' => 'number', 'tags' => [],
                'values' => [['id' => '1', 'name' => '3'], ['id' => '2', 'name' => '4']]],
            ['id' => 'IS_FOLDABLE', 'name' => 'É dobrável', 'value_type' => 'boolean', 'tags' => [],
                'values' => [['id' => 's', 'name' => 'Sim'], ['id' => 'n', 'name' => 'Não']]],
        ]);

        $this->assertSame(F::TIPO_NUMERO, self::campo($grupos, 'LEGS_NUMBER')['tipo']);
        $this->assertSame([], self::campo($grupos, 'LEGS_NUMBER')['valores'], 'número não carrega lista de opções');
        $this->assertSame(F::TIPO_SIM_NAO, self::campo($grupos, 'IS_FOLDABLE')['tipo']);
        $this->assertSame([], self::campo($grupos, 'IS_FOLDABLE')['valores']);
    }

    public function test_lista_marcada_multivalued_aceita_mais_de_uma_opcao(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'MATERIALS', 'name' => 'Materiais', 'value_type' => 'string', 'tags' => ['multivalued' => true],
                'values' => [['id' => '1', 'name' => 'Algodão'], ['id' => '2', 'name' => 'Couro']]],
            ['id' => 'STYLE', 'name' => 'Estilo', 'value_type' => 'list', 'tags' => [],
                'values' => [['id' => '9', 'name' => 'Clássico']]],
            // Sem opção o campo é texto livre: multivalor não se aplica.
            ['id' => 'GTIN', 'name' => 'Código universal', 'value_type' => 'string', 'tags' => ['multivalued' => true]],
        ]);

        $this->assertTrue(self::campo($grupos, 'MATERIALS')['multivalor']);
        $this->assertSame(F::TIPO_LISTA, self::campo($grupos, 'MATERIALS')['tipo']);
        $this->assertFalse(self::campo($grupos, 'STYLE')['multivalor'], 'lista comum escolhe uma só');
        $this->assertFalse(self::campo($grupos, 'GTIN')['multivalor'], 'texto livre não vira chips');
    }

    // ═══ Medida do produto × medida do embalado (Volumes) ═════════════════════

    /**
     * O modo de falha que isto impede: a ficha mostrava DOIS conjuntos de medida — o do
     * produto (daqui) e o do embalado (Volumes) — e a pessoa digitava duas vezes. Só o
     * embalado alimenta peso cubado, logística e frete.
     */
    public function test_medida_do_produto_sai_da_ficha_quando_a_categoria_nao_exige(): void
    {
        $crus = [];
        foreach (['LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER', 'WEIGHT'] as $id) {
            $crus[] = ['id' => $id, 'name' => "Medida {$id}", 'value_type' => 'number_unit', 'tags' => []];
        }
        // Não é medida do produto: é quanto ele aguenta. Fica.
        $crus[] = ['id' => 'MAX_WEIGHT_SUPPORTED', 'name' => 'Peso máximo suportado', 'value_type' => 'number_unit', 'tags' => []];

        $campos = F::camposPorId(F::daAtributos($crus));

        foreach (['LENGTH', 'WIDTH', 'HEIGHT', 'DEPTH', 'DIAMETER', 'WEIGHT'] as $id) {
            $this->assertArrayNotHasKey($id, $campos, "{$id} duplica o Volume e não deve aparecer");
        }
        $this->assertArrayHasKey('MAX_WEIGHT_SUPPORTED', $campos);
    }

    public function test_medida_do_produto_fica_quando_a_categoria_exige(): void
    {
        $campos = F::camposPorId(F::daAtributos([
            ['id' => 'HEIGHT', 'name' => 'Altura', 'value_type' => 'number_unit', 'tags' => ['required' => true]],
            ['id' => 'WIDTH', 'name' => 'Largura', 'value_type' => 'number_unit', 'tags' => []],
        ]));

        $this->assertArrayHasKey('HEIGHT', $campos, 'exigida pela categoria, esconder deixaria o cadastro incompleto');
        $this->assertTrue($campos['HEIGHT']['obrigatorio']);
        $this->assertArrayNotHasKey('WIDTH', $campos);
    }

    // ═══ Os campos que o editor interno deixa preencher (08/10/2026) ══════════

    /** Os 15 `hidden` editáveis de Cadeiras de Escritório (MLB193945), na ordem da categoria. */
    private const HIDDEN_EDITAVEIS_DA_CADEIRA = [
        'LUMBAR_SUPPORT_TYPE', 'STRUCTURE_FINISH', 'LEAN_BACK_MECHANISM_TYPES', 'BACKREST_TILT_RANGE',
        'OFFICE_CHAIR_HEIGHT', 'OFFICE_CHAIR_DEPTH', 'BASE_DIAMETER', 'OFFICE_CHAIR_WEIGHT', 'SEAT_WIDTH',
        'BACKREST_WIDTH', 'MIN_HEIGHT_FROM_FLOOR_TO_SEAT', 'MAX_HEIGHT_FROM_FLOOR_TO_SEAT', 'MIN_CHAIR_HEIGHT',
        'IS_KIT', 'PRODUCT_DATA_SOURCE',
    ];

    private static function atributosDaCadeira(): array
    {
        return self::schema(self::CADEIRA)->atributos;
    }

    /**
     * O modo de falha que isto impede: duas regras separadas para "o que o cliente preenche".
     * A ficha descartava `hidden` e `allow_variations` inteiros; o editor interno deixa
     * preencher os `hidden` (como "Avançado") e todo `allow_variations` que não é o eixo.
     * Resultado: 16 campos que a equipe preenchia à mão e o cliente nunca via.
     *
     * A régua é o próprio classificador do editor, com o schema COMPLETO da categoria
     * (technical_specs incluído): a ficha do produto que varia por cor é a lista do editor com a
     * cor como eixo; a do produto sem eixo é a lista do editor sem eixo (aí a cor é atributo).
     */
    public function test_na_cadeira_a_ficha_tem_exatamente_os_atributos_de_produto_que_o_editor_deixa_editar(): void
    {
        $doEditor = function (array $eixos): array {
            $classificado = (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao('new', $eixos));
            $ids = array_keys(array_filter($classificado->atributos, fn (A $a) => $a->papel === A::PRODUCT
                && in_array($a->secao, [A::SECAO_PRINCIPAIS, A::SECAO_FICHA, A::SECAO_AVANCADO], true)));
            sort($ids);

            return [$ids, $classificado];
        };
        $definicao = F::daAtributos(self::atributosDaCadeira());

        // Produto que varia por cor (o caso da cadeira de escritório).
        [$editorPorCor] = $doEditor(['COLOR']);
        $porCor = array_keys(F::camposPorId(F::doProduto($definicao, ['cor'])));
        sort($porCor);
        $this->assertSame($editorPorCor, $porCor);
        $this->assertCount(44, $porCor, '28 de antes + 15 escondidos editáveis + o estofamento');

        // Produto sem eixo: a cor volta a ser atributo do produto, dos dois lados.
        [$editorSemEixo, $classificado] = $doEditor([]);
        $campos = F::camposPorId($definicao);
        $semEixo = array_keys($campos);
        sort($semEixo);
        $this->assertSame($editorSemEixo, $semEixo);
        $this->assertSame(['COLOR'], array_values(array_diff($semEixo, $porCor)));

        // E o "Não se aplica" é o mesmo dos dois lados.
        foreach ($campos as $id => $campo) {
            $this->assertSame($classificado->atributos[$id]->aceitaNaoSeAplica, $campo['nao_se_aplica'], "N/A de {$id}");
        }
    }

    public function test_na_cadeira_os_hidden_editaveis_vao_para_mais_detalhes_no_fim_e_o_estofamento_entra(): void
    {
        $grupos = F::daAtributos(self::atributosDaCadeira());
        $ultimo = end($grupos);

        $this->assertSame(F::GRUPO_MAIS_DETALHES, $ultimo['grupo']);
        $this->assertSame(self::HIDDEN_EDITAVEIS_DA_CADEIRA, array_column($ultimo['campos'], 'id'));
        foreach ($ultimo['campos'] as $campo) {
            $this->assertFalse($campo['obrigatorio'], "{$campo['id']} num grupo opcional");
        }

        $campos = F::camposPorId($grupos);
        $this->assertSame(F::TIPO_LISTA, $campos['UPHOLSTERY_MATERIAL']['tipo']);
        $this->assertSame('Material do estofamento', $campos['UPHOLSTERY_MATERIAL']['nome']);
        $this->assertNotSame(F::GRUPO_MAIS_DETALHES, $grupos[0]['grupo']);
        $this->assertContains('UPHOLSTERY_MATERIAL', array_column($grupos[0]['campos'], 'id'), 'o estofamento é campo principal');
        $this->assertSame(F::TIPO_NUMERO_UNIDADE, $campos['SEAT_WIDTH']['tipo']);
        $this->assertContains('cm', array_column($campos['SEAT_WIDTH']['unidades'], 'id'));
        $this->assertSame(F::TIPO_SIM_NAO, $campos['IS_KIT']['tipo']);
        $this->assertTrue($campos['LEAN_BACK_MECHANISM_TYPES']['multivalor']);

        // A cor entra marcada como eixo do portal; no produto que varia por cor ela sai.
        $this->assertSame('cor', $campos['COLOR']['eixo_do_portal']);
        $this->assertArrayNotHasKey('COLOR', F::camposPorId(F::doProduto($grupos, ['cor'])));

        // Nunca: sistema, dado de variante, condição, pacote.
        foreach (['MAIN_COLOR', 'FILTRABLE_COLOR', 'SELLER_SKU', 'GTIN', 'EMPTY_GTIN_REASON', 'MPN', 'ITEM_CONDITION',
            'SELLER_PACKAGE_WIDTH', 'SELLER_PACKAGE_WEIGHT', 'PACKAGE_WEIGHT', 'LINE', 'CATALOG_TITLE', 'VERTICAL_TAGS'] as $fora) {
            $this->assertArrayNotHasKey($fora, $campos, "{$fora} não é do cliente");
        }
    }

    /**
     * O modo de falha que isto impede: a regra do eixo ser por CATEGORIA. Onde MATERIAL deixa
     * variar, ele sumia da ficha de todo produto da categoria — inclusive do que varia só por cor,
     * que ficava sem onde dizer o material. A definição marca; o produto decide.
     */
    public function test_eixo_do_portal_entra_marcado_e_so_sai_do_produto_que_varia_por_ele(): void
    {
        $definicao = F::daAtributos([
            // Eixos do portal com `allow_variations`: entram, marcados com o eixo.
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            ['id' => 'SIZE', 'name' => 'Tamanho', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'string', 'tags' => ['allow_variations' => true]],
            // Não é eixo do portal: entra sem marca, mesmo deixando variar.
            ['id' => 'UPHOLSTERY_MATERIAL', 'name' => 'Material do estofamento', 'value_type' => 'string',
                'tags' => ['allow_variations' => true], 'values' => [['id' => '1', 'name' => 'Couro']]],
            // Eixo do portal SEM `allow_variations` nesta categoria: é atributo comum, sem marca.
            ['id' => 'VOLTAGE', 'name' => 'Voltagem', 'value_type' => 'string', 'tags' => []],
        ]);
        $campos = F::camposPorId($definicao);

        $this->assertSame(['COLOR', 'SIZE', 'MATERIAL', 'UPHOLSTERY_MATERIAL', 'VOLTAGE'], array_keys($campos));
        $this->assertSame(
            ['COLOR' => 'cor', 'SIZE' => 'tamanho', 'MATERIAL' => 'material', 'UPHOLSTERY_MATERIAL' => null, 'VOLTAGE' => null],
            array_map(fn ($c) => $c['eixo_do_portal'], $campos),
        );

        $ids = fn (array $eixos) => array_keys(F::camposPorId(F::doProduto($definicao, $eixos)));
        // Varia por cor: o material fica (é onde o cliente o informa).
        $this->assertSame(['SIZE', 'MATERIAL', 'UPHOLSTERY_MATERIAL', 'VOLTAGE'], $ids(['cor']));
        // Varia por material: o material sai (o valor vem da variação).
        $this->assertSame(['COLOR', 'SIZE', 'UPHOLSTERY_MATERIAL', 'VOLTAGE'], $ids(['material']));
        // Sem eixo, "Outro" ou vazio: tudo fica. Voltagem sem `allow_variations` nunca sai.
        $this->assertSame(array_keys($campos), $ids([]));
        $this->assertSame(array_keys($campos), $ids(['outro', null, '']));
        $this->assertSame(['COLOR', 'SIZE', 'MATERIAL', 'UPHOLSTERY_MATERIAL', 'VOLTAGE'], $ids(['voltagem']));
        // Dois eixos no mesmo produto (variações misturadas): os dois saem.
        $this->assertSame(['SIZE', 'UPHOLSTERY_MATERIAL', 'VOLTAGE'], $ids(['cor', 'material']));
    }

    public function test_grupo_que_fica_vazio_no_produto_sai(): void
    {
        $definicao = F::daAtributos([
            ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => [], 'attribute_group_name' => 'Principais'],
            ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'string', 'tags' => ['allow_variations' => true], 'attribute_group_name' => 'Cores'],
        ]);

        $this->assertSame(['Principais', 'Cores'], array_column($definicao, 'grupo'));
        $this->assertSame(['Principais'], array_column(F::doProduto($definicao, ['cor']), 'grupo'));
    }

    public function test_hidden_obrigatorio_fica_no_grupo_normal_e_nao_aceita_nao_se_aplica(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'A', 'name' => 'Escondido opcional', 'value_type' => 'string', 'tags' => ['hidden' => true]],
            ['id' => 'B', 'name' => 'Escondido exigido', 'value_type' => 'string', 'tags' => ['hidden' => true, 'required' => true]],
            ['id' => 'C', 'name' => 'Comum', 'value_type' => 'string', 'tags' => []],
        ]);

        $this->assertSame([F::GRUPO_PADRAO, F::GRUPO_MAIS_DETALHES], array_column($grupos, 'grupo'));
        $this->assertSame(['B', 'C'], array_column($grupos[0]['campos'], 'id'));
        $this->assertSame(['A'], array_column($grupos[1]['campos'], 'id'));

        $campos = F::camposPorId($grupos);
        $this->assertFalse($campos['B']['nao_se_aplica'], 'obrigatório nunca aceita "Não se aplica"');
        $this->assertTrue($campos['A']['nao_se_aplica']);
        $this->assertTrue($campos['C']['nao_se_aplica']);
    }

    public function test_o_grupo_novo_tambem_passa_pelo_filtro_de_sigilo(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'A', 'name' => 'Visível no Mercado Livre', 'value_type' => 'string', 'tags' => ['hidden' => true]],
            ['id' => 'B', 'name' => 'Acabamento', 'value_type' => 'string', 'tags' => ['hidden' => true],
                'values' => [['id' => '1', 'name' => 'Cromado'], ['id' => '2', 'name' => 'Igual ao anúncio']]],
        ]);

        $this->assertSame([F::GRUPO_MAIS_DETALHES], array_column($grupos, 'grupo'));
        $this->assertSame(['B'], array_column($grupos[0]['campos'], 'id'));
        $this->assertSame([['id' => '1', 'nome' => 'Cromado']], $grupos[0]['campos'][0]['valores']);
        $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publicar|mlb/iu', json_encode($grupos, JSON_UNESCAPED_UNICODE));
    }

    // ═══ Digitar fora das opções: a régua do editor interno (09/10/2026) ═══════

    /**
     * O modo de falha que isto impede: duas regras de "texto livre". A ficha deixava (antes do
     * dia 08/10) ou não deixava (depois) digitar sem olhar o que o editor aceita, e o Sincronizar
     * recusava o que o cliente tinha gravado ("Madeira maciça de eucalipto" em Materiais da estrutura).
     * Nas quatro respostas reais guardadas, com o `technical_specs` COMPLETO do lado do editor, todo
     * campo de lista ou de texto da ficha tem `texto_livre` igual ao `aceitaTextoLivre` do editor.
     */
    public function test_texto_livre_da_ficha_e_o_mesmo_do_editor_nas_respostas_reais(): void
    {
        $conferidos = ['lista_livre' => 0, 'lista_fechada' => 0];
        foreach ([self::CADEIRA, self::FURADEIRA, self::CAMISETA, self::PASTILHA] as $categoria) {
            $schema = self::schema($categoria);
            $editor = (new ClassificadorAtributos())->classificar($schema, new ContextoClassificacao('new', []))->atributos;

            foreach (F::camposPorId(F::daAtributos($schema->atributos)) as $id => $campo) {
                if (! in_array($campo['tipo'], [F::TIPO_LISTA, F::TIPO_TEXTO], true)) {
                    $this->assertFalse($campo['texto_livre'], "{$categoria} {$id}: número e Sim/Não não usam a chave");

                    continue;
                }
                $this->assertSame($editor[$id]->aceitaTextoLivre, $campo['texto_livre'], "{$categoria} {$id} ({$campo['tipo']})");
                if ($campo['tipo'] === F::TIPO_LISTA) {
                    $conferidos[$campo['texto_livre'] ? 'lista_livre' : 'lista_fechada']++;
                }
            }
        }

        // A prova só vale se cobriu os dois lados da régua.
        $this->assertGreaterThan(5, $conferidos['lista_livre']);
        $this->assertGreaterThan(5, $conferidos['lista_fechada']);
    }

    /** Os três campos do aviso de 09/10 (gabinete, espelho, lixeira): `string` com opções, multivalor, deixa digitar. */
    public function test_materiais_e_salas_deixam_digitar_e_a_lista_fechada_nao(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'STRUCTURE_MATERIALS', 'name' => 'Materiais da estrutura', 'value_type' => 'string', 'tags' => ['multivalued' => true],
                'values' => [['id' => '2431881', 'name' => 'Madeira'], ['id' => '2748302', 'name' => 'Plástico']]],
            ['id' => 'CABINET_MATERIALS', 'name' => 'Materiais do móvel', 'value_type' => 'string', 'tags' => ['multivalued' => true],
                'values' => [['id' => '1', 'name' => 'MDF']]],
            ['id' => 'RECOMMENDED_INSTALLATION_ROOMS', 'name' => 'Salas de instalação recomendadas', 'value_type' => 'string',
                'tags' => ['multivalued' => true], 'values' => [['id' => '9', 'name' => 'Banheiro']]],
            ['id' => 'STYLE', 'name' => 'Estilo', 'value_type' => 'list', 'tags' => [], 'values' => [['id' => '7', 'name' => 'Moderno']]],
            ['id' => 'MODEL', 'name' => 'Modelo', 'value_type' => 'string', 'tags' => []],
        ]);
        $campos = F::camposPorId($grupos);

        foreach (['STRUCTURE_MATERIALS', 'CABINET_MATERIALS', 'RECOMMENDED_INSTALLATION_ROOMS'] as $id) {
            $this->assertSame(F::TIPO_LISTA, $campos[$id]['tipo'], $id);
            $this->assertTrue($campos[$id]['multivalor'], $id);
            $this->assertTrue($campos[$id]['texto_livre'], "{$id}: as opções aparecem E dá para digitar");
        }
        $this->assertFalse($campos['STYLE']['texto_livre'], 'lista fechada: só as opções');
        $this->assertTrue($campos['MODEL']['texto_livre']);
    }

    /** Lista FECHADA cujas opções o filtro de sigilo derruba inteiras não degrada para texto: sai da ficha. */
    public function test_lista_fechada_sem_nenhuma_opcao_segura_sai_da_ficha_em_vez_de_virar_texto(): void
    {
        $grupos = F::daAtributos([
            ['id' => 'VOLUME_FECHADO', 'name' => 'Volume', 'value_type' => 'list', 'tags' => [],
                'values' => [['id' => '1', 'name' => '500 ML'], ['id' => '2', 'name' => '1 ML']]],
            ['id' => 'VOLUME_LIVRE', 'name' => 'Volume livre', 'value_type' => 'string', 'tags' => [],
                'values' => [['id' => '1', 'name' => '500 ML']]],
        ]);
        $campos = F::camposPorId($grupos);

        $this->assertArrayNotHasKey('VOLUME_FECHADO', $campos, 'texto livre ali gravaria valor que não publica');
        $this->assertSame(F::TIPO_TEXTO, $campos['VOLUME_LIVRE']['tipo'], 'onde o editor aceita texto, sobra o texto');
        $this->assertTrue($campos['VOLUME_LIVRE']['texto_livre']);
    }
}
