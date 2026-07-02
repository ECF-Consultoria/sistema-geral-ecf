<?php

// Phase 53 Plan 53-01 — Testes RED (TDD) para os 3 fixes cirurgicos:
//  1. Cache 10min em MercadoLivreAdsService::listCampaigns (contra rate-limit ML).
//  2. Novo MercadoLivreService::fetchItemStatus com cache 1h + fail-open universal.
//  3. Filtro em MercadoLivreSugadoresProvider::fetchAdgroupsMetrics: adgroup cujo
//     MLB esta paused/closed/under_review NAO entra no array retornado (fix B1
//     CAMILLO PARTS, evidencia real em prod §Caso B1 do 53-RESEARCH).
//
// Evidencia prod (53-RESEARCH):
//  - 639 rate-limit incidents ML no historico → listCampaigns sem cache derruba
//    filtro SGI. Fix: Cache::remember("ml:campaigns:{advertiser}:{from}:{to}", 600).
//  - MLB6258261358 (CAMILLO) retornou status=paused na API ML mas o payload de
//    Ads reporta raw_data.status=active (status do ADGROUP, nao do MLB) →
//    detector nunca ve que o vendedor pausou o anuncio.
//
// Padrao: Http::preventStrayRequests + Cache::flush + Carbon::setTestNow
// (mesmo padrao Phase 42 - AnalyzeCompanyMlWindowQuarantineTest linha 44-50).

namespace Tests\Unit\Services\Sugadores;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Models\SugadorConfig;
use App\Services\MercadoLivreService;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use App\Services\SugadorAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MercadoLivreSugadoresProviderFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────── Helpers ───────────────────────

    /**
     * Cria Company + MlToken active + MlAdvertiser cacheado (evita chamar
     * discoverAdvertiser em cada teste). Espelha helper Phase 42.
     */
    private function makeCompanyWithMlToken(int $advertiserId = 620095): Company
    {
        $company = Company::create([
            'name'        => 'Phase 53-01 Test Company',
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
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => (string) $advertiserId,
            'seller_id'     => '465723451',
            'site_id'       => 'MLB',
            'raw_data'      => [],
            'discovered_at' => now(), // cache hit — nao dispara discoverAdvertiser HTTP
        ]);

        SugadorConfig::create([
            'company_id'                      => $company->id,
            'dias_analise'                    => 30,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => false,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ]);

        return $company->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Bloco 1: cache 10min em MercadoLivreAdsService::listCampaigns
    // ═══════════════════════════════════════════════════════════════════

    public function test_list_campaigns_hits_cache_on_second_call(): void
    {
        $company = $this->makeCompanyWithMlToken(620095);

        Http::fake([
            '*/product_ads/campaigns/search*' => Http::response(
                ['results' => [], 'paging' => ['total' => 0]],
                200,
            ),
        ]);

        /** @var MercadoLivreAdsService $ads */
        $ads = $this->app->make(MercadoLivreAdsService::class);

        // Primeira chamada — miss, dispara HTTP.
        $ads->listCampaigns($company, 620095, '2026-06-01', '2026-06-30');
        // Segunda chamada com mesmos args — DEVE vir do cache (sem novo HTTP).
        $ads->listCampaigns($company, 620095, '2026-06-01', '2026-06-30');

        Http::assertSentCount(1);
    }

    public function test_list_campaigns_cache_key_varies_by_date_range(): void
    {
        $company = $this->makeCompanyWithMlToken(620095);

        Http::fake([
            '*/product_ads/campaigns/search*' => Http::response(
                ['results' => [], 'paging' => ['total' => 0]],
                200,
            ),
        ]);

        /** @var MercadoLivreAdsService $ads */
        $ads = $this->app->make(MercadoLivreAdsService::class);

        $ads->listCampaigns($company, 620095, '2026-06-01', '2026-06-30');
        // Mesma empresa/advertiser mas date_to diferente → chave distinta → HTTP novo.
        $ads->listCampaigns($company, 620095, '2026-06-01', '2026-07-30');

        Http::assertSentCount(2);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Bloco 2: MercadoLivreService::fetchItemStatus
    // ═══════════════════════════════════════════════════════════════════

    public function test_fetch_item_status_returns_normalized_array(): void
    {
        $company = $this->makeCompanyWithMlToken();

        Http::fake([
            '*/items/MLB123*' => Http::response([
                'id'                 => 'MLB123',
                'status'             => 'paused',
                'sub_status'         => ['paused_by_seller'],
                'available_quantity' => 1,
                'sold_quantity'      => 4,
                'shipping'           => ['logistic_type' => 'fulfillment'],
            ], 200),
        ]);

        /** @var MercadoLivreService $ml */
        $ml = $this->app->make(MercadoLivreService::class);
        $result = $ml->fetchItemStatus($company, 'MLB123');

        $this->assertSame('paused', $result['status']);
        $this->assertSame(['paused_by_seller'], $result['sub_status']);
        $this->assertSame(1, $result['available_quantity']);
        $this->assertSame(4, $result['sold_quantity']);
        $this->assertSame('fulfillment', $result['logistic_type']);
    }

    public function test_fetch_item_status_hits_cache_on_second_call(): void
    {
        $company = $this->makeCompanyWithMlToken();

        Http::fake([
            '*/items/MLB123*' => Http::response([
                'id'                 => 'MLB123',
                'status'             => 'active',
                'sub_status'         => [],
                'available_quantity' => 10,
                'sold_quantity'      => 50,
                'shipping'           => ['logistic_type' => 'xd_drop_off'],
            ], 200),
        ]);

        /** @var MercadoLivreService $ml */
        $ml = $this->app->make(MercadoLivreService::class);

        $ml->fetchItemStatus($company, 'MLB123');
        $ml->fetchItemStatus($company, 'MLB123');

        Http::assertSentCount(1);
    }

    public function test_fetch_item_status_fail_open_on_exception(): void
    {
        $company = $this->makeCompanyWithMlToken();

        Http::fake([
            '*/items/*' => Http::response('rate limit', 429),
        ]);

        /** @var MercadoLivreService $ml */
        $ml = $this->app->make(MercadoLivreService::class);
        $result = $ml->fetchItemStatus($company, 'MLB999');

        $this->assertNull($result['status']);
        $this->assertSame([], $result['sub_status']);
        $this->assertNull($result['available_quantity']);
        $this->assertNull($result['sold_quantity']);
        $this->assertNull($result['logistic_type']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Bloco 3: filtro mlb_status no provider (fix B1 CAMILLO PARTS)
    // ═══════════════════════════════════════════════════════════════════

    private function fakeProviderEndpoints(array $campaignsResults, array $adsResults, array $itemsByMlbId): void
    {
        $itemFakes = [];
        foreach ($itemsByMlbId as $mlbId => $payload) {
            $itemFakes["*/items/{$mlbId}*"] = Http::response($payload, 200);
        }

        Http::fake(array_merge(
            $itemFakes,
            [
                '*/product_ads/items*' => Http::response([
                    'results' => $adsResults,
                    'paging'  => ['total' => count($adsResults)],
                ], 200),
                '*/product_ads/campaigns/search*' => Http::response([
                    'results' => $campaignsResults,
                    'paging'  => ['total' => count($campaignsResults)],
                ], 200),
                // Fallback generico (nao deve bater — MlAdvertiser cacheado).
                '*/advertising/advertisers*' => Http::response([
                    'advertisers' => [[
                        'advertiser_id' => 620095,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200),
                // Rate-limit fallback pra qualquer /items/* nao configurado — fail-open testado.
                '*/items/*' => Http::response('rate limit', 429),
            ],
        ));
    }

    public function test_provider_skips_adgroup_when_mlb_paused(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->fakeProviderEndpoints(
            campaignsResults: [['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active']],
            adsResults: [[
                'ad_group_id'     => 'AD1',
                'title'           => 'MLB pausado no seller',
                'campaign_id'     => 'C1',
                'item_id'         => 'MLB111',
                'metrics'         => ['cost' => 30, 'clicks' => 11, 'prints' => 3499, 'units_quantity' => 0],
            ]],
            itemsByMlbId: [
                'MLB111' => [
                    'id'                 => 'MLB111',
                    'status'             => 'paused',
                    'sub_status'         => ['paused_by_seller'],
                    'available_quantity' => 1,
                    'sold_quantity'      => 4,
                    'shipping'           => ['logistic_type' => 'xd_drop_off'],
                ],
            ],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertCount(0, $result, 'Adgroup com MLB paused deve ser skipado.');
    }

    public function test_provider_skips_adgroup_when_mlb_under_review(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->fakeProviderEndpoints(
            campaignsResults: [['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active']],
            adsResults: [[
                'ad_group_id'     => 'AD1',
                'title'           => 'MLB em revisao',
                'campaign_id'     => 'C1',
                'item_id'         => 'MLB222',
                'metrics'         => ['cost' => 45, 'clicks' => 20, 'prints' => 1500, 'units_quantity' => 0],
            ]],
            itemsByMlbId: [
                'MLB222' => [
                    'id'                 => 'MLB222',
                    'status'             => 'under_review',
                    'sub_status'         => [],
                    'available_quantity' => 5,
                    'sold_quantity'      => 12,
                    'shipping'           => ['logistic_type' => 'fulfillment'],
                ],
            ],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertCount(0, $result, 'Adgroup com MLB under_review deve ser skipado.');
    }

    public function test_provider_skips_adgroup_when_mlb_closed(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->fakeProviderEndpoints(
            campaignsResults: [['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active']],
            adsResults: [[
                'ad_group_id'     => 'AD1',
                'title'           => 'MLB fechado',
                'campaign_id'     => 'C1',
                'item_id'         => 'MLB333',
                'metrics'         => ['cost' => 60, 'clicks' => 30, 'prints' => 2000, 'units_quantity' => 0],
            ]],
            itemsByMlbId: [
                'MLB333' => [
                    'id'                 => 'MLB333',
                    'status'             => 'closed',
                    'sub_status'         => [],
                    'available_quantity' => 0,
                    'sold_quantity'      => 100,
                    'shipping'           => ['logistic_type' => 'xd_drop_off'],
                ],
            ],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertCount(0, $result, 'Adgroup com MLB closed deve ser skipado.');
    }

    public function test_provider_keeps_adgroup_when_mlb_active(): void
    {
        $company = $this->makeCompanyWithMlToken();

        $this->fakeProviderEndpoints(
            campaignsResults: [['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active']],
            adsResults: [[
                'ad_group_id'     => 'AD1',
                'title'           => 'MLB legitimo (regressao)',
                'campaign_id'     => 'C1',
                'item_id'         => 'MLB444',
                'metrics'         => ['cost' => 25, 'clicks' => 15, 'prints' => 1000, 'units_quantity' => 0],
            ]],
            itemsByMlbId: [
                'MLB444' => [
                    'id'                 => 'MLB444',
                    'status'             => 'active',
                    'sub_status'         => [],
                    'available_quantity' => 20,
                    'sold_quantity'      => 5,
                    'shipping'           => ['logistic_type' => 'xd_drop_off'],
                ],
            ],
        );

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertCount(1, $result, 'Adgroup com MLB active deve ser retornado (regressao).');
        $this->assertSame('AD1', $result[0]['adgroup_id']);
        $this->assertArrayHasKey('mlb_status', $result[0]);
        $this->assertSame('active', $result[0]['mlb_status']);
    }

    public function test_provider_keeps_adgroup_when_mlb_status_null_fail_open(): void
    {
        $company = $this->makeCompanyWithMlToken();

        // /items/* retorna 429 → fetchItemStatus fail-open → mlb_status=null → NAO skipa.
        Http::fake([
            '*/product_ads/items*' => Http::response([
                'results' => [[
                    'ad_group_id'     => 'AD1',
                    'title'           => 'MLB inacessivel (rate-limit)',
                    'campaign_id'     => 'C1',
                    'item_id'         => 'MLB555',
                    'metrics'         => ['cost' => 40, 'clicks' => 22, 'prints' => 1200, 'units_quantity' => 0],
                ]],
                'paging' => ['total' => 1],
            ], 200),
            '*/product_ads/campaigns/search*' => Http::response([
                'results' => [['id' => 'C1', 'name' => 'Campanha A', 'status' => 'active']],
                'paging'  => ['total' => 1],
            ], 200),
            // /items/* SEMPRE 429 nesta suite — provider deve fail-open e preservar adgroup.
            '*/items/*' => Http::response('rate limit', 429),
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 620095,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
        ]);

        /** @var MercadoLivreSugadoresProvider $provider */
        $provider = $this->app->make(MercadoLivreSugadoresProvider::class);
        $result = $provider->fetchAdgroupsMetrics(
            $company,
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-30'),
        );

        $this->assertCount(1, $result, 'Fail-open: MLB status=null NAO skipa (preserva volume).');
        $this->assertSame('AD1', $result[0]['adgroup_id']);
        $this->assertNull($result[0]['mlb_status']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Bloco 4: Phase 53-02 — filtro sold_global no evaluateMetrics
    //  (fix unificado B2 BARAOSHOP + B3 DINMAP — venda organica global)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Helper Phase 53-02: SugadorConfig default para os testes de evaluateMetrics.
     * Espelha os defaults de SugadorConfig::DEFAULTS + habilita os 4 criterios.
     * cliques_minimos_sem_venda=15 (usado no teste de preservar cliques_sem_conversao).
     */
    private function defaultConfig(): SugadorConfig
    {
        $company = $this->makeCompanyWithMlToken(620096); // advertiser distinto pra isolar
        $config = SugadorConfig::where('company_id', $company->id)->first();
        // Habilita todos os criterios com valores conhecidos.
        $config->update([
            'gasto_minimo_sem_venda'    => 10.00,
            'gasto_minimo_logic'        => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_maximo'                => 2.00,
            'cpc_maximo_logic'          => SugadorConfig::LOGIC_OPTIONAL,
            'cpc_minimo_cliques'        => null,
            'acos_maximo_pct'           => 80.00,
            'acos_maximo_logic'         => SugadorConfig::LOGIC_OPTIONAL,
            'cliques_minimos_sem_venda' => 15,
            'cliques_minimos_logic'     => SugadorConfig::LOGIC_OPTIONAL,
        ]);
        return $config->fresh();
    }

    public function test_evaluate_metrics_generates_sugador_when_sold_global_zero(): void
    {
        // Baseline: sold_global=0 + gasto_sem_venda bate → gera sugador (hit legitimo).
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 12.22,
            'sold_quantity' => 0,   // units_ads=0
            'clicks'        => 4,
            'cpc'           => 3.05,
            'acos'          => null,
            'sold_global'   => 0,   // sem venda organica tambem
        ], $config);

        $this->assertContains('gasto_sem_venda', $motivos, 'sold_global=0 nao deve filtrar (hit legitimo).');
    }

    public function test_evaluate_metrics_removes_gasto_sem_venda_when_sold_global_gte_10(): void
    {
        // Caso B2 BARAOSHOP: 781 vendas globais + FULL, ads reporta 0.
        // Filtro Phase 53-02 remove gasto_sem_venda quando sold_global >= 10.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 12.22,
            'sold_quantity' => 0,   // units_ads=0
            'clicks'        => 4,
            'cpc'           => 3.05,
            'acos'          => null,
            'sold_global'   => 781, // vende MUITO organico via FULL
        ], $config);

        $this->assertNotContains('gasto_sem_venda', $motivos, 'sold_global>=10 deve filtrar gasto_sem_venda (fix B2).');
    }

    public function test_evaluate_metrics_threshold_exact_10_removes_gasto_sem_venda(): void
    {
        // Threshold LOCKED = 10 (research §A1). Exatamente 10 ainda filtra.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 15.00,
            'sold_quantity' => 0,
            'clicks'        => 3,
            'cpc'           => 5.00,
            'acos'          => null,
            'sold_global'   => 10, // limite exato
        ], $config);

        $this->assertNotContains('gasto_sem_venda', $motivos, 'sold_global=10 (limite exato) deve filtrar.');
    }

    public function test_evaluate_metrics_keeps_gasto_sem_venda_when_sold_global_below_threshold(): void
    {
        // sold_global=9 (abaixo do threshold 10) → NAO filtra, hit legitimo.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 15.00,
            'sold_quantity' => 0,
            'clicks'        => 3,
            'cpc'           => 5.00,
            'acos'          => null,
            'sold_global'   => 9, // abaixo do threshold
        ], $config);

        $this->assertContains('gasto_sem_venda', $motivos, 'sold_global<10 deve manter hit (baixo volume organico nao invalida).');
    }

    public function test_evaluate_metrics_keeps_gasto_sem_venda_when_sold_global_null_fail_open(): void
    {
        // Fail-open Wave 1: sold_global=null (rate-limit ML, path Adman) preserva comportamento legacy.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 15.00,
            'sold_quantity' => 0,
            'clicks'        => 3,
            'cpc'           => 5.00,
            'acos'          => null,
            'sold_global'   => null, // sem sinal ML
        ], $config);

        $this->assertContains('gasto_sem_venda', $motivos, 'sold_global=null (fail-open) preserva comportamento legacy.');
    }

    public function test_evaluate_metrics_preserves_acos_alto_when_sold_global_high(): void
    {
        // Outros criterios NAO sao afetados pelo filtro sold_global.
        // acos_alto exige sold_quantity>0 — sold_global alto nao muda nada.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 50.00,
            'sold_quantity' => 5,   // ads converteu 5 — acos avaliavel
            'clicks'        => 20,
            'cpc'           => 2.50,
            'acos'          => 85.00, // acima do threshold 80
            'sold_global'   => 150, // alto, mas nao afeta acos_alto
        ], $config);

        $this->assertContains('acos_alto', $motivos, 'acos_alto NAO deve ser afetado por sold_global.');
    }

    public function test_evaluate_metrics_preserves_cpc_alto_when_sold_global_high(): void
    {
        // cpc_alto exige sold_quantity=0. Mesmo com sold_global alto, cpc_alto se mantem.
        /** @var SugadorAnalysisService $service */
        $service = $this->app->make(SugadorAnalysisService::class);
        $config  = $this->defaultConfig();

        $motivos = $service->evaluateMetrics([
            'investment'    => 50.00,
            'sold_quantity' => 0,   // ads=0
            'clicks'        => 10,
            'cpc'           => 5.00, // acima do threshold 2.00
            'acos'          => null,
            'sold_global'   => 100, // alto — filtra gasto_sem_venda mas NAO cpc_alto
        ], $config);

        $this->assertContains('cpc_alto', $motivos, 'cpc_alto NAO deve ser afetado por sold_global.');
        $this->assertNotContains('gasto_sem_venda', $motivos, 'gasto_sem_venda deve ser filtrado (validacao cross-criterio).');
    }
}
