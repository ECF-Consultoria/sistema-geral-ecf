<?php

namespace Tests\Feature\Phase119;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 02 (Task 2) — contrato completo da linha por empresa
 * (EMPS-01) devolvida por `CompanyScoreService::computeEmpresasScore()`.
 *
 * Cobre os 6 comportamentos do bloco `<behavior>` da Task 2: contrato
 * completo, empresa sem fonte (D-03), escopo de carteira (T-119-02) e
 * invalidação por competência (T-119-03).
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-02-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 */
class CompanyScoreServiceContratoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 — nenhuma chamada real à Adman é aceitável enquanto o
        // gate não estiver aprovado. NÃO registrar um Http::fake() genérico
        // aqui: os stubs de Http::fake() ACUMULAM e o PRIMEIRO registrado
        // vence — um fake 404 aqui "ganharia" do fixture real chamado depois
        // em cada teste (mesmo comportamento documentado em
        // AdmanMetricDiffServiceTest). Cada teste que toca o dispatcher
        // chama fakeAdmanEndpoints() explicitamente.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    /**
     * Payload real de /accounts/{custId}/metrics — reusado de
     * tests/Feature/V18/AdmanMetricDiffServiceTest.php.
     */
    private function respostaAccountMetrics(): array
    {
        return [
            'metrics' => [
                'billing'          => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'     => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin' => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
                'investment'       => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    private function respostaPerformance(): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'profitMargin' => ['value' => 141428.81, 'diff' => 147.75, 'prev' => 57084.83],
                'investment'   => ['value' => 9990.82,   'diff' => 81.96,  'prev' => 5490.65],
                'profitShare'  => 26.64,
            ],
            'items' => [],
        ];
    }

    /**
     * Reusa exatamente os 2 padrões de `AdmanMetricDiffServiceTest` — esta
     * suíte só tem 1 empresa Adman por cenário, então o wildcard genérico é
     * suficiente (não precisa distinguir por custId).
     */
    private function fakeAdmanEndpoints(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);
    }

    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    private function periodoMesEmCurso(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => 'current_month']);
    }

    /** @return array{empresaAdman: Company, empresaPolos: Company, user: User} */
    private function montarCenario(): array
    {
        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $empresaAdman = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-CONTRATO-A']);
        $servicoPerf  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaAdman->id, $servicoPerf, true);
        $this->inserirPivot($empresaAdman->id, $user->id, 'consultor', $servicoPerf);

        $empresaPolos = Company::factory()->create(['active' => true]);
        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresaPolos->id, $user->id, 'consultor', $servicoPolos);

        return compact('empresaAdman', 'empresaPolos', 'user');
    }

    private function service(): CompanyScoreService
    {
        return app(CompanyScoreService::class);
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_carteira_com_adman_e_polos_devolve_2_linhas_com_todas_as_chaves_do_contrato(): void
    {
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertCount(2, $resultado);
        $this->assertTrue($resultado->has($cenario['empresaAdman']->id));
        $this->assertTrue($resultado->has($cenario['empresaPolos']->id));

        $chavesEsperadas = [
            'company_id', 'company_name', 'fonte_financeira', 'nps_pontos',
            'faturamento_atual', 'faturamento_anterior', 'faturamento_var_pct', 'faturamento_pontos',
            'margem_pct_atual', 'margem_pct_anterior', 'margem_var_pp', 'margem_pontos',
            'componentes_presentes', 'nota_empresa', 'nota_empresa_parcial', 'status', 'quality',
        ];

        foreach ($resultado as $linha) {
            foreach ($chavesEsperadas as $chave) {
                $this->assertTrue(property_exists($linha, $chave), "Chave ausente na linha: {$chave}");
            }
        }
    }

    #[Test]
    public function test_empresa_adman_traz_faturamento_e_margem_do_dispatcher_com_diff_source(): void
    {
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresaAdman']->id);

        $this->assertSame('adman', $linha->fonte_financeira);
        $this->assertSame(530797.73, $linha->faturamento_atual);
        $this->assertSame(263768.55, $linha->faturamento_anterior);
        $this->assertSame(101.24, $linha->faturamento_var_pct);
        $this->assertSame(27.47, $linha->margem_pct_atual);
        $this->assertSame(24.08, $linha->margem_pct_anterior);
        $this->assertSame(3.39, $linha->margem_var_pp);
        $this->assertSame('adman_diff', $linha->quality['revenue_diff_source']);
        $this->assertSame('adman_diff', $linha->quality['margin_diff_source']);
    }

    #[Test]
    public function test_empresa_polos_fica_sem_fonte_com_todos_os_campos_financeiros_nulos(): void
    {
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresaPolos']->id);

        $this->assertNull($linha->fonte_financeira);
        $this->assertNull($linha->faturamento_atual);
        $this->assertNull($linha->faturamento_anterior);
        $this->assertNull($linha->faturamento_var_pct);
        $this->assertNull($linha->faturamento_pontos);
        $this->assertNull($linha->margem_pct_atual);
        $this->assertNull($linha->margem_pct_anterior);
        $this->assertNull($linha->margem_var_pp);
        $this->assertNull($linha->margem_pontos);
        $this->assertSame('sem_fonte', $linha->status);
        // Competência 2026-06 lida em 2026-07-15: a janela NPS (M+1=julho)
        // AINDA não fechou (`gte` só em 31/07 14h) — sem survey real na
        // fixture, notasNpsPorEmpresa devolve nota=null/origem=janela_aberta
        // para TODA a carteira. nps_pontos fica null e o motivo aparece
        // DEPOIS de sem_fonte_financeira (ordem determinística do §Task 2).
        $this->assertNull($linha->nps_pontos);
        $this->assertSame(['sem_fonte_financeira', 'nps_janela_aberta'], $linha->quality['motivos']);
        // D-03: nota_empresa_parcial SEMPRE bate com o único componente
        // possível (NPS) — aqui ambos são null.
        $this->assertSame($linha->nps_pontos, $linha->nota_empresa_parcial);
        $this->assertSame(0, $linha->componentes_presentes);
    }

    #[Test]
    public function test_mes_em_curso_da_piso_1_de_nps_e_empresa_polos_fica_com_parcial_1(): void
    {
        // Dispatcher é chamado independente de mesFechado (guard C-04 só
        // depende de fonte_financeira !== null) — a empresaAdman sempre
        // exercita o MetricDiffDispatcher.
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-07-01'),
            $this->periodoMesEmCurso(),
        );

        $linhaAdman = $resultado->get($cenario['empresaAdman']->id);
        $linhaPolos = $resultado->get($cenario['empresaPolos']->id);

        $this->assertSame(1.0, $linhaAdman->nps_pontos);
        $this->assertSame(1.0, $linhaPolos->nps_pontos);
        $this->assertSame(1.0, $linhaPolos->nota_empresa_parcial);
        $this->assertSame(1, $linhaPolos->componentes_presentes);
        $this->assertSame('sem_fonte', $linhaPolos->status);
    }

    #[Test]
    public function test_empresa_de_outro_profissional_nao_aparece_na_collection(): void
    {
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        $outroUser    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresaAlheia = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-ALHEIA']);
        $servicoPerf2  = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresaAlheia->id, $servicoPerf2, true);
        $this->inserirPivot($empresaAlheia->id, $outroUser->id, 'consultor', $servicoPerf2);

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertCount(2, $resultado);
        $this->assertFalse($resultado->has($empresaAlheia->id));
    }

    #[Test]
    public function test_empresa_invalidada_na_competencia_nao_aparece_nem_como_sem_fonte(): void
    {
        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenario();

        BonusInvalidacao::create([
            'company_id'  => $cenario['empresaPolos']->id,
            'competencia' => Carbon::parse('2026-06-01'),
            'motivo'      => 'teste 119-02',
        ]);

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertCount(1, $resultado);
        $this->assertTrue($resultado->has($cenario['empresaAdman']->id));
        $this->assertFalse($resultado->has($cenario['empresaPolos']->id));
    }
}
