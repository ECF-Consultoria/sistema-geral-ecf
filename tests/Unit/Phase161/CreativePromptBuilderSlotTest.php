<?php

namespace Tests\Unit\Phase161;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * `CreativePromptBuilder::paraSlot()` — prompt de cada um dos N slots do kit
 * (Fase 161, Plano 02, Task 1). Sem banco: `ProductTruth` e o array de slot
 * são montados à mão, no formato de `CreativeSlotPlan::paraPrompt()`.
 *
 * As asserções são por CONTEÚDO (presença/ausência de trechos), nunca por
 * igualdade do texto inteiro — o teste não pode virar obstáculo a ajuste de
 * redação (ver `<action>` da Task 1 do 161-02-PLAN.md).
 */
class CreativePromptBuilderSlotTest extends TestCase
{
    private function builder(): CreativePromptBuilder
    {
        return new CreativePromptBuilder(new CreativeSlotCatalog());
    }

    private function truth(array $contagens = [], array $claims = ['Não escreva texto na imagem.', 'Não altere a cor do produto.']): ProductTruth
    {
        return new ProductTruth(
            marca: 'MarcaTeste',
            modelo: 'ModeloX',
            fatosVerificados: ['Material' => 'MDF'],
            contagens: $contagens,
            beneficiosVerificados: [],
            claimsProibidas: $claims,
            referenciasMeta: [],
        );
    }

    private function slotPlano(array $overrides = []): array
    {
        return array_merge([
            'indice'       => 1,
            'tipo'         => 'hero',
            'objetivo'     => 'Imagem de capa do anúncio.',
            'cena'         => 'Produto centralizado, fundo branco.',
            'headline'     => null,
            'badges'       => [],
            'fatos_usados' => [],
            'proibicoes'   => [],
        ], $overrides);
    }

    // ═══ hero — nenhum texto, mesmo com badges no plano ═════════════════

    public function test_slot_hero_proibe_texto_logo_selo_marca_dagua_e_ignora_badges(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano([
            'tipo'   => 'hero',
            'badges' => ['Produto premium', 'Garantia de 1 ano'],
        ]));

        $this->assertStringContainsString('TEXTO: PROIBIDO', $prompt);
        $this->assertStringContainsString('NENHUM texto, logo aplicado, selo ou marca d\'água', $prompt);
        $this->assertStringNotContainsString('Produto premium', $prompt);
        $this->assertStringNotContainsString('Garantia de 1 ano', $prompt);
    }

    // ═══ benefits — badges exatas, sem o claim fixo de "sem texto" ══════

    public function test_slot_benefits_manda_escrever_badges_exatas_e_remove_claim_fixo_de_sem_texto(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano([
            'tipo'       => 'benefits',
            'headline'   => 'Resistente e durável',
            'badges'     => ['Feito em MDF', 'Acabamento premium'],
            'proibicoes' => ['Não altere a cor do produto.'],
        ]));

        $this->assertStringContainsString('escreva EXATAMENTE os textos abaixo', $prompt);
        $this->assertStringContainsString('Feito em MDF', $prompt);
        $this->assertStringContainsString('Acabamento premium', $prompt);
        $this->assertStringContainsString('Resistente e durável', $prompt);
        $this->assertStringNotContainsString('Não escreva texto na imagem.', $prompt);
    }

    // ═══ MASTER presente em todos os tipos ══════════════════════════════

    public function test_master_presente_em_qualquer_tipo_de_slot(): void
    {
        foreach (['hero', 'benefits', 'dimensions', 'white_background'] as $tipo) {
            $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => $tipo]));

            $this->assertStringContainsString('MASTER — REGRAS QUE NÃO PODEM SER QUEBRADAS', $prompt);
            $this->assertStringContainsString('Nunca redesenhe o produto', $prompt);
            $this->assertStringContainsString('nunca mude a quantidade ou o conteúdo', $prompt);
        }
    }

    // ═══ TRUTH-02/03 — contagens vazias nunca declaram número, por slot ═

    public function test_contagens_vazias_nao_declaram_numero_em_slot_que_aceita_texto(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(contagens: []), $this->slotPlano(['tipo' => 'package_content']));

        $this->assertStringContainsString('não declare número algum', $prompt);
        $this->assertDoesNotMatchRegularExpression('/CONTAGENS CONFIRMADAS/', $prompt);
    }

    public function test_contagens_preenchidas_saem_literais_com_peca_e_quantidade(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(contagens: [['peca' => 'Gavetas', 'quantidade' => '3', 'origem' => 'cadastro']]),
            $this->slotPlano(['tipo' => 'package_content']),
        );

        $this->assertStringContainsString('CONTAGENS CONFIRMADAS NO CADASTRO', $prompt);
        $this->assertStringContainsString('Gavetas: 3', $prompt);
    }

    // ═══ Sanitização — colapsa quebra de linha/controle vindos do slot ══

    public function test_sanitiza_quebras_de_linha_e_caracteres_de_controle_do_plano(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano([
            'tipo'     => 'benefits',
            'headline' => "Linha 1\n\n\nLinha 2\x07",
            'badges'   => [],
        ]));

        $this->assertStringContainsString('Linha 1 Linha 2', $prompt);
        $this->assertStringNotContainsString("\n\n\n", $prompt);
    }

    // ═══ paraSlotHero() continua existindo e sem alteração de saída ═════

    public function test_para_slot_hero_continua_existindo_e_proibe_texto(): void
    {
        $this->assertTrue(method_exists(CreativePromptBuilder::class, 'paraSlotHero'));
    }
}
