<?php

namespace Tests\Unit;

use App\Services\MlKeywordMinerService;
use Tests\TestCase;

/**
 * Testes unitários do MlKeywordMinerService.
 * Não usa DB — PHP puro (análogo a CobrancaCalculatorTest).
 *
 * @group phase17
 */
class MlKeywordMinerTest extends TestCase
{
    private MlKeywordMinerService $miner;

    protected function setUp(): void
    {
        parent::setUp();
        // Sem RefreshDatabase — o service é PHP puro, sem dependência de DB
        $this->miner = new MlKeywordMinerService();
    }

    /**
     * D-04-a: normalizarToken converte para lowercase UTF-8 e remove acentos.
     */
    public function test_normaliza_token(): void
    {
        $this->assertSame('fone estereo', $this->miner->normalizarToken('Fone Estéreo'));
    }

    /**
     * D-04-b: tokenizar remove stopwords pt-BR e mantém termos relevantes.
     */
    public function test_stopwords_filtradas(): void
    {
        $tokens = $this->miner->tokenizar('fone de ouvido bluetooth esportivo');

        // Stopword 'de' deve ser removida
        $this->assertNotContains('de', $tokens);

        // Termo relevante 'fone' deve ser mantido
        $this->assertContains('fone', $tokens);
    }

    /**
     * D-04-c: rankingKeywords gera unigramas/bigramas/trigramas e rankeia por frequência.
     * 'bluetooth' aparece nas 3 entradas → deve ser o top unigrama com frequência 3.
     */
    public function test_ranking_keywords(): void
    {
        $titulos = [
            'Fone Bluetooth Esportivo',
            'Fone Bluetooth Sem Fio',
            'Fone Bluetooth 5.0',
        ];

        $ranking = $this->miner->rankingKeywords($titulos);

        // 'bluetooth' aparece 3x — deve ser o primeiro do ranking
        $this->assertSame('bluetooth', $ranking[0]['termo']);
        $this->assertSame(3, $ranking[0]['frequencia']);
    }

    /**
     * D-05: recomendacaoHeuristica retorna array com as chaves esperadas
     * e o aviso explícito de que é heurística (sem IA).
     */
    public function test_recomendacao_heuristica(): void
    {
        // Monta um ranking mínimo para alimentar a função
        $ranking = [
            ['termo' => 'bluetooth', 'frequencia' => 3, 'eh_tendencia' => false, 'tipo' => 'unigrama'],
            ['termo' => 'fone',      'frequencia' => 3, 'eh_tendencia' => false, 'tipo' => 'unigrama'],
            ['termo' => 'esportivo', 'frequencia' => 1, 'eh_tendencia' => false, 'tipo' => 'unigrama'],
        ];

        $resultado = $this->miner->recomendacaoHeuristica($ranking, [], 'fone bluetooth');

        // Deve conter as chaves obrigatórias
        $this->assertArrayHasKey('titulo_sugerido', $resultado);
        $this->assertArrayHasKey('palavras_top_incluir', $resultado);
        $this->assertArrayHasKey('aviso', $resultado);

        // O aviso deve deixar explícito que é heurística (sem IA — D-05)
        $this->assertStringContainsStringIgnoringCase('heurística', $resultado['aviso']);
    }
}
