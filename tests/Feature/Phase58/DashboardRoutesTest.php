<?php

namespace Tests\Feature\Phase58;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 58 — Feature tests das rotas dashboard multi-marketplace.
 * RED antes das rotas serem implementadas na Task 2.
 */
class DashboardRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_ecf_route_retorna_200(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_mercadolivre_route_retorna_200(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('mercadolivre.dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_shopee_route_retorna_200(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        // X-Inertia simula navegacao client-side — evita depender do
        // manifest Vite do componente Dashboard/ShopeeShell.jsx, que so
        // vai existir no Plan 58-02 (backend puro nesta plan). Version
        // precisa bater com o hash do manifest, senao 409.
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('shopee.dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_amazon_route_retorna_200(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        // X-Inertia simula navegacao client-side — evita depender do
        // manifest Vite do componente Dashboard/AmazonShell.jsx, que so
        // vai existir no Plan 58-02 (backend puro nesta plan). Version
        // precisa bater com o hash do manifest, senao 409.
        $response = $this->actingAs($admin)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $this->inertiaVersion()])
            ->get(route('amazon.dashboard'));

        $response->assertOk();
    }

    public function test_dashboard_legacy_route_continua_ativa(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get('/dashboard');

        $response->assertOk();
    }

    /**
     * Versao de assets calculada pelo middleware Inertia (mesma logica de
     * HandleInertiaRequests::version() — hash de ASSET_URL quando setado,
     * senao hash do manifest.json). Sem isso, requests com X-Inertia
     * recebem 409 (conflito de versao).
     */
    private function inertiaVersion(): string
    {
        return (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }
}
