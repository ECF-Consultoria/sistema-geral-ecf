<?php

namespace Tests\Unit\Publicador\Schema;

use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\ValorAtributo;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * `03` §5 e `08` (V-ATT-02 a 06) — o valor de UM atributo: se é válido (L1) e
 * como vai no payload. Os erros do ML que estas regras evitam foram medidos
 * na sondagem: 3510, 3708/344, 154/394 e 100.
 */
class ValorAtributoTest extends TestCase
{
    use CarregaSchemas;

    private static function atributo(string $id, string $categoria = self::CADEIRA, array $eixos = [])
    {
        return (new ClassificadorAtributos())->classificar(self::schema($categoria), new ContextoClassificacao(eixos: $eixos))->atributo($id);
    }

    private static function regra(?array $problema): ?string
    {
        return $problema['regra'] ?? null;
    }

    public function test_tc35_valor_fora_da_lista(): void
    {
        $giratoria = self::atributo('IS_SWIVEL');

        $this->assertNull(ValorAtributo::problema($giratoria, ['value_id' => '242085']));
        $this->assertSame('V-ATT-03', self::regra(ValorAtributo::problema($giratoria, ['value_id' => '999'])));
        // RN-16: booleano não aceita texto livre (o ML responde 3510).
        $this->assertSame('V-ATT-03', self::regra(ValorAtributo::problema($giratoria, ['value_name' => 'Talvez'])));
        // Texto igual a um valor da lista vale — o formatador manda o id.
        $this->assertNull(ValorAtributo::problema($giratoria, ['value_name' => ' sim ']));
    }

    public function test_texto_livre_onde_o_ml_aceita(): void
    {
        $this->assertNull(ValorAtributo::problema(self::atributo('BRAND', self::PASTILHA), ['value_name' => 'Marca Própria']));
        $this->assertNull(ValorAtributo::problema(self::atributo('MODEL'), ['value_name' => 'Executiva']));
    }

    public function test_tc36_unidade_fora_das_permitidas_e_numero_invalido(): void
    {
        $encosto = self::atributo('BACKREST_HEIGHT');

        $this->assertNull(ValorAtributo::problema($encosto, ['value_name' => '23 cm']));
        $this->assertNull(ValorAtributo::problema($encosto, ['value_number' => 23.5, 'value_unit' => 'mm']));
        $this->assertNull(ValorAtributo::problema($encosto, ['value_name' => '23,5']), 'sem unidade usa a padrão');
        $this->assertSame('V-ATT-04', self::regra(ValorAtributo::problema($encosto, ['value_name' => '60 pol'])));
        $this->assertSame('V-ATT-02', self::regra(ValorAtributo::problema($encosto, ['value_name' => 'alto'])));
    }

    public function test_tc37_texto_acima_do_limite(): void
    {
        $this->assertSame('V-ATT-05', self::regra(ValorAtributo::problema(self::atributo('MODEL'), ['value_name' => str_repeat('a', 300)])));
        $this->assertNull(ValorAtributo::problema(self::atributo('MODEL'), ['value_name' => str_repeat('a', 255)]));
    }

    public function test_nao_se_aplica_so_onde_e_permitido(): void
    {
        $na = ['value_id' => '-1', 'value_name' => null];

        $this->assertNull(ValorAtributo::problema(self::atributo('INMETRO_CERTIFICATION_REGISTRATION_NUMBER'), $na));
        $this->assertSame('V-ATT-06', self::regra(ValorAtributo::problema(self::atributo('BACKREST_HEIGHT'), $na)), 'H-06');
        $this->assertSame('V-ATT-06', self::regra(ValorAtributo::problema(self::atributo('COLOR', eixos: ['COLOR']), $na)), 'TC-40');
    }

    public function test_vazio_nao_e_problema_de_campo(): void
    {
        // Obrigatório vazio é regra do rascunho (L2, V-ATT-01), não do campo.
        $this->assertNull(ValorAtributo::problema(self::atributo('BACKREST_HEIGHT'), []));
        $this->assertNull(ValorAtributo::problema(self::atributo('BACKREST_HEIGHT'), ['value_name' => '  ']));
    }

    public function test_tc31_numero_sem_unidade_sai_com_a_unidade_padrao(): void
    {
        $encosto = self::atributo('BACKREST_HEIGHT');

        $this->assertSame(['id' => 'BACKREST_HEIGHT', 'value_name' => '23 cm'], ValorAtributo::paraPayload($encosto, ['value_name' => '23']));
        $this->assertSame(['id' => 'BACKREST_HEIGHT', 'value_name' => '23.5 mm'], ValorAtributo::paraPayload($encosto, ['value_number' => 23.5, 'value_unit' => 'mm']));
        $this->assertSame(['id' => 'BACKREST_HEIGHT', 'value_name' => '23.5 cm'], ValorAtributo::paraPayload($encosto, ['value_name' => '23,5 cm']));
    }

    public function test_tc39_nao_se_aplica_no_formato_oficial(): void
    {
        $this->assertSame(
            ['id' => 'INMETRO_CERTIFICATION_REGISTRATION_NUMBER', 'value_id' => '-1', 'value_name' => null],
            ValorAtributo::paraPayload(self::atributo('INMETRO_CERTIFICATION_REGISTRATION_NUMBER'), ['value_id' => '-1']),
        );
    }

    public function test_lista_sai_por_id_e_texto_sai_por_nome(): void
    {
        $this->assertSame(['id' => 'IS_SWIVEL', 'value_id' => '242085'], ValorAtributo::paraPayload(self::atributo('IS_SWIVEL'), ['value_name' => 'Sim']));
        $this->assertSame(['id' => 'IS_SWIVEL', 'value_id' => '242084'], ValorAtributo::paraPayload(self::atributo('IS_SWIVEL'), ['value_id' => '242084', 'value_name' => 'Não']));
        $this->assertSame(['id' => 'MODEL', 'value_name' => 'Executiva'], ValorAtributo::paraPayload(self::atributo('MODEL'), ['value_name' => ' Executiva ']));
        $this->assertNull(ValorAtributo::paraPayload(self::atributo('MODEL'), ['value_name' => '']));
    }
}
