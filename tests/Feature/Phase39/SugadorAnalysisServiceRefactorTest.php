<?php

// Phase 39 Plan 39-04 — Testes de regressão do refactor SugadorAnalysisService.
//
// Cobre exclusivamente a TROCA DE DEPENDÊNCIA (AdmanService direto → SugadoresAdsProviderFactory)
// e a paridade comportamental quando o provider é Adman. A lógica de detecção em si
// (evaluateMetrics, STATUS_TRAVADOS, auto-resolve, quarentena) continua coberta pelas
// suites legadas (Tests\Feature\Sugadores\*, Tests\Feature\Phase19\*) — esta suite NÃO
// duplica esses testes; só garante que o REFACTOR DA DI FOI FEITO CORRETAMENTE e que
// nenhuma chamada direta ao AdmanService permanece dentro de analyzeCompany.
//
// Critério de aceitação Plan 39-04:
//  - tests/Feature/Sugador (49 verdes) continua verde pós-refactor — gate de zero regressão
//  - estes 8 testes provam que o refactor foi feito (constructor, param novo, provider chamado)

namespace Tests\Feature\Phase39;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;
use App\Models\SugadorConfig;
use App\Services\AdmanService;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\AdmanSugadoresProvider;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use App\Services\Sugadores\SugadoresAdsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SugadorAnalysisServiceRefactorTest extends TestCase
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
     * Cria empresa persistida com config padrão (incluir_anuncios=true,
     * incluir_campanhas=true para forçar as duas chamadas do provider).
     */
    private function makeCompanyWithConfig(array $companyAttrs = [], array $configAttrs = []): Company
    {
        $defaults = [
            'name'             => 'Empresa Refactor 39-04',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-RF-39-04',
            'active'           => true,
        ];
        $company = Company::create(array_merge($defaults, $companyAttrs));

        SugadorConfig::create(array_merge([
            'company_id'                      => $company->id,
            'dias_analise'                    => 30,
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
     * Cria mock do provider e bindando o factory no container para que o
     * SugadorAnalysisService o receba via DI.
     *
     * @return MockInterface&SugadoresAdsProvider
     */
    private function bindMockedProviderInFactory(): MockInterface
    {
        /** @var MockInterface&SugadoresAdsProvider $providerMock */
        $providerMock = Mockery::mock(SugadoresAdsProvider::class);
        $providerMock->shouldReceive('name')->andReturn('adman')->byDefault();

        /** @var MockInterface&SugadoresAdsProviderFactory $factoryMock */
        $factoryMock = Mockery::mock(SugadoresAdsProviderFactory::class);
        $factoryMock->shouldReceive('for')->andReturn($providerMock)->byDefault();

        $this->app->instance(SugadoresAdsProviderFactory::class, $factoryMock);

        return $providerMock;
    }

    // ─────────── Test 1: constructor recebe SugadoresAdsProviderFactory ───────────

    public function test_constructor_receives_provider_factory_instead_of_adman_service_only(): void
    {
        $refl   = new ReflectionClass(SugadorAnalysisService::class);
        $ctor   = $refl->getConstructor();
        $this->assertNotNull($ctor, 'SugadorAnalysisService deve declarar constructor');

        $params = $ctor->getParameters();
        $types  = array_map(
            fn($p) => $p->getType() ? $p->getType()->getName() : null,
            $params
        );

        $this->assertContains(
            SugadoresAdsProviderFactory::class,
            $types,
            'Constructor de SugadorAnalysisService deve receber SugadoresAdsProviderFactory via DI (Plan 39-04)'
        );
    }

    // ─────────── Test 2: analyzeCompany aceita parâmetro opcional ?string $forceProvider ───────────

    public function test_analyzeCompany_accepts_optional_force_provider_parameter(): void
    {
        $refl   = new ReflectionClass(SugadorAnalysisService::class);
        $method = $refl->getMethod('analyzeCompany');

        $params      = $method->getParameters();
        $paramNames  = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains(
            'forceProvider',
            $paramNames,
            'analyzeCompany deve aceitar parâmetro opcional ?string $forceProvider (Plan 39-04)'
        );

        // Localiza o parâmetro e valida que é nullable + tem default null.
        $forceParam = null;
        foreach ($params as $p) {
            if ($p->getName() === 'forceProvider') { $forceParam = $p; break; }
        }
        $this->assertNotNull($forceParam);
        $this->assertTrue($forceParam->allowsNull(), '$forceProvider deve ser nullable');
        $this->assertTrue($forceParam->isOptional(), '$forceProvider deve ser opcional');
        $this->assertNull($forceParam->getDefaultValue(), 'Default de $forceProvider deve ser null');
    }

    // ─────────── Test 3: factory recebe forceProvider quando passado ───────────

    public function test_analyzeCompany_passes_force_provider_to_factory(): void
    {
        $company = $this->makeCompanyWithConfig();

        $providerMock = $this->bindMockedProviderInFactory();
        $providerMock->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchAdgroupsMetrics')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchCampaignsMetrics')->andReturn([])->byDefault();

        // Reinjeta o factory mock com expectativa estrita do forceName
        /** @var MockInterface&SugadoresAdsProviderFactory $factoryMock */
        $factoryMock = Mockery::mock(SugadoresAdsProviderFactory::class);
        $factoryMock->shouldReceive('for')
            ->once()
            ->with(Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id), 'adman')
            ->andReturn($providerMock);
        $this->app->instance(SugadoresAdsProviderFactory::class, $factoryMock);

        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany($company, $this->hoje, true, 'adman');
    }

    // ─────────── Test 4: factory recebe null quando forceProvider omitido ───────────

    public function test_analyzeCompany_passes_null_to_factory_when_force_provider_omitted(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadoresAdsProvider $providerMock */
        $providerMock = Mockery::mock(SugadoresAdsProvider::class);
        $providerMock->shouldReceive('name')->andReturn('adman')->byDefault();
        $providerMock->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchAdgroupsMetrics')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchCampaignsMetrics')->andReturn([])->byDefault();

        /** @var MockInterface&SugadoresAdsProviderFactory $factoryMock */
        $factoryMock = Mockery::mock(SugadoresAdsProviderFactory::class);
        $factoryMock->shouldReceive('for')
            ->once()
            ->with(Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id), null)
            ->andReturn($providerMock);
        $this->app->instance(SugadoresAdsProviderFactory::class, $factoryMock);

        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany($company, $this->hoje, true);
    }

    // ─────────── Test 5: analyzeCompany chama provider->fetchAdgroupsMetrics quando incluir_anuncios=true ───────────

    public function test_analyzeCompany_calls_provider_fetchAdgroupsMetrics_when_config_incluir_anuncios(): void
    {
        $company = $this->makeCompanyWithConfig([], ['incluir_anuncios' => true, 'incluir_campanhas' => false]);

        $providerMock = $this->bindMockedProviderInFactory();
        $providerMock->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchAdgroupsMetrics')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::type(Carbon::class),
                Mockery::type(Carbon::class)
            )
            ->andReturn([]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($company, $this->hoje, true);

        $this->assertFalse($result['skipped']);
    }

    // ─────────── Test 6: analyzeCompany chama provider->fetchCampaignsMetrics quando incluir_campanhas=true ───────────

    public function test_analyzeCompany_calls_provider_fetchCampaignsMetrics_when_config_incluir_campanhas(): void
    {
        $company = $this->makeCompanyWithConfig([], ['incluir_anuncios' => false, 'incluir_campanhas' => true]);

        $providerMock = $this->bindMockedProviderInFactory();
        $providerMock->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchCampaignsMetrics')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::type(Carbon::class),
                Mockery::type(Carbon::class)
            )
            ->andReturn([]);

        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany($company, $this->hoje, true);
    }

    // ─────────── Test 7: analyzeCompany NÃO chama AdmanService::fetchAdsMetrics direto ───────────

    public function test_analyzeCompany_does_not_call_adman_service_fetchAdsMetrics_directly(): void
    {
        $company = $this->makeCompanyWithConfig();

        // Mock spy do AdmanService: garante que NENHUM método HTTP-bound seja invocado
        // direto pelo SugadorAnalysisService — toda chamada deve passar pelo provider.
        /** @var MockInterface&AdmanService $admanSpy */
        $admanSpy = Mockery::mock(AdmanService::class);
        $admanSpy->shouldNotReceive('fetchAdsMetrics');
        $admanSpy->shouldNotReceive('fetchCampaignsRange');
        // fetchAllCampaigns PODE ser tolerado em loadCampaignsInfo legacy (preservada
        // para CleanupSugadoresQuarentena), mas dentro de analyzeCompany não deve
        // mais ser chamado direto — agora vem via provider->fetchCampaigns.
        $admanSpy->shouldNotReceive('fetchAllCampaigns');
        $this->app->instance(AdmanService::class, $admanSpy);

        // Provider mock que satisfaz analyzeCompany
        $providerMock = $this->bindMockedProviderInFactory();
        $providerMock->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchAdgroupsMetrics')->andReturn([])->byDefault();
        $providerMock->shouldReceive('fetchCampaignsMetrics')->andReturn([])->byDefault();

        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany($company, $this->hoje, true);

        // Mockery::close() no tearDown valida shouldNotReceive automaticamente.
        $this->assertTrue(true, 'AdmanService NÃO foi chamado direto dentro de analyzeCompany');
    }

    // ─────────── Test 8: paridade — provider Adman real produz mesmos detalhes ───────────

    public function test_analyzeCompany_with_adman_provider_produces_expected_detalhes(): void
    {
        // Cenário: provider retorna 1 adgroup com gasto sem venda → vira sugador.
        // Valida paridade fim-a-fim usando o AdmanSugadoresProvider real composto
        // sobre AdmanService mockado. Garante que a cadeia DI factory → provider →
        // AdmanService funciona como antes.

        $company = $this->makeCompanyWithConfig([], ['incluir_anuncios' => true, 'incluir_campanhas' => false]);

        $admanMock = Mockery::mock(AdmanService::class);
        $admanMock->shouldReceive('fetchAllCampaigns')->andReturn([]);
        $admanMock->shouldReceive('fetchAdsMetrics')->andReturn([
            [
                'adgroup_id'      => 'AG-PAR-1',
                'adgroup_name'    => 'Adgroup paridade',
                'campaign_id'     => 'CP-PAR-1',
                'thumbnail'       => null,
                'adgroup_type'    => null,
                'catalog_listing' => false,
                'investment'      => 100.00, // > gasto_minimo_sem_venda 20.00
                'revenue'         => 0,
                'sold_quantity'   => 0,
                'clicks'          => 10,
                'impressions'     => 100,
                'cpc'             => 10.0,
                'ctr'             => 10.0,
                'acos'            => null,
                'roas'            => null,
                'organic_amount'  => null,
                'organic_units'   => null,
                'raw'             => ['raw' => true],
            ],
        ]);
        $admanMock->shouldReceive('fetchCampaignsRange')->andReturn([]);
        $this->app->instance(AdmanService::class, $admanMock);

        $service = $this->app->make(SugadorAnalysisService::class);
        $result  = $service->analyzeCompany($company, $this->hoje, true, 'adman');

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['adgroups'], 'Deve detectar 1 sugador adgroup (gasto sem venda)');
        $this->assertCount(1, $result['detalhes']);
        $this->assertSame('adgroup', $result['detalhes'][0]['tipo']);
        $this->assertSame('AG-PAR-1', $result['detalhes'][0]['adgroup_id']);
        $this->assertContains('gasto_sem_venda', $result['detalhes'][0]['motivos']);
    }
}
