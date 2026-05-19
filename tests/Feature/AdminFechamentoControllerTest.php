<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
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

    public function test_empresa_ok_recebe_faturamento_somado(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa OK', 'cnpj' => '10000000000001', 'active' => true, 'adman_account_id' => 'ACC001']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->startOfMonth()->toDateString(), 'revenue' => 500000.00, 'synced_at' => now(), 'raw_data' => []]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->toDateString(),                  'revenue' => 500000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.faturamento', 1000000)
            ->where('companies.0.estado', 'ok')
        );
    }

    public function test_empresa_ok_recebe_periodo_coberto(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa Periodo', 'cnpj' => '10000000000002', 'active' => true, 'adman_account_id' => 'ACC002']);

        $inicio = Carbon::now()->startOfMonth();
        $fim    = Carbon::now();

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => $inicio->toDateString(), 'revenue' => 100000.00, 'synced_at' => now(), 'raw_data' => []]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => $fim->toDateString(),    'revenue' => 100000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.periodo_inicio', $inicio->format('d/m'))
            ->where('companies.0.periodo_fim',    $fim->format('d/m'))
        );
    }

    public function test_empresa_sem_dados_recebe_estado_sem_dados(): void
    {
        $admin   = $this->criarAdmin();
        Company::create(['name' => 'Empresa Sem Dados', 'cnpj' => '10000000000003', 'active' => true, 'adman_account_id' => 'ACC003']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.estado', 'sem_dados')
            ->where('companies.0.faturamento', null)
            ->where('companies.0.faixa', null)
        );
    }

    public function test_empresa_sem_adman_recebe_estado_sem_integracao(): void
    {
        $admin   = $this->criarAdmin();
        Company::create(['name' => 'Empresa Sem Adman', 'cnpj' => '10000000000004', 'active' => true]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.estado', 'sem_integracao')
            ->where('companies.0.faturamento', null)
        );
    }

    public function test_fatura_ate_499k_retorna_faixa_correta(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa 300k', 'cnpj' => '10000000000005', 'active' => true, 'adman_account_id' => 'ACC005']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->toDateString(), 'revenue' => 300000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.faixa', 'faixa_1')
            ->where('companies.0.valor_mensal', 3000)
        );
    }

    public function test_fatura_500k_999k_retorna_faixa_correta(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa 700k', 'cnpj' => '10000000000006', 'active' => true, 'adman_account_id' => 'ACC006']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->toDateString(), 'revenue' => 700000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.faixa', 'faixa_2')
            ->where('companies.0.valor_mensal', 4500)
        );
    }

    public function test_fatura_acima_5m_retorna_maxima(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa 5.5M', 'cnpj' => '10000000000007', 'active' => true, 'adman_account_id' => 'ACC007']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->toDateString(), 'revenue' => 5500000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.faixa', 'maxima')
            ->where('companies.0.valor_mensal', 12000)
        );
    }

    public function test_metrica_fora_do_mes_nao_conta(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::create(['name' => 'Empresa Mes Anterior', 'cnpj' => '10000000000008', 'active' => true, 'adman_account_id' => 'ACC008']);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->subMonth()->toDateString(), 'revenue' => 1000000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.estado', 'sem_dados')
        );
    }
}
