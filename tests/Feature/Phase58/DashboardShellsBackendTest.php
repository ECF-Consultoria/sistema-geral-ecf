<?php

namespace Tests\Feature\Phase58;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 58 — Feature tests de assinatura Inertia dos shells Shopee/Amazon.
 * RED antes das rotas serem implementadas na Task 2.
 *
 * Estes tests validam apenas o contrato do backend (component name + props);
 * os componentes JSX reais ainda nao existem (ficam pro Plan 58-02).
 */
class DashboardShellsBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_shell_renderiza_componente_e_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('shopee.dashboard'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/ShopeeShell')
            ->where('marketplace', 'shopee')
            ->where('label', 'Shopee')
        );
    }

    public function test_amazon_shell_renderiza_componente_e_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('amazon.dashboard'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard/AmazonShell')
            ->where('marketplace', 'amazon')
            ->where('label', 'Amazon')
        );
    }
}
