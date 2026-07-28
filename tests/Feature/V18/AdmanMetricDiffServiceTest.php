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
     * calculated_fallback para `revenue` MESMO com a Adman tendo mandado
     * .diff. Margem (R$ e %) NÃO usa mais fallback local (hotfix 2026-07-24):
     * fora da janela-igual o `.diff` nativo é ignorado (gate falha) e a
     * variação de margem fica `null` (`adman_indisponivel`), nunca calculada
     * localmente.
     */
    public function test_b_modo_operacional_forca_calculated_fallback_no_revenue_e_null_na_margem(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Dados locais consistentes nas duas janelas (07-01..18 vs 06-01..18) —
        // prova que o fallback de revenue de fato CALCULA, não só rotula.
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-01', '2026-06-18', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());

        $this->assertSame('calculated_fallback', $resultado['metrics']['revenue']['diff_source']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['contribution_margin_value']['diff_source']);
        $this->assertNull($resultado['metrics']['contribution_margin_value']['diff_pct']);
        $this->assertSame('adman_indisponivel', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
        // revenue: (18000-9000)/9000*100 = 100%
        $this->assertSame(100.0, $resultado['metrics']['revenue']['diff_pct']);
    }

    /**
     * CENÁRIO (c) — janela-igual mas Adman NÃO devolveu .diff (payload só
     * value) ⇒ `null` explícito (hotfix 2026-07-24: sem `.diff` nativo não há
     * mais fallback local pra margem — mesmo com dado local denso disponível).
     */
    public function test_c_janela_igual_sem_diff_da_adman_da_null_explicito(): void
    {
        $this->fakeAdmanEndpoints(percentageMarginDiff: null);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('adman_indisponivel', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
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
        // Fase 117 (MPP-01/02, D-06): shape deixou de ser uniforme — só
        // contribution_margin_pct ganha diff_pp (pp só faz sentido pra uma
        // métrica que já é percentual).
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['revenue']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_value']));
        $this->assertSame(['value', 'prev_value', 'diff_pct', 'diff_pp', 'diff_source'], array_keys($resultado['metrics']['contribution_margin_pct']));
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

    // ─────────────── Fase 110 (REVERTIDA pelo hotfix 2026-07-24) — native-first para margem ───────────────

    /**
     * CENÁRIO (g) — REGRESSÃO do hotfix: cobertura local 100% (dias-com-linha
     * nas duas janelas com contribution_margin não-nulo, que produziria um
     * pct local DIFERENTE do nativo) NÃO influencia mais a resolução — o
     * `.diff` nativo da Adman sempre vence quando presente em janela-igual,
     * mesmo com dado local denso disponível (reverte a preferência local da
     * Fase 110).
     */
    public function test_g_cobertura_local_densa_nao_muda_resultado_native_sempre_vence(): void
    {
        $this->fakeAdmanEndpoints(); // percentageMargin.diff = 14.09 (ao vivo)
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Cobertura 100% nas duas janelas (18/18 dias-com-linha, margem sempre
        // não-nula) — local produziria pct(20%) vs pct(20%) => diff 0.0,
        // DIFERENTE do .diff nativo (14.09). O resultado deve ser o nativo.
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame(14.09, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
        $this->assertNotEquals(0.0, $resultado['metrics']['contribution_margin_pct']['diff_pct']);
    }

    /**
     * CENÁRIO (h) — REGRESSÃO do hotfix: com margem 100% native-first, 3
     * chamadas de compute() com o .diff AO VIVO oscilando a cada chamada
     * (simula rate-limit 429 concorrente — valores reais do trigger do debug:
     * +6,83/-3,25/+8,63 refletidos aqui como 69.3/-44.4/8.63) devolvem CADA
     * UMA o valor nativo da vez — não há mais determinismo via local (essa
     * proteção foi intencionalmente removida: a Adman é a fonte da verdade,
     * mesmo quando ruidosa). Usa `Http::sequence()` (não chamadas repetidas de
     * `Http::fake()`, que EMPILHAM callbacks pro mesmo padrão de URL — o
     * primeiro registrado sempre venceria, mascarando o teste) pra simular as
     * 3 respostas em sequência. Cache EXTERNO (diff service) e INTERNO
     * (AdmanService) limpos entre chamadas pra provar que não há cache
     * mascarando a leitura ao vivo.
     */
    public function test_h_recompute_repetido_reflete_ao_vivo_oscilante_sem_mascarar(): void
    {
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $diffsOscilantes = [69.3, -44.4, 8.63];

        $sequence = Http::sequence();
        foreach ($diffsOscilantes as $diffOscilante) {
            $accountMetrics = $this->respostaAccountMetrics();
            $accountMetrics['metrics']['percentageMargin']['diff'] = $diffOscilante;
            $sequence->push($accountMetrics, 200);
        }

        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => $sequence,
        ]);

        $periodo    = $this->periodoJanelaIgual();
        $resultados = [];

        foreach ($diffsOscilantes as $diffOscilante) {
            Cache::flush();
            $resultado    = app(AdmanMetricDiffService::class)->compute($company, $periodo);
            $resultados[] = $resultado['metrics']['contribution_margin_pct']['diff_pct'];
        }

        $this->assertSame($diffsOscilantes, $resultados,
            'diff_pct deve refletir o .diff nativo DE CADA chamada (native-first, sem cache/local mascarando)');
    }

    /**
     * CENÁRIO (i) — REESCRITO pelo hotfix: a lógica de cobertura local por
     * dias-com-linha deixou de existir para `contribution_margin_pct`
     * (`coberturaMargem()`/`fallbackMargemPct()` ficaram órfãos — margem
     * agora é sempre native-first). O guard de dias-com-linha (não
     * dias-calendário) continua VIVO para o fallback de `revenue`
     * (`somasComGuards()`), então este cenário passa a cobrir ELE: empresa
     * que só vende em dias alternados (9 de 18 dias-calendário) em modo
     * operacional (sem `.diff` nativo aplicável) ainda calcula o fallback de
     * revenue corretamente via interseção de offsets-com-linha.
     */
    public function test_i_revenue_fallback_usa_dias_com_linha_nao_calendario(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetricAlternado($company, '2026-07-01', 18, 1000.0, 200.0);
        $this->semearAdmanMetricAlternado($company, '2026-06-01', 18, 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());

        $this->assertSame('calculated_fallback', $resultado['metrics']['revenue']['diff_source']);
        // 9 dias-com-linha em cada janela: (9000-4500)/4500*100 = 100%
        $this->assertSame(100.0, $resultado['metrics']['revenue']['diff_pct']);

        // Margem % não usa mais fallback local — sem .diff nativo aplicável
        // (modo operacional), fica null explícito.
        $this->assertSame('adman_indisponivel', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertNull($resultado['metrics']['contribution_margin_pct']['diff_pct']);
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
     * EXPLÍCITO. Com o hotfix native-first, o motivo mudou (não é mais
     * `coberturaMargem()`=0 caindo pro fallback local que também dá null —
     * é simplesmente o gate de `resolveMargemPct()` sem `.diff` nativo
     * disponível), mas o resultado observável continua o mesmo: sem
     * fail-open artificial.
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
        $this->assertSame('adman_indisponivel', $resultado['metrics']['contribution_margin_pct']['diff_source']);
    }

    /**
     * CENÁRIO (l) — REGRESSÃO do hotfix: com margem % agora TAMBÉM
     * native-first, os 3 metrics (`revenue`, `contribution_margin_value`,
     * `contribution_margin_pct`) resolvem igualmente via `.diff` nativo em
     * janela-igual — mesmo com dado local denso disponível nas duas janelas
     * (que antes fazia a margem % preferir o local). Escopo estrito: nenhum
     * metric cai indevidamente pro fallback quando o nativo está disponível.
     */
    public function test_l_todos_os_metrics_usam_adman_diff_em_janela_igual_mesmo_com_dado_local_denso(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_pct']['diff_source']);
        $this->assertSame('adman_diff', $resultado['metrics']['revenue']['diff_source']);
        $this->assertSame('adman_diff', $resultado['metrics']['contribution_margin_value']['diff_source']);
    }

    // ─────────────── Quick 260724-gf9 — rate-limit não apaga baseline local ───────────────

    /**
     * CENÁRIO (m) — falha TOTAL do ao-vivo (os 2 endpoints Adman indisponíveis,
     * simulando rate-limit 429 sustentado) MAS a empresa TEM dado local denso
     * nas duas janelas (current e baseline) — o `calculated_fallback` consegue
     * preencher `diff_pct` nos 3 metrics mesmo com todos os `value` null.
     *
     * ANTES do fix: `value=null` em tudo ⇒ `comValue===0` ⇒ `quality.status
     * ='missing'` ⇒ `compute()` gravava o ERROR_SENTINEL ⇒ a 2ª leitura
     * devolvia `emptyMetrics()` (tudo null), jogando fora o `diff_pct` bom do
     * fallback (raiz do bug de oscilação de `empresas_com_baseline`).
     *
     * DEPOIS do fix: `comDiff=3` (os 3 diff_pct vieram do fallback) ⇒ status
     * ='partial' ⇒ NÃO grava sentinela — `Cache::put()` grava o resultado
     * REAL (TTL curto) ⇒ a 2ª leitura (nova instância do service, sem o memo
     * em memória — só o cache persistente) continua devolvendo o diff_pct.
     */
    public function test_m_falha_total_ao_vivo_com_dado_local_preserva_diff_pct_via_fallback(): void
    {
        // 500 nos 2 endpoints — simula indisponibilidade sustentada da Adman.
        // Usamos 500 (não 429) pra não acionar o retry com sleep(2s+4s) de
        // fetchPerformance(); o catch em compute() é o mesmo pra qualquer
        // \Throwable, então o efeito no service sob teste é idêntico ao 429.
        Http::fake([
            '*/performance/*'       => Http::response([], 500),
            '*/accounts/*/metrics*' => Http::response([], 500),
        ]);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        // Dado local denso (cobertura 100%) nas duas janelas — o fallback
        // consegue calcular os 3 diffs sem depender do ao-vivo.
        $this->semearAdmanMetric($company, '2026-07-01', '2026-07-18', 1000.0, 200.0);
        $this->semearAdmanMetric($company, '2026-06-13', '2026-06-30', 500.0, 100.0);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertNull($resultado['metrics']['revenue']['value']);
        $this->assertNotNull($resultado['metrics']['revenue']['diff_pct']);
        $this->assertSame('calculated_fallback', $resultado['metrics']['revenue']['diff_source']);
        // (18000-9000)/9000*100 = 100%
        $this->assertSame(100.0, $resultado['metrics']['revenue']['diff_pct']);
        $this->assertSame('partial', $resultado['quality']['status']);

        // 2ª leitura — instância NOVA do service (sem o memo em memória),
        // dentro do TTL curto de 'partial'. Se tivesse gravado ERROR_SENTINEL
        // (comportamento pré-fix), viria tudo null aqui.
        $resultado2 = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());
        $this->assertNotNull($resultado2['metrics']['revenue']['diff_pct']);
        $this->assertSame($resultado['metrics']['revenue']['diff_pct'], $resultado2['metrics']['revenue']['diff_pct']);
        $this->assertSame('partial', $resultado2['quality']['status']);
    }

    /**
     * CENÁRIO (n) — GUARDA: empresa genuinamente SEM dado nenhum (nem local,
     * nem ao-vivo) continua `'missing'` — comportamento preservado pelo fix
     * (o `missing` só deixa de disparar quando HÁ diff_pct usável).
     */
    public function test_n_empresa_sem_dado_nenhum_continua_missing(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response([], 500),
            '*/accounts/*/metrics*' => Http::response([], 500),
        ]);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        // Sem NENHUMA linha AdmanMetric — sem fallback possível (0/0).

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame('missing', $resultado['quality']['status']);
        $this->assertNull($resultado['metrics']['revenue']['diff_pct']);
        $this->assertNull($resultado['metrics']['revenue']['value']);
    }

    // ─────────────── 2026-07-27 — fallback de faturamento via billing (caso Jf Auto) ───────────────

    /**
     * CENÁRIO (o) — FALLBACK NATIVO DE FATURAMENTO via `billing` do endpoint
     * detalhado (fix 2026-07-27, caso Jf Auto Câmbio): a Adman devolve HTTP 500
     * no /performance/{custId} de algumas empresas, o que zerava o `revenue`
     * (→ "sem fonte" na carteira) MESMO com o dado existindo na Adman. O
     * endpoint detalhado /accounts/{id}/metrics RESPONDE nesses casos e traz
     * `billing` = o MESMO {value,diff} do grossBilling. O revenue deve vir de
     * `billing` (nativo, sem cálculo local) — em janela-igual usa o `.diff`
     * nativo (`adman_diff`), NUNCA fica null.
     */
    public function test_o_faturamento_cai_para_billing_quando_performance_da_500(): void
    {
        // /performance 500 (caso Jf Auto); detalhado OK com billing nativo.
        // 500 (não 429) não aciona o retry-com-sleep do fetchPerformance.
        Http::fake([
            '*/performance/*'       => Http::response([], 500),
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        // revenue veio do `billing` do detalhado (mesmo value+diff do grossBilling).
        $this->assertSame(530797.73, $resultado['metrics']['revenue']['value']);
        $this->assertSame(101.24, $resultado['metrics']['revenue']['diff_pct']);
        $this->assertSame('adman_diff', $resultado['metrics']['revenue']['diff_source']);
        // NÃO é 'missing' — a empresa tem fonte de faturamento nativa.
        $this->assertNotSame('missing', $resultado['quality']['status']);
    }

    // ─────────────── Fase 117 (v21.0) — prev_value/diff_pp aditivos (MPP-01/02/03/06) ───────────────

    /**
     * CENÁRIO (p) — caso âncora MPP-06: `value=27.47` + `prev=24.08` produz
     * `diff_pp=3.39`, e `diff_pct` continua exatamente `14.09`. O teste prova
     * que `diff_pp` NÃO deriva de `diff_pct` (são fontes diferentes: diff_pp
     * é `value - prev_value` calculado aqui; diff_pct é o `.diff` nativo).
     */
    public function test_r_fixture_ancora_diff_pp_3_39_nao_deriva_de_diff_pct(): void
    {
        $this->fakeAdmanEndpoints(); // percentageMargin: value=27.47, diff=14.09, prev=24.08 (fixture já existente)
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $margem = $resultado['metrics']['contribution_margin_pct'];
        $this->assertSame(27.47, $margem['value']);
        $this->assertSame(24.08, $margem['prev_value']);
        $this->assertSame(3.39, $margem['diff_pp']);       // 27.47 - 24.08, NÃO deriva de diff_pct
        $this->assertSame(14.09, $margem['diff_pct']);     // inalterado — continua sendo a variação relativa nativa
    }

    /**
     * CENÁRIO (q) — gate D-07/MPP-02: diff_pp só é calculado em janela-igual
     * com value+prev_value ambos numéricos. Primeiro caso: MESMA fixture
     * (prev=24.08 presente), mas modo operacional ⇒ diff_pp=null E
     * prev_value=24.08 (prova que o gate é sobre comparison_mode, não sobre
     * ausência de prev). Segundo caso: sem `.diff` nativo (percentageMargin
     * sem chave `diff`) + janela-igual ⇒ diff_pct=null, diff_source=
     * 'adman_indisponivel', mas diff_pp=3.39 (o gate de diff_pp depende de
     * value+prev_value, NÃO da presença de `.diff`).
     */
    public function test_p_diff_pp_null_fora_de_janela_igual_mesmo_com_prev_presente(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultadoOperacional = app(AdmanMetricDiffService::class)->compute($company, $this->periodoOperacional());
        $margemOperacional    = $resultadoOperacional['metrics']['contribution_margin_pct'];
        $this->assertNull($margemOperacional['diff_pp']);
        $this->assertSame(24.08, $margemOperacional['prev_value']);
    }

    /**
     * Segundo caso do gate D-07/MPP-02, em teste isolado (cada teste precisa
     * de `Http::fake()` próprio — chamar `fakeAdmanEndpoints()` duas vezes no
     * MESMO teste não substitui o fake anterior, os stubs se ACUMULAM e o
     * primeiro registrado ainda casa primeiro): sem `.diff` nativo
     * (percentageMargin sem chave `diff`) + janela-igual ⇒ diff_pct=null,
     * diff_source='adman_indisponivel', mas diff_pp=3.39 (o gate de diff_pp
     * depende de value+prev_value, NÃO da presença de `.diff`).
     */
    public function test_p2_diff_pp_calculado_mesmo_sem_diff_nativo_quando_value_e_prev_presentes(): void
    {
        $this->fakeAdmanEndpoints(percentageMarginDiff: null);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultadoSemDiff = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());
        $margemSemDiff     = $resultadoSemDiff['metrics']['contribution_margin_pct'];
        $this->assertNull($margemSemDiff['diff_pct']);
        $this->assertSame('adman_indisponivel', $margemSemDiff['diff_source']);
        $this->assertSame(3.39, $margemSemDiff['diff_pp']);
    }

    /**
     * CENÁRIO (prev_value nas 3 métricas) — MPP-01: as 3 métricas expõem
     * prev_value; só contribution_margin_pct expõe diff_pp (D-06 — a chave
     * é AUSENTE, não presente com null, em revenue/contribution_margin_value).
     */
    public function test_prev_value_presente_nas_tres_metricas_e_diff_pp_ausente_fora_da_margem_pct(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $this->assertSame(263768.55, $resultado['metrics']['revenue']['prev_value']);
        $this->assertSame(57036.05, $resultado['metrics']['contribution_margin_value']['prev_value']);
        $this->assertSame(24.08, $resultado['metrics']['contribution_margin_pct']['prev_value']);

        $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['revenue']);
        $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['contribution_margin_value']);
    }

    /**
     * CENÁRIO (bump de cache MPP-03) — uma entrada `adman:diff:v5:` gravada
     * ANTES do deploy (shape antigo) não é servida pós-deploy: compute()
     * devolve o shape NOVO (com prev_value/diff_pp), lido da chave `v6`, e o
     * value da fixture real (27.47) — não o valor plantado no cache velho.
     */
    public function test_q_cache_v6_nao_reaproveita_shape_v5(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);
        $periodo = $this->periodoJanelaIgual();

        // Simula shape v5 (sem prev_value/diff_pp) já cacheado na chave ANTIGA.
        $cacheKeyV5 = "adman:diff:v5:meli:CUST1:{$periodo['current_start']}:{$periodo['current_end']}:" . now()->setTimezone(config('app.timezone'))->toDateString();
        Cache::put($cacheKeyV5, [
            'metrics' => [
                'contribution_margin_pct' => ['value' => 1.0, 'diff_pct' => 1.0, 'diff_source' => 'adman_diff'],
            ],
        ], 1440);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $periodo);

        $margem = $resultado['metrics']['contribution_margin_pct'];
        $this->assertArrayHasKey('prev_value', $margem);
        $this->assertArrayHasKey('diff_pp', $margem);
        $this->assertSame(27.47, $margem['value']);
    }

    /**
     * CENÁRIO (quality.diff_pp_disponivel, D-08) — indicador informativo:
     * true com fixture completa em janela-igual; false quando percentageMargin
     * está ausente do payload (ou modo operacional). quality.status NÃO
     * rebaixa por falta de diff_pp (senão prenderia empresa sem prev em TTL
     * curto permanentemente).
     */
    public function test_quality_diff_pp_disponivel_true_com_fixture_completa(): void
    {
        $this->fakeAdmanEndpoints();
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultadoCompleto = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());
        $this->assertTrue($resultadoCompleto['quality']['diff_pp_disponivel']);
        $this->assertSame('complete', $resultadoCompleto['quality']['status']);
    }

    /**
     * Segundo caso do indicador D-08, em teste isolado (mesma razão do
     * `test_p2` acima — `Http::fake()` não substitui um fake anterior no
     * mesmo teste): percentageMargin AUSENTE do payload ⇒
     * `diff_pp_disponivel=false`, e `quality.status` cai pra 'partial' pela
     * AUSÊNCIA de `percentageMargin.value` (revenue + margem R$ presentes) —
     * não é rebaixado ADICIONALMENTE por falta de diff_pp; a razão do
     * 'partial' é a mesma de antes desta fase (D-08 não altera o critério).
     */
    public function test_quality_diff_pp_disponivel_false_quando_percentage_margin_ausente(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response([
                'metrics' => [
                    'billing'      => ['value' => 530797.73, 'diff' => 101.24, 'prev' => 263768.55],
                    'liquidMargin' => ['value' => 141428.81, 'diff' => 147.96, 'prev' => 57036.05],
                    // percentageMargin AUSENTE — sem diff_pp possível.
                ],
            ], 200),
        ]);
        $company = Company::factory()->create(['adman_account_id' => 'CUST1', 'marketplace' => 'meli']);

        $resultadoSemMargem = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());
        $this->assertFalse($resultadoSemMargem['quality']['diff_pp_disponivel']);
        $this->assertSame('partial', $resultadoSemMargem['quality']['status']);
    }

    /**
     * CENÁRIO (emptyMetrics) — empresa sem adman_account_id nem ml_store_id:
     * contribution_margin_pct tem diff_pp=>null e prev_value=>null;
     * revenue/contribution_margin_value têm prev_value=>null e NÃO têm a
     * chave diff_pp.
     */
    public function test_empty_metrics_empresa_sem_custid_tem_shape_novo_todo_null(): void
    {
        $company = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => null]);

        $resultado = app(AdmanMetricDiffService::class)->compute($company, $this->periodoJanelaIgual());

        $margem = $resultado['metrics']['contribution_margin_pct'];
        $this->assertNull($margem['diff_pp']);
        $this->assertNull($margem['prev_value']);

        $this->assertNull($resultado['metrics']['revenue']['prev_value']);
        $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['revenue']);
        $this->assertNull($resultado['metrics']['contribution_margin_value']['prev_value']);
        $this->assertArrayNotHasKey('diff_pp', $resultado['metrics']['contribution_margin_value']);
    }
}
