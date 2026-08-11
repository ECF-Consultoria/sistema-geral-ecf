<?php

namespace Tests\Feature\Phase136;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 136 Plano 03 (Tasks 2 e 3) — prova que o valor lançado à mão manda
 * efetivamente no cálculo da nota, cobrindo D-01/D-02/D-05/D-06/D-07/D-08,
 * o desbloqueio da margem Shopee, a trava de escopo D-91-01 (Task 2) e a
 * propagação do sinal até o snapshot congelado, D-03 (Task 3).
 *
 * Todo cenário roda sobre fonte 'shopee' (leitura 100% local via
 * `shopee_metrics`, sem HTTP) — EXCETO o cenário de D-06 rung 3 (base
 * ausente), que usa fonte 'adman' de uma empresa SEM `cust_id`, onde o
 * dispatcher devolve o shape vazio sem tocar a rede. Nenhum teste desta
 * suíte dispara HTTP real: `Http::preventStrayRequests()` no `setUp()`.
 *
 * @see .planning/phases/136-.../136-03-PLAN.md
 * @see app/Services/Metrics/ManualMetricOverrideService.php
 * @see app/Services/Desempenho/CompanyScoreService.php
 */
class MetricaManualLancamentoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-11 10:00:00'));

        // Nenhum teste desta suíte pode disparar HTTP real à Adman.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    /** @return array{user: User, empresa: Company} */
    private function montarCenarioShopee(): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        return compact('user', 'empresa');
    }

    /** Empresa com vínculo só-performance, SEM cust_id — dispatcher devolve shape vazio, sem HTTP. */
    private function montarCenarioAdmanSemCustId(): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => null, 'ml_store_id' => null]);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);

        return compact('user', 'empresa');
    }

    /** Empresa com vínculo de setor SEM fonte financeira (Polos) — status sem_fonte, override nunca alcança. */
    private function montarCenarioSemFonte(): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);

        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPolos);

        return compact('user', 'empresa');
    }

    private function shopeeRevenue(int $companyId, string $data, float $valor): void
    {
        ShopeeMetric::create(['company_id' => $companyId, 'reference_date' => $data, 'revenue' => $valor]);
    }

    private function lancarManual(int $companyId, string $mesReferencia, string $metrica, float $valor): DesempenhoMetricaManual
    {
        return DesempenhoMetricaManual::create([
            'company_id'     => $companyId,
            'mes_referencia' => $mesReferencia . '-01',
            'metrica'        => $metrica,
            'valor'          => $valor,
            'ativo'          => true,
            'lancado_em'     => now(),
        ]);
    }

    /** Fixa nota de NPS por dublê — os asserts desta suíte não dependem de janela/survey do NPS. */
    private function fixarNps(array $notasPorEmpresa): void
    {
        $this->mock(NpsPorEmpresaService::class, function ($mock) use ($notasPorEmpresa) {
            $mock->shouldReceive('notasNpsPorEmpresa')
                ->andReturn(collect($notasPorEmpresa)->map(fn ($nota) => (object) ['nota' => $nota]));
        });
    }

    private function periodo(string $mesStr): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => $mesStr]);
    }

    private function service(): CompanyScoreService
    {
        return app(CompanyScoreService::class);
    }

    // ═══ D-01 / D-07 — eixos independentes ═══════════════════════════════════

    #[Test]
    public function d01_d07_lancar_so_faturamento_nao_altera_nenhum_campo_de_margem(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Sem lançamento de CMV — margem deve permanecer exatamente como o
        // dispatcher devolveria sem NENHUM lançamento (sempre null p/ Shopee).
        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 150000);
        $this->lancarManual($cenario['empresa']->id, '2026-06', DesempenhoMetricaManual::METRICA_FATURAMENTO, 100000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(150000.0, $linha->faturamento_atual);
        $this->assertSame(100000.0, $linha->faturamento_anterior);
        $this->assertSame(50.0, $linha->faturamento_var_pct);
        $this->assertSame(5.0, $linha->faturamento_pontos, 'reguaFaturamento(50%) = 5.0.');

        $this->assertNull($linha->margem_pct_atual);
        $this->assertNull($linha->margem_pct_anterior);
        $this->assertNull($linha->margem_var_pp);
        $this->assertNull($linha->margem_pontos);
        $this->assertSame('manual', $linha->quality['faturamento_fonte']);
        $this->assertSame('auto', $linha->quality['margem_fonte']);
    }

    #[Test]
    public function d01_d07_lancar_so_cmv_nao_altera_nenhum_campo_de_faturamento(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Mesmos dados de revenue de `montarCenarioSoShopee()` da Fase 119 —
        // sem NENHUM lançamento, o faturamento seria 102000/100000/+2%/4pts.
        $this->shopeeRevenue($cenario['empresa']->id, '2026-07-15', 102000);
        $this->shopeeRevenue($cenario['empresa']->id, '2026-06-15', 100000);

        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_MARGEM_CMV, 30000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(102000.0, $linha->faturamento_atual, 'Faturamento continua vindo do caminho automático.');
        $this->assertSame(100000.0, $linha->faturamento_anterior);
        $this->assertSame(2.0, $linha->faturamento_var_pct);
        $this->assertSame(4.0, $linha->faturamento_pontos);
        $this->assertSame('auto', $linha->quality['faturamento_fonte']);

        // Margem passa a existir (faturamento efetivo 102000 − CMV 30000).
        $this->assertSame(70.59, $linha->margem_pct_atual);
        $this->assertSame('manual', $linha->quality['margem_fonte']);
    }

    // ═══ D-02 — caso Tuki Pet: manual nunca reverte sozinho ═══════════════════

    #[Test]
    public function d02_celula_manual_ignora_dado_parcial_da_fonte_a_partir_do_dia_28(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Dado parcial (só a partir do dia 28) — a soma da fonte técnica
        // seria 11000, bem abaixo do valor real do mês.
        $this->shopeeRevenue($cenario['empresa']->id, '2026-07-28', 5000);
        $this->shopeeRevenue($cenario['empresa']->id, '2026-07-29', 6000);

        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 200000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(
            200000.0,
            $linha->faturamento_atual,
            'D-02: o valor manual continua mandando — NUNCA a soma parcial da fonte técnica (11000).'
        );
        $this->assertNotEquals(11000.0, $linha->faturamento_atual);
    }

    // ═══ D-05 — sinal de quality e diff_source ═════════════════════════════

    #[Test]
    public function d05_quality_faturamento_fonte_manual_e_diff_source_manual_mes_calendario(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 150000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame('manual', $linha->quality['faturamento_fonte']);
    }

    // ═══ D-06 — cascata do lado base ═══════════════════════════════════════

    #[Test]
    public function d06_prev_value_usa_lancamento_manual_do_mes_anterior_quando_existe(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 150000);
        $this->lancarManual($cenario['empresa']->id, '2026-06', DesempenhoMetricaManual::METRICA_FATURAMENTO, 100000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertSame(100000.0, $linha->faturamento_anterior);
    }

    #[Test]
    public function d06_prev_value_usa_total_do_mes_calendario_anterior_pela_fonte_tecnica_sem_lancamento_manual(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // SEM lançamento manual em junho — só dado local (papel da "API").
        $this->shopeeRevenue($cenario['empresa']->id, '2026-06-15', 80000);

        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 200000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertSame(80000.0, $linha->faturamento_anterior);
    }

    #[Test]
    public function d06_prev_value_e_null_sem_lancamento_manual_e_sem_dado_na_fonte(): void
    {
        $cenario = $this->montarCenarioAdmanSemCustId();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Sem cust_id, o dispatcher nunca toca a rede — devolve shape vazio.
        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 90000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(90000.0, $linha->faturamento_atual);
        $this->assertNull($linha->faturamento_anterior);
    }

    // ═══ D-08 — margem derivada do CMV ═════════════════════════════════════

    #[Test]
    public function d08_cmv_lancado_com_faturamento_efetivo_calcula_margem_pct(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        $this->shopeeRevenue($cenario['empresa']->id, '2026-07-15', 100000);
        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_MARGEM_CMV, 40000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame(60.0, $linha->margem_pct_atual, '(100000 - 40000) / 100000 * 100 = 60.0.');
        $this->assertSame('manual', $linha->quality['margem_fonte']);
    }

    #[Test]
    public function d08_cmv_lancado_sem_nenhum_faturamento_produz_margem_nula_com_motivo(): void
    {
        $cenario = $this->montarCenarioShopee();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Nenhum dado de revenue em lugar nenhum (nem manual, nem shopee_metrics).
        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_MARGEM_CMV, 40000);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertNull($linha->margem_pct_atual);
        $this->assertNull($linha->margem_pontos);
        $this->assertContains('margem_manual_sem_faturamento', $linha->quality['motivos']);
    }

    // ═══ Desbloqueio da margem Shopee — o ponto que tira o teto de 3,33 ══════

    #[Test]
    public function loja_shopee_com_cmv_manual_no_mes_e_no_anterior_pontua_em_margem_com_3_componentes_esperados(): void
    {
        $comCmv = $this->montarCenarioShopee();
        $this->shopeeRevenue($comCmv['empresa']->id, '2026-07-15', 100000);
        $this->shopeeRevenue($comCmv['empresa']->id, '2026-06-15', 90000);
        $this->lancarManual($comCmv['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_MARGEM_CMV, 40000);
        $this->lancarManual($comCmv['empresa']->id, '2026-06', DesempenhoMetricaManual::METRICA_MARGEM_CMV, 45000);

        $this->fixarNps([$comCmv['empresa']->id => 4.5]);
        $resultadoComCmv = $this->service()->computeEmpresasScore($comCmv['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linhaComCmv = $resultadoComCmv->get($comCmv['empresa']->id);

        $this->assertNotNull($linhaComCmv);
        $this->assertSame(60.0, $linhaComCmv->margem_pct_atual);
        $this->assertSame(50.0, $linhaComCmv->margem_pct_anterior);
        $this->assertSame(10.0, $linhaComCmv->margem_var_pp);
        $this->assertSame(5.0, $linhaComCmv->margem_pontos, 'reguaMargem(+10pp) = 5.0.');
        $this->assertSame(3, $linhaComCmv->componentes_esperados);

        // MESMA loja, SEM CMV manual — regressão zero: continua no teto de 2
        // componentes esperados e margem null.
        $semCmv = $this->montarCenarioShopee();
        $this->shopeeRevenue($semCmv['empresa']->id, '2026-07-15', 100000);
        $this->shopeeRevenue($semCmv['empresa']->id, '2026-06-15', 90000);

        $this->fixarNps([$semCmv['empresa']->id => 4.5]);
        $resultadoSemCmv = $this->service()->computeEmpresasScore($semCmv['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linhaSemCmv = $resultadoSemCmv->get($semCmv['empresa']->id);

        $this->assertNotNull($linhaSemCmv);
        $this->assertNull($linhaSemCmv->margem_pontos);
        $this->assertSame(2, $linhaSemCmv->componentes_esperados);
        $this->assertContains('margem_nao_fornecida_shopee', $linhaSemCmv->quality['motivos']);
    }

    // ═══ Trava de escopo — override nunca alcança a linha sem_fonte (D-91-01) ═

    #[Test]
    public function empresa_sem_fonte_financeira_elegivel_com_lancamento_manual_gravado_continua_sem_fonte(): void
    {
        $cenario = $this->montarCenarioSemFonte();
        $this->fixarNps([$cenario['empresa']->id => 4.5]);

        // Lançamento gravado mesmo assim — prova que ele NÃO é porta dos
        // fundos para a linha `sem_fonte` receber nota oficial.
        $this->lancarManual($cenario['empresa']->id, '2026-07', DesempenhoMetricaManual::METRICA_FATURAMENTO, 999999);

        $resultado = $this->service()->computeEmpresasScore($cenario['user'], Carbon::parse('2026-07-01'), $this->periodo('2026-07'));
        $linha = $resultado->get($cenario['empresa']->id);

        $this->assertNotNull($linha);
        $this->assertSame('sem_fonte', $linha->status);
        $this->assertNull($linha->margem_pontos);
        $this->assertNull($linha->faturamento_pontos);
        $this->assertSame('auto', $linha->quality['faturamento_fonte']);
        $this->assertSame('auto', $linha->quality['margem_fonte']);
    }
}
