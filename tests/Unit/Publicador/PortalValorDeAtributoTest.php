<?php

namespace Tests\Unit\Publicador;

use App\Support\Publicador\Portal\PortalValorDeAtributo;
use App\Support\Publicador\Schema\AtributoClassificado;
use PHPUnit\Framework\TestCase;

/** Tradução campo do portal -> valor do rascunho (172-04, D-08/D-13). */
class PortalValorDeAtributoTest extends TestCase
{
    private function def(string $tipo = 'list', array $valores = [], array $o = []): AtributoClassificado
    {
        return new AtributoClassificado(
            id: 'ATTR', nome: 'Campo', papel: AtributoClassificado::PRODUCT, obrigatoriedade: AtributoClassificado::OPTIONAL,
            secao: AtributoClassificado::SECAO_FICHA, grupo: null, valueType: $tipo, valores: $valores,
            unidades: $o['unidades'] ?? [], unidadePadrao: $o['unidadePadrao'] ?? null,
            aceitaTextoLivre: $o['livre'] ?? false, aceitaNaoSeAplica: $o['na'] ?? false, podeSerEixo: false, definePicture: false,
            multivalor: $o['multi'] ?? false, maxLength: $o['max'] ?? 255, dica: null, exemplo: null, tooltip: null,
            componente: null, tags: [],
        );
    }

    private const MATERIAIS = [['id' => '1', 'name' => 'Algodão'], ['id' => '2', 'name' => 'Couro'], ['id' => '3', 'name' => 'Linho']];

    public function test_lista_pelo_valor_id(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS), ['valor' => 'qualquer', 'valor_id' => '2']);
        $this->assertSame('2', $r['valor']['value_id']);
        $this->assertSame('Couro', $r['valor']['value_name']);
        $this->assertSame('portal', $r['valor']['origem']);
        $this->assertNull($r['aviso']);
    }

    public function test_lista_com_id_que_sumiu_casa_pelo_nome_sem_acento_e_caixa(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS), ['valor' => 'ALGODAO', 'valor_id' => '99']);
        $this->assertSame('1', $r['valor']['value_id']);
        $this->assertSame('Algodão', $r['valor']['value_name']);
    }

    public function test_lista_fechada_sem_casar_vira_aviso_e_nao_grava(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS), ['valor' => 'Seda']);
        $this->assertNull($r['valor']);
        $this->assertStringContainsString('Seda', $r['aviso']);
    }

    public function test_lista_com_texto_livre_aceita_o_nome(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS, ['livre' => true]), ['valor' => 'Seda']);
        $this->assertNull($r['valor']['value_id']);
        $this->assertSame('Seda', $r['valor']['value_name']);
        $this->assertSame('portal', $r['valor']['origem']);
    }

    public function test_multivalor_vira_primeira_opcao_mais_todos_os_ids_e_revisar(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS, ['multi' => true]), ['valor' => 'Algodão | Couro']);
        $this->assertSame('1', $r['valor']['value_id']);
        $this->assertSame('Algodão', $r['valor']['value_name']);
        $this->assertSame(['1', '2'], $r['valor']['values_multi']);
        $this->assertTrue($r['valor']['revisar']);
        $this->assertNull($r['aviso']);
    }

    public function test_multivalor_com_nome_que_nao_casa_avisa_e_deixa_de_fora(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS, ['multi' => true]), ['valor' => 'Algodão | Seda | Linho']);
        $this->assertSame(['1', '3'], $r['valor']['values_multi']);
        $this->assertStringContainsString('Seda', $r['aviso']);
    }

    public function test_multivalor_sem_nenhuma_opcao_resolvida_nao_grava(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS, ['multi' => true]), ['valor' => 'Seda | Nylon']);
        $this->assertNull($r['valor']);
        $this->assertNotNull($r['aviso']);
    }

    // ─── Texto livre gravado antes no Portal (09/10/2026) ──────────────────

    public function test_multivalor_que_aceita_texto_livre_e_nada_casa_leva_o_texto_sem_value_id(): void
    {
        $def = $this->def('string', self::MATERIAIS, ['multi' => true, 'livre' => true]);

        $r = PortalValorDeAtributo::resolver($def, ['valor' => 'Madeira maciça de eucalipto']);
        $this->assertNull($r['valor']['value_id']);
        $this->assertSame('Madeira maciça de eucalipto', $r['valor']['value_name']);
        $this->assertArrayNotHasKey('values_multi', $r['valor']);
        $this->assertFalse($r['valor']['revisar']);
        $this->assertNull($r['aviso']);

        // Mais de um nome digitado: um texto só, e a equipe confere.
        $r = PortalValorDeAtributo::resolver($def, ['valor' => 'Seda | Nylon']);
        $this->assertSame('Seda, Nylon', $r['valor']['value_name']);
        $this->assertTrue($r['valor']['revisar']);
    }

    public function test_multivalor_que_aceita_texto_livre_leva_so_as_opcoes_que_casam_quando_alguma_casa(): void
    {
        // D-13: com opção resolvida, o valor é a opção; o nome que não casa fica de fora (vai para o log).
        $r = PortalValorDeAtributo::resolver($this->def('string', self::MATERIAIS, ['multi' => true, 'livre' => true]), ['valor' => 'Couro | Seda']);
        $this->assertSame('2', $r['valor']['value_id']);
        $this->assertSame(['2'], $r['valor']['values_multi']);
        $this->assertStringContainsString('Seda', (string) $r['aviso']);
    }

    public function test_multivalor_fechado_com_texto_livre_antigo_fica_vazio(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS, ['multi' => true]), ['valor' => 'Madeira maciça de eucalipto']);
        $this->assertNull($r['valor'], 'lista fechada: o campo fica pendente no editor');
    }

    public function test_separador_no_texto_basta_para_tratar_como_multivalor(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS), ['valor' => 'Couro | Linho']);
        $this->assertSame(['2', '3'], $r['valor']['values_multi']);
    }

    public function test_boolean_resolve_sim_e_nao_pela_lista(): void
    {
        $def = $this->def('boolean', [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]);
        $this->assertSame('242085', PortalValorDeAtributo::resolver($def, ['valor' => 'Sim'])['valor']['value_id']);
        $this->assertSame('242084', PortalValorDeAtributo::resolver($def, ['valor' => 'Não'])['valor']['value_id']);
        $this->assertSame('242084', PortalValorDeAtributo::resolver($def, ['valor' => 'nao'])['valor']['value_id']);
    }

    public function test_numero_com_unidade_aceita(): void
    {
        $def = $this->def('number_unit', [], ['unidades' => ['cm', 'mm'], 'unidadePadrao' => 'cm']);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '45', 'unidade' => 'CM']);
        $this->assertSame(45.0, $r['valor']['value_number']);
        $this->assertSame('cm', $r['valor']['value_unit']);
    }

    public function test_numero_com_virgula_decimal_e_unidade_nao_aceita_nao_vira_a_padrao_com_o_mesmo_numero(): void
    {
        // Review 172 WR-04: "4,5 km" não pode virar "4,5 cm" em silêncio.
        $def = $this->def('number_unit', [], ['unidades' => ['cm', 'mm'], 'unidadePadrao' => 'cm']);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '4,5', 'unidade' => 'km']);
        $this->assertNull($r['valor']);
        $this->assertStringContainsString('não é aceita', (string) $r['aviso']);
    }

    public function test_unidade_da_mesma_grandeza_e_convertida(): void
    {
        $mm = $this->def('number_unit', [], ['unidades' => ['mm'], 'unidadePadrao' => 'mm']);
        $r = PortalValorDeAtributo::resolver($mm, ['valor' => '50', 'unidade' => 'cm']);
        $this->assertSame(500.0, $r['valor']['value_number'], '50 cm = 500 mm, nunca 50 mm');
        $this->assertSame('mm', $r['valor']['value_unit']);

        $g = $this->def('number_unit', [], ['unidades' => ['g'], 'unidadePadrao' => 'g']);
        $r = PortalValorDeAtributo::resolver($g, ['valor' => '2,5', 'unidade' => 'kg']);
        $this->assertSame(2500.0, $r['valor']['value_number']);
        $this->assertSame('g', $r['valor']['value_unit']);

        $m = $this->def('number_unit', [], ['unidades' => ['m', 'cm'], 'unidadePadrao' => 'm']);
        $r = PortalValorDeAtributo::resolver($m, ['valor' => '15', 'unidade' => 'mm']);
        $this->assertSame(0.015, $r['valor']['value_number']);
        $this->assertSame('m', $r['valor']['value_unit']);
    }

    public function test_unidade_de_outra_grandeza_nao_e_convertida(): void
    {
        $def = $this->def('number_unit', [], ['unidades' => ['g', 'kg'], 'unidadePadrao' => 'g']);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '10', 'unidade' => 'cm']);
        $this->assertNull($r['valor']);
        $this->assertNotNull($r['aviso']);
    }

    public function test_numero_sem_unidade_cai_na_padrao(): void
    {
        $def = $this->def('number_unit', [], ['unidades' => ['cm', 'mm'], 'unidadePadrao' => 'cm']);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '12', 'unidade' => null]);
        $this->assertSame(12.0, $r['valor']['value_number']);
        $this->assertSame('cm', $r['valor']['value_unit']);
    }

    public function test_numero_com_unidade_fora_e_sem_padrao_avisa(): void
    {
        $def = $this->def('number_unit', [], ['unidades' => ['cm']]);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '45', 'unidade' => 'km']);
        $this->assertNull($r['valor']);
        $this->assertNotNull($r['aviso']);
    }

    public function test_numero_invalido_avisa(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('number'), ['valor' => 'abc']);
        $this->assertNull($r['valor']);
        $this->assertNotNull($r['aviso']);
    }

    public function test_texto_e_cortado_em_max_length(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('string', [], ['max' => 5]), ['valor' => 'abcdefghij']);
        $this->assertSame('abcde', $r['valor']['value_name']);
        $this->assertSame('portal', $r['valor']['origem']);
    }

    public function test_valor_vazio_nao_gera_valor_nem_aviso(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('string'), ['valor' => '  ']);
        $this->assertNull($r['valor']);
        $this->assertNull($r['aviso']);
    }

    public function test_pacote_vira_atributos_com_cm_e_gramas(): void
    {
        $r = PortalValorDeAtributo::pacoteParaAtributos(['c' => 50.0, 'l' => 40.0, 'a' => 30.5, 'peso_real' => 12.345]);
        $this->assertSame('50 cm', $r['SELLER_PACKAGE_LENGTH']);
        $this->assertSame('40 cm', $r['SELLER_PACKAGE_WIDTH']);
        $this->assertSame('30.5 cm', $r['SELLER_PACKAGE_HEIGHT']);
        $this->assertSame('12345 g', $r['SELLER_PACKAGE_WEIGHT']);
        $this->assertSame([], PortalValorDeAtributo::pacoteParaAtributos(null));
    }

    // ─── "Não se aplica" do Portal (08/10/2026) ─────────────────────────

    public function test_nao_se_aplica_do_portal_vira_o_na_do_rascunho_quando_o_atributo_aceita(): void
    {
        foreach (['list' => self::MATERIAIS, 'number_unit' => [], 'string' => [], 'boolean' => [['id' => '242085', 'name' => 'Sim']]] as $tipo => $valores) {
            $r = PortalValorDeAtributo::resolver($this->def($tipo, $valores, ['na' => true, 'unidades' => ['cm'], 'unidadePadrao' => 'cm']),
                ['valor' => null, 'valor_id' => '-1', 'unidade' => null]);

            $this->assertSame(['value_id' => '-1', 'value_name' => null, 'origem' => 'portal', 'revisar' => false], $r['valor'], $tipo);
            $this->assertNull($r['aviso']);
        }
    }

    public function test_nao_se_aplica_que_o_atributo_nao_aceita_vira_aviso_e_nao_grava(): void
    {
        $r = PortalValorDeAtributo::resolver($this->def('list', self::MATERIAIS), ['valor' => null, 'valor_id' => '-1']);

        $this->assertNull($r['valor']);
        $this->assertStringContainsString('Não se aplica', $r['aviso']);
    }
}
