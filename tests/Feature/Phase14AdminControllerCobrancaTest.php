<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Testes do refator de AdminController (Plan 14-03 Task 3).
 *
 * Garante que:
 *  - cobranca_mensal calculada via CobrancaCalculator::novo bate (até R$ 0,01)
 *    com o cálculo legacy (faixa + additional_service_price) — golden test SVC-02
 *  - O comando `phase14:verificar-cobranca` retorna 0 divergências quando o
 *    catálogo + contratos estão consistentes com os campos legacy
 *  - As props Inertia de `/administrativo/financeiro` agora incluem a chave
 *    nova `servicos_contratados` ao lado das chaves legacy (coexistência Wave 2)
 *
 * Per Plan 14-03 Task 3 + CONTEXT.md D-03/D-08/D-10.
 *
 * @group phase14
 */
class Phase14AdminControllerCobrancaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria 1 admin + actingAs (helper consistente com AdminFechamentoControllerTest).
     */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Helper: registra faturamento Adman do mês atual.
     */
    private function registrarFaturamento(Company $company, float $revenue): void
    {
        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::now()->toDateString(),
            'revenue'        => $revenue,
            'synced_at'      => now(),
            'raw_data'       => [],
        ]);
    }

    /**
     * Helper: cria contrato vinculado a 1 servico do catálogo.
     */
    private function criarContrato(Company $company, string $nomeServico, float $valor): ContratoServico
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
     * TEST 1 — Golden: cobranca_mensal calculada pelo refator bate com a fórmula legacy.
     *
     * Cenário: empresa com additional_service_price=200 (legacy) + 1 contrato mensal
     * no novo modelo com valor_contratado=200. Faturamento R$ 600k → faixa_2 → R$ 4.500.
     *
     * Legacy: 4500 + 200 = 4700
     * Novo:   4500 + SUM(contratos ativos mensais=200) = 4700
     *
     * Asserção: prop Inertia `companies[0].cobranca_mensal === 4700.0` (até R$ 0,01).
     */
    public function test_cobranca_mensal_legacy_e_novo_modelo_batem_para_empresa_com_additional_service(): void
    {
        $admin = $this->criarAdmin();

        // Empresa com additional_service_price=200 (legacy preservado)
        $empresa = Company::create([
            'name'                     => 'Empresa Golden',
            'cnpj'                     => '11111111000001',
            'active'                   => true,
            'adman_account_id'         => 'ACC-GOLDEN',
            'service_type'             => ['polos'],
            'additional_service'       => 'Treinamento',
            'additional_service_price' => 200.00,
            'contract_start'           => '2026-01-01',
        ]);

        // Faturamento → faixa_2 (R$ 500k–999k) = R$ 4.500
        $this->registrarFaturamento($empresa, 600_000.00);

        // Catálogo + contrato derivado (RefreshDatabase já populou os 6 servicos canônicos
        // via Migration 1). Criamos os contratos manualmente para simular o estado
        // pós-migration 2 — Polos sem cobrança + Treinamento R$ 200 (additional).
        Servico::firstOrCreate(['nome' => 'Treinamento'], [
            'valor_padrao' => 200.00, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
        ]);
        $this->criarContrato($empresa, 'Polos', 0.0);
        $this->criarContrato($empresa, 'Treinamento', 200.00);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();

        // Extrai valor via viewData para comparação numérica tolerante a int/float
        // (Inertia serializa 4700.0 como 4700 em JSON quando não há decimal).
        $companies = $response->viewData('page')['props']['companies'];
        $this->assertCount(1, $companies);
        $this->assertEqualsWithDelta(
            4700.0,
            (float) $companies[0]['cobranca_mensal'],
            0.01,
            'cobranca_mensal pós-refator deve bater com legacy (4500 faixa + 200 additional = 4700) até R$ 0,01',
        );
    }

    /**
     * TEST 2 — Comando `phase14:verificar-cobranca` retorna 0 divergências
     * quando o estado é consistente entre legacy e novo modelo.
     *
     * Cenário cumulativo (D-04): cria 3 empresas com configurações distintas,
     * popula manualmente os contratos para refletir os campos legacy e roda
     * o comando.
     */
    public function test_phase14_verificar_cobranca_retorna_zero_divergencias_apos_migrations(): void
    {
        // 1) Empresa com additional_service preenchido + service_type=['polos']
        $a = Company::create([
            'name'                     => 'Consistente A',
            'cnpj'                     => '22222222000002',
            'active'                   => true,
            'adman_account_id'         => 'ACC-A',
            'service_type'             => ['polos'],
            'additional_service'       => 'Treinamento Custom',
            'additional_service_price' => 350.00,
        ]);
        $this->registrarFaturamento($a, 300_000.00); // faixa_1 → R$ 3.000

        // 2) Empresa só com additional_service (sem service_type)
        $b = Company::create([
            'name'                     => 'Consistente B',
            'cnpj'                     => '33333333000003',
            'active'                   => true,
            'adman_account_id'         => 'ACC-B',
            'service_type'             => [],
            'additional_service'       => 'Consultoria',
            'additional_service_price' => 120.00,
        ]);
        $this->registrarFaturamento($b, 600_000.00); // faixa_2 → R$ 4.500

        // 3) Empresa sem nada legacy (zero contratos)
        $c = Company::create([
            'name'             => 'Consistente C',
            'cnpj'             => '44444444000004',
            'active'           => true,
            'adman_account_id' => 'ACC-C',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($c, 1_500_000.00); // faixa_3 → R$ 6.000

        // Cria contratos manualmente espelhando o que a migration 2 faria.
        Servico::firstOrCreate(['nome' => 'Treinamento Custom'], [
            'valor_padrao' => 350.00, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
        ]);
        Servico::firstOrCreate(['nome' => 'Consultoria'], [
            'valor_padrao' => 120.00, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true,
        ]);
        $this->criarContrato($a, 'Polos', 0.0);
        $this->criarContrato($a, 'Treinamento Custom', 350.00);
        $this->criarContrato($b, 'Consultoria', 120.00);
        // C não recebe contrato — D-04 item 3.

        $exit = Artisan::call('phase14:verificar-cobranca', ['--abort-on-divergence' => true]);

        $this->assertEquals(
            0,
            $exit,
            'Helper novo deve bater com o legacy (R$ 0,01 tolerância) em todas as 3 empresas — comando NÃO deve abortar.',
        );
        $this->assertStringContainsString('0 divergências', Artisan::output());
    }

    /**
     * TEST 3 — Props Inertia de fechamento incluem `servicos_contratados`
     * ao lado das chaves legacy (coexistência Wave 2).
     */
    public function test_fechamento_props_inclui_servicos_contratados(): void
    {
        $admin = $this->criarAdmin();

        $empresa = Company::create([
            'name'             => 'Empresa Coexistencia',
            'cnpj'             => '55555555000005',
            'active'           => true,
            'adman_account_id' => 'ACC-COEX',
            'service_type'     => ['publicacao'],
        ]);
        // Sem faturamento — estado 'sem_dados', mas as props ainda têm contratos.
        $this->criarContrato($empresa, 'Publicação', 0.0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();

        $companies = $response->viewData('page')['props']['companies'];
        $this->assertCount(1, $companies);
        // Chave nova: array com ao menos 1 contrato (Publicação)
        $this->assertIsArray($companies[0]['servicos_contratados']);
        $this->assertCount(1, $companies[0]['servicos_contratados']);
        $this->assertEquals('Publicação', $companies[0]['servicos_contratados'][0]['servico_nome']);
        $this->assertEqualsWithDelta(
            0.0,
            (float) $companies[0]['servicos_contratados'][0]['valor_contratado'],
            0.01,
            'valor_contratado do contrato deve ser 0 (servico de classificação Polos/Publicação/etc).',
        );
        // Chaves legacy permanecem (coexistência) — Plan 14-06 remove
        $this->assertArrayHasKey('service_type', $companies[0]);
    }
}
