<?php

namespace Tests\Feature\Mcp;

use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Models\ShopeeToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `shopee` — dados das lojas conectadas, ao vivo na API da Shopee (mockada
 * aqui), mais o `resumo` do banco, conferido contra o /dashboard/shopee.
 */
class ShopeeToolTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    private const PARTNER_ERP = 111111;

    private const PARTNER_ADS = 222222;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Nada de chamada de verdade à Shopee: URL sem fake estoura.
        Http::preventStrayRequests();

        config([
            'services.shopee.host'                 => 'https://partner.shopeemobile.com',
            'services.shopee.verify_ssl'           => false,
            'services.shopee.apps.erp.partner_id'  => self::PARTNER_ERP,
            'services.shopee.apps.erp.partner_key' => 'shpk_erp_teste',
            'services.shopee.apps.ads.partner_id'  => self::PARTNER_ADS,
            'services.shopee.apps.ads.partner_key' => 'shpk_ads_teste',
        ]);
    }

    // ═══ Apoio ═══

    /** Loja com os apps pedidos conectados (token válido por 3 h). */
    private function lojaShopee(string $nome, array $apps = ['erp'], string $shopId = '900001', string $status = 'active'): Company
    {
        $loja = Company::factory()->create(['name' => $nome, 'active' => true]);

        foreach ($apps as $app) {
            ShopeeToken::create([
                'company_id'        => $loja->id,
                'app'               => $app,
                'shop_id'           => $shopId,
                'access_token'      => "atk_{$app}",
                'refresh_token'     => "rtk_{$app}",
                'expires_at'        => now()->addHours(3),
                'last_refreshed_at' => now(),
                'status'            => $status,
                'connected_at'      => now()->subMonth(),
            ]);
        }

        return $loja;
    }

    /** Resposta v2 da Shopee com sucesso. */
    private function ok(array $response): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => '', 'message' => '', 'request_id' => 'r1', 'response' => $response]);
    }

    private function erro(string $codigo, string $mensagem): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['error' => $codigo, 'message' => $mensagem, 'request_id' => 'r1']);
    }

    /** Chama a ferramenta e devolve o JSON da resposta (vai como texto). */
    private function shopee(User $usuario, array $argumentos = []): array
    {
        $resultado = $this->rpc($usuario, 'tools/call', ['name' => 'shopee', 'arguments' => (object) $argumentos])
            ->assertOk()
            ->json('result');

        $this->assertFalse(
            $resultado['isError'] ?? false,
            'A ferramenta respondeu erro: '.json_encode($resultado['content'] ?? null, JSON_UNESCAPED_UNICODE)
        );

        return json_decode($resultado['content'][0]['text'], true);
    }

    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true]);
    }

    // ═══ Acesso e lojas ═══

    public function test_qualquer_usuario_logado_ve_a_ferramenta_e_as_lojas_conectadas(): void
    {
        $completa = $this->lojaShopee('Loja Completa', ['erp', 'ads'], '700001');
        $this->lojaShopee('Loja Só ERP', ['erp'], '700002');
        $this->lojaShopee('Loja Revogada', ['erp'], '700003', 'revoked');
        ShopeeMetric::create(['company_id' => $completa->id, 'reference_date' => '2026-10-12', 'revenue' => 10]);

        $consultor = $this->consultor();
        $this->assertContains('shopee', $this->ferramentasVisiveis($consultor));

        $resposta = $this->shopee($consultor);

        $this->assertSame(2, $resposta['total']);
        $this->assertSame(['Loja Completa', 'Loja Só ERP'], array_column($resposta['lojas'], 'nome'));
        $this->assertSame('active', $resposta['lojas'][0]['ads']['status']);
        $this->assertNull($resposta['lojas'][1]['ads']);
        $this->assertSame('2026-10-12', $resposta['lojas'][0]['ultimo_dia_sincronizado']);
        $this->assertArrayHasKey('vendas', $resposta['dados']);
        $this->assertStringContainsString('visitantes', $resposta['fora_da_api']);

        // Credencial nunca sai.
        $this->assertStringNotContainsString('atk_', json_encode($resposta));
    }

    public function test_loja_por_nome_id_ou_shop_id_e_ambiguidade(): void
    {
        $azul = $this->lojaShopee('Casa Azul', ['erp'], '800001');
        $this->lojaShopee('Casa Verde', ['erp'], '800002');
        Http::fake(['*' => $this->ok(['shop_name' => 'casa'])]);
        $consultor = $this->consultor();

        foreach (['Azul', (string) $azul->id, '800001'] as $filtro) {
            $this->assertSame($azul->id, $this->shopee($consultor, ['dado' => 'loja', 'loja' => $filtro])['loja']['loja_id']);
        }

        $this->assertStringContainsString('Mais de uma loja', $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'loja', 'loja' => 'Casa']));
        $this->assertStringContainsString('Nenhuma loja', $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'loja', 'loja' => 'Inexistente']));
        $this->assertStringContainsString('Informe a "loja"', $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'pedidos']));
    }

    // ═══ Resumo (banco) ═══

    public function test_resumo_e_o_mesmo_numero_do_dashboard_shopee(): void
    {
        $a = $this->lojaShopee('Loja A', ['erp'], '1');
        $b = $this->lojaShopee('Loja B', ['erp'], '2');
        foreach ([
            [$a, '2026-09-01', 1000.10, 10, 12, 50.00, 400.0],
            [$a, '2026-09-02', 500.00, 4, 5, null, null],
            [$b, '2026-09-01', 3000.00, 20, 30, 120.00, 900.0],
            [$b, '2026-10-01', 9999.00, 1, 1, null, null], // fora do período
        ] as [$loja, $dia, $fat, $ped, $itens, $gasto, $gmvAds]) {
            ShopeeMetric::create([
                'company_id' => $loja->id, 'reference_date' => $dia, 'revenue' => $fat,
                'orders_count' => $ped, 'sold_quantity' => $itens,
                'ad_expense' => $gasto, 'ad_broad_gmv' => $gmvAds,
            ]);
        }

        $resposta = $this->shopee($this->consultor(), ['dado' => 'resumo', 'de' => '2026-09-01', 'ate' => '2026-09-30']);

        $tela = $this->actingAs($this->admin())
            ->get('/dashboard/shopee?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->viewData('page')['props'];

        // assertEquals: o JSON da resposta perde o ".0" (170.0 vira 170).
        $this->assertEquals($tela['kpis']['revenue'], $resposta['faturamento']);
        $this->assertSame($tela['kpis']['orders'], $resposta['pedidos']);
        $this->assertSame($tela['kpis']['items'], $resposta['itens_vendidos']);
        $this->assertEquals($tela['kpis']['ad_spend'], $resposta['ads']['gasto']);
        $this->assertEquals($tela['kpis']['tacos'], $resposta['ads']['tacos']);
        $this->assertSame(['Loja B', 'Loja A'], array_column($resposta['por_loja'], 'nome'));
        $this->assertCount(2, $resposta['por_dia']);

        $soA = $this->shopee($this->consultor(), ['dado' => 'resumo', 'loja' => 'Loja A', 'de' => '2026-09-01', 'ate' => '2026-09-30']);
        $this->assertSame(1500.1, $soA['faturamento']);
        $this->assertArrayNotHasKey('por_loja', $soA);
    }

    // ═══ Produtos ═══

    public function test_produtos_junta_cadastro_e_metricas_e_pagina_pelo_offset_da_shopee(): void
    {
        $this->lojaShopee('Loja Produtos');
        Http::fake([
            '*/api/v2/product/get_item_list*' => $this->ok([
                'item' => [['item_id' => 11, 'item_status' => 'NORMAL'], ['item_id' => 22, 'item_status' => 'NORMAL']],
                'total_count' => 5, 'has_next_page' => true, 'next_offset' => 2,
            ]),
            '*/api/v2/product/get_item_base_info*' => $this->ok(['item_list' => [
                ['item_id' => 11, 'item_name' => 'Tapete Pet', 'item_sku' => 'TP-1', 'item_status' => 'NORMAL',
                    'price_info' => [['current_price' => 49.9, 'original_price' => 59.9]],
                    'stock_info_v2' => ['summary_info' => ['total_available_stock' => 30]], 'has_model' => false],
                ['item_id' => 22, 'item_name' => 'Coleira', 'item_status' => 'NORMAL', 'has_model' => true],
            ]]),
            '*/api/v2/product/get_item_extra_info*' => $this->ok(['item_list' => [
                ['item_id' => 11, 'sale' => 120, 'views' => 5400, 'likes' => 80, 'rating_star' => 4.8123, 'comment_count' => 40],
                ['item_id' => 22, 'sale' => 3, 'views' => 900, 'likes' => 5, 'rating_star' => 4, 'comment_count' => 1],
            ]]),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'produtos', 'loja' => 'Loja Produtos', 'limite' => 2]);

        $this->assertSame(5, $resposta['total']);
        $this->assertSame(['item_id' => 11, 'nome' => 'Tapete Pet', 'sku' => 'TP-1', 'preco' => 49.9, 'estoque' => 30, 'vendas' => 120, 'visualizacoes' => 5400, 'nota' => 4.81],
            array_intersect_key($resposta['itens'][0], array_flip(['item_id', 'nome', 'sku', 'preco', 'estoque', 'vendas', 'visualizacoes', 'nota'])));
        $this->assertTrue($resposta['itens'][1]['tem_variacoes']);
        $this->assertNotNull($resposta['proximo_cursor']);

        $this->shopee($this->consultor(), ['dado' => 'produtos', 'loja' => 'Loja Produtos', 'limite' => 2, 'cursor' => $resposta['proximo_cursor']]);
        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'get_item_list') && $r['offset'] == 2 && $r['item_status'] === 'NORMAL');
    }

    public function test_produtos_com_busca_e_ordenar_varre_a_loja(): void
    {
        $this->lojaShopee('Loja Ranking');
        Http::fake([
            '*/api/v2/product/get_item_list*' => $this->ok([
                'item' => [['item_id' => 1], ['item_id' => 2], ['item_id' => 3]], 'total_count' => 3, 'has_next_page' => false,
            ]),
            '*/api/v2/product/get_item_base_info*' => $this->ok(['item_list' => [
                ['item_id' => 1, 'item_name' => 'Ração Gato'], ['item_id' => 2, 'item_name' => 'Ração Cão'], ['item_id' => 3, 'item_name' => 'Areia Gato'],
            ]]),
            '*/api/v2/product/get_item_extra_info*' => $this->ok(['item_list' => [
                ['item_id' => 1, 'views' => 10], ['item_id' => 2, 'views' => 300], ['item_id' => 3, 'views' => 50],
            ]]),
        ]);
        $consultor = $this->consultor();

        $ranking = $this->shopee($consultor, ['dado' => 'produtos', 'loja' => 'Loja Ranking', 'ordenar' => 'visualizacoes']);
        $this->assertSame([2, 3, 1], array_column($ranking['itens'], 'item_id'));

        $gato = $this->shopee($consultor, ['dado' => 'produtos', 'loja' => 'Loja Ranking', 'busca' => 'gato', 'ordenar' => 'visualizacoes']);
        $this->assertSame([3, 1], array_column($gato['itens'], 'item_id'));

        // A segunda consulta usou a varredura em cache.
        Http::assertSentCount(3);
    }

    // ═══ Pedidos e vendas ═══

    public function test_pedidos_traduz_o_pedido_e_nao_pede_endereco_nem_cpf(): void
    {
        $this->lojaShopee('Loja Pedidos');
        Http::fake([
            '*/api/v2/order/get_order_list*' => $this->ok(['order_list' => [['order_sn' => 'SN1']], 'more' => false, 'next_cursor' => '']),
            '*/api/v2/order/get_order_detail*' => $this->ok(['order_list' => [[
                'order_sn' => 'SN1', 'order_status' => 'SHIPPED', 'create_time' => 1760000000, 'pay_time' => 1760000600,
                'total_amount' => 89.9, 'buyer_username' => 'comprador_x', 'shipping_carrier' => 'Shopee Xpress',
                'item_list' => [['item_id' => 11, 'item_name' => 'Tapete', 'model_quantity_purchased' => 2, 'model_discounted_price' => 39.95]],
            ]]]),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'pedidos', 'loja' => 'Loja Pedidos', 'de' => '2026-10-01', 'ate' => '2026-10-10']);

        $pedido = $resposta['itens'][0];
        $this->assertSame('SHIPPED', $pedido['status']);
        $this->assertSame('comprador_x', $pedido['comprador']);
        $this->assertSame(2, $pedido['itens'][0]['quantidade']);
        $this->assertSame('2025-10-09 05:53', $pedido['criado_em']); // Unix em horário de Brasília
        $this->assertNull($resposta['proximo_cursor']);

        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'get_order_detail')
            && ! str_contains($r['response_optional_fields'], 'recipient_address')
            && ! str_contains($r['response_optional_fields'], 'cpf'));

        $this->assertStringContainsString('máximo é 15 dias',
            $this->erroDaFerramenta($this->consultor(), 'shopee', ['dado' => 'pedidos', 'loja' => 'Loja Pedidos', 'de' => '2026-09-01', 'ate' => '2026-09-30']));
    }

    public function test_vendas_monta_o_funil_feito_pago_e_a_recompra(): void
    {
        $this->lojaShopee('Loja Funil');
        $dia = 1759300000; // 01/10/2025
        $pedido = fn (string $sn, string $comprador, ?int $pago, string $status, int $qtd = 1, int $item = 11) => [
            'order_sn' => $sn, 'buyer_user_id' => $comprador, 'pay_time' => $pago, 'order_status' => $status,
            'item_list' => [['item_id' => $item, 'model_quantity_purchased' => $qtd, 'model_discounted_price' => 10.0]],
        ];
        Http::fake([
            '*/api/v2/order/get_order_list*' => $this->ok(['order_list' => array_map(fn ($sn) => ['order_sn' => $sn], ['A1', 'A2', 'B1', 'C1', 'D1']), 'more' => false]),
            '*/api/v2/order/get_order_detail*' => $this->ok(['order_list' => [
                $pedido('A1', '501', $dia, 'COMPLETED', 2),
                $pedido('A2', '501', $dia + 3 * 86400, 'COMPLETED', 1, 22),
                $pedido('B1', '502', $dia, 'CANCELLED'),   // pago e cancelado depois: conta como pago
                $pedido('C1', '503', null, 'UNPAID'),       // não pago: só "pedido feito"
                $pedido('D1', '504', $dia, 'SHIPPED', 3),
            ]]),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'vendas', 'loja' => 'Loja Funil', 'de' => '2025-10-01', 'ate' => '2025-10-31']);

        $this->assertEquals(['pedidos' => 5, 'compradores' => 4, 'unidades' => 8, 'produtos_distintos' => 2, 'vendas' => 80], $resposta['pedido_feito']);
        $this->assertEquals(['pedidos' => 4, 'compradores' => 3, 'unidades' => 7, 'produtos_distintos' => 2, 'vendas' => 70], $resposta['produto_pago']);
        $this->assertSame(1, $resposta['recompra']['compradores_com_2_ou_mais']);
        $this->assertSame(33.33, $resposta['recompra']['taxa_de_recompra_pct']);
        $this->assertEquals(3, $resposta['recompra']['media_de_dias_entre_pedidos']);
        $this->assertSame(2, $resposta['pedidos_por_status']['COMPLETED']);

        // Uma lista por dia (31, em paralelo) + um lote de detalhe.
        Http::assertSentCount(32);
    }

    public function test_vendas_segue_o_cursor_do_dia_com_mais_de_cem_pedidos(): void
    {
        $this->lojaShopee('Loja Cheia');
        Http::fake([
            '*/api/v2/order/get_order_list*' => fn (RequisicaoHttp $r) => $r['cursor'] === ''
                ? $this->ok(['order_list' => [['order_sn' => 'P1']], 'more' => true, 'next_cursor' => 'c2'])
                : $this->ok(['order_list' => [['order_sn' => 'P2']], 'more' => false, 'next_cursor' => '']),
            '*/api/v2/order/get_order_detail*' => $this->ok(['order_list' => [
                ['order_sn' => 'P1', 'buyer_user_id' => 1, 'pay_time' => 1759300000, 'order_status' => 'COMPLETED', 'item_list' => []],
                ['order_sn' => 'P2', 'buyer_user_id' => 2, 'pay_time' => 1759300000, 'order_status' => 'COMPLETED', 'item_list' => []],
            ]]),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'vendas', 'loja' => 'Loja Cheia', 'de' => '2025-10-01', 'ate' => '2025-10-02']);

        // 2 dias: 2 primeiras páginas + 2 segundas (cursor c2) + 1 detalhe.
        $this->assertSame(2, $resposta['pedido_feito']['pedidos']);
        Http::assertSentCount(5);
        // A chamada em paralelo vai assinada como as outras.
        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'get_order_list') && $r['cursor'] === 'c2'
            && $r['partner_id'] == self::PARTNER_ERP && $r['shop_id'] == 900001 && strlen((string) $r['sign']) === 64);
    }

    public function test_carteira_comeca_na_pagina_1_da_shopee(): void
    {
        $this->lojaShopee('Loja Carteira');
        Http::fake(['*/api/v2/payment/get_wallet_transaction_list*' => $this->ok([
            'transaction_list' => [['create_time' => 1760000000, 'transaction_type' => 'ESCROW_VERIFIED_ADD', 'amount' => 35.19, 'current_balance' => 100]],
            'more' => true,
        ])]);
        $consultor = $this->consultor();

        $p1 = $this->shopee($consultor, ['dado' => 'carteira', 'loja' => 'Loja Carteira']);
        $this->shopee($consultor, ['dado' => 'carteira', 'loja' => 'Loja Carteira', 'cursor' => $p1['proximo_cursor']]);

        // Com 0 e com 1 a Shopee devolve a mesma página (medido em produção).
        Http::assertSent(fn (RequisicaoHttp $r) => $r['page_no'] == 1);
        Http::assertSent(fn (RequisicaoHttp $r) => $r['page_no'] == 2);
        Http::assertNotSent(fn (RequisicaoHttp $r) => $r['page_no'] == 0);
    }

    public function test_pedido_traz_o_financeiro_do_escrow(): void
    {
        $this->lojaShopee('Loja Escrow');
        Http::fake([
            '*/api/v2/order/get_order_detail*' => $this->ok(['order_list' => [['order_sn' => 'SN9', 'order_status' => 'COMPLETED', 'note' => 'embrulhar']]]),
            '*/api/v2/payment/get_escrow_detail*' => $this->ok(['order_sn' => 'SN9', 'order_income' => [
                'escrow_amount' => 70.5, 'commission_fee' => 12.0, 'service_fee' => 6.0, 'voucher_from_seller' => 5.0,
            ]]),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'pedido', 'loja' => 'Loja Escrow', 'pedido' => 'SN9']);

        $this->assertSame('embrulhar', $resposta['pedido']['observacao_do_comprador']);
        $this->assertSame(70.5, $resposta['financeiro']['repasse_ao_vendedor']);
        $this->assertSame(12, (int) $resposta['financeiro']['comissao']);
    }

    // ═══ Apps ERP × ADS e erros da Shopee ═══

    public function test_ads_usa_o_app_ads_e_calcula_o_tacos_com_o_faturamento_do_banco(): void
    {
        $loja = $this->lojaShopee('Loja Ads', ['erp', 'ads']);
        $this->lojaShopee('Loja Sem Ads', ['erp'], '999');
        $ontem = now('America/Sao_Paulo')->subDay();
        ShopeeMetric::create(['company_id' => $loja->id, 'reference_date' => $ontem->toDateString(), 'revenue' => 2000]);

        Http::fake([
            '*/api/v2/ads/get_all_cpc_ads_daily_performance*' => $this->ok([[
                'date' => $ontem->format('d-m-Y'), 'impression' => 1000, 'clicks' => 50, 'expense' => 100.0,
                'broad_gmv' => 800.0, 'direct_gmv' => 500.0, 'broad_order' => 8, 'direct_order' => 5, 'broad_conversions' => 8,
            ]]),
            '*/api/v2/ads/get_total_balance*'    => $this->ok(['total_balance' => 350.0]),
            '*/api/v2/ads/get_shop_toggle_info*' => $this->ok(['auto_top_up' => true]),
        ]);
        $consultor = $this->consultor();

        // Até HOJE: no SQLite dos testes a coluna `date` guarda hora e o
        // whereBetween perderia o último dia (no MariaDB é DATE e não perde).
        $resposta = $this->shopee($consultor, ['dado' => 'ads', 'loja' => 'Loja Ads', 'de' => $ontem->toDateString(), 'ate' => $ontem->addDay()->toDateString()]);

        $this->assertSame(100, (int) $resposta['totais']['gasto']);
        $this->assertSame(8, (int) $resposta['totais']['roas']);
        $this->assertSame(0.05, $resposta['totais']['tacos']);
        $this->assertSame(350, (int) $resposta['saldo']['total_balance']);
        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'get_all_cpc_ads_daily_performance') && $r['partner_id'] == self::PARTNER_ADS);

        $this->assertStringContainsString('app ADS', $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'ads', 'loja' => 'Loja Sem Ads']));
        $this->assertStringContainsString('6 meses', $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'ads', 'loja' => 'Loja Ads', 'de' => '2020-01-01', 'ate' => '2020-01-05']));
    }

    public function test_permissao_negada_no_erp_tenta_o_app_ads_da_mesma_loja(): void
    {
        $this->lojaShopee('Loja Video', ['erp', 'ads']);
        Http::fake(['*/api/v2/video/get_overview_performance*' => fn (RequisicaoHttp $r) => $r['partner_id'] == self::PARTNER_ERP
            ? $this->erro('error_permission_denied', 'This app type has no permission to this API')
            : $this->ok(['views' => 1234])]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'api', 'loja' => 'Loja Video', 'caminho' => 'v2.video.get_overview_performance']);

        $this->assertSame('ADS', $resposta['app']);
        $this->assertSame(1234, $resposta['resposta']['views']);
        $this->assertSame('/api/v2/video/get_overview_performance', $resposta['caminho']);
    }

    public function test_quando_nenhum_app_tem_permissao_a_resposta_diz_que_o_dado_nao_existe_para_nos(): void
    {
        $this->lojaShopee('Loja Negada', ['erp', 'ads']);
        Http::fake(['*' => $this->erro('error_permission_denied', 'This app type has no permission to this API')]);

        $erro = $this->erroDaFerramenta($this->consultor(), 'shopee', ['dado' => 'api', 'loja' => 'Loja Negada', 'caminho' => '/api/v2/ams/get_shop_performance']);

        $this->assertStringContainsString('não libera /api/v2/ams/get_shop_performance', $erro);
        Http::assertSentCount(2);
    }

    public function test_erro_de_parametro_e_token_vencido_voltam_legiveis(): void
    {
        $this->lojaShopee('Loja Erros');
        Http::fake([
            '*/api/v2/logistics/get_tracking_info*' => $this->erro('error_param', 'order_sn is required'),
            '*/api/v2/shop/get_profile*'            => $this->erro('error_auth', 'Invalid access_token.'),
            '*/api/v2/shop/get_shop_info*'          => $this->erro('error_auth', 'Invalid access_token.'),
        ]);
        $consultor = $this->consultor();

        $this->assertStringContainsString('error_param — order_sn is required',
            $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'api', 'loja' => 'Loja Erros', 'caminho' => '/api/v2/logistics/get_tracking_info']));

        // Resposta composta: a parte recusada vira "erro" sem derrubar o resto.
        $loja = $this->shopee($consultor, ['dado' => 'loja', 'loja' => 'Loja Erros']);
        $this->assertStringContainsString('reconectar em /shopee-oauth', $loja['perfil']['erro']);
    }

    public function test_get_shop_info_responde_na_raiz_e_nao_volta_vazio(): void
    {
        $this->lojaShopee('Loja Raiz');
        Http::fake([
            '*/api/v2/shop/get_shop_info*' => Http::response(['error' => '', 'message' => '', 'request_id' => 'r', 'shop_name' => 'RAIZ OFICIAL', 'status' => 'NORMAL']),
            '*/api/v2/shop/get_profile*'   => $this->ok(['description' => 'loja de teste']),
        ]);

        $resposta = $this->shopee($this->consultor(), ['dado' => 'loja', 'loja' => 'Loja Raiz']);

        $this->assertSame(['shop_name' => 'RAIZ OFICIAL', 'status' => 'NORMAL'], $resposta['informacoes']);
        $this->assertSame('loja de teste', $resposta['perfil']['description']);
    }

    public function test_api_so_le_e_lista_os_endpoints_sem_caminho(): void
    {
        $this->lojaShopee('Loja Api');
        Http::fake();
        $consultor = $this->consultor();

        $this->assertArrayHasKey('Pedido e envio', $this->shopee($consultor, ['dado' => 'api'])['endpoints']);

        $this->assertStringContainsString('não é endpoint de leitura',
            $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'api', 'loja' => 'Loja Api', 'caminho' => '/api/v2/product/update_item']));
        $this->assertStringContainsString('fica fora do MCP',
            $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'api', 'loja' => 'Loja Api', 'caminho' => '/api/v2/public/get_shops_by_partner']));
        $this->assertStringContainsString('Caminho inválido',
            $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'api', 'loja' => 'Loja Api', 'caminho' => '/api/v2/auth/token/get']));
        $this->assertStringContainsString('Caminho inválido',
            $this->erroDaFerramenta($consultor, 'shopee', ['dado' => 'api', 'loja' => 'Loja Api', 'caminho' => 'https://evil.example/api']));

        Http::assertNothingSent();
    }
}
