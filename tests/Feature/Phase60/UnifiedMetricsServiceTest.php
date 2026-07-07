<?php

namespace Tests\Feature\Phase60;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\Metrics\UnifiedMetricsDto;
use App\Services\Metrics\UnifiedMetricsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Phase 60 Plan 60-04 (Wave 4) — Testes do `UnifiedMetricsService`.
 *
 * Cobre os 3 casos formalizados pelo ADR DATA-04 + caso "none" + divergência
 * TACOS (>5% gera log; <=5% não gera) + resiliência a falhas de provider.
 *
 * Success Criterion #3 do ROADMAP Phase 60 (testes automatizados dos 3 casos)
 * é atendido nomeadamente pelos testes `test_caso_so_adman_*`,
 * `test_caso_so_ml_*` e `test_caso_ambos_*` (grep por `so_adman|so_ml|ambos`
 * retorna ≥ 3 ocorrências nos nomes dos métodos deste arquivo).
 *
 * Estratégia de mock:
 *  - `MercadoLivreService` mockado via `$this->app->instance()` para injetar
 *    respostas controladas do endpoint ML sem tocar em HTTP real. Mesma
 *    técnica usada em `MlMetricsProviderTest`.
 *  - `AdmanMetric::create()` direto (sem factory) para atender ao shape
 *    exato consumido pelo `AdmanMetricsProvider`.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — tabela de precedência
 * campo-a-campo, 4 valores válidos de `source` e regra de log em divergência
 * TACOS > 5%.
 */
class UnifiedMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $from;
    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush(); // determinismo entre testes (cache do MlMetricsProvider)

        // Janela padrão dos testes — mesmo período usado em MlMetricsProviderTest
        // pra rimar com as fixtures Adman abaixo (todas em 2026-07-02..07).
        $this->from = Carbon::parse('2026-07-01');
        $this->to   = Carbon::parse('2026-07-07');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────────────────────── Helpers ───────────────────────────

    private function makeCompanySoAdman(): Company
    {
        return Company::factory()->create([
            'adman_account_id' => '12345',
            'ml_store_id'      => null,
        ])->fresh();
    }

    private function makeCompanySoMl(): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => '465723451',
        ]);
        $this->attachMlToken($company, 'active');

        return $company->fresh();
    }

    private function makeCompanyAmbos(): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => '12345',
            'ml_store_id'      => '465723451',
        ]);
        $this->attachMlToken($company, 'active');

        return $company->fresh();
    }

    private function makeCompanyNone(): Company
    {
        return Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ])->fresh();
    }

    private function attachMlToken(Company $company, string $status): void
    {
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => $status,
            'connected_at'      => now(),
        ]);
    }

    /**
     * Seeda 1 row em `adman_metrics` para o dia + campos passados.
     * Ecoa o padrão do `AdmanMetricsProviderTest` (create direto, sem factory).
     */
    private function seedAdmanMetric(Company $company, string $date, array $data): AdmanMetric
    {
        return AdmanMetric::create(array_merge([
            'company_id'     => $company->id,
            'reference_date' => $date,
        ], $data));
    }

    /**
     * Instala mock do `MercadoLivreService` no container Laravel — quando
     * `MlMetricsProvider` for resolvido via DI, receberá este mock.
     *
     * @param  array|\Throwable|null  $ordersReturn  payload de fetchOrdersSummary,
     *                                               exception a lançar, ou null (não configurar).
     * @param  array|\Throwable|null  $adsReturn     idem para fetchAdsSummary.
     */
    private function mockMercadoLivreService($ordersReturn, $adsReturn): void
    {
        $mock = Mockery::mock(MercadoLivreService::class);

        if ($ordersReturn instanceof \Throwable) {
            $mock->shouldReceive('fetchOrdersSummary')->andThrow($ordersReturn);
        } elseif (is_array($ordersReturn)) {
            $mock->shouldReceive('fetchOrdersSummary')->andReturn($ordersReturn);
        }

        if ($adsReturn instanceof \Throwable) {
            $mock->shouldReceive('fetchAdsSummary')->andThrow($adsReturn);
        } elseif (is_array($adsReturn)) {
            $mock->shouldReceive('fetchAdsSummary')->andReturn($adsReturn);
        }

        $this->app->instance(MercadoLivreService::class, $mock);
    }

    /**
     * Payload orders "OK" — bate com shape de `MercadoLivreService::fetchOrdersSummary`.
     */
    private function ordersPayload(
        ?float $revenue = 1000.0,
        ?int $sold = 8,
        ?int $orders = 4,
        ?float $salesFee = 90.0,
    ): array {
        $out = ['raw_orders' => []];
        if ($revenue !== null)  { $out['revenue']       = $revenue; }
        if ($sold !== null)     { $out['sold_quantity'] = $sold; }
        if ($orders !== null)   { $out['orders_count']  = $orders; }
        if ($salesFee !== null) { $out['sales_fee']     = $salesFee; }

        return $out;
    }

    /**
     * Payload ads "OK" — bate com shape de `MercadoLivreService::fetchAdsSummary`.
     */
    private function adsPayload(
        ?float $adSpend = 100.0,
        ?int $clicks = 200,
        ?int $impressions = 10000,
        ?float $acos = 10.0,
        ?float $roas = 10.0,
    ): array {
        $out = [];
        if ($adSpend !== null)     { $out['ad_spend']    = $adSpend; }
        if ($clicks !== null)      { $out['clicks']      = $clicks; }
        if ($impressions !== null) { $out['impressions'] = $impressions; }
        if ($acos !== null)        { $out['acos']        = $acos; }
        if ($roas !== null)        { $out['roas']        = $roas; }

        return $out;
    }

    private function service(): UnifiedMetricsService
    {
        return $this->app->make(UnifiedMetricsService::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // T1 — Caso "so-adman"
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_so_adman_retorna_dto_com_source_adman(): void
    {
        $company = $this->makeCompanySoAdman();

        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue'     => 500.0,
            'ad_spend'    => 40.0,
            'net_billing' => 450.0,
            'taxes'       => 25.0,
        ]);

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame('adman', $dto->source);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertEqualsWithDelta(500.0, $dto->revenue, 0.01);
        $this->assertEqualsWithDelta(40.0, $dto->ad_spend, 0.01);
        $this->assertEqualsWithDelta(450.0, $dto->net_billing, 0.01);
        $this->assertEqualsWithDelta(25.0, $dto->taxes, 0.01);
        // Campos ML-only permanecem null no caso só-Adman (Adman não expõe).
        $this->assertNull($dto->acos);
        $this->assertNull($dto->roas);
        $this->assertNull($dto->clicks);
        $this->assertNull($dto->impressions);
        $this->assertNull($dto->orders_count);
    }

    // ═══════════════════════════════════════════════════════════════
    // T2 — Caso "so-ml"
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_so_ml_retorna_dto_com_source_ml(): void
    {
        $company = $this->makeCompanySoMl();

        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 1500.0, sold: 12, orders: 6, salesFee: 120.0),
            adsReturn: $this->adsPayload(adSpend: 200.0, clicks: 150, impressions: 8000, acos: 13.3, roas: 7.5),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame('ml', $dto->source);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertEqualsWithDelta(1500.0, $dto->revenue, 0.01);
        $this->assertEqualsWithDelta(200.0, $dto->ad_spend, 0.01);
        $this->assertSame(12, $dto->sold_quantity);
        $this->assertSame(6, $dto->orders_count);
        $this->assertSame(150, $dto->clicks);
        $this->assertSame(8000, $dto->impressions);
        // Campos Adman-only permanecem null no caso só-ML.
        $this->assertNull($dto->net_billing);
        $this->assertNull($dto->taxes);
        $this->assertNull($dto->shipping_cost);
        $this->assertNull($dto->product_cost);
        $this->assertNull($dto->contribution_margin);
    }

    // ═══════════════════════════════════════════════════════════════
    // T3 — Caso "ambos" → source='unified'
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_retorna_dto_com_source_unified(): void
    {
        $company = $this->makeCompanyAmbos();

        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue'             => 900.0,
            'ad_spend'            => 80.0,
            'net_billing'         => 810.0,
            'taxes'               => 45.0,
            'shipping_cost'       => 30.0,
            'product_cost'        => 200.0,
            'contribution_margin' => 435.0,
        ]);

        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 1000.0, sold: 10, orders: 5, salesFee: 95.0),
            adsReturn: $this->adsPayload(adSpend: 100.0, clicks: 220, impressions: 12000, acos: 10.0, roas: 10.0),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertSame('unified', $dto->source);
        $this->assertSame($company->id, $dto->company_id);
        // ML dita numéricos operacionais.
        $this->assertEqualsWithDelta(1000.0, $dto->revenue, 0.01);
        $this->assertEqualsWithDelta(100.0, $dto->ad_spend, 0.01);
        $this->assertSame(10, $dto->sold_quantity);
        // Adman enriquece contábeis.
        $this->assertEqualsWithDelta(810.0, $dto->net_billing, 0.01);
        $this->assertEqualsWithDelta(45.0, $dto->taxes, 0.01);
        $this->assertEqualsWithDelta(30.0, $dto->shipping_cost, 0.01);
        $this->assertEqualsWithDelta(200.0, $dto->product_cost, 0.01);
        $this->assertEqualsWithDelta(435.0, $dto->contribution_margin, 0.01);
        // ML-only.
        $this->assertSame(220, $dto->clicks);
        $this->assertSame(12000, $dto->impressions);
        $this->assertEqualsWithDelta(10.0, $dto->acos, 0.01);
        $this->assertEqualsWithDelta(10.0, $dto->roas, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // T4 — Caso "ambos": ML dita revenue sobre Adman
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_ml_dita_revenue_sobre_adman(): void
    {
        $company = $this->makeCompanyAmbos();

        // Adman revenue=100 vs ML revenue=200. ML deve vencer (ADR DATA-04).
        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue' => 100.0,
        ]);

        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 200.0),
            adsReturn: $this->adsPayload(),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertEqualsWithDelta(200.0, $dto->revenue, 0.01, 'ML deve dita revenue no caso ambos (ADR DATA-04).');
    }

    // ═══════════════════════════════════════════════════════════════
    // T5 — Caso "ambos": Adman dita net_billing (ML não expõe)
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_adman_dita_net_billing_sobre_ml(): void
    {
        $company = $this->makeCompanyAmbos();

        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue'     => 800.0,
            'net_billing' => 500.0,
        ]);

        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 900.0),
            adsReturn: $this->adsPayload(),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        // ML dita revenue.
        $this->assertEqualsWithDelta(900.0, $dto->revenue, 0.01);
        // Adman dita net_billing (ML não expõe).
        $this->assertEqualsWithDelta(500.0, $dto->net_billing, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // T6 — Caso "ambos": ML campo null cai pro Adman (fallback)
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_ml_com_campo_null_cai_pro_adman(): void
    {
        $company = $this->makeCompanyAmbos();

        // Adman com sales_fee=50; ML sem sales_fee (null).
        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue'   => 800.0,
            'sales_fee' => 50.0,
        ]);

        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 800.0, salesFee: null),
            adsReturn: $this->adsPayload(),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        // ML.sales_fee null → fallback pro Adman (50.0).
        $this->assertNotNull($dto->sales_fee, 'Fallback Adman deveria preencher sales_fee null vindo de ML.');
        $this->assertEqualsWithDelta(50.0, $dto->sales_fee, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // T7 — Caso "ambos": TACOS divergente > 5% gera Log::warning
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_tacos_divergente_gera_log_warning(): void
    {
        $company = $this->makeCompanyAmbos();

        // Adman TACOS = 8.0
        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue' => 1000.0,
            'tacos'   => 8.0,
        ]);

        // ML derivado: ad_spend=50, revenue=1000 → TACOS=(50/1000)*100 = 5.0
        // Delta = |5.0 - 8.0| / max(8.0, 0.01) = 0.375 = 37.5% > 5% → deve logar.
        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 1000.0),
            adsReturn: $this->adsPayload(adSpend: 50.0),
        );

        Log::spy();

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame('unified', $dto->source);

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) use ($company) {
                if (! is_string($message) || ! str_contains($message, 'TACOS divergente')) {
                    return false;
                }
                // Contexto tem que carregar company_id + valores para auditoria.
                return is_array($context)
                    && ($context['company_id'] ?? null) === $company->id
                    && array_key_exists('ml', $context)
                    && array_key_exists('adman', $context);
            })
            ->atLeast()->once();
    }

    // ═══════════════════════════════════════════════════════════════
    // T8 — Caso "ambos": TACOS dentro de 5% NÃO gera log
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_tacos_dentro_de_5pct_nao_gera_log(): void
    {
        $company = $this->makeCompanyAmbos();

        // Adman TACOS = 5.2
        $this->seedAdmanMetric($company, '2026-07-02', [
            'revenue' => 1000.0,
            'tacos'   => 5.2,
        ]);

        // ML derivado: ad_spend=50, revenue=1000 → TACOS=5.0
        // Delta = |5.0 - 5.2| / max(5.2, 0.01) ≈ 0.0385 = 3.85% < 5% → NÃO deve logar.
        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(revenue: 1000.0),
            adsReturn: $this->adsPayload(adSpend: 50.0),
        );

        Log::spy();

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);

        Log::shouldNotHaveReceived('warning', [
            Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'TACOS divergente')),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // T9 — Caso "none": empresa sem NENHUMA integração
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_none_retorna_dto_source_none_todos_campos_null(): void
    {
        $company = $this->makeCompanyNone();

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame('none', $dto->source);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->ad_spend);
        $this->assertNull($dto->sold_quantity);
        $this->assertNull($dto->tacos);
        $this->assertNull($dto->net_billing);
        $this->assertNull($dto->sales_fee);
        $this->assertNull($dto->taxes);
        $this->assertNull($dto->shipping_cost);
        $this->assertNull($dto->product_cost);
        $this->assertNull($dto->contribution_margin);
        $this->assertNull($dto->acos);
        $this->assertNull($dto->roas);
        $this->assertNull($dto->clicks);
        $this->assertNull($dto->impressions);
        $this->assertNull($dto->orders_count);
    }

    // ═══════════════════════════════════════════════════════════════
    // T10 — readForCompany nunca lança exception quando provider erra
    // ═══════════════════════════════════════════════════════════════

    public function test_readforcompany_nunca_lanca_exception_com_provider_com_erro(): void
    {
        $company = $this->makeCompanySoMl();

        // Simular erro ML nos 2 endpoints. MlMetricsProvider engole a exception
        // e retorna DTO com nulls — UnifiedMetricsService NÃO deve propagar.
        $this->mockMercadoLivreService(
            ordersReturn: new \RuntimeException('[ML] Simulado 500 orders'),
            adsReturn: new \RuntimeException('[ML] Simulado 500 ads'),
        );

        $dto = $this->service()->readForCompany($company, $this->from, $this->to);

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->ad_spend);
        // Ainda assim retorna com source='ml' — no caso "so-ml" o DTO do
        // provider vem direto (não vira 'unified').
        $this->assertSame('ml', $dto->source);
    }

    // ═══════════════════════════════════════════════════════════════
    // T11 — source do DTO por caso exato (dict-driven)
    // ═══════════════════════════════════════════════════════════════

    public function test_source_do_dto_por_caso_exato(): void
    {
        // Configura mock global 1x — vale para todos os casos que precisarem ML.
        $this->mockMercadoLivreService(
            ordersReturn: $this->ordersPayload(),
            adsReturn: $this->adsPayload(),
        );

        $matrix = [
            'ambos'    => ['company' => $this->makeCompanyAmbos(),   'expected' => 'unified'],
            'so-ml'    => ['company' => $this->makeCompanySoMl(),    'expected' => 'ml'],
            'so-adman' => ['company' => $this->makeCompanySoAdman(), 'expected' => 'adman'],
            'none'     => ['company' => $this->makeCompanyNone(),    'expected' => 'none'],
        ];

        foreach ($matrix as $caseName => $entry) {
            $dto = $this->service()->readForCompany($entry['company'], $this->from, $this->to);

            $this->assertSame(
                $entry['expected'],
                $dto->source,
                "Caso '{$caseName}' deveria retornar DTO.source === '{$entry['expected']}'.",
            );
        }
    }
}
