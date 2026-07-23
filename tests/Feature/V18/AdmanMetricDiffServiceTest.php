<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use App\Services\Metrics\AdmanMetricDiffService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 101 Plan 01 — AdmanMetricDiffService (v18.0).
 *
 * Cobre ADM-01/02/03/05: leitura AO VIVO do diff de período pronto da Adman
 * (`.diff`), gate por `comparison_mode`, e o `calculated_fallback` com os
 * guards já cicatrizados por 3 rounds de bugs de produção (margem_dias +
 * interseção de dias-comuns).
 *
 * Fixtures = payloads REAIS capturados no research (empresa id 242, range
 * 2026-07-01..2026-07-18) — ver 101-RESEARCH.md.
 *
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-01-PLAN.md
 * @see .planning/phases/101-admanmetricdiffservice-v18-0/101-RESEARCH.md
 */
class AdmanMetricDiffServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Payload real de /performance/{custId} (summarizedData) — research §41-63.
     */
    private function respostaPerformance(): array
    {
        return [
            'summarizedData' => [
                'grossBilling'  => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'profitMargin'  => ['value' => 141428.81, 'diff' => 147.75, 'prev' => 57084.83],
                'investment'    => ['value' => 9990.82,   'diff' => 81.96,  'prev' => 5490.65],
                'profitShare'   => 26.64,
            ],
            'items' => [],
        ];
    }

    /**
     * Payload real de /accounts/{custId}/metrics (metrics) — research §67-81.
     */
    private function respostaAccountMetrics(): array
    {
        return [
            'metrics' => [
                'billing'           => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                'liquidMargin'      => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                'percentageMargin'  => ['value' => 27.47,     'diff' => 14.09,  'prev' => 24.08],
                'investment'        => ['value' => 9990.82,   'diff' => 80.36,  'prev' => 5539.43],
            ],
        ];
    }

    // ─────────────────────── Task 1 — leitura detalhada aditiva ───────────────────────

    /**
     * fetchAccountMetricsDetailedCached preserva value+diff+prev por campo —
     * com o fixture real, percentageMargin => value 27.47, diff 14.09, prev 24.08.
     */
    public function test_fetch_account_metrics_detailed_cached_preserva_value_diff_prev(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);

        $service = new AdmanService();
        $detalhado = $service->fetchAccountMetricsDetailedCached('CUST1', '2026-07-01', '2026-07-18');

        $this->assertNotNull($detalhado);
        $this->assertSame(27.47, $detalhado['percentageMargin']['value']);
        $this->assertSame(14.09, $detalhado['percentageMargin']['diff']);
        $this->assertSame(24.08, $detalhado['percentageMargin']['prev']);
    }

    /**
     * REGRESSÃO: fetchAccountMetricsCached continua retornando o shape
     * simplificado {acos,tacos,investment,liquid_margin,percentage_margin,billing}
     * só com floats — inalterado (mesmo fixture).
     */
    public function test_fetch_account_metrics_cached_simplificado_permanece_inalterado(): void
    {
        Http::fake([
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);

        $service = new AdmanService();
        $simples = $service->fetchAccountMetricsCached('CUST1', '2026-07-01', '2026-07-18');

        $this->assertNotNull($simples);
        $this->assertSame(
            ['acos', 'tacos', 'investment', 'liquid_margin', 'percentage_margin', 'billing'],
            array_keys($simples)
        );
        $this->assertSame(530797.73, $simples['billing']);
        $this->assertSame(27.47, $simples['percentage_margin']);
        $this->assertSame(141428.81, $simples['liquid_margin']);
    }

    // ─────────────────────── Task 2 — AdmanMetricDiffService::compute ───────────────────────

    /**
     * Fake dos 2 endpoints Adman consumidos por compute() com os fixtures reais.
     * `$percentageMarginDiff` permite simular payload SEM .diff (cenário c).
     */
    private function fakeAdmanEndpoints(?float $percentageMarginDiff = 14.09): void
    {
        $accountMetrics = $this->respostaAccountMetrics();
        if ($percentageMarginDiff === null) {
            unset($accountMetrics['metrics']['percentageMargin']['diff']);
        } else {
            $accountMetrics['metrics']['percentageMargin']['diff'] = $percentageMarginDiff;
        }

        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($accountMetrics, 200),
        ]);
    }

    /**
     * Período `previous_equal_length_window` — mesma janela do research (18 dias):
     * current 2026-07-01..18, baseline 2026-06-13..30 (N dias imediatamente
     * anteriores, verificado empiricamente no research §93-97).
     */
    private function periodoJanelaIgual(): array
    {
        return [
            'mode'                   => 'closed_period',
            'period_key'             => 'custom',
            'current_start'          => '2026-07-01',
            'current_end'            => '2026-07-18',
            'baseline_start'         => '2026-06-13',
            'baseline_end'           => '2026-06-30',
            'days_count'             => 18,
            'comparison_mode'        => 'previous_equal_length_window',
            'timezone'               => 'America/Sao_Paulo',
            'data_fresh_until'       => '2026-07-18',
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => false,
            'is_closed'              => true,
        ];
    }

    /**
     * Período `same_interval_previous_month` — mesmo range de 18 dias, mas
     * baseline alinhado por dia-do-mês-anterior (modo operacional/mês em curso).
     */
    private function periodoOperacional(): array
    {
        return [
            'mode'                   => 'operational',
            'period_key'             => 'current_month',
            'current_start'          => '2026-07-01',
            'current_end'            => '2026-07-18',
            'baseline_start'         => '2026-06-01',
            'baseline_end'           => '2026-06-18',
            'days_count'             => 18,
            'comparison_mode'        => 'same_interval_previous_month',
            'timezone'               => 'America/Sao_Paulo',
            'data_fresh_until'       => '2026-07-18',
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => true,
            'is_closed'              => false,
        ];
    }

    /** Semeia AdmanMetric diário para uma empresa num range, com revenue/margem fixos. */
    private function semearAdmanMetric(Company $company, string $inicio, string $fim, ?float $revenue, ?float $margem): void
    {
        $cursor = Carbon::parse($inicio);
        $fimDate = Carbon::parse($fim);
        while ($cursor->lte($fimDate)) {
            AdmanMetric::create([
                'company_id'              => $company->id,
                'reference_date'          => $cursor->toDateString(),
                'revenue'                 => $revenue,
                'contribution_margin'     => $margem,
                'contribution_margin_pct' => ($revenue !== null && $revenue > 0 && $margem !== null)
                    ? round(($margem / $revenue) * 100, 4)
                    : null,
            ]);
            $cursor->addDay();
        }
    }

    /**
     * Período `previous_equal_length_window` — janela de 30 dias (calendário
     * cheio) pra testar a distinção dias-com-linha vs dias-calendário: current
     * 2026-07-01..30 (31 dias? não — 30), baseline 2026-06-01..30 (30 dias,
     * junho tem exatamente 30).
     */
    private function periodoJanelaIgual30Dias(): array
    {
        return [
            'mode'                   => 'closed_period',
            'period_key'             => 'custom',
            'current_start'          => '2026-07-01',
            'current_end'            => '2026-07-30',
            'baseline_start'         => '2026-06-01',
            'baseline_end'           => '2026-06-30',
            'days_count'             => 30,
            'comparison_mode'        => 'previous_equal_length_window',
            'timezone'               => 'America/Sao_Paulo',
            'data_fresh_until'       => '2026-07-30',
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => false,
            'is_closed'              => true,
        ];
    }

    /**
     * Semeia AdmanMetric em DIAS ALTERNADOS (offsets pares) dentro de um range
     * de `$diasTotais` dias-calendário — simula empresa que só vende em PARTE
     * do mês (fecha fim de semana, entrou no meio do mês etc.), mas 100%
     * SINCRONIZADA nos dias em que efetivamente vendeu (linha sempre com
     * `contribution_margin` não-nulo).
     */
    private function semearAdmanMetricAlternado(Company $company, string $inicio, int $diasTotais, float $revenue, float $margem): void
    {
        $inicioDate = Carbon::parse($inicio);
        for ($i = 0; $i < $diasTotais; $i += 2) {
            AdmanMetric::create([
                'company_id'              => $company->id,
                'reference_date'          => $inicioDate->copy()->addDays($i)->toDateString(),
                'revenue'                 => $revenue,
                'contribution_margin'     => $margem,
                'contribution_margin_pct' => round(($margem / $revenue) * 100, 4),
            ]);
        }
    }

    /**
     * Semeia `revenue` em TODOS os dias do range (dias-com-linha = 100% via
     * revenue), mas `contribution_margin` só nos primeiros `$diasComMargem`
     * dias — simula falha REAL de sincronização de margem (não ausência de
     * venda): a empresa vendeu todo dia, mas a margem só chegou em poucos.
     */
    private function semearRevenueTodoDiaMargemParcial(Company $company, string $inicio, int $diasTotais, float $revenue, int $diasComMargem): void
    {
        $inicioDate = Carbon::parse($inicio);
        for ($i = 0; $i < $diasTotais; $i++) {
            $temMargem = $i < $diasComMargem;
            AdmanMetric::create([
                'company_id'              => $company->id,
                'reference_date'          => $inicioDate->copy()->addDays($i)->toDateString(),
                'revenue'                 => $revenue,
                'contribution_margin'     => $temMargem ? 200.0 : null,
                'contribution_margin_pct' => $temMargem ? round((200.0 / $revenue) * 100, 4) : null,
            ]);
        }
    }

    /**
     * CENÁRIO (a) — janela-igual (`previous_equal_length_window`) com .diff
     * presente ⇒ diff_source='adman_diff', diff_pct=14.09 (percentageMargin.diff).
     */
    public function test_a_janela_igual_usa_adman_diff(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame(14.09, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertSame('adman_diff', $resultado['metrics']['revenue']['diff_source']);
        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_value']['diff_source']);
    }

    /**
     * CENÁRIO (b) — modo operacional (`same_interval_previous_month`) força
     * calculated_fallback MESMO com a Adman tendo mandado .diff.
     */
    public function test_b_modo_operacional_forca_calculated_fallback_mesmo_com_diff_adman(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Dados locais consistentes nas duas janelas (07-01..18 vs 06-01..18) —
        // prova que o fallback de fato CALCULA, não só rotula.
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-01', '2026-06-18', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());

        $this->assertSame('calculated_fallback', $resultado['metrics']['revenue']['diff_source']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_value']['diff_source']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        // revenue: (18000-9000)/9000*100 = 100%
        $this->assertSame(100.0, $resultado['metrics']['revenue']['diff_pct']);
    }

    /**
     * CENÁRIO (c) — janela-igual mas Adman NÃO devolveu .diff (payload só
     * value) ⇒ calculated_fallback (mesmo em previous_equal_length_window).
     */
    public function test_c_janela_igual_sem_diff_da_adman_usa_fallback(): void
    {
        $this->fakeAdmanEndpoints(percentageMarginDiff: null);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertNotNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
    }

    /**
     * CENÁRIO (d) — ADM-05: Margem R$ (profitMargin) e Margem % (percentageMargin)
     * em chaves distintas, nunca cruzadas (profitMargin.value != liquidMargin.value
     * neste fixture seria indistinguível — o teste prova que o service usa o
     * campo certo por endpoint).
     */
    public function test_d_margem_rs_e_pct_em_chaves_distintas(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame(141428.81, $resultado['metrics']['contribution_margin_value']['value']);
        $this->assertSame(27.47, $resultado['metrics']['contribution_margin_pct']['value']);
    }

    /**
     * CENÁRIO (e) — shape completo: company_id, period (objeto inteiro),
     * metrics.{revenue,contribution_margin_value,contribution_margin_pct},
     * quality{status,source,computed_at}. quality.status='complete' quando os
     * 3 metrics têm value.
     */
    public function test_e_shape_e_quality_completos(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        $periodo = $this->periodoJanelaIgual();

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $periodo);

        $this->assertSame($company->id, $resultado['company_id']);
        $this->assertSame($periodo, $resultado['period']);
        $this->assertSame(
            ['revenue', 'contribution_margin_value', 'contribution_margin_pct'],
            array_keys($resultado['metrics'])
        );
        foreach ($resultado['metrics'] as $metric) {
            $this->assertSame(['value', 'diff_pct', 'diff_source'], array_keys($metric));
        }
        $this->assertSame('complete', $resultado['quality']['status']);
        $this->assertSame('adman', $resultado['quality']['source']);
        $this->assertArrayHasKey('computed_at', $resultado['quality']);
    }

    /**
     * CENÁRIO (f) — GAP DE SYNC PARCIAL (espelha audit-margem-luiz-ana.md):
     * empresa ML-driven com contribution_margin populado no baseline (junho
     * completo) mas NULL na janela atual (julho) inteira. Sem os guards
     * cicatrizados, isso daria -100% ARTIFICIAL. Com os guards, diff_pct=null
     * (margem_dias(atual)==0). revenue CONTINUA calculando normalmente (o gap
     * é só de margem, não de faturamento — igual ao caso real).
     */
    public function test_f_gap_de_sync_parcial_nao_produz_variacao_artificial(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Baseline (junho): revenue + margem populados normalmente.
        $this->semearAdmanMetric($company, '2026-06-01', '2026-06-18', 1000.0, 200.0);
        // Atual (julho, ML-driven): revenue populado, margem NULL (API ML não
        // expõe CMV — exatamente o cenário do audit-margem-luiz-ana.md).
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1100.0, null);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());

        // Guard margem_dias: atual tem ZERO dias com contribution_margin não-null.
        $this->assertNull($resultado['metrics']['contribution_margin_value']['diff_pct']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_value']['diff_source']);

        // Sem os guards: SUM(atual)=0 vs SUM(baseline)=3600 => -100% artificial.
        $this->assertNotEquals(-100.0, $resultado['metrics']['contribution_margin_value']['diff_pct']);

        // Revenue NÃO é afetado pelo gap de margem — continua calculando.
        $this->assertNotNull($resultado['metrics']['revenue']['diff_pct']);
        // (19800-18000)/18000*100 = 10%
        $this->assertSame(10.0, $resultado['metrics']['revenue']['diff_pct']);
    }

    // ─────────────── Fase 110 (FIXMARG-01/02) — prioridade local + gate de cobertura ───────────────

    /**
     * CENÁRIO (g) — cobertura local suficiente (100% dos dias-com-linha nas
     * duas janelas com contribution_margin não-nulo) PREFERE o local
     * determinístico, mesmo com o .diff nativo presente e DIFERENTE.
     */
    public function test_g_cobertura_suficiente_prefere_local_mesmo_com_adman_diff_presente(): void
    {
        $this->fakeAdmanEndpoints(); // percentageMargin.diff = 14.09 (ao vivo)
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Cobertura 100% nas duas janelas (18/18 dias-com-linha, margem sempre
        // não-nula) — local: pct(20%) vs pct(20%) => diff 0.0, DIFERENTE do
        // .diff nativo (14.09).
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame(0.0, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertNotEquals(14.09, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
    }

    /**
     * CENÁRIO (h) — determinismo: 3 chamadas de compute() com o .diff AO VIVO
     * oscilando a cada chamada (simula rate-limit 429 concorrente — valores
     * reais do trigger do debug: +6,83/-3,25/+8,63 refletidos aqui como
     * 69.3/-44.4/8.63) devolvem o MESMO diff_pct (vem do local). Cache
     * EXTERNO (diff service) e INTERNO (AdmanService) limpos entre chamadas
     * pra provar determinismo real, não cache mascarando.
     */
    public function test_h_recompute_repetido_com_ao_vivo_oscilando_e_deterministico(): void
    {
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $periodo    = $this->periodoJanelaIgual();
        $resultados = [];

        foreach ([69.3, -44.4, 8.63] as $diffOscilante) {
            Cache::flush();
            $accountMetrics = $this->respostaAccountMetrics();
            $accountMetrics['metrics']['percentageMargin']['diff'] = $diffOscilante;
            Http::fake([
                '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
                '*/accounts/*/metrics*' => Http::response($accountMetrics, 200),
            ]);

            $resultado    = app(AdmanMetricDiffService::class)->compute($company, $periodo);
            $resultados[] = $resultado['metrics']['contribution_margin_pct']['diff_pct'];
        }

        $this->assertCount(1, array_unique($resultados),
            'diff_pct deve ser IDÊNTICO nas 3 chamadas (vem do local determinístico, não do ao-vivo oscilante)');
        $this->assertSame(0.0, $resultados[0]);
    }

    /**
     * CENÁRIO (i) — cobertura é medida por DIAS-COM-LINHA, não dias-calendário.
     * Empresa que só vende em dias alternados (15 de 30 dias-calendário) mas
     * 100% sincronizada NESSES dias-com-linha usa o local. Se o denominador
     * fosse dias-calendário (diffInDays=30), a cobertura cairia pra ~50% e
     * recusaria o local indevidamente.
     */
    public function test_i_cobertura_por_dias_com_linha_nao_por_calendario(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetricAlternado($company, '2026-07-01', 30, 1000.0, 200.0);
        $this->semearAdmanMetricAlternado($company, '2026-06-01', 30, 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual30Dias());

        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertNotNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
    }

    /**
     * CENÁRIO (j) — cobertura INSUFICIENTE de verdade: empresa vendeu (tem
     * linha/revenue) todo dia da janela current (30 dias-com-linha), mas a
     * margem só sincronizou em 1 desses dias (falha REAL de sync de margem,
     * não ausência de venda). Cobertura=1/30 (3,3%) < 80% => cai pro .diff
     * nativo ao vivo (último recurso).
     */
    public function test_j_cobertura_insuficiente_real_cai_para_ao_vivo(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearRevenueTodoDiaMargemParcial($company, '2026-07-01', 30, 1000.0, diasComMargem: 1);
        $this->semearAdmanMetric($company, '2026-06-01', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual30Dias());

        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame(14.09, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
    }

    /**
     * CENÁRIO (k) — empresa SEM nenhuma linha AdmanMetric (0/0, sem venda)
     * E ao-vivo indisponível (percentageMargin ausente do payload) => null
     * EXPLÍCITO (FIXMARG-02, sem fail-open artificial).
     */
    public function test_k_empresa_sem_linha_e_ao_vivo_indisponivel_da_null_explicito(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response([
                'metrics' => [
                    'billing'      => ['value' => 0, 'diff' => null, 'prev' => null],
                    'liquidMargin' => ['value' => 0, 'diff' => null, 'prev' => null],
                    // percentageMargin AUSENTE — ao-vivo indisponível.
                ],
            ], 200),
        ]);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        // Sem NENHUMA linha AdmanMetric — empresa 0/0 (sem venda/teste).

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
    }

    /**
     * CENÁRIO (l) — REGRESSÃO: mesmo com a margem % preferindo o local
     * (cobertura 100%), `revenue` e `contribution_margin_value` CONTINUAM
     * resolvendo via `resolveField()` original (.diff nativo quando
     * isJanelaIgual) — escopo estrito, sem regressão de faturamento/carteira.
     */
    public function test_l_revenue_continua_adman_diff_mesmo_com_margem_local_preferida(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame('adman_diff', $resultado['metrics']['revenue']['diff_source']);
        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_value']['diff_source']);
    }
}
