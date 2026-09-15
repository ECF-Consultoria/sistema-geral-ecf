<?php

namespace Tests\Feature\Phase143;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 05 — Tarefa 2 (item 5 do `deferred-items.md`): a tela do
 * fechamento passa a dizer QUAIS grupos ela está somando numa linha só.
 *
 * ⚠️ Sem isto, o usuário monta o grupo MPozenato, abre o fechamento para
 * conferir se a cobrança caiu de R$ 33.500 para R$ 21.000 — e lê
 * *"MPozenato, 10 empresas"* sem nenhuma pista de que DRossi, Gran Belo e
 * Lyam estão ali dentro. Conferência às cegas exatamente no momento da
 * conferência.
 *
 * ⚠️ **A chave `subgrupos` tem de sair nos CINCO literais de linha** de
 * `AdminController::fechamento()` — empresa ao vivo, empresa congelada com e
 * sem linha no mês, grupo ao vivo e grupo congelado. Faltar em um produz a
 * propriedade que o JSX usa e o backend nunca emite; foi assim que nasceu o
 * `cobranca_mensal_grupo` fantasma nesta mesma tela. Grupo sem nenhum grupo
 * dentro emite lista VAZIA, nunca omite a chave.
 *
 * Toda asserção é por reconsulta às props Inertia — nunca por texto de tela.
 */
class Phase143ComposicaoDoGrupoNaTelaTest extends TestCase
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

    private function criarMembro(Servico $servico, CompanyGroup $grupo, float $faturamento): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => 'cust-'.uniqid(),
            'company_group_id' => $grupo->id,
        ]);

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

    /**
     * Empresa fora de qualquer grupo, com serviço contratado — sem contrato
     * ela seria cortada por `fechamentoRemoverForaDeEscopo` (quick 260909-e8n)
     * e nunca chegaria ao literal que este teste precisa cobrir.
     */
    private function criarSemGrupo(string $custId): Company
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );

        $company = Company::factory()->create([
            'adman_account_id' => $custId,
            'company_group_id' => null,
        ]);

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        return $company;
    }

    /**
     * O caso do 143-CONTEXT: MPozenato (2 empresas) + DRossi (4) +
     * Gran Belo (2) + Lyam (2), 10 no total.
     *
     * @return array{0: CompanyGroup, 1: array<string, CompanyGroup>}
     */
    private function montarCasoMPozenato(bool $juntado): array
    {
        $servico = Servico::firstOrCreate(
            ['nome' => 'Gestão'],
            ['valor_padrao' => 0, 'tipo_cobranca' => Servico::TIPO_MENSAL, 'ativo' => true]
        );

        $raiz = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);

        $dentro = [];
        foreach (['DRossi' => 4, 'Gran Belo' => 2, 'Lyam' => 2] as $nome => $quantas) {
            $dentro[$nome] = CompanyGroup::create(array_filter([
                'name'      => $nome,
                'color'     => '#000',
                'parent_id' => $juntado ? $raiz->id : null,
            ]));

            for ($i = 0; $i < $quantas; $i++) {
                $this->criarMembro($servico, $dentro[$nome], 300_000.00);
            }
        }

        $this->criarMembro($servico, $raiz, 3_000_000.00);
        $this->criarMembro($servico, $raiz, 812_487.89);

        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 12_000.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);

        return [$raiz, $dentro];
    }

    /** @return \Illuminate\Support\Collection<int, array> */
    private function linhasDaTela(User $admin, string $mes = '2026-08'): \Illuminate\Support\Collection
    {
        $response = $this->actingAs($admin)->get("/administrativo/financeiro?mes={$mes}");
        $response->assertOk();

        return collect($response->viewData('page')['props']['companies']);
    }

    // ─── ⚠️ A chave sai nos CINCO literais ────────────────────────────────

    #[Test]
    public function a_chave_sai_em_toda_linha_do_ramo_ao_vivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        $this->montarCasoMPozenato(juntado: true);

        // Uma empresa fora de qualquer grupo, para cobrir o literal de
        // empresa ao vivo (literal 1 de 5).
        $this->criarSemGrupo('cust-solo');

        $linhas = $this->linhasDaTela($admin);

        $this->assertGreaterThan(1, $linhas->count());

        foreach ($linhas as $linha) {
            $this->assertArrayHasKey('subgrupos', $linha, 'Faltar em UM literal cria a propriedade que o JSX usa e o backend nunca emite.');
            $this->assertIsArray($linha['subgrupos']);
            $this->assertArrayHasKey('subgrupos_sao_de_hoje', $linha);

            foreach ($linha['filhas'] ?? [] as $filha) {
                $this->assertArrayHasKey('subgrupos', $filha, 'As linhas-membro saem do mesmo literal de empresa — a chave vale para elas também.');
            }
        }
    }

    #[Test]
    public function a_chave_sai_em_toda_linha_do_ramo_congelado_inclusive_sem_linha_no_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        $this->montarCasoMPozenato(juntado: true);

        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-08']));

        // Empresa criada DEPOIS do fechamento — cai no literal 2 de 5
        // (congelada sem linha na competência).
        $novata = $this->criarSemGrupo('cust-novata');

        $linhas = $this->linhasDaTela($admin);

        foreach ($linhas as $linha) {
            $this->assertArrayHasKey('subgrupos', $linha);
            $this->assertArrayHasKey('subgrupos_sao_de_hoje', $linha);
        }

        $linhaNovata = $linhas->firstWhere('id', $novata->id);
        $this->assertNotNull($linhaNovata, 'A empresa sem linha nesta competência precisa aparecer — é o literal 2 de 5.');
        $this->assertSame([], $linhaNovata['subgrupos']);
    }

    // ─── ⚠️ Regressão zero: sem árvore, tela idêntica à de hoje ───────────

    #[Test]
    public function grupo_sem_nenhum_grupo_dentro_emite_lista_vazia(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        $this->montarCasoMPozenato(juntado: false);

        $linhasDeGrupo = $this->linhasDaTela($admin)->filter(fn ($l) => ($l['tipo'] ?? 'empresa') === 'grupo');

        $this->assertCount(4, $linhasDeGrupo, 'Sem ninguém juntado, quatro linhas — como sempre foi.');

        foreach ($linhasDeGrupo as $linha) {
            $this->assertSame(
                [],
                $linha['subgrupos'],
                'Repetir o nome do próprio grupo como composição seria ruído: enquanto ninguém juntar nada, a tela é idêntica à de hoje.'
            );
        }
    }

    #[Test]
    public function linha_de_empresa_nunca_traz_grupo_dentro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        $this->montarCasoMPozenato(juntado: true);

        $linhasDeEmpresa = $this->linhasDaTela($admin)->filter(fn ($l) => ($l['tipo'] ?? 'empresa') === 'empresa');

        foreach ($linhasDeEmpresa as $linha) {
            $this->assertSame([], $linha['subgrupos']);
            $this->assertFalse($linha['subgrupos_sao_de_hoje']);
        }
    }

    // ─── Com a junção montada, a tela diz o que está somando ──────────────

    #[Test]
    public function com_os_grupos_juntados_a_linha_lista_quais_grupos_ela_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz, $dentro] = $this->montarCasoMPozenato(juntado: true);

        $linha = $this->linhasDaTela($admin)
            ->filter(fn ($l) => ($l['tipo'] ?? 'empresa') === 'grupo')
            ->firstWhere('company_group_id', $raiz->id);

        $this->assertNotNull($linha);
        $this->assertCount(10, $linha['filhas'], 'As 10 empresas continuam na mesma linha de cobrança.');

        $porNome = collect($linha['subgrupos'])->keyBy('nome');

        $this->assertCount(4, $porNome, 'MPozenato + DRossi + Gran Belo + Lyam — os quatro grupos que a cobrança junta.');
        $this->assertSame(2, $porNome['MPozenato']['empresas']);
        $this->assertSame(4, $porNome['DRossi']['empresas']);
        $this->assertSame(2, $porNome['Gran Belo']['empresas']);
        $this->assertSame(2, $porNome['Lyam']['empresas']);

        $this->assertTrue($porNome['MPozenato']['eh_a_raiz'], 'O grupo que dá nome à cobrança fica marcado.');
        $this->assertFalse($porNome['DRossi']['eh_a_raiz']);

        $this->assertSame(
            10,
            collect($linha['subgrupos'])->sum('empresas'),
            'A soma da composição tem de fechar com a contagem de empresas da linha.'
        );

        $this->assertSame(
            $dentro['DRossi']->id,
            $porNome['DRossi']['id'],
            'Cada item traz o id do grupo, para a tela poder levar até ele.'
        );
    }

    // ─── ⚠️ Mês fechado: a divisão é a de hoje, e a tela diz isso ─────────

    #[Test]
    public function competencia_em_curso_nao_marca_a_divisao_como_de_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz] = $this->montarCasoMPozenato(juntado: true);

        $linha = $this->linhasDaTela($admin)->firstWhere('company_group_id', $raiz->id);

        $this->assertNotEmpty($linha['subgrupos']);
        $this->assertFalse(
            $linha['subgrupos_sao_de_hoje'],
            'Mês ainda aberto: a divisão exibida É a que está valendo — não há "de hoje" versus "de então".'
        );
    }

    #[Test]
    public function mes_fechado_marca_que_a_divisao_exibida_e_a_de_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $admin = $this->criarAdmin();
        [$raiz] = $this->montarCasoMPozenato(juntado: true);

        $this->assertSame(0, Artisan::call('fechamento:consolidar-mes', ['--mes' => '2026-08']));

        $linha = $this->linhasDaTela($admin)
            ->filter(fn ($l) => ($l['tipo'] ?? 'empresa') === 'grupo')
            ->firstWhere('company_group_id', $raiz->id);

        $this->assertNotNull($linha);
        $this->assertNotEmpty($linha['subgrupos']);
        $this->assertTrue(
            $linha['subgrupos_sao_de_hoje'],
            '⚠️ O fechamento congelado não guarda em qual grupo cada empresa estava naquele mês. A tela pode mostrar a divisão de hoje, mas TEM de dizer que é a de hoje — mostrar a de hoje como se fosse a daquele mês é o único desfecho proibido.'
        );
    }
}
