<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 103 Plan 01 (v18.0) — `renderCarteiraProfissional()` passa a resolver
 * período via `MetricPeriodResolver` (Fase 100) e a variação de margem por
 * empresa via `AdmanMetricDiffService` (Fase 101) — mesmo transplante que a
 * Fase 102 já fez no `DesempenhoScoreService`.
 *
 * Cobre:
 *  - CAR-01/CAR-03 — payload.periodo expõe current_start/current_end/
 *    baseline_start/baseline_end/mode/comparison_mode do resolver, tanto no
 *    mês em curso quanto no mês fechado.
 *  - CAR-01 (Pitfall 2 do 103-RESEARCH.md) — mês fechado usa baseline
 *    JANELA-DE-MESMO-TAMANHO (N dias imediatamente anteriores ao início do
 *    mês selecionado), NÃO o mês calendário anterior completo.
 *  - CAR-02 (Pitfall 1 do 103-RESEARCH.md, o CERNE da fase) —
 *    `margem_variacao_pct` lê `contribution_margin_value.diff_pct`
 *    (profitMargin.diff), NUNCA `contribution_margin_pct` (percentageMargin.diff
 *    — usado pela Fase 102 no score, métrica DIFERENTE).
 *  - CAR-02 — mês em curso permanece BYTE-IDÊNTICO ao comportamento anterior
 *    (comparison_mode=same_interval_previous_month ⇒ gate desligado ⇒ sempre
 *    calculated_fallback, mesma soma+dias-comuns de antes da migração).
 *  - CAR-02 — elegibilidade financeira v17 preservada: empresa Shopee sem
 *    contrato Performance não entra (margem/variação null), e o diff service
 *    NUNCA é chamado pra ela.
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO (decisão travada 6 do 103-01-PLAN.md):
 * `Http::preventStrayRequests()` no setUp — nenhum teste desta suíte pode
 * disparar requisição REAL à Adman. `AdmanMetricDiffService::compute()`
 * agora é chamado por empresa elegível dentro de `renderCarteiraProfissional`
 * (antes só se lia `getCachedGrossBillingsMany`/`getCachedAccountMetricsMany`,
 * que são cache-only e nunca tocam rede).
 *
 * @see .planning/phases/103-carteira-por-periodo-v18-0/103-01-PLAN.md
 * @see .planning/phases/103-carteira-por-periodo-v18-0/103-RESEARCH.md Pitfall 1/2
 */
class CarteiraPeriodoDiffTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // ISOLAMENTO HTTP OBRIGATÓRIO — nenhuma requisição real à Adman.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        }

        return $this->servicoPerfId;
    }

    /**
     * Empresa elegível financeiramente (vínculo de serviço Performance ativo)
     * com `adman_account_id` fixo — necessário pro `AdmanMetricDiffService`
     * conseguir montar a chamada/chave de cache (custId vazio = early-return
     * `emptyMetrics()`, sem HTTP nenhum).
     */
    private function criarEmpresaElegivel(User $user, string $custId): Company
    {
        $company = Company::factory()->create(['adman_account_id' => $custId, 'marketplace' => 'meli']);
        $servicoPerfId = $this->servicoPerformanceId();

        $this->criarContrato($company->id, $servicoPerfId, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerfId);

        return $company->fresh();
    }

    /**
     * Fixture DENSA — 1 row `AdmanMetric` por DIA cobrindo `[$inicio,$fim]`
     * (inclusive). Pitfall 2 do 103-RESEARCH.md: sob a janela-de-mesmo-
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

    /**
     * Fake dos 2 endpoints Adman com `.diff` presente EM AMBOS os campos de
     * margem (profitMargin no endpoint de performance, percentageMargin no
     * endpoint de account metrics), com valores DISTINTOS — prova a escolha
     * de campo do Pitfall 1 (Teste C).
     */
    private function fakeAdmanComDiff(float $liquidMarginDiff, float $percentageMarginDiff): void
    {
        Http::fake([
            '*/performance/*' => Http::response([
                'summarizedData' => [
                    'grossBilling' => ['value' => 1000.0, 'diff' => 5.0, 'prev' => 950.0],
                    'profitMargin' => ['value' => 300.0, 'diff' => 42.0, 'prev' => 260.0],
                ],
            ], 200),
            // contribution_margin_value = liquidMargin (endpoint detalhado) desde
            // 2026-07-22 — NÃO mais profitMargin do performance.
            '*/accounts/*/metrics*' => Http::response([
                'metrics' => [
                    'liquidMargin'     => ['value' => 300.0, 'diff' => $liquidMarginDiff, 'prev' => 260.0],
                    'percentageMargin' => ['value' => 27.0, 'diff' => $percentageMarginDiff, 'prev' => 20.0],
                ],
            ], 200),
        ]);
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    // ─── CAR-01/CAR-03 · payload.periodo expõe as 4 datas do resolver ───────

    #[Test]
    public function test_payload_periodo_expoe_datas_resolver_mes_em_curso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('2026-07-01', $props['periodo']['current_start']);
        $this->assertSame('2026-07-20', $props['periodo']['current_end']);
        $this->assertSame('2026-06-01', $props['periodo']['baseline_start']);
        $this->assertSame('2026-06-20', $props['periodo']['baseline_end']);
        $this->assertSame('operational', $props['periodo']['mode']);
        $this->assertSame('same_interval_previous_month', $props['periodo']['comparison_mode']);
        $this->assertTrue($props['periodo']['is_current_month']);
        $this->assertFalse($props['periodo']['is_closed']);
    }

    #[Test]
    public function test_payload_periodo_mes_fechado_usa_baseline_janela_mesmo_tamanho(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('portfolio.show', [$profissional, 'mes' => '2026-05']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // Trava o Pitfall 2: baseline NÃO é 01-30/04 (mês calendário anterior
        // inteiro) — é a janela-de-mesmo-tamanho, 31 dias imediatamente
        // anteriores a 01/05.
        $this->assertSame('2026-05-01', $props['periodo']['current_start']);
        $this->assertSame('2026-05-31', $props['periodo']['current_end']);
        $this->assertSame('2026-03-31', $props['periodo']['baseline_start']);
        $this->assertSame('2026-04-30', $props['periodo']['baseline_end']);
        $this->assertSame('closed_period', $props['periodo']['mode']);
        $this->assertSame('previous_equal_length_window', $props['periodo']['comparison_mode']);
    }

    // ─── CAR-02 (Pitfall 1) · contribution_margin_value, NÃO _pct ───────────

    #[Test]
    public function test_margem_variacao_pct_le_contribution_margin_value_nao_pct(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        // liquidMargin.diff=15.0 (contribution_margin_value) E
        // percentageMargin.diff=99.0 (contribution_margin_pct) — DISTINTOS.
        $this->fakeAdmanComDiff(liquidMarginDiff: 15.0, percentageMarginDiff: 99.0);

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $this->criarEmpresaElegivel($profissional, 'CUST-C-1');

        // Mês fechado → comparison_mode=previous_equal_length_window → gate
        // adman_diff ligado.
        $response = $this->actingAs($admin)->get(route('portfolio.show', [$profissional, 'mes' => '2026-05']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(15.0, $props['empresas'][0]['margem_variacao_pct'],
            'Deve ler contribution_margin_value.diff_pct (liquidMargin.diff=15.0), NUNCA contribution_margin_pct (percentageMargin.diff=99.0).');
    }

    #[Test]
    public function test_margem_variacao_pct_fallback_calculado_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = $this->criarEmpresaElegivel($profissional, 'CUST-D-1');

        // current 01-31/05 margem 300/dia (31 dias).
        $this->semearDiario($company, '2026-05-01', '2026-05-31', revenue: 1000.0, margem: 300.0);

        // baseline janela-de-mesmo-tamanho (31 dias) = 31/03..30/04. Dia
        // 31/03 é um OUTLIER (margem 1000) DELIBERADO: o código ANTIGO
        // (mês calendário anterior completo, só 01-30/04) nunca lê esse dia
        // — só o resolver novo inclui 31/03 na baseline. Isso garante RED
        // de verdade (valores diferentes entre antes/depois), não só troca
        // de fonte com resultado numérico coincidente.
        AdmanMetric::create([
            'company_id'          => $company->id,
            'reference_date'      => '2026-03-31',
            'revenue'             => 1000.0,
            'contribution_margin' => 1000.0,
        ]);
        $this->semearDiario($company, '2026-04-01', '2026-04-30', revenue: 1000.0, margem: 250.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', [$profissional, 'mes' => '2026-05']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // SUM margem atual = 31*300 = 9300.
        // SUM margem anterior (janela-de-mesmo-tamanho, 31/03 incluso) =
        // 1000 (31/03) + 30*250 (01-30/04) = 8500.
        // diff = (9300-8500)/8500*100 = +9,41%.
        $this->assertEqualsWithDelta(9.41, $props['empresas'][0]['margem_variacao_pct'], 0.01,
            'Sem .diff nativo, cai no calculated_fallback (SUM local via AdmanMetricDiffService) — inclui 31/03 na baseline (janela-de-mesmo-tamanho), diferente do mês calendário anterior antigo.');
    }

    // ─── CAR-02 · byte-idêntico no mês em curso ──────────────────────────────

    #[Test]
    public function test_variacao_margem_mes_em_curso_byte_identico_ao_calculo_manual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = $this->criarEmpresaElegivel($profissional, 'CUST-E-1');

        // Fixture DENSA sem gaps: 01-20/07 (atual) e 01-20/06 (baseline).
        $this->semearDiario($company, '2026-07-01', '2026-07-20', revenue: 1000.0, margem: 300.0);
        $this->semearDiario($company, '2026-06-01', '2026-06-20', revenue: 1000.0, margem: 240.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // atual=SUM margem 20 dias=6000; anterior=SUM margem 20 dias=4800.
        // (6000-4800)/4800*100 = +25,00%. current_month sempre cai em
        // calculated_fallback (comparison_mode=same_interval_previous_month
        // nunca satisfaz o gate adman_diff) — comportamento IDÊNTICO ao
        // bloco inline que existia antes da migração. O diff service usa
        // `whereDate()` (extrai a parte de data), então soma os 20 dias
        // corretamente mesmo no SQLite de teste.
        $this->assertEqualsWithDelta(25.00, $props['empresas'][0]['margem_variacao_pct'], 0.01);

        // total_faturamento vem de $atualPorEmpresa (bloco NÃO tocado por
        // esta fase — só a variação de margem troca de fonte). Essa query
        // usa `whereBetween()` plain contra a coluna `reference_date`
        // (cast 'date', mas persistida como 'Y-m-d 00:00:00'); no SQLite de
        // teste isso é comparação de STRING, e '...-20 00:00:00' > '...-20'
        // lexicograficamente — o último dia da janela cai fora do
        // whereBetween. Artefato PRÉ-EXISTENTE do ambiente de teste
        // (SQLite), não introduzido por esta fase, e não visível em
        // produção (MySQL/MariaDB tipam a coluna como DATE de verdade). O
        // valor abaixo é o comportamento REAL, idêntico antes/depois da
        // migração — é isso que "byte-idêntico" garante (mesmo número, não
        // o número teoricamente "correto" de uma query diferente).
        $this->assertSame(19000.0, $props['resumo']['total_faturamento'],
            'total_faturamento idêntico antes/depois — bloco $atualPorEmpresa não foi tocado por esta fase.');
        // 2026-07-22 (unificação margem %): o resumo passou a ler
        // `margem_pct_var_pct` (variação do percentageMargin, métrica do bônus),
        // não mais `margem_variacao_pct` (variação da margem R$). Aqui a receita
        // é CONSTANTE (1000/dia nas duas janelas), então a variação da margem %
        // e a da margem R$ coincidem em +25,00% — a troca de fonte não muda este
        // golden, mas o número agora reflete a margem %.
        $this->assertEqualsWithDelta(25.00, $props['resumo']['variacao_margem_pct'], 0.01,
            'variacao_margem_pct do resumo = média das variações de margem % por empresa.');
    }

    // ─── CAR-02 · elegibilidade v17 preservada (Shopee sem fonte) ────────────

    #[Test]
    public function test_empresa_shopee_sem_fonte_nao_aciona_diff_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        // NENHUM Http::fake() registrado — Http::preventStrayRequests() do
        // setUp lança exceção se QUALQUER requisição (real ou não) for
        // tentada. Este teste prova que o diff service NUNCA é chamado pra
        // uma empresa sem vínculo elegível (Shopee-only).

        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();

        $company = Company::factory()->create(['adman_account_id' => 'CUST-F-1']);
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $linha = $props['empresas'][0];
        $this->assertNull($linha['margem_contribuicao'], 'Vínculo Shopee-only não pode herdar margem ML.');
        $this->assertNull($linha['margem_variacao_pct'], 'Vínculo Shopee-only não pode ter variação de margem.');
        $this->assertFalse(
            collect($linha['servicos'])->first()['financial_metrics_eligible'],
            'Vínculo Shopee permanece financial_metrics_eligible=false (elegibilidade v17 preservada).'
        );
        $this->assertSame(0.0, $props['resumo']['total_faturamento']);
    }
}
