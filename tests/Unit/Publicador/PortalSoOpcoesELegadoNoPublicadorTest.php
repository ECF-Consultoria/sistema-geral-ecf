<?php

namespace Tests\Unit\Publicador;

use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria as F;
use App\Support\Publicador\Portal\PortalValorDeAtributo;
use App\Support\Publicador\Schema\CategorySchema;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use PHPUnit\Framework\TestCase;

/**
 * 09/10/2026: a régua das duas pontas, provada com o MESMO schema.
 * - Portal: campo com opções é SÓ lista. O cliente escolhe e não digita, mesmo onde o editor
 *   aceitaria texto (decisão do usuário, que mantém o §35 de 08/10).
 * - Publicador: o texto ANTIGO, gravado no Portal antes de 08/10 quando `string` com opções ainda
 *   era texto, chega ao rascunho como `value_name` onde o editor aceita texto livre. Onde o editor
 *   não aceita (`list`), nada é preenchido e o campo fica pendente.
 *
 * O caso real da #459: "Madeira maciça de eucalipto" em Materiais da estrutura. O Sincronizar
 * recusava ("nenhuma das opções existe na lista"), embora o editor aceite texto nesse atributo
 * (`string` com opções e `allow_custom_value: true`).
 *
 * O schema foi montado com os ids reais das categorias da #459, lidos de `/categories/{id}/attributes`
 * em 09/10: gabinete MLB186151, espelho MLB186270, lixeira MLB33375 e cadeira MLB32664. Ele leva
 * ainda duas listas fechadas de controle.
 */
class PortalSoOpcoesELegadoNoPublicadorTest extends TestCase
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

    /** O que estava gravado no Portal antes de 08/10 (texto), id do atributo → valor. */
    private const LEGADO = [
        'STRUCTURE_MATERIALS' => 'Madeira maciça de eucalipto',
        'CABINET_MATERIALS' => 'MDF 15 mm com acabamento ripado e pintura UV',
        'RECOMMENDED_INSTALLATION_ROOMS' => 'Banheiro, lavabo e hall de entrada',
        'STYLE' => 'Rústico chique',
        'FRAME_MATERIALS' => 'Aço escovado',
    ];

    private const EDITOR_ACEITA_TEXTO = ['STRUCTURE_MATERIALS', 'CABINET_MATERIALS', 'RECOMMENDED_INSTALLATION_ROOMS'];

    public function test_o_portal_so_oferece_as_opcoes_e_o_sincronizar_leva_o_legado_onde_o_editor_aceita_texto(): void
    {
        $campos = F::camposPorId(F::daAtributos(self::atributos()));
        $editor = (new ClassificadorAtributos())
            ->classificar(CategorySchema::dasFontes('MLB186151', [], self::atributos(), [], []), new ContextoClassificacao('new', []))
            ->atributos;

        foreach (self::LEGADO as $id => $texto) {
            // Portal: lista, sem marca de digitar, qualquer que seja a regra do editor.
            $this->assertSame(F::TIPO_LISTA, $campos[$id]['tipo'], "{$id}: com opção, só lista no Portal");
            $this->assertArrayNotHasKey('texto_livre', $campos[$id], $id);

            $aceita = in_array($id, self::EDITOR_ACEITA_TEXTO, true);
            $this->assertSame($aceita, $editor[$id]->aceitaTextoLivre, "{$id}: régua do editor");

            $r = PortalValorDeAtributo::resolver($editor[$id], ['id' => $id, 'valor' => $texto, 'valor_id' => null]);
            if ($aceita) {
                $this->assertNotNull($r['valor'], "{$id}: o editor aceita texto, então o legado chega ao rascunho");
                $this->assertNull($r['valor']['value_id']);
                $this->assertSame($texto, $r['valor']['value_name']);
                $this->assertSame('portal', $r['valor']['origem']);
            } else {
                $this->assertNull($r['valor'], "{$id}: lista fechada, o campo fica pendente no editor");
            }
        }
    }
}
