<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ShopeeMetric;
use App\Models\ShopeeToken;
use App\Services\Shopee\ShopeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Quick 261006-dv3 — a releitura dos últimos dias da Shopee.
 *
 * O que estes testes travam: o `shopee:sync` gravava D-1 uma vez e nunca
 * voltava, e `syncCompanyDay` devolvia `null` no dia sem pedido — então
 * cancelamento posterior NUNCA baixava o número já gravado. Medido em produção
 * em 06/10/2026 relendo a API: GENUINEAUTOMOTIVE 04/10 +2,5%, MPozenato 20/08
 * −1,3%. A correção vai para os dois lados, e é isso que está coberto aqui.
 */
class ShopeeRelerDiasTest extends TestCase
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

    private function companyComToken(string $app = 'erp'): Company
    {
        $company = Company::factory()->create();
        ShopeeToken::create([
            'company_id'         => $company->id,
            'app'                => $app,
            'shop_id'            => '227758374',
            'access_token'       => 'atk',
            'refresh_token'      => 'rtk',
            'expires_at'         => now()->addHours(3),
            'refresh_expires_at' => now()->addDays(30),
            'status'             => 'active',
        ]);

        return $company->fresh();
    }

    /** Lista vazia = nenhum pedido válido no dia. */
    private function fakeDiaSemPedido(): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => ['order_list' => [], 'more' => false, 'next_cursor' => ''],
            ], 200),
        ]);
    }

    /** Um pedido COMPLETED de R$ 100. */
    private function fakeUmPedido(float $valor = 100): void
    {
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => ['order_list' => [['order_sn' => 'A']], 'more' => false, 'next_cursor' => ''],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => ['order_list' => [
                    ['order_sn' => 'A', 'order_status' => 'COMPLETED', 'total_amount' => $valor, 'item_list' => [['model_quantity_purchased' => 1]]],
                ]],
            ], 200),
        ]);
    }

    // ─── T2: cancelamento passa a baixar o número ────────────────────────────

    public function test_dia_zerado_com_linha_existente_baixa_para_zero(): void
    {
        $company = $this->companyComToken();

        // Retrato antigo: o dia foi coletado com venda.
        ShopeeMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-01',
            'revenue'        => 500,
            'orders_count'   => 3,
            'sold_quantity'  => 7,
            'synced_at'      => now()->subDays(10),
        ]);

        // Hoje a Shopee não devolve mais nenhum pedido válido (todos cancelados).
        $this->fakeDiaSemPedido();

        $metric = app(ShopeeService::class)->syncCompanyDay($company, '2026-07-01');

        $this->assertNotNull($metric, 'linha existente deve ser atualizada, não ignorada');
        $this->assertSame('0.00', (string) $metric->revenue);
        $this->assertSame(0, $metric->orders_count);
        $this->assertSame(0, $metric->sold_quantity);
        $this->assertNotNull($metric->synced_at);
        $this->assertDatabaseCount('shopee_metrics', 1); // atualizou, não duplicou
    }

    public function test_dia_zerado_sem_linha_continua_sem_gravar(): void
    {
        $company = $this->companyComToken();
        $this->fakeDiaSemPedido();

        $metric = app(ShopeeService::class)->syncCompanyDay($company, '2026-07-01');

        $this->assertNull($metric);
        $this->assertDatabaseCount('shopee_metrics', 0);
    }

    public function test_releitura_atualiza_valor_sem_duplicar_linha(): void
    {
        $company = $this->companyComToken();

        ShopeeMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-01',
            'revenue'        => 80,
            'orders_count'   => 1,
            'sold_quantity'  => 1,
            'synced_at'      => now()->subDays(5),
        ]);

        $this->fakeUmPedido(100); // pedido fechou mais caro do que no retrato

        $metric = app(ShopeeService::class)->syncCompanyDay($company, '2026-07-01');

        $this->assertSame('100.00', (string) $metric->revenue);
        $this->assertDatabaseCount('shopee_metrics', 1);
    }

    // ─── T1: o comando ───────────────────────────────────────────────────────

    public function test_comando_rele_a_janela_pedida_e_nao_toca_hoje(): void
    {
        $company = $this->companyComToken();
        $this->fakeUmPedido(100);

        $this->artisan('shopee:reler-dias', ['--dias' => 3])
            ->assertSuccessful();

        // Janela é D-3 → D-1: o dia de hoje nunca entra (ainda está abrindo).
        $datas = ShopeeMetric::where('company_id', $company->id)
            ->orderBy('reference_date')
            ->pluck('reference_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $this->assertSame([
            now()->subDays(3)->toDateString(),
            now()->subDays(2)->toDateString(),
            now()->subDay()->toDateString(),
        ], $datas);
        $this->assertNotContains(now()->toDateString(), $datas);
    }

    public function test_comando_respeita_filtro_de_empresa(): void
    {
        $alvo  = $this->companyComToken();
        $outra = $this->companyComToken();
        $this->fakeUmPedido(100);

        $this->artisan('shopee:reler-dias', ['--dias' => 1, '--company' => $alvo->id])
            ->assertSuccessful();

        $this->assertSame(1, ShopeeMetric::where('company_id', $alvo->id)->count());
        $this->assertSame(0, ShopeeMetric::where('company_id', $outra->id)->count());
    }

    public function test_comando_ignora_empresa_sem_token_erp_ativo(): void
    {
        Company::factory()->create();                  // sem token nenhum
        $revogada = $this->companyComToken();
        $revogada->shopeeToken->update(['status' => 'revoked']);

        Http::fake(); // qualquer request faria o teste falhar

        $this->artisan('shopee:reler-dias', ['--dias' => 1])
            ->expectsOutputToContain('Nenhuma empresa com token Shopee ativo')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_falha_de_um_dia_nao_derruba_a_rodada_e_vai_para_log_error(): void
    {
        Log::spy();

        $company = $this->companyComToken();
        Http::fake(['*get_order_list*' => Http::response(['error' => 'error_server'], 500)]);

        $this->artisan('shopee:reler-dias', ['--dias' => 2])
            ->assertSuccessful();

        $this->assertDatabaseCount('shopee_metrics', 0);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains((string) $msg, '[Shopee] Releitura empresa'))
            ->atLeast()->once();
    }

    // ─── T3: truncamento deixa de ser silencioso ─────────────────────────────

    public function test_truncamento_da_paginacao_grava_log_error(): void
    {
        Log::spy();

        $company = $this->companyComToken();

        // A Shopee diz "more = true" para sempre: o loop tem que parar na trava
        // e GRITAR, nunca parar calado deixando o dia truncado.
        Http::fake([
            '*get_order_list*' => Http::response([
                'response' => [
                    'order_list'  => array_map(fn ($i) => ['order_sn' => "SN{$i}"], range(1, 100)),
                    'more'        => true,
                    'next_cursor' => 'c',
                ],
            ], 200),
            '*get_order_detail*' => Http::response([
                'response' => ['order_list' => []],
            ], 200),
        ]);

        app(ShopeeService::class)->fetchOrdersSummary($company, '2026-07-01', '2026-07-01');

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'Faturamento TRUNCADO'))
            ->once();
    }
}
