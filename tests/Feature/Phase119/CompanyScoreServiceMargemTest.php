<?php

namespace Tests\Feature\Phase119;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 03 (Task 1) — EMPS-03: a margem da linha por empresa é
 * pontuada sobre `diff_pp` (pontos percentuais), NUNCA sobre `diff_pct`
 * (variação relativa).
 *
 * Fixture MPP-06, já validada em
 * `tests/Feature/V18/AdmanMetricDiffServiceTest.php:52-62` — escolhida DE
 * PROPÓSITO porque `diff_pp = round(27.47 - 24.08, 2) = 3.39` ⇒
 * `reguaMargem(3.39) = 4.0`, enquanto `diff_pct = 14.09` ⇒
 * `reguaMargem(14.09) = 5.0` — os dois caminhos DIVERGEM em nota. Um erro de
 * fio (ligar o campo errado) troca o resultado de forma visível, em vez de
 * silenciosa (T-119-08).
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-03-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 */
class CompanyScoreServiceMargemTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** Hash de referência do gate de aditividade (119-VALIDATION.md). */
    /** Rotacionado pela Fase 119.1 (D1) — 4º ramo (D) somado a DesempenhoScoreService.php. */
    /** Rotacionado pela quick 260810-mbv (2026-08-10) — bump da chave de cache v17 → v18. O gate já estava vermelho desde as Fases 120/122/v17, que alteraram o arquivo sem rotacionar. */
    /** Rotacionado pela quick 260810-mt8 (2026-08-10) — nota final passa a dividir sempre por 3. */
    /** Rotacionado pela Fase 136 (D-10, 2026-08-11) — desempate de fonte financeira passa a checar cust_id e a chave de cache foi para v20. */
    private const HASH_DESEMPENHO_SCORE_SERVICE = 'a78b7d2823aa899ef30600d32b608b96c8ae84887588e17df8b058781a6b8794';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 pendente — nenhuma chamada real à Adman é aceitável.
        // NÃO registrar Http::fake() genérico aqui: os stubs ACUMULAM e o
        // PRIMEIRO registrado vence (achado da Wave 1, 119-02-SUMMARY.md) —
        // cada teste chama fakeAdmanEndpoints() explicitamente.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    /**
     * Payload real de /performance/{custId} — reusado de
     * tests/Feature/V18/AdmanMetricDiffServiceTest.php:36-47.
     */
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
     * Payload MPP-06 de /accounts/{custId}/metrics — reusado de
     * tests/Feature/V18/AdmanMetricDiffServiceTest.php:52-62.
     * `$comPrev=false` remove `percentageMargin.prev` (cenário negativo do
     * behavior — payload sem baseline de margem).
     */
    private function respostaAccountMetrics(bool $comPrev = true): array
    {
        $percentageMargin = ['value' => 27.47, 'diff' => 14.09];
        if ($comPrev) {
            $percentageMargin['prev'] = 24.08;
        }

        return [
            'metrics' => [
                'billing'          => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'     => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin' => $percentageMargin,
                'investment'       => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    private function fakeAdmanEndpoints(bool $comPrev = true): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics($comPrev), 200),
        ]);
    }

    /** Janela igual (`comparison_mode='previous_equal_length_window'`) — mês fechado. */
    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    /** Mês em curso (`comparison_mode='same_interval_previous_month'`). */
    private function periodoMesEmCurso(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => 'current_month']);
    }

    /**
     * Empresa Adman + user + vínculo elegível. `$custId` único por teste —
     * a cacheKey do AdmanMetricDiffService inclui custId e janela.
     *
     * @return array{user: User, empresa: Company}
     */
    private function montarCenario(string $custId): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => $custId]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servico, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servico);

        return compact('user', 'empresa');
    }

    /**
     * Fixa a nota de NPS por dublê — os asserts de margem desta suíte não
     * dependem do comportamento de janela/survey do NpsPorEmpresaService
     * (já coberto em CompanyScoreServiceContratoTest).
     */
    private function fixarNps(int $companyId, ?float $nota): void
    {
        $this->mock(NpsPorEmpresaService::class, function ($mock) use ($companyId, $nota) {
            $mock->shouldReceive('notasNpsPorEmpresa')
                ->andReturn(collect([$companyId => (object) ['nota' => $nota]]));
        });
    }

    private function service(): CompanyScoreService
    {
        return app(CompanyScoreService::class);
    }

    /** Gate de aditividade (119-CONTEXT.md, 119-VALIDATION.md) — repetido em toda task. */
    private function assertHashDesempenhoScoreServiceIntocado(): void
    {
        $hash = hash_file('sha256', app_path('Services/DesempenhoScoreService.php'));
        $this->assertSame(self::HASH_DESEMPENHO_SCORE_SERVICE, $hash, 'DesempenhoScoreService.php foi alterado — fase é ADITIVA.');
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_fixture_mpp06_pontua_margem_sobre_diff_pp_4_pontos_e_nao_5(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $cenario = $this->montarCenario('CUST-MARGEM-01');
        $this->fixarNps($cenario['empresa']->id, 4.6);
        $this->fakeAdmanEndpoints();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertSame(27.47, $linha->margem_pct_atual);
        $this->assertSame(24.08, $linha->margem_pct_anterior);
        $this->assertSame(3.39, $linha->margem_var_pp);
        $this->assertSame(4.0, $linha->margem_pontos);
        // A prova de EMPS-03: diff_pct=14.09 daria reguaMargem(14.09)=5.0 —
        // NÃO é isso que a linha reporta. Divergência proposital (T-119-08).
        $this->assertNotSame(5.0, $linha->margem_pontos);
        $this->assertSame('adman_diff', $linha->quality['margin_diff_source']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_sem_prev_margem_var_pp_e_pontos_ficam_null_com_motivo(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $cenario = $this->montarCenario('CUST-MARGEM-02');
        $this->fixarNps($cenario['empresa']->id, 4.6);
        $this->fakeAdmanEndpoints(comPrev: false);

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNull($linha->margem_var_pp);
        $this->assertNull($linha->margem_pontos);
        $this->assertContains('margem_pp_indisponivel', $linha->quality['motivos']);
        // NPS (4.6) + faturamento (revenue.prev presente ⇒ diff_pct=101.24 ⇒
        // 5 pts) presentes; só margem falta ⇒ 2 de 3.
        $this->assertSame(2, $linha->componentes_presentes);
        $this->assertSame('partial', $linha->status);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    /**
     * REESCRITO pela quick 260810-mbv (2026-08-10).
     *
     * O contrato anterior era "mês em curso ⇒ `margem_var_pp` null mesmo com
     * value e prev presentes", e o efeito colateral era a coluna Margem vazia
     * o mês inteiro na tela de Desempenho. O que o gate D-07 protegia era a
     * JANELA errada (o `prev` da Adman é a janela imediatamente anterior, não
     * o mesmo intervalo do mês passado), não a existência da variação.
     *
     * Agora o `AdmanMetricDiffService` lê a margem da janela baseline do
     * próprio período e a linha por empresa herda o `diff_pp` — continua sem
     * recalcular nada por fora, que é o ponto que este teste sempre guardou.
     */
    #[Test]
    public function test_mes_em_curso_margem_var_pp_vem_da_janela_baseline(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $cenario = $this->montarCenario('CUST-MARGEM-03');
        $this->fixarNps($cenario['empresa']->id, 4.6);
        $this->fakeAdmanEndpoints(comPrev: true);

        $periodo = $this->periodoMesEmCurso();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-07-01'),
            $periodo,
        );

        $linha = $resultado->get($cenario['empresa']->id);

        // O fake devolve o MESMO payload para as duas janelas, então a margem
        // da baseline é a própria 27.47 e o p.p. dá 0.0 — o que importa aqui é
        // que a variação EXISTE e que `margem_pct_anterior` passou a ser a
        // margem da janela comparada (27.47), nunca mais o `prev` nativo
        // (24.08), que aponta para outra janela. A distinção entre as duas
        // janelas é provada em V18\AdmanMetricDiffServiceTest, onde o fake
        // responde por range.
        $this->assertSame(27.47, $linha->margem_pct_atual);
        $this->assertSame(27.47, $linha->margem_pct_anterior);
        $this->assertNotSame(24.08, $linha->margem_pct_anterior);
        $this->assertSame(0.0, $linha->margem_var_pp);
        $this->assertSame(3.0, $linha->margem_pontos);
        $this->assertNotContains('margem_pp_indisponivel', $linha->quality['motivos']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_regua_de_margem_nunca_recebe_diff_pct(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        // Mesma fixture MPP-06: se a régua recebesse diff_pct (14.09) em vez
        // de diff_pp (3.39), o resultado seria 5.0 — este teste prova que
        // NUNCA é 5.0 na fixture MPP-06 em janela igual.
        $cenario = $this->montarCenario('CUST-MARGEM-04');
        $this->fixarNps($cenario['empresa']->id, 4.6);
        $this->fakeAdmanEndpoints();

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotSame(5.0, $linha->margem_pontos, 'reguaMargem(diff_pct=14.09) seria 5.0 — a linha NÃO pode reportar isso.');
        $this->assertSame(4.0, $linha->margem_pontos, 'reguaMargem(diff_pp=3.39) é o único caminho correto — 4.0.');

        $this->assertHashDesempenhoScoreServiceIntocado();
    }
}
