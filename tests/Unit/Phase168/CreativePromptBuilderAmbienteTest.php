<?php

namespace Tests\Unit\Phase168;

use App\Models\Configuracao;
use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bloco AMBIENTE brasileiro subentendido (Fase 168, Plano 02, D5) — prova dos
 * requisitos AMB-01/02/03/04 da `REQUIREMENTS-v25.md`:
 *
 * - AMB-01: `lifestyle`/`lifestyle_uso`/`composicao` recebem o texto aprovado
 *   de ambiente brasileiro subentendido.
 * - AMB-02: os 3 slots proíbem explicitamente bandeira, verde-amarelo,
 *   símbolo nacional e futebol.
 * - AMB-03: `hero`/`white_background` NUNCA recebem o bloco — moderação ML.
 * - AMB-04: `CreativeSlotCatalog::elegiveis()` fica byte-idêntico.
 *
 * Usa `RefreshDatabase` porque `textoAmbiente()` lê `Configuracao` (tabela
 * `configuracoes`) — mesma convenção de `tests/Unit/Phase162/OrcamentoDeRegeneracaoTest.php`.
 */
class CreativePromptBuilderAmbienteTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'creative_ambiente_brasileiro_prompt';

    private function builder(): CreativePromptBuilder
    {
        return new CreativePromptBuilder(new CreativeSlotCatalog());
    }

    private function truth(array $fatosVerificados = [], array $contagens = [], array $atributosIds = []): ProductTruth
    {
        return new ProductTruth(
            marca: 'MarcaTeste',
            modelo: 'ModeloX',
            fatosVerificados: $fatosVerificados,
            contagens: $contagens,
            beneficiosVerificados: [],
            claimsProibidas: ['Não escreva texto na imagem.'],
            referenciasMeta: [],
            atributosIds: $atributosIds,
        );
    }

    private function slotPlano(array $overrides = []): array
    {
        return array_merge([
            'indice'       => 1,
            'tipo'         => 'hero',
            'objetivo'     => 'Objetivo padrão de teste.',
            'cena'         => 'Cena padrão de teste.',
            'headline'     => null,
            'badges'       => [],
            'fatos_usados' => [],
            'proibicoes'   => [],
        ], $overrides);
    }

    // ═══ AMB-01 — os 3 slots ambientados recebem o texto padrão aprovado ═══

    public function test_slot_lifestyle_recebe_bloco_ambiente_com_texto_padrao(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']));

        $this->assertStringContainsString('AMBIENTE:', $prompt);
        $this->assertStringContainsString('luz quente e abundante de clima tropical', $prompt);
        $this->assertStringContainsString('pé-direito e esquadrias de apartamento brasileiro', $prompt);
        $this->assertStringContainsString('costela-de-adão', $prompt);
        $this->assertStringContainsString('jiboia', $prompt);
        $this->assertStringContainsString('madeira clara com branco', $prompt);
    }

    public function test_slot_lifestyle_uso_recebe_bloco_ambiente(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle_uso']));

        $this->assertStringContainsString('AMBIENTE:', $prompt);
        $this->assertStringContainsString('luz quente e abundante de clima tropical', $prompt);
    }

    public function test_slot_composicao_recebe_bloco_ambiente(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'composicao']));

        $this->assertStringContainsString('AMBIENTE:', $prompt);
        $this->assertStringContainsString('luz quente e abundante de clima tropical', $prompt);
    }

    // ═══ AMB-02 — proibição explícita nos 3 slots ambientados ══════════════

    public function test_bloco_ambiente_proibe_explicitamente_bandeira_verde_amarelo_simbolo_e_futebol(): void
    {
        foreach (['lifestyle', 'lifestyle_uso', 'composicao'] as $tipo) {
            $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => $tipo]));

            $this->assertStringContainsString('bandeira', $prompt);
            $this->assertStringContainsString('verde-amarelo', $prompt);
            $this->assertStringContainsString('símbolo nacional', $prompt);
            $this->assertStringContainsString('futebol', $prompt);
        }
    }

    // ═══ AMB-03 — hero e white_background NUNCA recebem o bloco ════════════

    public function test_slot_hero_nunca_recebe_bloco_ambiente(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'hero']));

        $this->assertStringNotContainsString('AMBIENTE:', $prompt);
        $this->assertStringNotContainsString('luz quente e abundante de clima tropical', $prompt);
    }

    public function test_slot_white_background_nunca_recebe_bloco_ambiente(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'white_background']));

        $this->assertStringNotContainsString('AMBIENTE:', $prompt);
        $this->assertStringNotContainsString('luz quente e abundante de clima tropical', $prompt);
    }

    // ═══ AMB-04 — catálogo intocado, por regressão (não por confiança) ═════

    public function test_elegiveis_continua_identico_a_lista_documentada_do_catalogo(): void
    {
        // Truth sem nenhum fato/atributo/contagem — nenhum COM_FATO se qualifica,
        // sobra só a lista SEM_FATO completa, na ordem documentada no catálogo.
        $truth = $this->truth();

        $elegiveis = (new CreativeSlotCatalog())->elegiveis($truth);

        $this->assertSame([
            'hero', 'white_background', 'angles', 'detail',
            'lifestyle', 'lifestyle_uso', 'detail_textura', 'composicao',
        ], $elegiveis);
    }

    // ═══ Configurável sem deploy — Configuracao sobrescreve o padrão ═══════

    public function test_texto_calibrado_via_configuracao_substitui_o_padrao(): void
    {
        Configuracao::set(self::CHAVE, 'TEXTO DE TESTE CALIBRADO');

        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'composicao']));

        $this->assertStringContainsString('TEXTO DE TESTE CALIBRADO', $prompt);
        $this->assertStringNotContainsString('luz quente e abundante de clima tropical', $prompt);
    }

    // ═══ Off-switch — texto vazio apaga o bloco inteiro ═════════════════════

    public function test_texto_vazio_na_configuracao_apaga_o_bloco_ambiente_inteiro(): void
    {
        Configuracao::set(self::CHAVE, '');

        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']));

        $this->assertStringNotContainsString('AMBIENTE:', $prompt);
        $this->assertStringNotContainsString('bandeira', $prompt);
    }

    // ═══ Memoização — mesma instância não repete a consulta a Configuracao ═

    public function test_texto_ambiente_e_memoizado_na_mesma_instancia_do_builder(): void
    {
        $builder = $this->builder();

        $primeiro = $builder->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']));

        // Muda a configuração DEPOIS da primeira chamada — se a 2ª chamada
        // nesta MESMA instância lesse de novo, o texto mudaria no meio do kit.
        Configuracao::set(self::CHAVE, 'TEXTO MUDOU NO MEIO DO KIT');

        $segundo = $builder->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'composicao']));

        $this->assertStringContainsString('luz quente e abundante de clima tropical', $primeiro);
        $this->assertStringContainsString('luz quente e abundante de clima tropical', $segundo);
        $this->assertStringNotContainsString('TEXTO MUDOU NO MEIO DO KIT', $segundo);

        // Uma instância NOVA, por outro lado, lê de novo (comportamento correto).
        $novoBuilder = $this->builder();
        $terceiro    = $novoBuilder->paraSlot($this->truth(), $this->slotPlano(['tipo' => 'lifestyle']));
        $this->assertStringContainsString('TEXTO MUDOU NO MEIO DO KIT', $terceiro);
    }
}
