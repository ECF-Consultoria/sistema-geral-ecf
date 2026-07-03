<?php

namespace Tests\Feature\Phase57;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 57 v13.0 — Smoke test do /sugadores.
 *
 * Objetivo: pegar regressao GROSSEIRA (SQL error / accessor quebrado)
 * causada pelo modelo N:N novo em rotas Sugadores.
 */
class SugadoresSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_listagem_sugadores_carrega_com_pivot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create([
            'marketplace'      => 'meli',
            'adman_account_id' => 'ADM_S1',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'adman_id'    => 'ADM_S1',
            'is_primary'  => true,
        ]);

        $response = $this->actingAs($admin)->get('/sugadores');

        $this->assertLessThan(500, $response->status(), '/sugadores nao deve retornar 5xx');
    }

    public function test_drilldown_sugadores_por_empresa_carrega(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::factory()->create([
            'marketplace'      => 'meli',
            'adman_account_id' => 'ADM_S2',
        ]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'adman_id'    => 'ADM_S2',
            'is_primary'  => true,
        ]);

        $response = $this->actingAs($admin)->get("/sugadores/empresa/{$company->id}");

        // 200 (com dados) ou 404 (sem sugadores/permissao) ou 302 (redirect)
        // sao aceitaveis — o teste falha SOMENTE em 5xx (regressao real).
        $this->assertLessThan(
            500,
            $response->status(),
            'Rota /sugadores/empresa/{id} nao deve retornar 5xx'
        );
    }
}
