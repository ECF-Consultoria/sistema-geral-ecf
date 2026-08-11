<?php

namespace Tests\Feature\Phase119;

use App\Models\Company;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsPorEmpresaService;
use App\Services\Metrics\AdmanMetricDiffService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\ShopeeMetricDiffService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 119 Plano 04 (Task 1) — EMPS-06: fonte financeira vencedora
 * (Adman × Shopee) e placeholder de margem para empresa só-Shopee.
 *
 * Cobre os 4 comportamentos do `<behavior>` da Task 1:
 *  - Empresa com DOIS vínculos elegíveis (performance + shopee) resolve
 *    'adman' e produz UMA única linha, apesar da multiplicidade de vínculos
 *    (`project_company_users_multi_linha_servico`).
 *  - Empresa só-Shopee entra `complete` com `margem_pontos=1.0` fixo e
 *    `quality.margin_source='placeholder_shopee'` — nunca `margem_pp_indisponivel`
 *    nos motivos (D-02, trava da Fase 109).
 *  - Caso âncora D-02: NPS 4,2 + faturamento +2% (4 pts) + margem placeholder
 *    1,0 ⇒ `nota_empresa = 3,07`.
 *  - A régua de margem NUNCA roda para fonte Shopee, mesmo que `diff_pp`
 *    existisse no payload — provado por dublê do dispatcher que injeta um
 *    `diff_pp` fabricado na resposta Shopee.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-04-PLAN.md
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md D-02
 */
class CompanyScoreServiceFonteTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** Hash de referência do gate de aditividade (119-VALIDATION.md). */
    /** Rotacionado pela Fase 119.1 (D1) — 4º ramo (D) somado a DesempenhoScoreService.php. */
    /** Rotacionado pela quick 260810-mbv (2026-08-10) — bump da chave de cache v17 → v18. O gate já estava vermelho desde as Fases 120/122/v17, que alteraram o arquivo sem rotacionar. */
    /** Rotacionado pela quick 260810-mt8 (2026-08-10) — nota final passa a dividir sempre por 3. */
    private const HASH_DESEMPENHO_SCORE_SERVICE = '5b6cb40da43773c19c24c1bbf8b6dffe20672cc6b223e8cc8f27676473064f24';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        // GATE MPP-04 pendente — nenhuma chamada real à Adman é aceitável.
        // NÃO registrar Http::fake() genérico aqui: os stubs ACUMULAM e o
        // PRIMEIRO registrado vence (achado da Wave 1, 119-02-SUMMARY.md) —
        // cada teste chama fakeAdmanEndpoints() explicitamente quando precisa.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Fixtures ══════════════════════════════════════════════════════════

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

    private function fakeAdmanEndpoints(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response($this->respostaPerformance(), 200),
            '*/accounts/*/metrics*' => Http::response($this->respostaAccountMetrics(), 200),
        ]);
    }

    /** Janela igual (mês fechado) — mesma janela usada nas Waves 1/2. */
    private function periodoFechado(): array
    {
        return app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-06']);
    }

    /**
     * Empresa mista: MESMO profissional, MESMA empresa, DOIS vínculos com
     * `servico_id` preenchido — um de setor `performance`, um de setor
     * `shopee`. Cenário de multiplicidade documentado na memória
     * `project_company_users_multi_linha_servico`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function montarCenarioMisto(): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true, 'adman_account_id' => 'CUST-FONTE-MISTA']);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        return compact('user', 'empresa');
    }

    /**
     * Empresa só-Shopee com `shopee_metrics` calibrado para `revenue.diff_pct`
     * de +2% (baseline 100000, janela atual 102000) ⇒ `reguaFaturamento(2.0)
     * = 4.0`. Molde: `tests/Feature/DesempenhoShopeeScoreTest.php::mockShopee`
     * e `CompanyScoreServiceDispatcherTest::montarCarteira`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function montarCenarioSoShopee(): array
    {
        $user    = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $empresa = Company::factory()->create(['active' => true]);

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        // Janela current (2026-06) e baseline (janela-de-mesmo-tamanho, mai/2026).
        ShopeeMetric::create(['company_id' => $empresa->id, 'reference_date' => '2026-06-15', 'revenue' => 102000]);
        ShopeeMetric::create(['company_id' => $empresa->id, 'reference_date' => '2026-05-15', 'revenue' => 100000]);

        return compact('user', 'empresa');
    }

    /**
     * Fixa a nota de NPS por dublê — os asserts de fonte/margem desta suíte
     * não dependem do comportamento de janela/survey do
     * `NpsPorEmpresaService` (já coberto em `CompanyScoreServiceContratoTest`).
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
    public function test_empresa_com_dois_vinculos_performance_e_shopee_resolve_fonte_adman_e_produz_uma_linha(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $this->fakeAdmanEndpoints();
        $cenario = $this->montarCenarioMisto();
        $this->fixarNps($cenario['empresa']->id, 4.6);

        // Confirma a premissa da fixture: 2 vínculos de fato na pivot, mesma
        // empresa, mesmo profissional — é a multiplicidade que o desempate
        // precisa resolver sem inflar a Collection.
        $this->assertSame(2, DB::table('company_users')->where('company_id', $cenario['empresa']->id)->count());

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $this->assertCount(1, $resultado, 'count() não pode inflar com a multiplicidade de vínculos.');

        $linha = $resultado->get($cenario['empresa']->id);
        $this->assertNotNull($linha);
        $this->assertSame('adman', $linha->fonte_financeira, "'adman' vence sobre 'shopee' quando a MESMA empresa tem os dois vínculos elegíveis.");

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_empresa_so_shopee_entra_complete_com_margem_placeholder_e_caso_ancora_3_07(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $cenario = $this->montarCenarioSoShopee();
        $this->fixarNps($cenario['empresa']->id, 4.2);

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresa']->id);
        $this->assertNotNull($linha);

        $this->assertSame('shopee', $linha->fonte_financeira);
        $this->assertSame(4.0, $linha->faturamento_pontos, 'reguaFaturamento(+2%) = 4.0.');
        $this->assertNull($linha->margem_var_pp, 'Shopee nunca fornece diff_pp de margem (arquitetura future-ready).');
        // ATUALIZADO em 2026-08-10 (quick 260810-mbv) para o contrato vigente
        // desde 2026-08-05: o placeholder fixo de 1,0 foi REVOGADO por decisão
        // do usuário — a Shopee não fornece CMV, então a loja fica FORA do
        // denominador da margem em vez de entrar com a nota mínima. O teste
        // afirmava o contrato antigo e só não acusava porque o gate de hash
        // (desatualizado desde as Fases 120/122/v17) falhava antes.
        // Ver `CompanyScoreService::computeEmpresasScore()`, bloco D-02.
        $this->assertNull($linha->margem_pontos, 'D-02 (2026-08-05) — Shopee sai da margem, não entra com placeholder.');
        $this->assertSame('sem_margem_shopee', $linha->quality['margin_source']);
        $this->assertNotContains('margem_pp_indisponivel', $linha->quality['motivos'],
            'A ausência de margem na Shopee NÃO é componente faltando — é dimensão que a plataforma não entrega.');
        $this->assertContains('margem_nao_fornecida_shopee', $linha->quality['motivos']);
        $this->assertSame(2, $linha->componentes_presentes);
        $this->assertSame(2, $linha->componentes_esperados);
        $this->assertSame('complete', $linha->status,
            'Loja Shopee fecha `complete` com 2 de 2 componentes esperados — não é `partial`.');

        // Caso âncora recalculado pelo contrato vigente: (4,2 + 4,0) / 2 = 4,10.
        // Sob o placeholder revogado seria (4,2 + 4 + 1,0) / 3 = 3,07.
        $this->assertSame(4.1, $linha->nota_empresa);
        $this->assertSame(4.1, $linha->nota_empresa_parcial);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }

    #[Test]
    public function test_regua_de_margem_nunca_e_aplicada_para_shopee_mesmo_com_diff_pp_fabricado(): void
    {
        $this->assertHashDesempenhoScoreServiceIntocado();

        $cenario = $this->montarCenarioSoShopee();
        $this->fixarNps($cenario['empresa']->id, 4.2);

        // Dublê injeta um diff_pp de margem FABRICADO (99.0) na resposta
        // Shopee — se a régua fosse aplicada por engano, reguaMargem(99.0)
        // daria 5.0. A linha precisa continuar reportando o placeholder 1.0.
        $this->app->instance(MetricDiffDispatcher::class, new DispatcherShopeeComDiffPpFake(
            app(AdmanMetricDiffService::class),
            app(ShopeeMetricDiffService::class),
        ));

        $resultado = $this->service()->computeEmpresasScore(
            $cenario['user'],
            Carbon::parse('2026-06-01'),
            $this->periodoFechado(),
        );

        $linha = $resultado->get($cenario['empresa']->id);
        $this->assertNotNull($linha);

        $this->assertNotSame(5.0, $linha->margem_pontos, 'reguaMargem(diff_pp=99.0) seria 5.0 — a linha NÃO pode reportar isso.');
        // ATUALIZADO em 2026-08-10 (quick 260810-mbv): o placeholder 1,0 foi
        // revogado em 2026-08-05 — a linha Shopee sai da margem. O que este
        // teste guarda continua igual: a régua NUNCA roda sobre um diff_pp
        // fabricado de fonte Shopee.
        $this->assertNull($linha->margem_pontos, 'A régua de margem NUNCA roda para fonte Shopee.');
        $this->assertSame('sem_margem_shopee', $linha->quality['margin_source']);

        $this->assertHashDesempenhoScoreServiceIntocado();
    }
}

/**
 * Dublê de `MetricDiffDispatcher` que delega em `parent::compute()` e, só
 * para a fonte 'shopee', injeta um `contribution_margin_pct.diff_pp`
 * fabricado no resultado — prova de que `CompanyScoreService` nunca lê esse
 * campo para empresa Shopee (o placeholder é decidido só por
 * `$fonteFinanceira === 'shopee'`, antes de qualquer leitura de diff_pp).
 *
 * Molde: `DispatcherContador` em `CompanyScoreServiceDispatcherTest.php`.
 */
class DispatcherShopeeComDiffPpFake extends MetricDiffDispatcher
{
    public function compute(\App\Models\Company $company, array $periodo, string $source): array
    {
        $resultado = parent::compute($company, $periodo, $source);

        if ($source === 'shopee') {
            $resultado['metrics']['contribution_margin_pct']['diff_pp'] = 99.0;
        }

        return $resultado;
    }
}
