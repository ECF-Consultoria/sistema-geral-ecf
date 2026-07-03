<?php

namespace Tests\Feature\Phase58;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 58 — Feature tests do whitelist do filtro `?marketplace=`.
 * RED antes das rotas serem implementadas na Task 2.
 *
 * Threat model T-58-01 — injection via query param. Whitelist aceita
 * apenas meli/shopee/amazon; qualquer outro valor deve retornar 422.
 */
class DashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_marketplace_meli_aceito(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard', ['marketplace' => 'meli']));

        $response->assertOk();
    }

    public function test_filter_marketplace_shopee_aceito(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard', ['marketplace' => 'shopee']));

        $response->assertOk();
    }

    public function test_filter_marketplace_amazon_aceito(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard', ['marketplace' => 'amazon']));

        $response->assertOk();
    }

    public function test_filter_marketplace_invalido_rejeitado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Company::factory()->create(['marketplace' => 'meli']);

        $response = $this->actingAs($admin)->get(route('ecf.dashboard', ['marketplace' => 'evil<script>']));

        $response->assertStatus(422);
    }
}
