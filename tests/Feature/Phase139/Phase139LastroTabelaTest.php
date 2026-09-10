<?php

namespace Tests\Feature\Phase139;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoAssinatura;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\FechamentoSnapshot;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Quick 260904-kwz — a tabela de faixas aplicada a uma empresa (ou a um
 * grupo) só tem confirmação quando (a) existe cadastro MANUAL
 * (`tabela_origem` 'propria'/'grupo') ou (b) a empresa dona da tabela tem
 * um `ContratoAssinatura` com `status = 'assinado'` no sistema. Fora
 * disso, a tabela foi presumida a partir do serviço contratado — o que
 * NÃO É ERRO, é ausência de confirmação (a correção do usuário de
 * 2026-09-04, medida em produção: 127 de 167 empresas com tabela vinda do
 * serviço não tinham nenhum dos dois caminhos).
 *
 * Cobre os `dois ramos` de `AdminController::fechamento()` (ao vivo e
 * congelado) e os dois níveis (empresa e grupo) — os CINCO literais de
 * linha mapeados em fases anteriores.
 */
class Phase139LastroTabelaTest extends TestCase
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
     * semeia as 7 faixas de "Gestão" — mesmo padrão dos demais testes da
     * fase. Faixa 1 (até R$499.999,99) cobra R$3.000.
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

    private function criarContratoAssinado(Company $company, Servico $servico, string $status = ContratoAssinatura::STATUS_ASSINADO): ContratoAssinatura
    {
        return ContratoAssinatura::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'status'      => $status,
            'assinado_em' => $status === ContratoAssinatura::STATUS_ASSINADO ? Carbon::now() : null,
        ]);
    }

    // ─── (1) A regra dos dois caminhos ─────────────────────────────────────

    #[Test]
    public function confirmada_quando_ha_cadastro_manual_proprio_mesmo_sem_nenhum_contrato_assinado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        // D-13: qualquer linha própria substitui a tabela do serviço.
        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 5_000.00, 'valor_e_piso' => false,
        ]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertSame('propria', $linha['tabela_origem']);
        $this->assertTrue($linha['tabela_confirmada'], 'Cadastro manual próprio confirma a tabela sem precisar de contrato assinado.');
    }

    #[Test]
    public function confirmada_quando_a_tabela_vem_do_servico_e_a_empresa_tem_contrato_assinado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        $this->criarContratoAssinado($company, $gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertSame('servico', $linha['tabela_origem']);
        $this->assertTrue($linha['tabela_confirmada'], 'Contrato assinado no sistema confirma a tabela do serviço.');
    }

    #[Test]
    public function assumida_quando_a_tabela_vem_do_servico_sem_contrato_assinado_e_sem_cadastro_manual_mas_mantem_o_valor(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertSame('servico', $linha['tabela_origem']);
        $this->assertFalse($linha['tabela_confirmada'], 'Sem cadastro manual e sem contrato assinado, a tabela do serviço foi só presumida.');
        $this->assertNotNull($linha['cobranca_mensal'], 'Assumida não é erro — o valor continua sendo cobrado normalmente (decisão do usuário: visibilidade sem tirar o valor).');
        $this->assertGreaterThan(0.0, (float) $linha['cobranca_mensal']);
    }

    #[Test]
    public function tabela_confirmada_e_null_quando_nao_ha_tabela_nenhuma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin = $this->criarAdmin();
        // Empresa com faturamento mas sem NENHUM contrato de serviço — cai
        // em estado "sem_tabela" ("A DEFINIR"), que é diferente de assumida.
        $company = Company::factory()->create(['adman_account_id' => 'cust-'.uniqid()]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertSame(FechamentoSnapshot::ESTADO_SEM_TABELA, $linha['estado']);
        $this->assertNull($linha['tabela_origem']);
        $this->assertNull($linha['tabela_confirmada'], '"A DEFINIR" (sem tabela nenhuma) é um estado diferente de "assumida" — não pode virar false.');
    }

    #[Test]
    public function contrato_assinado_de_outra_empresa_nao_confirma_a_tabela_desta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin        = $this->criarAdmin();
        $gestao       = $this->criarServicoGestao();
        $semContrato  = $this->criarEmpresaComContrato($gestao);
        $comContrato  = $this->criarEmpresaComContrato($gestao);
        $this->criarContratoAssinado($comContrato, $gestao);

        AdmanMetric::create(['company_id' => $semContrato->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $comContrato->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $companies = collect($response->viewData('page')['props']['companies']);

        $this->assertFalse($companies->firstWhere('id', $semContrato->id)['tabela_confirmada'], 'O contrato assinado é de OUTRA empresa — não pode vazar confirmação pra esta.');
        $this->assertTrue($companies->firstWhere('id', $comContrato->id)['tabela_confirmada']);
    }

    #[Test]
    public function contrato_nao_assinado_nao_confirma_a_tabela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);
        // Em andamento, não assinado ainda.
        $this->criarContratoAssinado($company, $gestao, ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linha = collect($response->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertFalse($linha['tabela_confirmada'], 'Só `status = assinado` confirma — "aguardando_assinaturas" ainda não é contrato assinado.');
    }

    // ─── (2) Grupo — mesma regra, dono é a empresa-âncora ──────────────────

    #[Test]
    public function grupo_com_tabela_propria_e_confirmado_sem_precisar_de_contrato(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Lastro '.uniqid(), 'color' => '#000']);
        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 8_000.00, 'valor_e_piso' => false,
        ]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linhaGrupo = collect($response->viewData('page')['props']['companies'])->firstWhere('tipo', 'grupo');

        $this->assertSame('grupo', $linhaGrupo['tabela_origem']);
        $this->assertTrue($linhaGrupo['tabela_confirmada'], 'Tabela própria do grupo é cadastro manual — confirmada por si só.');
    }

    #[Test]
    public function grupo_sem_tabela_propria_herda_confirmacao_do_contrato_assinado_da_ancora(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Herdado '.uniqid(), 'color' => '#000']);

        // Âncora: quem mais fatura vira a âncora do grupo (maior faturamento).
        $ancora  = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $this->criarContratoAssinado($ancora, $gestao);

        AdmanMetric::create(['company_id' => $ancora->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linhaGrupo = collect($response->viewData('page')['props']['companies'])->firstWhere('tipo', 'grupo');

        $this->assertSame('servico', $linhaGrupo['tabela_origem']);
        $this->assertTrue($linhaGrupo['tabela_confirmada'], 'Tabela herdada da âncora é confirmada pelo contrato assinado DELA.');
    }

    #[Test]
    public function grupo_sem_tabela_propria_e_sem_contrato_da_ancora_fica_assumido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Assumido '.uniqid(), 'color' => '#000']);
        $ancora  = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $ancora->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $linhaGrupo = collect($response->viewData('page')['props']['companies'])->firstWhere('tipo', 'grupo');

        $this->assertFalse($linhaGrupo['tabela_confirmada']);
        $this->assertNotNull($linhaGrupo['cobranca_mensal'], 'Grupo assumido mantém a mensalidade — não é erro.');
    }

    // ─── (3) O contador do topo bate com a contagem real ───────────────────

    #[Test]
    public function contador_tabelas_assumidas_bate_com_a_contagem_real_das_linhas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        // Duas assumidas (sem cadastro manual, sem contrato assinado).
        $assumida1 = $this->criarEmpresaComContrato($gestao);
        $assumida2 = $this->criarEmpresaComContrato($gestao);
        // Uma confirmada por contrato assinado.
        $confirmadaContrato = $this->criarEmpresaComContrato($gestao);
        $this->criarContratoAssinado($confirmadaContrato, $gestao);
        // Uma confirmada por cadastro manual.
        $confirmadaManual = $this->criarEmpresaComContrato($gestao);
        EmpresaFaixaFaturamento::create([
            'company_id' => $confirmadaManual->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 5_000.00, 'valor_e_piso' => false,
        ]);

        foreach ([$assumida1, $assumida2, $confirmadaContrato, $confirmadaManual] as $c) {
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        }

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props           = $response->viewData('page')['props'];
        $contagemReal    = collect($props['companies'])
            ->filter(fn ($l) => ($l['conta_no_total'] ?? true) !== false)
            ->where('tabela_confirmada', false)
            ->count();

        $this->assertSame(2, $contagemReal, 'Pré-condição do teste: duas linhas assumidas.');
        $this->assertSame($contagemReal, $props['totais']['tabelas_assumidas'], 'O contador do topo precisa bater com a contagem real das linhas — nunca um número solto.');
    }

    // ─── (4) Sem N+1 ────────────────────────────────────────────────────────

    #[Test]
    public function consulta_de_contratos_assinados_e_feita_uma_unica_vez_independente_da_quantidade_de_empresas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        for ($i = 0; $i < 6; $i++) {
            $c = $this->criarEmpresaComContrato($gestao);
            if ($i % 2 === 0) {
                $this->criarContratoAssinado($c, $gestao);
            }
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-09-05', 'revenue' => 100_000.00]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        $consultasContratos = collect($log)->filter(fn ($q) => str_contains($q['query'], 'contrato_assinaturas'));

        $this->assertCount(1, $consultasContratos, 'A consulta de contratos assinados precisa ser em massa, ANTES do laço — nunca uma por empresa (T-138-11).');
    }

    // ─── (5) Cobertura nos dois ramos, por reconsulta ao banco ─────────────

    #[Test]
    public function ramo_congelado_continua_confirmando_por_contrato_assinado_apos_o_fechamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $confirmada = $this->criarEmpresaComContrato($gestao);
        $assumida   = $this->criarEmpresaComContrato($gestao);
        $this->criarContratoAssinado($confirmada, $gestao);

        AdmanMetric::create(['company_id' => $confirmada->id, 'reference_date' => '2026-09-10', 'revenue' => 100_000.00]);
        AdmanMetric::create(['company_id' => $assumida->id, 'reference_date' => '2026-09-10', 'revenue' => 100_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->assertExitCode(0);

        // Reconsulta via nova requisição HTTP — nunca reaproveita o array
        // da chamada anterior (aqui nem existe uma: é a primeira leitura
        // depois de fechar o mês).
        $response = $this->actingAs($admin)->get('/administrativo/financeiro');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $this->assertTrue($props['competencia_fechada'], 'Pré-condição do teste: competência precisa estar fechada (ramo congelado).');

        // Confere também direto no banco que a competência realmente
        // congelou como snapshot de fechamento — nunca só pelo retorno da
        // tela.
        $this->assertTrue(
            FechamentoSnapshot::query()
                ->where('company_id', $confirmada->id)
                ->whereDate('mes_referencia', '2026-09-01')
                ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
                ->exists(),
            'A competência precisa ter congelado de verdade como snapshot no banco.'
        );

        $companies = collect($props['companies']);
        $this->assertTrue($companies->firstWhere('id', $confirmada->id)['tabela_confirmada']);
        $this->assertFalse($companies->firstWhere('id', $assumida->id)['tabela_confirmada']);
    }

    #[Test]
    public function contrato_assinado_depois_do_fechamento_passa_a_confirmar_a_competencia_ja_congelada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin   = $this->criarAdmin();
        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-10', 'revenue' => 100_000.00]);
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-09'])->assertExitCode(0);

        $respAntes = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respAntes->assertOk();
        $linhaAntes = collect($respAntes->viewData('page')['props']['companies'])->firstWhere('id', $company->id);
        $this->assertFalse($linhaAntes['tabela_confirmada'], 'Pré-condição: ainda sem contrato assinado, tabela assumida.');
        $this->assertSame(FechamentoSnapshot::ESTADO_OK, $linhaAntes['estado']);
        $valorAntes = $linhaAntes['cobranca_mensal'];

        // A equipe cadastra o contrato assinado DEPOIS do fechamento —
        // reconsulta ao banco (nova requisição) precisa refletir a
        // confirmação nova, mas sem recalcular o valor já cobrado (D-11).
        $this->criarContratoAssinado($company, $gestao);

        $respDepois = $this->actingAs($admin)->get('/administrativo/financeiro');
        $respDepois->assertOk();
        $linhaDepois = collect($respDepois->viewData('page')['props']['companies'])->firstWhere('id', $company->id);

        $this->assertTrue($linhaDepois['tabela_confirmada'], 'Confirmação é sobre o presente — um contrato assinado depois do fechamento passa a confirmar a competência congelada também.');
        $this->assertEqualsWithDelta((float) $valorAntes, (float) $linhaDepois['cobranca_mensal'], 0.01, 'D-11: nunca recalcula o valor já cobrado — só a confirmação muda.');
    }
}
