<?php

namespace Tests\Unit\Quick261007Rmv;

use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * Quick 261007-rmv — reversão do bloco "pontos fortes e medidas digitados à
 * mão" (Fase 169). `CreativeSlotCatalog::algumAceitaTexto()`/
 * `faltamParaTexto()` foram PRESERVADOS (alimentam o aviso na tela, agora a
 * partir de `kit.pode_ter_texto`/`kit.faltam`) — só o caminho de fato
 * confirmado pelo OPERADOR saiu; `dimensions`/`benefits` voltam a depender
 * só do cadastro do Mercado Livre (como nas Fases 160/161).
 *
 * Substitui `tests/Unit/Phase169/CreativeSlotCatalogFatosHumanosTest.php`
 * (removido — testava exclusivamente o caminho humano, agora inexistente).
 */
class CreativeSlotCatalogAvisoTextoTest extends TestCase
{
    private function catalogo(): CreativeSlotCatalog
    {
        return new CreativeSlotCatalog;
    }

    private function truth(array $fatosVerificados = [], array $atributosIds = []): ProductTruth
    {
        return new ProductTruth(
            marca: null,
            modelo: null,
            fatosVerificados: $fatosVerificados,
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: ['claim fixa'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
        );
    }

    public function test_sem_fato_nenhum_do_cadastro_nenhum_slot_de_texto_e_elegivel(): void
    {
        $truth = $this->truth();

        $catalogo = $this->catalogo();

        $this->assertFalse($catalogo->algumAceitaTexto($truth));
        $this->assertNotEmpty($catalogo->faltamParaTexto($truth));
    }

    public function test_com_3_fatos_verificados_do_cadastro_benefits_fica_elegivel_e_faltam_fica_vazio(): void
    {
        $truth = $this->truth(['Material' => 'MDF', 'Cor' => 'Branco', 'Marca' => 'ECF']);

        $catalogo = $this->catalogo();

        $this->assertTrue($catalogo->algumAceitaTexto($truth));
        $this->assertSame([], $catalogo->faltamParaTexto($truth));
    }

    public function test_com_atributo_de_dimensao_do_produto_dimensions_fica_elegivel(): void
    {
        $truth = $this->truth(atributosIds: ['WIDTH' => '45 cm']);

        $catalogo = $this->catalogo();

        $this->assertTrue($catalogo->algumAceitaTexto($truth));
        $this->assertSame([], $catalogo->faltamParaTexto($truth));
    }

    /** Achado 261007-ifa, preservado intacto: medida de EMBALAGEM não basta. */
    public function test_so_com_medida_de_embalagem_dimensions_continua_nao_elegivel(): void
    {
        $truth = $this->truth(atributosIds: ['SELLER_PACKAGE_WIDTH' => '12 cm']);

        $this->assertFalse($this->catalogo()->algumAceitaTexto($truth));
    }

    /**
     * Quick 261007-rmv: as mensagens não citam mais "confirmado"/"aqui mesmo" — o único caminho
     * agora é o cadastro do Mercado Livre.
     */
    public function test_faltam_para_texto_nao_cita_mais_confirmacao_manual(): void
    {
        $mensagens = $this->catalogo()->faltamParaTexto($this->truth());

        $this->assertNotEmpty($mensagens);
        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsStringIgnoringCase('aqui mesmo', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('fato confirmado', $mensagem);
            $this->assertStringContainsString('cadastro do Mercado Livre', $mensagem);
        }
    }

    public function test_faltam_para_texto_nunca_sugere_afrouxar_truth_02_03(): void
    {
        $mensagens = $this->catalogo()->faltamParaTexto($this->truth());

        foreach ($mensagens as $mensagem) {
            $this->assertStringNotContainsStringIgnoringCase('afrouxar', $mensagem);
            $this->assertStringNotContainsStringIgnoringCase('inventar', $mensagem);
        }
    }
}
