<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Comando shopee:sync-ads — grava colunas ad_* em shopee_metrics via app 'ads'
 * e CLAMPA datas fora do lookback de 6 meses (Shopee recusa datas mais antigas).
 */
class ShopeeSyncAdsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.host'                 => 'https://openplatform.sandbox.test-stable.shopee.sg',
            'services.shopee.verify_ssl'           => false,
            'services.shopee.apps.ads.partner_id'  => 654321,
            'services.shopee.apps.ads.partner_key' => 'shpk_ads_test_key',
            'services.shopee.apps.ads.redirect'    => 'https://desafio.ecfconsultoria.com.br/oauth/shopee/ads/callback',
        ]);
    }

    private function companyComTokenAds(): Company
    {
        $company = Company::factory()->create();
        ShopeeToken::create([
            'company_id'         => $company->id,
            'app'                => 'ads',
            'shop_id'            => '227763739',
            'access_token'       => 'atk_ads',
            'refresh_token'      => 'rtk_ads',
            'expires_at'         => now()->addHours(3),
            'refresh_expires_at' => now()->addDays(30),
            'status'             => 'active',
        ]);

        return $company->fresh();
    }

    private function fakeAdsDia(float $expense = 80.0): void
    {
        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [[
                    'date'              => '20-07-2026',
                    'impression'        => 1500,
                    'clicks'            => 60,
                    'expense'           => $expense,
                    'broad_gmv'         => 700,
                    'direct_gmv'        => 200,
                    'broad_order'       => 8,
                    'direct_order'      => 3,
                    'broad_conversions' => 10,
                ]],
                'error' => '',
            ], 200),
        ]);
    }

    public function test_sync_ads_dia_recente_grava_colunas_ad(): void
    {
        $this->fakeAdsDia();
        $company = $this->companyComTokenAds();
        $ontem   = now()->subDay()->toDateString();

        $this->artisan('shopee:sync-ads', ['--date' => $ontem])->assertSuccessful();

        $this->assertDatabaseCount('shopee_metrics', 1);
        $m = \App\Models\ShopeeMetric::where('company_id', $company->id)->firstOrFail();
        $this->assertEquals(80.0, (float) $m->ad_expense);
        $this->assertSame(1500, $m->ad_impressions);
    }

    public function test_sync_ads_clampa_data_alem_de_6_meses(): void
    {
        Http::fake(); // qualquer chamada faria o teste falhar
        $this->companyComTokenAds();
        $antiga = now()->subMonths(8)->toDateString();

        $this->artisan('shopee:sync-ads', ['--date' => $antiga])->assertSuccessful();

        Http::assertNothingSent(); // clamp barrou antes de qualquer request
        $this->assertDatabaseCount('shopee_metrics', 0);
    }

    public function test_sync_ads_sem_empresa_com_token_ads_nao_faz_nada(): void
    {
        Http::fake();
        Company::factory()->create(); // sem token ADS

        $this->artisan('shopee:sync-ads')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('shopee_metrics', 0);
    }
}
