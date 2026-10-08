<?php

namespace Tests\Unit\Quick261008Bdg;

use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * `CreativeSlotCatalog::fatosParaTexto()` — quick 261008-bdg ("o sistema
 * monta o texto do cadastro, sem depender do modelo propor"). Testes
 * diretos, sem passar pelo `CreativePlanner`/LLM: dado um `ProductTruth`,
 * qual fato (rotulado, valor exato) cada tipo de slot usaria como badge do
 * SISTEMA.
 */
class CreativeSlotCatalogFatosParaTextoTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog();
    }

    /** @param  array<string, string>  $atributosIds */
    private function truth(array $atributosIds, array $contagens = []): ProductTruth
    {
        return new ProductTruth(
            marca: $atributosIds['BRAND'] ?? null,
            modelo: $atributosIds['MODEL'] ?? null,
            fatosVerificados: [],
            contagens: $contagens,
            beneficiosVerificados: [],
            claimsProibidas: ['Não escreva texto na imagem.'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
        );
    }

    // ═══ dimensions — só medidas do PRODUTO, no máximo 3, rotuladas pt-BR ═══

    public function test_dimensions_usa_as_medidas_do_produto_rotuladas_em_pt_br(): void
    {
        $truth = $this->truth(['WIDTH' => '120 cm', 'HEIGHT' => '75 cm', 'DEPTH' => '50 cm']);

        $fatos = $this->catalogo()->fatosParaTexto('dimensions', $truth);

        $this->assertSame([
            'Largura'       => '120 cm',
            'Altura'        => '75 cm',
            'Profundidade'  => '50 cm',
        ], $fatos);
    }

    public function test_dimensions_nunca_usa_medida_de_embalagem_mesmo_presente_no_cadastro(): void
    {
        $truth = $this->truth([
            'WIDTH'                 => '120 cm',
            'SELLER_PACKAGE_WIDTH'  => '12 cm',
            'SELLER_PACKAGE_HEIGHT' => '12 cm',
            'PACKAGE_WEIGHT'        => '20 kg',
        ]);

        $fatos = $this->catalogo()->fatosParaTexto('dimensions', $truth);

        $this->assertSame(['Largura' => '120 cm'], $fatos);
        $this->assertNotContains('12 cm', $fatos);
    }

    public function test_dimensions_limita_a_tres_medidas_mesmo_com_quatro_no_cadastro(): void
    {
        $truth = $this->truth([
            'WIDTH'  => '120 cm',
            'HEIGHT' => '75 cm',
            'LENGTH' => '200 cm',
            'DEPTH'  => '50 cm',
        ]);

        $fatos = $this->catalogo()->fatosParaTexto('dimensions', $truth);

        $this->assertCount(3, $fatos);
        // Ordem de prioridade largura/altura/comprimento/profundidade — a
        // quarta (profundidade) fica de fora quando as quatro existem.
        $this->assertSame(['Largura' => '120 cm', 'Altura' => '75 cm', 'Comprimento' => '200 cm'], $fatos);
    }

    public function test_dimensions_sem_nenhuma_medida_do_produto_devolve_vazio(): void
    {
        $this->assertSame([], $this->catalogo()->fatosParaTexto('dimensions', $this->truth([])));
    }

    // ═══ package_content — o MESMO fato que tornou o tipo elegível ═══════════

    public function test_package_content_usa_a_contagem_de_kit_quando_existe(): void
    {
        $truth = $this->truth([], [
            ['peca' => 'peças do kit', 'quantidade' => '5', 'origem' => 'cadastro'],
        ]);

        $fatos = $this->catalogo()->fatosParaTexto('package_content', $truth);

        $this->assertSame(['peças do kit' => '5'], $fatos);
    }

    public function test_package_content_usa_atributo_de_conteudo_quando_nao_ha_contagem_de_kit(): void
    {
        $truth = $this->truth(['INCLUDED_ACCESSORIES' => '1 cabo e 1 manual']);

        $fatos = $this->catalogo()->fatosParaTexto('package_content', $truth);

        $this->assertSame(['Included accessories' => '1 cabo e 1 manual'], $fatos);
    }

    // ═══ how_to_use — o MESMO atributo de instalação/montagem ════════════════

    public function test_how_to_use_usa_o_atributo_de_instalacao(): void
    {
        $truth = $this->truth(['ASSEMBLY_REQUIRED' => 'Sim, com manual incluso']);

        $fatos = $this->catalogo()->fatosParaTexto('how_to_use', $truth);

        $this->assertSame(['Assembly required' => 'Sim, com manual incluso'], $fatos);
    }

    // ═══ specifications/benefits/feature_highlight — genéricos, no máximo 2 ═

    public function test_generico_prioriza_material_e_cor_sobre_outros_atributos(): void
    {
        $truth = $this->truth([
            'MATERIAL'        => 'Madeira',
            'COLOR'           => 'Marrom',
            'FINISH'          => 'Laqueado',
            'COUNTRY_OF_ORIGIN' => 'Brasil',
        ]);

        foreach (['specifications', 'benefits', 'feature_highlight'] as $tipo) {
            $fatos = $this->catalogo()->fatosParaTexto($tipo, $truth);
            $this->assertSame(['Material' => 'Madeira', 'Cor' => 'Marrom'], $fatos, "tipo={$tipo}");
        }
    }

    public function test_generico_nunca_usa_model_mesmo_sendo_o_unico_fato_disponivel(): void
    {
        // Achado em produção: MODEL carrega lista de palavras-chave de SEO
        // ("mesa escritorio gaveta, escrivaninha com gavetas, ..."), não um
        // fato único — "parece fato e não é".
        $truth = $this->truth(['MODEL' => 'mesa escritorio gaveta, escrivaninha com gavetas, mesa de estudos']);

        $fatos = $this->catalogo()->fatosParaTexto('specifications', $truth);

        $this->assertSame([], $fatos);
    }

    public function test_generico_nunca_usa_brand_mesmo_sendo_o_unico_fato_disponivel(): void
    {
        // BRAND já aparece em FATOS PERMITIDOS como "Marca" — não duplica como badge.
        $truth = $this->truth(['BRAND' => 'MarcaTeste']);

        $fatos = $this->catalogo()->fatosParaTexto('specifications', $truth);

        $this->assertSame([], $fatos);
    }

    public function test_generico_nao_repete_fato_ja_coberto_por_slot_dedicado(): void
    {
        // WIDTH (dimensions), KIT_CONTENT (package_content) e ASSEMBLY_TYPE
        // (how_to_use) já têm slot dedicado — não viram badge genérica.
        $truth = $this->truth([
            'WIDTH'         => '120 cm',
            'KIT_CONTENT'   => '2 peças',
            'ASSEMBLY_TYPE' => 'Parafusada',
            'MATERIAL'      => 'Madeira',
        ]);

        $fatos = $this->catalogo()->fatosParaTexto('specifications', $truth);

        $this->assertSame(['Material' => 'Madeira'], $fatos);
    }

    public function test_generico_limita_a_duas_badges_mesmo_com_muitos_atributos_elegiveis(): void
    {
        $truth = $this->truth([
            'MATERIAL'  => 'Madeira',
            'MAIN_MATERIAL' => 'MDF',
            'COLOR'     => 'Marrom',
            'FINISH'    => 'Laqueado',
            'WEIGHT_CAPACITY' => '50 kg',
        ]);

        $fatos = $this->catalogo()->fatosParaTexto('benefits', $truth);

        $this->assertCount(2, $fatos);
    }

    public function test_generico_sem_fato_nenhum_devolve_vazio(): void
    {
        $this->assertSame([], $this->catalogo()->fatosParaTexto('specifications', $this->truth([])));
    }

    // ═══ tipos sem texto nunca têm fatosParaTexto (não é chamado pelo Planner, mas a API é defensiva) ═══

    public function test_tipo_que_nao_aceita_texto_devolve_vazio(): void
    {
        $truth = $this->truth(['MATERIAL' => 'Madeira']);

        $this->assertSame([], $this->catalogo()->fatosParaTexto('hero', $truth));
        $this->assertSame([], $this->catalogo()->fatosParaTexto('lifestyle', $truth));
    }
}
