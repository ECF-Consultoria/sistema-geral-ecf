<?php

namespace Tests\Unit\Publicador\Schema;

use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Schema\MigradorDeCategoria;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\ValorEixo;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/** RN-21 / TC-71 — trocar de categoria aproveita o que vale e diz o que se perdeu. */
class MigradorDeCategoriaTest extends TestCase
{
    use CarregaSchemas;

    private const RASCUNHO_CADEIRA = [
        'BRAND'           => ['value_id' => null, 'value_name' => 'ECF'],
        'MODEL'           => ['value_id' => null, 'value_name' => 'Executiva'],
        'BACKREST_HEIGHT' => ['value_id' => null, 'value_name' => '23 cm'],
        'IS_SWIVEL'       => ['value_id' => '242085', 'value_name' => 'Sim'],
    ];

    private static function eixosDaCadeira(): array
    {
        return [
            new Eixo('COLOR', 'Cor', 0, true, [new ValorEixo('52028', 'Azul')]),
            new Eixo('UPHOLSTERY_MATERIAL', 'Material do estofamento', 1, true, [new ValorEixo(null, 'Couro')]),
        ];
    }

    private static function classificado(string $categoria)
    {
        return (new ClassificadorAtributos())->classificar(self::schema($categoria), new ContextoClassificacao());
    }

    public function test_tc71_da_cadeira_para_a_furadeira(): void
    {
        $r = MigradorDeCategoria::migrar(self::classificado(self::CADEIRA), self::classificado(self::FURADEIRA), self::RASCUNHO_CADEIRA, self::eixosDaCadeira());

        $this->assertSame(['BRAND', 'MODEL'], array_keys($r->atributos));
        foreach ($r->atributos as $valor) {
            $this->assertSame('migrated', $valor['origem']);
            $this->assertTrue($valor['revisar']);
        }

        $descartados = array_column($r->descartados, 'motivo', 'id');
        $this->assertSame(['BACKREST_HEIGHT' => 'nao_existe', 'IS_SWIVEL' => 'nao_existe'], $descartados);
        $this->assertSame('Altura do encosto', array_column($r->descartados, 'nome', 'id')['BACKREST_HEIGHT'], 'a lista mostra o nome que a pessoa conhece');

        // COLOR também é eixo na furadeira; o material do estofamento não existe lá.
        $this->assertSame(['COLOR'], array_map(fn ($e) => $e->chave, $r->eixos));
        $this->assertSame(0, $r->eixos[0]->posicao);
        $this->assertSame([['chave' => 'UPHOLSTERY_MATERIAL', 'nome' => 'Material do estofamento', 'motivo' => 'nao_e_eixo']], $r->eixosRemovidos);
    }

    public function test_valor_que_nao_vale_na_categoria_nova_e_descartado_com_o_motivo(): void
    {
        $rascunho = [...self::RASCUNHO_CADEIRA, 'IS_SWIVEL' => ['value_id' => '999', 'value_name' => 'Talvez']];

        $r = MigradorDeCategoria::migrar(self::classificado(self::CADEIRA), self::classificado(self::CADEIRA), $rascunho, []);

        $this->assertArrayNotHasKey('IS_SWIVEL', $r->atributos);
        $this->assertSame('valor_invalido', array_column($r->descartados, 'motivo', 'id')['IS_SWIVEL']);
        $this->assertSame('V-ATT-03', array_column($r->descartados, 'regra', 'id')['IS_SWIVEL']);
    }

    public function test_atributo_que_vira_sistema_na_categoria_nova_sai(): void
    {
        // Na pastilha, VEHICLE_TYPE é fixed: o ML preenche, o Publicador não manda.
        $r = MigradorDeCategoria::migrar(self::classificado(self::CADEIRA), self::classificado(self::PASTILHA), ['VEHICLE_TYPE' => ['value_id' => null, 'value_name' => 'Carro']], []);

        $this->assertSame([], $r->atributos);
        $this->assertSame('sistema', $r->descartados[0]['motivo']);
    }

    public function test_eixo_customizado_sai_se_o_nome_bater_com_atributo_da_categoria_nova(): void
    {
        $estampa = Eixo::customizado('Voltagem', 0, [new ValorEixo(null, 'Lisa')]);

        $r = MigradorDeCategoria::migrar(self::classificado(self::CADEIRA), self::classificado(self::FURADEIRA), [], [$estampa]);

        $this->assertSame([], $r->eixos);
        $this->assertSame('conflito_com_atributo', $r->eixosRemovidos[0]['motivo']);
        $this->assertSame([], MigradorDeCategoria::migrar(self::classificado(self::FURADEIRA), self::classificado(self::CADEIRA), [], [Eixo::customizado('Estampa', 0)])->eixosRemovidos);
    }
}
