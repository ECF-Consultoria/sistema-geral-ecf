<?php

namespace Tests\Feature\Phase121;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoComparadorEmpresa;
use App\Models\DesempenhoComparadorProfissional;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Gate nº 1 do `121-VALIDATION.md` — prova a "comparação justa" do comando
 * `desempenho:comparar-score-empresa`: chamada única de `compute()` por
 * (profissional, competência) com `incluirEmpresasScore: true` (D-01),
 * releitura interleaved do `diff_pct` nativo da margem só na competência
 * alvo (invariante nº 2), persistência reconsultável do banco, fail-open
 * por profissional e o guard de re-execução do mesmo dia.
 *
 * `CompanyScoreService::computeEmpresasScore()` é mockado (molde de
 * `AgregacaoProfissionalTest`/`ShadowNotaNovaExpostaTest`) para controlar
 * exatamente quantas/quais empresas entram na comparação. `DesempenhoScoreService`
 * é substituído por um dublê que ENVOLVE uma instância REAL (resolvida do
 * container já com o mock de `CompanyScoreService` injetado) — o dublê só
 * conta as chamadas de `compute()`, o comportamento é o de verdade.
 */
class CompararScoreEmpresaCommandTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Mesmo molde de AgregacaoProfissionalTest/ShadowNotaNovaExpostaTest —
        // agosto/2026 é "hoje", julho/2026 (a competência alvo dos testes)
        // fica fechada.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->app->instance(MetricsProviderFactory::class, new CompararScoreEmpresaTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-121-02',
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

    // ═══ Helpers (mesmo molde de AgregacaoProfissionalTest) ══════════════════

    private function criarUserComCargo(string $nome): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

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

    private function criarEmpresa(string $createdAt = '-3 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        return $company->fresh();
    }

    /** 1 row de AdmanMetric no dia 10 — dia comum às duas janelas. */
    private function mockAdman(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    /**
     * Fixture MÍNIMA e válida (`sem_carteira=false`) — o conteúdo real do
     * Adman é irrelevante nos testes que mockam o `MetricDiffDispatcher`.
     * Só precisa existir carteira o suficiente para `computeUniverso()` não
     * cair no shape `sem_carteira`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function criarFixtureBase(string $nomeUser): array
    {
        $user = $this->criarUserComCargo($nomeUser);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $empresa     = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-121-02-BASE', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-07', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-06', revenue: 10000, margem: 2000.00);

        return ['user' => $user, 'empresa' => $empresa];
    }

    /**
     * Monta uma linha `object` no shape de
     * `CompanyScoreService::computeEmpresasScore()` — inclui `fonte_financeira`
     * e `quality.motivos` (lidos pelo comando), além dos campos que
     * `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()` leem.
     */
    private function linhaEmpresaComFonte(
        int $companyId,
        string $fonteFinanceira,
        string $status,
        ?float $notaEmpresa,
        ?float $notaEmpresaParcial
    ): object {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => $fonteFinanceira,
            'nps_pontos'            => 4.0,
            'faturamento_atual'     => 1000.0,
            'faturamento_anterior'  => 900.0,
            'faturamento_var_pct'   => 11.11,
            'faturamento_pontos'    => 5.0,
            'margem_pct_atual'      => 20.0,
            'margem_pct_anterior'   => 18.0,
            'margem_var_pp'         => 2.0,
            'margem_pontos'         => 4.0,
            'componentes_presentes' => 3,
            'nota_empresa'          => $notaEmpresa,
            'nota_empresa_parcial'  => $notaEmpresaParcial,
            'status'                => $status,
            'quality'               => [
                'revenue_diff_source' => 'adman_diff',
                'margin_diff_source'  => 'adman_diff',
                'margin_source'       => null,
                'motivos'             => [],
            ],
        ];
    }

    /**
     * Substitui `CompanyScoreService::computeEmpresasScore()` pela Collection
     * fornecida e substitui `DesempenhoScoreService` por um dublê que ENVOLVE
     * uma instância REAL (resolvida do container já com o mock acima
     * injetado): o dublê só registra `[user_id, competência,
     * incluirEmpresasScore]` em `$chamadasCompute` (por referência) e o
     * payload retornado em `$payloadsCapturados`, delegando o cálculo de
     * verdade para a instância real — o comando resolve o serviço pelo
     * container e passa a ser contado sem perder comportamento.
     *
     * Quando `$mockarDispatcher=true`, `MetricDiffDispatcher::compute()`
     * também é substituído por um dublê que empurra `'diff:'.$company->id`
     * em `$eventosDispatcher` (por referência) e devolve um payload mínimo
     * fixo — usado pelos testes de interleaving/releitura.
     *
     * `$userIdQueFalha`, quando presente, faz o dublê lançar exceção ANTES
     * de chamar a instância real para aquele user — prova o fail-open.
     */
    private function instalarDuble(
        Collection $linhasEmpresasScore,
        array &$chamadasCompute,
        array &$payloadsCapturados,
        bool $mockarDispatcher = false,
        array &$eventosDispatcher = [],
        ?int $userIdQueFalha = null
    ): void {
        $this->mock(CompanyScoreService::class, function ($mock) use ($linhasEmpresasScore) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn($linhasEmpresasScore);
        });

        if ($mockarDispatcher) {
            $this->mock(MetricDiffDispatcher::class, function ($mock) use (&$eventosDispatcher) {
                $mock->shouldReceive('compute')
                    ->andReturnUsing(function (Company $company, array $periodo, string $source) use (&$eventosDispatcher) {
                        $eventosDispatcher[] = 'diff:' . $company->id;

                        return [
                            'metrics' => [
                                'revenue'                 => ['diff_pct' => null],
                                'contribution_margin_pct' => ['diff_pct' => 1.5],
                            ],
                        ];
                    });
            });
        }

        // Descarta qualquer binding anterior (instância ou dublê de uma
        // chamada prévia a este helper, dentro do mesmo teste) — sem isso,
        // `make()` devolveria o dublê da chamada anterior, e envolvê-lo de
        // novo com Mockery gera uma classe de mock composta que colide
        // (redeclaração fatal) na segunda/terceira chamada do mesmo teste.
        $this->app->forgetInstance(DesempenhoScoreService::class);
        $realService = $this->app->make(DesempenhoScoreService::class);

        $duble = \Mockery::mock($realService)->makePartial();
        $duble->shouldReceive('compute')
            ->andReturnUsing(function (User $user, Carbon $mes, ?array $periodoOverride = null, bool $incluirEmpresasScore = false) use (
                $realService,
                &$chamadasCompute,
                &$payloadsCapturados,
                $userIdQueFalha
            ) {
                $chamadasCompute[] = [
                    'user_id'                => $user->id,
                    'competencia'             => $mes->format('Y-m'),
                    'incluir_empresas_score'  => $incluirEmpresasScore,
                ];

                if ($userIdQueFalha !== null && $user->id === $userIdQueFalha) {
                    throw new \RuntimeException('falha proposital de teste');
                }

                $resultado = $realService->compute($user, $mes, $periodoOverride, $incluirEmpresasScore);

                $payloadsCapturados[$user->id . '|' . $mes->format('Y-m')] = $resultado;

                return $resultado;
            });

        $this->app->instance(DesempenhoScoreService::class, $duble);
    }

    // ═══ Teste 1 — D-01: chamada única, sempre incluirEmpresasScore=true ════

    #[Test]
    public function test_compute_e_chamado_exatamente_uma_vez_por_profissional_por_competencia_com_incluir_empresas_score_true(): void
    {
        $userA = $this->criarUserComCargo('Comparador Contagem A');
        $userB = $this->criarUserComCargo('Comparador Contagem B');

        $chamadas           = [];
        $payloadsCapturados = [];
        $this->instalarDuble(collect(), $chamadas, $payloadsCapturados);

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $chamadas, 'Com --historico=0, o número de chamadas é exatamente o número de profissionais elegíveis.');

        foreach ($chamadas as $chamada) {
            $this->assertSame('2026-07', $chamada['competencia']);
            $this->assertTrue($chamada['incluir_empresas_score'], 'compute() sempre chamado com incluirEmpresasScore: true (D-01).');
        }

        $userIdsChamados = collect($chamadas)->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$userA->id, $userB->id], $userIdsChamados, 'Cada profissional elegível é chamado exatamente 1 vez.');
        $this->assertSame(2, collect($chamadas)->unique('user_id')->count(), 'Nenhum profissional chamado mais de uma vez.');
    }

    // ═══ Teste 2 — invariante nº 2: interleaving ═══════════════════════════

    #[Test]
    public function test_releitura_do_dispatcher_acontece_interleaved_antes_da_persistencia_de_cada_empresa(): void
    {
        $fixture = $this->criarFixtureBase('Comparador Interleaving');

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $linhas = collect([
            $companyA->id => $this->linhaEmpresaComFonte($companyA->id, 'adman', 'complete', 4.0, 4.0),
            $companyB->id => $this->linhaEmpresaComFonte($companyB->id, 'adman', 'complete', 3.0, 3.0),
        ]);

        $chamadas           = [];
        $payloadsCapturados = [];
        $eventos            = [];
        $this->instalarDuble($linhas, $chamadas, $payloadsCapturados, true, $eventos);

        DesempenhoComparadorEmpresa::created(function ($model) use (&$eventos) {
            $eventos[] = 'persist:' . $model->company_id;
        });

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);

        $this->assertSame(0, $exitCode);

        $idsAlvo   = [$companyA->id, $companyB->id];
        $eventosAB = array_values(array_filter($eventos, function ($e) use ($idsAlvo) {
            foreach ($idsAlvo as $id) {
                if ($e === "diff:{$id}" || $e === "persist:{$id}") {
                    return true;
                }
            }

            return false;
        }));

        $this->assertSame([
            "diff:{$companyA->id}",
            "persist:{$companyA->id}",
            "diff:{$companyB->id}",
            "persist:{$companyB->id}",
        ], $eventosAB, 'diff(A), persist(A), diff(B), persist(B) — nunca persist(A), persist(B), diff(A), diff(B).');
    }

    // ═══ Teste 3 — releitura só na competência alvo ══════════════════════════

    #[Test]
    public function test_nenhuma_chamada_ao_dispatcher_nas_competencias_historicas(): void
    {
        $fixture = $this->criarFixtureBase('Comparador Sem Releitura Historica');
        $user    = $fixture['user'];

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $linhas = collect([
            $companyA->id => $this->linhaEmpresaComFonte($companyA->id, 'adman', 'complete', 4.0, 4.0),
            $companyB->id => $this->linhaEmpresaComFonte($companyB->id, 'adman', 'complete', 3.0, 3.0),
        ]);

        $chamadas           = [];
        $payloadsCapturados = [];
        $eventos            = [];
        $this->instalarDuble($linhas, $chamadas, $payloadsCapturados, true, $eventos);

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $chamadas, '2 competências (alvo + 1 histórica) x 1 profissional = 2 chamadas de compute().');

        $diffA = collect($eventos)->filter(fn ($e) => $e === "diff:{$companyA->id}")->count();
        $diffB = collect($eventos)->filter(fn ($e) => $e === "diff:{$companyB->id}")->count();

        $this->assertSame(1, $diffA, 'Releitura só acontece na competência alvo — 1 chamada, não 2.');
        $this->assertSame(1, $diffB, 'Releitura só acontece na competência alvo — 1 chamada, não 2.');

        $totalEmpresaRows = DesempenhoComparadorEmpresa::query()
            ->where('user_id', $user->id)
            ->whereIn('company_id', [$companyA->id, $companyB->id])
            ->count();
        $this->assertSame(4, $totalEmpresaRows, '2 empresas x 2 competências = 4 linhas persistidas.');

        $historica = DesempenhoComparadorEmpresa::query()
            ->where('user_id', $user->id)
            ->where('competencia_alvo', false)
            ->whereIn('company_id', [$companyA->id, $companyB->id])
            ->get();

        $this->assertTrue(
            $historica->every(fn ($linha) => $linha->margem_diff_pct === null),
            'Competência histórica nunca releva diff_pct — margem_diff_pct fica null.'
        );
    }

    // ═══ Teste 4 — persistência reconsultada do banco ════════════════════════

    #[Test]
    public function test_persistencia_relida_do_banco_com_nota_antiga_nova_delta_e_contadores_corretos(): void
    {
        $fixture = $this->criarFixtureBase('Comparador Persistencia');
        $user    = $fixture['user'];

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $linhas = collect([
            $companyA->id => $this->linhaEmpresaComFonte($companyA->id, 'adman', 'complete', 4.0, 4.0),
            $companyB->id => $this->linhaEmpresaComFonte($companyB->id, 'adman', 'complete', 2.0, 2.0),
        ]);

        $chamadas           = [];
        $payloadsCapturados = [];
        $eventos            = [];
        $this->instalarDuble($linhas, $chamadas, $payloadsCapturados, true, $eventos);

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $payloadsCapturados[$user->id . '|2026-07'];

        // Todas as asserções abaixo RELEEM do banco (::query()->...), nunca
        // do array em memória — só o `$payload` capturado serve de oráculo
        // independente pra conferir que o valor persistido bate com o que o
        // shadow realmente calculou.
        $linhaProfissional = DesempenhoComparadorProfissional::query()
            ->where('user_id', $user->id)
            ->where('periodo_key', '2026-07')
            ->firstOrFail();

        $this->assertEqualsWithDelta($payload['nota_final'], $linhaProfissional->nota_antiga, 0.001);
        $this->assertEqualsWithDelta($payload['nota_final_por_empresa'], $linhaProfissional->nota_nova, 0.001);
        $this->assertEqualsWithDelta(3.0, $linhaProfissional->nota_nova, 0.001, '(4.0+2.0)/2 = 3.0.');
        $this->assertEqualsWithDelta(
            $payload['nota_final_por_empresa'] - $payload['nota_final'],
            $linhaProfissional->delta,
            0.001
        );
        $this->assertSame($payload['score_status'], $linhaProfissional->status_antigo);
        $this->assertSame($payload['score_status_por_empresa'], $linhaProfissional->status_novo);
        $this->assertSame('official', $linhaProfissional->status_novo, 'Cobertura 100% (2 de 2 complete) -> official.');
        $this->assertSame(2, $linhaProfissional->empresas_total);
        $this->assertSame(2, $linhaProfissional->empresas_complete);
        $this->assertSame(0, $linhaProfissional->empresas_partial);
        $this->assertSame(0, $linhaProfissional->empresas_sem_fonte);
        $this->assertSame(0, $linhaProfissional->empresas_sem_dados);
        $this->assertFalse($linhaProfissional->falhou);
        $this->assertNull($linhaProfissional->erro);

        $linhasEmpresa = DesempenhoComparadorEmpresa::query()
            ->where('user_id', $user->id)
            ->where('periodo_key', '2026-07')
            ->orderBy('company_id')
            ->get();

        $this->assertCount(2, $linhasEmpresa);
        $this->assertTrue(
            $linhasEmpresa->every(fn ($l) => $l->margem_diff_pct === 1.5),
            'diff_pct nativo mockado propagado pra cada empresa da competência alvo.'
        );
    }

    // ═══ Teste 5 — fail-open por profissional ════════════════════════════════

    #[Test]
    public function test_falha_de_um_profissional_nao_impede_persistencia_dos_demais(): void
    {
        $fixtureOk    = $this->criarFixtureBase('Comparador Falha OK');
        $fixtureFalha = $this->criarFixtureBase('Comparador Falha Ruim');

        $chamadas           = [];
        $payloadsCapturados = [];
        $eventosNaoUsado    = [];
        $this->instalarDuble(collect(), $chamadas, $payloadsCapturados, false, $eventosNaoUsado, $fixtureFalha['user']->id);

        $exitCode = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);

        $this->assertSame(0, $exitCode, 'Fail-open: uma falha de profissional não aborta a rodada inteira.');

        $linhaOk = DesempenhoComparadorProfissional::query()
            ->where('user_id', $fixtureOk['user']->id)
            ->where('periodo_key', '2026-07')
            ->firstOrFail();
        $this->assertFalse($linhaOk->falhou);

        $linhaFalha = DesempenhoComparadorProfissional::query()
            ->where('user_id', $fixtureFalha['user']->id)
            ->where('periodo_key', '2026-07')
            ->firstOrFail();
        $this->assertTrue($linhaFalha->falhou);
        $this->assertStringContainsString('falha proposital de teste', (string) $linhaFalha->erro);
    }

    // ═══ Teste 6 — guard de re-execução ═══════════════════════════════════════

    #[Test]
    public function test_guard_de_reexecucao_aborta_sem_gravar_e_com_force_grava_novo_run_id(): void
    {
        $this->criarFixtureBase('Comparador Guard');

        $chamadas1           = [];
        $payloadsCapturados1 = [];
        $this->instalarDuble(collect(), $chamadas1, $payloadsCapturados1);

        $exitCode1 = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);
        $this->assertSame(0, $exitCode1);

        $countApos1 = DesempenhoComparadorProfissional::query()
            ->where('periodo_key', '2026-07')
            ->where('competencia_alvo', true)
            ->count();
        $this->assertSame(1, $countApos1);

        $runIdInicial = DesempenhoComparadorProfissional::query()
            ->where('periodo_key', '2026-07')
            ->where('competencia_alvo', true)
            ->value('run_id');

        // Segunda rodada SEM --force no mesmo dia — aborta sem gravar nada.
        $chamadas2           = [];
        $payloadsCapturados2 = [];
        $this->instalarDuble(collect(), $chamadas2, $payloadsCapturados2);

        $exitCode2 = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
        ]);
        $this->assertSame(1, $exitCode2, 'Sem --force, a segunda rodada do mesmo dia para a mesma competência aborta.');
        $this->assertCount(0, $chamadas2, 'Abortou ANTES de chamar compute() para qualquer profissional.');

        $countApos2 = DesempenhoComparadorProfissional::query()
            ->where('periodo_key', '2026-07')
            ->where('competencia_alvo', true)
            ->count();
        $this->assertSame(1, $countApos2, 'Nenhuma linha nova gravada sem --force.');

        // Terceira rodada COM --force — grava novo run_id.
        $chamadas3           = [];
        $payloadsCapturados3 = [];
        $this->instalarDuble(collect(), $chamadas3, $payloadsCapturados3);

        $exitCode3 = Artisan::call('desempenho:comparar-score-empresa', [
            '--mes'       => '2026-07',
            '--historico' => 0,
            '--force'     => true,
        ]);
        $this->assertSame(0, $exitCode3);

        $countApos3 = DesempenhoComparadorProfissional::query()
            ->where('periodo_key', '2026-07')
            ->where('competencia_alvo', true)
            ->count();
        $this->assertSame(2, $countApos3, 'Com --force, uma nova linha é gravada.');

        $runIdsDistintos = DesempenhoComparadorProfissional::query()
            ->where('periodo_key', '2026-07')
            ->where('competencia_alvo', true)
            ->pluck('run_id')
            ->unique();
        $this->assertCount(2, $runIdsDistintos);
        $this->assertTrue($runIdsDistintos->contains($runIdInicial));
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suite. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suite roda com `--filter`, mesmo
 * padrão já documentado em `AgregacaoProfissionalTest`/`ShadowNotaNovaExpostaTest`).
 */
class CompararScoreEmpresaTestProviderStub extends MetricsProviderFactory
{
    public function __construct()
    {
        // Intencionalmente não chama parent::__construct().
    }

    public function caseFor(Company $company): string
    {
        return 'so-adman';
    }

    public function forCompany(Company $company): array
    {
        return [];
    }
}
