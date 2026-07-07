<?php

namespace Tests\Feature\Phase60;

use App\Contracts\MetricsProvider;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\Metrics\AdmanMetricsProvider;
use App\Services\Metrics\UnifiedMetricsDto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Phase 60 Plan 60-02 (Wave 2) — Testes do contract `MetricsProvider`, do
 * DTO `UnifiedMetricsDto` (19 propriedades) e do primeiro provider
 * (`AdmanMetricsProvider`).
 *
 * Estrutura em duas partes:
 *
 *  Task 1 (RED — foundação):
 *    - test_contract_metrics_provider_existe
 *    - test_dto_cobre_15_campos_numericos_e_4_metadados
 *    - test_dto_to_array_serializa_carbon_como_string_ymd
 *    - test_dto_aceita_construcao_com_campos_numericos_nulos
 *
 *  Task 2 (GREEN — comportamento do provider):
 *    - test_supports_true_quando_adman_account_id_presente
 *    - test_supports_false_quando_adman_account_id_ausente
 *    - test_name_retorna_adman
 *    - test_readForCompany_soma_revenue_e_ad_spend_no_periodo
 *    - test_readForCompany_retorna_dto_com_nulls_quando_periodo_sem_rows
 *    - test_readForCompany_calcula_tacos_ponderado_por_revenue
 *    - test_readForCompany_campos_nao_cobertos_por_adman_retornam_null
 *    - test_readForCompany_ignora_rows_fora_do_range
 *    - test_readForCompany_source_field_igual_a_adman
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — enum `source` de 4
 * valores + tabela de precedência campo-a-campo.
 */
class AdmanMetricsProviderTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // Task 1 — RED: contract + DTO (fundação)
    // ═══════════════════════════════════════════════════════════════

    public function test_contract_metrics_provider_existe(): void
    {
        $this->assertTrue(
            interface_exists(MetricsProvider::class),
            'Contract App\\Contracts\\MetricsProvider deve existir (Plan 60-02 Task 1).',
        );

        $reflection = new ReflectionClass(MetricsProvider::class);
        $methods    = $reflection->getMethods();

        // Contract deve expor exatamente 3 métodos abstratos: supports, name, readForCompany.
        $this->assertCount(
            3,
            $methods,
            'MetricsProvider deve declarar exatamente 3 métodos (supports/name/readForCompany).',
        );

        $methodNames = array_map(fn ($m) => $m->getName(), $methods);
        $this->assertContains('supports', $methodNames);
        $this->assertContains('name', $methodNames);
        $this->assertContains('readForCompany', $methodNames);
    }

    public function test_dto_cobre_15_campos_numericos_e_4_metadados(): void
    {
        $this->assertTrue(
            class_exists(UnifiedMetricsDto::class),
            'DTO App\\Services\\Metrics\\UnifiedMetricsDto deve existir.',
        );

        $reflection = new ReflectionClass(UnifiedMetricsDto::class);

        // Deve ser readonly (imutável — threat T-60-02-01).
        $this->assertTrue(
            $reflection->isReadOnly() || $reflection->isFinal(),
            'UnifiedMetricsDto deve ser final readonly (PHP 8.2+).',
        );

        // Total de 19 propriedades (4 metadados + 15 numéricos).
        $props = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        $this->assertCount(
            19,
            $props,
            'UnifiedMetricsDto deve ter exatamente 19 propriedades (4 metadados + 15 numéricos).',
        );

        // Distinção metadados vs numéricos: metadados são NÃO-nulláveis
        // (company_id: int, source: string, period_from/to: Carbon), enquanto
        // numéricos são SEMPRE nulláveis (?float|?int) — sinal explícito de
        // "fonte não expõe este campo".
        $numericos = 0;
        $metadados = 0;

        foreach ($props as $prop) {
            $type = $prop->getType();
            if ($type === null) {
                continue;
            }
            $typeName    = $type->getName();
            $allowsNull  = $type->allowsNull();

            if (in_array($typeName, ['float', 'int'], true) && $allowsNull) {
                $numericos++;
            } elseif (! $allowsNull && in_array($typeName, ['int', 'string', 'Carbon\\Carbon', 'Carbon'], true)) {
                $metadados++;
            }
        }

        $this->assertSame(
            15,
            $numericos,
            'DTO deve ter 15 propriedades numéricas (float/int).',
        );
        $this->assertSame(
            4,
            $metadados,
            'DTO deve ter 4 propriedades de metadados (int company_id + string source + 2x Carbon).',
        );
    }

    public function test_dto_to_array_serializa_carbon_como_string_ymd(): void
    {
        $dto = new UnifiedMetricsDto(
            company_id: 42,
            source: 'adman',
            period_from: Carbon::parse('2026-07-01'),
            period_to: Carbon::parse('2026-07-07'),
            revenue: 1000.50,
            ad_spend: 100.25,
            sold_quantity: 10,
            tacos: 0.10,
            net_billing: 900.00,
            sales_fee: 50.00,
            taxes: 40.00,
            shipping_cost: 20.00,
            product_cost: 300.00,
            contribution_margin: 490.00,
            acos: null,
            roas: null,
            clicks: null,
            impressions: null,
            orders_count: null,
        );

        $arr = $dto->toArray();

        $this->assertIsArray($arr);
        $this->assertSame(42, $arr['company_id']);
        $this->assertSame('adman', $arr['source']);
        $this->assertSame('2026-07-01', $arr['period_from']);
        $this->assertSame('2026-07-07', $arr['period_to']);
        $this->assertSame(1000.50, $arr['revenue']);
        $this->assertNull($arr['acos']);
    }

    public function test_dto_aceita_construcao_com_campos_numericos_nulos(): void
    {
        $dto = new UnifiedMetricsDto(
            company_id: 1,
            source: 'none',
            period_from: Carbon::parse('2026-07-01'),
            period_to: Carbon::parse('2026-07-07'),
            revenue: null,
            ad_spend: null,
            sold_quantity: null,
            tacos: null,
            net_billing: null,
            sales_fee: null,
            taxes: null,
            shipping_cost: null,
            product_cost: null,
            contribution_margin: null,
            acos: null,
            roas: null,
            clicks: null,
            impressions: null,
            orders_count: null,
        );

        $this->assertSame(1, $dto->company_id);
        $this->assertSame('none', $dto->source);
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->ad_spend);
        $this->assertNull($dto->orders_count);
    }

    // ═══════════════════════════════════════════════════════════════
    // Task 2 — GREEN: comportamento do AdmanMetricsProvider
    // ═══════════════════════════════════════════════════════════════

    public function test_supports_true_quando_adman_account_id_presente(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);
        $provider = new AdmanMetricsProvider();

        $this->assertTrue($provider->supports($company));
    }

    public function test_supports_false_quando_adman_account_id_ausente(): void
    {
        $companyNull  = Company::factory()->create(['adman_account_id' => null]);
        $companyEmpty = Company::factory()->create(['adman_account_id' => '']);
        $provider     = new AdmanMetricsProvider();

        $this->assertFalse($provider->supports($companyNull));
        $this->assertFalse($provider->supports($companyEmpty));
    }

    public function test_name_retorna_adman(): void
    {
        $this->assertSame('adman', (new AdmanMetricsProvider())->name());
    }

    public function test_readForCompany_soma_revenue_e_ad_spend_no_periodo(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        // 3 rows dentro do período [2026-07-01, 2026-07-07].
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-02',
            'revenue'        => 100.00,
            'ad_spend'       => 10.00,
            'sold_quantity'  => 5,
            'net_billing'    => 90.00,
            'sales_fee'      => 5.00,
            'taxes'          => 4.00,
            'shipping_cost'  => 2.00,
            'product_cost'   => 30.00,
        ]);
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-03',
            'revenue'        => 100.00,
            'ad_spend'       => 10.00,
            'sold_quantity'  => 5,
        ]);
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-04',
            'revenue'        => 100.00,
            'ad_spend'       => 10.00,
            'sold_quantity'  => 5,
        ]);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertSame('adman', $dto->source);
        $this->assertEqualsWithDelta(300.00, $dto->revenue, 0.01);
        $this->assertEqualsWithDelta(30.00, $dto->ad_spend, 0.01);
        $this->assertSame(15, $dto->sold_quantity);
    }

    public function test_readForCompany_retorna_dto_com_nulls_quando_periodo_sem_rows(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertInstanceOf(UnifiedMetricsDto::class, $dto);
        $this->assertSame($company->id, $dto->company_id);
        $this->assertSame('adman', $dto->source);
        $this->assertNull($dto->revenue);
        $this->assertNull($dto->ad_spend);
        $this->assertNull($dto->sold_quantity);
        $this->assertNull($dto->tacos);
        $this->assertNull($dto->net_billing);
    }

    public function test_readForCompany_calcula_tacos_ponderado_por_revenue(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        // Row A: revenue=100, tacos=10% ⇒ contribui 100*10 = 1000
        // Row B: revenue=200, tacos=20% ⇒ contribui 200*20 = 4000
        // TACOS ponderado = (1000+4000)/(100+200) = 5000/300 ≈ 16.6667
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-02',
            'revenue'        => 100.00,
            'tacos'          => 10.0000,
        ]);
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-03',
            'revenue'        => 200.00,
            'tacos'          => 20.0000,
        ]);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertNotNull($dto->tacos);
        $this->assertEqualsWithDelta(16.6667, $dto->tacos, 0.01);
    }

    public function test_readForCompany_campos_nao_cobertos_por_adman_retornam_null(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-02',
            'revenue'        => 100.00,
            'ad_spend'       => 10.00,
        ]);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        // Campos exclusivos ML por ADR DATA-04 — Adman não expõe.
        $this->assertNull($dto->acos);
        $this->assertNull($dto->roas);
        $this->assertNull($dto->clicks);
        $this->assertNull($dto->impressions);
        $this->assertNull($dto->orders_count);
    }

    public function test_readForCompany_ignora_rows_fora_do_range(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        // Row FORA do range.
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-06-20',
            'revenue'        => 999.00,
            'ad_spend'       => 999.00,
        ]);
        // Row DENTRO do range.
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-07-03',
            'revenue'        => 100.00,
            'ad_spend'       => 10.00,
        ]);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertEqualsWithDelta(100.00, $dto->revenue, 0.01);
        $this->assertEqualsWithDelta(10.00, $dto->ad_spend, 0.01);
    }

    public function test_readForCompany_source_field_igual_a_adman(): void
    {
        $company = Company::factory()->create(['adman_account_id' => '12345']);

        $dto = (new AdmanMetricsProvider())->readForCompany(
            $company,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-07'),
        );

        $this->assertSame('adman', $dto->source);
    }
}
