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
 * pedidos→detalhe do `fetchOrdersSummary` e a gravação diária em
 * `shopee_metrics` via `syncCompanyDay`.
 *
 * ⚠️ Quick 261006-fac (2026-10-06) — a REGRA MUDOU e estes testes travam a nova.
 * Antes o faturamento era a soma de `total_amount` dos pedidos cujo status não
 * fosse UNPAID|CANCELLED|IN_CANCEL. Agora é a régua do painel da Shopee:
 *
 *     revenue = Σ (pedidos com `pay_time`) Σ (itens) preço_com_desconto × qtd
 *
 * sem frete, sem filtro de status e sem descontar item cancelado/devolvido.
 * Medido contra a planilha manual do time em setembro/2026: EDUMAC PARTS #144 e
 * CAMILLO FILIAL RS #358 fecham ao centavo; pior resto GRAN BELO #212 (+5,53%).
 * Na CAMILLO MATRIZ #1 em 30/09 o filtro de status descartava R$ 1.434,94 de
 * pedidos pagos e cancelados em seguida (painel R$ 8.953,89 contra os
 * R$ 6.953,31 que tínhamos gravado).
 *
 * ⚠️ Quick 261006-j44 (2026-10-06) — a régua ganhou o DESCONTO DO CUPOM DO
 * VENDEDOR, que fechou o resíduo "sempre pra cima" do 261006-fac:
 *
 *     revenue = Σ (pagos) [ Σ (itens) preço_com_desconto × qtd ] − voucher_from_seller
 *
 * Estes testes continuam travando a régua de ITENS (o cupom é 0 em todos eles —
 * `fakePedidos` faz o lote de escrow devolver vazio). O comportamento do cupom
 * em si fica em `tests/Feature/Quick261006J44/CupomDoVendedorNoFaturamentoTest`.
 *
 * ⚠️ O lote `get_escrow_detail_batch` PRECISA estar fakeado em todo cenário com
 *    pedido pago: sem isso o teste tenta rede real, o `catch` do
 *    `vouchersDoVendedor` engole e o caso vira lento e dependente de DNS.
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

    /**
     * Monta o trio lista→detalhe→escrow. Cada item de `$pedidos` é o pedido cru
     * como a Shopee devolve no `get_order_detail` (precisa trazer `order_sn`).
     *
     * O lote de escrow devolve LISTA VAZIA de propósito: aqui o cupom do
     * vendedor é sempre 0, para que estes casos afirmem só a régua de itens.
     * Fakear é obrigatório — ver o aviso no docblock da classe.
     */
    private function fakePedidos(array $pedidos): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => [
                    'order_list'  => array_map(fn ($p) => ['order_sn' => $p['order_sn']], $pedidos),
                    'more'        => false,
                    'next_cursor' => '',
                ],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => ['order_list' => $pedidos],
            ], 200),
            '*get_escrow_detail_batch*' => Http::response([
                'response' => [], // nenhum cupom do vendedor neste cenário
            ], 200),
        ]);
    }

    /**
     * Dois pedidos pagos: R$ 100 (2 itens de R$ 50) + R$ 50 (1 item de R$ 50).
     * O `total_amount` vai junto de propósito, para provar que ele é ignorado.
     */
    private function fakeDoisPedidosOk(): void
    {
        $this->fakePedidos([
            [
                'order_sn'     => 'A',
                'order_status' => 'COMPLETED',
                'pay_time'     => 1751337600,
                'total_amount' => 118.90, // itens + frete — NÃO é o que entra
                'item_list'    => [
                    ['model_discounted_price' => 50.0, 'model_quantity_purchased' => 2],
                ],
            ],
            [
                'order_sn'     => 'B',
                'order_status' => 'COMPLETED',
                'pay_time'     => 1751341200,
                'total_amount' => 61.40,
                'item_list'    => [
                    ['model_discounted_price' => 50.0, 'model_quantity_purchased' => 1],
                ],
            ],
        ]);
    }

    public function test_fetch_orders_summary_agrega_itens_e_contagens(): void
    {
        $this->fakeDoisPedidosOk();
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(150.0, $sum['revenue']);   // 50×2 + 50×1 — sem frete
        $this->assertSame(2, $sum['orders_count']);
        $this->assertSame(3, $sum['sold_quantity']); // 2 + 1
    }

    /**
     * Era `test_fetch_orders_summary_ignora_cancelados_e_nao_pagos`. O que o
     * status decide mudou: só o `pay_time` tira ou põe o pedido no faturamento.
     */
    public function test_fetch_orders_summary_conta_pago_mesmo_cancelado_e_ignora_unpaid(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'A', 'order_status' => 'COMPLETED', 'pay_time' => 1751337600,
                'total_amount' => 100, 'item_list' => [['model_discounted_price' => 100.0, 'model_quantity_purchased' => 1]],
            ],
            [
                // Pago e cancelado pelo comprador depois — o painel conta.
                'order_sn' => 'B', 'order_status' => 'CANCELLED', 'pay_time' => 1751338600,
                'total_amount' => 999, 'item_list' => [['model_discounted_price' => 20.0, 'model_quantity_purchased' => 2]],
            ],
            [
                // Nunca foi pago: `pay_time` ausente.
                'order_sn' => 'C', 'order_status' => 'UNPAID', 'pay_time' => '',
                'total_amount' => 50, 'item_list' => [['model_discounted_price' => 10.0, 'model_quantity_purchased' => 5]],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(140.0, $sum['revenue']);   // 100 (A) + 40 (B cancelado) — C fora
        $this->assertSame(2, $sum['orders_count']);
        $this->assertSame(3, $sum['sold_quantity']); // 1 + 2
    }

    // ─── Quick 261006-fac: os casos que a regra nova precisa afirmar ─────────

    /**
     * CAMILLO MATRIZ #1, 30/09/2026: dois pedidos pagos e cancelados em seguida
     * pelo comprador (R$ 1.398,13 e R$ 36,81 = R$ 1.434,94) que o filtro de
     * status antigo descartava. Painel R$ 8.953,89; nosso valor gravado era
     * R$ 6.953,31.
     */
    public function test_pedido_pago_e_cancelado_depois_entra_no_faturamento(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'CANC1', 'order_status' => 'CANCELLED', 'pay_time' => 1759190400,
                'total_amount' => 1500.00,
                'item_list' => [['model_discounted_price' => 1398.13, 'model_quantity_purchased' => 1]],
            ],
            [
                'order_sn' => 'CANC2', 'order_status' => 'IN_CANCEL', 'pay_time' => 1759194000,
                'total_amount' => 42.00,
                'item_list' => [['model_discounted_price' => 36.81, 'model_quantity_purchased' => 1]],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(1434.94, $sum['revenue'], 'pago conta; cancelamento posterior não tira');
        $this->assertSame(2, $sum['orders_count']);
    }

    /** Sem `pay_time` não houve pagamento — fora do faturamento, mesmo com itens. */
    public function test_pedido_unpaid_sem_pay_time_nao_entra(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'U1', 'order_status' => 'UNPAID',
                'total_amount' => 500.00, // sem a chave `pay_time`
                'item_list' => [['model_discounted_price' => 480.00, 'model_quantity_purchased' => 1]],
            ],
            [
                'order_sn' => 'U2', 'order_status' => 'UNPAID', 'pay_time' => 0, // zero também é "não pago"
                'total_amount' => 300.00,
                'item_list' => [['model_discounted_price' => 290.00, 'model_quantity_purchased' => 1]],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(0.0, $sum['revenue']);
        $this->assertSame(0, $sum['orders_count']);
        $this->assertSame(0, $sum['sold_quantity']);
    }

    /**
     * Caso real da ITUFARMA: um pedido com `total_amount` R$ 31,42 contra
     * R$ 48,46 de item — 54% de diferença. Vale o ITEM.
     */
    public function test_vale_a_soma_dos_itens_quando_total_amount_diverge(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'IT1', 'order_status' => 'COMPLETED', 'pay_time' => 1759190400,
                'total_amount' => 31.42, // abaixo do item (frete/promoção embutidos)
                'item_list' => [['model_discounted_price' => 48.46, 'model_quantity_purchased' => 1]],
            ],
            [
                'order_sn' => 'IT2', 'order_status' => 'COMPLETED', 'pay_time' => 1759194000,
                'total_amount' => 200.00, // acima do item (frete pago pelo cliente)
                'item_list' => [['model_discounted_price' => 150.00, 'model_quantity_purchased' => 1]],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(198.46, $sum['revenue'], '48,46 + 150,00 — total_amount (231,42) seria o bug');
    }

    /**
     * Descontar `cancelled_qty`/`returned_qty` PASSA DO ALVO em 16,6% (medido em
     * 06/10/2026). O painel conta o pedido pago por inteiro.
     */
    public function test_item_cancelado_ou_devolvido_nao_e_descontado(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'D1', 'order_status' => 'COMPLETED', 'pay_time' => 1751337600,
                'total_amount' => 310.00,
                'item_list' => [
                    ['model_discounted_price' => 100.0, 'model_quantity_purchased' => 3, 'cancelled_qty' => 1],
                    ['model_discounted_price' => 25.0,  'model_quantity_purchased' => 2, 'returned_qty'  => 2],
                ],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(350.0, $sum['revenue'], '100×3 + 25×2 cheios — descontar daria 200,00');
        $this->assertSame(5, $sum['sold_quantity']);
    }

    /** Pedido com 2 itens e quantidade > 1: soma preço × quantidade de cada um. */
    public function test_pedido_com_dois_itens_soma_preco_vezes_quantidade(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'M1', 'order_status' => 'SHIPPED', 'pay_time' => 1751337600,
                'total_amount' => 95.00,
                'item_list' => [
                    ['model_discounted_price' => 25.50, 'model_quantity_purchased' => 2], // 51,00
                    ['model_discounted_price' => 10.25, 'model_quantity_purchased' => 3], // 30,75
                ],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(81.75, $sum['revenue']);
        $this->assertSame(1, $sum['orders_count']);
        $this->assertSame(5, $sum['sold_quantity']);
    }

    /**
     * Os três números saem do mesmo laço e têm que ficar coerentes: `orders_count`
     * conta os pedidos PAGOS e `sold_quantity`, as quantidades DELES.
     */
    public function test_orders_count_e_sold_quantity_coerentes_com_a_regra_nova(): void
    {
        $this->fakePedidos([
            [
                'order_sn' => 'P1', 'order_status' => 'COMPLETED', 'pay_time' => 1751337600,
                'total_amount' => 10,
                'item_list' => [
                    ['model_discounted_price' => 30.0, 'model_quantity_purchased' => 2],
                    ['model_discounted_price' => 15.0, 'model_quantity_purchased' => 1],
                ],
            ],
            [
                'order_sn' => 'P2', 'order_status' => 'CANCELLED', 'pay_time' => 1751341200,
                'total_amount' => 10,
                'item_list' => [['model_discounted_price' => 5.0, 'model_quantity_purchased' => 4]],
            ],
            [
                'order_sn' => 'P3', 'order_status' => 'UNPAID',
                'total_amount' => 10,
                'item_list' => [['model_discounted_price' => 1000.0, 'model_quantity_purchased' => 9]],
            ],
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(95.0, $sum['revenue']);    // 60 + 15 + 20
        $this->assertSame(2, $sum['orders_count']);  // P1 e P2 (pagos); P3 fora
        $this->assertSame(7, $sum['sold_quantity']); // 2 + 1 + 4 — as 9 do P3 fora
    }

    // ─── Gravação diária (segue valendo) ────────────────────────────────────

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

    /** A janela do dia é BRT (−03:00) — dos cinco fusos testados só este fecha. */
    public function test_janela_do_dia_vai_em_brt(): void
    {
        $this->fakeDoisPedidosOk();
        $company = $this->companyComToken();

        app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'get_order_list')) {
                return false;
            }

            return (int) $request['time_from'] === strtotime('2026-07-01 00:00:00 -0300')
                && (int) $request['time_to'] === strtotime('2026-07-01 23:59:59 -0300');
        });
    }

    /** `pay_time` precisa ser pedido no detalhe, senão a regra nova não tem base. */
    public function test_detalhe_pede_pay_time_e_item_list(): void
    {
        $this->fakeDoisPedidosOk();
        $company = $this->companyComToken();

        app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'get_order_detail')) {
                return false;
            }

            $campos = (string) $request['response_optional_fields'];

            return str_contains($campos, 'pay_time') && str_contains($campos, 'item_list');
        });
    }
}
