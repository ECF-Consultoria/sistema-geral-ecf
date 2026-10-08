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
            aceitaTextoLivre: $o['livre'] ?? false, aceitaNaoSeAplica: false, podeSerEixo: false, definePicture: false,
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

    public function test_numero_com_virgula_decimal_e_unidade_fora_cai_na_padrao(): void
    {
        $def = $this->def('number_unit', [], ['unidades' => ['cm', 'mm'], 'unidadePadrao' => 'cm']);
        $r = PortalValorDeAtributo::resolver($def, ['valor' => '4,5', 'unidade' => 'km']);
        $this->assertSame(4.5, $r['valor']['value_number']);
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
}
