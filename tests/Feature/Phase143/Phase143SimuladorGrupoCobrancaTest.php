<?php

namespace Tests\Feature\Phase143;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\Configuracao;
use App\Models\ContratoServico;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoRegraTabela;
use App\Services\Fechamento\SimuladorGrupoCobrancaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 143 Plano 02 — Tarefa 2: `SimuladorGrupoCobrancaService`, a prévia do
 * impacto ANTES de qualquer gravação.
 *
 * O que estes testes protegem, em ordem de risco:
 *
 * 1. **A prévia não pode mentir.** O número que ela mostra tem de ser o que
 *    a cobrança vai cobrar — por isso ela usa `FechamentoFaixaResolver` como
 *    está, e há teste conferindo o caso real do 143-CONTEXT (D-02) nos dois
 *    lados: R$ 33.500 em quatro linhas → R$ 21.000 em uma.
 * 2. **A prévia não pode escrever.** Nem snapshot, nem `parent_id`, nem log.
 * 3. **A prévia tem de dizer de ONDE vem a tabela.** No caso real a
 *    diferença entre R$ 21.000 e R$ 12.000 é exatamente qual tabela governa
 *    (contrato × presumida) — esconder isso vira uma decisão de R$ 9.000/mês
 *    sem contexto.
 */
class Phase143SimuladorGrupoCobrancaTest extends TestCase
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

    private function simulador(): SimuladorGrupoCobrancaService
    {
        return app(SimuladorGrupoCobrancaService::class);
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

    /** Tabela de GRUPO de uma faixa só (aberta) — cobra sempre o mesmo valor. */
    private function tabelaDeGrupoUnica(CompanyGroup $grupo, float $valor): void
    {
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1,
            'limite_superior'  => null, 'valor' => $valor, 'valor_e_piso' => true,
        ]);
    }

    /**
     * Monta o caso real do 143-CONTEXT com as tabelas de grupo que
     * reproduzem a cobrança medida em produção em ago/2026:
     * MPozenato R$ 9.500 · Gran Belo R$ 12.000 · DRossi R$ 6.000 ·
     * Lyam R$ 6.000 = **R$ 33.500** somados.
     *
     * A tabela do MPozenato tem um segundo degrau (acima de R$ 5 mi cobra
     * R$ 21.000) — é ele que passa a valer quando os R$ 12,68 mi do cliente
     * inteiro caem numa faixa só.
     *
     * @return array{0: CompanyGroup, 1: array<string, CompanyGroup>}
     */
    private function montarCasoMPozenato(): array
    {
        $gestao = $this->criarServicoGestao();

        $raiz      = CompanyGroup::create(['name' => 'MPozenato', 'color' => '#000']);
        $subgrupos = [];

        foreach (['DRossi', 'Gran Belo', 'Lyam'] as $nome) {
            $subgrupos[$nome] = CompanyGroup::create(['name' => $nome, 'color' => '#000']);
        }

        foreach (self::FATURAMENTO_MPOZENATO as $valor) {
            $this->criarMembro($gestao, $raiz, $valor);
        }
        foreach (self::FATURAMENTO_DROSSI as $valor) {
            $this->criarMembro($gestao, $subgrupos['DRossi'], $valor);
        }
        foreach (self::FATURAMENTO_GRAN_BELO as $valor) {
            $this->criarMembro($gestao, $subgrupos['Gran Belo'], $valor);
        }
        foreach (self::FATURAMENTO_LYAM as $valor) {
            $this->criarMembro($gestao, $subgrupos['Lyam'], $valor);
        }

        // MPozenato: até R$ 5 mi cobra R$ 9.500; acima, R$ 21.000.
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 1,
            'limite_superior'  => 5_000_000.00, 'valor' => 9_500.00, 'valor_e_piso' => false,
        ]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $raiz->id, 'ordem' => 2,
            'limite_superior'  => null, 'valor' => 21_000.00, 'valor_e_piso' => true,
        ]);

        $this->tabelaDeGrupoUnica($subgrupos['Gran Belo'], 12_000.00);
        $this->tabelaDeGrupoUnica($subgrupos['DRossi'], 6_000.00);
        $this->tabelaDeGrupoUnica($subgrupos['Lyam'], 6_000.00);

        return [$raiz, $subgrupos];
    }

    // ─── O caso real, antes × depois ──────────────────────────────────────

    #[Test]
    public function o_caso_mpozenato_sai_de_quatro_linhas_de_33500_para_uma_de_21000(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [$raiz, $subgrupos] = $this->montarCasoMPozenato();

        $resultado = $this->simulador()->simular(
            collect($subgrupos)->pluck('id')->all(),
            $raiz->id,
            '2026-08'
        );

        // ── ANTES: quatro linhas, R$ 33.500 ───────────────────────────────
        $antes = collect($resultado['antes']['linhas'])->keyBy('company_group_id');

        $this->assertCount(4, $antes, 'Hoje o cliente é cobrado em quatro pedaços.');
        $this->assertEqualsWithDelta(9_500.00, (float) $antes[$raiz->id]['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(12_000.00, (float) $antes[$subgrupos['Gran Belo']->id]['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(6_000.00, (float) $antes[$subgrupos['DRossi']->id]['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(6_000.00, (float) $antes[$subgrupos['Lyam']->id]['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(33_500.00, $resultado['antes']['total_cobranca'], 0.01);

        // ── DEPOIS: uma linha, R$ 21.000 ──────────────────────────────────
        $depois = $resultado['depois']['linhas'];

        $this->assertCount(1, $depois, 'Como um cliente só, R$ 12,68 mi caem numa faixa só.');

        $linha = $depois[0];

        $this->assertSame($raiz->id, $linha['company_group_id']);
        $this->assertSame('MPozenato', $linha['grupo_nome']);
        $this->assertSame(10, $linha['empresas_count']);
        $this->assertEqualsWithDelta(self::TOTAL_DO_CLIENTE, (float) $linha['faturamento_total'], 0.01);
        $this->assertSame(2, $linha['faixa_ordem']);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha['cobranca_mensal'], 0.01);
        $this->assertSame('grupo', $linha['tabela_origem']);
        $this->assertSame(
            'MPozenato',
            $linha['tabela_grupo_nome'],
            'A prévia tem de dizer QUAL tabela governa o resultado — aqui, a do grupo-pai.'
        );

        // A composição mostra os quatro grupos do cadastro dentro da linha.
        $this->assertCount(4, $linha['subgrupos']);
        $this->assertEqualsCanonicalizing(
            ['MPozenato', 'DRossi', 'Gran Belo', 'Lyam'],
            collect($linha['subgrupos'])->pluck('nome')->all()
        );

        // ── O delta, que é o número da decisão ────────────────────────────
        $this->assertEqualsWithDelta(21_000.00, $resultado['depois']['total_cobranca'], 0.01);
        $this->assertEqualsWithDelta(
            -12_500.00,
            $resultado['delta'],
            0.01,
            'A correção DERRUBA a cobrança — é o número que a pessoa precisa ver antes de aprovar (143-CONTEXT, D-02).'
        );
    }

    #[Test]
    public function despendurar_desfaz_a_juncao_e_o_delta_volta_a_subir(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [$raiz, $subgrupos] = $this->montarCasoMPozenato();

        // Estado de partida: a árvore JÁ montada.
        foreach ($subgrupos as $sub) {
            $sub->update(['parent_id' => $raiz->id]);
        }

        $resultado = $this->simulador()->simular(
            collect($subgrupos)->pluck('id')->all(),
            null,
            '2026-08'
        );

        $this->assertCount(1, $resultado['antes']['linhas']);
        $this->assertEqualsWithDelta(21_000.00, $resultado['antes']['total_cobranca'], 0.01);

        $this->assertCount(4, $resultado['depois']['linhas']);
        $this->assertEqualsWithDelta(33_500.00, $resultado['depois']['total_cobranca'], 0.01);
        $this->assertEqualsWithDelta(12_500.00, $resultado['delta'], 0.01);
    }

    // ─── De onde vem a tabela: contrato × presumida ────────────────────────

    /**
     * @return array{0: CompanyGroup, 1: CompanyGroup, 2: Company, 3: Company}
     */
    private function montarDoisGruposComTabelaDeEmpresa(float $fatDaRaiz, float $fatDoSub): array
    {
        $gestao = $this->criarServicoGestao();

        $raiz = CompanyGroup::create(['name' => 'Cliente Raiz', 'color' => '#000']);
        $sub  = CompanyGroup::create(['name' => 'Cliente Sub', 'color' => '#000']);

        $daRaiz = $this->criarMembro($gestao, $raiz, $fatDaRaiz, 'Empresa da Raiz');
        $doSub  = $this->criarMembro($gestao, $sub, $fatDoSub, 'Empresa do Sub');

        // A tabela da raiz veio da LEITURA DO CONTRATO (conferida por gente).
        EmpresaFaixaFaturamento::create([
            'company_id' => $daRaiz->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 21_000.00, 'valor_e_piso' => true,
            'origem' => EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
        ]);

        // A do subgrupo é PRESUMIDA a partir do serviço — nunca conferida.
        EmpresaFaixaFaturamento::create([
            'company_id' => $doSub->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 12_000.00, 'valor_e_piso' => true,
            'origem' => EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
        ]);

        return [$raiz, $sub, $daRaiz, $doSub];
    }

    #[Test]
    public function quando_a_tabela_vem_do_contrato_a_previa_diz_contrato(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        // A empresa da RAIZ é a maior → é a tabela DELA que classifica a soma.
        [$raiz, $sub, $daRaiz] = $this->montarDoisGruposComTabelaDeEmpresa(9_000_000.00, 1_000_000.00);

        $resultado = $this->simulador()->simular([$sub->id], $raiz->id, '2026-08');

        $linha = $resultado['depois']['linhas'][0];

        $this->assertSame($raiz->id, $linha['company_group_id']);
        $this->assertEqualsWithDelta(21_000.00, (float) $linha['cobranca_mensal'], 0.01);
        $this->assertSame('propria', $linha['tabela_origem']);
        $this->assertSame(
            EmpresaFaixaFaturamento::ORIGEM_CONTRATO,
            $linha['procedencia'],
            'Esta é a informação que separa R$ 21.000 de R$ 12.000 no caso real — a prévia não pode escondê-la.'
        );
        $this->assertSame(
            'Empresa da Raiz',
            $linha['tabela_herdada_de_nome'],
            'Herança invisível é o defeito que a Fase 138 veio corrigir: a prévia diz de QUAL empresa a tabela veio.'
        );
        $this->assertSame($daRaiz->id, $linha['empresa_ancora_id']);
    }

    #[Test]
    public function quando_a_tabela_vem_de_uma_presuncao_a_previa_diz_presumida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        // Agora a empresa do SUBGRUPO é a maior → a âncora muda, e com ela a
        // tabela que governa o cliente inteiro.
        [$raiz, $sub] = $this->montarDoisGruposComTabelaDeEmpresa(1_000_000.00, 9_000_000.00);

        $resultado = $this->simulador()->simular([$sub->id], $raiz->id, '2026-08');

        $linha = $resultado['depois']['linhas'][0];

        $this->assertSame($raiz->id, $linha['company_group_id'], 'A linha continua sendo a da RAIZ.');
        $this->assertEqualsWithDelta(12_000.00, (float) $linha['cobranca_mensal'], 0.01);
        $this->assertSame(
            EmpresaFaixaFaturamento::ORIGEM_PRESUMIDA_SERVICO,
            $linha['procedencia'],
            'R$ 9.000/mês de diferença por causa de uma tabela que ninguém conferiu — a prévia precisa gritar isso.'
        );
        $this->assertSame('Empresa do Sub', $linha['tabela_herdada_de_nome']);
    }

    #[Test]
    public function tabela_so_na_raiz_e_tabela_so_no_subgrupo_dao_valores_diferentes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        $gestao = $this->criarServicoGestao();

        $raizA = CompanyGroup::create(['name' => 'Raiz A', 'color' => '#000']);
        $subA  = CompanyGroup::create(['name' => 'Sub A', 'color' => '#000']);
        $this->criarMembro($gestao, $raizA, 2_000_000.00);
        $this->criarMembro($gestao, $subA, 1_000_000.00);
        $this->tabelaDeGrupoUnica($raizA, 18_000.00); // só a RAIZ tem tabela

        // ⚠️ Aqui a empresa do SUBGRUPO é a maior DE PROPÓSITO: o 2º degrau
        // do resolver ("sem tabela na raiz, vale a do subgrupo") consulta o
        // subgrupo DA ÂNCORA. Com a âncora na raiz, a tabela do subgrupo nem
        // é olhada — e a linha cairia em "sem régua".
        $raizB = CompanyGroup::create(['name' => 'Raiz B', 'color' => '#000']);
        $subB  = CompanyGroup::create(['name' => 'Sub B', 'color' => '#000']);
        $this->criarMembro($gestao, $raizB, 1_000_000.00);
        $this->criarMembro($gestao, $subB, 2_000_000.00);
        $this->tabelaDeGrupoUnica($subB, 7_000.00); // só o SUBGRUPO tem tabela

        $comTabelaNaRaiz = $this->simulador()->simular([$subA->id], $raizA->id, '2026-08');
        $linhaA          = $comTabelaNaRaiz['depois']['linhas'][0];

        $this->assertEqualsWithDelta(18_000.00, (float) $linhaA['cobranca_mensal'], 0.01);
        $this->assertSame('grupo', $linhaA['tabela_origem']);
        $this->assertSame('Raiz A', $linhaA['tabela_grupo_nome'], '1º degrau: a tabela da raiz manda.');

        $comTabelaNoSub = $this->simulador()->simular([$subB->id], $raizB->id, '2026-08');
        $linhaB         = $comTabelaNoSub['depois']['linhas'][0];

        $this->assertEqualsWithDelta(
            7_000.00,
            (float) $linhaB['cobranca_mensal'],
            0.01,
            '2º degrau: sem tabela na raiz, vale a do subgrupo — perder este degrau deixaria o cliente sem régua.'
        );
        $this->assertSame('grupo', $linhaB['tabela_origem']);
        $this->assertSame('Sub B', $linhaB['tabela_grupo_nome']);

        $this->assertNotEquals($linhaA['cobranca_mensal'], $linhaB['cobranca_mensal']);
    }

    // ─── Pureza ───────────────────────────────────────────────────────────

    #[Test]
    public function a_previa_nao_escreve_absolutamente_nada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [$raiz, $subgrupos] = $this->montarCasoMPozenato();

        $faixasAntes = DB::table('grupo_faixas_faturamento')->count();

        $this->simulador()->simular(collect($subgrupos)->pluck('id')->all(), $raiz->id, '2026-08');

        // Nenhum `parent_id` gravado — é o ponto: a prévia roda ANTES da decisão.
        $this->assertSame(
            0,
            CompanyGroup::whereNotNull('parent_id')->count(),
            'A simulação é em memória — nenhum grupo pode sair pendurado do banco por causa dela.'
        );
        $this->assertSame(0, DB::table('fechamento_snapshots')->count());
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
        $this->assertSame($faixasAntes, DB::table('grupo_faixas_faturamento')->count());
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'grupo_cobranca_hierarquia')->count());
    }

    #[Test]
    public function a_mesma_previa_rodada_duas_vezes_da_o_mesmo_resultado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [$raiz, $subgrupos] = $this->montarCasoMPozenato();
        $ids = collect($subgrupos)->pluck('id')->all();

        $primeira = $this->simulador()->simular($ids, $raiz->id, '2026-08');
        $segunda  = $this->simulador()->simular($ids, $raiz->id, '2026-08');

        $this->assertEquals(
            $primeira,
            $segunda,
            'Sem efeito colateral: rodar a prévia duas vezes tem de dar exatamente o mesmo número.'
        );
    }

    // ─── A trava do model é reusada, não reimplementada ───────────────────

    #[Test]
    public function simular_um_arranjo_impossivel_recusa_com_a_mensagem_do_model(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $avo   = CompanyGroup::create(['name' => 'Avô', 'color' => '#000']);
        $pai   = CompanyGroup::create(['name' => 'Pai', 'color' => '#000', 'parent_id' => $avo->id]);
        $outro = CompanyGroup::create(['name' => 'Outro', 'color' => '#000']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('já está dentro de outro grupo — a hierarquia tem um nível só.');

        // A prévia de um arranjo que a gravação recusaria seria pior que
        // nenhuma prévia — mostraria um número que nunca vai valer.
        $this->simulador()->simular([$outro->id], $pai->id, '2026-08');
    }

    #[Test]
    public function simular_um_ciclo_recusa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $a = CompanyGroup::create(['name' => 'A', 'color' => '#000']);
        $b = CompanyGroup::create(['name' => 'B', 'color' => '#000', 'parent_id' => $a->id]);

        $this->expectException(\InvalidArgumentException::class);

        // A→B→A: `A` já é pai de `B`, então `A` não pode ganhar pai.
        $this->simulador()->simular([$a->id], $b->id, '2026-08');
    }

    // ─── Regressão zero ───────────────────────────────────────────────────

    #[Test]
    public function simular_sem_mudanca_nenhuma_da_delta_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');

        [, $subgrupos] = $this->montarCasoMPozenato();

        // Despendurar quem já está solto: os dois lados têm de ser idênticos.
        $resultado = $this->simulador()->simular([$subgrupos['DRossi']->id], null, '2026-08');

        $this->assertSame($resultado['antes'], $resultado['depois']);
        $this->assertSame(0.0, $resultado['delta']);
    }
}
