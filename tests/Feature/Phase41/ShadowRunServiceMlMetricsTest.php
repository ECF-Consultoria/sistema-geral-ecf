<?php

// Phase 41 Plan 41-04 — Suite RED → GREEN do refactor ShadowRunService para
// mesclar metricas operacionais ML (Plan 41-02 MercadoLivreAdsService::getLastRunMetrics)
// no campo `summary` JSON da row `sugador_provider_runs` correspondente ao provider 'ml'.
//
// Provider 'adman' permanece SEM mudanca — gate de zero regressao Plan 40-02
// (ShadowRunServiceTest 9/9 nao pode quebrar).
//
// Esta suite cobre (5 tests):
//   1. summary do provider 'ml' contem 'ml_metrics' (6 chaves canonical) quando
//      MercadoLivreAdsService esta disponivel via DI e retorna metricas validas
//   2. summary do provider 'adman' NAO contem 'ml_metrics' (back-compat Plan 40-02)
//   3. summary do provider 'ml' PRESERVA as 5 chaves originais Plan 40-02
//      (campanhas, adgroups, items, skipped, reason) — extensao puramente aditiva
//   4. excecao em getLastRunMetrics NAO interrompe a run (try/catch defensivo +
//      Log::warning + summary fica sem ml_metrics)
//   5. construtor com $mlAds=null (DI nao resolveu) continua funcionando — summary
//      ml fica sem ml_metrics sem warning
//
// Mockery setup: mocka SugadorAnalysisService inteiro + MercadoLivreAdsService inteiro,
// binda ambos no container (pattern Plan 40-02). ShadowRunService consome via DI.
// Para Test 5 instanciamos o service explicitamente com null no segundo parametro.

namespace Tests\Feature\Phase41;

use App\Models\Company;
use App\Models\SugadorConfig;
use App\Models\SugadorProviderRun;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\ShadowRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowRunServiceMlMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        // Determinismo temporal — mesma data usada pelo ShadowRunServiceTest Phase 40-02.
        Carbon::setTestNow(Carbon::create(2026, 6, 26, 12, 0, 0));
        $this->hoje = Carbon::create(2026, 6, 26)->startOfDay();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Cria empresa valida + config padrao (dias_analise=7).
     * Pattern reaproveitado de Phase40\ShadowRunServiceTest::makeCompanyWithConfig.
     */
    private function makeCompanyWithConfig(): Company
    {
        $company = Company::create([
            'name'             => 'Empresa Shadow ML Metrics 41-04',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-41-04-' . random_int(1000, 9999),
            'active'           => true,
        ]);

        SugadorConfig::create([
            'company_id'                      => $company->id,
            'dias_analise'                    => 7,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => true,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ]);

        return $company;
    }

    /**
     * Gera fixture sintetico no shape que SugadorAnalysisService realmente retorna
     * (compativel com Phase40\ShadowRunServiceTest::detalhesFixture).
     */
    private function detalhesFixture(int $n, string $prefix): array
    {
        $detalhes = [];
        for ($i = 1; $i <= $n; $i++) {
            $detalhes[] = [
                'tipo'         => 'adgroup',
                'campaign_id'  => "{$prefix}-cmp-{$i}",
                'adgroup_id'   => "{$prefix}-adg-{$i}",
                'mlb_id'       => "MLB{$prefix}{$i}",
                'nome'         => "Adgroup {$prefix} #{$i}",
                'investimento' => 10.0 + $i,
                'vendas'       => 0,
                'motivos'      => ['cpc_alto'],
            ];
        }
        return $detalhes;
    }

    private function payloadOk(array $detalhes, int $campanhas = 0, ?int $adgroups = null): array
    {
        return [
            'skipped'   => false,
            'reason'    => null,
            'campanhas' => $campanhas,
            'adgroups'  => $adgroups ?? count($detalhes),
            'detalhes'  => $detalhes,
        ];
    }

    /**
     * Mocka SugadorAnalysisService inteiro e binda no container.
     * 4o arg ($dryRun) deve ser SEMPRE true (gate REQ-40-02 — preservado por
     * Phase40\ShadowRunServiceTest Test 9).
     * 5o arg ($forceProvider) seleciona o payload por provider.
     */
    private function mockAnalyzer(array $payloadAdman, array $payloadMl): void
    {
        /** @var MockInterface&SugadorAnalysisService $mock */
        $mock = Mockery::mock(SugadorAnalysisService::class);

        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'adman')
            ->andReturn($payloadAdman);

        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'ml')
            ->andReturn($payloadMl);

        $this->app->instance(SugadorAnalysisService::class, $mock);
    }

    /**
     * Mocka MercadoLivreAdsService::getLastRunMetrics retornando o payload dado.
     * Usado para Tests 1, 2, 3.
     */
    private function mockMlAds(array $metrics): void
    {
        /** @var MockInterface&MercadoLivreAdsService $mock */
        $mock = Mockery::mock(MercadoLivreAdsService::class);
        $mock->shouldReceive('getLastRunMetrics')->andReturn($metrics);

        $this->app->instance(MercadoLivreAdsService::class, $mock);
    }

    /**
     * Mocka MercadoLivreAdsService::getLastRunMetrics lancando excecao.
     * Usado para Test 4.
     */
    private function mockMlAdsThrowing(\Throwable $exception): void
    {
        /** @var MockInterface&MercadoLivreAdsService $mock */
        $mock = Mockery::mock(MercadoLivreAdsService::class);
        $mock->shouldReceive('getLastRunMetrics')->andThrow($exception);

        $this->app->instance(MercadoLivreAdsService::class, $mock);
    }

    private function defaultMlMetrics(): array
    {
        return [
            'total_calls'         => 7,
            'pages_read'          => 2,
            'rate_limit_429'      => 1,
            'refresh_token_count' => 0,
            'backoff_sleep_ms'    => 1500,
            'total_duration_ms'   => 4200,
        ];
    }

    // ─────────── Test 1: summary ml contem ml_metrics com 6 chaves ───────────

    #[Test]
    public function summary_ml_contem_ml_metrics_quando_provider_ml(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->mockAnalyzer(
            $this->payloadOk($this->detalhesFixture(2, 'adm'), adgroups: 2),
            $this->payloadOk($this->detalhesFixture(3, 'ml'), adgroups: 3),
        );
        $this->mockMlAds($this->defaultMlMetrics());

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $mlRun = SugadorProviderRun::where('provider', 'ml')->first();

        $this->assertNotNull($mlRun, 'Row ml deve existir.');
        $this->assertIsArray($mlRun->summary);
        $this->assertArrayHasKey('ml_metrics', $mlRun->summary, 'summary do provider ml deve conter ml_metrics.');

        $mlMetrics = $mlRun->summary['ml_metrics'];
        $this->assertIsArray($mlMetrics);

        // Valida as 6 chaves canonicas do contrato Plan 41-02 (getLastRunMetrics).
        foreach (['total_calls', 'pages_read', 'rate_limit_429', 'refresh_token_count', 'backoff_sleep_ms', 'total_duration_ms'] as $key) {
            $this->assertArrayHasKey($key, $mlMetrics, "ml_metrics deve conter chave '{$key}'.");
        }

        // Valores batem com o mock.
        $this->assertEquals(7,    $mlMetrics['total_calls']);
        $this->assertEquals(2,    $mlMetrics['pages_read']);
        $this->assertEquals(1,    $mlMetrics['rate_limit_429']);
        $this->assertEquals(0,    $mlMetrics['refresh_token_count']);
        $this->assertEquals(1500, $mlMetrics['backoff_sleep_ms']);
        $this->assertEquals(4200, $mlMetrics['total_duration_ms']);
    }

    // ─────────── Test 2: summary adman NAO contem ml_metrics ───────────

    #[Test]
    public function summary_adman_nao_contem_ml_metrics(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->mockAnalyzer(
            $this->payloadOk($this->detalhesFixture(2, 'adm'), adgroups: 2),
            $this->payloadOk($this->detalhesFixture(3, 'ml'), adgroups: 3),
        );
        $this->mockMlAds($this->defaultMlMetrics());

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();

        $this->assertNotNull($admanRun, 'Row adman deve existir.');
        $this->assertIsArray($admanRun->summary);

        // ml_metrics so vai no summary do provider 'ml' (back-compat Plan 40-02).
        $this->assertArrayNotHasKey('ml_metrics', $admanRun->summary, 'summary do adman NAO deve conter ml_metrics.');

        // Continua com as 5 chaves originais Plan 40-02.
        foreach (['campanhas', 'adgroups', 'items', 'skipped', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $admanRun->summary, "summary adman deve conter '{$key}' (Plan 40-02 baseline).");
        }
    }

    // ─────────── Test 3: summary ml preserva chaves baseline Plan 40-02 ───────────

    #[Test]
    public function summary_ml_preserva_chaves_baseline_plan_40_02(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->mockAnalyzer(
            $this->payloadOk($this->detalhesFixture(2, 'adm'), adgroups: 2),
            $this->payloadOk($this->detalhesFixture(3, 'ml'), adgroups: 3),
        );
        $this->mockMlAds($this->defaultMlMetrics());

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $mlRun = SugadorProviderRun::where('provider', 'ml')->first();

        // 5 chaves originais Plan 40-02 — extensao Plan 41-04 NAO pode remover nenhuma.
        foreach (['campanhas', 'adgroups', 'items', 'skipped', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $mlRun->summary, "summary ml deve conter '{$key}' (Plan 40-02 baseline).");
        }

        // + ml_metrics (novo Plan 41-04). Total 6 chaves.
        $this->assertArrayHasKey('ml_metrics', $mlRun->summary);

        // Valores semanticos preservados — items deve refletir o count de detalhes ML.
        $this->assertEquals(3, $mlRun->summary['adgroups']);
        $this->assertEquals(3, $mlRun->summary['items']);
        $this->assertFalse($mlRun->summary['skipped']);
        $this->assertNull($mlRun->summary['reason']);
    }

    // ─────────── Test 4: excecao em getLastRunMetrics NAO interrompe a run ───────────

    #[Test]
    public function excecao_em_getLastRunMetrics_nao_interrompe_run(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->mockAnalyzer(
            $this->payloadOk($this->detalhesFixture(1, 'adm'), adgroups: 1),
            $this->payloadOk($this->detalhesFixture(2, 'ml'), adgroups: 2),
        );

        // MercadoLivreAdsService::getLastRunMetrics lanca — coleta defensiva
        // deve engolir (try/catch) + log warning + summary fica sem ml_metrics.
        $this->mockMlAdsThrowing(new \RuntimeException('Failed to compute ml metrics'));

        Log::spy();

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);

        // NAO lanca excecao — try/catch defensivo engole.
        $result = $service->run($company, $this->hoje);

        $mlRun = SugadorProviderRun::where('provider', 'ml')->first();

        // analyzer foi bem-sucedido — status fica 'completed', so a coleta de metricas falhou.
        $this->assertEquals('completed', $mlRun->status, 'Excecao em getLastRunMetrics nao pode marcar run como failed.');
        $this->assertEquals('completed', $result['ml_status']);

        // summary ml NAO tem ml_metrics (skip silencioso + log warning).
        $this->assertArrayNotHasKey('ml_metrics', $mlRun->summary);

        // Baseline Plan 40-02 preservado mesmo com o skip.
        foreach (['campanhas', 'adgroups', 'items', 'skipped', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $mlRun->summary);
        }

        // Log warning emitido com prefixo padrao [Sugadores Shadow] e mencionando ml_metrics.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => is_string($msg)
                && str_contains($msg, '[Sugadores Shadow]')
                && str_contains($msg, 'ml_metrics'))
            ->atLeast()->once();
    }

    // ─────────── Test 5: mlAds=null no construtor continua funcionando ───────────

    #[Test]
    public function mlAds_null_no_constructor_continua_funcionando(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->mockAnalyzer(
            $this->payloadOk($this->detalhesFixture(1, 'adm'), adgroups: 1),
            $this->payloadOk($this->detalhesFixture(2, 'ml'), adgroups: 2),
        );

        // Instancia ShadowRunService EXPLICITAMENTE com mlAds=null —
        // simula cenario em que DI nao resolveu MercadoLivreAdsService.
        // mockAnalyzer ja bindou SugadorAnalysisService no container.
        /** @var SugadorAnalysisService $analyzerMock */
        $analyzerMock = $this->app->make(SugadorAnalysisService::class);
        $service = new ShadowRunService($analyzerMock, null);

        $result = $service->run($company, $this->hoje);

        $mlRun = SugadorProviderRun::where('provider', 'ml')->first();

        // Run completa OK.
        $this->assertEquals('completed', $mlRun->status);
        $this->assertEquals('completed', $result['ml_status']);

        // summary ml NAO tem ml_metrics (defensa contra DI nao resolvida).
        $this->assertArrayNotHasKey('ml_metrics', $mlRun->summary);

        // Baseline Plan 40-02 preservado.
        foreach (['campanhas', 'adgroups', 'items', 'skipped', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $mlRun->summary);
        }
    }
}
