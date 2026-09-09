<?php

namespace Tests\Feature\Phase141;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\FechamentoSnapshot;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 06 (Tarefa 1) — os CINCO ramos de `AdminController::fechamento()`
 * (empresa ao vivo, empresa congelada COM/SEM snapshot, grupo ao vivo, grupo
 * congelado) honrando a regra nova (`FechamentoRegraTabela`) e expondo a
 * procedência da tabela própria (`procedencia_tabela`) para a tela nunca
 * mostrar uma tabela copiada na virada como confirmada (T-141-18).
 *
 * Cada teste afirma por RECONSULTA à resposta HTTP (props Inertia) — nunca
 * por stdout.
 */
class Phase141FechamentoPropsTest extends TestCase
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

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    /** Serviço "Gestão" (ML) já semeado com 7 faixas pela migration da Fase 137. */
    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update([
            'plataforma'             => 'Mercado Livre',
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => true,
        ]);

        return $servico->refresh();
    }

    /** Serviço "Gestão de ADS Shopee" — sem faixa própria (usa tabela de empresa/grupo). */
    private function criarServicoShopee(): Servico
    {
        return Servico::create([
            'nome'                    => 'Gestão de ADS Shopee '.uniqid(),
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_SHOPEE,
            'plataforma'              => 'Shopee',
            'usa_tabela_progressiva'  => true,
        ]);
    }

    /** Serviço sem tabela progressiva (o caso de Mentoria, D-02/D-03). */
    private function criarServicoMentoria(): Servico
    {
        return Servico::create([
            'nome'                    => 'Mentoria '.uniqid(),
            'valor_padrao'            => 0,
            'tipo_cobranca'           => Servico::TIPO_MENSAL,
            'ativo'                   => true,
            'setor'                   => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva'  => false,
        ]);
    }

    /** As 7 faixas reais de Gestão. */
    private function faixasGestaoReais(): array
    {
        return [
            [1, 499_999.99, 3_000.00, false],
            [2, 999_999.99, 4_500.00, false],
            [3, 1_999_999.99, 6_000.00, false],
            [4, 2_999_999.99, 7_500.00, false],
            [5, 3_999_999.99, 9_000.00, false],
            [6, 4_999_999.99, 10_500.00, false],
            [7, null, 12_000.00, true],
        ];
    }

    private function criarTabelaPropriaComFaixasReais(Company $company, string $origem = EmpresaFaixaFaturamento::ORIGEM_MANUAL): void
    {
        foreach ($this->faixasGestaoReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
                'origem'          => $origem,
            ]);
        }
    }

    // ─── Ramo 1 — empresa AO VIVO ─────────────────────────────────────────

    #[Test]
    public function ramo1_flag_ligada_mensalidade_e_o_valor_da_faixa_da_soma_e_plataformas_consideradas_vem_preenchido(): void
    {
        // "Hoje" precisa ser DEPOIS da data do faturamento — a janela do
        // mês corrente vai do dia 1 até hoje (clampada); revenue no dia 5
        // com "hoje" no dia 2 cairia fora da janela (bug de fixture, não do
        // controller — armadilha já documentada em CONTEXT/RESEARCH).
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopee();

        $company = Company::factory()->create(['adman_account_id' => 'cust-baraoshop']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 0]);
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 2_500.00]);

        $this->criarTabelaPropriaComFaixasReais($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 350_262.90]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 138_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(3_000.00, (float) $linha['valor_mensal'], 0.01);
        $this->assertEqualsWithDelta(3_000.00, (float) $linha['cobranca_mensal'], 0.01, 'DEPOIS (D-03): a mensalidade é o valor da faixa, e só isso — nunca faixa + contrato de Shopee.');
        $this->assertIsArray($linha['plataformas_consideradas']);
        $this->assertEqualsCanonicalizing(['ml', 'shopee'], $linha['plataformas_consideradas']);
        $this->assertTrue($response->viewData('page')['props']['regra_nova_ativa']);
    }

    #[Test]
    public function ramo1_flag_desligada_continua_somando_faixa_mais_contrato_como_antes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopee();

        $company = Company::factory()->create(['adman_account_id' => 'cust-baraoshop-antes']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 0]);
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 2_500.00]);

        $this->criarTabelaPropriaComFaixasReais($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 350_262.90]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 138_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(5_500.00, (float) $linha['cobranca_mensal'], 0.01, 'ANTES (flag desligada): comportamento intocado, byte a byte.');
        $this->assertFalse($response->viewData('page')['props']['regra_nova_ativa']);
    }

    #[Test]
    public function ramo1_tabela_propria_presumida_do_servico_nunca_aparece_confirmada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-presumida']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropriaComFaixasReais($company, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame('propria', $linha['tabela_origem']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $linha['procedencia_tabela']);
        $this->assertFalse($linha['tabela_confirmada'], 'Tabela copiada da tabela do serviço na virada NUNCA é confirmada, mesmo sendo origem propria (T-141-18).');
    }

    #[Test]
    public function ramo1_tabela_propria_manual_continua_confirmada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-manual']);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropriaComFaixasReais($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linha['procedencia_tabela']);
        $this->assertTrue($linha['tabela_confirmada']);
    }

    #[Test]
    public function ramo1_empresa_sem_regua_e_com_contrato_mensal_grava_estado_valor_fixo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        $this->ligarFlag();

        $admin    = $this->criarAdmin();
        $mentoria = $this->criarServicoMentoria();
        $company  = Company::factory()->create(['adman_account_id' => 'cust-mentoria']);

        ContratoServico::factory()->paraServico($mentoria)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 1_800.00,
        ]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame(FechamentoSnapshot::ESTADO_VALOR_FIXO, $linha['estado']);
        $this->assertEqualsWithDelta(1_800.00, (float) $linha['cobranca_mensal'], 0.01);
    }

    // ─── Ramo 2 — empresa CONGELADA sem snapshot ───────────────────────────

    #[Test]
    public function ramo2_empresa_congelada_sem_snapshot_traz_chaves_novas_nulas_e_nao_quebra(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();

        // Uma empresa PRÉVIA precisa fechar de verdade — senão
        // `ConsolidarMesFechamento` não grava NENHUMA linha, o exists() de
        // `fechamento()` não encontra a competência congelada, e a tela cai
        // no ramo AO VIVO em vez do congelado (o que esta prova precisa
        // exercitar).
        $companhiaJaFechada = Company::factory()->create(['adman_account_id' => 'cust-ja-fechada']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $companhiaJaFechada->id, 'ativo' => true]);
        AdmanMetric::create(['company_id' => $companhiaJaFechada->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        // Fecha agosto SEM a empresa do teste existir ainda.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // Empresa nasce depois do fechamento de agosto — sem linha no snapshot.
        $company = Company::factory()->create(['adman_account_id' => 'cust-tardia']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropriaComFaixasReais($company);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame(FechamentoSnapshot::ESTADO_SEM_FATURAMENTO, $linha['estado']);
        $this->assertNull($linha['plataformas_consideradas']);
        $this->assertNull($linha['procedencia_tabela']);
        $this->assertNull($linha['tabela_confirmada']);
        $this->assertNull($linha['cobranca_mensal']);
    }

    // ─── Ramo 3 — empresa CONGELADA com snapshot ───────────────────────────

    #[Test]
    public function ramo3_empresa_congelada_com_snapshot_nunca_recalcula_mesmo_com_flag_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopee();

        $company = Company::factory()->create(['adman_account_id' => 'cust-congelada']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 0]);
        ContratoServico::factory()->paraServico($shopee)->create(['company_id' => $company->id, 'ativo' => true, 'valor_contratado' => 2_500.00]);
        $this->criarTabelaPropriaComFaixasReais($company);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 350_262.90]);
        ShopeeMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 138_000.00]);

        // Fecha agosto com a flag DESLIGADA: congela 5.500 (faixa + contrato).
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $congelado = FechamentoSnapshot::where('company_id', $company->id)->whereDate('mes_referencia', '2026-08-01')->first();
        $this->assertEqualsWithDelta(5_500.00, (float) $congelado->cobranca_mensal, 0.01);

        // Agora liga a flag e lê a tela da competência JÁ FECHADA — precisa
        // continuar mostrando o valor congelado, nunca recalcular (D-11).
        $this->ligarFlag();

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertEqualsWithDelta(5_500.00, (float) $linha['cobranca_mensal'], 0.01, 'D-11: competência já congelada é imune à virada da flag.');
        $this->assertNull($linha['plataformas_consideradas'], 'Snapshot não guarda quais plataformas entraram na soma — null, nunca palpite.');
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_MANUAL, $linha['procedencia_tabela'], 'Procedência é lida AO VIVO da tabela própria (pergunta é "quem é dono HOJE").');
        $this->assertTrue($linha['tabela_confirmada']);
    }

    #[Test]
    public function ramo3_procedencia_atual_reflete_tabela_presumida_mesmo_em_competencia_congelada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $company = Company::factory()->create(['adman_account_id' => 'cust-congelada-presumida']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        $this->criarTabelaPropriaComFaixasReais($company, EmpresaFaixaFaturamento::ORIGEM_MANUAL);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        // Depois do fechamento a tabela própria é trocada (dado corrigido) —
        // ramo 3 não pode ignorar isso: "quem confirma HOJE" é a pergunta.
        EmpresaFaixaFaturamento::where('company_id', $company->id)->update(['origem' => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies = $response->viewData('page')['props']['companies'];
        $linha     = collect($companies)->firstWhere('id', $company->id);

        $this->assertNotNull($linha);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $linha['procedencia_tabela']);
        $this->assertFalse($linha['tabela_confirmada']);
    }

    // ─── Ramo 4 — grupo AO VIVO ─────────────────────────────────────────────

    #[Test]
    public function ramo4_grupo_ao_vivo_flag_ligada_mensalidade_e_so_a_faixa_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo 141-06', 'color' => '#000']);

        foreach ($this->faixasGestaoReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $limiteSuperior,
                'valor'             => $valor,
                'valor_e_piso'      => $valorEPiso,
            ]);
        }

        $membroA = Company::factory()->create(['adman_account_id' => 'cust-grupo-a-141', 'company_group_id' => $grupo->id]);
        $membroB = Company::factory()->create(['adman_account_id' => 'cust-grupo-b-141', 'company_group_id' => $grupo->id]);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroA->id, 'ativo' => true, 'valor_contratado' => 900.00]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroB->id, 'ativo' => true, 'valor_contratado' => 700.00]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-09-05', 'revenue' => 250_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies  = $response->viewData('page')['props']['companies'];
        $linhaGrupo = collect($companies)->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaGrupo);
        $this->assertEqualsWithDelta(550_000.00, (float) $linhaGrupo['faturamento'], 0.01);
        $this->assertEqualsWithDelta(4_500.00, (float) $linhaGrupo['cobranca_mensal'], 0.01, 'A cobrança do grupo é só o valor da faixa da soma — os R$ 900 + R$ 700 dos contratos-membro não podem entrar.');
    }

    #[Test]
    public function ramo4_grupo_com_tabela_presumida_na_ancora_nunca_aparece_confirmado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Presumido 141-06', 'color' => '#111']);

        $ancora = Company::factory()->create(['adman_account_id' => 'cust-ancora-presumida', 'company_group_id' => $grupo->id]);
        $membro = Company::factory()->create(['adman_account_id' => 'cust-membro-presumida', 'company_group_id' => $grupo->id]);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $ancora->id, 'ativo' => true]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membro->id, 'ativo' => true]);

        // Só a âncora (maior faturamento) tem tabela própria — presumida.
        $this->criarTabelaPropriaComFaixasReais($ancora, EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO);

        AdmanMetric::create(['company_id' => $ancora->id, 'reference_date' => '2026-09-05', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membro->id, 'reference_date' => '2026-09-05', 'revenue' => 50_000.00]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $companies  = $response->viewData('page')['props']['companies'];
        $linhaGrupo = collect($companies)->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaGrupo);
        $this->assertSame('propria', $linhaGrupo['tabela_origem']);
        $this->assertSame(EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO, $linhaGrupo['procedencia_tabela']);
        $this->assertFalse($linhaGrupo['tabela_confirmada'], 'Grupo herdando tabela presumida da âncora nunca é confirmado (T-141-18).');
    }

    // ─── Ramo 5 — grupo CONGELADO ───────────────────────────────────────────

    #[Test]
    public function ramo5_grupo_congelado_nunca_recalcula_mesmo_com_flag_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin  = $this->criarAdmin();
        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Congelado 141-06', 'color' => '#222']);

        foreach ($this->faixasGestaoReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $limiteSuperior,
                'valor'             => $valor,
                'valor_e_piso'      => $valorEPiso,
            ]);
        }

        $membroA = Company::factory()->create(['adman_account_id' => 'cust-grupo-cong-a', 'company_group_id' => $grupo->id]);
        $membroB = Company::factory()->create(['adman_account_id' => 'cust-grupo-cong-b', 'company_group_id' => $grupo->id]);

        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroA->id, 'ativo' => true, 'valor_contratado' => 900.00]);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $membroB->id, 'ativo' => true, 'valor_contratado' => 700.00]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 250_000.00]);

        // Fecha agosto com a flag DESLIGADA — congela faixa + contratos-membro.
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $grupoGravado = \Illuminate\Support\Facades\DB::table('fechamento_grupo_snapshots')
            ->where('company_group_id', $grupo->id)
            ->first();
        $cobrancaCongelada = (float) $grupoGravado->cobranca_mensal;

        $this->ligarFlag();

        $response = $this->actingAs($admin)->get('/administrativo/financeiro?mes=2026-08');

        $response->assertOk();
        $companies  = $response->viewData('page')['props']['companies'];
        $linhaGrupo = collect($companies)->firstWhere('tipo', 'grupo');

        $this->assertNotNull($linhaGrupo);
        $this->assertEqualsWithDelta($cobrancaCongelada, (float) $linhaGrupo['cobranca_mensal'], 0.01, 'D-11: grupo já congelado é imune à virada da flag.');
        $this->assertNull($linhaGrupo['plataformas_consideradas']);
    }

    // ─── Totais do topo — valor_fixo não vira pendência ────────────────────

    #[Test]
    public function totais_empresa_valor_fixo_entra_no_total_a_receber_e_nao_conta_como_sem_valor_definido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15'));
        $this->ligarFlag();

        $admin    = $this->criarAdmin();
        $mentoria = $this->criarServicoMentoria();
        $company  = Company::factory()->create(['adman_account_id' => 'cust-totais-valor-fixo']);

        ContratoServico::factory()->paraServico($mentoria)->create([
            'company_id'       => $company->id,
            'ativo'            => true,
            'valor_contratado' => 1_800.00,
        ]);

        $response = $this->actingAs($admin)->get('/administrativo/financeiro');

        $response->assertOk();
        $totais = $response->viewData('page')['props']['totais'];

        $this->assertGreaterThanOrEqual(1_800.00, (float) $totais['total_a_receber'], 'valor_fixo precisa somar normalmente em total_a_receber.');
        $this->assertSame(0, $totais['empresas_sem_valor_definido'], 'ESTADO_VALOR_FIXO é resultado normal — não pode contar como pendência.');
    }
}
