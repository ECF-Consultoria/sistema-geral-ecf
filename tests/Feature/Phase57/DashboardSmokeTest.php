<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Smoke test do Dashboard.
 *
 * Objetivo: pegar regressao GROSSEIRA (SQL error, undefined method,
 * null pointer) causada pelo modelo N:N novo. Nao valida valores
 * especificos — isso eh UAT em prod.
 */
class DashboardSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_carrega_com_pivot_preenchida(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create([
            'marketplace'      => 'meli',
            'adman_account_id' => 'ADM_123',
            'ml_store_id'      => 'ML_456',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'store_id'    => 'ML_456',
            'adman_id'    => 'ADM_123',
            'is_primary'  => true,
            'active'      => true,
        ]);

        $response = $this->actingAs($admin)->get('/dashboard');

        $this->assertLessThan(500, $response->status(), 'Dashboard nao deve retornar 5xx');
    }

    public function test_dashboard_carrega_sem_pivot_via_fallback_flat(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Company::factory()->create([
            'marketplace'      => 'meli',
            'adman_account_id' => 'ADM_LEGACY',
            'ml_store_id'      => 'ML_LEGACY',
        ]);
        // NENHUMA row em company_marketplaces (fallback flat testado)

        $response = $this->actingAs($admin)->get('/dashboard');

        $this->assertLessThan(500, $response->status(), 'Dashboard nao deve retornar 5xx no fallback flat');
    }
}
