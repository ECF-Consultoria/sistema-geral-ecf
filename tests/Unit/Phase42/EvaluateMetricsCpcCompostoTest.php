<?php

namespace Tests\Unit\Phase42;

use App\Models\SugadorConfig;
use App\Services\SugadorAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 42 Plan 42-01 — Suite Unit (RED) cobrindo a logica composta do
 * criterio `cpc_alto` em SugadorAnalysisService::evaluateMetrics.
 *
 * Spec D-01 / briefing §8 Opcao B:
 *
 *   $hit = $vendas === 0
 *       && (float) $cpc > $threshold
 *       && ($config->cpc_minimo_cliques === null
 *           || $clicks >= (int) $config->cpc_minimo_cliques);
 *
 * Cenarios cobertos (5 tests):
 *   T1 — modo legacy (cpc_minimo_cliques=null): comportamento atual preservado
 *   T2 — cliques abaixo do minimo: gate bloqueia hit
 *   T3 — boundary inclusivo (clicks == minimo): hit
 *   T4 — clicks bem acima do minimo: hit
 *   T5 — com venda: criterio nao marca em nenhum modo
 *
 * RefreshDatabase necessario porque SugadorAnalysisService eh resolvido via
 * container e instanciar SugadorConfig (mesmo sem persistir) toca casts.
 */
class EvaluateMetricsCpcCompostoTest extends TestCase
{
    use RefreshDatabase;

    /** Resolve o service via container (mantem DI consistente com producao). */
    private function evaluator(): SugadorAnalysisService
    {
        return app(SugadorAnalysisService::class);
    }

    /**
     * Cria SugadorConfig in-memory (sem persistir) usando DEFAULTS + overrides.
     * Para evaluateMetrics o service nao precisa de row no banco — usa o objeto
     * direto. Mantem unit test rapido e isolado.
     */
    private function configWith(array $overrides): SugadorConfig
    {
        return new SugadorConfig(array_merge(SugadorConfig::DEFAULTS, $overrides));
    }

    // ──────────────────────────────────────────────────────────────────────
    // T1 — Legacy: cpc_minimo_cliques null preserva comportamento atual
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cpc_alto_legacy_quando_minimo_cliques_eh_null(): void
    {
        $config = $this->configWith([
            'cpc_maximo'         => 4.00,
            'cpc_maximo_logic'   => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques' => null,
        ]);

        $motivos = $this->evaluator()->evaluateMetrics(
            [
                'investment'    => 10.0,
                'sold_quantity' => 0,
                'cpc'           => 5.0,
                'clicks'        => 2,
            ],
            $config
        );

        $this->assertContains(
            'cpc_alto',
            $motivos,
            'Com cpc_minimo_cliques=null, criterio cpc_alto deve operar identico ao legado (CPC > limite + zero vendas basta).'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T2 — Cliques abaixo do minimo: gate bloqueia o hit
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cpc_alto_bloqueado_quando_clicks_abaixo_minimo(): void
    {
        $config = $this->configWith([
            'cpc_maximo'         => 4.00,
            'cpc_maximo_logic'   => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques' => 5,
        ]);

        $motivos = $this->evaluator()->evaluateMetrics(
            [
                'investment'    => 10.0,
                'sold_quantity' => 0,
                'cpc'           => 5.0,
                'clicks'        => 3, // abaixo do minimo (5)
            ],
            $config
        );

        $this->assertNotContains(
            'cpc_alto',
            $motivos,
            'cpc_minimo_cliques=5 + clicks=3 deve bloquear cpc_alto (gate de cliques).'
        );
        $this->assertSame([], $motivos, 'Nenhum outro criterio deveria marcar nesse cenario.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // T3 — Boundary inclusivo (clicks == minimo): hit
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cpc_alto_marca_hit_no_boundary_inclusivo(): void
    {
        $config = $this->configWith([
            'cpc_maximo'         => 4.00,
            'cpc_maximo_logic'   => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques' => 5,
        ]);

        $motivos = $this->evaluator()->evaluateMetrics(
            [
                'investment'    => 10.0,
                'sold_quantity' => 0,
                'cpc'           => 5.0,
                'clicks'        => 5, // EXATO no minimo (boundary inclusivo >=)
            ],
            $config
        );

        $this->assertContains(
            'cpc_alto',
            $motivos,
            'Boundary inclusivo: clicks == cpc_minimo_cliques deve marcar cpc_alto.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T4 — Clicks acima do minimo: hit
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cpc_alto_marca_hit_acima_do_minimo(): void
    {
        $config = $this->configWith([
            'cpc_maximo'         => 4.00,
            'cpc_maximo_logic'   => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques' => 5,
        ]);

        $motivos = $this->evaluator()->evaluateMetrics(
            [
                'investment'    => 50.0,
                'sold_quantity' => 0,
                'cpc'           => 5.0,
                'clicks'        => 10, // acima do minimo
            ],
            $config
        );

        $this->assertContains(
            'cpc_alto',
            $motivos,
            'clicks=10 (acima do minimo=5) deve marcar cpc_alto.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // T5 — Com venda: criterio nao marca mesmo com tudo configurado
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function cpc_alto_nao_marca_quando_venda_existe(): void
    {
        $config = $this->configWith([
            'cpc_maximo'         => 4.00,
            'cpc_maximo_logic'   => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques' => 5,
        ]);

        $motivos = $this->evaluator()->evaluateMetrics(
            [
                'investment'    => 50.0,
                'sold_quantity' => 1, // VENDEU — criterio cpc_alto so vale com zero vendas
                'cpc'           => 5.0,
                'clicks'        => 10,
            ],
            $config
        );

        $this->assertNotContains(
            'cpc_alto',
            $motivos,
            'sold_quantity > 0 deve impedir cpc_alto mesmo com clicks acima do minimo.'
        );
    }
}
