<?php

namespace Tests\Unit\Phase162;

use App\Services\Creative\CreativeJuizPromptBuilder;
use App\Services\Creative\CreativeSlotCatalog;
use Tests\TestCase;

/**
 * Cobertura do `CreativeJuizPromptBuilder` (Fase 162, D-06) — sem banco, sem
 * HTTP: só a montagem de texto. O bloco TRUTH-02/03 do juiz é obrigatório e
 * tem teste próprio (item a).
 */
class CreativeJuizPromptBuilderTest extends TestCase
{
    private function builder(): CreativeJuizPromptBuilder
    {
        return new CreativeJuizPromptBuilder(new CreativeSlotCatalog());
    }

    /** Recorta o bloco "CONTAGENS CONFIRMADAS NO CADASTRO:" até a próxima linha em branco. */
    private function blocoContagens(string $prompt): string
    {
        $inicio = strpos($prompt, 'CONTAGENS CONFIRMADAS NO CADASTRO:');
        $this->assertNotFalse($inicio, 'Prompt deveria conter o bloco de contagens.');

        $fim = strpos($prompt, "\n\n", $inicio);

        return $fim !== false ? substr($prompt, $inicio, $fim - $inicio) : substr($prompt, $inicio);
    }

    /** (a) contagens vazia: proíbe exigir contagem e o bloco de contagens não tem dígito. */
    public function test_contagens_vazia_proibe_exigir_contagem_e_nao_vaza_digito(): void
    {
        $truth = [
            'marca' => 'MarcaX', 'modelo' => 'ModeloY',
            'fatos_verificados' => [], 'contagens' => [],
        ];

        $prompt = $this->builder()->paraCriativo($truth, null, 2);

        $this->assertStringContainsString(
            'NÃO exija nem afirme contagem nenhuma',
            $prompt,
        );

        $blocoContagens = $this->blocoContagens($prompt);
        $this->assertDoesNotMatchRegularExpression('/\d/', $blocoContagens);
    }

    /** (b) contagem presente aparece EXATAMENTE como gravada. */
    public function test_contagem_presente_aparece_exatamente_como_gravada(): void
    {
        $truth = [
            'marca' => null, 'modelo' => null,
            'fatos_verificados' => [],
            'contagens' => [
                ['peca' => 'portas', 'quantidade' => '2', 'origem' => 'cadastro'],
                ['peca' => 'gavetas', 'quantidade' => '3', 'origem' => 'cadastro'],
            ],
        ];

        $prompt = $this->builder()->paraCriativo($truth, null, 1);

        $this->assertStringContainsString('portas: 2', $prompt);
        $this->assertStringContainsString('gavetas: 3', $prompt);
    }

    /** (c1) slot que NÃO aceita texto trata qualquer texto como defeito. */
    public function test_slot_sem_texto_trata_texto_como_defeito(): void
    {
        $slotPlano = [
            'indice' => 1, 'tipo' => 'hero', 'objetivo' => 'Imagem principal',
            'cena' => 'Produto centralizado', 'headline' => null, 'badges' => [],
            'fatos_usados' => [], 'proibicoes' => [],
        ];

        $prompt = $this->builder()->paraCriativo(['contagens' => [], 'fatos_verificados' => []], $slotPlano, 1);

        $this->assertStringContainsString('NÃO aceita texto', $prompt);
        $this->assertStringContainsString('DEFEITO', $prompt);
    }

    /** (c2) slot que aceita texto lista os textos exatos esperados. */
    public function test_slot_com_texto_lista_textos_exatos_esperados(): void
    {
        $slotPlano = [
            'indice' => 2, 'tipo' => 'benefits', 'objetivo' => 'Destacar benefícios',
            'cena' => 'Produto com destaques', 'headline' => 'Resistente a impactos',
            'badges' => ['Garantia 12 meses'], 'fatos_usados' => [], 'proibicoes' => [],
        ];

        $prompt = $this->builder()->paraCriativo(['contagens' => [], 'fatos_verificados' => []], $slotPlano, 1);

        $this->assertStringContainsString('Resistente a impactos', $prompt);
        $this->assertStringContainsString('Garantia 12 meses', $prompt);
    }

    /** (d) título com \n e caracteres de controle sai colapsado (sanitização). */
    public function test_titulo_com_quebra_de_linha_e_controle_sai_colapsado(): void
    {
        $truth = [
            'marca' => "Marca\nComÇontrole\x07", 'modelo' => null,
            'fatos_verificados' => [], 'contagens' => [],
        ];

        $prompt = $this->builder()->paraCriativo($truth, null, 1);

        $this->assertStringContainsString('Marca ComÇontrole', $prompt);
        $this->assertStringNotContainsString("\x07", $prompt);
    }

    /** (e) a frase de ORDEM DAS IMAGENS reflete o número de originais. */
    public function test_ordem_das_imagens_reflete_quantidade_de_originais(): void
    {
        $prompt = $this->builder()->paraCriativo(['contagens' => [], 'fatos_verificados' => []], null, 4);

        $this->assertStringContainsString('As 4 primeiras imagens são as FOTOS ORIGINAIS', $prompt);
        $this->assertStringContainsString('A ÚLTIMA imagem é a imagem GERADA', $prompt);
    }
}
