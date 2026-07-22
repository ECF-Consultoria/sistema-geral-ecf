<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Métricas de ADS Shopee (app 'ads') com a API mockada. Valida a agregação do
 * fetchAdsMetrics sobre get_all_cpc_ads_daily_performance (gasto/GMV/pedidos +
 * derivados CTR/ROAS/ACoS) e o cálculo de TACoS.
 *
 * O payload do fake espelha o SCHEMA REAL capturado no sandbox (shop 227763739):
 * lista de dias com date "DD-MM-YYYY", impression, clicks, expense, broad_gmv,
 * direct_gmv, broad_order, direct_order, *_roas. Números não-zero (o sandbox
 * devolve tudo 0 por não ter tráfego — aqui testamos o parsing de verdade).
 */
class ShopeeAdsMetricsTest extends TestCase
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

    /** Um dia do schema real, com os campos que fetchAdsMetrics agrega. */
    private function dia(string $date, int $impression, int $clicks, float $expense, float $broadGmv, float $directGmv, int $broadOrder, int $directOrder): array
    {
        return [
            'date'              => $date,
            'impression'        => $impression,
            'clicks'            => $clicks,
            'ctr'               => $impression > 0 ? $clicks / $impression : 0,
            'direct_order'      => $directOrder,
            'broad_order'       => $broadOrder,
            'direct_conversions' => $directOrder,
            'broad_conversions' => $broadOrder * 2, // valor distinto p/ cobrir a agregação
            'direct_gmv'        => $directGmv,
            'broad_gmv'         => $broadGmv,
            'expense'           => $expense,
            'broad_roas'        => $expense > 0 ? $broadGmv / $expense : 0,
            'direct_roas'       => $expense > 0 ? $directGmv / $expense : 0,
        ];
    }

    public function test_fetch_ads_metrics_agrega_periodo_e_deriva_ctr_roas_acos(): void
    {
        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [
                    $this->dia('06-07-2026', 1000, 50, 100, 500, 200, 5, 2),
                    $this->dia('07-07-2026', 3000, 150, 150, 1000, 400, 10, 4),
                ],
                'error'   => '',
            ], 200),
        ]);
        $company = $this->companyComTokenAds();

        $m = ShopeeService::for('ads')->fetchAdsMetrics($company, '2026-07-06', '2026-07-07');

        $this->assertSame(250.0, $m['expense']);      // 100 + 150
        $this->assertSame(1500.0, $m['broad_gmv']);   // 500 + 1000
        $this->assertSame(600.0, $m['direct_gmv']);   // 200 + 400
        $this->assertSame(4000, $m['impressions']);   // 1000 + 3000
        $this->assertSame(200, $m['clicks']);         // 50 + 150
        $this->assertSame(15, $m['broad_orders']);    // 5 + 10
        $this->assertSame(6, $m['direct_orders']);    // 2 + 4
        $this->assertSame(0.05, $m['ctr']);           // 200 / 4000
        $this->assertSame(6.0, $m['broad_roas']);     // 1500 / 250
        $this->assertSame(0.1667, $m['acos']);        // 250 / 1500
        $this->assertSame(30, $m['broad_conversions']); // (5+10)*2
        $this->assertCount(2, $m['days']);
    }

    public function test_sync_ads_day_grava_colunas_ad_na_mesma_linha(): void
    {
        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [
                    $this->dia('20-07-2026', 2000, 80, 120.5, 900, 300, 12, 5),
                ],
                'error'   => '',
            ], 200),
        ]);
        $company = $this->companyComTokenAds();

        $m = ShopeeService::for('ads')->syncAdsDay($company, '2026-07-20');

        $this->assertNotNull($m);
        $this->assertEquals(120.5, (float) $m->ad_expense);
        $this->assertSame(2000, $m->ad_impressions);
        $this->assertSame(80, $m->ad_clicks);
        $this->assertEquals(900.0, (float) $m->ad_broad_gmv);
        $this->assertSame(12, $m->ad_broad_orders);
        $this->assertSame(24, $m->ad_broad_conversions); // 12 * 2
        $this->assertNotNull($m->ad_synced_at);

        $this->assertDatabaseHas('shopee_metrics', [
            'company_id'     => $company->id,
            'reference_date' => '2026-07-20 00:00:00', // formato do cast date sob SQLite
            'ad_impressions' => 2000,
        ]);
    }

    public function test_sync_ads_day_nao_clobbera_faturamento_existente(): void
    {
        // Linha de faturamento já existente (app erp) — o sync de Ads deve
        // preservar revenue/orders e só preencher as colunas ad_*.
        // Insert RAW com data "pura" (sem hora) p/ espelhar a coluna DATE do
        // MySQL de produção — é o que o updateOrCreate do syncAdsDay procura.
        $company = $this->companyComTokenAds();
        \Illuminate\Support\Facades\DB::table('shopee_metrics')->insert([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-20',
            'revenue'        => 1234.56,
            'orders_count'   => 9,
            'sold_quantity'  => 15,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [$this->dia('20-07-2026', 500, 20, 50, 300, 100, 4, 1)],
                'error'    => '',
            ], 200),
        ]);

        ShopeeService::for('ads')->syncAdsDay($company, '2026-07-20');

        $this->assertDatabaseCount('shopee_metrics', 1); // mesma linha, não duplicou

        $m = \App\Models\ShopeeMetric::where('company_id', $company->id)->firstOrFail();

        $this->assertEquals(1234.56, (float) $m->revenue); // preservado
        $this->assertSame(9, $m->orders_count);            // preservado
        $this->assertEquals(50.0, (float) $m->ad_expense); // preenchido
    }

    public function test_sync_ads_day_pula_dia_totalmente_zerado(): void
    {
        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [$this->dia('20-07-2026', 0, 0, 0, 0, 0, 0, 0)],
                'error'    => '',
            ], 200),
        ]);
        $company = $this->companyComTokenAds();

        $m = ShopeeService::for('ads')->syncAdsDay($company, '2026-07-20');

        $this->assertNull($m);
        $this->assertDatabaseMissing('shopee_metrics', [
            'company_id'     => $company->id,
            'reference_date' => '2026-07-20',
        ]);
    }

    public function test_fetch_ads_metrics_periodo_zerado_nao_estoura_divisao(): void
    {
        // Espelha o retorno do sandbox: dias existem mas tudo é 0.
        Http::fake([
            '*get_all_cpc_ads_daily_performance*' => Http::response([
                'response' => [
                    $this->dia('06-07-2026', 0, 0, 0, 0, 0, 0, 0),
                ],
                'error'   => '',
            ], 200),
        ]);
        $company = $this->companyComTokenAds();

        $m = ShopeeService::for('ads')->fetchAdsMetrics($company, '2026-07-06', '2026-07-06');

        $this->assertSame(0.0, $m['expense']);
        $this->assertSame(0.0, $m['ctr']);
        $this->assertNull($m['broad_roas']); // sem gasto → null, não divisão por zero
        $this->assertNull($m['acos']);       // sem GMV → null
    }

    public function test_tacos_cruza_gasto_com_faturamento_total(): void
    {
        // gasto 250 sobre faturamento total 5000 → 5%
        $this->assertSame(0.05, ShopeeService::tacos(250, 5000));
        // sem faturamento → null (evita divisão por zero)
        $this->assertNull(ShopeeService::tacos(250, 0));
    }

    public function test_ads_sem_token_lanca_sem_bater_na_api(): void
    {
        Http::fake(); // qualquer request faria o teste falhar
        $company = Company::factory()->create(); // sem token 'ads'

        try {
            ShopeeService::for('ads')->fetchAdsMetrics($company, '2026-07-06', '2026-07-07');
            $this->fail('deveria ter lançado RuntimeException por falta de token');
        } catch (\RuntimeException) {
            // esperado — get() aborta antes de qualquer request
        }

        Http::assertNothingSent();
    }
}
