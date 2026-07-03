<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Smoke test do /performance.
 *
 * Objetivo: pegar regressao GROSSEIRA na rota de desempenho apos
 * introducao do modelo N:N.
 */
class PerformanceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_carrega_com_pivot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create([
            'marketplace' => 'meli',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'is_primary'  => true,
        ]);

        $response = $this->actingAs($admin)->get('/performance');

        $this->assertLessThan(500, $response->status(), '/performance nao deve retornar 5xx');
    }
}
