<?php

namespace Tests\Unit\Quick261007Ifa;

use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * Quick 261007-ifa — medida de EMBALAGEM não pode satisfazer o slot
 * `dimensions` (que anuncia a medida do PRODUTO). Caso literal medido em
 * produção (rascunho 8, categoria MLB31578, "Mesa Centro Sala Mesinha Base
 * Piramide"): produto só com `SELLER_PACKAGE_*` (todos 12 cm) tornava
 * `dimensions` elegível com a medida da caixa — o teste abaixo falharia com
 * o código de antes.
 */
class CreativeSlotCatalogEmbalagemTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog;
    }

    private function truth(array $atributosIds = [], array $medidasConfirmadas = []): ProductTruth
    {
        return new ProductTruth(
            marca: null,
            modelo: null,
            fatosVerificados: [],
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: ['claim fixa'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
            medidasConfirmadas: $medidasConfirmadas,
        );
    }

    // ═══ Caso literal de produção — só SELLER_PACKAGE_* ════════════════════

    public function test_dimensions_nao_elegivel_quando_so_ha_medida_de_embalagem_seller_package(): void
    {
        // Atributos reais do rascunho 8 (MLB31578), menos os que não são de
        // medida (BRAND, INCLUDES_ASSEMBLY_MANUAL, REQUIRES_ASSEMBLY).
        $truth = $this->truth(atributosIds: [
            'SELLER_PACKAGE_HEIGHT' => '12 cm',
            'SELLER_PACKAGE_LENGTH' => '12 cm',
            'SELLER_PACKAGE_WEIGHT' => '4000 g',
            'SELLER_PACKAGE_WIDTH'  => '12 cm',
        ]);

        $this->assertFalse(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_dimensions_nao_elegivel_quando_so_ha_medida_de_embalagem_package_sistema(): void
    {
        // O atributo de SISTEMA do Mercado Livre para a mesma medida de
        // pacote (sem o prefixo SELLER_) — AnuncioSaudeService::ATRIBUTOS_DIMENSAO.
        $truth = $this->truth(atributosIds: [
            'PACKAGE_HEIGHT' => '12 cm',
            'PACKAGE_WIDTH'  => '12 cm',
            'PACKAGE_LENGTH' => '12 cm',
            'PACKAGE_WEIGHT' => '4000 g',
        ]);

        $this->assertFalse(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    // ═══ Complemento — medida de PRODUTO continua elegível ═════════════════

    public function test_dimensions_elegivel_quando_ha_medida_de_produto_de_verdade(): void
    {
        $truth = $this->truth(atributosIds: ['WIDTH' => '45 cm']);

        $this->assertTrue(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_dimensions_elegivel_quando_ha_medida_de_produto_com_sufixo_largura(): void
    {
        // `*_WIDTH` que NÃO é de embalagem (ex.: medida de uma peça específica
        // do produto) continua elegível — só o prefixo de embalagem exclui.
        $truth = $this->truth(atributosIds: ['SCREEN_WIDTH' => '32 cm']);

        $this->assertTrue(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_dimensions_elegivel_quando_ha_medida_confirmada_pelo_operador_mesmo_com_so_embalagem_no_cadastro(): void
    {
        // medidasConfirmadas (fato humano, Fase 169) continua valendo
        // exatamente como antes, independente do que exista em atributosIds.
        $truth = $this->truth(
            atributosIds: ['SELLER_PACKAGE_WIDTH' => '12 cm'],
            medidasConfirmadas: ['Largura: 45 cm'],
        );

        $this->assertTrue(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }

    public function test_dimensions_nao_elegivel_sem_atributo_nenhum_e_sem_medida_humana(): void
    {
        // Regressão do piso já coberto pela Fase 169 — continua igual.
        $truth = $this->truth();

        $this->assertFalse(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }
}
