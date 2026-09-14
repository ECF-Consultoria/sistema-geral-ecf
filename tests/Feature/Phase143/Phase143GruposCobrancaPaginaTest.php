<?php

namespace Tests\Feature\Phase143;

use App\Http\Controllers\GrupoCobrancaHierarquiaController;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Services\Fechamento\FechamentoRegraTabela;
use App\Services\Fechamento\SimuladorGrupoCobrancaService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 04 — a TELA onde o grupo de cobrança é montado
 * (`GET /administrativo/contratos/grupos`) e a criação de um grupo de
 * cobrança novo.
 *
 * ## Por que esta tela é a fase inteira
 * O usuário foi explícito (143-CONTEXT, D-06): a correção existe "para que,
 * caso existam outros casos, seja possível resolver pela UI". Só conhecemos
 * o caso MPozenato, e **não dá para achar os outros por dado** — 145 das 203
 * empresas estão sem CNPJ. A montagem é curadoria humana feita nesta tela;
 * sem ela, a fase vira uma migration manual disfarçada.
 *
 * ## O que estes testes protegem
 * 1. A listagem mostra **escala**: quantas empresas e quanto de cobrança por
 *    grupo — e esses números são os MESMOS que a prévia e o fechamento usam
 *    (vêm de `SimuladorGrupoCobrancaService`, nunca de uma conta própria).
 * 2. A tela sabe dizer **quem não tem tabela própria** — é o aviso que
 *    impede montar um grupo de cobrança que herda uma tabela copiada do
 *    serviço e cai mais do que deveria (R$ 12.000 em vez de R$ 21.000).
 * 3. **Criar um grupo de cobrança não muda cobrança nenhuma** — é por isso
 *    que ele pode ser criado antes da prévia, e é a ordem obrigatória: só um
 *    grupo que existe pode receber tabela, e a tabela tem de vir antes de
 *    juntar.
 * 4. A prévia do **caso real** chega na tela pela rota, com a diferença e o
 *    sinal: R$ 33.500 em quatro mensalidades → R$ 21.000 numa só,
 *    −R$ 12.500 por mês.
 * 5. Juntar e desfazer **refletem na listagem** e deixam trilha.
 * 6. Arranjo recusado devolve a mensagem em pt-BR do model — a tela exibe o
 *    erro, nunca reimplementa a regra.
 *
 * `index` é lido via header `X-Inertia` (JSON puro) em vez de
 * `assertInertia()` — mesmo motivo de `Phase143TabelaGrupoPaginaTest`: o
 * helper exigiria renderizar o Blade `@vite` e resolver o manifest.
 */
class Phase143GruposCobrancaPaginaTest extends TestCase
{
    use RefreshDatabase;

    private const FATURAMENTO_MPOZENATO = [3_000_000.00, 812_487.89];   // 3.812.487,89
    private const FATURAMENTO_GRAN_BELO = [5_000_000.00, 977_697.79];   // 5.977.697,79
    private const FATURAMENTO_DROSSI    = [400_000.00, 400_000.00, 400_000.00, 38_304.17]; // 1.238.304,17
    private const FATURAMENTO_LYAM      = [1_000_000.00, 650_921.98];   // 1.650.921,98

    private const TOTAL_DO_CLIENTE = 12_679_411.83;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada. */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Grupos Cobranca '.uniqid(),
            'slug'   => 'grupos-cobranca-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    private function criarServicoGestao(): Servico
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );
        $servico->update(['plataforma' => 'Mercado Livre', 'setor' => Servico::SETOR_PERFORMANCE]);

        return $servico->refresh();
    }

    private function criarMembro(Servico $servico, CompanyGroup $grupo, float $faturamento, ?string $nome = null): Company
    {
        $company = Company::factory()->create(array_filter([
            'name'             => $nome,
            'adman_account_id' => 'cust-'.uniqid(),
            'company_group_id' => $grupo->id,
        ]));

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        AdmanMetric::create([
            'company_id'     => $company->id,
            'reference_date' => '2026-08-10',
            'revenue'        => $faturamento,
        ]);

        return $company;
    }

    private function tabelaDeGrupoUnica(CompanyGroup $grupo, float $valor): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1,
            'limite_superior'  => null, 'valor' => $valor, 'valor_e_piso' => true,
        ]);
    }

    /**
     * O caso do 143-CONTEXT (D-02) com os números medidos em produção em
     * ago/2026: quatro grupos do MESMO cliente, 10 empresas,
     * R$ 12.679.411,83 de faturamento e R$ 33.500 de cobrança somada.
     *
     * @return array{0: CompanyGroup, 1: array<string, CompanyGroup>}
     */
    private function montarCasoMPozenato(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $gestao = $this->criarServicoGestao();

        $grupoDeCobranca = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $outros          = [];

        foreach (['DRossi', 'Gran Belo', 'Lyam'] as $nome) {
            $outros[$nome] = CompanyGroup::create(['name' => $nome, 'color' => '#000']);
        }

        foreach (self::FATURAMENTO_MPOZENATO as $valor) {
            $this->criarMembro($gestao, $grupoDeCobranca, $valor);
        }
        foreach (self::FATURAMENTO_DROSSI as $valor) {
            $this->criarMembro($gestao, $outros['DRossi'], $valor);
        }
        foreach (self::FATURAMENTO_GRAN_BELO as $valor) {
            $this->criarMembro($gestao, $outros['Gran Belo'], $valor);
        }
        foreach (self::FATURAMENTO_LYAM as $valor) {
            $this->criarMembro($gestao, $outros['Lyam'], $valor);
        }

        // MPozenato: até R$ 5 mi cobra R$ 9.500; acima, R$ 21.000.
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupoDeCobranca->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 9_500.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupoDeCobranca->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);

        $this->tabelaDeGrupoUnica($outros['Gran Belo'], 12_000.00);
        $this->tabelaDeGrupoUnica($outros['DRossi'], 6_000.00);
        $this->tabelaDeGrupoUnica($outros['Lyam'], 6_000.00);

        return [$grupoDeCobranca, $outros];
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];
    }

    private function abrir(User $user, string $mes = '2026-08'): TestResponse
    {
        return $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('admin.contratos.grupos.index', ['mes' => $mes]));
    }

    /** @return array<string, mixed> */
    private function props(TestResponse $response): array
    {
        return json_decode($response->getContent(), true)['props'];
    }

    // ─── (a) a listagem mostra a escala ───────────────────────────────────

    #[Test]
    public function a_pagina_lista_cada_grupo_com_quantas_empresas_e_quanto_de_cobranca(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $props = $this->props($this->abrir($this->admin()));

        $this->assertSame('Admin/GruposCobranca', json_decode($this->abrir($this->admin())->getContent(), true)['component']);
        $this->assertSame('2026-08', $props['mes']);

        $porNome = collect($props['grupos'])->keyBy('nome');

        $this->assertCount(4, $porNome, 'Sem ninguém junto, os quatro grupos aparecem separados.');

        $this->assertSame(2, $porNome['MPozenato']['empresas_count']);
        $this->assertSame(4, $porNome['DRossi']['empresas_count']);
        $this->assertSame(2, $porNome['Gran Belo']['empresas_count']);
        $this->assertSame(2, $porNome['Lyam']['empresas_count']);

        // A cobrança de hoje, grupo a grupo — é o que dá noção de escala
        // antes de mexer, e soma os R$ 33.500 do caso real.
        $this->assertEqualsWithDelta(9_500.00, (float) $porNome['MPozenato']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(12_000.00, (float) $porNome['Gran Belo']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(6_000.00, (float) $porNome['DRossi']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(6_000.00, (float) $porNome['Lyam']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(33_500.00, (float) $props['total_cobranca'], 0.01);

        // E o faturamento que produziu esses valores.
        $this->assertEqualsWithDelta(3_812_487.89, (float) $porNome['MPozenato']['faturamento_total'], 0.01);

        // Ninguém está dentro de ninguém ainda.
        foreach ($props['grupos'] as $grupo) {
            $this->assertSame([], $grupo['dentro']);
        }
    }

    #[Test]
    public function os_numeros_da_listagem_sao_os_mesmos_que_a_previa_usa(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $props = $this->props($this->abrir($this->admin()));

        // ⚠️ A listagem não pode ter conta própria: o número que a pessoa lê
        // na lista é o mesmo que a prévia mostra e que o fechamento congela.
        $doSimulador = collect(app(SimuladorGrupoCobrancaService::class)->estadoAtual('2026-08')['linhas'])
            ->keyBy('company_group_id');

        foreach ($props['grupos'] as $grupo) {
            $this->assertEqualsWithDelta(
                (float) $doSimulador[$grupo['id']]['cobranca_mensal'],
                (float) $grupo['cobranca_mensal'],
                0.01,
                "A cobrança exibida para \"{$grupo['nome']}\" divergiu da que o fechamento usa."
            );
            $this->assertSame($doSimulador[$grupo['id']]['empresas_count'], $grupo['empresas_count']);
        }
    }

    #[Test]
    public function depois_de_juntar_a_listagem_mostra_uma_linha_com_os_grupos_dentro(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        foreach ($outros as $grupo) {
            $grupo->update(['parent_id' => $grupoDeCobranca->id]);
        }

        $props = $this->props($this->abrir($this->admin()));

        $this->assertCount(1, $props['grupos'], 'Juntos, os quatro viram uma linha de cobrança só.');

        $linha = $props['grupos'][0];

        $this->assertSame('MPozenato', $linha['nome']);
        $this->assertSame(10, $linha['empresas_count'], 'A contagem tem de ser a do conjunto inteiro — 10 empresas, não 2.');
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['faturamento_total'], 0.01);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha['cobranca_mensal'], 0.01);
        $this->assertSame('MPozenato', $linha['tabela_grupo_nome'], 'A tela precisa dizer de qual tabela veio o valor.');

        $this->assertEqualsCanonicalizing(
            ['DRossi', 'Gran Belo', 'Lyam'],
            collect($linha['dentro'])->pluck('nome')->all()
        );
        $this->assertSame(4, collect($linha['dentro'])->firstWhere('nome', 'DRossi')['empresas_count']);
    }

    #[Test]
    public function a_pagina_diz_quem_ainda_nao_tem_tabela_propria(): void
    {
        $comTabela = CompanyGroup::create(['name' => 'Com tabela', 'color' => '#000']);
        $semTabela = CompanyGroup::create(['name' => 'Sem tabela', 'color' => '#000']);

        $this->tabelaDeGrupoUnica($comTabela, 9_500.00);

        $porNome = collect($this->props($this->abrir($this->admin()))['grupos'])->keyBy('nome');

        $this->assertTrue($porNome['Com tabela']['tem_tabela_propria']);
        $this->assertFalse(
            $porNome['Sem tabela']['tem_tabela_propria'],
            'Sem este sinal a tela não tem como avisar ANTES de juntar — e juntar sem tabela derruba a cobrança mais do que deveria.'
        );
    }

    // ─── (b) permissão: a do módulo, liberável por setor ──────────────────

    #[Test]
    public function quem_tem_a_permissao_de_contratos_abre_a_tela_e_cria_grupo(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $this->abrir($user)->assertOk();

        $this->actingAs($user)
            ->post(route('admin.contratos.grupos.criar'), ['name' => 'Cliente Novo'])
            ->assertRedirect();

        $this->assertDatabaseHas('company_groups', ['name' => 'Cliente Novo', 'parent_id' => null]);
    }

    #[Test]
    public function quem_nao_tem_a_permissao_leva_403_nas_duas_rotas(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($user)->get(route('admin.contratos.grupos.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.contratos.grupos.criar'), ['name' => 'X'])->assertForbidden();
    }

    // ─── (c) criar não muda cobrança de ninguém ───────────────────────────

    #[Test]
    public function criar_um_grupo_de_cobranca_nao_mexe_em_empresa_nenhuma_nem_na_cobranca(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $antes       = app(SimuladorGrupoCobrancaService::class)->estadoAtual('2026-08');
        $vinculoDe   = Company::pluck('company_group_id', 'id');

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.grupos.criar'), ['name' => 'Cliente Pozenato'])
            ->assertRedirect();

        $depois = app(SimuladorGrupoCobrancaService::class)->estadoAtual('2026-08');

        // ⚠️ É isto que permite criar ANTES da prévia: o grupo nasce vazio e
        // não produz linha de cobrança nenhuma.
        $this->assertEqualsWithDelta($antes['total_cobranca'], $depois['total_cobranca'], 0.01);
        $this->assertCount(count($antes['linhas']), $depois['linhas']);

        // Nenhuma empresa trocou de grupo — o NPS continua enxergando
        // exatamente os mesmos grupos de antes.
        $this->assertEquals($vinculoDe, Company::pluck('company_group_id', 'id'));

        // E o grupo novo aparece na tela, pronto para receber a tabela.
        $porNome = collect($this->props($this->abrir($this->admin()))['grupos'])->keyBy('nome');

        $this->assertArrayHasKey('Cliente Pozenato', $porNome);
        $this->assertSame(0, $porNome['Cliente Pozenato']['empresas_count']);
        $this->assertNull($porNome['Cliente Pozenato']['cobranca_mensal']);
        $this->assertFalse($porNome['Cliente Pozenato']['tem_tabela_propria']);

        $this->assertSame(
            1,
            DB::table('activity_log')
                ->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)
                ->count(),
            'Criar um grupo de cobrança tem de ficar registrado.'
        );
    }

    // ─── (d) a prévia do caso real, pela rota que a tela consome ──────────

    #[Test]
    public function a_previa_do_caso_real_chega_na_tela_com_a_diferenca_e_o_sinal(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $json = $this->actingAs($this->admin())->getJson(route('admin.contratos.grupos.hierarquia.previa', [
            'grupo_ids' => collect($outros)->pluck('id')->all(),
            'pai_id'    => $grupoDeCobranca->id,
            'mes'       => '2026-08',
        ]))->assertOk()->json();

        // Hoje: quatro mensalidades somando R$ 33.500.
        $this->assertCount(4, $json['antes']['linhas']);
        $this->assertEqualsWithDelta(33_500.00, $json['antes']['total_cobranca'], 0.01);

        // Juntos: uma mensalidade de R$ 21.000.
        $this->assertCount(1, $json['depois']['linhas']);
        $this->assertEqualsWithDelta(21_000.00, $json['depois']['total_cobranca'], 0.01);
        $this->assertSame(10, $json['depois']['linhas'][0]['empresas_count']);
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $json['depois']['linhas'][0]['faturamento_total'], 0.01);

        // A diferença, com sinal — o número da decisão.
        $this->assertEqualsWithDelta(-12_500.00, $json['delta'], 0.01);

        // E de qual tabela veio o R$ 21.000 (a diferença entre ele e os
        // R$ 12.000 é exatamente esta informação).
        $this->assertSame('grupo', $json['depois']['linhas'][0]['tabela_origem']);
        $this->assertSame('MPozenato', $json['depois']['linhas'][0]['tabela_grupo_nome']);
    }

    #[Test]
    public function a_previa_diz_quando_a_tabela_veio_do_contrato_e_quando_foi_copiada_do_servico(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $gestao = $this->criarServicoGestao();

        // Nenhum dos dois grupos tem tabela própria: quem governa é a tabela
        // da empresa que mais faturou — e é a procedência DELA que muda o
        // resultado de R$ 21.000 para R$ 12.000.
        $comContrato = CompanyGroup::create(['name' => 'Cliente A', 'color' => '#000']);
        $copiada     = CompanyGroup::create(['name' => 'Cliente B', 'color' => '#000']);

        $empresaA = $this->criarMembro($gestao, $comContrato, 9_000_000.00, 'Empresa A');
        $empresaB = $this->criarMembro($gestao, $copiada, 1_000_000.00, 'Empresa B');

        EmpresaFaixaFaturamento::create([
            'company_id' => $empresaA->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 21_000.00, 'valor_e_piso' => true,
            'origem' => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ]);
        EmpresaFaixaFaturamento::create([
            'company_id' => $empresaB->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 12_000.00, 'valor_e_piso' => true,
            'origem' => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
        ]);

        $json = $this->actingAs($this->admin())->getJson(route('admin.contratos.grupos.hierarquia.previa', [
            'grupo_ids' => [$copiada->id],
            'pai_id'    => $comContrato->id,
            'mes'       => '2026-08',
        ]))->assertOk()->json();

        $linha = $json['depois']['linhas'][0];

        $this->assertEqualsWithDelta(21_000.00, (float) $linha['cobranca_mensal'], 0.01);
        $this->assertSame(
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            $linha['procedencia'],
            'A tela precisa dizer de onde vem a tabela — é a diferença entre R$ 21.000 e R$ 12.000.'
        );

        // O outro lado: cada uma sozinha, a cobrada pela tabela copiada do
        // serviço aparece marcada como tal.
        $porGrupo = collect($json['antes']['linhas'])->keyBy('company_group_id');

        $this->assertSame(
            EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
            $porGrupo[$copiada->id]['procedencia']
        );
    }

    // ─── (e) juntar e desfazer, com reflexo na tela ───────────────────────

    #[Test]
    public function juntar_pela_tela_grava_registra_e_a_listagem_reflete(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => collect($outros)->pluck('id')->all(),
                'pai_id'    => $grupoDeCobranca->id,
                'mes'       => '2026-08',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $props = $this->props($this->abrir($this->admin()));

        $this->assertCount(1, $props['grupos']);
        $this->assertSame(10, $props['grupos'][0]['empresas_count']);
        $this->assertEqualsWithDelta(21_000.00, (float) $props['grupos'][0]['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(21_000.00, (float) $props['total_cobranca'], 0.01);

        $trilha = DB::table('activity_log')
            ->where('log_name', GrupoCobrancaHierarquiaController::LOG_NAME)
            ->latest('id')
            ->first();

        $this->assertNotNull($trilha);

        $propriedades = json_decode($trilha->properties, true);

        $this->assertEqualsWithDelta(33_500.00, $propriedades['previa']['total_cobranca_antes'], 0.01);
        $this->assertEqualsWithDelta(21_000.00, $propriedades['previa']['total_cobranca_depois'], 0.01);
        $this->assertEqualsWithDelta(-12_500.00, $propriedades['previa']['delta'], 0.01);
    }

    #[Test]
    public function tirar_de_dentro_pela_tela_volta_a_listagem_para_quatro_linhas(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        foreach ($outros as $grupo) {
            $grupo->update(['parent_id' => $grupoDeCobranca->id]);
        }

        $this->actingAs($this->admin())
            ->delete(route('admin.contratos.grupos.hierarquia.despendurar'), [
                'grupo_ids' => collect($outros)->pluck('id')->all(),
                'mes'       => '2026-08',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $props = $this->props($this->abrir($this->admin()));

        $this->assertCount(4, $props['grupos']);
        $this->assertEqualsWithDelta(33_500.00, (float) $props['total_cobranca'], 0.01);
    }

    // ─── (f) a tela exibe o erro do model, não uma cópia da regra ─────────

    #[Test]
    public function arranjo_recusado_devolve_a_mensagem_em_portugues_do_model(): void
    {
        $grupoDeCobranca = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $jaDentro        = CompanyGroup::create(['name' => 'DRossi', 'color' => '#000', 'parent_id' => $grupoDeCobranca->id]);
        $outro           = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000']);

        // Tentar colocar um grupo dentro de quem já está dentro de outro.
        $this->actingAs($this->admin())
            ->from(route('admin.contratos.grupos.index'))
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => [$outro->id],
                'pai_id'    => $jaDentro->id,
            ])
            ->assertSessionHasErrors(['grupo_ids' => '"DRossi" já está dentro de outro grupo — a hierarquia tem um nível só.']);

        $this->assertNull($outro->fresh()->parent_id);
    }

    // ─── (g) o NPS não sente nada ─────────────────────────────────────────

    #[Test]
    public function montar_pela_tela_nao_remaneja_nenhuma_empresa_de_grupo(): void
    {
        [$grupoDeCobranca, $outros] = $this->montarCasoMPozenato();

        $antes = Company::pluck('company_group_id', 'id');

        $this->actingAs($this->admin())
            ->post(route('admin.contratos.grupos.hierarquia.pendurar'), [
                'grupo_ids' => collect($outros)->pluck('id')->all(),
                'pai_id'    => $grupoDeCobranca->id,
                'mes'       => '2026-08',
            ])
            ->assertRedirect();

        // `companies.company_group_id` é o que o NPS de grupo usa. Ele não
        // pode mudar — é a condição que torna a Fase 143 possível.
        $this->assertEquals($antes, Company::pluck('company_group_id', 'id'));
    }
}
