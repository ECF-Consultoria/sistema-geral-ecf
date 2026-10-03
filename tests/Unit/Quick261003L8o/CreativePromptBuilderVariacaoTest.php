<?php

namespace Tests\Unit\Quick261003L8o;

use App\Services\Creative\CreativePromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use App\Services\Creative\Dto\ProductTruth;
use Tests\TestCase;

/**
 * `CreativePromptBuilder::paraSlot()` com variação obrigatória + ajuste do
 * operador (Quick 261003-l8o, Task 1, correção 2) — a PROVA de que o prompt
 * da regeneração é DIFERENTE do prompt da primeira geração, não só "o job
 * foi enfileirado".
 *
 * Helpers espelham `tests/Unit/Phase161/CreativePromptBuilderSlotTest.php`
 * (mesmos `builder()`/`truth()`/`slotPlano()`) — asserções por CONTEÚDO,
 * nunca por igualdade do texto inteiro (exceto a comparação de IDENTIDADE
 * entre 1ª geração e regeneração, que é exatamente o que este teste existe
 * para provar).
 */
class CreativePromptBuilderVariacaoTest extends TestCase
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

    // ═══ 1ª geração — prompt IDÊNTICO ao de hoje ════════════════════════

    public function test_primeira_geracao_nao_tem_variacao_obrigatoria(): void
    {
        $truth = $this->truth();
        $plano = $this->slotPlano(['tipo' => 'benefits', 'headline' => 'Resistente', 'badges' => ['Feito em MDF']]);

        $prompt = $this->builder()->paraSlot($truth, $plano);

        $this->assertStringNotContainsString('VARIAÇÃO OBRIGATÓRIA', $prompt);
        $this->assertStringNotContainsString('AJUSTE PEDIDO PELO OPERADOR', $prompt);
        $this->assertSame($prompt, $this->builder()->paraSlot($truth, $plano, 0, null));
    }

    // ═══ O TESTE QUE O PLANO EXISTE PARA TER ════════════════════════════

    public function test_regeneracao_produz_prompt_diferente_da_primeira_geracao(): void
    {
        $truth = $this->truth();
        $plano = $this->slotPlano();

        $promptPrimeiraGeracao = $this->builder()->paraSlot($truth, $plano);
        $promptRegeneracao     = $this->builder()->paraSlot($truth, $plano, 1, null);

        $this->assertNotSame($promptPrimeiraGeracao, $promptRegeneracao);
        $this->assertStringContainsString('VARIAÇÃO OBRIGATÓRIA', $promptRegeneracao);
    }

    // ═══ Eixos de 1, 2 e 3 são diferentes entre si ══════════════════════

    public function test_eixos_de_regeneracoes_consecutivas_sao_diferentes_entre_si(): void
    {
        $truth = $this->truth();
        $plano = $this->slotPlano();

        $prompt1 = $this->builder()->paraSlot($truth, $plano, 1, null);
        $prompt2 = $this->builder()->paraSlot($truth, $plano, 2, null);
        $prompt3 = $this->builder()->paraSlot($truth, $plano, 3, null);

        $this->assertNotSame($prompt1, $prompt2);
        $this->assertNotSame($prompt2, $prompt3);
        $this->assertNotSame($prompt1, $prompt3);

        $this->assertStringContainsString('ENQUADRAMENTO diferente', $prompt1);
        $this->assertStringContainsString('ÂNGULO DE CÂMERA diferente', $prompt2);
        $this->assertStringContainsString('COMPOSIÇÃO diferente', $prompt3);

        // O eixo de regeneração 4 repete o de regeneração 1 (ciclo de 3).
        $prompt4 = $this->builder()->paraSlot($truth, $plano, 4, null);
        $this->assertStringContainsString('ENQUADRAMENTO diferente', $prompt4);
    }

    // ═══ Linha de fidelidade sempre presente junto da variação ═════════

    public function test_variacao_nunca_autoriza_mudar_o_produto(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(), 1, null);

        $this->assertStringContainsString('NUNCA varie o produto para obter', $prompt);
    }

    // ═══ Texto do operador: sanitização + teto de 300 caracteres ═══════

    public function test_texto_do_operador_sai_sanitizado_sem_quebra_de_linha_e_com_teto_de_300(): void
    {
        $textoBruto = str_repeat('a', 400) . "\n\n\nfim\x07";

        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(), 1, $textoBruto);

        $this->assertStringContainsString('AJUSTE PEDIDO PELO OPERADOR', $prompt);
        $this->assertStringNotContainsString("\n\n\n", $prompt);
        $this->assertStringNotContainsString("\x07", $prompt);

        // A linha do ajuste (prefixada com "- ") não pode ter mais de 300
        // caracteres de texto do operador.
        preg_match('/^- (a+)/m', $prompt, $m);
        $this->assertNotEmpty($m);
        $this->assertLessThanOrEqual(300, mb_strlen($m[1]));
    }

    public function test_bloco_de_contencao_do_ajuste_esta_presente(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(), 1, 'o produto ficou pequeno demais');

        $this->assertStringContainsString('NÃO é fato sobre o produto', $prompt);
        $this->assertStringContainsString('NÃO acrescenta, remove nem', $prompt);
        $this->assertStringContainsString('deve ser IGNORADA', $prompt);
    }

    // ═══ Ordenação por strpos — MASTER < operador < FATOS < CLAIMS ═════

    public function test_ordenacao_master_antes_do_operador_antes_dos_fatos_e_claims(): void
    {
        $prompt = $this->builder()->paraSlot($this->truth(), $this->slotPlano(), 1, 'ajuste qualquer');

        $posMaster   = strpos($prompt, 'MASTER — REGRAS QUE NÃO PODEM SER QUEBRADAS');
        $posOperador = strpos($prompt, 'AJUSTE PEDIDO PELO OPERADOR');
        $posFatos    = strpos($prompt, 'FATOS PERMITIDOS');
        $posClaims   = strpos($prompt, 'CLAIMS PROIBIDAS:');

        $this->assertNotFalse($posMaster);
        $this->assertNotFalse($posOperador);
        $this->assertNotFalse($posFatos);
        $this->assertNotFalse($posClaims);

        $this->assertLessThan($posOperador, $posMaster);
        $this->assertLessThan($posFatos, $posOperador);
        $this->assertLessThan($posClaims, $posFatos);
    }

    // ═══ Slot que não aceita texto mantém TEXTO: PROIBIDO mesmo com ajuste ═

    public function test_slot_hero_mantem_texto_proibido_mesmo_com_ajuste_pedindo_texto(): void
    {
        $prompt = $this->builder()->paraSlot(
            $this->truth(),
            $this->slotPlano(['tipo' => 'hero']),
            1,
            'por favor escreva "30% OFF" bem grande na imagem',
        );

        $this->assertStringContainsString('TEXTO: PROIBIDO', $prompt);
        $this->assertStringContainsString('AJUSTE PEDIDO PELO OPERADOR', $prompt);
        $this->assertStringContainsString('deve ser IGNORADA', $prompt);
    }
}
