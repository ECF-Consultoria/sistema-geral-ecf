<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fase 102 Plan 01 (v18.0) — `DesempenhoScoreService` passa a consumir o
 * `MetricPeriodResolver` (Fase 100, janelas) e o `AdmanMetricDiffService`
 * (Fase 101, variação de margem pronta da Adman).
 *
 * Cobre:
 *  - BON-02 — `computeOficial(User)` resolve o último mês FECHADO (julho
 *    paga a competência junho, janela 01/06..30/06 vs baseline
 *    janela-de-mesmo-tamanho 02/05..31/05).
 *  - BON-01 — `resolvePeriodo()` (privado) usa `current_month` quando o mês
 *    de referência é o mês corrente e `YYYY-MM` (closed_period) caso
 *    contrário — zero `now()`/`startOfMonth()`/`subMonth()` inline pra
 *    definir período de comparação. `var_faturamento_pct` passa a somar
 *    sobre as janelas do `$periodo`.
 *  - BON-03 — `var_margem_pct` delega a `AdmanMetricDiffService::compute()`:
 *    gate `adman_diff` (janela-de-mesmo-tamanho + `.diff` presente) vs
 *    `calculated_fallback` (sem `.diff` — lê `AdmanMetric` local).
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO (fix plan-checker — BLOCKER do 102-01-PLAN.md):
 * `Http::preventStrayRequests()` no setUp — nenhum teste desta suíte pode
 * disparar requisição REAL à Adman. Só os 2 testes de gate (
 * `test_var_margem_pct_usa_adman_diff_...` /
 * `test_var_margem_pct_cai_no_calculated_fallback_...`) variam o payload
 * `.diff`; os demais usam sempre o fake "sem diff" (`fakeAdmanSemDiff()`),
 * forçando `calculated_fallback` determinístico sobre o fixture local.
 *
 * @see .planning/phases/102-desempenho-oficial-por-competencia-v18-0/102-01-PLAN.md
 * @see .planning/phases/102-desempenho-oficial-por-competencia-v18-0/102-RESEARCH.md Pitfall 1
 */
class DesempenhoPeriodoOficialTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // ISOLAMENTO HTTP OBRIGATÓRIO — nenhuma requisição real à Adman.
        Http::preventStrayRequests();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-102-01',
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

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarUserAnalista(string $nome = 'Analista V18'): User
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

    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture V18)',
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

    /**
     * Empresa elegível financeiramente (vínculo de serviço Performance ativo)
     * com `adman_account_id` fixo — necessário pro `AdmanMetricDiffService`
     * conseguir montar a chamada/chave de cache (custId vazio = early-return
     * `emptyMetrics()`, sem HTTP nenhum).
     */
    private function criarEmpresaElegivel(User $user, string $custId, string $createdAt = '-6 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create(['adman_account_id' => $custId, 'marketplace' => 'meli']);
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $servicoPerfId = $this->servicoPerformanceId();

        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoPerfId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $servicoPerfId,
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $company->fresh();
    }

    /**
     * Fixture DENSA — 1 row `AdmanMetric` por DIA cobrindo `[$inicio,$fim]`
     * (inclusive). Pitfall 1 do 102-RESEARCH.md: sob a janela-de-mesmo-
     * tamanho, a baseline de mês fechado NÃO começa mais no dia 1 — fixture
     * esparso ("1 linha no dia 15") cai na interseção vazia de dias-comuns
     * do `AdmanMetricDiffService`.
     */
    private function semearDiario(Company $c, string $inicio, string $fim, float $revenue, ?float $margem): void
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

    /** Fake dos 2 endpoints Adman SEM `.diff` — força `calculated_fallback`. */
    private function fakeAdmanSemDiff(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);
    }

    /** Fake dos 2 endpoints Adman com `.diff` presente na margem % (gate BON-03). */
    private function fakeAdmanComDiffMargem(float $diffPct): void
    {
        Http::fake([
            '*/performance/*'       => Http::response(['summarizedData' => []], 200),
            '*/accounts/*/metrics*' => Http::response([
                'metrics' => [
                    'percentageMargin' => ['value' => 27.0, 'diff' => $diffPct, 'prev' => 20.0],
                ],
            ], 200),
        ]);
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    // ─── BON-02 · computeOficial resolve o último mês fechado ───────────────

    #[Test]
    public function test_compute_oficial_resolve_competencia_junho_2026_em_julho(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $u = $this->criarUserAnalista('Oficial Sem Carteira');
        $service = app(DesempenhoScoreService::class);

        $r = $service->computeOficial($u);

        // BON-02: em julho/2026, a competência oficial de bônus é junho/2026
        // (mês fechado anterior) — o `$mes` interno de computeOficial() vem
        // de `periodo['current_start']`.
        $this->assertSame('2026-06-01', $r['mes_referencia'],
            'BON-02: em julho/2026, computeOficial resolve a competência jun/2026 (mês fechado anterior).');
    }

    #[Test]
    public function test_resolve_last_closed_month_produz_janelas_da_regua_nova(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $periodoOficial = app(MetricPeriodResolver::class)->resolve(['period_key' => 'last_closed_month']);

        // Conta (BON-02): current = jun/2026 inteiro (30 dias). baseline = 30
        // dias imediatamente anteriores a 01/06 → baseline_end=31/05,
        // baseline_start = 31/05 - 29 dias = 02/05.
        $this->assertSame('2026-06-01', $periodoOficial['current_start']);
        $this->assertSame('2026-06-30', $periodoOficial['current_end']);
        $this->assertSame('2026-05-02', $periodoOficial['baseline_start']);
        $this->assertSame('2026-05-31', $periodoOficial['baseline_end']);
        $this->assertSame('official_bonus', $periodoOficial['mode']);
        $this->assertSame('previous_equal_length_window', $periodoOficial['comparison_mode']);
        $this->assertSame('2026-06', $periodoOficial['bonus_competence_month']);
        $this->assertSame('2026-07', $periodoOficial['bonus_payment_month']);

        // compute($u, junho) SEM override resolve pra period_key='2026-06'
        // (closed_period) — MESMAS janelas de current/baseline que
        // last_closed_month (só o metadado bonus_* difere — null aqui).
        $service = app(DesempenhoScoreService::class);
        $ref = new ReflectionMethod(DesempenhoScoreService::class, 'resolvePeriodo');
        $ref->setAccessible(true);
        $periodoGenerico = $ref->invoke($service, Carbon::parse('2026-06-01'));

        $this->assertSame($periodoOficial['current_start'], $periodoGenerico['current_start']);
        $this->assertSame($periodoOficial['current_end'], $periodoGenerico['current_end']);
        $this->assertSame($periodoOficial['baseline_start'], $periodoGenerico['baseline_start']);
        $this->assertSame($periodoOficial['baseline_end'], $periodoGenerico['baseline_end']);
        $this->assertSame('closed_period', $periodoGenerico['mode']);
        $this->assertSame('2026-06', $periodoGenerico['period_key']);
        $this->assertNull($periodoGenerico['bonus_competence_month'],
            'closed_period (mês específico) NÃO popula bonus_* — só last_closed_month faz.');
    }

    #[Test]
    public function test_resolve_periodo_mes_corrente_usa_current_month_operacional(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $service = app(DesempenhoScoreService::class);
        $ref = new ReflectionMethod(DesempenhoScoreService::class, 'resolvePeriodo');
        $ref->setAccessible(true);

        // compute($u, julho) com "agora" = 20/07 → julho é o mês CORRENTE.
        $periodo = $ref->invoke($service, Carbon::parse('2026-07-01'));

        $this->assertSame('current_month', $periodo['period_key']);
        $this->assertSame('operational', $periodo['mode']);
        $this->assertSame('same_interval_previous_month', $periodo['comparison_mode']);
    }

    // ─── BON-03 · gate adman_diff vs calculated_fallback (var_margem_pct) ───

    #[Test]
    public function test_var_margem_pct_usa_adman_diff_quando_janela_igual_e_diff_presente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanComDiffMargem(diffPct: 12.34);

        $u = $this->criarUserAnalista('Gate Adman Diff');
        $this->criarEmpresaElegivel($u, 'CUST-GATE-1');

        $service = app(DesempenhoScoreService::class);
        // Mês fechado (junho, via period_key='2026-06') → comparison_mode=
        // previous_equal_length_window → gate ligado.
        $r = $service->compute($u, Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(12.34, $r['componentes']['var_margem_pct'], 0.001,
            'BON-03: com .diff presente E janela-de-mesmo-tamanho, var_margem_pct usa o diff nativo da Adman.');
    }

    #[Test]
    public function test_var_margem_pct_cai_no_calculated_fallback_quando_diff_ausente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $u = $this->criarUserAnalista('Gate Fallback');
        $c = $this->criarEmpresaElegivel($u, 'CUST-GATE-2');

        // Fixture DENSA cobrindo current (01..30/06) e baseline (02..31/05) —
        // margem baseline 25%, margem atual 30% → diff = +20,00%.
        $this->semearDiario($c, '2026-06-01', '2026-06-30', revenue: 1000.0, margem: 300.0); // 30,00%
        $this->semearDiario($c, '2026-05-02', '2026-05-31', revenue: 1000.0, margem: 250.0); // 25,00%

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-06-01'));

        // pctAtual = SUM(300*30)/SUM(1000*30)*100 = 30,00%.
        // pctAnterior = SUM(250*30)/SUM(1000*30)*100 = 25,00%.
        // diff = (30,00-25,00)/25,00*100 = +20,00%.
        $this->assertEqualsWithDelta(20.00, $r['componentes']['var_margem_pct'], 0.01,
            'Sem .diff nativo, cai no calculated_fallback (SUM margem / SUM revenue por janela, depois a variação %).');
    }

    // ─── BON-01 · var_faturamento_pct usa as janelas do resolver ────────────

    #[Test]
    public function test_var_faturamento_pct_media_por_janela_do_resolver_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $u  = $this->criarUserAnalista('Var Fat Janela');
        $c1 = $this->criarEmpresaElegivel($u, 'CUST-FAT-1');
        $c2 = $this->criarEmpresaElegivel($u, 'CUST-FAT-2');
        // Empresa nova (dentro dos 2 meses) — deve ser EXCLUÍDA (DESEMP-04, preservado).
        $cNova = $this->criarEmpresaElegivel($u, 'CUST-FAT-NOVA', createdAt: '-10 days');

        // C1: current 1000/dia, baseline 900/dia → (30000-27000)/27000*100 = +11,1111...%
        $this->semearDiario($c1, '2026-06-01', '2026-06-30', revenue: 1000.0, margem: null);
        $this->semearDiario($c1, '2026-05-02', '2026-05-31', revenue: 900.0, margem: null);
        // C2: current 1200/dia, baseline 1000/dia → (36000-30000)/30000*100 = +20,00%
        $this->semearDiario($c2, '2026-06-01', '2026-06-30', revenue: 1200.0, margem: null);
        $this->semearDiario($c2, '2026-05-02', '2026-05-31', revenue: 1000.0, margem: null);
        // Nova: delta absurdo — se entrasse na média, distorceria muito.
        $this->semearDiario($cNova, '2026-06-01', '2026-06-30', revenue: 100000.0, margem: null);
        $this->semearDiario($cNova, '2026-05-02', '2026-05-31', revenue: 1.0, margem: null);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-06-01'));

        // Média (+11,1111%, +20,00%) = 15,5556% → round(2) = 15,56.
        $this->assertEqualsWithDelta(15.56, $r['componentes']['var_faturamento_pct'], 0.01,
            'BON-01: var_faturamento_pct usa a janela-de-mesmo-tamanho do resolver — empresa nova excluída.');
        $this->assertSame(2, $r['empresas_com_baseline']);
    }
}
