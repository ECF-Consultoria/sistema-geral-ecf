<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testes do refator de filtros do Plan 14-03 Task 2.
 *
 * Verifica:
 *  - MlbController::empresas() filtra empresas_pendentes via whereHas em
 *    contratos_servico → servicos.nome (Publicação/Polos/Assessoria)
 *  - CompanyController::index() filtra empresas_pendentes análogo
 *    (Publicidade/Gestão)
 *  - A chave nova `servicos_contratados` aparece no payload Inertia ao lado
 *    de `service_type` (coexistência Wave 2)
 *
 * Per Plan 14-03 Task 3 + CONTEXT.md D-01.
 *
 * @group phase14
 */
class Phase14MlbControllerFiltroTest extends TestCase
{
    use RefreshDatabase;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarContrato(Company $company, string $nomeServico, float $valor = 0.0): ContratoServico
    {
        $servico = Servico::where('nome', $nomeServico)->firstOrFail();
        return ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => $valor,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);
    }

    /**
     * TEST 1 — /mlb/empresas filtra pendentes via whereHas em contratos.
     *
     * Cenário:
     *  - Empresa A (pendente, Polos) → DEVE aparecer
     *  - Empresa B (pendente, Publicidade) → NÃO aparece (filtro do MlbController
     *    é só Publicação/Polos/Assessoria — Publicidade vai pro CompanyController)
     *  - Empresa C (ativa, Polos) → NÃO aparece (status != pendente)
     */
    public function test_mlb_empresas_filtra_pendentes_via_whereHas_contratos(): void
    {
        $admin = $this->criarAdmin();

        $a = Company::create([
            'name'             => 'Empresa A',
            'cnpj'             => '11111111000010',
            'active'           => true,
            'status'           => 'pendente',
            'service_type'     => ['polos'],
        ]);
        $b = Company::create([
            'name'             => 'Empresa B',
            'cnpj'             => '22222222000020',
            'active'           => true,
            'status'           => 'pendente',
            'service_type'     => ['publicidade'],
        ]);
        $c = Company::create([
            'name'             => 'Empresa C Ativa',
            'cnpj'             => '33333333000030',
            'active'           => true,
            'status'           => 'ativo',
            'service_type'     => ['polos'],
        ]);

        $this->criarContrato($a, 'Polos');
        $this->criarContrato($b, 'Publicidade');
        $this->criarContrato($c, 'Polos');

        $response = $this->actingAs($admin)->get('/mlb/empresas');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Mlb/Empresas')
            ->has('empresas_pendentes', 1)
            ->where('empresas_pendentes.0.name', 'Empresa A')
            // Chave nova: array de nomes de servicos contratados
            ->has('empresas_pendentes.0.servicos_contratados')
            ->where('empresas_pendentes.0.servicos_contratados.0', 'Polos')
        );
    }

    /**
     * TEST 2 — /companies filtra pendentes Publicidade/Gestão via whereHas.
     */
    public function test_companies_index_filtra_pendentes_publicidade_gestao(): void
    {
        $admin = $this->criarAdmin();

        $a = Company::create([
            'name'         => 'Empresa Publicidade',
            'cnpj'         => '44444444000040',
            'active'       => true,
            'status'       => 'pendente',
            'service_type' => ['publicidade'],
        ]);
        $b = Company::create([
            'name'         => 'Empresa Gestao',
            'cnpj'         => '55555555000050',
            'active'       => true,
            'status'       => 'pendente',
            'service_type' => ['gestao'],
        ]);
        $c = Company::create([
            'name'         => 'Empresa Polos Pendente',
            'cnpj'         => '66666666000060',
            'active'       => true,
            'status'       => 'pendente',
            'service_type' => ['polos'],
        ]);

        $this->criarContrato($a, 'Publicidade');
        $this->criarContrato($b, 'Gestão');
        $this->criarContrato($c, 'Polos');

        $response = $this->actingAs($admin)->get('/companies');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Companies/Index')
            ->has('empresas_pendentes', 2)
        );

        // Ordem decrescente por created_at — então a última criada (b) vem primeiro
        $pendentes = collect($response->viewData('page')['props']['empresas_pendentes']);
        $nomes = $pendentes->pluck('name')->sort()->values()->all();

        $this->assertEquals(['Empresa Gestao', 'Empresa Publicidade'], $nomes);

        // Polos NÃO aparece — filtro do CompanyController é só Publicidade/Gestão
        $this->assertNotContains('Empresa Polos Pendente', $nomes);
    }
}
