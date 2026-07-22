<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Métrica Shopee (Dashboard) com a API mockada. Valida a orquestração
 * pedidos→detalhe do fetchOrdersSummary (bruto/contagens, ignorando pedidos
 * não-pagos/cancelados) e a gravação diária em shopee_metrics via syncCompanyDay.
 *
 * Estrutura atual (main): sem escrow/net_billing e sem ShopeeMetricsProvider —
 * o faturamento é bruto (total_amount) e persiste em tabela dedicada.
 */
class ShopeeMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.host'                 => 'https://openplatform.sandbox.test-stable.shopee.sg',
            'services.shopee.verify_ssl'           => false,
            'services.shopee.apps.erp.partner_id'  => 123456,
            'services.shopee.apps.erp.partner_key' => 'shpk_test_key',
            'services.shopee.apps.erp.redirect'    => 'https://desafio.ecfconsultoria.com.br/oauth/shopee/callback',
        ]);
    }

    private function companyComToken(): Company
    {
        $company = Company::factory()->create();
        ShopeeToken::create([
            'company_id'         => $company->id,
            'app'                => 'erp',
            'shop_id'            => '227758374',
            'access_token'       => 'atk',
            'refresh_token'      => 'rtk',
            'expires_at'         => now()->addHours(3),
            'refresh_expires_at' => now()->addDays(30),
            'status'             => 'active',
        ]);

        return $company->fresh();
    }

    /** Dois pedidos COMPLETED: lista → detalhe → soma bruto/itens. */
    private function fakeDoisPedidosOk(): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => [
                    'order_list'  => [['order_sn' => 'A'], ['order_sn' => 'B']],
                    'more'        => false,
                    'next_cursor' => '',
                ],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => [
                    'order_list' => [
                        ['order_sn' => 'A', 'order_status' => 'COMPLETED', 'total_amount' => 100, 'item_list' => [['model_quantity_purchased' => 2]]],
                        ['order_sn' => 'B', 'order_status' => 'COMPLETED', 'total_amount' => 50,  'item_list' => [['model_quantity_purchased' => 1]]],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_fetch_orders_summary_agrega_bruto_e_contagens(): void
    {
        $this->fakeDoisPedidosOk();
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(150.0, $sum['revenue']);   // 100 + 50
        $this->assertSame(2, $sum['orders_count']);
        $this->assertSame(3, $sum['sold_quantity']); // 2 + 1
    }

    public function test_fetch_orders_summary_ignora_cancelados_e_nao_pagos(): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => [
                    'order_list'  => [['order_sn' => 'A'], ['order_sn' => 'B'], ['order_sn' => 'C']],
                    'more'        => false,
                    'next_cursor' => '',
                ],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => [
                    'order_list' => [
                        ['order_sn' => 'A', 'order_status' => 'COMPLETED', 'total_amount' => 100, 'item_list' => [['model_quantity_purchased' => 1]]],
                        ['order_sn' => 'B', 'order_status' => 'CANCELLED', 'total_amount' => 999, 'item_list' => [['model_quantity_purchased' => 9]]],
                        ['order_sn' => 'C', 'order_status' => 'UNPAID',    'total_amount' => 50,  'item_list' => [['model_quantity_purchased' => 5]]],
                    ],
                ],
            ], 200),
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(100.0, $sum['revenue']);   // só o COMPLETED entra
        $this->assertSame(1, $sum['orders_count']);
        $this->assertSame(1, $sum['sold_quantity']);
    }

    public function test_sync_company_day_grava_metrica_diaria(): void
    {
        $this->fakeDoisPedidosOk();
        $company = $this->companyComToken();

        $metric = app(ShopeeService::class)->syncCompanyDay($company, '2026-07-01');

        $this->assertNotNull($metric);
        $this->assertDatabaseHas('shopee_metrics', [
            'company_id'     => $company->id,
            'reference_date' => '2026-07-01 00:00:00',
            'orders_count'   => 2,
            'sold_quantity'  => 3,
        ]);
        $this->assertSame('150.00', (string) $metric->revenue); // cast decimal:2
    }

    public function test_sync_company_day_sem_pedidos_nao_grava_linha(): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => ['order_list' => [], 'more' => false, 'next_cursor' => ''],
            ], 200),
        ]);
        $company = $this->companyComToken();

        $metric = app(ShopeeService::class)->syncCompanyDay($company, '2026-07-01');

        $this->assertNull($metric);
        $this->assertDatabaseCount('shopee_metrics', 0);
    }

    public function test_leitura_sem_token_lanca_sem_bater_na_api(): void
    {
        Http::fake(); // qualquer chamada HTTP faria o teste falhar
        $company = Company::factory()->create(); // sem shopeeToken

        try {
            app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');
            $this->fail('deveria ter lançado RuntimeException por falta de token');
        } catch (\RuntimeException) {
            // esperado — get() aborta antes de qualquer request
        }

        Http::assertNothingSent();
    }
}
