<?php

namespace Tests\Feature\Phase58;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 58 v13.0 — Smoke test E2E de navegacao multi-marketplace. Valida os
 * 4 caminhos completos (route -> controller -> componente Inertia) apos
 * NAV_TREE atualizado. Pega regressao GROSSEIRA de wiring — nao valida
 * valores exatos de KPI (deferido para UAT em prod).
 */
class DashboardNavigationSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ecf_dashboard_renderiza_shell_em_construcao(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create(['marketplace' => 'meli']);
        CompanyMarketplace::factory()->create([
            'company_id' => $company->id,
            'marketplace' => 'meli',
            'is_primary' => true,
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard'));

        $response->assertOk();
        // Ajuste pos-UAT Phase 58: ECF Dashboard renderiza EcfShell (em construcao)
        // pra diferenciar active-state do sidebar do /dashboard/mercadolivre. A
        // agregacao real cross-marketplace fica pra v14+ (CONTEXT §2 + Deferred).
        $response->assertInertia(fn (Assert $page) => $page->component('Dashboard/EcfShell'));
    }

    public function test_mercadolivre_dashboard_renderiza_componente_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create(['marketplace' => 'meli']);
        CompanyMarketplace::factory()->create([
            'company_id' => $company->id,
            'marketplace' => 'meli',
            'is_primary' => true,
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        // Dashboard ML forca filter marketplace=meli via $request->merge (Plan 58-01) mas reutiliza Dashboard/Admin
        $response->assertInertia(fn (Assert $page) => $page->component('Dashboard/Admin'));
    }

    public function test_shopee_dashboard_renderiza_shell(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('shopee.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/ShopeeShell')
            ->where('marketplace', 'shopee')
            ->where('label', 'Shopee')
        );
    }

    public function test_amazon_dashboard_renderiza_shell(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('amazon.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/AmazonShell')
            ->where('marketplace', 'amazon')
            ->where('label', 'Amazon')
        );
    }

    public function test_legacy_dashboard_ainda_navegavel(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        // Rota canonical /dashboard permanece registrada (CONTEXT §5) para deep links e bookmarks legados
        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
    }
}
