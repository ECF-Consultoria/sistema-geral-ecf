<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
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

    /**
     * Fase 137 (D-01) — a faixa deixou de vir da constante `FAIXAS` e passou
     * a depender de a empresa ter contrato ativo com um serviço "dono de
     * tabela" (setor financeiro OU plataforma preenchida). A migration
     * `2026_09_02_100003_seed_faixas_faturamento_iniciais` já semeia as 7
     * faixas de "Gestão" (criada pelo catálogo base da Fase 14) — este
     * helper só ajusta `plataforma`/`setor` para o serviço virar candidato
     * no resolver, mesmo padrão de `Phase137FaixaResolverTest`.
     */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    /** Vincula a empresa ao serviço "dono de tabela" com um contrato ativo. */
    private function vincularGestao(Company $company): ContratoServico
    {
        $servico = $this->criarServicoGestao();

        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);
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
        $this->vincularGestao($company);

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
        $this->vincularGestao($company);

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
        // Fase 137 (D-06): o estado antigo 'sem_dados' virou 'sem_faturamento'
        // (mesma precedência do comando fechamento:consolidar-mes) — empresa
        // com integração mas sem métrica no mês-calendário fechado.
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.estado', 'sem_faturamento')
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
        $this->vincularGestao($company);

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
        $this->vincularGestao($company);

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
        $this->vincularGestao($company);

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
        $this->vincularGestao($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => Carbon::now()->subMonth()->toDateString(), 'revenue' => 1000000.00, 'synced_at' => now(), 'raw_data' => []]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        // Prova dupla de D-06: a métrica do mês anterior não conta (mês-
        // calendário fechado, nunca janela móvel) — e o estado 'sem_dados'
        // virou 'sem_faturamento' (Fase 137, mesma precedência do comando
        // fechamento:consolidar-mes).
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Financeiro')
            ->where('companies.0.estado', 'sem_faturamento')
        );
    }
}
