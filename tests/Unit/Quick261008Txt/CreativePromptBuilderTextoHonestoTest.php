<?php

namespace Tests\Unit\Quick261008Txt;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * Quick 261008-txt, Correções 1 e 3 — "o prompt mandava desenhar texto e
 * proibia texto na mesma respiração".
 *
 * Achado em produção (criativo 40, kit 7, rascunho "mesa escritório", conta
 * 459): o slot `dimensions` saiu com `badges: []`/`headline: null` e o bloco
 * TEXTO ainda assim mandava "escreva EXATAMENTE os textos abaixo" seguido de
 * NADA — o modelo lê isso como "não escreva nada", contradizendo a CENA
 * (leiaute `LAYOUT_MEDIDAS`, que promete cabeçalho e cotas de medida). Este
 * teste prova que, a partir desta quick, (a) o bloco TEXTO nunca contradiz a
 * CENA quando não há texto confirmado, e (b) a proibição "não escreva texto"
 * reaparece em CLAIMS PROIBIDAS nesse caso, mesmo para um tipo que aceita
 * texto por catálogo — sem nunca duplicar claim nenhuma.
 */
class CreativePromptBuilderTextoHonestoTest extends TestCase
{
    private function builder(): CreativePromptBuilder
    {
        return new CreativePromptBuilder(new CreativeSlotCatalog());
    }

    private function truth(): ProductTruth
    {
        return new ProductTruth(
            marca: 'MarcaTeste',
            modelo: 'ModeloX',
            fatosVerificados: ['Largura' => '120 cm', 'Altura' => '75 cm', 'Profundidade' => '50 cm'],
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: [
                'Não altere a cor do produto.',
                'Não altere a geometria do produto.',
                'Não adicione nem altere logotipo ou marca.',
                'Não adicione acessório que não existe no produto.',
                'Não remova componente ou peça do produto.',
                'Não mude a quantidade ou o conteúdo da embalagem.',
                'Não invente especificação que não está no cadastro.',
                'Não escreva texto na imagem.',
            ],
            referenciasMeta: [],
        );
    }

    private function slotPlanoDimensoesSemTexto(): array
    {
        // Exatamente a forma gravada em produção no criativo 40: tipo
        // aceita texto, mas nada foi validado.
        return [
            'indice'       => 2,
            'tipo'         => 'dimensions',
            'objetivo'     => 'Mostrar as medidas reais do produto, só com os valores confirmados no cadastro.',
            'cena'         => 'Fundo claro e liso. Cabeçalho curto em caixa alta com ícone simples de régua...',
            'headline'     => null,
            'badges'       => [],
            'fatos_usados' => [],
            'proibicoes'   => [],
        ];
    }

    // ═══ Correção 1 — bloco TEXTO nunca contradiz a CENA quando vazio ═════

    public function test_slot_dimensions_sem_texto_confirmado_nao_manda_escrever_lista_vazia(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlanoDimensoesSemTexto());

        $this->assertStringNotContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('TEXTO: NENHUM valor foi confirmado', $prompt);
        $this->assertStringContainsString('não escreva', $prompt);
    }

    public function test_slot_dimensions_sem_texto_confirmado_ainda_mantem_a_cena_do_leiaute(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlanoDimensoesSemTexto());

        // A CENA continua presente no prompt — o que muda é a instrução de
        // TEXTO não prometer elementos que não existem.
        $this->assertStringContainsString('CENA: Fundo claro e liso.', $prompt);
    }

    public function test_slot_dimensions_com_texto_confirmado_continua_mandando_escrever_exatamente(): void
    {
        $slotPlano = array_merge($this->slotPlanoDimensoesSemTexto(), [
            'badges' => ['Largura: 120 cm', 'Altura: 75 cm', 'Profundidade: 50 cm'],
        ]);

        $prompt = $this->builder()->paraSlot($this->truth(), $slotPlano);

        $this->assertStringContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('Largura: 120 cm', $prompt);
        $this->assertStringContainsString('Altura: 75 cm', $prompt);
        $this->assertStringContainsString('Profundidade: 50 cm', $prompt);
    }

    // ═══ Consistência de CLAIMS — "não escreva texto" volta quando não há texto ═

    public function test_claim_de_nao_escrever_texto_reaparece_quando_tipo_aceita_texto_mas_nada_foi_confirmado(): void
    {
        // `claimsDoSlot()` só filtra o claim fixo do Truth quando há texto
        // CONFIRMADO — sem ele, 'Não escreva texto na imagem.' (já presente
        // em `truth->claimsProibidas`) simplesmente nunca é removido.
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlanoDimensoesSemTexto());

        $this->assertStringContainsString('Não escreva texto na imagem.', $prompt);
    }

    /**
     * A proibição ADICIONAL que `CreativePlanner::proibicoesDoSlot()` grava
     * em `slot_plano['proibicoes']` quando não há texto confirmado — só
     * aparece quando o plano VEIO do Planner (aqui simulado à mão, no
     * formato que ele grava de fato).
     */
    public function test_proibicao_adicional_do_planner_tambem_aparece_quando_presente_no_slot_plano(): void
    {
        $slotPlano = array_merge($this->slotPlanoDimensoesSemTexto(), [
            'proibicoes' => ['Não escreva texto, logo, selo ou marca d\'água nesta imagem.'],
        ]);

        $prompt = $this->builder()->paraSlot($this->truth(), $slotPlano);

        $this->assertStringContainsString('Não escreva texto, logo, selo ou marca d\'água nesta imagem.', $prompt);
    }

    public function test_claim_de_nao_escrever_texto_fica_fora_quando_ha_texto_confirmado(): void
    {
        $slotPlano = array_merge($this->slotPlanoDimensoesSemTexto(), [
            'badges' => ['Largura: 120 cm'],
        ]);

        $prompt = $this->builder()->paraSlot($this->truth(), $slotPlano);

        $this->assertStringNotContainsString('Não escreva texto na imagem.', $prompt);
        $this->assertStringNotContainsString('Não escreva texto, logo, selo ou marca d\'água nesta imagem.', $prompt);
    }

    // ═══ Correção 3 — claims nunca duplicadas ══════════════════════════════

    public function test_claims_proibidas_nunca_aparecem_duplicadas_no_prompt(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlanoDimensoesSemTexto());

        $posClaims = strpos($prompt, 'CLAIMS PROIBIDAS:');
        $this->assertNotFalse($posClaims);
        $blocoClaims = substr($prompt, $posClaims);

        $this->assertSame(
            1,
            substr_count($blocoClaims, 'Não altere a cor do produto.'),
            'A claim fixa "Não altere a cor do produto." não pode aparecer mais de uma vez.'
        );
        $this->assertSame(
            1,
            substr_count($blocoClaims, 'Não invente especificação que não está no cadastro.'),
            'A claim fixa "Não invente especificação..." não pode aparecer mais de uma vez.'
        );
    }

    public function test_claims_proibidas_nunca_duplicam_mesmo_quando_slot_plano_repete_proibicao_do_truth(): void
    {
        // Simula um `slot_plano['proibicoes']` que por algum motivo já
        // repete uma claim fixa do Truth (defesa em profundidade da
        // Correção 3 — array_unique em claimsDoSlot(), independente da
        // causa raiz já corrigida em CreativePlanner::proibicoesDoSlot()).
        $slotPlano = array_merge($this->slotPlanoDimensoesSemTexto(), [
            'proibicoes' => ['Não altere a cor do produto.'],
        ]);

        $prompt = $this->builder()->paraSlot($this->truth(), $slotPlano);

        $posClaims   = strpos($prompt, 'CLAIMS PROIBIDAS:');
        $blocoClaims = substr($prompt, $posClaims);

        $this->assertSame(1, substr_count($blocoClaims, 'Não altere a cor do produto.'));
    }
}
