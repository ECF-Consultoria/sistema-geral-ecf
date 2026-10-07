<?php

namespace Tests\Unit\Quick261007Amb;

use App\Services\Creative\CreativeSlotCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quick 261007-amb, Tarefa 2 — layout fixo (`cena_padrao`) dos tipos que
 * aceitam texto, inspirado nos dois prints de anúncio real mandados pelo
 * usuário: `dimensions` usa o layout de MEDIDAS; os demais tipos COM_FATO
 * com texto (`package_content`, `specifications`, `benefits`,
 * `feature_highlight`, `how_to_use`) usam o layout de TÓPICOS.
 *
 * O layout é FORMA, nunca conteúdo (TRUTH-02/03) — por isso o teste mais
 * importante aqui é o último: nenhum dos dois textos de layout pode conter
 * um valor numérico/medida concreta, porque isso só pode vir do
 * `ProductTruth` via `CreativePromptBuilder::validarTexto()`.
 */
class CreativeSlotCatalogLayoutTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog;
    }

    public function test_cena_padrao_de_dimensions_e_o_layout_de_medidas(): void
    {
        $padrao = $this->catalogo()->padraoDe('dimensions');

        $this->assertStringContainsString('TAMANHO DO PRODUTO', $padrao['cena_padrao']);
        $this->assertStringContainsString('linhas de cota', $padrao['cena_padrao']);
    }

    #[DataProvider('tiposComLayoutDeTopicos')]
    public function test_cena_padrao_dos_tipos_de_topico_e_o_layout_de_circulos(string $tipo): void
    {
        $padrao = $this->catalogo()->padraoDe($tipo);

        $this->assertStringContainsString('recortes circulares', $padrao['cena_padrao']);
        $this->assertStringContainsString('barra vertical', mb_strtolower($padrao['cena_padrao']));
    }

    public static function tiposComLayoutDeTopicos(): array
    {
        return [
            ['package_content'],
            ['specifications'],
            ['benefits'],
            ['feature_highlight'],
            ['how_to_use'],
        ];
    }

    public function test_layout_de_medidas_e_de_topicos_nunca_citam_numero_ou_medida_especifica(): void
    {
        // TRUTH-02/03: o layout é FORMA — nenhum valor numérico/medida
        // concreta pode vir embutido no texto do layout (isso só pode vir
        // do ProductTruth via validarTexto()).
        foreach (['dimensions', 'package_content', 'specifications', 'benefits', 'feature_highlight', 'how_to_use'] as $tipo) {
            $cena = $this->catalogo()->padraoDe($tipo)['cena_padrao'];

            $this->assertDoesNotMatchRegularExpression('/\d+\s*(cm|kg|g|mm|m)\b/i', $cena);
        }
    }
}
