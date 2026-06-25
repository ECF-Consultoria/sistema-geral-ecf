<?php

// Phase 39 Plan 39-02 — Testes Unit do MercadoLivreSugadoresProvider.
// Cobre a normalização payload ML (Mercado Ads) → contrato §2.3 do plano de migração.
// Usa Mockery do MercadoLivreAdsService (composição, sem modificá-lo) e Http::fake
// como rede de segurança contra qualquer chamada HTTP escapando.
//
// CANDIDATO — Phase 38 Tarefa 3 (smoke real ML) DEFERIDA por bloqueio MariaDB.
// As fixtures abaixo são ESPECULATIVAS: baseadas em §2.3 do plano canônico
// (plano-migracao-sugadores-ml-direto.md) + MercadoLivreAdsService::DEFAULT_METRICS
// (cost, clicks, prints, total_amount, units_quantity, cpc, acos, roas, ctr).
// Quando o smoke real rodar, substituir por fixture gravada em
// storage/app/sugadores/ml-smoke/{id}-{date}.json — ajustes pontuais no provider
// não devem mudar a assinatura nem o contrato §2.3.

namespace Tests\Unit\Phase39;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MercadoLivreSugadoresProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Rede de segurança: nenhuma chamada HTTP deve escapar dos mocks.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria uma Company + MlToken persistidos — necessário porque
     * MercadoLivreSugadoresProvider::supports() lê $company->mlToken->status
     * via Eloquent relation.
     */
    private function makeCompanyWithMlToken(string $status = 'active'): Company
    {
        $company = Company::create([
            'name'        => 'ByMobille - Teste',
            'cnpj'        => '99999999000099',
            'active'      => true,
            'ml_store_id' => '465723451',
        ]);

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token-xyz',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => $status,
            'connected_at'      => now(),
        ]);

        return $company->fresh();
    }

    private function makeCompanyWithoutMlToken(): Company
    {
        return Company::create([
            'name'   => 'Empresa sem ML',
            'cnpj'   => '88888888000088',
            'active' => true,
        ])->fresh();
    }

    // ─────────── Test 1: supports() true com mlToken active ───────────

    public function test_supports_returns_true_when_company_has_active_ml_token(): void
    {
        $ads      = Mockery::mock(MercadoLivreAdsService::class);
        $provider = new MercadoLivreSugadoresProvider($ads);

        $company = $this->makeCompanyWithMlToken('active');

        $this->assertTrue($provider->supports($company));
    }

    // ─────────── Test 2: supports() false sem mlToken ───────────

    public function test_supports_returns_false_when_company_has_no_ml_token(): void
    {
        $ads      = Mockery::mock(MercadoLivreAdsService::class);
        $provider = new MercadoLivreSugadoresProvider($ads);

        $company = $this->makeCompanyWithoutMlToken();

        $this->assertFalse($provider->supports($company));
    }

    // ─────────── Test 3: supports() false com mlToken inativo ───────────

    public function test_supports_returns_false_when_ml_token_inactive(): void
    {
        $ads      = Mockery::mock(MercadoLivreAdsService::class);
        $provider = new MercadoLivreSugadoresProvider($ads);

        $company = $this->makeCompanyWithMlToken('revoked');

        $this->assertFalse($provider->supports($company));
    }

    // ─────────── Test 4: name() retorna 'ml' ───────────

    public function test_name_returns_ml_string(): void
    {
        $ads      = Mockery::mock(MercadoLivreAdsService::class);
        $provider = new MercadoLivreSugadoresProvider($ads);

        $this->assertSame('ml', $provider->name());
    }

    // ─────────── Test 5: fetchAdgroupsMetrics normaliza para contrato §2.3 ───────────

    public function test_fetchAdgroupsMetrics_normalizes_ml_payload_to_contract_keys(): void
    {
        $company = $this->makeCompanyWithMlToken();
        $from    = Carbon::parse('2026-05-26');
        $to      = Carbon::parse('2026-06-25');

        $ads = Mockery::mock(MercadoLivreAdsService::class);

        // Provider deve resolver advertiser_id primeiro.
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => 71098,
                'site_id'       => 'MLB',
                'seller_id'     => '465723451',
                'raw'           => [],
                'url'           => '/advertising/advertisers?product_id=PADS',
                'status'        => 200,
            ]);

        // FIXTURE ESPECULATIVA — payload baseado em §2.3 + DEFAULT_METRICS.
        // CANDIDATO — revalidar contra payload real após smoke Phase 38 Tarefa 3.
        $ads->shouldReceive('tryFetchAdsMetrics')
            ->once()
            ->with($company, 71098, '2026-05-26', '2026-06-25')
            ->andReturn([
                'ok'    => true,
                'data'  => [
                    'count'   => 1,
                    'results' => [
                        [
                            'id'              => 'AD-ML-1',
                            'title'           => 'Tênis preto 42 - MLB',
                            'campaign_id'     => 'CP-ML-100',
                            'thumbnail'       => 'https://http2.mlstatic.com/teste.jpg',
                            'type'            => 'PRODUCT',
                            'catalog_listing' => false,
                            'item_id'         => 'MLB12345',
                            'metrics'         => [
                                'cost'           => 150.50,
                                'clicks'         => 50,
                                'prints'         => 2000,
                                'total_amount'   => 300.00,
                                'units_quantity' => 3,
                                'cpc'            => 3.01,
                                'ctr'            => 2.5,
                                'acos'           => 50.16,
                                'roas'           => 1.99,
                            ],
                        ],
                    ],
                    'raw_first_page'  => null,
                    'endpoints_tried' => [],
                ],
                'error' => null,
            ]);

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchAdgroupsMetrics($company, $from, $to);

        $this->assertCount(1, $result);
        $row = $result[0];

        // Valida que TODAS as 20 chaves do contrato §2.3 estão presentes
        $expectedKeys = [
            'adgroup_id', 'adgroup_name', 'campaign_id', 'thumbnail', 'adgroup_type',
            'catalog_listing', 'mlb_id', 'mlb_titulo', 'investment', 'revenue',
            'sold_quantity', 'clicks', 'impressions', 'cpc', 'ctr', 'acos', 'roas',
            'organic_amount', 'organic_units', 'raw',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $row, "Chave obrigatória '{$key}' do contrato §2.3 ausente");
        }

        // Mapeamento ML → contrato (CANDIDATO — revalidar pós-smoke)
        $this->assertSame('AD-ML-1', $row['adgroup_id']);
        $this->assertSame('Tênis preto 42 - MLB', $row['adgroup_name']);
        $this->assertSame('CP-ML-100', $row['campaign_id']);
        $this->assertSame('MLB12345', $row['mlb_id']);
        $this->assertSame('Tênis preto 42 - MLB', $row['mlb_titulo']);
        $this->assertSame(150.50, $row['investment']);  // cost → investment
        $this->assertSame(300.00, $row['revenue']);     // total_amount → revenue
        $this->assertSame(3, $row['sold_quantity']);    // units_quantity → sold_quantity
        $this->assertSame(50, $row['clicks']);
        $this->assertSame(2000, $row['impressions']);   // prints → impressions
        $this->assertSame(3.01, $row['cpc']);
        $this->assertSame(2.5, $row['ctr']);
        $this->assertSame(50.16, $row['acos']);
        $this->assertSame(1.99, $row['roas']);
        // Mercado Ads não retorna organic — null (deferred).
        $this->assertNull($row['organic_amount']);
        $this->assertNull($row['organic_units']);
    }

    // ─────────── Test 6: fetchAdgroupsMetrics retorna vazio sem advertiser_id ───────────

    public function test_fetchAdgroupsMetrics_returns_empty_when_advertiser_id_null(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $ads = Mockery::mock(MercadoLivreAdsService::class);
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => null,  // Conta sem Mercado Ads — estado válido.
                'site_id'       => null,
                'seller_id'     => null,
                'raw'           => ['advertisers' => []],
                'url'           => '/advertising/advertisers?product_id=PADS',
                'status'        => 200,
            ]);
        // tryFetchAdsMetrics NUNCA deve ser chamado nesse caso.
        $ads->shouldNotReceive('tryFetchAdsMetrics');

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchAdgroupsMetrics($company, Carbon::parse('2026-05-26'), Carbon::parse('2026-06-25'));

        $this->assertSame([], $result);
    }

    // ─────────── Test 7: fetchCampaigns normaliza listCampaigns ───────────

    public function test_fetchCampaigns_returns_normalized_list_via_listCampaigns(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $ads = Mockery::mock(MercadoLivreAdsService::class);
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => 71098,
                'site_id'       => 'MLB',
                'seller_id'     => '465723451',
                'raw'           => [],
                'url'           => '',
                'status'        => 200,
            ]);

        // FIXTURE ESPECULATIVA — campos id/name/status da campanha Mercado Ads.
        // CANDIDATO — revalidar contra payload real após smoke.
        $ads->shouldReceive('listCampaigns')
            ->once()
            ->andReturn([
                'count'   => 2,
                'results' => [
                    ['id' => 'CP-A', 'name' => 'Campanha A', 'status' => 'active'],
                    ['id' => 'CP-B', 'name' => 'SGI Quarentena', 'status' => 'paused'],
                ],
                'raw_first_page'  => null,
                'endpoints_tried' => [],
            ]);

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchCampaigns($company);

        $this->assertCount(2, $result);

        foreach ($result as $row) {
            $this->assertArrayHasKey('campaign_id', $row);
            $this->assertArrayHasKey('campaign_name', $row);
            $this->assertArrayHasKey('campaign_status', $row);
            $this->assertArrayHasKey('raw', $row);
        }

        $this->assertSame('CP-A', $result[0]['campaign_id']);
        $this->assertSame('Campanha A', $result[0]['campaign_name']);
        $this->assertSame('active', $result[0]['campaign_status']);
        $this->assertSame('paused', $result[1]['campaign_status']);
    }

    // ─────────── Test 8: fetchCampaignsMetrics normaliza com período ───────────

    public function test_fetchCampaignsMetrics_returns_normalized_list_with_period(): void
    {
        $company = $this->makeCompanyWithMlToken();
        $from    = Carbon::parse('2026-05-01');
        $to      = Carbon::parse('2026-05-31');

        $ads = Mockery::mock(MercadoLivreAdsService::class);
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => 71098,
                'site_id'       => 'MLB',
                'seller_id'     => '465723451',
                'raw'           => [],
                'url'           => '',
                'status'        => 200,
            ]);

        // FIXTURE ESPECULATIVA — payload de campanha com métricas Mercado Ads.
        // CANDIDATO — revalidar contra payload real após smoke.
        $ads->shouldReceive('listCampaigns')
            ->once()
            ->with($company, 71098, '2026-05-01', '2026-05-31')
            ->andReturn([
                'count'   => 1,
                'results' => [
                    [
                        'id'      => 'CP-ML-1',
                        'name'    => 'Camp ML Top',
                        'status'  => 'active',
                        'metrics' => [
                            'cost'           => 500.00,
                            'clicks'         => 200,
                            'prints'         => 8000,
                            'total_amount'   => 1500.00,
                            'units_quantity' => 10,
                            'cpc'            => 2.5,
                            'acos'           => 33.33,
                            'roas'           => 3.0,
                        ],
                    ],
                ],
                'raw_first_page'  => null,
                'endpoints_tried' => [],
            ]);

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchCampaignsMetrics($company, $from, $to);

        $this->assertCount(1, $result);
        $row = $result[0];

        $expectedKeys = [
            'campaign_id', 'campaign_name', 'campaign_status',
            'investment', 'revenue', 'sold_quantity', 'clicks', 'impressions',
            'cpc', 'acos', 'roas', 'raw',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $row, "Chave §2.2 '{$key}' ausente");
        }

        $this->assertSame('CP-ML-1', $row['campaign_id']);
        $this->assertSame('Camp ML Top', $row['campaign_name']);
        $this->assertSame('active', $row['campaign_status']);
        $this->assertSame(500.00, $row['investment']);
        $this->assertSame(1500.00, $row['revenue']);
        $this->assertSame(10, $row['sold_quantity']);
        $this->assertSame(200, $row['clicks']);
        $this->assertSame(8000, $row['impressions']);
        $this->assertSame(2.5, $row['cpc']);
        $this->assertSame(33.33, $row['acos']);
        $this->assertSame(3.0, $row['roas']);
    }

    // ─────────── Test 9: fetchAdgroupMlbs extrai mapping de item_id ───────────

    public function test_fetchAdgroupMlbs_extracts_mlb_ids_from_ads_payload(): void
    {
        $company = $this->makeCompanyWithMlToken();
        $from    = Carbon::parse('2026-05-01');
        $to      = Carbon::parse('2026-05-31');

        $ads = Mockery::mock(MercadoLivreAdsService::class);
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => 71098,
                'site_id'       => 'MLB',
                'seller_id'     => '465723451',
                'raw'           => [],
                'url'           => '',
                'status'        => 200,
            ]);

        // FIXTURE ESPECULATIVA — múltiplos ads por adgroup; cada ad com item_id MLB.
        // CANDIDATO — revalidar contra payload real após smoke.
        $ads->shouldReceive('tryFetchAdsMetrics')
            ->once()
            ->andReturn([
                'ok'    => true,
                'data'  => [
                    'count'   => 3,
                    'results' => [
                        ['id' => 'AD-1', 'item_id' => 'MLB1001', 'campaign_id' => 'CP-1', 'metrics' => []],
                        ['id' => 'AD-1', 'item_id' => 'MLB1002', 'campaign_id' => 'CP-1', 'metrics' => []],
                        ['id' => 'AD-2', 'item_id' => 'MLB2001', 'campaign_id' => 'CP-2', 'metrics' => []],
                    ],
                    'raw_first_page'  => null,
                    'endpoints_tried' => [],
                ],
                'error' => null,
            ]);

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchAdgroupMlbs($company, $from, $to);

        // Esperado: map adgroup_id → [mlb_ids]
        $this->assertArrayHasKey('AD-1', $result);
        $this->assertArrayHasKey('AD-2', $result);
        $this->assertSame(['MLB1001', 'MLB1002'], $result['AD-1']);
        $this->assertSame(['MLB2001'], $result['AD-2']);
    }

    // ─────────── Test 10: safe_div fallback aplica quando ML não envia cpc/acos/roas ───────────

    public function test_fetchAdgroupsMetrics_applies_safe_div_when_metrics_calc_fields_missing(): void
    {
        $company = $this->makeCompanyWithMlToken();
        $from    = Carbon::parse('2026-05-01');
        $to      = Carbon::parse('2026-05-31');

        $ads = Mockery::mock(MercadoLivreAdsService::class);
        $ads->shouldReceive('discoverAdvertiser')
            ->once()
            ->andReturn([
                'advertiser_id' => 71098,
                'site_id'       => 'MLB',
                'seller_id'     => '465723451',
                'raw'           => [],
                'url'           => '',
                'status'        => 200,
            ]);

        // FIXTURE ESPECULATIVA — payload sem cpc/acos/roas/ctr pré-calculados;
        // provider deve calcular via safe_div.
        // CANDIDATO — revalidar contra payload real após smoke.
        $ads->shouldReceive('tryFetchAdsMetrics')
            ->once()
            ->andReturn([
                'ok'    => true,
                'data'  => [
                    'count'   => 1,
                    'results' => [
                        [
                            'id'          => 'AD-3',
                            'title'       => 'Item sem métricas pré-calculadas',
                            'campaign_id' => 'CP-3',
                            'item_id'     => 'MLB9999',
                            'metrics'     => [
                                'cost'           => 100.00,
                                'clicks'         => 40,
                                'prints'         => 1000,
                                'total_amount'   => 400.00,
                                'units_quantity' => 2,
                                // cpc/ctr/acos/roas AUSENTES — provider calcula.
                            ],
                        ],
                    ],
                    'raw_first_page'  => null,
                    'endpoints_tried' => [],
                ],
                'error' => null,
            ]);

        $provider = new MercadoLivreSugadoresProvider($ads);
        $result   = $provider->fetchAdgroupsMetrics($company, $from, $to);

        $row = $result[0];
        // cpc = cost / clicks = 100/40 = 2.5
        $this->assertEqualsWithDelta(2.5, $row['cpc'], 0.001);
        // ctr = clicks / prints = 40/1000 = 0.04
        $this->assertEqualsWithDelta(0.04, $row['ctr'], 0.001);
        // acos = cost * 100 / revenue = 100*100/400 = 25
        $this->assertEqualsWithDelta(25.0, $row['acos'], 0.001);
        // roas = revenue / cost = 400/100 = 4.0
        $this->assertEqualsWithDelta(4.0, $row['roas'], 0.001);
    }
}
