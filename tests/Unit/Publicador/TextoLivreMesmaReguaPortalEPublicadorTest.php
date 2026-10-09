<?php

namespace Tests\Unit\Publicador;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria as F;
use App\Support\Publicador\Portal\PortalValorDeAtributo;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use PHPUnit\Framework\TestCase;

/**
 * 09/10/2026 — a MESMA regra de texto livre nas duas pontas, provada com o MESMO schema.
 *
 * O que aconteceu na #459: a ficha do Portal gravou "Madeira maciça de eucalipto" em Materiais da
 * estrutura (quando ainda era texto, antes de 08/10) e o Sincronizar recusou ("nenhuma das opções
 * existe na lista"), embora o editor do Publicador aceite texto nesse atributo (`string` com opções,
 * `allow_custom_value: true`). Agora: onde o editor aceita texto, a ficha deixa digitar e o
 * Sincronizar leva o texto; onde não aceita, a ficha só oferece as opções e o Sincronizar não preenche.
 *
 * Schema montado com os ids reais das categorias da #459 (gabinete MLB186151, espelho MLB186270,
 * lixeira MLB33375 e cadeira MLB32664, lidos de `/categories/{id}/attributes` em 09/10) mais uma
 * lista fechada de controle.
 */
class TextoLivreMesmaReguaPortalEPublicadorTest extends TestCase
{
    private static function atributos(): array
    {
        $multi = fn (string $id, string $nome, array $opcoes) => ['id' => $id, 'name' => $nome, 'value_type' => 'string',
            'tags' => ['multivalued' => true], 'values' => $opcoes];

        return [
            $multi('STRUCTURE_MATERIALS', 'Materiais da estrutura', [['id' => '2431881', 'name' => 'Madeira'], ['id' => '2748302', 'name' => 'Plástico']]),
            $multi('CABINET_MATERIALS', 'Materiais do móvel', [['id' => '2431881', 'name' => 'Madeira'], ['id' => '7', 'name' => 'MDF']]),
            $multi('RECOMMENDED_INSTALLATION_ROOMS', 'Salas de instalação recomendadas', [['id' => '9', 'name' => 'Banheiro']]),
            ['id' => 'STYLE', 'name' => 'Estilo', 'value_type' => 'list', 'tags' => [], 'values' => [['id' => '5', 'name' => 'Moderno']]],
            ['id' => 'FRAME_MATERIALS', 'name' => 'Materiais da armação', 'value_type' => 'list', 'tags' => ['multivalued' => true],
                'values' => [['id' => '41', 'name' => 'Aço']]],
        ];
    }

    private const DIGITADO = [
        'STRUCTURE_MATERIALS' => 'Madeira maciça de eucalipto',
        'CABINET_MATERIALS' => 'MDF 15 mm com acabamento ripado e pintura UV',
        'RECOMMENDED_INSTALLATION_ROOMS' => 'Banheiro, lavabo e hall de entrada',
        'STYLE' => 'Rústico chique',
        'FRAME_MATERIALS' => 'Aço escovado',
    ];

    public function test_onde_a_ficha_deixa_digitar_o_sincronizar_leva_o_texto_e_onde_nao_deixa_nao_preenche(): void
    {
        $campos = F::camposPorId(F::daAtributos(self::atributos()));
        $editor = (new ClassificadorAtributos())
            ->classificar(CategorySchema::dasFontes('MLB186151', [], self::atributos(), [], []), new ContextoClassificacao('new', []))
            ->atributos;

        foreach (self::DIGITADO as $id => $texto) {
            $aceita = $editor[$id]->aceitaTextoLivre;
            $this->assertSame($aceita, $campos[$id]['texto_livre'], "{$id}: a ficha e o editor discordam");

            $r = PortalValorDeAtributo::resolver($editor[$id], ['id' => $id, 'valor' => $texto, 'valor_id' => null]);
            if ($aceita) {
                $this->assertNotNull($r['valor'], "{$id}: aceita texto, o Sincronizar leva");
                $this->assertNull($r['valor']['value_id']);
                $this->assertSame($texto, $r['valor']['value_name']);
                $this->assertSame('portal', $r['valor']['origem']);
            } else {
                $this->assertNull($r['valor'], "{$id}: lista fechada, o campo fica pendente no editor");
            }
        }

        // Os três do aviso de 09/10 aceitam; as duas listas `list` não.
        $this->assertTrue($campos['STRUCTURE_MATERIALS']['texto_livre']);
        $this->assertTrue($campos['CABINET_MATERIALS']['texto_livre']);
        $this->assertTrue($campos['RECOMMENDED_INSTALLATION_ROOMS']['texto_livre']);
        $this->assertFalse($campos['STYLE']['texto_livre']);
        $this->assertFalse($campos['FRAME_MATERIALS']['texto_livre']);
    }
}
