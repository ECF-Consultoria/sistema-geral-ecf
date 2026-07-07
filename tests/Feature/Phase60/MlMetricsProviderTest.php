<?php

namespace Tests\Feature\Phase60;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\Metrics\MlMetricsProvider;
use App\Services\Metrics\UnifiedMetricsDto;
use App\Services\MercadoLivreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * Phase 60 Plan 60-03 (Wave 3) — Testes do `MlMetricsProvider`.
 *
 * Estratégia de mock: `MercadoLivreService` mockado via Mockery. A alternativa
 * `Http::fake` exigiria stubar 3+ endpoints internos (advertiser lookup,
 * orders paginado, ads search) — só ruído. O mock no boundary do service dá
 * o mesmo resultado semântico com testes mais legíveis. Para T10 (cache),
 * usamos `->once()` em ambos métodos e chamamos `readForCompany` 2x; se o
 * cache falhar, Mockery::close() no tearDown falha o teste — semanticamente
 * equivalente ao `Http::recorded()` prescrito no plano.
 *
 * Cobertura (11 testes obrigatórios T1-T11 do plan):
 *  - T1: supports true quando mlToken.status='active'
 *  - T2: supports false quando mlToken.status='inactive' (não-active)
 *  - T3: supports false quando empresa não tem mlToken
 *  - T4: name() retorna 'ml'
 *  - T5: readForCompany sem supports retorna DTO com nulls sem chamar API
 *  - T6: readForCompany com supports agrega orders + ads corretamente
 *  - T7: readForCompany com orders API falhando retorna DTO com revenue null
 *        mas ads OK, sem exception vazando
 *  - T8: TACOS derivado calculado (ad_spend=50, revenue=1000 → tacos=5.0)
 *  - T9: campos não-cobertos por ML retornam null
 *  - T10: cache 15 min — 2ª chamada NÃO invoca API (via ->once() em ambos)
 *  - T11: source do DTO === 'ml'
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — enum `source`,
 * tabela de precedência campo-a-campo e estratégia de cache TTL 15 min.
 */
class MlMetricsProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // determinismo do cache entre testes
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────── Helpers ───────────────────────────

    /**
     * Cria Company persistida SEM adman_account_id (ML-only) opcionalmente
     * com MlToken persistido. Ativa por default para exercitar supports().
     */
    private function makeCompany(?string $mlStatus = 'active'): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => '465723451',
        ]);

        if ($mlStatus !== null) {
            MlToken::create([
                'company_id'        => $company->id,
                'ml_user_id'        => '465723451',
                'access_token'      => 'fake-token',
                'refresh_token'     => 'fake-refresh',
                'token_type'        => 'bearer',
                'scope'             => 'read offline_access',
                'expires_at'        => now()->addHour(),
                'last_refreshed_at' => now(),
                'status'            => $mlStatus,
                'connected_at'      => now(),
            ]);
        }

        return $company->fresh();
    }

    /**
     * Payload orders "OK" — bate com o shape que `MercadoLivreService::fetchOrdersSummary`
     * retorna hoje (docblock linhas 400-408).
     */
    private function ordersOk(float $revenue = 1000.0, int $sold = 8, int $orders = 4, float $salesFee = 90.0): array
    {
        return [
            'revenue'       => $revenue,
            'sold_quantity' => $sold,
            'orders_count'  => $orders,
            'sales_fee'     => $salesFee,
            'raw_orders'    => [],
        ];
    }

    /**
     * Payload ads "OK" — bate com o shape que `MercadoLivreService::fetchAdsSummary`
     * retorna hoje (docblock linhas 475-484).
     */
    private function adsOk(float $adSpend = 200.0, int $clicks = 100, int $impressions = 5000, ?float $acos = 15.5, ?float $roas = 4.2): array
    {
        return [
            'ad_spend'      => $adSpend,
            'ad_revenue'    => 1290.32,
            'clicks'        => $clicks,
            'impressions'   => $impressions,
            'acos'          => $acos,
            'roas'          => $roas,
            'cpc'           => 2.0,
            'sold_quantity' => 0,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // T1-T4 — supports() + name()
    // ═══════════════════════════════════════════════════════════════

    public function test_supports_true_quando_ml_token_active(): void
    {
        $company = $this->makeCompany('active');
        $provider = new MlMetricsProvider(Mockery::mock(MercadoLivreService::class));

        $this->assertTrue($provider->supports($company));
    }

    public function test_supports_false_quando_ml_token_inactive(): void
    {
        $company = $this->makeCompany('expired');
        $provider = new MlMetricsProvider(Mockery::mock(MercadoLivreService::class));

        $this->assertFalse($provider->supports($company));
    }

    public function test_supports_false_quando_empresa_sem_ml_token(): void
    {
        $company = $this->makeCompany(null); // sem MlToken persistido
        $provider = new MlMetricsProvider(Mockery::mock(MercadoLivreService::class));

        $this->assertFalse($provider->supports($company));
    }

    public function test_name_retorna_ml(): void
    {
        $provider = new MlMetricsProvider(Mockery::mock(MercadoLivreService::class));

        $this->assertSame('ml', $provider->name());
    }

    // ═══════════════════════════════════════════════════════════════
    // T5 — sem supports, zero chamada de API
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_sem_supports_retorna_dto_com_nulls_sem_chamar_api(): void
    {
        $company = $this->makeCompany(null); // sem MlToken → supports() = false

        $mlService = Mockery::mock(MercadoLivreService::class);
        // Assinatura equivalente a Http::assertNothingSent(): mock NÃO deve
        // receber nenhuma chamada. Se algum método for invocado, Mockery falha.
        $mlService->shouldNotReceive('fetchOrdersSummary');
        $mlService->shouldNotReceive('fetchAdsSummary');

        $provider = new MlMetricsProvider($mlService);
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame('ml', $dto->source);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->ad_spend);
        $this->assertNull($dto->tacos);
        $this->assertNull($dto->orders_count);
    }

    // ═══════════════════════════════════════════════════════════════
    // T6 — agregação orders + ads (caminho feliz)
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_agrega_orders_e_ads_corretamente(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        $mlService->shouldReceive('fetchOrdersSummary')
            ->once()
            ->with(Mockery::on(fn ($c) => $c instanceof Company && $c->id === $company->id), '2026-07-01', '2026-07-07')
            ->andReturn($this->ordersOk(revenue: 1500.0, sold: 12, orders: 6, salesFee: 120.0));
        $mlService->shouldReceive('fetchAdsSummary')
            ->once()
            ->with(Mockery::on(fn ($c) => $c instanceof Company && $c->id === $company->id), '2026-07-01', '2026-07-07')
            ->andReturn($this->adsOk(adSpend: 250.0, clicks: 150, impressions: 8000, acos: 12.5, roas: 5.0));

        $provider = new MlMetricsProvider($mlService);
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertEqualsWithDelta(1500.0, $dto->revenue, 0.01);
        $this->assertSame(12, $dto->sold_quantity);
        $this->assertSame(6, $dto->orders_count);
        $this->assertEqualsWithDelta(120.0, $dto->sales_fee, 0.01);
        $this->assertEqualsWithDelta(250.0, $dto->ad_spend, 0.01);
        $this->assertSame(150, $dto->clicks);
        $this->assertSame(8000, $dto->impressions);
        $this->assertEqualsWithDelta(12.5, $dto->acos, 0.01);
        $this->assertEqualsWithDelta(5.0, $dto->roas, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // T7 — orders falha, ads OK: exception NÃO vaza
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_orders_falhando_retorna_revenue_null_mas_ads_ok(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        $mlService->shouldReceive('fetchOrdersSummary')
            ->once()
            ->andThrow(new \RuntimeException('[MercadoLivre] Simulado 500'));
        $mlService->shouldReceive('fetchAdsSummary')
            ->once()
            ->andReturn($this->adsOk(adSpend: 300.0, clicks: 200, impressions: 10000));

        $provider = new MlMetricsProvider($mlService);
        // Não deve lançar — falha vira DTO com bloco orders null.
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        // Bloco orders foi null-ificado
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->sold_quantity);
        $this->assertNull($dto->orders_count);
        $this->assertNull($dto->sales_fee);
        // Bloco ads intacto
        $this->assertEqualsWithDelta(300.0, $dto->ad_spend, 0.01);
        $this->assertSame(200, $dto->clicks);
        $this->assertSame(10000, $dto->impressions);
    }

    // ═══════════════════════════════════════════════════════════════
    // T8 — TACOS derivado ad_spend/revenue * 100
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_calcula_tacos_derivado(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        // Fixture: ad_spend=50, revenue=1000 → tacos = (50/1000)*100 = 5.0
        $mlService->shouldReceive('fetchOrdersSummary')
            ->once()
            ->andReturn($this->ordersOk(revenue: 1000.0));
        $mlService->shouldReceive('fetchAdsSummary')
            ->once()
            ->andReturn($this->adsOk(adSpend: 50.0));

        $provider = new MlMetricsProvider($mlService);
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertNotNull($dto->tacos);
        $this->assertEqualsWithDelta(5.0, $dto->tacos, 0.001);
    }

    // ═══════════════════════════════════════════════════════════════
    // T9 — campos não-cobertos por ML retornam null (ADR DATA-04)
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_campos_exclusivos_adman_retornam_null(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        $mlService->shouldReceive('fetchOrdersSummary')->once()->andReturn($this->ordersOk());
        $mlService->shouldReceive('fetchAdsSummary')->once()->andReturn($this->adsOk());

        $provider = new MlMetricsProvider($mlService);
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        // Campos exclusivos Adman por ADR DATA-04 — ML não expõe.
        $this->assertNull($dto->net_billing);
        $this->assertNull($dto->taxes);
        $this->assertNull($dto->shipping_cost);
        $this->assertNull($dto->product_cost);
        $this->assertNull($dto->contribution_margin);
    }

    // ═══════════════════════════════════════════════════════════════
    // T10 — cache: 2ª chamada NÃO invoca API
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_cache_impede_segunda_chamada_api(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        // Estratégia equivalente a Http::recorded() count no boundary do
        // service: ambos os métodos DEVEM ser chamados exatamente 1x mesmo
        // após 2 invocações de readForCompany com args idênticos. Se cache
        // falhar, mock recebe 2x e Mockery::close() falha o teste.
        $mlService->shouldReceive('fetchOrdersSummary')->once()->andReturn($this->ordersOk());
        $mlService->shouldReceive('fetchAdsSummary')->once()->andReturn($this->adsOk());

        $provider = new MlMetricsProvider($mlService);
        $from = Carbon::parse('2026-07-01');
        $to   = Carbon::parse('2026-07-07');

        $dto1 = $provider->readForCompany($company, $from, $to);
        $dto2 = $provider->readForCompany($company, $from, $to);

        // Sanidade: DTOs idênticos (cache serve a mesma leitura).
        $this->assertEquals($dto1->toArray(), $dto2->toArray());
        // Mockery::close() no tearDown enforça `->once()` — se 2ª chamada
        // tivesse ido à API, falharia com "expected 1, got 2".
    }

    // ═══════════════════════════════════════════════════════════════
    // T11 — source === 'ml'
    // ═══════════════════════════════════════════════════════════════

    public function test_readForCompany_source_field_igual_a_ml(): void
    {
        $company = $this->makeCompany('active');

        $mlService = Mockery::mock(MercadoLivreService::class);
        $mlService->shouldReceive('fetchOrdersSummary')->once()->andReturn($this->ordersOk());
        $mlService->shouldReceive('fetchAdsSummary')->once()->andReturn($this->adsOk());

        $provider = new MlMetricsProvider($mlService);
        $dto = $provider->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertSame('ml', $dto->source);
    }
}
