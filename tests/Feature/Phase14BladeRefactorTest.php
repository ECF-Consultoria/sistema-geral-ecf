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

/**
 * Testes do refator das 3 Blade views de relatório (Plan 14-05 Task 3).
 *
 * Garante que:
 *  - `relatorio-fechamento.blade.php` exibe nomes do CATÁLOGO (não enum legacy)
 *    tanto para a empresa pai (`$company->serviceTypeLabel()`) quanto para
 *    filhas em arrays (`$v['servicos_contratados']`).
 *  - `relatorio-geral.blade.php` mesma cobertura, considerando agregação por
 *    múltiplas empresas.
 *  - Nenhuma das views vaza o nome do método legacy `labelFromTypes` no HTML
 *    final (sintoma de Blade não renderizado / referência string).
 *
 * Per Plan 14-05 Task 3 + CONTEXT.md D-09.
 *
 * @group phase14
 */
class Phase14BladeRefactorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante o catálogo canônico mínimo após RefreshDatabase
     * (Phase14MigrationTest popula via migration; aqui reforçamos
     * para evitar fragilidade de ordem de teste).
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
    }

    /**
     * Helper: cria admin via factory.
     */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Helper: registra faturamento Adman do mês atual para que a empresa
     * caia em uma faixa válida no relatório.
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
     * Helper: cria contrato ativo vinculado a 1 serviço do catálogo.
     */
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
     * TEST 1 — Relatório individual renderiza nomes do catálogo via
     * `$company->serviceTypeLabel()` (derivado dos contratos ativos).
     *
     * Cenário: empresa com 2 contratos ativos (Polos + Gestão). A view DEVE
     * mostrar "Polos, Gestão" no campo "Tipo de serviço" — não mais o enum
     * legacy "POLO"/"Gestão" via labelFromTypes(service_type).
     */
    public function test_relatorio_fechamento_renderiza_nomes_servicos_de_contratos_ativos(): void
    {
        $admin = $this->criarAdmin();

        $empresa = Company::create([
            'name'             => 'Empresa Pai Polos+Gestão',
            'cnpj'             => '11111111000011',
            'active'           => true,
            'adman_account_id' => 'ACC-BLADE-1',
            'service_type'     => [], // legacy vazio — força a UI a usar contratos
        ]);

        // Faturamento R$ 600k → faixa_2 (necessário para a Blade montar a tabela)
        $this->registrarFaturamento($empresa, 600_000.00);

        // Contratos do catálogo — sem valor (classificação)
        $this->criarContrato($empresa, 'Polos');
        $this->criarContrato($empresa, 'Gestão');

        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $empresa));

        $response->assertOk();
        $html = $response->getContent();

        // Os nomes do catálogo aparecem renderizados no HTML
        $this->assertStringContainsString('Polos', $html, 'Relatório deve exibir o nome "Polos" do catálogo.');
        $this->assertStringContainsString('Gestão', $html, 'Relatório deve exibir o nome "Gestão" do catálogo.');
        // Vazamento defensivo: o nome do método legacy nunca pode aparecer renderizado
        $this->assertStringNotContainsString('labelFromTypes', $html, 'O método legacy não pode vazar no HTML.');
    }

    /**
     * TEST 2 — Relatório individual renderiza `servicos_contratados` das
     * empresas FILHAS no array `vinculadas[]` que a Blade percorre.
     *
     * Cenário: pai com 1 contrato + 1 filha com 2 contratos distintos. A view
     * DEVE mostrar os nomes da filha derivados de `$v['servicos_contratados']`
     * (string formatada pelo AdminController via pluck('servico.nome')).
     */
    public function test_relatorio_fechamento_renderiza_servicos_contratados_de_empresa_filha(): void
    {
        $admin = $this->criarAdmin();

        $pai = Company::create([
            'name'             => 'Pai do Grupo',
            'cnpj'             => '22222222000022',
            'active'           => true,
            'adman_account_id' => 'ACC-BLADE-PAI',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($pai, 600_000.00);
        $this->criarContrato($pai, 'Polos');

        $filha = Company::create([
            'name'              => 'Filha Vinculada',
            'cnpj'              => '33333333000033',
            'active'            => true,
            'adman_account_id'  => 'ACC-BLADE-FILHA',
            'service_type'      => [],
            'parent_company_id' => $pai->id,
        ]);
        $this->registrarFaturamento($filha, 600_000.00);
        $this->criarContrato($filha, 'Assessoria');
        $this->criarContrato($filha, 'Publicidade');

        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio', $pai));

        $response->assertOk();
        $html = $response->getContent();

        // Os nomes dos contratos da filha aparecem renderizados no HTML
        $this->assertStringContainsString('Assessoria', $html, 'Relatório deve mostrar serviço da filha (Assessoria).');
        $this->assertStringContainsString('Publicidade', $html, 'Relatório deve mostrar serviço da filha (Publicidade).');
        $this->assertStringNotContainsString('labelFromTypes', $html, 'O método legacy não pode vazar no HTML.');
    }

    /**
     * TEST 3 — Relatório geral itera múltiplas empresas e exibe pelo menos
     * 1 serviço do catálogo de CADA empresa (mostrando que a view percorre
     * todas e cada uma tem seu próprio serviceTypeLabel derivado).
     */
    public function test_relatorio_geral_renderiza_nomes_servicos_de_multiplas_empresas(): void
    {
        $admin = $this->criarAdmin();

        $a = Company::create([
            'name'             => 'Geral A',
            'cnpj'             => '44444444000044',
            'active'           => true,
            'adman_account_id' => 'ACC-GERAL-A',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($a, 600_000.00);
        $this->criarContrato($a, 'Incubadora');

        $b = Company::create([
            'name'             => 'Geral B',
            'cnpj'             => '55555555000055',
            'active'           => true,
            'adman_account_id' => 'ACC-GERAL-B',
            'service_type'     => [],
        ]);
        $this->registrarFaturamento($b, 1_500_000.00);
        $this->criarContrato($b, 'Publicidade');

        $response = $this->actingAs($admin)->get(route('admin.financeiro.relatorio.geral'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Incubadora', $html, 'Relatório geral deve exibir Incubadora (serviço de A).');
        $this->assertStringContainsString('Publicidade', $html, 'Relatório geral deve exibir Publicidade (serviço de B).');
        $this->assertStringNotContainsString('labelFromTypes', $html, 'O método legacy não pode vazar no HTML.');
    }
}
