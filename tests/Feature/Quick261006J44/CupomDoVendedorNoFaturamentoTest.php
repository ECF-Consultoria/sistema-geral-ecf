<?php

namespace Tests\Feature\Quick261006J44;

use App\Models\Company;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use App\Services\Shopee\ShopeeSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Quick 261006-j44 — o faturamento Shopee desconta o cupom do VENDEDOR.
 *
 * A régua de itens do 261006-fac deixou um resíduo pequeno e SEMPRE PARA CIMA
 * (o usuário conferiu contra a planilha: "a diferença é pouca e sempre pra
 * cima"). A causa é o `voucher_from_seller`, que o painel da Shopee desconta e
 * nós não descontávamos:
 *
 *     revenue = Σ (pagos) [ Σ (itens) preço_com_desconto × qtd ] − voucher_from_seller
 *
 * Medido na API real de produção, três lojas independentes:
 *
 *   | loja                      | alvo (painel)  | itens (antes)       | itens − cupom       |
 *   |---------------------------|----------------|---------------------|---------------------|
 *   | ITUFARMA1 #225, 30/09     | R$    603,72   |    609,13 (+0,90%)  | 603,72 (0,00%) ✔    |
 *   | CAMILLO MATRIZ #1, 30/09  | R$  8.953,89   |  9.133,89 (+2,01%)  | 8.983,89 (+0,34%)   |
 *   | DROSSI #217, setembro     | R$ 392.422,00  | 403.187,26 (+2,74%) | 391.537,81 (−0,23%) |
 *
 * O que estes testes travam, além do valor:
 * - `voucher_from_shopee` NÃO entra (descontar os dois passa do alvo);
 * - o lote é `POST get_escrow_detail_batch` em chunks de 50 — NUNCA pedido a
 *   pedido (a GENUINEAUTOMOTIVE faz ~980 pedidos/dia);
 * - falha do lote não quebra e não sai calada;
 * - `orders_count`/`sold_quantity` ficam idênticos ao 261006-fac.
 */
class CupomDoVendedorNoFaturamentoTest extends TestCase
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
     * Fake do `get_order_list`: devolve os `order_sn` da janela numa página só.
     */
    private function fakeListaDePedidos(array $pedidos)
    {
        return Http::response([
            'response' => [
                'order_list'  => array_map(fn ($p) => ['order_sn' => $p['order_sn']], $pedidos),
                'more'        => false,
                'next_cursor' => '',
            ],
        ], 200);
    }

    /**
     * Fake do `get_order_detail` que HONRA o chunk pedido — devolve só os
     * pedidos do `order_sn_list` daquela chamada. Sem isso um cenário de 120
     * pedidos contaria 360 (os 120 em cada uma das 3 chamadas) e nenhum teste de
     * chunk teria valor.
     */
    private function fakeDetalhePorChunk(array $pedidos): callable
    {
        $porSn = [];
        foreach ($pedidos as $p) {
            $porSn[$p['order_sn']] = $p;
        }

        return function ($request) use ($porSn) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $doChunk = [];
            foreach (explode(',', (string) ($query['order_sn_list'] ?? '')) as $sn) {
                if (isset($porSn[$sn])) {
                    $doChunk[] = $porSn[$sn];
                }
            }

            return Http::response(['response' => ['order_list' => $doChunk]], 200);
        };
    }

    /**
     * Fake do `get_escrow_detail_batch` que também honra o lote: devolve só as
     * linhas dos `order_sn` daquela chamada (linha malformada, sem `order_sn`,
     * passa sempre — é o que o serviço tem que ignorar sozinho).
     */
    private function fakeEscrowPorChunk(array $escrow): callable
    {
        return function ($request) use ($escrow) {
            $pedidos = (array) ($request->data()['order_sn_list'] ?? []);

            $doLote = array_values(array_filter($escrow, function ($linha) use ($pedidos) {
                $sn = $linha['escrow_detail']['order_sn'] ?? null;

                return $sn === null || in_array($sn, $pedidos, true);
            }));

            return Http::response(['response' => $doLote], 200);
        };
    }

    /**
     * Monta lista→detalhe→escrow.
     *
     * `$escrow` é a `response` crua do `get_escrow_detail_batch`: uma LISTA de
     * `{ escrow_detail: { order_sn, order_income: {...} } }` — exatamente a
     * forma sondada na API real. Quando `$escrow` é `null`, o lote devolve uma
     * resposta de ERRO (para o caso de falha).
     */
    private function fakeApi(array $pedidos, ?array $escrow = []): void
    {
        Http::fake([
            '*get_order_list*'          => $this->fakeListaDePedidos($pedidos),
            '*get_order_detail*'        => $this->fakeDetalhePorChunk($pedidos),
            '*get_escrow_detail_batch*' => $escrow === null
                ? Http::response(['error' => 'error_server', 'message' => 'internal error'], 500)
                : $this->fakeEscrowPorChunk($escrow),
        ]);
    }

    /** Linha do lote de escrow, na forma exata da API real. */
    private function linhaEscrow(string $orderSn, float $doVendedor, float $daShopee = 0.0): array
    {
        return [
            'escrow_detail' => [
                'order_sn'     => $orderSn,
                'order_income' => [
                    'escrow_amount'       => 0.0,
                    'voucher_from_seller' => $doVendedor,
                    'voucher_from_shopee' => $daShopee,
                ],
            ],
        ];
    }

    private function pedido(string $orderSn, float $preco, int $qtd = 1, string $status = 'COMPLETED', $payTime = 1759190400): array
    {
        return [
            'order_sn'     => $orderSn,
            'order_status' => $status,
            'pay_time'     => $payTime,
            'total_amount' => $preco * $qtd + 20, // frete embutido — nunca é o que entra
            'item_list'    => [
                ['model_discounted_price' => $preco, 'model_quantity_purchased' => $qtd],
            ],
        ];
    }

    // ─── O valor ────────────────────────────────────────────────────────────

    /** Caso-base: pedido pago com cupom do vendedor sai itens − cupom. */
    public function test_pedido_pago_com_cupom_do_vendedor_desconta_do_revenue(): void
    {
        $this->fakeApi(
            [$this->pedido('V1', 100.00)],
            [$this->linhaEscrow('V1', 15.00)],
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(85.0, $sum['revenue'], '100,00 de item − 15,00 de cupom do vendedor');
    }

    /**
     * ITUFARMA1 #225, 30/09/2026: alvo do painel R$ 603,72; a régua de itens
     * dava R$ 609,13 (+0,90%) e o cupom do vendedor de R$ 5,41 fecha AO CENTAVO.
     */
    public function test_alvo_medido_da_itufarma_fecha_ao_centavo(): void
    {
        $this->fakeApi(
            [
                $this->pedido('ITU1', 509.13),
                $this->pedido('ITU2', 100.00),
            ],
            [$this->linhaEscrow('ITU2', 5.41)],
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(603.72, $sum['revenue'], 'itens 609,13 − cupom 5,41 = alvo 603,72 do painel');
    }

    /**
     * ⛔ `voucher_from_shopee` é bancado pela plataforma — descontar os DOIS
     * passa do alvo (medido: ITUFARMA −1,76%, CAMILLO −3,20%).
     */
    public function test_cupom_da_shopee_nao_e_descontado(): void
    {
        $this->fakeApi(
            [$this->pedido('S1', 200.00)],
            [$this->linhaEscrow('S1', 10.00, 50.00)], // 50,00 da Shopee NÃO sai
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(190.0, $sum['revenue'], 'só os 10,00 do vendedor saem; descontar os dois daria 140,00');
    }

    /**
     * Caso da CAMILLO MATRIZ #1: todo pedido enviado tem cupom de R$ 30, mas os
     * pagos e cancelados DEPOIS voltam com `voucher_from_seller = 0` — a Shopee
     * para de reportar o cupom após o cancelamento. O pedido entra pelos itens,
     * sem desconto. É o resíduo irredutível de R$ 30,00; não tentar recuperar.
     */
    public function test_pedido_pago_e_cancelado_com_cupom_zero_entra_sem_desconto(): void
    {
        $this->fakeApi(
            [$this->pedido('CANC1', 1398.13, 1, 'CANCELLED')],
            [$this->linhaEscrow('CANC1', 0.0)], // a Shopee zera o cupom pós-cancelamento
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(1398.13, $sum['revenue'], 'pago conta inteiro; cupom 0 não desconta nada');
        $this->assertSame(1, $sum['orders_count']);
    }

    /** Lote devolve MENOS pedidos do que pedimos → os ausentes contam cupom 0. */
    public function test_pedido_ausente_do_lote_conta_cupom_zero(): void
    {
        $this->fakeApi(
            [
                $this->pedido('A1', 100.00),
                $this->pedido('A2', 200.00),
                $this->pedido('A3', 300.00),
            ],
            [$this->linhaEscrow('A2', 20.00)], // A1 e A3 nem aparecem na resposta
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(580.0, $sum['revenue'], '600,00 de itens − só os 20,00 do A2');
        $this->assertSame(3, $sum['orders_count']);
    }

    /** Linha do lote sem `order_sn` ou sem `escrow_detail` é ignorada, não quebra. */
    public function test_linha_malformada_do_lote_e_ignorada(): void
    {
        $this->fakeApi(
            [$this->pedido('M1', 100.00)],
            [
                ['escrow_detail' => ['order_income' => ['voucher_from_seller' => 99.0]]], // sem order_sn
                ['order_sn' => 'M1'],                                                     // sem escrow_detail
                $this->linhaEscrow('M1', 10.00),                                          // esta vale
            ],
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(90.0, $sum['revenue'], 'só a linha bem formada desconta');
    }

    /** UNPAID não entra nem no faturamento nem no lote de cupom. */
    public function test_pedido_nao_pago_fica_fora_do_lote_de_cupom(): void
    {
        $this->fakeApi(
            [
                $this->pedido('P1', 100.00),
                $this->pedido('U1', 500.00, 1, 'UNPAID', ''), // sem pay_time
            ],
            [$this->linhaEscrow('P1', 10.00)],
        );
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(90.0, $sum['revenue']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'get_escrow_detail_batch')) {
                return false;
            }

            return $request->data()['order_sn_list'] === ['P1'];
        });
    }

    // ─── O custo: lote, nunca pedido a pedido ───────────────────────────────

    /**
     * ⛔ O coração do quick: 120 pedidos pagos ⇒ **3** chamadas de lote
     * (chunk 50: 50+50+20), não 120. `get_escrow_detail` pedido a pedido seria
     * inviável — a GENUINEAUTOMOTIVE faz ~980 pedidos num dia normal.
     */
    public function test_cento_e_vinte_pedidos_pagos_fazem_tres_chamadas_de_lote(): void
    {
        $pedidos = [];
        $escrow  = [];

        for ($i = 1; $i <= 120; $i++) {
            $sn        = 'L' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $pedidos[] = $this->pedido($sn, 10.00);
            $escrow[]  = $this->linhaEscrow($sn, 1.00);
        }

        $this->fakeApi($pedidos, $escrow);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $chamadasDeLote = Http::recorded(
            fn ($request) => str_contains($request->url(), 'get_escrow_detail_batch')
        );

        $this->assertCount(
            3,
            $chamadasDeLote,
            '120 pedidos em chunks de 50 ⇒ 3 chamadas de lote; 120 chamadas seria get_escrow_detail pedido a pedido'
        );

        // O mesmo chunk do get_order_detail: uma chamada a mais por lote de 50.
        $this->assertCount(
            3,
            Http::recorded(fn ($request) => str_contains($request->url(), 'get_order_detail')),
            'o lote de escrow usa o MESMO chunk do detalhe — custo igual ao de hoje'
        );

        // 120 × 10,00 − 120 × 1,00
        $this->assertSame(1080.0, $sum['revenue']);
        $this->assertSame(120, $sum['orders_count']);
    }

    /** O lote vai com no máximo 50 `order_sn` por chamada. */
    public function test_lote_respeita_o_chunk_de_cinquenta(): void
    {
        $pedidos = [];
        for ($i = 1; $i <= 120; $i++) {
            $pedidos[] = $this->pedido('C' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 10.00);
        }

        $this->fakeApi($pedidos, []);
        $company = $this->companyComToken();

        app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $tamanhos = Http::recorded(fn ($request) => str_contains($request->url(), 'get_escrow_detail_batch'))
            ->map(fn ($par) => count($par[0]->data()['order_sn_list']))
            ->values()
            ->all();

        $this->assertSame([50, 50, 20], $tamanhos);
    }

    // ─── Falha do lote: sem desconto, mas aos gritos ────────────────────────

    /**
     * ⚠️ Se o lote falhar, o faturamento sai SEM o desconto — que é o defeito
     * conhecido que este quick corrige. Sair calado o reintroduz em silêncio,
     * então grita em `Log::error` (mesmo espírito da trava de truncamento do
     * 261006-dv3). E, acima de tudo, NÃO lança: o dia inteiro não pode cair por
     * causa do cupom.
     */
    public function test_falha_do_lote_sai_sem_desconto_grita_no_log_e_nao_lanca(): void
    {
        $this->fakeApi([$this->pedido('F1', 100.00)], null); // lote devolve HTTP 500
        $company = $this->companyComToken();

        Log::spy();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(100.0, $sum['revenue'], 'sem o desconto, mas o dia não se perde');
        $this->assertSame(1, $sum['orders_count']);

        Log::shouldHaveReceived('error')->withArgs(function ($message) use ($company) {
            return is_string($message)
                && str_contains($message, '[Shopee]')
                && str_contains($message, 'Cupom do vendedor')
                && str_contains($message, (string) $company->id);
        })->once();
    }

    /** Um lote que falha não contamina os outros: os demais seguem descontando. */
    public function test_falha_de_um_lote_nao_cala_os_outros(): void
    {
        $pedidos = [];
        for ($i = 1; $i <= 60; $i++) {
            $pedidos[] = $this->pedido('X' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 10.00);
        }

        // Primeira chamada do lote falha; a segunda responde normalmente.
        $chamada = 0;

        Http::fake([
            '*get_order_list*'   => $this->fakeListaDePedidos($pedidos),
            '*get_order_detail*' => $this->fakeDetalhePorChunk($pedidos),
            '*get_escrow_detail_batch*' => function ($request) use (&$chamada) {
                $chamada++;

                if ($chamada === 1) {
                    return Http::response(['error' => 'error_server', 'message' => 'boom'], 500);
                }

                return Http::response(['response' => array_map(
                    fn ($sn) => $this->linhaEscrow($sn, 1.00),
                    (array) ($request->data()['order_sn_list'] ?? [])
                )], 200);
            },
        ]);
        $company = $this->companyComToken();

        Log::spy();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        // 60 × 10,00 = 600,00; o 1º lote (50) perdeu o desconto, o 2º (10) desconta 10 × 1,00
        $this->assertSame(590.0, $sum['revenue']);

        Log::shouldHaveReceived('error')->once();
    }

    // ─── O que NÃO muda ─────────────────────────────────────────────────────

    /**
     * `orders_count` e `sold_quantity` são idênticos ao 261006-fac — o cupom
     * mexe só no `revenue`. Mesmo cenário do teste de coerência de lá, agora com
     * cupom em cima.
     */
    public function test_orders_count_e_sold_quantity_nao_mudam_com_o_cupom(): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => [
                    'order_list'  => [['order_sn' => 'P1'], ['order_sn' => 'P2'], ['order_sn' => 'P3']],
                    'more'        => false,
                    'next_cursor' => '',
                ],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => ['order_list' => [
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
                ]],
            ], 200),
            '*get_escrow_detail_batch*' => Http::response([
                'response' => [
                    $this->linhaEscrow('P1', 5.00),
                    $this->linhaEscrow('P2', 2.00),
                ],
            ], 200),
        ]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        $this->assertSame(88.0, $sum['revenue']);    // 95,00 do fac − 7,00 de cupom
        $this->assertSame(2, $sum['orders_count']);  // idêntico ao 261006-fac
        $this->assertSame(7, $sum['sold_quantity']); // idêntico ao 261006-fac
    }

    /** Sem pedido pago na janela, nenhum lote de cupom é chamado. */
    public function test_dia_sem_pedido_pago_nao_chama_o_lote(): void
    {
        $this->fakeApi([$this->pedido('U1', 500.00, 1, 'UNPAID', '')]);
        $company = $this->companyComToken();

        $sum = app(ShopeeService::class)->fetchOrdersSummary($company, '2026-09-30', '2026-09-30');

        $this->assertSame(0.0, $sum['revenue']);
        $this->assertCount(
            0,
            Http::recorded(fn ($request) => str_contains($request->url(), 'get_escrow_detail_batch')),
            'lote vazio não vira chamada'
        );
    }

    // ─── T1: o post() ───────────────────────────────────────────────────────

    /**
     * O `post()` assina IGUAL ao `get()`: a `ShopeeSigner` cobre
     * partner_id+caminho+timestamp+token+shop_id e NADA do corpo. As credenciais
     * vão na query string; o payload vai como corpo JSON.
     */
    public function test_post_assina_igual_ao_get_credenciais_na_query_e_corpo_json(): void
    {
        Http::fake(['*' => Http::response(['response' => ['ok' => true]], 200)]);
        $company = $this->companyComToken();

        $resposta = app(ShopeeService::class)->post($company, '/api/v2/payment/get_escrow_detail_batch', [
            'order_sn_list' => ['260930AAA', '261001BBB'],
        ]);

        $this->assertSame(['ok' => true], $resposta, 'devolve $json[\'response\'], igual ao get()');

        $signer = new ShopeeSigner(123456, 'shpk_test_key');

        Http::assertSent(function ($request) use ($signer) {
            $this->assertSame('POST', $request->method());

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            // Credenciais na QUERY, mesmo no POST (exigência da v2).
            $this->assertSame('123456', $query['partner_id']);
            $this->assertSame('atk', $query['access_token']);
            $this->assertSame('227758374', $query['shop_id']);

            // Mesma assinatura do get(): caminho + timestamp + token + shop_id.
            $this->assertSame(
                $signer->sign('/api/v2/payment/get_escrow_detail_batch', (int) $query['timestamp'], 'atk', 227758374),
                $query['sign'],
                'o corpo NÃO participa da base string — post() e get() assinam igual'
            );

            // Payload no CORPO JSON, não na query.
            $this->assertArrayNotHasKey('order_sn_list', $query);
            $this->assertSame(['order_sn_list' => ['260930AAA', '261001BBB']], $request->data());
            $this->assertStringContainsString('application/json', $request->header('Content-Type')[0] ?? '');

            return true;
        });
    }

    /** Erro no `post()` virou exceção, igual ao `get()` — top-level `error` ou HTTP ruim. */
    public function test_post_lanca_em_erro_igual_ao_get(): void
    {
        $company = $this->companyComToken();

        // `error` não-vazio com HTTP 200 (padrão da v2)
        Http::fake(['*' => Http::response(['error' => 'error_param', 'message' => 'ruim'], 200)]);

        try {
            app(ShopeeService::class)->post($company, '/api/v2/payment/get_escrow_detail_batch', []);
            $this->fail('deveria ter lançado por `error` não-vazio');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('[Shopee]', $e->getMessage());
        }

        // HTTP ruim
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        app(ShopeeService::class)->post($company, '/api/v2/payment/get_escrow_detail_batch', []);
    }

    /** Sem token válido o `post()` aborta antes de qualquer request, igual ao `get()`. */
    public function test_post_sem_token_lanca_sem_bater_na_api(): void
    {
        Http::fake();
        $company = Company::factory()->create(); // sem shopeeToken

        try {
            app(ShopeeService::class)->post($company, '/api/v2/payment/get_escrow_detail_batch', []);
            $this->fail('deveria ter lançado RuntimeException por falta de token');
        } catch (\RuntimeException) {
            // esperado
        }

        Http::assertNothingSent();
    }
}
