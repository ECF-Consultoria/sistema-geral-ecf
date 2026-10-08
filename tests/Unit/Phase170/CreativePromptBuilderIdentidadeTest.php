<?php

namespace Tests\Unit\Phase170;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\CreativeContext;
use App\Services\Creative\Dto\ProductTruth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloco IDENTIDADE (Fase 170, Plano 01, Task 2, IDENT-02/03/05) — prova de:
 * - presença/ausência conforme a conta tem ou não identidade cadastrada
 * - defesa TRUTH-02/03 (identidade nunca autoriza fato novo sobre o produto)
 * - precedência explícita de CENA/AMBIENTE/leiaute sobre a identidade
 * - reforço de capa isolada (hero/white_background), ausente em lifestyle
 * - posição no prompt: depois de AMBIENTE, antes de TEXTO
 * - sanitização do texto livre (mesma defesa dos outros blocos)
 *
 * Sem banco: `ProductTruth`/`slotPlano` montados à mão, no molde de
 * `CreativePromptBuilderSlotTest`/`CreativePromptBuilderAmbienteTest`.
 */
class CreativePromptBuilderIdentidadeTest extends TestCase
{
    // Testes com tipo=lifestyle passam pelo bloco AMBIENTE (D5), que lê
    // `Configuracao` (tabela `configuracoes`) — mesma convenção de
    // `tests/Unit/Phase168/CreativePromptBuilderAmbienteTest.php`.
    use RefreshDatabase;

    private function builder(): CreativePromptBuilder
    {
        return new CreativePromptBuilder(new CreativeSlotCatalog());
    }

    private function truth(): ProductTruth
    {
        return new ProductTruth(
            marca: 'MarcaTeste',
            modelo: 'ModeloX',
            fatosVerificados: ['Material' => 'MDF'],
            contagens: [],
            beneficiosVerificados: [],
            claimsProibidas: ['Não escreva texto na imagem.'],
            referenciasMeta: [],
        );
    }

    private function slotPlano(array $overrides = []): array
    {
        return array_merge([
            'indice'       => 1,
            'tipo'         => 'lifestyle',
            'objetivo'     => 'Objetivo padrão de teste.',
            'cena'         => 'Cena padrão de teste.',
            'headline'     => null,
            'badges'       => [],
            'fatos_usados' => [],
            'proibicoes'   => [],
        ], $overrides);
    }

    private function contexto(): CreativeContext
    {
        return new CreativeContext(
            rascunhoId: 1,
            produto: 'Produto Teste',
            marca: 'MarcaTeste',
            modelo: 'ModeloX',
            categoriaId: 'MLB1574',
            descricao: null,
            atributos: [],
            variacoes: ['quantidade' => 0, 'combinacoes' => []],
            loja: 'Loja Teste',
            imagensReferencia: [],
            referenciasMeta: [],
        );
    }

    private const TEXTO_IDENTIDADE = 'Paleta escura, fonte serifada, filtro vintage.';

    // ═══ IDENT-02 — identidade presente entra no prompt ═════════════════

    public function test_identidade_presente_contem_a_palavra_identidade_e_o_texto_literal(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('IDENTIDADE', $prompt);
        $this->assertStringContainsString(self::TEXTO_IDENTIDADE, $prompt);
    }

    // ═══ IDENT-03 — identidade null nunca aparece no prompt ═════════════

    public function test_identidade_null_nunca_contem_a_palavra_identidade(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']), 0, null, null);

        $this->assertStringNotContainsString('IDENTIDADE', $prompt);
    }

    public function test_identidade_omitida_do_argumento_tambem_nunca_contem_a_palavra_identidade(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']));

        $this->assertStringNotContainsString('IDENTIDADE', $prompt);
    }

    // ═══ TRUTH-02/03 — defesa explícita contra virar fato do produto ════

    public function test_identidade_presente_contem_a_frase_de_defesa_truth(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('só ESTILO VISUAL', $prompt);
        $this->assertStringContainsString('nunca um fato sobre o produto', $prompt);
        $this->assertStringContainsString('quantidade, medida, material, marca ou característica do produto', $prompt);
    }

    // ═══ Precedência — CENA/AMBIENTE/leiaute vencem em caso de conflito ═

    public function test_identidade_presente_contem_a_frase_de_precedencia(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('conflitar com a CENA,', $prompt);
        $this->assertStringContainsString('o AMBIENTE ou o leiaute de texto já definidos acima, eles têm prioridade', $prompt);
    }

    // ═══ Capa isolada (hero/white_background) — reforço adicional ═══════

    public function test_identidade_em_hero_contem_a_frase_adicional_de_capa_isolada(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'hero']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('nunca cenário, objeto, texto ou marca d\'água', $prompt);
    }

    public function test_identidade_em_white_background_contem_a_frase_adicional_de_capa_isolada(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'white_background']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('nunca cenário, objeto, texto ou marca d\'água', $prompt);
    }

    public function test_identidade_em_lifestyle_nao_contem_a_frase_adicional_de_capa_isolada(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $this->assertStringContainsString('IDENTIDADE', $prompt);
        $this->assertStringNotContainsString('nunca cenário, objeto, texto ou marca d\'água', $prompt);
    }

    // ═══ Sanitização — mesma defesa dos outros blocos de texto livre ════

    public function test_identidade_com_caracteres_de_controle_e_quebras_repetidas_sai_sanitizada(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            "Paleta escura\n\n\n<script>alert(1)</script>\x07 fonte serifada",
        );

        $this->assertStringNotContainsString("\n\n\n", $prompt);
        $this->assertStringContainsString('Paleta escura <script>alert(1)</script> fonte serifada', $prompt);
    }

    // ═══ Posição — depois de AMBIENTE, antes de TEXTO ═══════════════════

    public function test_bloco_identidade_aparece_depois_de_ambiente_e_antes_de_texto(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'lifestyle']),
            0,
            null,
            self::TEXTO_IDENTIDADE,
        );

        $posAmbiente   = strpos($prompt, 'AMBIENTE:');
        $posIdentidade = strpos($prompt, 'IDENTIDADE');
        $posTexto      = strpos($prompt, 'TEXTO:');

        $this->assertNotFalse($posAmbiente);
        $this->assertNotFalse($posIdentidade);
        $this->assertNotFalse($posTexto);
        $this->assertTrue($posAmbiente < $posIdentidade);
        $this->assertTrue($posIdentidade < $posTexto);
    }

    // ═══ paraSlotHero() — sempre capa isolada ════════════════════════════

    public function test_para_slot_hero_com_identidade_contem_identidade_texto_e_frase_de_capa_isolada(): void
    {
        $prompt = $this->builder()->paraSlotHero($this->contexto(), $this->truth(), self::TEXTO_IDENTIDADE);

        $this->assertStringContainsString('IDENTIDADE', $prompt);
        $this->assertStringContainsString(self::TEXTO_IDENTIDADE, $prompt);
        $this->assertStringContainsString('nunca cenário, objeto, texto ou marca d\'água', $prompt);
    }

    public function test_para_slot_hero_sem_identidade_nao_contem_nada_do_bloco(): void
    {
        $prompt = $this->builder()->paraSlotHero($this->contexto(), $this->truth());

        $this->assertStringNotContainsString('IDENTIDADE', $prompt);
    }
}
