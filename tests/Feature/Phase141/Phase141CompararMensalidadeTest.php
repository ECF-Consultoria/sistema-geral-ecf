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
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\FechamentoRegraTabela;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 141 Plano 05 — Tarefa 2: trava a concordância entre
 * `fechamento:comparar-mensalidade --json` e o que `fechamento:consolidar-mes`
 * de fato GRAVA — nos dois lados (ANTES com a flag desligada, DEPOIS com a
 * flag ligada). Toda asserção é por RECONSULTA ao banco ou pelo `--json`
 * do relatório — nunca pelo texto formatado
 * (`.planning/learnings/desempenho-bonificacao.md` §4).
 *
 * ⚠️ ANTES e DEPOIS são DOIS testes SEPARADOS (mesmo padrão documentado em
 * `Phase141ConsolidarRegraNovaTest`, cenário BARAOSHOP): o console Kernel
 * de teste memoiza a instância do comando entre chamadas de
 * `$this->artisan()` dentro do MESMO método — rodar
 * `fechamento:consolidar-mes` duas vezes no mesmo teste (uma com a flag
 * desligada, outra ligada) reusaria a MESMA instância de
 * `FechamentoRegraTabela` injetada na primeira resolução, cujo `ativa()`
 * já está memoizado — a segunda rodada leria o valor ANTIGO (falso
 * negativo de teste, não comportamento real: produção roda cada
 * `artisan` como processo novo). Por isso cada teste roda
 * `fechamento:consolidar-mes` UMA ÚNICA vez, com a flag já no estado
 * final antes da chamada, sobre um cenário construído do zero
 * (`montarCenario()`).
 */
class Phase141CompararMensalidadeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ligarFlag(): void
    {
        Configuracao::set(FechamentoRegraTabela::CHAVE, '1');
    }

    /** Serviço "Gestão" (ML) com tabela DE SERVIÇO própria — usada pelo cenário de herança. */
    private function criarServicoGestaoComTabela(): Servico
    {
        $servico = Servico::create([
            'nome'                   => 'Gestão '.uniqid(),
            'valor_padrao'           => 0,
            'tipo_cobranca'          => Servico::TIPO_MENSAL,
            'ativo'                  => true,
            'plataforma'             => 'Mercado Livre',
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => true,
        ]);

        foreach ($this->faixasReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            ServicoFaixaFaturamento::create([
                'servico_id'      => $servico->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
            ]);
        }

        return $servico;
    }

    private function criarServicoShopee(): Servico
    {
        return Servico::create([
            'nome'                   => 'Gestão de ADS Shopee '.uniqid(),
            'valor_padrao'           => 0,
            'tipo_cobranca'          => Servico::TIPO_MENSAL,
            'ativo'                  => true,
            'setor'                  => Servico::SETOR_SHOPEE,
            'plataforma'             => 'Shopee',
            'usa_tabela_progressiva' => true,
        ]);
    }

    /** Serviço sem tabela progressiva (o caso de Mentoria, D-02/D-03). */
    private function criarServicoMentoria(): Servico
    {
        return Servico::create([
            'nome'                   => 'Mentoria '.uniqid(),
            'valor_padrao'           => 0,
            'tipo_cobranca'          => Servico::TIPO_MENSAL,
            'ativo'                  => true,
            'setor'                  => Servico::SETOR_PERFORMANCE,
            'usa_tabela_progressiva' => false,
        ]);
    }

    private function faixasReais(): array
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

    private function criarTabelaPropriaComFaixasReais(Company $company): void
    {
        foreach ($this->faixasReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            EmpresaFaixaFaturamento::create([
                'company_id'      => $company->id,
                'ordem'           => $ordem,
                'limite_superior' => $limiteSuperior,
                'valor'           => $valor,
                'valor_e_piso'    => $valorEPiso,
                'origem'          => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
            ]);
        }
    }

    /**
     * Roda o relatório e devolve o array decodificado do `--json`.
     */
    private function relatorio(string $mes): array
    {
        $exitCode = Artisan::call('fechamento:comparar-mensalidade', [
            '--mes'  => $mes,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, 'O comando de comparação é leitura pura — nunca deveria falhar.');

        $decodificado = json_decode(Artisan::output(), true);

        $this->assertNotNull($decodificado, 'A saída --json precisa ser parseável.');

        return $decodificado;
    }

    private function linhaEmpresaDoRelatorio(array $relatorio, int $companyId): ?array
    {
        foreach ($relatorio['empresas'] as $linha) {
            if ($linha['id'] === $companyId) {
                return $linha;
            }
        }

        return null;
    }

    private function linhaGrupoDoRelatorio(array $relatorio, int $groupId): ?array
    {
        foreach ($relatorio['grupos'] as $linha) {
            if ($linha['id'] === $groupId) {
                return $linha;
            }
        }

        return null;
    }

    // ─── Cenário completo: BARAOSHOP + herança de serviço + Mentoria + grupo ─

    /**
     * Monta os 4 perfis exigidos pelo `<behavior>` da Tarefa 2 — construído
     * do zero em CADA teste (nunca reaproveitado entre ANTES e DEPOIS, ver
     * o comentário da classe sobre a memoização do comando de teste).
     *
     * @return array{baraoshop: Company, heranca: Company, mentorada: Company, grupo: CompanyGroup}
     */
    private function montarCenario(): array
    {
        $gestaoComTabela = $this->criarServicoGestaoComTabela();
        $shopee          = $this->criarServicoShopee();
        $mentoria        = $this->criarServicoMentoria();

        // ── 1) BARAOSHOP: tabela PRÓPRIA + contrato de Shopee por cima ──
        $baraoshop = Company::factory()->create(['adman_account_id' => 'cust-baraoshop']);

        ContratoServico::factory()->paraServico($gestaoComTabela)->create([
            'company_id'       => $baraoshop->id,
            'ativo'            => true,
            'valor_contratado' => 0,
        ]);
        ContratoServico::factory()->paraServico($shopee)->create([
            'company_id'       => $baraoshop->id,
            'ativo'            => true,
            'valor_contratado' => 2_500.00,
        ]);
        $this->criarTabelaPropriaComFaixasReais($baraoshop);

        AdmanMetric::create(['company_id' => $baraoshop->id, 'reference_date' => '2026-08-10', 'revenue' => 350_262.90]);
        \App\Models\ShopeeMetric::create(['company_id' => $baraoshop->id, 'reference_date' => '2026-08-10', 'revenue' => 138_000.00]);

        // ── 2) Empresa que HOJE herda a tabela do serviço, sem tabela própria ──
        $heranca = Company::factory()->create(['adman_account_id' => 'cust-heranca']);
        ContratoServico::factory()->paraServico($gestaoComTabela)->create([
            'company_id'       => $heranca->id,
            'ativo'            => true,
            // Valor fixo, nunca aleatório: DEPOIS (sem tabela de
            // grupo/própria) esta empresa passa a cobrar ESTADO_VALOR_FIXO
            // — o teste precisa de um número determinístico pra travar
            // esse valor exato.
            'valor_contratado' => 950.00,
        ]);
        AdmanMetric::create(['company_id' => $heranca->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        // ── 3) Empresa só de Mentoria — nunca teve tabela, cobra fixo ──
        $mentorada = Company::factory()->create(['adman_account_id' => 'cust-mentoria']);
        ContratoServico::factory()->paraServico($mentoria)->create([
            'company_id'       => $mentorada->id,
            'ativo'            => true,
            'valor_contratado' => 1_800.00,
        ]);

        // ── 4) Grupo com tabela própria ──
        $grupo = CompanyGroup::create(['name' => 'Grupo Fase 141 Comparativo', 'color' => '#000']);
        foreach ($this->faixasReais() as [$ordem, $limiteSuperior, $valor, $valorEPiso]) {
            GrupoFaixaFaturamento::create([
                'company_group_id' => $grupo->id,
                'ordem'             => $ordem,
                'limite_superior'   => $limiteSuperior,
                'valor'             => $valor,
                'valor_e_piso'      => $valorEPiso,
            ]);
        }
        $membroA = Company::factory()->create(['adman_account_id' => 'cust-grupo-a', 'company_group_id' => $grupo->id]);
        $membroB = Company::factory()->create(['adman_account_id' => 'cust-grupo-b', 'company_group_id' => $grupo->id]);
        ContratoServico::factory()->paraServico($gestaoComTabela)->create(['company_id' => $membroA->id, 'ativo' => true, 'valor_contratado' => 900.00]);
        ContratoServico::factory()->paraServico($gestaoComTabela)->create(['company_id' => $membroB->id, 'ativo' => true, 'valor_contratado' => 700.00]);
        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 250_000.00]);

        return compact('baraoshop', 'heranca', 'mentorada', 'grupo');
    }

    #[Test]
    public function lado_antes_do_relatorio_bate_com_o_que_a_consolidacao_grava_com_a_flag_desligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        ['baraoshop' => $baraoshop, 'heranca' => $heranca, 'mentorada' => $mentorada, 'grupo' => $grupo] = $this->montarCenario();

        // Contagens capturadas em torno APENAS da chamada ao relatório — a
        // consolidação abaixo grava snapshot de propósito (é o passo do
        // TESTE que produz o "gabarito", não do comando sob teste); a
        // prova de "não escreve nada" precisa isolar só o comparador.
        $configuracoesAntesDoRelatorio    = DB::table('configuracoes')->count();
        $faixasEmpresaAntesDoRelatorio    = DB::table('empresa_faixas_faturamento')->count();
        $snapshotsEmpresaAntesDoRelatorio = DB::table('fechamento_snapshots')->count();
        $snapshotsGrupoAntesDoRelatorio   = DB::table('fechamento_grupo_snapshots')->count();

        // ── Roda o relatório (leitura pura) ──
        $relatorio = $this->relatorio('2026-08');

        // ── O comando de comparação não pode ter escrito NADA ──
        $this->assertSame($configuracoesAntesDoRelatorio, DB::table('configuracoes')->count(), 'comparar-mensalidade não pode alterar configuracoes.');
        $this->assertSame($faixasEmpresaAntesDoRelatorio, DB::table('empresa_faixas_faturamento')->count(), 'comparar-mensalidade não pode alterar empresa_faixas_faturamento.');
        $this->assertSame($snapshotsEmpresaAntesDoRelatorio, DB::table('fechamento_snapshots')->count(), 'comparar-mensalidade não pode gravar fechamento_snapshots.');
        $this->assertSame($snapshotsGrupoAntesDoRelatorio, DB::table('fechamento_grupo_snapshots')->count(), 'comparar-mensalidade não pode gravar fechamento_grupo_snapshots.');
        $this->assertSame(0, $snapshotsEmpresaAntesDoRelatorio, 'sanity: nenhuma consolidação real rodou ainda.');

        // ── Consolida de verdade UMA VEZ, flag desligada (gabarito ANTES) ──
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $antesBaraoshop = DB::table('fechamento_snapshots')->where('company_id', $baraoshop->id)->first();
        $antesHeranca   = DB::table('fechamento_snapshots')->where('company_id', $heranca->id)->first();
        $antesMentorada = DB::table('fechamento_snapshots')->where('company_id', $mentorada->id)->first();
        $antesGrupo     = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        // ── BARAOSHOP: faixa R$ 3.000 + contrato de Shopee R$ 2.500 — o bug que abriu a Fase 141 ──
        $linhaBaraoshop = $this->linhaEmpresaDoRelatorio($relatorio, $baraoshop->id);
        $this->assertNotNull($linhaBaraoshop);
        $this->assertEqualsWithDelta((float) $antesBaraoshop->faturamento_total, $linhaBaraoshop['antes']['faturamento_total'], 0.01);
        $this->assertSame((int) $antesBaraoshop->faixa_ordem, $linhaBaraoshop['antes']['faixa_ordem']);
        $this->assertEqualsWithDelta((float) $antesBaraoshop->cobranca_mensal, $linhaBaraoshop['antes']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(5_500.00, (float) $antesBaraoshop->cobranca_mensal, 0.01, 'ANTES: faixa R$ 3.000 + contrato de Shopee R$ 2.500 — o bug que abriu a Fase 141.');

        // ── Empresa que herda a tabela do serviço (ainda existe ANTES) ──
        $linhaHeranca = $this->linhaEmpresaDoRelatorio($relatorio, $heranca->id);
        $this->assertNotNull($linhaHeranca);
        $this->assertSame('tabela_servico', $linhaHeranca['antes']['regua'], 'ANTES: herdava a tabela do serviço Gestão.');
        $this->assertSame((int) $antesHeranca->faixa_ordem, $linhaHeranca['antes']['faixa_ordem']);
        $this->assertEqualsWithDelta((float) $antesHeranca->cobranca_mensal, $linhaHeranca['antes']['cobranca_mensal'], 0.01);

        // ── Mentoria: nunca teve tabela, cobra fixo ─────────────────────
        $linhaMentorada = $this->linhaEmpresaDoRelatorio($relatorio, $mentorada->id);
        $this->assertNotNull($linhaMentorada);
        $this->assertSame('sem_tabela', $linhaMentorada['antes']['regua']);
        $this->assertEqualsWithDelta((float) $antesMentorada->cobranca_mensal, $linhaMentorada['antes']['cobranca_mensal'], 0.01);

        // ── Grupo com tabela própria ─────────────────────────────────────
        $linhaGrupo = $this->linhaGrupoDoRelatorio($relatorio, $grupo->id);
        $this->assertNotNull($linhaGrupo);
        $this->assertEqualsWithDelta((float) $antesGrupo->faturamento_total, $linhaGrupo['antes']['faturamento_total'], 0.01);
        $this->assertSame((int) $antesGrupo->faixa_ordem, $linhaGrupo['antes']['faixa_ordem']);
        $this->assertEqualsWithDelta((float) $antesGrupo->cobranca_mensal, $linhaGrupo['antes']['cobranca_mensal'], 0.01);
    }

    #[Test]
    public function lado_depois_do_relatorio_bate_com_o_que_a_consolidacao_grava_com_a_flag_ligada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        ['baraoshop' => $baraoshop, 'heranca' => $heranca, 'mentorada' => $mentorada, 'grupo' => $grupo] = $this->montarCenario();

        // ── Roda o relatório (leitura pura) — computa os dois lados por
        //    conta própria via forcar(), independente do que estiver
        //    persistido em configuracoes.
        $relatorio = $this->relatorio('2026-08');

        // ── Liga a flag de verdade e consolida UMA VEZ (gabarito DEPOIS) ──
        $this->ligarFlag();
        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $depoisBaraoshop = DB::table('fechamento_snapshots')->where('company_id', $baraoshop->id)->first();
        $depoisHeranca   = DB::table('fechamento_snapshots')->where('company_id', $heranca->id)->first();
        $depoisMentorada = DB::table('fechamento_snapshots')->where('company_id', $mentorada->id)->first();
        $depoisGrupo     = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        // ── BARAOSHOP: só o valor da faixa da soma — NÃO pode ser fixado
        //    sem calcular (a soma das duas plataformas pode cair em
        //    qualquer faixa; aqui bate R$ 3.000 porque R$ 488.262,90 cai
        //    na faixa 1, não porque o teste assumiu esse valor) ─────────
        $linhaBaraoshop = $this->linhaEmpresaDoRelatorio($relatorio, $baraoshop->id);
        $this->assertNotNull($linhaBaraoshop);
        $this->assertEqualsWithDelta((float) $depoisBaraoshop->faturamento_total, $linhaBaraoshop['depois']['faturamento_total'], 0.01);
        $this->assertSame((int) $depoisBaraoshop->faixa_ordem, $linhaBaraoshop['depois']['faixa_ordem']);
        $this->assertEqualsWithDelta((float) $depoisBaraoshop->cobranca_mensal, $linhaBaraoshop['depois']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(3_000.00, (float) $depoisBaraoshop->cobranca_mensal, 0.01, 'DEPOIS: só o valor da faixa classificada sobre a soma R$ 488.262,90.');
        $this->assertNotEqualsCanonicalizing(5_500.00, (float) $depoisBaraoshop->cobranca_mensal);

        // ── Empresa que herdava a tabela do serviço: some da régua. Como
        //    tem contrato mensal ativo (Gestão, R$ 950), a consolidação
        //    grava ESTADO_VALOR_FIXO (não ESTADO_SEM_TABELA) — sem régua,
        //    mas com um valor definido a cobrar, nunca null. ─────────────
        $linhaHeranca = $this->linhaEmpresaDoRelatorio($relatorio, $heranca->id);
        $this->assertNotNull($linhaHeranca);
        $this->assertSame('sem_tabela', $linhaHeranca['depois']['regua'], 'DEPOIS: a tabela do serviço não é mais régua aplicável.');
        $this->assertSame(FechamentoSnapshot::ESTADO_VALOR_FIXO, $depoisHeranca->estado, 'sanity: com contrato mensal ativo e sem régua, a consolidação grava valor_fixo.');
        $this->assertNull($linhaHeranca['depois']['faixa_ordem']);
        $this->assertNull($depoisHeranca->faixa_ordem);
        $this->assertEqualsWithDelta((float) $depoisHeranca->cobranca_mensal, $linhaHeranca['depois']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(950.00, (float) $depoisHeranca->cobranca_mensal, 0.01);

        // ── A empresa que perdeu a régua aparece na contagem "sem régua" ──
        $nomesSemRegua = collect($relatorio['resumo']['sem_regua_depois']['nomes'])->pluck('nome')->all();
        $this->assertContains($heranca->name, $nomesSemRegua);
        $this->assertGreaterThanOrEqual(1, $relatorio['resumo']['sem_regua_depois']['quantidade']);

        // ── Mentoria: continua sem tabela, cobra fixo ───────────────────
        $linhaMentorada = $this->linhaEmpresaDoRelatorio($relatorio, $mentorada->id);
        $this->assertNotNull($linhaMentorada);
        $this->assertSame('sem_tabela', $linhaMentorada['depois']['regua']);
        $this->assertEqualsWithDelta((float) $depoisMentorada->cobranca_mensal, $linhaMentorada['depois']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(1_800.00, (float) $depoisMentorada->cobranca_mensal, 0.01);

        // ── Grupo com tabela própria: só a faixa da soma do grupo ───────
        $linhaGrupo = $this->linhaGrupoDoRelatorio($relatorio, $grupo->id);
        $this->assertNotNull($linhaGrupo);
        $this->assertEqualsWithDelta((float) $depoisGrupo->faturamento_total, $linhaGrupo['depois']['faturamento_total'], 0.01);
        $this->assertSame((int) $depoisGrupo->faixa_ordem, $linhaGrupo['depois']['faixa_ordem']);
        $this->assertEqualsWithDelta((float) $depoisGrupo->cobranca_mensal, $linhaGrupo['depois']['cobranca_mensal'], 0.01);
        $this->assertEqualsWithDelta(4_500.00, (float) $depoisGrupo->cobranca_mensal, 0.01, 'DEPOIS: só a faixa da soma do grupo (R$ 550.000) — os R$ 900 + R$ 700 dos contratos-membro não entram.');
    }

    // ─── Rodar o comando não escreve nada, mesmo isoladamente ───────────

    #[Test]
    public function rodar_o_comando_nao_muda_nenhuma_linha_de_configuracao_faixa_ou_snapshot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao  = $this->criarServicoGestaoComTabela();
        $company = Company::factory()->create(['adman_account_id' => 'cust-isolado']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $tabelas = ['configuracoes', 'empresa_faixas_faturamento', 'grupo_faixas_faturamento', 'servico_faixas_faturamento', 'fechamento_snapshots', 'fechamento_grupo_snapshots'];
        $contagensAntes = collect($tabelas)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $this->relatorio('2026-08');
        // --todas e sem --json também não podem escrever nada.
        Artisan::call('fechamento:comparar-mensalidade', ['--mes' => '2026-08', '--todas' => true]);

        foreach ($tabelas as $tabela) {
            $this->assertSame($contagensAntes[$tabela], DB::table($tabela)->count(), "comparar-mensalidade não pode alterar {$tabela}.");
        }
    }

    // ─── A flag persistida nunca é lida como alterada pelo comando ──────

    #[Test]
    public function a_flag_persistida_continua_desligada_depois_do_relatorio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao  = $this->criarServicoGestaoComTabela();
        $company = Company::factory()->create(['adman_account_id' => 'cust-flag']);
        ContratoServico::factory()->paraServico($gestao)->create(['company_id' => $company->id, 'ativo' => true]);
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->relatorio('2026-08');

        // Uma instância NOVA do leitor (o comando usou a sua própria,
        // resolvida pelo container) precisa ler o valor real persistido —
        // '0' (desligado), porque nunca gravamos nada.
        $leitorNovo = app(FechamentoRegraTabela::class);
        $this->assertFalse($leitorNovo->ativa(), 'A flag persistida não pode ter sido alterada por forcar().');
        $this->assertSame('0', Configuracao::get(FechamentoRegraTabela::CHAVE, '0'));
    }
}
