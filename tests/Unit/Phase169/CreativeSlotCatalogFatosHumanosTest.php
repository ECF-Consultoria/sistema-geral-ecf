<?php

namespace Tests\Unit\Phase169;

use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * `CreativeSlotCatalog` reconhece o fato confirmado pelo OPERADOR (Fase 169,
 * TXT-03) em `benefits`/`dimensions` — e expõe o que falta quando nenhum
 * slot de texto é elegível (TXT-04). `specifications`/`feature_highlight`/
 * `how_to_use`/`package_content` ficam byte a byte como antes — fora do
 * escopo literal deste plano ("pontos fortes e medidas").
 */
class CreativeSlotCatalogFatosHumanosTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog;
    }

    private function truth(
        array $fatosVerificados = [],
        array $beneficiosVerificados = [],
        array $medidasConfirmadas = [],
        array $atributosIds = [],
    ): ProductTruth {
        return new ProductTruth(
            marca: null,
            modelo: null,
            fatosVerificados: $fatosVerificados,
            contagens: [],
            beneficiosVerificados: $beneficiosVerificados,
            claimsProibidas: ['claim fixa'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
            medidasConfirmadas: $medidasConfirmadas,
        );
    }

    // ═══ dimensions elegível só com fato humano (zero cadastro) ═══════════

    public function test_dimensions_elegivel_so_com_medida_confirmada_pelo_operador(): void
    {
        $truth = $this->truth(medidasConfirmadas: ['Largura: 45 cm']);

        $this->assertTrue(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_dimensions_nao_elegivel_sem_atributo_e_sem_medida_humana(): void
    {
        $truth = $this->truth();

        $this->assertFalse(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    // ═══ benefits elegível só com fato humano (zero cadastro) ═════════════

    public function test_benefits_elegivel_com_3_beneficios_confirmados_pelo_operador_zero_cadastro(): void
    {
        $truth = $this->truth(beneficiosVerificados: ['Resistente à água', 'Fácil de montar', 'Garantia de 1 ano']);

        $this->assertTrue(in_array('benefits', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_benefits_soma_fatosVerificados_com_beneficiosVerificados(): void
    {
        $truth = $this->truth(
            fatosVerificados: ['Material' => 'MDF'],
            beneficiosVerificados: ['Resistente à água', 'Fácil de montar'],
        );

        $this->assertTrue(in_array('benefits', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_benefits_nao_elegivel_com_so_2_fatos_combinados(): void
    {
        $truth = $this->truth(
            fatosVerificados: ['Material' => 'MDF'],
            beneficiosVerificados: ['Resistente à água'],
        );

        $this->assertFalse(in_array('benefits', $this->catalogo()->elegiveis($truth), true));
    }

    // ═══ algumAceitaTexto() / faltamParaTexto() ════════════════════════════

    public function test_produto_sem_fato_nenhum_nem_cadastro_nem_humano_continua_sem_slot_de_texto(): void
    {
        // Zero sinal de qualquer caminho (cadastro OU humano) — o caso
        // "falta tudo" que TXT-04 pede para a tela explicar.
        //
        // ACHADO (fora do escopo deste plano, documentado no SUMMARY): o
        // dump real da auditoria (REQUIREMENTS-v25.md, categoria MLB31578,
        // "UM único atributo — MODEL") teria `fatosVerificados = ['Modelo'
        // => '...']` (count 1) se reconstruídoà mão como o plano descreve —
        // e `feature_highlight` já exige só `count($truth->fatosVerificados)
        // >= 1` desde a Fase 161, SEM MUDANÇA NESTE PLANO. Com count=1,
        // `feature_highlight` já seria elegível por aquele caminho de
        // CADASTRO antigo, intocado aqui — não é regressão introduzida por
        // TXT-03. Por isso este teste usa fatosVerificados VAZIO (o caso
        // "nenhum fato", que é o que TXT-04 precisa cobrir), em vez de
        // reproduzir literalmente o dump com 1 fato.
        $truth = $this->truth();

        $catalogo = $this->catalogo();

        $this->assertFalse($catalogo->algumAceitaTexto($truth));
        $this->assertNotEmpty($catalogo->faltamParaTexto($truth));
    }

    public function test_faltam_para_texto_vazio_quando_ha_3_beneficios_confirmados(): void
    {
        $truth = $this->truth(beneficiosVerificados: ['A', 'B', 'C']);

        $catalogo = $this->catalogo();

        $this->assertTrue($catalogo->algumAceitaTexto($truth));
        $this->assertSame([], $catalogo->faltamParaTexto($truth));
    }

    public function test_faltam_para_texto_vazio_quando_ha_medida_confirmada(): void
    {
        $truth = $this->truth(medidasConfirmadas: ['45 cm']);

        $catalogo = $this->catalogo();

        $this->assertTrue($catalogo->algumAceitaTexto($truth));
        $this->assertSame([], $catalogo->faltamParaTexto($truth));
    }

    public function test_faltam_para_texto_nunca_sugere_afrouxar_truth_02_03(): void
    {
        $mensagens = $this->catalogo()->faltamParaTexto($this->truth());

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsStringIgnoringCase('afrouxar', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('inventar', $mensagem);
        }
    }

    // ═══ Regressão — specifications/feature_highlight/how_to_use/package_content intactos ═══

    public function test_especificacoes_feature_highlight_how_to_use_package_content_inalterados_por_fato_humano(): void
    {
        // beneficiosVerificados/medidasConfirmadas não afetam os 4 tipos fora do escopo TXT-01.
        $truth = $this->truth(beneficiosVerificados: ['A', 'B', 'C'], medidasConfirmadas: ['45 cm']);

        $elegiveis = $this->catalogo()->elegiveis($truth);

        $this->assertFalse(in_array('specifications', $elegiveis, true));
        $this->assertFalse(in_array('feature_highlight', $elegiveis, true));
        $this->assertFalse(in_array('how_to_use', $elegiveis, true));
        $this->assertFalse(in_array('package_content', $elegiveis, true));
    }
}
