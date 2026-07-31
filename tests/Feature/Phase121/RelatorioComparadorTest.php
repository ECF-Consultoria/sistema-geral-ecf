<?php

namespace Tests\Feature\Phase121;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoComparadorEmpresa;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricDiffDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 121, Plano 04 — fase de APRESENTAÇÃO do comando
 * `desempenho:comparar-score-empresa` (D-04: o comando informa, quem decide
 * é o usuário). Cobre o cabeçalho de rastreabilidade (T-121-30), a tabela
 * por profissional, a seção "delta zero mas comportamento mudou", o modo
 * `--run=` (reimpressão pura, sem tocar Adman), as 7 amostras de risco
 * (ROLL-02, gate `121-VALIDATION.md`) e o histograma deduplicado de
 * `margem_var_pp` (ROLL-03, gate nº 3).
 *
 * Estratégia de fixture: as linhas são gravadas DIRETO nas duas tabelas
 * insert-only (mesmo padrão de `ComparadorTabelasTest`) — o alvo aqui é a
 * fase de apresentação (reconsulta ao banco), não a coleta em si (já
 * coberta por `CompararScoreEmpresaCommandTest`/`DecomposicaoDeltaTest`). O
 * comando é sempre exercitado via `--run=`, o que já prova
 * reconsultabilidade pura em toda a suíte.
 */
class RelatorioComparadorTest extends TestCase
{
    use RefreshDatabase;

    private string $runId;
    private Carbon $geradoEm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runId    = (string) Str::uuid();
        $this->geradoEm = Carbon::parse('2026-07-31 10:15:00');
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function criarUser(string $nome): User
    {
        return User::factory()->create(['name' => $nome, 'active' => true]);
    }

    private function criarProfissional(User $user, array $overrides = []): DesempenhoComparadorProfissional
    {
        return DesempenhoComparadorProfissional::create(array_merge([
            'run_id'               => $this->runId,
            'user_id'              => $user->id,
            'periodo_key'          => '2026-07',
            'competencia_alvo'     => true,
            'gerado_em'            => $this->geradoEm,
            'nota_antiga'          => 3.00,
            'nota_nova'            => 3.00,
            'delta'                => 0.00,
            'status_antigo'        => 'official',
            'status_novo'          => 'official',
            'faixa_antiga_oficial' => null,
            'faixa_antiga_inicial' => 'intermediario',
            'faixa_nova_inicial'   => 'intermediario',
            'mudou_faixa'          => false,
            'empresas_total'       => 1,
            'empresas_complete'    => 1,
            'empresas_partial'     => 0,
            'empresas_sem_fonte'   => 0,
            'empresas_sem_dados'   => 0,
            'decomposicao'         => null,
            'maior_causa_delta'    => null,
            'falhou'               => false,
            'erro'                 => null,
        ], $overrides));
    }

    private function criarEmpresaLinha(User $user, Company $company, array $overrides = []): DesempenhoComparadorEmpresa
    {
        return DesempenhoComparadorEmpresa::create(array_merge([
            'run_id'               => $this->runId,
            'user_id'              => $user->id,
            'company_id'           => $company->id,
            'periodo_key'          => '2026-07',
            'competencia_alvo'     => true,
            'gerado_em'            => $this->geradoEm,
            'fonte_financeira'     => 'adman',
            'status'               => 'complete',
            'nps_pontos'           => 4.0,
            'faturamento_var_pct'  => 0.0,
            'faturamento_pontos'   => 3.0,
            'margem_var_pp'        => 0.0,
            'margem_diff_pct'      => null,
            'margem_pontos'        => 3.0,
            'nota_empresa'         => 3.33,
            'nota_empresa_parcial' => 3.33,
            'quality_motivos'      => [],
        ], $overrides));
    }

    private function rodarERetornarOutput(array $params): string
    {
        $exitCode = Artisan::call('desempenho:comparar-score-empresa', $params);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode, $output);

        return $output;
    }

    // ═══ Task 1 — cabeçalho, tabela, --run=, aviso ═══════════════════════

    #[Test]
    public function test_cabecalho_traz_run_id_e_gerado_em(): void
    {
        $user = $this->criarUser('Profissional Cabecalho');
        $this->criarProfissional($user);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString($this->runId, $output);
        $this->assertStringContainsString($this->geradoEm->format('d/m/Y H:i:s'), $output);
        $this->assertStringContainsString('Competência alvo: 2026-07', $output);
    }

    #[Test]
    public function test_tabela_lista_profissionais_persistidos_com_valores_do_banco(): void
    {
        $user = $this->criarUser('Fulano Delta Negativo');
        $this->criarProfissional($user, [
            'nota_antiga' => 4.00,
            'nota_nova'   => 3.20,
            'delta'       => -0.80,
        ]);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('Fulano Delta Negativo', $output);
        $this->assertStringContainsString('4.00', $output);
        $this->assertStringContainsString('3.20', $output);
        $this->assertStringContainsString('-0.80', $output);
    }

    #[Test]
    public function test_run_reimprime_sem_chamar_compute_nem_dispatcher(): void
    {
        $user = $this->criarUser('Reimpressao Sem Custo');
        $this->criarProfissional($user);

        $this->app->forgetInstance(DesempenhoScoreService::class);
        $realScoreService = $this->app->make(DesempenhoScoreService::class);
        $spyScoreService   = Mockery::mock($realScoreService)->makePartial();
        $spyScoreService->shouldNotReceive('compute');
        $this->app->instance(DesempenhoScoreService::class, $spyScoreService);

        $dispatcherMock = Mockery::mock(MetricDiffDispatcher::class);
        $dispatcherMock->shouldNotReceive('compute');
        $this->app->instance(MetricDiffDispatcher::class, $dispatcherMock);

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', ['--run' => $this->runId]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    #[Test]
    public function test_delta_zero_mas_status_mudou_aparece_na_secao(): void
    {
        $user = $this->criarUser('Misto Status Mudou');
        $this->criarProfissional($user, [
            'nota_antiga'   => 2.33,
            'nota_nova'     => 2.33,
            'delta'         => 0.00,
            'status_antigo' => 'official',
            'status_novo'   => 'partial',
        ]);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('Delta zero mas comportamento mudou', $output);
        $this->assertStringContainsString('Misto Status Mudou', $output);
        $this->assertStringContainsString('official → partial', $output);
    }

    #[Test]
    public function test_aviso_final_diz_que_nao_aprova_nem_reprova(): void
    {
        $user = $this->criarUser('Qualquer Profissional');
        $this->criarProfissional($user);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('NÃO APROVA NEM REPROVA', $output);
        $this->assertStringContainsString('ESPELHO', $output);
        $this->assertStringContainsString($this->runId, $output);
    }

    // ═══ Task 2 — as 7 amostras de risco (ROLL-02) ═══════════════════════

    #[Test]
    public function test_sete_amostras_de_risco_aparecem_com_o_candidato_certo(): void
    {
        $userPoucas = $this->criarUser('Poucas Empresas');
        $userMuitas = $this->criarUser('Muitas Empresas');
        $userShopee = $this->criarUser('Com Shopee');

        $this->criarProfissional($userPoucas, ['empresas_total' => 1]);
        $this->criarProfissional($userMuitas, ['empresas_total' => 5]);
        $this->criarProfissional($userShopee, ['empresas_total' => 2]);

        $companyQuedaSevera = Company::factory()->create(['name' => 'Empresa Queda Severa']);
        $companyNormal      = Company::factory()->create(['name' => 'Empresa Normal']);
        $companyMargemPos   = Company::factory()->create(['name' => 'Empresa Margem Positiva']);
        $companySemBaseline = Company::factory()->create(['name' => 'Empresa Sem Baseline']);
        $companyShopee      = Company::factory()->create(['name' => 'Empresa Shopee']);
        $companyInvalidada  = Company::factory()->create(['name' => 'Empresa Invalidada Ausente']);

        $this->criarEmpresaLinha($userPoucas, $companyQuedaSevera, [
            'faturamento_var_pct' => -20.0,
            'faturamento_pontos'  => 1.0,
        ]);
        $this->criarEmpresaLinha($userPoucas, $companyNormal, [
            'faturamento_var_pct' => -2.0,
            'faturamento_pontos'  => 2.0,
        ]);
        $this->criarEmpresaLinha($userMuitas, $companyMargemPos, [
            'margem_var_pp' => 3.5,
        ]);
        $this->criarEmpresaLinha($userMuitas, $companySemBaseline, [
            'faturamento_pontos'  => null,
            'faturamento_var_pct' => null,
            'quality_motivos'     => ['faturamento_sem_baseline'],
        ]);
        $this->criarEmpresaLinha($userShopee, $companyShopee, [
            'fonte_financeira' => 'shopee',
            'margem_var_pp'    => null,
            'margem_pontos'    => 1.0,
        ]);

        // Prova de ausência (bloco 6): empresa invalidada na competência
        // alvo, mas DELIBERADAMENTE ausente das linhas persistidas acima.
        BonusInvalidacao::create([
            'company_id'  => $companyInvalidada->id,
            'competencia' => Carbon::parse('2026-07-01'),
            'motivo'      => 'teste gate 121-04',
        ]);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('1. Profissional com poucas empresas: Poucas Empresas', $output);
        $this->assertStringContainsString('2. Profissional com muitas empresas: Muitas Empresas', $output);
        $this->assertStringContainsString('3. Empresa com queda grande de faturamento: Empresa Queda Severa', $output);
        $this->assertStringContainsString('queda severa (nota 1 na régua de faturamento): 1.', $output);
        $this->assertStringContainsString('4. Empresa com pp positivo: Empresa Margem Positiva', $output);
        $this->assertStringContainsString('5. Empresa sem baseline: Empresa Sem Baseline', $output);
        $this->assertStringContainsString('1 empresa(s) invalidada(s) na competência 2026-07', $output);
        $this->assertStringContainsString('VEREDITO: ausência confirmada', $output);
        $this->assertStringContainsString('7. Profissional com Shopee: Com Shopee', $output);
        $this->assertStringContainsString('Referência de sanidade', $output);
    }

    #[Test]
    public function test_amostra_sem_candidato_nao_desaparece_da_saida(): void
    {
        $user = $this->criarUser('Unico Profissional');
        $this->criarProfissional($user, ['empresas_total' => 1]);

        $company = Company::factory()->create();
        $this->criarEmpresaLinha($user, $company, [
            'margem_var_pp'      => -1.0, // nunca positivo -> bloco 4 vazio
            'faturamento_pontos' => 3.0,  // não-nulo -> bloco 5 vazio
            'quality_motivos'    => [],
        ]);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('4. Empresa com pp positivo: nenhum candidato nesta competência.', $output);
        $this->assertStringContainsString('5. Empresa sem baseline: nenhum candidato nesta competência.', $output);
        $this->assertStringContainsString('7. Profissional com Shopee: nenhum candidato nesta competência.', $output);
    }

    // ═══ Task 3 — gate nº 3: histograma deduplicado, 3 competências ══════

    #[Test]
    public function test_gate_3_histograma_dedupe_escopo_tres_competencias_e_soma_sanidade(): void
    {
        $userX = $this->criarUser('Carteira X');
        $userY = $this->criarUser('Carteira Y');

        $this->criarProfissional($userX, ['periodo_key' => '2026-07', 'competencia_alvo' => true]);
        $this->criarProfissional($userY, ['periodo_key' => '2026-07', 'competencia_alvo' => true]);
        $this->criarProfissional($userX, ['periodo_key' => '2026-06', 'competencia_alvo' => false]);
        $this->criarProfissional($userX, ['periodo_key' => '2026-05', 'competencia_alvo' => false]);

        $companhiaCompartilhada = Company::factory()->create(['name' => 'Empresa Compartilhada']);
        $companhiaSemFonte      = Company::factory()->create(['name' => 'Empresa Sem Fonte']);
        $companhiaSolo06        = Company::factory()->create(['name' => 'Empresa Junho']);
        $companhiaSolo05        = Company::factory()->create(['name' => 'Empresa Maio']);

        // Competência alvo (2026-07): a MESMA empresa em duas carteiras (X e
        // Y) — dedupe por company_id tem que contar UMA vez só.
        $this->criarEmpresaLinha($userX, $companhiaCompartilhada, ['periodo_key' => '2026-07', 'margem_var_pp' => 2.0, 'fonte_financeira' => 'adman']);
        $this->criarEmpresaLinha($userY, $companhiaCompartilhada, ['periodo_key' => '2026-07', 'margem_var_pp' => 2.0, 'fonte_financeira' => 'adman']);
        // Empresa sem fonte financeira — fica FORA do escopo do histograma.
        $this->criarEmpresaLinha($userX, $companhiaSemFonte, ['periodo_key' => '2026-07', 'margem_var_pp' => -3.0, 'fonte_financeira' => null]);

        // Competências históricas — uma empresa cada, distribuições distintas.
        $this->criarEmpresaLinha($userX, $companhiaSolo06, ['periodo_key' => '2026-06', 'competencia_alvo' => false, 'margem_var_pp' => -6.0, 'fonte_financeira' => 'adman']);
        $this->criarEmpresaLinha($userX, $companhiaSolo05, ['periodo_key' => '2026-05', 'competencia_alvo' => false, 'margem_var_pp' => 5.0, 'fonte_financeira' => 'adman']);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('Competência 2026-07 — 1 empresa(s) distinta(s) elegível(is):', $output);
        $this->assertStringContainsString('Competência 2026-06 — 1 empresa(s) distinta(s) elegível(is):', $output);
        $this->assertStringContainsString('Competência 2026-05 — 1 empresa(s) distinta(s) elegível(is):', $output);
        $this->assertStringNotContainsString('ANOMALIA de sanidade', $output);
        $this->assertStringContainsString('Percentual consolidado nas notas 3+4', $output);
    }

    #[Test]
    public function test_menos_de_tres_competencias_avisa_leitura_prejudicada(): void
    {
        $user = $this->criarUser('Competencia Unica');
        $this->criarProfissional($user, ['periodo_key' => '2026-07', 'competencia_alvo' => true]);

        $company = Company::factory()->create();
        $this->criarEmpresaLinha($user, $company, ['periodo_key' => '2026-07', 'margem_var_pp' => 1.0, 'fonte_financeira' => 'adman']);

        $output = $this->rodarERetornarOutput(['--run' => $this->runId]);

        $this->assertStringContainsString('menos de três competências coletadas', $output);
    }
}
