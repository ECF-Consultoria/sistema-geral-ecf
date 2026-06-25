<?php

// Phase 38 Plan 38-01 — Testes Feature do MercadoLivreAdsService.
// Cobre a camada HTTP do ML Mercado Ads (advertiser/campaigns) que o comando
// `sugadores:ml-smoke` (Plan 02) vai consumir. Todos os tests usam Http::fake
// para isolar de chamadas reais à API ML.

namespace Tests\Feature\Phase38;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\Sugadores\MercadoLivreAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoLivreAdsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bloqueia qualquer request HTTP que escape do Http::fake — garante que
        // nenhum teste vaze chamada real para api.mercadolibre.com.
        Http::preventStrayRequests();
    }

    /**
     * Cria uma Company + MlToken ativo (fixture mínima para o service rodar).
     * Não persiste no banco campos opcionais — só o suficiente para
     * `ensureValidToken()` aceitar o token sem precisar refresh.
     */
    private function makeCompanyWithMlToken(): Company
    {
        $company = Company::create([
            'name'         => 'ByMobille - Teste',
            'cnpj'         => '99999999000099',
            'active'       => true,
            'ml_store_id'  => '465723451',
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
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        return $company->fresh();
    }

    // ─────────── Test 1: advertiser descoberto (path feliz) ───────────

    public function test_discoverAdvertiser_returns_advertiser_data_when_account_has_pads(): void
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
        ]);

        $service = $this->app->make(MercadoLivreAdsService::class);
        $result  = $service->discoverAdvertiser($company);

        $this->assertSame(71098, $result['advertiser_id']);
        $this->assertSame('MLB', $result['site_id']);
        $this->assertSame('465723451', $result['seller_id']);
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('url', $result);
        // Payload cru completo preservado para o relatório do Plan 02 inspecionar.
        $this->assertSame(71098, $result['raw']['advertisers'][0]['advertiser_id']);
    }

    // ─────────── Test 2: advertiser ausente (lista vazia — não é erro) ───────────

    public function test_discoverAdvertiser_returns_empty_when_account_has_no_pads(): void
    {
        $company = $this->makeCompanyWithMlToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [],
            ], 200),
        ]);

        $service = $this->app->make(MercadoLivreAdsService::class);
        $result  = $service->discoverAdvertiser($company);

        // Conta sem Mercado Ads é estado VÁLIDO — service não lança exceção.
        $this->assertNull($result['advertiser_id']);
        $this->assertNull($result['site_id']);
        $this->assertNull($result['seller_id']);
        $this->assertSame(['advertisers' => []], $result['raw']);
        $this->assertSame(200, $result['status']);
    }

    // ─────────── Test 3: paginação de campanhas ───────────

    public function test_listCampaigns_paginates_and_returns_all_results(): void
    {
        $company = $this->makeCompanyWithMlToken();

        // Gera 50 results na página 1 e 23 na página 2 (total=73).
        $page1Results = array_fill(0, 50, ['id' => 'C1', 'name' => 'X']);
        $page2Results = array_fill(0, 23, ['id' => 'C2', 'name' => 'Y']);

        // O matcher 'sequence' do Http::fake responde na ordem das chamadas
        // — primeira call recebe page1, segunda recebe page2.
        Http::fake([
            '*/product_ads/campaigns/search*' => Http::sequence()
                ->push(['results' => $page1Results, 'paging' => ['total' => 73]], 200)
                ->push(['results' => $page2Results, 'paging' => ['total' => 73]], 200),
        ]);

        $service = $this->app->make(MercadoLivreAdsService::class);
        $result  = $service->listCampaigns($company, 71098, '2026-05-26', '2026-06-25');

        $this->assertSame(73, $result['count']);
        $this->assertCount(73, $result['results']);
        $this->assertArrayHasKey('raw_first_page', $result);
        $this->assertArrayHasKey('results', $result['raw_first_page']);
        $this->assertArrayHasKey('paging', $result['raw_first_page']);
        // 2 chamadas HTTP foram feitas — uma por página.
        $this->assertCount(2, $result['endpoints_tried']);
    }

    // ─────────── Test 4: 401 persistente após refresh dispara exceção ───────────

    public function test_discoverAdvertiser_throws_when_401_persists_after_refresh(): void
    {
        $company = $this->makeCompanyWithMlToken();

        // Todas as chamadas (advertiser + refresh) retornam 401 — o
        // MercadoLivreService::get() tenta refresh e propaga RuntimeException.
        Http::fake([
            '*/advertising/advertisers*' => Http::response(['error' => 'invalid_token'], 401),
            '*/oauth/token*'             => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $service = $this->app->make(MercadoLivreAdsService::class);

        $this->expectException(\RuntimeException::class);
        $service->discoverAdvertiser($company);
    }
}
