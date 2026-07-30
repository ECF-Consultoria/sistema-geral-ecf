<?php

namespace Tests\Feature\Phase116;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsImputedAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Phase74\DesempenhoScoreServiceTestProviderStub;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plano 06 (RED) — suíte de Console do comando
 * `nps:materializar-nao-respondidos`.
 *
 * Cobre: relatório antes/depois (D1), plano de reconsolidação, reconsolidação
 * VERIFICADA do snapshot mensal do bônus (reuso de `desempenho:consolidar-mes`,
 * NUNCA reimplementação do `updateOrCreate`), conferência nominal quando o
 * gate de margem (FIXMARG-03) recusa o congelamento, `--mes`, `--dry-run`,
 * `--force`, confirmação interativa, cache-bust e `--desfazer` (rollback).
 *
 * Molde de fixture herdado de `tests/Feature/Phase74/ConsolidarMesDesempenhoCommandTest.php`
 * (única suíte que faz `desempenho:consolidar-mes` gravar snapshot em SQLite)
 * e de `tests/Feature/Phase110/ConsolidarMesMargemResilienteTest.php` (molde
 * exato de como forçar `cobertura < 0.7`), mais `NpsImputacaoServiceTest.php`
 * (molde de template escopado por dimensão/serviço).
 *
 * @see .planning/phases/116-.../116-06-PLAN.md
 */
class NpsMaterializarNaoRespondidosCommandTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private DesempenhoScoreServiceTestProviderStub $providerStub;
    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela em 2026-08-01 14:05 BRT — mesmo instante do cron mensal
        // (batendo o padrão de ConsolidarMesDesempenhoCommandTest/Phase110).
        // Mês anterior = julho/2026 (fechado); o NPS da competência julho é
        // lido de agosto (M+1, régua NPSWIN-03).
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        require_once __DIR__ . '/../Phase74/DesempenhoScoreServiceTest.php';
        $this->providerStub = new DesempenhoScoreServiceTestProviderStub();
        $this->app->instance(MetricsProviderFactory::class, $this->providerStub);

        // O comando reconsolida via `desempenho:consolidar-mes`, que chama
        // compute() puro (delega margem/faturamento ao AdmanMetricDiffService,
        // HTTP quando a empresa tem custId). Defesa em profundidade — nenhum
        // request real é esperado nesta suíte.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-116-06',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers de fixture ═══════════════════════════════════════════════

    private function criarUserAnalista(string $nome): User
    {
        $user = User::factory()->create([
            'name'   => $nome,
            'role'   => 'consultor',
            'active' => true,
        ]);
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture 116-06)',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $this->servicoPerfId;
    }

    /** Empresa com contrato ativo + pivot company_users, SEM dado financeiro. */
    private function criarEmpresaSemMargem(User $user, int $servicoPerf): Company
    {
        $ts = Carbon::parse('-3 months')->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        return $company->fresh();
    }

    /** Popula AdmanMetric denso (current/baseline) via MetricPeriodResolver. */
    private function preencherFinanceiro(Company $c, string $mesYm): void
    {
        $c->timestamps = false;
        $c->forceFill(['adman_account_id' => 'CUST-116-06-' . $c->id, 'marketplace' => 'meli'])->save();
        $c->timestamps = true;

        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesYm]);

        $this->semearDiario($c, $periodo['current_start'], $periodo['current_end'], 10300, 2100);
        $this->semearDiario($c, $periodo['baseline_start'], $periodo['baseline_end'], 10000, 2000);
    }

    private function semearDiario(Company $c, string $inicio, string $fim, float $revenue, float $margem): void
    {
        $cursor  = Carbon::parse($inicio);
        $fimData = Carbon::parse($fim);
        while ($cursor->lte($fimData)) {
            AdmanMetric::create([
                'company_id'          => $c->id,
                'reference_date'      => $cursor->toDateString(),
                'revenue'             => $revenue,
                'contribution_margin' => $margem,
            ]);
            $cursor->addDay();
        }
    }

    /** Empresa com contrato + margem REAL (financeiro denso). */
    private function criarEmpresaComMargemReal(User $user, int $servicoPerf, string $mesYm): Company
    {
        $company = $this->criarEmpresaSemMargem($user, $servicoPerf);
        $this->preencherFinanceiro($company, $mesYm);

        return $company->fresh();
    }

    // ─── Fixture SHOPEE — usada nos cenários "saudáveis" desta suíte ───────
    //
    // Deliberadamente EVITA o setor Performance/Adman: a margem de
    // contribuição via `AdmanMetricDiffService` é instável neste working tree
    // (baseline herdada — ver `deferred-items.md` e a memória do projeto
    // `project_adman_margem_diff_instavel_bonus`, aberta 2026-07-23, "NÃO é
    // regressão da Fase 109"). `ShopeeMetricDiffService` é 100% local/
    // determinístico (sem HTTP, sem cache instável) e a dimensão margem de
    // empresa Shopee é sempre o placeholder fixo 1.0 (Fase 109 SHOP-DES-02) —
    // nunca aciona o gate FIXMARG-03 (`n_elegivel=0` para fonte Shopee). Só o
    // teste de gate de margem degradada (que PRECISA do gate) usa o molde
    // Adman/Performance herdado do Phase110.

    private function servicoShopeeId(): int
    {
        return $this->criarServico(Servico::SETOR_SHOPEE, true);
    }

    private function criarEmpresaShopee(User $user, int $servicoShopee): Company
    {
        $ts = Carbon::parse('-3 months')->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoShopee);

        return $company->fresh();
    }

    private function seedShopeeRevenue(Company $c, string $mesYm, float $revenueAtual, float $revenueAnterior): void
    {
        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesYm]);

        $this->semearShopeeDiario($c, $periodo['current_start'], $periodo['current_end'], $revenueAtual);
        $this->semearShopeeDiario($c, $periodo['baseline_start'], $periodo['baseline_end'], $revenueAnterior);
    }

    private function semearShopeeDiario(Company $c, string $inicio, string $fim, float $revenue): void
    {
        $cursor  = Carbon::parse($inicio);
        $fimData = Carbon::parse($fim);
        while ($cursor->lte($fimData)) {
            ShopeeMetric::create([
                'company_id'     => $c->id,
                'reference_date' => $cursor->toDateString(),
                'revenue'        => $revenue,
            ]);
            $cursor->addDay();
        }
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template 116-06 ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => now()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ], $overrides));
    }

    /**
     * Cenário padrão: 1 analista, 1 empresa com margem real (financeiro
     * saudável), 1 survey NÃO respondido de agosto/2026 (competência
     * financeira = julho/2026, régua NPSWIN-03).
     *
     * @return array{0: User, 1: Company, 2: NpsSurvey}
     */
    private function criarCenarioReconciliacaoSaudavel(string $nome = 'Reconsolida'): array
    {
        $u             = $this->criarUserAnalista($nome);
        $servicoShopee = $this->servicoShopeeId();
        $empresa       = $this->criarEmpresaShopee($u, $servicoShopee);
        // +66% de faturamento vs mês anterior — bem acima do limiar de 5% da
        // régua (reguaFaturamento), garante pontuação 5,0 determinística.
        $this->seedShopeeRevenue($empresa, '2026-07', 500, 300);

        $template = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoShopee]);
        $survey   = $this->criarSurveyNaoRespondido($empresa, $template, [
            'month_reference' => '2026-08-01',
            'expires_at'      => Carbon::parse('2026-08-31 23:59:59'),
        ]);

        return [$u, $empresa, $survey];
    }

    /**
     * Cenário de margem DEGRADADA — molde EXATO do
     * `Phase110/ConsolidarMesMargemResilienteTest`: 1 empresa com margem real
     * + 3 sem dado nenhum → cobertura 1/4 = 0,25 (< 0,7). A empresa boa
     * carrega o survey não respondido que dispara o backfill.
     *
     * @return array{0: User, 1: Company, 2: NpsSurvey}
     */
    private function montarCarteiraComMargemDegradada(): array
    {
        $u           = $this->criarUserAnalista('Degradado');
        $servicoPerf = $this->servicoPerformanceId();

        $companyBoa = $this->criarEmpresaComMargemReal($u, $servicoPerf, '2026-07');
        $template   = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);
        $survey     = $this->criarSurveyNaoRespondido($companyBoa, $template, [
            'month_reference' => '2026-08-01',
            'expires_at'      => Carbon::parse('2026-08-31 23:59:59'),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->criarEmpresaSemMargem($u, $servicoPerf);
        }

        return [$u, $companyBoa, $survey];
    }

    // ═══ 1 — dry-run: relatório antes/depois, nada gravado ═════════════════

    #[Test]
    public function test_dry_run_nao_grava_nada_e_mostra_relatorio_antes_depois(): void
    {
        $this->criarCenarioReconciliacaoSaudavel();

        // Nota: várias das colunas do relatório (Pessoa/Competência/NPS
        // antes/NPS depois/Faixa antes/Faixa depois) vivem na MESMA linha da
        // tabela (1 único `doWrite()`), e o mock de `$this->artisan()->
        // expectsOutputToContain()` só credita UMA expectativa por chamada
        // de output — múltiplas expectations concorrendo pela mesma linha se
        // atropelam (a primeira declarada "rouba" a linha das demais).
        // Por isso a checagem aqui usa `Artisan::output()` bruto +
        // `assertStringContainsString`, que não tem essa limitação.
        $exitCode = \Illuminate\Support\Facades\Artisan::call('nps:materializar-nao-respondidos', ['--dry-run' => true]);
        $output   = \Illuminate\Support\Facades\Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pessoa', $output);
        $this->assertStringContainsString('Competência', $output);
        $this->assertStringContainsString('NPS antes', $output);
        $this->assertStringContainsString('NPS depois', $output);
        $this->assertStringContainsString('Faixa antes', $output);
        $this->assertStringContainsString('Faixa depois', $output);
        $this->assertStringContainsString('DRY-RUN', $output);

        $this->assertDatabaseCount('nps_imputed_assignments', 0);
    }

    // ═══ 2 — dry-run: plano de reconsolidação sem tocar o snapshot ═════════

    #[Test]
    public function test_dry_run_lista_plano_de_reconsolidacao_sem_tocar_snapshot(): void
    {
        [$u] = $this->criarCenarioReconciliacaoSaudavel();

        // Snapshot mensal já congelado (regra ainda sem nenhuma linha imputada).
        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $snapAntes = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();
        $this->assertNotNull($snapAntes);
        $scoreAntes = $snapAntes->score;
        $classificacaoAntes = $snapAntes->classificacao;

        $this->artisan('nps:materializar-nao-respondidos', ['--dry-run' => true])
            ->expectsOutputToContain('reconsolidação')
            ->expectsOutputToContain('2026-07')
            ->assertExitCode(0);

        $snapDepois = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();
        $this->assertSame($scoreAntes, $snapDepois->score, 'dry-run NUNCA reconsolida o snapshot.');
        $this->assertSame($classificacaoAntes, $snapDepois->classificacao);
    }

    // ═══ 3 — --force grava e é idempotente ═════════════════════════════════

    #[Test]
    public function test_force_grava_e_rerun_e_idempotente(): void
    {
        $this->criarCenarioReconciliacaoSaudavel();

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true])->assertExitCode(0);
        $contagem1 = DB::table('nps_imputed_assignments')->count();
        $this->assertGreaterThan(0, $contagem1);

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true])->assertExitCode(0);
        $contagem2 = DB::table('nps_imputed_assignments')->count();

        $this->assertSame($contagem1, $contagem2, 'rerun com --force não pode duplicar linha nenhuma');
    }

    // ═══ 4 — reconsolidação muda o snapshot mensal (registro autoritativo) ═

    #[Test]
    public function test_reconsolidacao_do_snapshot_muda_score_apos_o_backfill(): void
    {
        [$u] = $this->criarCenarioReconciliacaoSaudavel();

        // "Antes" calculado DIRETO via compute() (regra desligada) — não via
        // uma chamada real a `desempenho:consolidar-mes` antes do comando.
        // Motivo: `updateOrCreate(['mes_referencia' => 'YYYY-MM-01'])` (bare
        // date string) desse comando compartilhado só encontra uma linha já
        // existente quando a coluna DATE trunca a hora nativamente (MySQL,
        // produção) — SQLite (testes) armazena a string completa
        // "YYYY-MM-01 00:00:00" e o WHERE bare-date NUNCA casa, colidindo
        // com o unique key no re-run (limitação de ambiente, não bug desta
        // fase; `ConsolidarMesDesempenho.php` é intocável por este plano —
        // ver `<notas_de_execucao>` do PLAN.md). Por isso este teste só
        // reconsolida a competência UMA vez (dentro do próprio comando),
        // evitando o double-write no mesmo (user, mês).
        $scoreService = app(DesempenhoScoreService::class);
        $scoreService->setIncluirImputadas(false);
        $antes = $scoreService->compute($u, Carbon::parse('2026-07-01'));
        $scoreService->setIncluirImputadas(true);
        $scoreAntesEsperado = (int) round($antes['nota_final'] * 20);

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->assertExitCode(0);

        $depois = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();

        $this->assertNotNull($depois, 'a reconsolidação precisa ter criado o snapshot mensal de julho.');
        $this->assertNotEquals($scoreAntesEsperado, $depois->score,
            'o backfill precisa chegar ao snapshot congelado — não só ao compute() ao vivo.');
    }

    // ═══ 5 — reconsolidação restrita à competência efetivamente backfillada

    #[Test]
    public function test_reconsolidacao_restrita_a_competencia_efetivamente_backfillada(): void
    {
        $this->criarCenarioReconciliacaoSaudavel(); // gera backfill em julho/2026

        // Snapshot de OUTRA competência (junho/2026), de outro user, sem
        // nenhuma linha imputada relacionada — não pode ser tocado.
        $outroUser = $this->criarUserAnalista('Fora do Escopo');
        $snapJunho = DesempenhoScoreSnapshot::create([
            'user_id'              => $outroUser->id,
            'ref_date'             => '2026-06-01',
            'mes_referencia'       => '2026-06-01',
            'score'                => 77,
            'classificacao'        => 'intermediario',
            'ranking_pos'          => 1,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 3.85, 'faixa_bonus' => 'intermediario'],
        ]);
        $updatedAtAntes = $snapJunho->updated_at;

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->assertExitCode(0);

        $snapJunho->refresh();
        $this->assertSame(77, $snapJunho->score, 'competência sem linha backfillada não pode ser tocada.');
        $this->assertSame('intermediario', $snapJunho->classificacao);
        $this->assertTrue($updatedAtAntes->equalTo($snapJunho->updated_at));
    }

    // ═══ 6 — gate de margem recusa a reconsolidação → aviso NOMINAL ════════

    #[Test]
    public function test_reconsolidacao_recusada_pelo_gate_de_margem_degradada_avisa_nominalmente(): void
    {
        [$u] = $this->montarCarteiraComMargemDegradada();

        // Snapshot anterior já congelado (o valor que o gate deve preservar).
        $anterior = DesempenhoScoreSnapshot::create([
            'user_id'              => $u->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => '2026-07-01',
            'score'                => 88,
            'classificacao'        => 'maximo',
            'ranking_pos'          => 1,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 4.4, 'faixa_bonus' => 'maximo'],
        ]);

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->expectsOutputToContain($u->name)
            ->expectsOutputToContain('2026-07')
            ->assertExitCode(1);

        $anterior->refresh();
        $this->assertSame(88, $anterior->score,
            'o gate de margem recusou o congelamento — score pré-backfill preservado.');

        // A recusa é do CONGELAMENTO, não do backfill — as linhas imputadas
        // continuam gravadas em nps_imputed_assignments.
        $this->assertGreaterThan(0, NpsImputedAssignment::where('user_id', $u->id)->count());
    }

    // ═══ 7 — contraste: cobertura saudável NÃO imprime aviso nominal ═══════

    #[Test]
    public function test_cobertura_saudavel_nao_imprime_aviso_de_snapshot_nao_atualizado(): void
    {
        $this->criarCenarioReconciliacaoSaudavel();

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->doesntExpectOutputToContain('snapshot NÃO foi atualizado')
            ->assertExitCode(0);
    }

    // ═══ 8 — sem --dry-run e sem --force: recusar a confirmação não grava ══

    #[Test]
    public function test_sem_dry_run_sem_force_recusar_confirmacao_nao_grava_nada(): void
    {
        [$u] = $this->criarCenarioReconciliacaoSaudavel();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $snapAntes = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();
        $scoreAntes = $snapAntes->score;

        $this->artisan('nps:materializar-nao-respondidos', ['--mes' => '2026-08'])
            ->expectsConfirmation(
                'Aplicar a materialização e reconsolidar o snapshot das competências afetadas?',
                'no'
            )
            ->assertExitCode(0);

        $this->assertDatabaseCount('nps_imputed_assignments', 0);

        $snapDepois = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();
        $this->assertSame($scoreAntes, $snapDepois->score, 'recusar a confirmação não reconsolida nada.');
    }

    // ═══ 9 — --mes restringe a materialização à competência informada ══════

    #[Test]
    public function test_mes_option_restringe_materializacao_a_competencia_informada(): void
    {
        $u             = $this->criarUserAnalista('Filtro Mes');
        $servicoShopee = $this->servicoShopeeId();
        $empresa       = $this->criarEmpresaShopee($u, $servicoShopee);
        $this->seedShopeeRevenue($empresa, '2026-07', 500, 300);
        $template      = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoShopee]);

        // Survey de agosto/2026 (dentro do filtro) e outro de outubro/2026 (fora).
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-08-01']);
        $this->criarSurveyNaoRespondido($empresa, $template, ['month_reference' => '2026-10-01']);

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('nps_imputed_assignments')->count(),
            '--mes=2026-08 deve materializar só o survey de agosto, não o de outubro.');
    }

    // ═══ 10 — --mes inválido retorna erro sem gravar ═══════════════════════

    #[Test]
    public function test_mes_invalido_retorna_erro_sem_gravar(): void
    {
        $this->criarCenarioReconciliacaoSaudavel();

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => 'junho'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('nps_imputed_assignments', 0);
    }

    // ═══ 11 — nada a fazer: sucesso, sem reconsolidar ══════════════════════

    #[Test]
    public function test_nada_a_materializar_retorna_sucesso_sem_reconsolidar(): void
    {
        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true])
            ->expectsOutputToContain('Nada a materializar')
            ->assertExitCode(0);

        $this->assertDatabaseCount('desempenho_score_snapshots', 0);
    }

    // ═══ 12 — cache do bônus é removido após --force ═══════════════════════

    #[Test]
    public function test_cache_do_bonus_e_removido_apos_force(): void
    {
        [$u] = $this->criarCenarioReconciliacaoSaudavel();

        $scoreService = app(DesempenhoScoreService::class);
        $competenciaFinanceira = Carbon::parse('2026-07-01');
        $chave = $scoreService->cacheKey($u->id, $competenciaFinanceira);
        Cache::put($chave, ['fake' => true], now()->addMinutes(10));
        $this->assertTrue(Cache::has($chave));

        $this->artisan('nps:materializar-nao-respondidos', ['--force' => true, '--mes' => '2026-08'])
            ->assertExitCode(0);

        $this->assertFalse(Cache::has($chave), 'a chave de cache do bônus da pessoa afetada deve ser removida.');
    }

    // ═══ 13 — --desfazer apaga, busta cache, reconsolida e confere (rollback)

    #[Test]
    public function test_desfazer_remove_linhas_reconsolida_e_devolve_score_anterior(): void
    {
        [$u] = $this->criarCenarioReconciliacaoSaudavel();

        // Materializa as linhas DIRETO pelo serviço (não pelo comando) —
        // evita que o teste precise reconsolidar julho DUAS vezes (uma no
        // "force" e outra no "desfazer"): `desempenho:consolidar-mes`
        // (arquivo compartilhado, intocável por este plano) faz
        // `updateOrCreate(['mes_referencia' => 'YYYY-MM-01'])` com STRING
        // bare-date; em MySQL (produção) a coluna DATE nativa trunca a hora
        // e o WHERE casa normalmente, mas em SQLite (testes) a linha já
        // existente é persistida com "YYYY-MM-01 00:00:00" e o WHERE
        // bare-date nunca casa — colide com o unique key no 2º write em vez
        // de atualizar (limitação de ambiente, não desta fase). Por isso
        // aqui o `--desfazer` é a ÚNICA reconsolidação de julho no teste.
        app(NpsImputationService::class)->materializarLote(Carbon::parse('2026-08-01'), dryRun: false);
        $this->assertGreaterThan(0, DB::table('nps_imputed_assignments')->count());

        $this->artisan('nps:materializar-nao-respondidos', ['--desfazer' => true, '--force' => true, '--mes' => '2026-08'])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('nps_imputed_assignments')->count(),
            '--desfazer apaga TODAS as linhas da competência informada, inclusive definitivo.');

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)->whereDate('mes_referencia', '2026-07-01')->first();
        $this->assertNotNull($snap, '--desfazer precisa reconsolidar o snapshot mesmo sem execução prévia do comando.');
        $this->assertNull($snap->breakdown_json['componentes']['nps_medio'],
            'sem nenhuma linha imputada (removidas pelo rollback), o componente NPS volta a ficar excluído (null) — mesmo estado pré-backfill.');
    }

    // ═══ 14 — relatório marca a pessoa cuja faixa de bônus muda ════════════

    #[Test]
    public function test_relatorio_marca_pessoa_que_muda_de_faixa(): void
    {
        $this->criarCenarioReconciliacaoSaudavel('Muda De Faixa');

        $this->artisan('nps:materializar-nao-respondidos', ['--dry-run' => true])
            ->expectsOutputToContain('Muda faixa?')
            ->assertExitCode(0);
    }

    // ═══ 15 — Fase 119.1 (Plano 08): regressão da janela restrita da rotina
    //          diária (mês anterior + mês corrente, NUNCA o histórico) ══════
    //
    // A Fase 119.1 desligou o disparo automático (`nps:disparar-mensal`,
    // `Schedule::command`) mas NÃO mexeu nesta rotina de propósito — o
    // backfill retroativo do histórico continua sendo operação MANUAL com
    // gate humano (C6 do 119.1-CONTEXT.md; ver também a seção "STATUS DO
    // BACKFILL RETROATIVO" em `docs/nps-nao-respondido-nota-1.md`). Estes
    // dois testes provam que essa restrição continua de pé depois da fase.

    #[Test]
    public function test_119_1_agendamento_processa_exatamente_mes_anterior_e_mes_corrente_via_mes(): void
    {
        // Asserção TEXTUAL sobre `routes/console.php`, e não sobre o closure
        // do `Schedule::call` (não trivialmente invocável isolado em teste,
        // conforme a própria Task 2 do plano 08 antecipa) — o risco real é
        // alguém "simplificar" o laço num refactor futuro e, sem querer,
        // ligar de novo o backfill retroativo (rodar sem `--mes` varre TODO
        // o histórico de `nps_surveys`, ver T-119.1-33 do 119.1-08-PLAN.md).
        $conteudo = file_get_contents(base_path('routes/console.php'));
        $this->assertNotFalse($conteudo, 'routes/console.php precisa existir e ser legível.');

        $this->assertStringContainsString('nps-materializar-nao-respondidos', $conteudo,
            'o agendamento da Fase 116 continua registrado.');
        $this->assertStringContainsString(
            'foreach ([now()->subMonthNoOverflow(), now()] as $mes)',
            $conteudo,
            'o laço precisa continuar restrito a EXATAMENTE 2 meses (mês anterior + corrente) — nunca um range aberto.'
        );
        $this->assertStringContainsString(
            "'--mes'   => \$mes->format('Y-m')",
            $conteudo,
            'cada chamada ao comando dentro do laço precisa continuar passando --mes explicitamente — sem isso o comando varre o histórico inteiro.'
        );

        // Garante que a chamada ao comando dentro do bloco do agendamento
        // (não em qualquer lugar do arquivo — outros blocos podem citar o
        // nome do comando em comentário) sempre tem `--mes` ao lado: conta as
        // ocorrências do nome do comando fora de comentário e confirma que
        // elas batem com as ocorrências de `--mes` dentro do MESMO bloco.
        $blocoAgendamento = substr(
            $conteudo,
            (int) strpos($conteudo, "Schedule::call(function () {\n    foreach ([now()->subMonthNoOverflow()"),
            600
        );
        $this->assertSame(
            substr_count($blocoAgendamento, "Artisan::call('nps:materializar-nao-respondidos'"),
            substr_count($blocoAgendamento, "'--mes'"),
            'toda chamada ao comando dentro do bloco do agendamento precisa vir acompanhada de --mes — nenhuma chamada "nua".'
        );
    }

    #[Test]
    public function test_119_1_competencia_fechada_anterior_ao_mes_anterior_continua_intocada_apos_rodar_a_rotina(): void
    {
        // "Hoje" já congelado em 2026-08-01 14:05:00 pelo setUp() desta
        // classe — mês anterior = julho/2026 (dentro da janela), mês
        // corrente = agosto/2026 (dentro da janela). Junho/2026 é a
        // competência de COLETA (`month_reference`) imediatamente ANTERIOR
        // ao mês anterior — precisa ficar de fora dos dois `--mes` que a
        // rotina roda.
        //
        // Usuários SEPARADOS de propósito: isola a reconsolidação do
        // snapshot de cada um (a cobertura de margem — FIXMARG-03 — é
        // calculada por PESSOA; misturar os dois cenários no mesmo usuário
        // faria a empresa "fora da janela" (sem margem) contaminar a
        // cobertura da competência julho e o comando sairia com exit 1 por
        // um motivo que não tem nada a ver com o que este teste prova).
        $uFora       = $this->criarUserAnalista('Coerencia Janela Fora 119.1-08');
        $servicoPerf = $this->servicoPerformanceId();
        $templateFora = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoPerf]);

        // Survey de COLETA em junho (competência financeira = maio) — FORA
        // da janela de 2 meses (julho + agosto). Nunca deveria ser tocado
        // pelo laço, então nem precisa de dado financeiro saudável.
        $empresaAntiga = $this->criarEmpresaSemMargem($uFora, $servicoPerf);
        $surveyForaDaJanela = $this->criarSurveyNaoRespondido($empresaAntiga, $templateFora, [
            'month_reference' => '2026-06-01',
            'expires_at'      => Carbon::parse('2026-06-30 23:59:59'),
        ]);

        // Survey de COLETA em agosto (competência financeira = julho) —
        // DENTRO da janela (mês corrente). Molde EXATO de
        // `criarCenarioReconciliacaoSaudavel()` (Shopee, financeiro saudável
        // e determinístico) para a reconsolidação do gate FIXMARG-03 passar
        // de primeira — o objeto deste teste é a JANELA, não o gate de margem.
        $uDentro       = $this->criarUserAnalista('Coerencia Janela Dentro 119.1-08');
        $servicoShopee = $this->servicoShopeeId();
        $empresaAtual  = $this->criarEmpresaShopee($uDentro, $servicoShopee);
        $this->seedShopeeRevenue($empresaAtual, '2026-07', 500, 300);
        $templateDentro = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoShopee]);
        $surveyDentroDaJanela = $this->criarSurveyNaoRespondido($empresaAtual, $templateDentro, [
            'month_reference' => '2026-08-01',
            'expires_at'      => Carbon::parse('2026-08-31 23:59:59'),
        ]);

        // Replica EXATAMENTE o laço de `routes/console.php` (mesmo shape:
        // --force + --mes por mês, mês anterior + mês corrente) — não chama
        // o Scheduler em si (mecanismo de cron não é o objeto deste teste),
        // só a mesma sequência de invocações que ele produz.
        foreach ([now()->subMonthNoOverflow(), now()] as $mes) {
            $this->artisan('nps:materializar-nao-respondidos', [
                '--force' => true,
                '--mes'   => $mes->format('Y-m'),
            ])->assertExitCode(0);
        }

        $this->assertDatabaseMissing('nps_imputed_assignments', ['survey_id' => $surveyForaDaJanela->id]);
        $this->assertDatabaseHas('nps_imputed_assignments', ['survey_id' => $surveyDentroDaJanela->id]);
    }
}
