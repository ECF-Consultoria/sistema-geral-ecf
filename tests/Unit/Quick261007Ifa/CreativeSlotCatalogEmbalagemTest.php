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

    private function truth(array $atributosIds = []): ProductTruth
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

    public function test_dimensions_nao_elegivel_sem_atributo_nenhum(): void
    {
        // Regressão do piso: zero atributo de qualquer tipo também não torna
        // dimensions elegível (quick 261007-rmv removeu o segundo caminho de
        // medida confirmada pelo operador que existia na Fase 169 — o único
        // caminho agora é o cadastro do Mercado Livre).
        $truth = $this->truth();

        $this->assertFalse(in_array('dimensions', $this->catalogo()->elegiveis($truth), true));
    }
}
