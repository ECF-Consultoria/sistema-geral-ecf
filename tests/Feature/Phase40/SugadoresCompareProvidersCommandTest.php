<?php

// Phase 40 Plan 40-04 — Suite RED → GREEN do comando `sugadores:compare-providers`.
//
// O comando consome ProviderComparisonService::compareWindow (Plan 40-03) e
// imprime o relatório de paridade em --format=table (default) ou --format=json.
// Exit code 0 se paridade_motivos_pct >= 95.0, exit code 1 caso contrário.
//
// Esta suite valida (9 testes):
//  1. Comando registrado em Artisan::all()
//  2. Sem --company → exit 1
//  3. Sem --from / sem --to → exit 1
//  4. --format=invalido → exit 1
//  5. --from=invalid-date → exit 1
//  6. --format=table com paridade 100% → exit 0 + output pt-BR
//  7. paridade 94.99% → exit 1
//  8. paridade 95.0 (boundary inclusive) → exit 0
//  9. --format=json retorna JSON parseável com chaves esperadas
//
// Setup: mocka ProviderComparisonService no container via $this->app->instance(...).

namespace Tests\Feature\Phase40;

use App\Services\Sugadores\ProviderComparisonService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SugadoresCompareProvidersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Monta um report completo com as 8 chaves padrão.
     * Apenas paridade_motivos_pct é parametrizada — os buckets são placeholders
     * representativos (a soma não precisa ser exata pra este test, só o pct).
     */
    private function report(float $paridade, array $overrides = []): array
    {
        return array_merge([
            'matched'              => 50,
            'metrics_diff'         => 2,
            'motivo_diff'          => 1,
            'apenas_adman'         => 1,
            'apenas_ml'            => 0,
            'quarentena_diff'      => 0,
            'total_items'          => 54,
            'paridade_motivos_pct' => $paridade,
        ], $overrides);
    }

    private function mockComparison(float $paridade, array $overrides = []): MockInterface
    {
        /** @var MockInterface&ProviderComparisonService $mock */
        $mock = Mockery::mock(ProviderComparisonService::class);
        $mock->shouldReceive('compareWindow')
            ->with(Mockery::type('int'), Mockery::type(Carbon::class), Mockery::type(Carbon::class))
            ->andReturn($this->report($paridade, $overrides));
        $this->app->instance(ProviderComparisonService::class, $mock);
        return $mock;
    }

    // ─────────── Test 1: comando registrado ───────────

    #[Test]
    public function comando_registrado_no_artisan(): void
    {
        $this->assertArrayHasKey('sugadores:compare-providers', Artisan::all());
    }

    // ─────────── Test 2: faltando --company aborta ───────────

    #[Test]
    public function company_required(): void
    {
        $exit = Artisan::call('sugadores:compare-providers', [
            '--from' => '2026-06-01',
            '--to'   => '2026-06-25',
        ]);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 3: faltando --from / --to aborta ───────────

    #[Test]
    public function from_to_required(): void
    {
        // Sem --from
        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--to'      => '2026-06-25',
        ]);
        $this->assertEquals(1, $exit);

        // Sem --to
        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
        ]);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 4: --format inválido aborta ───────────

    #[Test]
    public function format_invalido_aborta(): void
    {
        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
            '--to'      => '2026-06-25',
            '--format'  => 'xml',
        ]);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 5: data inválida aborta ───────────

    #[Test]
    public function data_invalida_aborta(): void
    {
        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => 'data-completamente-invalida',
            '--to'      => '2026-06-25',
        ]);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 6: --format=table paridade 100% APROVADA ───────────

    #[Test]
    public function format_table_paridade_100_aprovada(): void
    {
        $this->mockComparison(100.0);

        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
            '--to'      => '2026-06-25',
            '--format'  => 'table',
        ]);
        $this->assertEquals(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('100', $output);
        // Mensagem de aprovação em pt-BR (case insensitive — pode ser APROVADA/aprovada)
        $this->assertMatchesRegularExpression('/aprovad/i', $output);
    }

    // ─────────── Test 7: paridade 94.99% REPROVADA ───────────

    #[Test]
    public function paridade_abaixo_95_reprovada(): void
    {
        $this->mockComparison(94.99);

        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
            '--to'      => '2026-06-25',
        ]);
        $this->assertEquals(1, $exit);

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/reprovad/i', $output);
    }

    // ─────────── Test 8: paridade exata 95.0 (boundary inclusive) ───────────

    #[Test]
    public function paridade_exata_95_aprovada(): void
    {
        $this->mockComparison(95.0);

        $exit = Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
            '--to'      => '2026-06-25',
        ]);
        $this->assertEquals(0, $exit);
    }

    // ─────────── Test 9: --format=json retorna JSON parseável ───────────

    #[Test]
    public function format_json_retorna_json_parseavel(): void
    {
        $this->mockComparison(87.5);

        Artisan::call('sugadores:compare-providers', [
            '--company' => '1',
            '--from'    => '2026-06-01',
            '--to'      => '2026-06-25',
            '--format'  => 'json',
        ]);

        $output = Artisan::output();
        // Localizar o início do JSON (output pode ter linhas em branco)
        $jsonStart = strpos($output, '{');
        $this->assertNotFalse($jsonStart, 'Saída do --format=json não contém objeto JSON.');
        $decoded = json_decode(substr($output, $jsonStart), true);
        $this->assertIsArray($decoded, 'JSON decode falhou em: ' . substr($output, $jsonStart));

        foreach (['matched', 'metrics_diff', 'motivo_diff', 'apenas_adman', 'apenas_ml', 'quarentena_diff', 'paridade_motivos_pct'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
        $this->assertIsNumeric($decoded['paridade_motivos_pct']);
    }
}
