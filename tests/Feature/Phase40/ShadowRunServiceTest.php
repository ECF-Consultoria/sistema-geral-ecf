<?php

// Phase 40 Plan 40-02 — Suite RED → GREEN do ShadowRunService.
//
// O ShadowRunService orquestra, para uma (empresa, data), 2 chamadas paralelas a
// SugadorAnalysisService::analyzeCompany — uma forçando provider 'adman', outra 'ml',
// AMBAS com $dryRun=true. Cada chamada vira 1 row em `sugador_provider_runs` + N rows
// em `sugador_provider_items` (uma por motivo detectado).
//
// Esta suite valida (9 testes):
//  1. run() cria 2 rows em sugador_provider_runs (provider=adman e provider=ml)
//  2. run() persiste N items por motivo detectado (linkados via run_id correto)
//  3. GATE CRÍTICO REQ-40-02: ZERO gravação em `sugadores` — assertDatabaseCount
//     antes==depois==0 mesmo com detalhes retornados pelo mock do analyzer
//  4. status final = 'completed' quando provider executa OK; summary populado
//  5. Falha em provider 'adman' (RuntimeException) NÃO interrompe execução do 'ml'
//  6. Falha em provider 'ml' (RuntimeException) NÃO interrompe execução do 'adman'
//  7. raw_hash determinístico — mesmos motivos+metrics geram mesmo hash SHA256
//  8. Retorno do método tem keys ['adman_run_id', 'ml_run_id', 'adman_status', 'ml_status']
//  9. analyzeCompany é chamado SEMPRE com $dryRun=true (4º argumento) — gate de segurança
//
// Mockery setup: mocka SugadorAnalysisService inteiro e binda no container (pattern
// já validado em Phase 39 Plan 39-04). ShadowRunService consome via DI.

namespace Tests\Feature\Phase40;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Models\SugadorProviderItem;
use App\Models\SugadorProviderRun;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\ShadowRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShadowRunServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::create(2026, 6, 25)->startOfDay();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria empresa válida + config padrão (dias_analise=7 pra cálculo do período).
     */
    private function makeCompanyWithConfig(array $companyAttrs = [], array $configAttrs = []): Company
    {
        $company = Company::create(array_merge([
            'name'             => 'Empresa Shadow 40-02',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-SHADOW-' . random_int(1000, 9999),
            'active'           => true,
        ], $companyAttrs));

        SugadorConfig::create(array_merge([
            'company_id'                      => $company->id,
            'dias_analise'                    => 7,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => true,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ], $configAttrs));

        return $company;
    }

    /**
     * Gera fixture sintético de "detalhes" no shape que SugadorAnalysisService
     * realmente retorna (ver linhas 220-228 e 300-307 de SugadorAnalysisService.php).
     *
     * Chaves: tipo, campaign_id, [adgroup_id], nome, investimento, vendas, motivos.
     * mlb_id é opcional — adicionado em alguns testes via $extra.
     */
    private function detalhesFixture(int $n, string $prefix, array $motivosBase = ['cpc_alto']): array
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
                'motivos'      => $motivosBase,
            ];
        }
        return $detalhes;
    }

    /**
     * Configura mock do SugadorAnalysisService para retornar payloads distintos por provider.
     * - 4º arg ($dryRun) deve ser SEMPRE true (validado no Test 9)
     * - 5º arg ($forceProvider) seleciona o payload
     *
     * @return MockInterface&SugadorAnalysisService
     */
    private function mockAnalyzer(array $payloadAdman, array $payloadMl): MockInterface
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

        return $mock;
    }

    private function payloadOk(array $detalhes, int $campanhas = 0, int $adgroups = 0): array
    {
        return [
            'skipped'   => false,
            'reason'    => null,
            'campanhas' => $campanhas,
            'adgroups'  => $adgroups ?: count($detalhes),
            'detalhes'  => $detalhes,
        ];
    }

    // ─────────── Test 1: run() cria 2 rows em sugador_provider_runs ───────────

    #[Test]
    public function run_cria_duas_rows_em_sugador_provider_runs(): void
    {
        $company = $this->makeCompanyWithConfig();
        $this->mockAnalyzer($this->payloadOk([]), $this->payloadOk([]));

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $this->assertDatabaseCount('sugador_provider_runs', 2);
        $this->assertDatabaseHas('sugador_provider_runs', [
            'company_id' => $company->id,
            'provider'   => 'adman',
        ]);
        $this->assertDatabaseHas('sugador_provider_runs', [
            'company_id' => $company->id,
            'provider'   => 'ml',
        ]);
    }

    // ─────────── Test 2: run() persiste items por motivo detectado ───────────

    #[Test]
    public function run_persiste_items_por_motivo_detectado(): void
    {
        $company = $this->makeCompanyWithConfig();

        $detalhesAdman = $this->detalhesFixture(2, 'adm');
        $detalhesMl    = $this->detalhesFixture(3, 'ml');

        $this->mockAnalyzer(
            $this->payloadOk($detalhesAdman, adgroups: 2),
            $this->payloadOk($detalhesMl, adgroups: 3),
        );

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $this->assertDatabaseCount('sugador_provider_items', 5);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();
        $mlRun    = SugadorProviderRun::where('provider', 'ml')->first();

        $this->assertEquals(2, SugadorProviderItem::where('run_id', $admanRun->id)->count());
        $this->assertEquals(3, SugadorProviderItem::where('run_id', $mlRun->id)->count());

        // Spot-check de um item Adman: campos vindos do detalhe foram persistidos
        $item = SugadorProviderItem::where('run_id', $admanRun->id)
            ->where('campaign_id', 'adm-cmp-1')
            ->first();
        $this->assertNotNull($item);
        $this->assertEquals('adgroup', $item->tipo);
        $this->assertEquals('adm-adg-1', $item->adgroup_id);
        $this->assertEquals('MLBadm1', $item->mlb_id);
        $this->assertEquals(['cpc_alto'], $item->motivos);
    }

    // ─────────── Test 3: GATE CRÍTICO — NUNCA grava em `sugadores` ───────────

    #[Test]
    public function run_NAO_grava_em_sugadores_GATE_CRITICO(): void
    {
        $company = $this->makeCompanyWithConfig();

        // Gate inicial: tabela sugadores começa vazia.
        $this->assertDatabaseCount('sugadores', 0);
        $initial = Sugador::count();

        // Mock retorna 10 itens em cada provider (20 detalhes totais).
        // Se o service por engano chamasse analyzeCompany com $dryRun=false ou
        // se ele mesmo gravasse direto, sugadores teria N rows. Esperamos ZERO.
        $detalhesAdman = $this->detalhesFixture(10, 'adm');
        $detalhesMl    = $this->detalhesFixture(10, 'ml');

        $this->mockAnalyzer(
            $this->payloadOk($detalhesAdman, adgroups: 10),
            $this->payloadOk($detalhesMl, adgroups: 10),
        );

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        // Gate final — a tabela canônica `sugadores` continua intocada.
        $this->assertEquals(
            $initial,
            Sugador::count(),
            'GATE REQ-40-02 violado: ShadowRunService gravou na tabela canônica `sugadores`.'
        );
        $this->assertDatabaseCount('sugadores', 0);

        // Sanidade: os 20 detalhes foram pra tabela auxiliar como esperado.
        $this->assertDatabaseCount('sugador_provider_items', 20);
    }

    // ─────────── Test 4: status='completed' + summary populado ───────────

    #[Test]
    public function run_marca_status_completed_quando_provider_executa_ok(): void
    {
        $company = $this->makeCompanyWithConfig();

        $detalhesAdman = $this->detalhesFixture(1, 'adm');
        $detalhesMl    = $this->detalhesFixture(2, 'ml');

        $this->mockAnalyzer(
            $this->payloadOk($detalhesAdman, campanhas: 0, adgroups: 1),
            $this->payloadOk($detalhesMl, campanhas: 0, adgroups: 2),
        );

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();
        $mlRun    = SugadorProviderRun::where('provider', 'ml')->first();

        $this->assertEquals('completed', $admanRun->status);
        $this->assertEquals('completed', $mlRun->status);
        $this->assertNotNull($admanRun->finished_at);
        $this->assertNotNull($mlRun->finished_at);

        $this->assertIsArray($admanRun->summary);
        $this->assertArrayHasKey('campanhas', $admanRun->summary);
        $this->assertArrayHasKey('adgroups', $admanRun->summary);
        $this->assertEquals(1, $admanRun->summary['adgroups']);
        $this->assertEquals(2, $mlRun->summary['adgroups']);
    }

    // ─────────── Test 5: falha em adman não interrompe ml ───────────

    #[Test]
    public function run_falha_adman_nao_interrompe_ml(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $mock */
        $mock = Mockery::mock(SugadorAnalysisService::class);
        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'adman')
            ->andThrow(new \RuntimeException('adman down'));
        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'ml')
            ->andReturn($this->payloadOk($this->detalhesFixture(1, 'ml'), adgroups: 1));
        $this->app->instance(SugadorAnalysisService::class, $mock);

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $result = $service->run($company, $this->hoje);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();
        $mlRun    = SugadorProviderRun::where('provider', 'ml')->first();

        $this->assertEquals('failed', $admanRun->status);
        $this->assertStringContainsString('adman down', (string) $admanRun->error);
        $this->assertNotNull($admanRun->finished_at);

        $this->assertEquals('completed', $mlRun->status);
        $this->assertNull($mlRun->error);

        $this->assertEquals('failed', $result['adman_status']);
        $this->assertEquals('completed', $result['ml_status']);
    }

    // ─────────── Test 6: falha em ml não interrompe adman ───────────

    #[Test]
    public function run_falha_ml_nao_interrompe_adman(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $mock */
        $mock = Mockery::mock(SugadorAnalysisService::class);
        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'adman')
            ->andReturn($this->payloadOk($this->detalhesFixture(1, 'adm'), adgroups: 1));
        $mock->shouldReceive('analyzeCompany')
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'ml')
            ->andThrow(new \RuntimeException('ml api 503'));
        $this->app->instance(SugadorAnalysisService::class, $mock);

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $result = $service->run($company, $this->hoje);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();
        $mlRun    = SugadorProviderRun::where('provider', 'ml')->first();

        $this->assertEquals('completed', $admanRun->status);
        $this->assertNull($admanRun->error);

        $this->assertEquals('failed', $mlRun->status);
        $this->assertStringContainsString('ml api 503', (string) $mlRun->error);

        $this->assertEquals('completed', $result['adman_status']);
        $this->assertEquals('failed', $result['ml_status']);
    }

    // ─────────── Test 7: raw_hash determinístico ───────────

    #[Test]
    public function run_calcula_raw_hash_deterministico(): void
    {
        $company = $this->makeCompanyWithConfig();

        // 2 detalhes IDÊNTICOS no payload Adman — devem produzir o mesmo raw_hash.
        // (NÃO há unique constraint em raw_hash; duplicação é permitida e esperada
        // quando ambos providers detectam o mesmo sugador.)
        $detEquivalente = [
            'tipo'         => 'adgroup',
            'campaign_id'  => 'CMP-X',
            'adgroup_id'   => 'AG-Y',
            'mlb_id'       => 'MLBZ',
            'nome'         => 'Item teste',
            'investimento' => 42.5,
            'vendas'       => 0,
            'motivos'      => ['cpc_alto', 'sem_conversao'],
        ];

        $this->mockAnalyzer(
            $this->payloadOk([$detEquivalente, $detEquivalente], adgroups: 2),
            $this->payloadOk([], adgroups: 0),
        );

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        $admanRun = SugadorProviderRun::where('provider', 'adman')->first();
        $items    = SugadorProviderItem::where('run_id', $admanRun->id)->get();

        $this->assertCount(2, $items);
        $hash1 = $items[0]->raw_hash;
        $hash2 = $items[1]->raw_hash;

        $this->assertEquals($hash1, $hash2, 'raw_hash deve ser determinístico para itens idênticos.');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash1, 'raw_hash deve ser SHA256 hex (64 chars).');

        // Ordem dos motivos NÃO deve afetar o hash — sort interno garante determinismo
        $detComMotivosInvertidos = $detEquivalente;
        $detComMotivosInvertidos['motivos'] = ['sem_conversao', 'cpc_alto'];

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('computeRawHash');
        $method->setAccessible(true);

        $hashSorted   = $method->invoke($service, ['cpc_alto', 'sem_conversao'], ['investimento' => 42.5]);
        $hashReversed = $method->invoke($service, ['sem_conversao', 'cpc_alto'], ['investimento' => 42.5]);

        $this->assertEquals($hashSorted, $hashReversed, 'Ordem dos motivos não deve afetar raw_hash.');
    }

    // ─────────── Test 8: retorno tem keys esperadas ───────────

    #[Test]
    public function run_retorna_array_com_run_ids_e_status(): void
    {
        $company = $this->makeCompanyWithConfig();
        $this->mockAnalyzer($this->payloadOk([]), $this->payloadOk([]));

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $result = $service->run($company, $this->hoje);

        $this->assertIsArray($result);
        $this->assertEqualsCanonicalizing(
            ['adman_run_id', 'ml_run_id', 'adman_status', 'ml_status'],
            array_keys($result),
            'Retorno deve ter exatamente 4 keys.'
        );
        $this->assertIsInt($result['adman_run_id']);
        $this->assertIsInt($result['ml_run_id']);
        $this->assertGreaterThan(0, $result['adman_run_id']);
        $this->assertGreaterThan(0, $result['ml_run_id']);
        $this->assertEquals('completed', $result['adman_status']);
        $this->assertEquals('completed', $result['ml_status']);
    }

    // ─────────── Test 9: dryRun=true sempre ───────────

    #[Test]
    public function run_usa_dryRun_true_em_ambas_chamadas_analyzeCompany(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $mock */
        $mock = Mockery::mock(SugadorAnalysisService::class);

        // Expectativas EXATAS: dryRun=true (4º arg) em ambas as chamadas.
        // Se o service por engano passar false, Mockery vai falhar com "no matching expectation".
        $mock->shouldReceive('analyzeCompany')
            ->once()
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'adman')
            ->andReturn($this->payloadOk([]));

        $mock->shouldReceive('analyzeCompany')
            ->once()
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class), true, 'ml')
            ->andReturn($this->payloadOk([]));

        $this->app->instance(SugadorAnalysisService::class, $mock);

        /** @var ShadowRunService $service */
        $service = $this->app->make(ShadowRunService::class);
        $service->run($company, $this->hoje);

        // Mockery::close() no tearDown valida as expectations (->once() + ->with(...true...))
        $this->assertTrue(true, 'Expectations Mockery validadas no tearDown.');
    }
}
