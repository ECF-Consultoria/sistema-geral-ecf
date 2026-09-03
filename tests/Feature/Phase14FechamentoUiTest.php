<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Testes da UI Inertia do Fechamento (Plan 14-05 Task 4).
 *
 * Garante que após a refatoração do Admin/Financeiro.jsx:
 *  - O component Inertia continua sendo `Admin/Financeiro` (sem regressão)
 *  - Cada item em `companies[]` agora inclui a chave `servicos_contratados`
 *    (array de objetos com shape esperado pelo JSX)
 *  - O cálculo de `cobranca_mensal` SOMA o valor dos contratos mensais
 *    (não apenas a faixa) — preserva a invariante financeira SVC-02
 *  - A página passa `servicos_disponiveis` (catálogo) para popular o select
 *    do modal "Adicionar contrato"
 *
 * Per Plan 14-05 Task 4 + CONTEXT.md D-09 / SVC-03.
 *
 * @group phase14
 */
class Phase14FechamentoUiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante catálogo canônico mínimo pós RefreshDatabase.
     */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Publicação', 'Polos', 'Assessoria', 'Incubadora', 'Publicidade', 'Gestão'] as $nome) {
            Servico::firstOrCreate(
                ['nome' => $nome],
                ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true],
            );
        }

        // Fase 137 (D-01) — "Gestão" precisa ser candidata a "dona de
        // tabela" (setor financeiro + plataforma) para o
        // FechamentoFaixaResolver conseguir resolver uma faixa; a migration
        // `2026_09_02_100003_seed_faixas_faturamento_iniciais` já semeia as
        // 7 faixas para o serviço "Gestão" do catálogo base acima, então
        // basta ajustar plataforma/setor (mesmo padrão de
        // Phase137FaixaResolverTest).
        Servico::where('nome', 'Gestão')->update([
            'plataforma' => 'Mercado Livre',
            'setor'      => Servico::SETOR_PERFORMANCE,
        ]);
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

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
     * TEST 1 — Inertia component + shape de `servicos_contratados`.
     *
     * Cada objeto em `companies[N].servicos_contratados[]` deve ter as chaves
     * que o JSX consome: id, servico_id, servico_nome, valor_contratado,
     * tipo_cobranca, data_contratacao, data_vencimento, ativo.
     */
    public function test_financeiro_inertia_component_e_company_servicos_contratados_shape(): void
    {
        $admin = $this->criarAdmin();

        $empresa = Company::create([
            'name'             => 'Empresa Inertia',
            'cnpj'             => '66666666000066',
            'active'           => true,
            'adman_account_id' => 'ACC-INERTIA-1',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($empresa, 600_000.00);
        $this->criarContrato($empresa, 'Polos', 100.00);
        $this->criarContrato($empresa, 'Gestão', 50.00);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Financeiro')
            ->has('companies', 1)
            ->has('companies.0.servicos_contratados', 2, fn (Assert $servico) => $servico
                ->has('id')
                ->has('servico_id')
                ->has('servico_nome')
                ->has('valor_contratado')
                ->has('tipo_cobranca')
                ->has('data_contratacao')
                ->has('data_vencimento')
                ->has('ativo')
            ),
        );
    }

    /**
     * TEST 2 — Toda empresa em `companies[]` recebe a chave `servicos_contratados`
     * como ARRAY (mesmo quando vazia). Sem essa garantia o JSX quebraria ao
     * tentar acessar `.length` em null/undefined.
     */
    public function test_financeiro_companies_inclui_chave_servicos_contratados_em_todas_empresas(): void
    {
        $admin = $this->criarAdmin();

        // 1) Empresa sem contratos
        Company::create([
            'name'             => 'Sem Contratos',
            'cnpj'             => '77777777000077',
            'active'           => true,
            'adman_account_id' => 'ACC-N1',
            'service_type'     => [],
        ]);

        // 2) Empresa com 1 contrato
        $b = Company::create([
            'name'             => 'Um Contrato',
            'cnpj'             => '88888888000088',
            'active'           => true,
            'adman_account_id' => 'ACC-N2',
            'service_type'     => [],
        ]);
        $this->criarContrato($b, 'Publicação', 0.0);

        // 3) Empresa com 3 contratos
        $c = Company::create([
            'name'             => 'Tres Contratos',
            'cnpj'             => '99999999000099',
            'active'           => true,
            'adman_account_id' => 'ACC-N3',
            'service_type'     => [],
        ]);
        $this->criarContrato($c, 'Polos', 0.0);
        $this->criarContrato($c, 'Assessoria', 0.0);
        $this->criarContrato($c, 'Gestão', 0.0);

        // Fase 137 (Plano 07, Tarefa 3): pelo menos uma empresa em grupo do
        // Comercial — prova que a linha de grupo TAMBÉM traz
        // `servicos_contratados` (a suíte exige a chave em TODA linha).
        $grupo = CompanyGroup::create(['name' => 'Grupo Lyam', 'color' => '#000']);
        $d     = Company::create([
            'name'             => 'Grupo Membro D',
            'cnpj'             => '10101010000010',
            'active'           => true,
            'adman_account_id' => 'ACC-GD',
            'company_group_id' => $grupo->id,
            'service_type'     => [],
        ]);
        $e = Company::create([
            'name'             => 'Grupo Membro E',
            'cnpj'             => '20202020000020',
            'active'           => true,
            'adman_account_id' => 'ACC-GE',
            'company_group_id' => $grupo->id,
            'service_type'     => [],
        ]);
        $this->criarContrato($d, 'Gestão', 0.0);
        $this->criarContrato($e, 'Polos', 0.0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        // 3 empresas soltas + 1 linha de grupo (D + E agregadas) = 4.
        $this->assertCount(4, $companies);

        foreach ($companies as $i => $emp) {
            $this->assertArrayHasKey(
                'servicos_contratados',
                $emp,
                "Empresa/grupo #{$i} deve ter a chave servicos_contratados (mesmo quando vazia).",
            );
            $this->assertIsArray(
                $emp['servicos_contratados'],
                "servicos_contratados da empresa/grupo #{$i} deve ser array (não null).",
            );
        }

        // Conta os contratos por empresa (ordem pode variar — buscar por name)
        $byName = collect($companies)->keyBy('name');
        $this->assertCount(0, $byName['Sem Contratos']['servicos_contratados']);
        $this->assertCount(1, $byName['Um Contrato']['servicos_contratados']);
        $this->assertCount(3, $byName['Tres Contratos']['servicos_contratados']);

        // A linha de grupo não aparece por nome de empresa — aparece pelo
        // nome do CompanyGroup (D-08) — e soma a união dos serviços das
        // duas membros (Gestão + Polos).
        $linhaGrupo = collect($companies)->firstWhere('tipo', 'grupo');
        $this->assertNotNull($linhaGrupo, 'Deve existir uma linha tipo=grupo para o Grupo Lyam.');
        $this->assertSame('grupo', $linhaGrupo['tipo']);
        $this->assertCount(2, $linhaGrupo['servicos_contratados'], 'A linha de grupo soma os serviços das 2 membros (união).');
    }

    /**
     * TEST 3 — Invariante financeira (SVC-02): cobranca_mensal soma o valor
     * dos contratos mensais à faixa.
     *
     * Cenário: 1 empresa com faturamento R$ 600k (faixa_2 → R$ 4.500) + 1
     * contrato Polos mensal R$ 200. Expected: 4.500 + 200 = R$ 4.700.
     */
    public function test_financeiro_cobranca_mensal_soma_contratos_a_faixa(): void
    {
        $admin = $this->criarAdmin();

        $empresa = Company::create([
            'name'             => 'Empresa Soma',
            'cnpj'             => '12121212000012',
            'active'           => true,
            'adman_account_id' => 'ACC-SOMA',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($empresa, 600_000.00);
        $this->criarContrato($empresa, 'Polos', 200.00);
        // Fase 137 (D-01): sem contrato com um serviço "dono de tabela" a
        // empresa fica 'sem_tabela' e a faixa não entra na cobrança — Polos
        // não é candidato (nem plataforma nem setor financeiro).
        $this->criarContrato($empresa, 'Gestão', 0.0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $this->assertCount(1, $companies);

        $this->assertEqualsWithDelta(
            4700.0,
            (float) $companies[0]['cobranca_mensal'],
            0.01,
            'cobranca_mensal deve somar faixa_2 (4.500) + contrato mensal Polos (200) = 4.700.',
        );
    }

    /**
     * TEST 4 — A página passa `servicos_disponiveis` (catálogo) para popular
     * o select do modal "Adicionar contrato" — sem isso o usuário não consegue
     * cadastrar contrato na UI Admin/Financeiro.
     */
    public function test_financeiro_inclui_servicos_disponiveis_no_payload(): void
    {
        $admin = $this->criarAdmin();

        // Cria 1 servico extra (não-canônico) para verificar que TODOS os ativos
        // entram no payload.
        Servico::firstOrCreate(['nome' => 'Treinamento'], [
            'valor_padrao'  => 300.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
        ]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Financeiro')
            ->has('servicos_disponiveis')
            ->where(
                'servicos_disponiveis',
                fn ($servicos) => is_array($servicos) || $servicos instanceof \Illuminate\Support\Collection,
            ),
        );

        $props = $response->viewData('page')['props'];
        $this->assertArrayHasKey('servicos_disponiveis', $props);
        $nomes = collect($props['servicos_disponiveis'])->pluck('nome')->all();
        $this->assertContains('Polos',       $nomes);
        $this->assertContains('Treinamento', $nomes);
    }
}
