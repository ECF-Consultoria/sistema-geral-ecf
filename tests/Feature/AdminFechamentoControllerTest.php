<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFechamentoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_fechamento_retorna_empresas_ativas_com_has_adman(): void
    {
        $admin = $this->criarAdmin();
        Company::create(['name' => 'Empresa A', 'cnpj' => '11111111111111', 'active' => true, 'adman_account_id' => '123']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->has('companies', 1)
            ->where('companies.0.has_adman', true)
        );
    }

    public function test_empresa_sem_adman_recebe_has_adman_false(): void
    {
        $admin = $this->criarAdmin();
        Company::create(['name' => 'Empresa B', 'cnpj' => '22222222222222', 'active' => true]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->has('companies', 1)
            ->where('companies.0.has_adman', false)
        );
    }

    public function test_empresa_inativa_nao_aparece(): void
    {
        $admin = $this->criarAdmin();
        Company::create(['name' => 'Empresa Inativa', 'cnpj' => '33333333333333', 'active' => false]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->has('companies', 0)
        );
    }

    public function test_update_persiste_service_type(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa C', 'cnpj' => '44444444444444', 'active' => true]);

        $response = $this->actingAs($admin)
            ->patch("/administrativo/financeiro/{$company->id}", ['service_type' => 'polo']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'service_type' => 'polo']);
    }

    public function test_update_rejeita_service_type_invalido(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa D', 'cnpj' => '55555555555555', 'active' => true]);

        $response = $this->actingAs($admin)
            ->patch("/administrativo/financeiro/{$company->id}", ['service_type' => 'invalido']);

        $response->assertStatus(422);
    }

    public function test_update_persiste_datas_contrato(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa E', 'cnpj' => '66666666666666', 'active' => true]);

        $response = $this->actingAs($admin)
            ->patch("/administrativo/financeiro/{$company->id}", [
                'contract_start' => '2026-01-01',
                'contract_end'   => '2026-12-31',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('companies', [
            'id'             => $company->id,
            'contract_start' => '2026-01-01',
            'contract_end'   => '2026-12-31',
        ]);
    }

    public function test_update_rejeita_contract_end_anterior(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa F', 'cnpj' => '77777777777777', 'active' => true]);

        $response = $this->actingAs($admin)
            ->patch("/administrativo/financeiro/{$company->id}", [
                'contract_start' => '2026-01-01',
                'contract_end'   => '2025-01-01',
            ]);

        $response->assertStatus(422);
    }

    public function test_nao_admin_recebe_403(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($consultor)->get('/administrativo/financeiro');

        $response->assertStatus(403);
    }
}
