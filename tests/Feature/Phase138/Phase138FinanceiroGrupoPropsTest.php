<?php

namespace Tests\Feature\Phase138;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 138 Plano 04 — contrato de props de `AdminController::fechamento()`
 * (`/administrativo/financeiro`) depois de `paraGrupo()` (Plano 01) entrar
 * na agregação de grupo, nos DOIS ramos (mês aberto e mês fechado, D-11).
 *
 * Cobre os 5 casos pedidos pelo plano 138-04 (Tarefa 3):
 * (a) mês ABERTO, grupo sem tabela própria → herança visível (tabela_herdada_de_nome);
 * (b) mês ABERTO, grupo COM tabela própria → tabela_grupo_nome, sem herança;
 * (c) mês FECHADO → as mesmas chaves, derivadas do snapshot, sem recálculo;
 * (d) prop `faixas_por_grupo` lista só grupos com tabela própria;
 * (e) não-regressão: `faixas_por_servico` e as chaves da Fase 137 continuam presentes.
 */
class Phase138FinanceiroGrupoPropsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` já
     * semeia as 7 faixas de "Gestão" — mesmo helper de
     * `Phase137FinanceiroPropsTest`/`Phase138GrupoFaixaResolverTest`.
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

    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 0,
            'data_contratacao' => Carbon::now()->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    /** Tabela de grupo com 2 faixas — mesmo molde de Phase138GrupoFaixaResolverTest. */
    private function criarFaixasDeGrupo(CompanyGroup $grupo): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'             => 1,
            'limite_superior'   => 200_000.00,
            'valor'             => 5_000.00,
            'valor_e_piso'      => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id,
            'ordem'             => 2,
            'limite_superior'   => null,
            'valor'             => 10_000.00,
            'valor_e_piso'      => true,
        ]);
    }

    private function acharLinhaDeGrupo(array $companies): ?array
    {
        return collect($companies)->firstWhere('tipo', 'grupo');
    }

    // ─── (a) mês ABERTO, grupo sem tabela própria → herança visível ─────

    #[Test]
    public function mes_aberto_grupo_sem_tabela_propria_traz_heranca_da_empresa_ancora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Sem Tabela', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 250_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linhaGrupo = $this->acharLinhaDeGrupo($response->viewData('page')['props']['companies']);

        $this->assertNotNull($linhaGrupo);
        $this->assertNotSame('grupo', $linhaGrupo['tabela_origem'], 'Sem tabela própria do grupo, a origem tem que ser da âncora (servico/propria), nunca grupo.');
        $this->assertNull($linhaGrupo['tabela_grupo_nome'], 'Sem tabela própria, tabela_grupo_nome fica null.');
        $this->assertSame($membroA->name, $linhaGrupo['tabela_herdada_de_nome'], 'membroA faturou mais (300k > 250k) — é ela a âncora de quem a tabela foi herdada.');
    }

    // ─── (b) mês ABERTO, grupo COM tabela própria → sem herança ─────────

    #[Test]
    public function mes_aberto_grupo_com_tabela_propria_nao_tem_heranca_e_usa_a_faixa_do_grupo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Com Tabela', 'color' => '#000']);
        $this->criarFaixasDeGrupo($grupo);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 250_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linhaGrupo = $this->acharLinhaDeGrupo($response->viewData('page')['props']['companies']);

        $this->assertNotNull($linhaGrupo);
        $this->assertSame('grupo', $linhaGrupo['tabela_origem']);
        $this->assertSame('Grupo Com Tabela', $linhaGrupo['tabela_grupo_nome'], 'Com tabela própria, a tela precisa poder escrever "Tabela deste grupo" sem adivinhar.');
        $this->assertNull($linhaGrupo['tabela_herdada_de_nome'], 'Com tabela própria não existe herança — nunca preencher as duas juntas.');
        // 300k + 250k = 550k > 200k (limite da faixa 1) — cai na faixa 2 (máxima, piso) da tabela do GRUPO, não na de Gestão.
        $this->assertSame('maxima', $linhaGrupo['faixa']);
        $this->assertTrue($linhaGrupo['valor_faixa_e_piso']);
        $this->assertEqualsWithDelta(10_000.00, (float) $linhaGrupo['valor_mensal'], 0.01);
    }

    // ─── (c) mês FECHADO → mesmas chaves, derivadas do snapshot ─────────

    #[Test]
    public function mes_fechado_grupo_com_tabela_propria_deriva_do_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Fechado Com Tabela', 'color' => '#000']);
        $this->criarFaixasDeGrupo($grupo);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-05', 'revenue' => 250_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $response->assertOk();

        $linhaGrupo = $this->acharLinhaDeGrupo($response->viewData('page')['props']['companies']);

        $this->assertNotNull($linhaGrupo);
        $this->assertSame('grupo', $linhaGrupo['tabela_origem']);
        $this->assertSame('Grupo Fechado Com Tabela', $linhaGrupo['tabela_grupo_nome']);
        $this->assertNull($linhaGrupo['tabela_herdada_de_nome']);
    }

    #[Test]
    public function mes_fechado_grupo_sem_tabela_propria_deriva_heranca_do_snapshot_sem_recalculo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Fechado Sem Tabela', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-05', 'revenue' => 250_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // Depois de congelado: cadastra tabela do grupo. Se o ramo congelado
        // recalculasse ao vivo, a origem viraria 'grupo' — mas ele NUNCA
        // recalcula (D-11), então a resposta tem que continuar mostrando a
        // herança da âncora original.
        $this->criarFaixasDeGrupo($grupo);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');
        $response->assertOk();

        $linhaGrupo = $this->acharLinhaDeGrupo($response->viewData('page')['props']['companies']);

        $this->assertNotNull($linhaGrupo);
        $this->assertNotSame('grupo', $linhaGrupo['tabela_origem'], 'Ramo congelado nunca recalcula (D-11) — cadastrar tabela do grupo DEPOIS do fechamento não pode mudar a origem já congelada.');
        $this->assertNull($linhaGrupo['tabela_grupo_nome']);
        $this->assertSame($membroA->name, $linhaGrupo['tabela_herdada_de_nome']);
    }

    // ─── (d) prop faixas_por_grupo lista só grupos com tabela própria ───

    #[Test]
    public function faixas_por_grupo_lista_o_grupo_com_tabela_e_nao_lista_o_grupo_sem_tabela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin = $this->criarAdmin();

        $grupoComTabela = CompanyGroup::create(['name' => 'Grupo Catálogo Com Tabela', 'color' => '#111']);
        $this->criarFaixasDeGrupo($grupoComTabela);

        $grupoSemTabela = CompanyGroup::create(['name' => 'Grupo Catálogo Sem Tabela', 'color' => '#222']);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $faixasPorGrupo = collect($response->viewData('page')['props']['faixas_por_grupo']);

        $itemComTabela = $faixasPorGrupo->firstWhere('id', $grupoComTabela->id);
        $this->assertNotNull($itemComTabela, 'faixas_por_grupo precisa listar o grupo que tem tabela própria.');
        $this->assertSame('Grupo Catálogo Com Tabela', $itemComTabela['nome']);
        $this->assertCount(2, $itemComTabela['faixas']);
        $this->assertSame(1, $itemComTabela['faixas'][0]['ordem']);
        $this->assertEqualsWithDelta(200_000.00, (float) $itemComTabela['faixas'][0]['limite_superior'], 0.01);
        $this->assertEqualsWithDelta(5_000.00, (float) $itemComTabela['faixas'][0]['valor'], 0.01);
        $this->assertFalse($itemComTabela['faixas'][0]['valor_e_piso']);
        $this->assertSame(2, $itemComTabela['faixas'][1]['ordem']);
        $this->assertNull($itemComTabela['faixas'][1]['limite_superior']);
        $this->assertTrue($itemComTabela['faixas'][1]['valor_e_piso']);

        $this->assertFalse($faixasPorGrupo->contains('id', $grupoSemTabela->id), 'Grupo sem nenhuma linha em grupo_faixas_faturamento não pode aparecer no catálogo.');
    }

    // ─── (e) não-regressão: faixas_por_servico e chaves da Fase 137 ─────

    #[Test]
    public function resposta_mantem_faixas_por_servico_e_as_chaves_da_fase_137_na_linha_do_grupo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Não Regressão', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 250_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $faixasPorServico = collect($props['faixas_por_servico']);
        $this->assertTrue($faixasPorServico->contains('nome', 'Gestão'), 'faixas_por_servico não pode sumir da tela com a introdução de faixas_por_grupo.');

        $linhaGrupo = $this->acharLinhaDeGrupo($props['companies']);
        $this->assertNotNull($linhaGrupo);

        // Chaves já entregues pela Fase 137 — precisam continuar presentes
        // (aditivas, nenhuma delas muda de significado).
        foreach (['tipo', 'tabela_origem', 'tabela_servico_nome', 'tabelas_divergentes', 'filhas', 'cobranca_mensal', 'evolucao'] as $chave) {
            $this->assertArrayHasKey($chave, $linhaGrupo, "Chave '{$chave}' da Fase 137 precisa continuar na linha do grupo.");
        }

        // Chaves novas da Fase 138 — sempre presentes (null quando não se aplica).
        $this->assertArrayHasKey('tabela_grupo_nome', $linhaGrupo);
        $this->assertArrayHasKey('tabela_herdada_de_nome', $linhaGrupo);

        $this->assertArrayHasKey('faixas_por_grupo', $props);
    }
}
