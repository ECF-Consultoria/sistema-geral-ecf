<?php

namespace Tests\Feature\Phase60;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Metrics\AdmanMetricsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 60 Plan 60-04 (Wave 4) — Baseline regressão dos consumidores de
 * `adman_metrics`.
 *
 * Objetivo: PROVAR que as rotas legadas que consultam `adman_metrics`
 * continuam retornando 200 sem exception DEPOIS que a Phase 60 introduziu
 * a nova base multi-fonte (Contract MetricsProvider + Providers +
 * Factory + UnifiedMetricsService). Cumpre o Success Criterion #4 do
 * ROADMAP Phase 60 (delta regressão = 0).
 *
 * **NENHUM** teste marcado como `skipped` — se rota mudou de nome,
 * `$this->fail()` explícito com instrução pra atualizar o teste. Skip
 * silencioso mascararia SC #4 (memory `feedback_project_priorities`:
 * "acertividade + praticidade" não permite ambiguidade em teste de
 * baseline).
 *
 * Rotas cobertas (validadas via `php artisan route:list --json` em 2026-07-07):
 *  - `dashboard`         → GET → DashboardController@index
 *  - `admin.financeiro`  → GET → AdminController@fechamento
 *  - `companies.show`    → GET {company} → CompanyController@show
 *  - `portfolio.own`     → GET → PortfolioController@own
 *
 * Também prova (T5) que a query bruta agregada por empresa em `adman_metrics`
 * continua íntegra — se Plans 60-02/03/04 tivessem tocado o schema por
 * engano, T5 quebraria antes das rotas.
 *
 * T6 prova coexistência DI: `AdmanService` legado E `AdmanMetricsProvider`
 * novo resolvidos no mesmo container sem interferência.
 */
class BaselineRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->company = Company::factory()->create([
            'adman_account_id' => '12345',
            'name'             => 'Empresa Baseline',
        ]);

        // 1 row em `adman_metrics` no dia D-1 pra alimentar as queries de
        // agregação dos controllers legados. Todos os controllers atuais
        // aceitam periodo vazio, mas 1 row garante que a agregação retorne
        // algo diferente de vazio (path completo exercitado).
        AdmanMetric::create([
            'company_id'          => $this->company->id,
            'reference_date'      => now()->subDay()->toDateString(),
            'revenue'             => 100.00,
            'ad_spend'            => 10.00,
            'sold_quantity'       => 5,
            'net_billing'         => 90.00,
            'sales_fee'           => 5.00,
            'taxes'               => 4.00,
            'shipping_cost'       => 2.00,
            'product_cost'        => 30.00,
            'contribution_margin' => 49.00,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // T1 — GET route('dashboard')
    // ═══════════════════════════════════════════════════════════════

    public function test_route_dashboard_ainda_retorna_200(): void
    {
        if (! Route::has('dashboard')) {
            $this->fail(
                'Rota `dashboard` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara SC #4 do ROADMAP.',
            );
        }

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // T2 — GET route('admin.financeiro')
    // ═══════════════════════════════════════════════════════════════

    public function test_route_admin_financeiro_ainda_retorna_200(): void
    {
        if (! Route::has('admin.financeiro')) {
            $this->fail(
                'Rota `admin.financeiro` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara SC #4 do ROADMAP.',
            );
        }

        $response = $this->actingAs($this->admin)->get(route('admin.financeiro'));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // T3 — GET route('companies.show', {company})
    // ═══════════════════════════════════════════════════════════════

    public function test_route_companies_show_ainda_retorna_200(): void
    {
        if (! Route::has('companies.show')) {
            $this->fail(
                'Rota `companies.show` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara SC #4 do ROADMAP.',
            );
        }

        $response = $this->actingAs($this->admin)->get(route('companies.show', $this->company));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // T4 — GET route('portfolio.own')
    // ═══════════════════════════════════════════════════════════════

    public function test_route_portfolio_ainda_retorna_200(): void
    {
        // Portfolio: rota real hoje é `portfolio.own` (validado via route:list
        // 2026-07-07). Se plan futuro renomear para `portfolio.index`, o fail
        // abaixo explicita a atualização exigida — NÃO usar markTestSkipped
        // (baseline não permite skip silencioso — SC #4 do ROADMAP).
        if (! Route::has('portfolio.own')) {
            $this->fail(
                'Rota `portfolio.own` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara SC #4 do ROADMAP.',
            );
        }

        $response = $this->actingAs($this->admin)->get(route('portfolio.own'));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // T5 — Query bruta AdmanMetric agregada por company_id continua íntegra
    // ═══════════════════════════════════════════════════════════════

    public function test_query_admanmetric_agregada_por_company_ainda_funciona(): void
    {
        // Adiciona 2 rows extras pra garantir agregação real (não apenas 1 row do setUp).
        AdmanMetric::create([
            'company_id'     => $this->company->id,
            'reference_date' => now()->subDays(2)->toDateString(),
            'revenue'        => 200.00,
            'ad_spend'       => 20.00,
        ]);
        AdmanMetric::create([
            'company_id'     => $this->company->id,
            'reference_date' => now()->subDays(3)->toDateString(),
            'revenue'        => 300.00,
            'ad_spend'       => 30.00,
        ]);

        // Query bruta idêntica ao padrão dos controllers legados (DashboardController,
        // PerformanceController, AdminController): SUM(revenue) GROUP BY company_id
        // em janela recente.
        $result = DB::table('adman_metrics')
            ->select('company_id', DB::raw('SUM(revenue) as revenue_total'), DB::raw('SUM(ad_spend) as ad_spend_total'))
            ->where('company_id', $this->company->id)
            ->whereBetween('reference_date', [now()->subDays(7)->toDateString(), now()->toDateString()])
            ->groupBy('company_id')
            ->first();

        $this->assertNotNull($result, 'Query bruta agregada deve retornar 1 linha (schema íntegro).');
        $this->assertSame($this->company->id, (int) $result->company_id);
        // 100 (setUp) + 200 + 300 = 600
        $this->assertEqualsWithDelta(600.0, (float) $result->revenue_total, 0.01);
        // 10 (setUp) + 20 + 30 = 60
        $this->assertEqualsWithDelta(60.0, (float) $result->ad_spend_total, 0.01);
    }

    // ═══════════════════════════════════════════════════════════════
    // T6 — Coexistência DI: AdmanService legado + AdmanMetricsProvider novo
    // ═══════════════════════════════════════════════════════════════

    public function test_admanservice_legado_pode_conviver_com_admanmetricsprovider_novo(): void
    {
        // Ambos resolvem via container sem interferência de binding.
        $legacy = $this->app->make(AdmanService::class);
        $novo   = $this->app->make(AdmanMetricsProvider::class);

        $this->assertInstanceOf(AdmanService::class, $legacy);
        $this->assertInstanceOf(AdmanMetricsProvider::class, $novo);

        // Sanity que o novo provider tem contrato ativo (name() foi implementado).
        $this->assertSame('adman', $novo->name());
    }
}
