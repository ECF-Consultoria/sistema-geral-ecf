<?php

namespace Tests\Feature\Phase119;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 02 (Task 3) — casos âncora da fórmula: `nota_empresa`
 * (EMPS-04, caso §5.4) e a divergência régua-por-empresa × régua-da-média
 * (EMPS-02, D3 da milestone).
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-02-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 */
class CompanyScoreServiceFormulaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 — sem Http::fake() genérico aqui (accumula e o
        // primeiro registrado vence); cada teste chama fakeAdmanPorEmpresa().
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

    private function respostaPerformance(float $faturamentoDiff): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 100000.0, 'diff' => $faturamentoDiff, 'prev' => 90000.0],
                'profitMargin' => ['value' => 20000.0,  'diff' => 10.0,             'prev' => 18000.0],
                'investment'   => ['value' => 5000.0,   'diff' => 5.0,              'prev' => 4700.0],
                'profitShare'  => 20.0,
            ],
            'items' => [],
        ];
    }

    private function respostaAccountMetrics(float $margemValue, float $margemPrev, float $margemDiff): array
    {
        return [
            'metrics' => [
                'billing'          => ['value' => 100000.0, 'diff' => 8.0,        'prev' => 90000.0],
                'liquidMargin'     => ['value' => 18200.0,  'diff' => 10.0,       'prev' => 15000.0],
                'percentageMargin' => ['value' => $margemValue, 'diff' => $margemDiff, 'prev' => $margemPrev],
                'investment'       => ['value' => 5000.0,   'diff' => 5.0,        'prev' => 4700.0],
            ],
        ];
    }

    /**
     * Fake dos 2 endpoints Adman com closure que inspeciona o custId na URL
     * — cada empresa (custId distinto) recebe um payload financeiro próprio.
     *
     * @param  array<string, array{faturamento_diff: float, margem_value: float, margem_prev: float, margem_diff: float}>  $porCustId
     */
    private function fakeAdmanPorEmpresa(array $porCustId): void
    {
        Http::fake(function ($request) use ($porCustId) {
            $url = $request->url();

            foreach ($porCustId as $custId => $dados) {
                if (! str_contains($url, $custId)) {
                    continue;
                }

                if (str_contains($url, '/performance/')) {
                    return Http::response($this->respostaPerformance($dados['faturamento_diff']), 200);
                }

                if (str_contains($url, '/metrics')) {
                    return Http::response(
                        $this->respostaAccountMetrics($dados['margem_value'], $dados['margem_prev'], $dados['margem_diff']),
                        200
                    );
                }
            }

            return Http::response([], 404);
        });
    }

    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    private function criarEmpresaComVinculo(User $user, string $custId): Company
    {
        $company = Company::factory()->create(['active' => true, 'adman_account_id' => $custId]);
        $servico = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servico, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servico);

        return $company;
    }

    /**
     * Injeta um dublê de NpsPorEmpresaService com notas fixas — documenta a
     * chamada ÚNICA (`->once()`) do componente NPS. Resolver o serviço sob
     * teste DEPOIS do bind.
     *
     * @param  array<int, float>  $notasPorCompanyId
     */
    private function mockNps(array $notasPorCompanyId): CompanyScoreService
    {
        $colecao = collect($notasPorCompanyId)->map(fn ($nota, $companyId) => (object) [
            'company_id' => $companyId,
            'nota'       => $nota,
        ])->keyBy('company_id');

        $this->mock(NpsPorEmpresaService::class, function ($mock) use ($colecao) {
            $mock->shouldReceive('notasNpsPorEmpresa')->once()->andReturn($colecao);
        });

        return app(CompanyScoreService::class);
    }

    private function invocarReguaFaturamentoOriginal(float $pct): ?float
    {
        $original = app(DesempenhoScoreService::class);
        $ref      = new ReflectionMethod($original, 'reguaFaturamento');
        $ref->setAccessible(true);

        return $ref->invoke($original, $pct);
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    /**
     * Caso âncora §5.4: NPS 4,6 · faturamento +8% (⇒5 pts) · margem +3,2pp
     * (⇒4 pts) ⇒ nota_empresa = round((4,6+5+4)/3, 2) = 4,53 — e NÃO 4,5333
     * (prova que o arredondamento é para 2 casas, não a fração exata).
     */
    #[Test]
    public function test_caso_ancora_nota_empresa_fecha_em_4_53(): void
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $company = $this->criarEmpresaComVinculo($user, 'CUST-ANCORA');

        $this->fakeAdmanPorEmpresa([
            'CUST-ANCORA' => [
                'faturamento_diff' => 8.0,   // reguaFaturamento(8.0) = 5.0
                'margem_value'     => 18.2,
                'margem_prev'      => 15.0,  // diff_pp = round(18.2-15.0,2) = 3.2 => reguaMargem(3.2) = 4.0
                'margem_diff'      => 20.0,  // presente só para diff_source sair 'adman_diff'
            ],
        ]);

        $service   = $this->mockNps([$company->id => 4.6]);
        $resultado = $service->computeEmpresasScore($user, Carbon::parse('2026-06-01'), $this->periodoFechado());

        $linha = $resultado->get($company->id);

        $this->assertSame(4.6, $linha->nps_pontos);
        $this->assertSame(5.0, $linha->faturamento_pontos);
        $this->assertSame(3.2, $linha->margem_var_pp);
        $this->assertSame(4.0, $linha->margem_pontos);
        $this->assertSame(3, $linha->componentes_presentes);
        $this->assertSame('complete', $linha->status);
        $this->assertSame(4.53, $linha->nota_empresa);
        $this->assertSame(4.53, $linha->nota_empresa_parcial);
        $this->assertNotEquals(4.5333, $linha->nota_empresa, 'nota_empresa deve arredondar para 2 casas, nunca a fração exata.');
    }

    /**
     * Caso de granularidade (EMPS-02/D3): empresa A com -20% recebe pontos
     * 1,0; empresa B com +2% recebe pontos 4,0. A MÉDIA dos pontos (2,5) é
     * DIFERENTE do que a regra antiga (régua sobre a média das variações,
     * -9%) produziria: reguaFaturamento(-9,0) = 1,0. A divergência é O
     * OBJETIVO da milestone (D3) — não é regressão.
     */
    #[Test]
    public function test_regua_por_empresa_diverge_da_regua_da_media(): void
    {
        $user       = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresaA   = $this->criarEmpresaComVinculo($user, 'CUST-GRAN-A');
        $empresaB   = $this->criarEmpresaComVinculo($user, 'CUST-GRAN-B');

        $this->fakeAdmanPorEmpresa([
            'CUST-GRAN-A' => ['faturamento_diff' => -20.0, 'margem_value' => 10.0, 'margem_prev' => 10.0, 'margem_diff' => 0.0],
            'CUST-GRAN-B' => ['faturamento_diff' => 2.0,   'margem_value' => 10.0, 'margem_prev' => 10.0, 'margem_diff' => 0.0],
        ]);

        $service   = $this->mockNps([$empresaA->id => 3.0, $empresaB->id => 3.0]);
        $resultado = $service->computeEmpresasScore($user, Carbon::parse('2026-06-01'), $this->periodoFechado());

        $linhaA = $resultado->get($empresaA->id);
        $linhaB = $resultado->get($empresaB->id);

        $this->assertSame(1.0, $linhaA->faturamento_pontos, 'reguaFaturamento(-20%) deve dar 1.0 pt (queda severa).');
        $this->assertSame(4.0, $linhaB->faturamento_pontos, 'reguaFaturamento(+2%) deve dar 4.0 pts (crescimento saudável).');

        $mediaPorEmpresa = round(($linhaA->faturamento_pontos + $linhaB->faturamento_pontos) / 2, 2);
        $this->assertSame(2.5, $mediaPorEmpresa, 'média das réguas por empresa deve ser 2,5.');

        // Prova literal: a regra ANTIGA (régua sobre a média das variações,
        // -20% e +2% médias em -9%) daria 1.0 — DIFERENTE de 2.5.
        $reguaDaMedia = $this->invocarReguaFaturamentoOriginal(-9.0);
        $this->assertSame(1.0, $reguaDaMedia);
        $this->assertNotSame($mediaPorEmpresa, $reguaDaMedia, 'régua-por-empresa deve DIVERGIR da régua-da-média — é o objetivo da milestone (D3).');
    }
}
