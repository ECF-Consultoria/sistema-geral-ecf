<?php

namespace Tests\Unit;

use App\Services\ForecastService;
use Tests\TestCase;

/**
 * Testes unitários puros do ForecastService (Phase 27).
 *
 * Sem RefreshDatabase — service não toca banco, cache, HTTP nem Carbon.
 * Todos os casos testam comportamento determinístico com entradas conhecidas.
 *
 * Cobertura:
 *   - regressaoLinear: slope conhecido + constante + degenerado vazio + degenerado N=1
 *   - projetar: 3 meses projetados a partir de startX
 *   - coeficienteVariacao: constante + valor calculável + media zero + strings + N=1
 */
class ForecastServiceTest extends TestCase
{
    private ForecastService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ForecastService();
    }

    // ─── regressaoLinear ──────────────────────────────────────────────────────

    /**
     * Série [1,2,3,4,5] → reta y = x + 1 (slope=1, intercept=1, R²=1).
     * Caso canônico para validar a fórmula de mínimos quadrados.
     */
    public function test_regressao_linear_slope_conhecido(): void
    {
        $r = $this->service->regressaoLinear([1, 2, 3, 4, 5]);

        $this->assertEqualsWithDelta(1.0, $r['a'], 0.001, 'slope deve ser 1.0');
        $this->assertEqualsWithDelta(1.0, $r['b'], 0.001, 'intercept deve ser 1.0');
        $this->assertEqualsWithDelta(1.0, $r['r2'], 0.001, 'R² deve ser 1.0 em ajuste perfeito');
    }

    /**
     * Série constante [2,2,2,2] → slope=0, intercept=2 (média da série).
     * Verifica que série sem tendência não resulta em slope espúrio.
     */
    public function test_regressao_linear_serie_constante(): void
    {
        $r = $this->service->regressaoLinear([2, 2, 2, 2]);

        $this->assertEqualsWithDelta(0.0, $r['a'], 0.001, 'slope deve ser 0 em série constante');
        $this->assertEqualsWithDelta(2.0, $r['b'], 0.001, 'intercept deve ser a média (2.0)');
    }

    /**
     * Série vazia [] → caso degenerado — deve retornar zeros sem TypeError.
     * Garante que ECF Drive retornando 0 meses não quebra o controller.
     */
    public function test_regressao_linear_degenerado_vazio(): void
    {
        $r = $this->service->regressaoLinear([]);

        $this->assertSame(0.0, $r['a']);
        $this->assertSame(0.0, $r['b']);
        $this->assertSame(0.0, $r['r2']);
    }

    /**
     * Série com 1 ponto [42] → slope=0, intercept=42 (único valor).
     * Verifica que N<2 retorna o próprio valor como intercept.
     */
    public function test_regressao_linear_degenerado_n_igual_1(): void
    {
        $r = $this->service->regressaoLinear([42]);

        $this->assertSame(0.0, $r['a']);
        $this->assertSame(42.0, $r['b'], 'com 1 ponto, intercept deve ser o próprio valor');
        $this->assertSame(0.0, $r['r2']);
    }

    // ─── projetar ────────────────────────────────────────────────────────────

    /**
     * projetar({a:1, b:0}, 3, 5) → [x=5,y=5], [x=6,y=6], [x=7,y=7].
     * Valida que startX é o índice exato do primeiro ponto projetado.
     */
    public function test_projetar_3_meses(): void
    {
        $proj = $this->service->projetar(['a' => 1.0, 'b' => 0.0], 3, 5);

        $this->assertCount(3, $proj, 'deve retornar exatamente 3 pontos');
        $this->assertSame(5, $proj[0]['x']);
        $this->assertEqualsWithDelta(5.0, $proj[0]['y'], 0.001);
        $this->assertSame(6, $proj[1]['x']);
        $this->assertEqualsWithDelta(6.0, $proj[1]['y'], 0.001);
        $this->assertSame(7, $proj[2]['x']);
        $this->assertEqualsWithDelta(7.0, $proj[2]['y'], 0.001);
    }

    // ─── coeficienteVariacao ──────────────────────────────────────────────────

    /**
     * Série constante [100,100,100,100] → variância zero → CV = 0.
     * Seller com receita perfeitamente estável tem CV=0.
     */
    public function test_coeficiente_variacao_serie_constante(): void
    {
        $cv = $this->service->coeficienteVariacao([100, 100, 100, 100]);
        $this->assertEqualsWithDelta(0.0, $cv, 0.001);
    }

    /**
     * [10,20,30,40] → média=25, desvio=sqrt(125)≈11.180, CV≈0.4472.
     * Valida fórmula: variância populacional / N → desvio / média.
     */
    public function test_coeficiente_variacao_valor_conhecido(): void
    {
        // media = 25
        // variancia = ((10-25)^2 + (20-25)^2 + (30-25)^2 + (40-25)^2) / 4
        //           = (225 + 25 + 25 + 225) / 4 = 125
        // desvio = sqrt(125) ≈ 11.1803
        // cv = 11.1803 / 25 ≈ 0.4472
        $cv = $this->service->coeficienteVariacao([10, 20, 30, 40]);
        $this->assertEqualsWithDelta(0.4472, $cv, 0.01);
    }

    /**
     * [0,0,0] → média=0 → divisão por zero → retorna null.
     * CV é indefinido quando a média é zero (receita zero).
     */
    public function test_coeficiente_variacao_media_zero_retorna_null(): void
    {
        $this->assertNull($this->service->coeficienteVariacao([0, 0, 0]));
    }

    /**
     * Cast defensivo: ['100','200','300'] → deve rodar sem TypeError.
     * tgmvLc vem STRING do parceiro ECF Drive (pitfall CONTEXT Phase 27).
     */
    public function test_coeficiente_variacao_aceita_strings(): void
    {
        $cv = $this->service->coeficienteVariacao(['100', '200', '300']);

        $this->assertNotNull($cv, 'CV deve aceitar strings parseáveis sem TypeError');
        $this->assertIsFloat($cv);
    }

    /**
     * N=1 → desvio padrão indefinido → retorna null.
     * Um único ponto não permite calcular variância.
     */
    public function test_coeficiente_variacao_n_igual_1_retorna_null(): void
    {
        $this->assertNull($this->service->coeficienteVariacao([42]));
    }
}
