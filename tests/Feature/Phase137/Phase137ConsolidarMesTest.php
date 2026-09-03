<?php

namespace Tests\Feature\Phase137;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoReconsolidacao;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ServicoFaixaFaturamento;
use App\Services\Fechamento\FechamentoSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 137 Plano 05 — Tarefas 1 e 2: FechamentoSnapshotWriter e o comando
 * `fechamento:consolidar-mes`.
 *
 * Toda asserção de resultado é por RECONSULTA ao banco (`DB::table`/model
 * fresh query) — nunca por `expectsOutput` (disciplina registrada em
 * `.planning/learnings/desempenho-bonificacao.md` §4).
 */
class Phase137ConsolidarMesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── Helpers de fixture (Tarefa 2 — comando) ────────────────────────

    /**
     * A migration `2026_09_02_100003_seed_faixas_faturamento_iniciais` roda
     * dentro do RefreshDatabase e já semeia as 7 faixas de "Gestão" — este
     * helper só ajusta `plataforma`/`setor` (mesmo padrão de
     * Phase137FaixaResolverTest).
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

    /** Serviço candidato sem NENHUMA linha em servico_faixas_faturamento — estado "A DEFINIR". */
    private function criarServicoSemFaixas(): Servico
    {
        return Servico::create([
            'nome'          => 'Serviço Sem Tabela '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
            'plataforma'    => 'Mercado Livre',
        ]);
    }

    private function criarServicoShopeeComFaixas(): Servico
    {
        $servico = Servico::create([
            'nome'          => 'Gestão de ADS Shopee '.uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_SHOPEE,
            'plataforma'    => 'Shopee',
        ]);

        ServicoFaixaFaturamento::create([
            'servico_id'      => $servico->id,
            'ordem'           => 1,
            'limite_superior' => 3_000_000.00,
            'valor'           => 1_500.00,
            'valor_e_piso'    => false,
        ]);

        return $servico;
    }

    /** Empresa com integração Adman (cust_id) e contrato ativo do serviço informado. */
    private function criarEmpresaComContrato(Servico $servico, array $overrides = []): Company
    {
        $company = Company::factory()->create(array_merge([
            'adman_account_id' => 'cust-'.uniqid(),
        ], $overrides));

        ContratoServico::factory()->paraServico($servico)->create([
            'company_id' => $company->id,
            'ativo'      => true,
        ]);

        return $company;
    }

    // ─── Helpers de linha (Tarefa 1 — writer puro) ──────────────────────

    private function linhaEmpresa(int $companyId, array $overrides = []): array
    {
        return array_merge([
            'company_id'        => $companyId,
            'company_name'      => 'Empresa '.$companyId,
            'faturamento_total' => 500_000.00,
            'estado'            => FechamentoSnapshot::ESTADO_OK,
        ], $overrides);
    }

    // ─── Tarefa 1 — FechamentoSnapshotWriter ────────────────────────────

    #[Test]
    public function primeira_gravacao_de_competencia_inedita_grava_e_nao_reconsolida(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($company->id)],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );

        $this->assertFalse($resultado['reconsolidado']);
        $this->assertSame(1, $resultado['empresas_upserted']);
        $this->assertSame(0, FechamentoReconsolidacao::count());

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertNotNull($gravado);
        $this->assertEqualsWithDelta(500_000.00, (float) $gravado->faturamento_total, 0.001);
    }

    #[Test]
    public function segunda_gravacao_sem_motivo_lanca_excecao_e_nao_altera_o_valor_gravado(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 500_000.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->expectException(\RuntimeException::class);

        try {
            $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 999_999.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        } finally {
            $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
            $this->assertEqualsWithDelta(500_000.00, (float) $gravado->faturamento_total, 0.001, 'Sem motivo, o valor da primeira rodada tem que permanecer intocado.');
        }
    }

    #[Test]
    public function segunda_gravacao_com_motivo_preserva_o_valor_anterior_na_auditoria(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id, ['faturamento_total' => 500_000.00])], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($company->id, ['faturamento_total' => 999_999.00])],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            reconsolidadoPor: null,
            motivo: 'Adman corrigiu o faturamento depois do fechamento.',
        );

        $this->assertTrue($resultado['reconsolidado']);
        $this->assertSame(1, FechamentoReconsolidacao::count());

        $auditoria = FechamentoReconsolidacao::first();
        $this->assertSame('Adman corrigiu o faturamento depois do fechamento.', $auditoria->motivo);
        $this->assertEqualsWithDelta(
            500_000.00,
            (float) $auditoria->snapshot_anterior['empresas'][0]['faturamento_total'],
            0.001,
            'snapshot_anterior precisa preservar o valor ANTES da sobrescrita.'
        );

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();
        $this->assertEqualsWithDelta(999_999.00, (float) $gravado->faturamento_total, 0.001, 'Com motivo, a sobrescrita acontece.');
    }

    #[Test]
    public function empresa_removida_do_conjunto_e_podada_na_rodada_seguinte(): void
    {
        $empresaFica = Company::factory()->create();
        $empresaSai  = Company::factory()->create();
        $mes         = Carbon::parse('2026-08-01');
        $writer      = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [
            $this->linhaEmpresa($empresaFica->id),
            $this->linhaEmpresa($empresaSai->id),
        ], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertSame(2, DB::table('fechamento_snapshots')->count());

        $resultado = $writer->sync(
            $mes,
            [$this->linhaEmpresa($empresaFica->id)],
            [],
            FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
            motivo: 'Empresa saiu da carteira.',
        );

        $this->assertSame(1, $resultado['empresas_pruned']);
        $this->assertSame(1, DB::table('fechamento_snapshots')->count());
        $this->assertNull(DB::table('fechamento_snapshots')->where('company_id', $empresaSai->id)->first());
        $this->assertNotNull(DB::table('fechamento_snapshots')->where('company_id', $empresaFica->id)->first());
    }

    #[Test]
    public function rerun_com_mesmo_conjunto_nao_duplica_linha(): void
    {
        $company = Company::factory()->create();
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $writer->sync($mes, [$this->linhaEmpresa($company->id)], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);
        $writer->sync($mes, [$this->linhaEmpresa($company->id)], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES, motivo: 'rerun idêntico');

        $this->assertSame(1, DB::table('fechamento_snapshots')->where('company_id', $company->id)->count());
    }

    #[Test]
    public function writer_grava_e_poda_linhas_de_grupo(): void
    {
        $grupo   = CompanyGroup::create(['name' => 'Grupo Teste', 'color' => '#000']);
        $mes     = Carbon::parse('2026-08-01');
        $writer  = app(FechamentoSnapshotWriter::class);

        $linhaGrupo = [
            'company_group_id'  => $grupo->id,
            'grupo_name'        => $grupo->name,
            'faturamento_total' => 800_000.00,
            'estado'            => 'ok',
            'empresas_count'    => 2,
        ];

        $resultado = $writer->sync($mes, [], [$linhaGrupo], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES);

        $this->assertSame(1, $resultado['grupos_upserted']);
        $gravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();
        $this->assertNotNull($gravado);
        $this->assertEqualsWithDelta(800_000.00, (float) $gravado->faturamento_total, 0.001);

        // Segunda rodada com motivo e SEM o grupo — precisa podar.
        $resultado2 = $writer->sync($mes, [], [], FechamentoSnapshotWriter::ORIGEM_CONSOLIDAR_MES, motivo: 'grupo desfeito');
        $this->assertSame(1, $resultado2['grupos_pruned']);
        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count());
    }

    // ─── Tarefa 2 — comando fechamento:consolidar-mes ───────────────────

    #[Test]
    public function fecha_competencia_com_grupo_de_2_empresas_e_2_soltas_grava_4_linhas_empresa_1_grupo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $soltaA  = $this->criarEmpresaComContrato($gestao);
        $soltaB  = $this->criarEmpresaComContrato($gestao);

        foreach ([$membroA, $membroB, $soltaA, $soltaB] as $c) {
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        }

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->assertSame(4, DB::table('fechamento_snapshots')->whereDate('mes_referencia', '2026-08-01')->count());
        $this->assertSame(1, DB::table('fechamento_grupo_snapshots')->whereDate('mes_referencia', '2026-08-01')->count());
    }

    #[Test]
    public function faturamento_do_grupo_e_a_soma_exata_das_empresas_membro_e_faixa_e_da_soma(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();
        $grupo  = CompanyGroup::create(['name' => 'Lyam', 'color' => '#000']);

        $membroA = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroB = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroA->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroB->id, 'reference_date' => '2026-08-10', 'revenue' => 250_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $somaEmpresas = (float) DB::table('fechamento_snapshots')
            ->whereIn('company_id', [$membroA->id, $membroB->id])
            ->sum('faturamento_total');

        $grupoGravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        $this->assertEqualsWithDelta(550_000.00, $somaEmpresas, 0.01);
        $this->assertEqualsWithDelta($somaEmpresas, (float) $grupoGravado->faturamento_total, 0.01);
        $this->assertSame('faixa_2', $grupoGravado->faixa_aplicada, 'A faixa do grupo é a classificação da SOMA (D-10) — 550.000 cai na faixa 2, não na faixa 1 de cada empresa isolada.');
    }

    #[Test]
    public function empresa_com_parent_company_id_sem_grupo_no_comercial_grava_como_linha_solta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();

        $pai   = $this->criarEmpresaComContrato($gestao);
        $filha = $this->criarEmpresaComContrato($gestao, ['parent_company_id' => $pai->id]);

        AdmanMetric::create(['company_id' => $pai->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $filha->id, 'reference_date' => '2026-08-10', 'revenue' => 200_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->assertSame(0, DB::table('fechamento_grupo_snapshots')->count(), 'parent_company_id não agrega nada (D-08/D-09) — nenhum grupo deve nascer daqui.');

        $paiGravado   = DB::table('fechamento_snapshots')->where('company_id', $pai->id)->first();
        $filhaGravada = DB::table('fechamento_snapshots')->where('company_id', $filha->id)->first();

        $this->assertEqualsWithDelta(300_000.00, (float) $paiGravado->faturamento_total, 0.01, 'Faturamento da mãe não pode incluir o da filha.');
        $this->assertEqualsWithDelta(200_000.00, (float) $filhaGravada->faturamento_total, 0.01);
    }

    #[Test]
    public function empresa_sem_tabela_de_faixas_resolvida_grava_estado_sem_tabela_sem_valor_zero(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $servicoSemFaixas = $this->criarServicoSemFaixas();
        $company          = $this->criarEmpresaComContrato($servicoSemFaixas);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $company->id)->first();

        $this->assertSame('sem_tabela', $gravado->estado);
        $this->assertNull($gravado->faixa_aplicada);
        $this->assertNull($gravado->valor_faixa, 'Nunca R$ 0 — ausência de tabela precisa ficar visível como null.');
    }

    #[Test]
    public function empresa_com_integracao_e_sem_metrica_no_mes_grava_sem_faturamento(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();

        // 4 empresas com faturamento (numerador) + 1 sem (denominador=5,
        // cobertura=0.8 — acima do mínimo, então o gate não recusa o lote).
        $comFaturamento = [];
        for ($i = 0; $i < 4; $i++) {
            $c = $this->criarEmpresaComContrato($gestao);
            AdmanMetric::create(['company_id' => $c->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
            $comFaturamento[] = $c;
        }

        $semMetrica = $this->criarEmpresaComContrato($gestao);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $gravado = DB::table('fechamento_snapshots')->where('company_id', $semMetrica->id)->first();

        $this->assertSame('sem_faturamento', $gravado->estado);
        $this->assertNull($gravado->faturamento_total);
    }

    #[Test]
    public function grupo_com_empresas_de_tabelas_diferentes_grava_tabelas_divergentes_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();
        $shopee = $this->criarServicoShopeeComFaixas();
        $grupo  = CompanyGroup::create(['name' => 'Grupo Misto', 'color' => '#000']);

        $membroGestao = $this->criarEmpresaComContrato($gestao, ['company_group_id' => $grupo->id]);
        $membroShopee = $this->criarEmpresaComContrato($shopee, ['company_group_id' => $grupo->id]);

        AdmanMetric::create(['company_id' => $membroGestao->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $membroShopee->id, 'reference_date' => '2026-08-10', 'revenue' => 100_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $grupoGravado = DB::table('fechamento_grupo_snapshots')->where('company_group_id', $grupo->id)->first();

        $this->assertNotNull($grupoGravado);
        $this->assertTrue((bool) $grupoGravado->tabelas_divergentes);
    }

    #[Test]
    public function evolucao_compara_faixa_atual_com_a_do_mes_anterior_pela_mesma_tabela(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();

        $subiu   = $this->criarEmpresaComContrato($gestao);
        $manteve = $this->criarEmpresaComContrato($gestao);
        $semBase = $this->criarEmpresaComContrato($gestao);

        // "subiu": julho 300k (faixa 1), agosto 600k (faixa 2).
        AdmanMetric::create(['company_id' => $subiu->id, 'reference_date' => '2026-07-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $subiu->id, 'reference_date' => '2026-08-10', 'revenue' => 600_000.00]);

        // "manteve": mesma faixa nos dois meses.
        AdmanMetric::create(['company_id' => $manteve->id, 'reference_date' => '2026-07-10', 'revenue' => 300_000.00]);
        AdmanMetric::create(['company_id' => $manteve->id, 'reference_date' => '2026-08-10', 'revenue' => 350_000.00]);

        // "sem base": nenhuma métrica em julho.
        AdmanMetric::create(['company_id' => $semBase->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $this->assertSame('subiu', DB::table('fechamento_snapshots')->where('company_id', $subiu->id)->value('evolucao'));
        $this->assertSame('manteve', DB::table('fechamento_snapshots')->where('company_id', $manteve->id)->value('evolucao'));
        $this->assertNull(DB::table('fechamento_snapshots')->where('company_id', $semBase->id)->value('evolucao'));
    }

    #[Test]
    public function cobertura_de_faturamento_abaixo_do_minimo_nao_grava_nada_e_retorna_exit_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao = $this->criarServicoGestao();

        // 1 com faturamento, 2 sem — cobertura 1/3 = 0,33, abaixo de 0,7.
        $comFaturamento = $this->criarEmpresaComContrato($gestao);
        AdmanMetric::create(['company_id' => $comFaturamento->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);
        $this->criarEmpresaComContrato($gestao);
        $this->criarEmpresaComContrato($gestao);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(1);

        $this->assertSame(0, DB::table('fechamento_snapshots')->count(), 'Cobertura degradada não pode gravar NADA — nem as linhas boas.');
    }

    #[Test]
    public function rodar_duas_vezes_sem_motivo_recusa_e_com_motivo_reconsolida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $valorOriginal = (float) DB::table('fechamento_snapshots')->where('company_id', $company->id)->value('faturamento_total');
        $this->assertEqualsWithDelta(300_000.00, $valorOriginal, 0.01);

        // Faturamento "corrigido" pela Adman depois do fechamento.
        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-20', 'revenue' => 100_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(1);

        $valorAposRecusa = (float) DB::table('fechamento_snapshots')->where('company_id', $company->id)->value('faturamento_total');
        $this->assertEqualsWithDelta($valorOriginal, $valorAposRecusa, 0.01, 'Sem --motivo=, a competência fechada não pode mudar.');
        $this->assertSame(0, DB::table('fechamento_reconsolidacoes')->count());

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08', '--motivo' => 'Adman corrigiu faturamento após o fechamento.'])->assertExitCode(0);

        $valorAposReconsolidacao = (float) DB::table('fechamento_snapshots')->where('company_id', $company->id)->value('faturamento_total');
        $this->assertEqualsWithDelta(400_000.00, $valorAposReconsolidacao, 0.01);
        $this->assertSame(1, DB::table('fechamento_reconsolidacoes')->count());
    }

    #[Test]
    public function se_ausente_numa_competencia_ja_consolidada_retorna_sucesso_sem_escrever(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02'));

        $gestao  = $this->criarServicoGestao();
        $company = $this->criarEmpresaComContrato($gestao);

        AdmanMetric::create(['company_id' => $company->id, 'reference_date' => '2026-08-10', 'revenue' => 300_000.00]);

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08'])->assertExitCode(0);

        $antesCount = DB::table('fechamento_snapshots')->count();
        $antesValor = (float) DB::table('fechamento_snapshots')->where('company_id', $company->id)->value('faturamento_total');

        $this->artisan('fechamento:consolidar-mes', ['--mes' => '2026-08', '--se-ausente' => true])->assertExitCode(0);

        $this->assertSame($antesCount, DB::table('fechamento_snapshots')->count());
        $this->assertEqualsWithDelta($antesValor, (float) DB::table('fechamento_snapshots')->where('company_id', $company->id)->value('faturamento_total'), 0.01);
    }
}
