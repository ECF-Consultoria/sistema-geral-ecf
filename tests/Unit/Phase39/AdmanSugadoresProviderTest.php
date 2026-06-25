<?php

// Phase 39 Plan 39-01 — Testes Unit do AdmanSugadoresProvider.
// Cobre a normalização Adman → contrato §2.2/§2.3 do plano de migração ML.
// Usa Mockery do AdmanService (composição, sem modificá-lo) — não toca HTTP,
// não toca DB (Company instanciado via setRawAttributes, sem persistir).

namespace Tests\Unit\Phase39;

use App\Models\Company;
use App\Services\AdmanService;
use App\Services\Sugadores\AdmanSugadoresProvider;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class AdmanSugadoresProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria uma Company sem persistir no banco — suficiente para os métodos do
     * provider que só leem $company->adman_account_id e $company->marketplace.
     */
    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $defaults = ['id' => 999, 'name' => 'Teste', 'adman_account_id' => '12345', 'marketplace' => 'meli'];
        $company->setRawAttributes(array_merge($defaults, $attrs));
        return $company;
    }

    // ─────────── Test 1: supports() true quando empresa tem adman_account_id ───────────

    public function test_supports_returns_true_when_company_has_adman_account_id(): void
    {
        $adman    = Mockery::mock(AdmanService::class);
        $provider = new AdmanSugadoresProvider($adman);

        $company = $this->makeCompany(['adman_account_id' => '12345']);

        $this->assertTrue($provider->supports($company));
    }

    // ─────────── Test 2: supports() false sem adman_account_id ───────────

    public function test_supports_returns_false_when_company_has_no_adman_account_id(): void
    {
        $adman    = Mockery::mock(AdmanService::class);
        $provider = new AdmanSugadoresProvider($adman);

        $company = $this->makeCompany(['adman_account_id' => null]);

        $this->assertFalse($provider->supports($company));
    }

    // ─────────── Test 3: name() retorna 'adman' ───────────

    public function test_name_returns_adman_string(): void
    {
        $adman    = Mockery::mock(AdmanService::class);
        $provider = new AdmanSugadoresProvider($adman);

        $this->assertSame('adman', $provider->name());
    }

    // ─────────── Test 4: fetchAdgroupsMetrics delega e preserva contrato §2.3 ───────────

    public function test_fetchAdgroupsMetrics_delegates_to_adman_service_and_returns_normalized_contract(): void
    {
        $payloadAdman = [
            [
                'adgroup_id'      => 'AD-1',
                'adgroup_name'    => 'Tênis preto 42',
                'campaign_id'     => 'CP-100',
                'thumbnail'       => 'https://http2.mlstatic.com/teste.jpg',
                'adgroup_type'    => 'PRODUCT',
                'catalog_listing' => false,
                'investment'      => 150.50,
                'revenue'         => 300.00,
                'sold_quantity'   => 3,
                'clicks'          => 50,
                'impressions'     => 2000,
                'cpc'             => 3.01,
                'ctr'             => 2.5,
                'acos'            => 50.16,
                'roas'            => 1.99,
                'organic_amount'  => 120.00,
                'organic_units'   => 1,
                'raw'             => ['adgroupId' => 'AD-1', 'something' => 'else'],
            ],
        ];

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('fetchAdsMetrics')
            ->once()
            ->with('12345', '2026-05-01', '2026-05-31', 50, 'meli')
            ->andReturn($payloadAdman);

        $provider = new AdmanSugadoresProvider($adman);
        $company  = $this->makeCompany(['adman_account_id' => '12345', 'marketplace' => 'meli']);
        $from     = Carbon::parse('2026-05-01');
        $to       = Carbon::parse('2026-05-31');

        $result = $provider->fetchAdgroupsMetrics($company, $from, $to);

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
            $this->assertArrayHasKey($key, $row, "Chave obrigatória '{$key}' ausente no contrato §2.3");
        }

        // Valida preservação dos dados Adman
        $this->assertSame('AD-1', $row['adgroup_id']);
        $this->assertSame('CP-100', $row['campaign_id']);
        $this->assertSame(150.50, $row['investment']);
        $this->assertSame(3.01, $row['cpc']);
        $this->assertSame(50.16, $row['acos']);
        $this->assertNull($row['mlb_id']);     // Adman não retorna mlb_id no adgroup-level
        $this->assertNull($row['mlb_titulo']);
    }

    // ─────────── Test 5: fetchAdgroupsMetrics aplica safe_div quando cpc/acos null ───────────

    public function test_fetchAdgroupsMetrics_applies_safe_div_when_cpc_acos_null(): void
    {
        $payloadAdman = [
            [
                'adgroup_id'      => 'AD-2',
                'adgroup_name'    => 'Item sem métricas calculadas',
                'campaign_id'     => 'CP-200',
                'thumbnail'       => null,
                'adgroup_type'    => null,
                'catalog_listing' => false,
                'investment'      => 100.00,
                'revenue'         => 400.00,
                'sold_quantity'   => 2,
                'clicks'          => 40,
                'impressions'     => 1000,
                'cpc'             => null,    // ausente — provider deve calcular via safe_div
                'ctr'             => null,    // ausente
                'acos'            => null,    // ausente
                'roas'            => null,    // ausente
                'organic_amount'  => null,
                'organic_units'   => null,
                'raw'             => [],
            ],
        ];

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('fetchAdsMetrics')->once()->andReturn($payloadAdman);

        $provider = new AdmanSugadoresProvider($adman);
        $company  = $this->makeCompany();
        $result   = $provider->fetchAdgroupsMetrics($company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        $row = $result[0];
        // cpc = investment / clicks = 100/40 = 2.5
        $this->assertEqualsWithDelta(2.5, $row['cpc'], 0.001);
        // ctr = clicks / impressions * 100 não é o que safe_div faz por default;
        // provider só preenche cpc/acos/roas pelo helper. ctr fica null se Adman não pré-calcular.
        // acos = investment / revenue * 100 = 100/400*100 = 25
        $this->assertEqualsWithDelta(25.0, $row['acos'], 0.001);
        // roas = revenue / investment = 400/100 = 4.0
        $this->assertEqualsWithDelta(4.0, $row['roas'], 0.001);
    }

    // ─────────── Test 6: fetchCampaignsMetrics delega para fetchCampaignsRange ───────────

    public function test_fetchCampaignsMetrics_delegates_to_fetchCampaignsRange(): void
    {
        $payloadAdman = [
            [
                'campaign_id'     => 'CP-1',
                'campaign_name'   => 'Camp Top',
                'campaign_status' => 'active',
                'investment'      => 500.00,
                'revenue'         => 1500.00,
                'sold_quantity'   => 10,
                'clicks'          => 200,
                'impressions'     => 8000,
                'cpc'             => 2.5,
                'acos'            => 33.33,
                'roas'            => 3.0,
                'raw'             => ['campaignId' => 'CP-1'],
            ],
        ];

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('fetchCampaignsRange')
            ->once()
            ->with('12345', '2026-05-01', '2026-05-31', 'meli')
            ->andReturn($payloadAdman);

        $provider = new AdmanSugadoresProvider($adman);
        $company  = $this->makeCompany();
        $result   = $provider->fetchCampaignsMetrics($company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

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

        $this->assertSame('CP-1', $row['campaign_id']);
        $this->assertSame('Camp Top', $row['campaign_name']);
        $this->assertSame('active', $row['campaign_status']);
    }

    // ─────────── Test 7: fetchCampaigns delega para fetchAllCampaigns e normaliza ───────────

    public function test_fetchCampaigns_delegates_to_fetchAllCampaigns_and_normalizes(): void
    {
        $payloadAdman = [
            ['campaignId' => 'CP-A', 'name' => 'Campanha A', 'status' => 'active', 'budget' => 100],
            ['campaignId' => 'CP-B', 'name' => 'SGI Quarentena', 'status' => 'paused', 'budget' => 0],
        ];

        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldReceive('fetchAllCampaigns')
            ->once()
            ->with('12345', 'meli')
            ->andReturn($payloadAdman);

        $provider = new AdmanSugadoresProvider($adman);
        $company  = $this->makeCompany();
        $result   = $provider->fetchCampaigns($company);

        $this->assertCount(2, $result);
        $this->assertSame('CP-A', $result[0]['campaign_id']);
        $this->assertSame('Campanha A', $result[0]['campaign_name']);
        $this->assertSame('active', $result[0]['campaign_status']);
        $this->assertArrayHasKey('raw', $result[0]);

        $this->assertSame('CP-B', $result[1]['campaign_id']);
        $this->assertSame('paused', $result[1]['campaign_status']);
    }

    // ─────────── Test 8: fetchAdgroupMlbs retorna [] no path Adman ───────────

    public function test_fetchAdgroupMlbs_returns_empty_array_when_adman_path_used(): void
    {
        $adman    = Mockery::mock(AdmanService::class);
        // Não deve chamar nada do AdmanService — provider Adman delega para Job separado.
        $provider = new AdmanSugadoresProvider($adman);

        $company = $this->makeCompany();
        $result  = $provider->fetchAdgroupMlbs($company, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'));

        $this->assertSame([], $result);
    }
}
