<?php

// Phase 42 Plan 42-03 — Suite Feature de aceite (contrato + janela + quarentena).
//
// Cobre:
//  - Provider ML retorna contrato §3 do briefing 100% completo, incluindo
//    campaign_name e campaign_status resolvidos via merge interno com listCampaigns
//    (REQ-42-01).
//  - Janela 30d fechados D-03 vigente: periodoFim=referenceDate-1d,
//    periodoInicio=ontem-29d. Validado via espionagem do provider (REQ-42-03).
//  - Quarentena SGI dispara para adgroups cuja campanha tem nome
//    SGI/Sugador/Sugadores OU status paused/closed/ended (REQ-42-05).
//  - Fail-open documentado: listCampaigns quebrado deixa campaign_name=null
//    sem trancar a analise (T-42-03-02).
//
// Padrao PHPUnit 11 — atributo #[Test], sem doc-comment legacy.
// Http::preventStrayRequests bloqueia qualquer rede que escape do fake.

namespace Tests\Feature\Phase42;

use App\Contracts\SugadoresAdsProvider;
use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Services\SugadorAnalysisService;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyzeCompanyMlWindowQuarantineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Nenhuma chamada HTTP deve escapar dos mocks/fakes desta suite.
        Http::preventStrayRequests();
        Cache::flush();
        // Determinismo temporal — todos os tests assumem hoje = 2026-06-25 12:00.
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────── Helpers ───────────────────────

    /**
     * Cria Company + MlToken active (provider ML resolve sem chamar API).
     * Tambem cria MlAdvertiser cacheado pra evitar a chamada de discoverAdvertiser
     * (que nao eh foco desta suite — testes Phase 41 ja cobrem).
     */
    private function makeCompanyWithMlToken(string $tokenStatus = 'active'): Company
    {
        $company = Company::create([
            'name'        => 'ByMobille - Phase 42-03',
            'cnpj'        => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'active'      => true,
            'ml_store_id' => '465723451',
        ]);

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => $tokenStatus,
            'connected_at'      => now(),
        ]);

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => '71098',
            'seller_id'     => '465723451',
            'site_id'       => 'MLB',
            'raw_data'      => [],
            'discovered_at' => now(), // cache hit -> discoverAdvertiser nao bate em API
        ]);

        SugadorConfig::create([
            'company_id'             => $company->id,
            'dias_analise'           => 30,
            'gasto_minimo_sem_venda' => 20.00,
            'gasto_minimo_logic'     => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'      => false,
            'incluir_anuncios'       => true,
            'ativo'                  => true,
        ]);

        return $company->fresh();
    }

    /**
     * Monta payload Http::fake para listCampaigns + listAds (product_ads/items).
     */
    private function httpFakeMlEndpoints(array $campaignsResults, array $adsResults): void
    {
        Http::fake([
            // discoverAdvertiser — fallback caso MlAdvertiser cache nao bata (nao deve usar).
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
            '*/product_ads/campaigns/search*' => Http::response([
                'results' => $campaignsResults,
                'paging'  => ['total' => count($campaignsResults)],
            ], 200),
            '*/product_ads/items*' => Http::response([
                'results' => $adsResults,
                'paging'  => ['total' => count($adsResults)],
            ], 200),
        ]);
    }

    // ─────────────────────── T1: fetchCampaigns normalizado ───────────────────────

    #[Test]
    public function fetchCampaigns_retorna_normalizado_com_3_chaves(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->httpFakeMlEndpoints(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active'],
                ['id' => 'C2', 'name' => 'Campanha B', 'status' => 'paused'],
            ],
            adsResults: [],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchCampaigns($company);

        $this->assertCount(2, $result);
        foreach ($result as $row) {
            $this->assertArrayHasKey('campaign_id', $row);
            $this->assertArrayHasKey('campaign_name', $row);
            $this->assertArrayHasKey('campaign_status', $row);
        }
        $this->assertSame('C1', $result[0]['campaign_id']);
        $this->assertSame('Campanha A', $result[0]['campaign_name']);
        $this->assertSame('active', $result[0]['campaign_status']);
        $this->assertSame('paused', $result[1]['campaign_status']);
    }

    // ───────── T2: fetchAdgroupsMetrics retorna contrato §3 completo (22 chaves) ─────────

    #[Test]
    public function fetchAdgroupsMetrics_retorna_contrato_completo_22_chaves(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->httpFakeMlEndpoints(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active'],
            ],
            adsResults: [[
                'id'              => 'AD1',
                'title'           => 'Tenis preto 42',
                'campaign_id'     => 'C1',
                'thumbnail'       => 'https://http2.mlstatic.com/teste.jpg',
                'type'            => 'PRODUCT',
                'catalog_listing' => false,
                'item_id'         => 'MLB12345',
                'metrics'         => [
                    'cost'           => 150.5,
                    'clicks'         => 50,
                    'prints'         => 2000,
                    'total_amount'   => 300.0,
                    'units_quantity' => 3,
                    'cpc'            => 3.01,
                    'ctr'            => 2.5,
                    'acos'           => 50.16,
                    'roas'           => 1.99,
                ],
            ]],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-05-27'),
            Carbon::parse('2026-06-24'),
        );

        $this->assertCount(1, $result);
        $row = $result[0];

        // Todas as 22 chaves do contrato §3 do briefing presentes.
        $expectedKeys = [
            'adgroup_id', 'adgroup_name',
            'campaign_id', 'campaign_name', 'campaign_status',
            'thumbnail', 'adgroup_type', 'catalog_listing',
            'mlb_id', 'mlb_titulo',
            'investment', 'revenue', 'sold_quantity',
            'clicks', 'impressions', 'cpc', 'ctr', 'acos', 'roas',
            'organic_amount', 'organic_units',
            'raw',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $row, "Chave obrigatoria '{$key}' do contrato §3 ausente");
        }

        // Validacoes pontuais (mapeamento ML -> contrato).
        $this->assertSame('AD1', $row['adgroup_id']);
        $this->assertSame('C1', $row['campaign_id']);
        $this->assertSame('Campanha A', $row['campaign_name']);
        $this->assertSame('active', $row['campaign_status']);
        $this->assertSame(150.5, $row['investment']);
        $this->assertSame(300.0, $row['revenue']);
        $this->assertSame(3, $row['sold_quantity']);
        $this->assertNull($row['organic_amount']);
        $this->assertNull($row['organic_units']);
        $this->assertIsArray($row['raw']);
    }

    // ─────── T3: fetchAdgroupsMetrics resolve campaign_name via merge interno ───────

    #[Test]
    public function fetchAdgroupsMetrics_resolve_campaign_name_via_merge(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->httpFakeMlEndpoints(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup X',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                'metrics'     => ['cost' => 50, 'units_quantity' => 0, 'clicks' => 5, 'prints' => 100],
            ]],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-05-27'),
            Carbon::parse('2026-06-24'),
        );

        $this->assertCount(1, $result);
        $this->assertSame('Campanha A', $result[0]['campaign_name']);
        $this->assertSame('active', $result[0]['campaign_status']);
    }

    // ─────── T4: fail-open quando listCampaigns quebra ───────

    #[Test]
    public function fetchAdgroupsMetrics_fail_open_em_listCampaigns_quebrado(): void
    {
        $company = $this->makeCompanyWithMlToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
            // listCampaigns sempre 500 → MercadoLivreAdsService::callWithBackoff
            // esgota MAX_ATTEMPTS=5 e lanca RuntimeException; provider tem try/catch
            // \Throwable que captura e segue com $campaignNames=[].
            '*/product_ads/campaigns/search*' => Http::response(['error' => 'srv'], 500),
            // listAds funciona normalmente
            '*/product_ads/items*' => Http::response([
                'results' => [[
                    'id'          => 'AD1',
                    'title'       => 'Adgroup orfao',
                    'campaign_id' => 'C1',
                    'item_id'     => 'MLB1',
                    'metrics'     => ['cost' => 50, 'units_quantity' => 0, 'clicks' => 5, 'prints' => 100],
                ]],
                'paging' => ['total' => 1],
            ], 200),
        ]);

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-05-27'),
            Carbon::parse('2026-06-24'),
        );

        // Adgroup ainda flui (fail-open documentado) — apenas campaign_name=null.
        $this->assertCount(1, $result);
        $this->assertSame('AD1', $result[0]['adgroup_id']);
        $this->assertNull($result[0]['campaign_name']);
        $this->assertNull($result[0]['campaign_status']);
    }

    // ─────── T5: janela 30d fechada quando reference_date=25/06/2026 ───────

    #[Test]
    public function janela_30d_fechada_quando_reference_date_25_06_2026(): void
    {
        $company = $this->makeCompanyWithMlToken();

        // Espionar provider para capturar from/to passados pelo service.
        /** @var Mockery\MockInterface&SugadoresAdsProvider $providerSpy */
        $providerSpy = Mockery::mock(SugadoresAdsProvider::class);
        $providerSpy->shouldReceive('name')->andReturn('ml')->byDefault();
        $providerSpy->shouldReceive('supports')->andReturn(true)->byDefault();
        $providerSpy->shouldReceive('fetchCampaigns')->andReturn([])->byDefault();
        $providerSpy->shouldReceive('fetchAdgroupsMetrics')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::on(fn($from) => $from instanceof Carbon && $from->toDateString() === '2026-05-27'),
                Mockery::on(fn($to)   => $to   instanceof Carbon && $to->toDateString()   === '2026-06-24'),
            )
            ->andReturn([]);

        // Substitui o MercadoLivreSugadoresProvider concreto no container.
        $this->app->instance(MercadoLivreSugadoresProvider::class, $providerSpy);

        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);

        $service->analyzeCompany(
            $company,
            Carbon::create(2026, 6, 25)->startOfDay(),
            dryRun: true,
            forceProvider: 'ml',
        );

        // Mockery::close() no tearDown valida ->once() com as datas exatas.
        $this->assertTrue(true, 'janela passada ao provider: 2026-05-27 → 2026-06-24');
    }

    // ─────── T6: quarentena pula adgroup em campanha SGI (nome) ───────

    #[Test]
    public function quarentena_pula_adgroup_em_campanha_SGI(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->httpFakeMlEndpoints(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'SGI - Lentes', 'status' => 'active'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup que bateria criterio',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                // Bateria gasto_sem_venda (cost=100 > 20, units=0).
                'metrics'     => ['cost' => 100, 'units_quantity' => 0, 'clicks' => 10, 'prints' => 500],
            ]],
        );

        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany(
            $company,
            Carbon::create(2026, 6, 25)->startOfDay(),
            dryRun: false,
            forceProvider: 'ml',
        );

        // Adgroup foi pulado pelo shouldSkipCampaign — nenhum sugador criado.
        $this->assertSame(0, Sugador::count(), 'Adgroup em campanha SGI nao deveria virar sugador');
    }

    // ─────── T7: quarentena pula adgroup em campanha paused ───────

    #[Test]
    public function quarentena_pula_adgroup_em_campanha_paused(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->httpFakeMlEndpoints(
            campaignsResults: [
                ['id' => 'C1', 'name' => 'Campanha Normal', 'status' => 'paused'],
            ],
            adsResults: [[
                'id'          => 'AD1',
                'title'       => 'Adgroup que bateria criterio',
                'campaign_id' => 'C1',
                'item_id'     => 'MLB1',
                'metrics'     => ['cost' => 100, 'units_quantity' => 0, 'clicks' => 10, 'prints' => 500],
            ]],
        );

        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $service->analyzeCompany(
            $company,
            Carbon::create(2026, 6, 25)->startOfDay(),
            dryRun: false,
            forceProvider: 'ml',
        );

        $this->assertSame(0, Sugador::count(), 'Adgroup em campanha paused nao deveria virar sugador');
    }
}
