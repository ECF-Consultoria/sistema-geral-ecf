<?php

namespace Tests\Unit\Publicador;

use App\Services\Ia\AnaliseAnuncioService;
use PHPUnit\Framework\TestCase;

/** Demo 23/10/2026 (A8): a descrição da IA saiu com "Garante já a sua poltrona…" (o certo é "Garanta"). */
class DescricaoPromptPortuguesTest extends TestCase
{
    private function prompt(): string
    {
        $metodo = new \ReflectionMethod(AnaliseAnuncioService::class, 'promptDescricao');

        return $metodo->invoke(new AnaliseAnuncioService(), 'Poltrona Decorativa', 'Loja X', 'Cor: Rosa', ['jtbd' => 'j', 'puv' => 'p']);
    }

    public function test_prompt_pede_imperativo_afirmativo_e_portugues_do_brasil_sem_girias(): void
    {
        $p = $this->prompt();

        $this->assertStringContainsString('PORTUGUÊS DO BRASIL', $p);
        $this->assertStringContainsString('gírias', $p);
        $this->assertStringContainsString('imperativo afirmativo', $p);
        foreach (['"garanta"', '"aproveite"', '"compre"', '"leve"'] as $forma) {
            $this->assertStringContainsString($forma, $p);
        }
        $this->assertStringContainsString('Garanta já a sua', $p);
    }

    public function test_regras_de_21_10_e_formato_da_resposta_continuam(): void
    {
        $p = $this->prompt();

        // Sem saudação nem nome de loja (§45) — não desfazer.
        $this->assertStringContainsString('SEM SAUDAÇÃO E SEM NOME DE LOJA', $p);
        $this->assertStringNotContainsString('Loja X', $p);
        $this->assertStringContainsString('{"descricao":"..."}', $p);
    }
}
