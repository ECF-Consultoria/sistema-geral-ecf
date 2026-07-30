<?php

namespace Tests\Feature\Phase120;

use App\Models\Company;
use App\Models\AdmanMetric;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Gate nº 1 do VALIDATION (120-VALIDATION.md) — substitui o `sha256sum` que
 * protegeu `DesempenhoScoreService.php` nas Fases 117-119. Aquelas fases
 * mantiveram o arquivo byte-a-byte intocado e comparavam o hash a cada task —
 * rede que pegou erros reais (literal errado de régua, contagem de rodadas,
 * cobertura agregada). A Fase 120 modifica o arquivo DE PROPÓSITO, então o
 * gate de hash cai. Este teste é o substituto: com a feature flag
 * `metrics.performance_company_first_score` DESLIGADA, o payload de
 * `compute()` tem que continuar byte-idêntico ao de antes da mudança — mesmas
 * chaves, mesmos tipos, mesmos valores, campo a campo. Qualquer divergência
 * aqui é sinal de que o caminho legado foi contaminado pela superfície nova
 * (Pitfall 2 do RESEARCH — o shadow rodando antes dos componentes legados e
 * mexendo em estado compartilhado).
 */
class PayloadBaselineFlagOffTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /**
     * Chaves de topo do payload de `compute()`, na ordem em que o `return`
     * do método as declara hoje (2026-07-30, conferida contra o arquivo).
     */
    private const CHAVES_LEGADAS = [
        'user_id', 'user_name', 'mes_referencia', 'sem_carteira', 'motivo',
        'empresas_carteira', 'empresas_com_baseline', 'margem_amostra',
        'componentes', 'pontos_componentes', 'nota_final', 'faixa_bonus',
        'faixa_promovida', 'periodo_meta', 'periodo', 'bonus',
        'empresas_unicas', 'vinculos_servico', 'vinculos_financeiros',
        'vinculos_sem_fonte_financeira', 'score_status', 'componentes_disponiveis',
    ];

    /**
     * Vazia NESTA task — a Task 3 atualiza para `['empresas_score']` quando o
     * payload aditivo entrar. É a única concessão auditável do gate: qualquer
     * chave nova que apareça sem passar por uma edição explícita desta
     * constante reprova o teste.
     */
    private const CHAVES_ADITIVAS_PERMITIDAS = [];

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 é o mês EM CURSO — mesmo molde de DesempenhoShopeeScoreTest.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        // Stub local — força o path Adman em toda janela (empresas Shopee
        // roteiam direto pro ShopeeMetricDiffService, sem passar por aqui).
        // Zero HTTP real.
        $this->app->instance(MetricsProviderFactory::class, new PayloadBaselineFlagOffTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-120-01',
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

    // ═══ Helpers copiados de DesempenhoShopeeScoreTest (mesmo molde) ═════════

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

    /** 1 row de AdmanMetric no dia 10 — dia comum às duas janelas (mês em curso). */
    private function mockAdman(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    /** 1 row de ShopeeMetric no dia 10 — mesmo padrão de `mockAdman`. Margem Shopee não existe. */
    private function mockShopee(Company $c, string $mesYm, float $revenue): void
    {
        ShopeeMetric::create([
            'company_id'     => $c->id,
            'reference_date' => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'        => $revenue,
        ]);
    }

    /**
     * Fixture rica e determinística: 1 profissional, 3 empresas.
     *  (a) empresaA — Adman completa, faturamento e margem reais nas duas
     *      janelas (jul/ago), com histórico de junho só pra provar operação
     *      pré-baseline;
     *  (b) empresaB — Shopee, exercita o placeholder de margem 1.0 e o blend
     *      por contagem de `margemPontos()`;
     *  (c) empresaC — Adman SEM baseline na janela anterior (só linha de
     *      agosto, nenhuma de julho), exercita o ramo de componente ausente.
     *
     * @return array{user: User, empresaA: Company, empresaB: Company, empresaC: Company}
     */
    private function montarFixture(): array
    {
        $user = $this->criarUserComCargo('Baseline Flag Off 120');

        $servicoPerf   = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);

        $empresaA = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-120-A', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaA, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresaA, '2026-07', revenue: 10000, margem: 2000.00);
        $this->mockAdman($empresaA, '2026-06', revenue: 9500, margem: 1900.00);

        $empresaB = $this->criarEmpresa();
        $this->inserirPivot($empresaB->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaB, '2026-08', revenue: 11000);
        $this->mockShopee($empresaB, '2026-07', revenue: 10000);

        $empresaC = $this->criarEmpresa();
        $empresaC->forceFill(['adman_account_id' => 'CUST-120-C', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresaC->id, $servicoPerf, true);
        $this->inserirPivot($empresaC->id, $user->id, 'consultor', $servicoPerf);
        // SEM baseline na janela anterior — só linha de agosto, nenhuma de julho.
        $this->mockAdman($empresaC, '2026-08', revenue: 5000, margem: 1000.00);

        return ['user' => $user, 'empresaA' => $empresaA, 'empresaB' => $empresaB, 'empresaC' => $empresaC];
    }

    private function computePayload(): array
    {
        $fixture = $this->montarFixture();
        $service = app(DesempenhoScoreService::class);

        return $service->compute($fixture['user'], Carbon::parse('2026-08-01'));
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    #[Test]
    public function test_chaves_de_topo_do_payload_sao_exatamente_as_legadas_mais_as_aditivas_declaradas(): void
    {
        $payload = $this->computePayload();

        $this->assertSame(
            self::CHAVES_LEGADAS,
            array_values(array_intersect(array_keys($payload), self::CHAVES_LEGADAS)),
            'Nenhuma chave legada pode sumir nem trocar de ordem relativa.'
        );

        $this->assertSame(
            self::CHAVES_ADITIVAS_PERMITIDAS,
            array_values(array_diff(array_keys($payload), self::CHAVES_LEGADAS)),
            'Nenhuma chave nova pode aparecer sem passar por CHAVES_ADITIVAS_PERMITIDAS.'
        );
    }

    #[Test]
    public function test_sub_chaves_de_componentes_pontos_componentes_margem_amostra_e_componentes_disponiveis(): void
    {
        $payload = $this->computePayload();

        $chavesLegadasComponentes = ['nps_medio', 'var_faturamento_pct', 'var_margem_pct', 'absenteismo_pct'];
        $aditivasComponentes      = []; // Task 3 atualiza para ['var_margem_pp'].

        $this->assertSame(
            $chavesLegadasComponentes,
            array_values(array_intersect(array_keys($payload['componentes']), $chavesLegadasComponentes))
        );
        $this->assertSame(
            $aditivasComponentes,
            array_values(array_diff(array_keys($payload['componentes']), $chavesLegadasComponentes))
        );

        $this->assertSame(['nps', 'faturamento', 'margem'], array_keys($payload['pontos_componentes']));
        $this->assertSame(['n_real', 'n_elegivel', 'cobertura'], array_keys($payload['margem_amostra']));
        $this->assertSame(
            ['nps_medio', 'var_faturamento_pct', 'var_margem_pct'],
            array_keys($payload['componentes_disponiveis'])
        );
    }

    #[Test]
    public function test_tipos_das_chaves_legadas_nao_mudam(): void
    {
        $payload = $this->computePayload();

        $this->assertIsInt($payload['empresas_carteira']);
        $this->assertIsInt($payload['empresas_com_baseline']);
        $this->assertIsString($payload['score_status']);
        $this->assertIsString($payload['faixa_bonus']);
        $this->assertIsBool($payload['faixa_promovida']);
        $this->assertIsArray($payload['componentes']);
        $this->assertIsArray($payload['pontos_componentes']);
        $this->assertIsArray($payload['margem_amostra']);
        $this->assertIsArray($payload['componentes_disponiveis']);

        $this->assertTrue(is_float($payload['nota_final']) || is_null($payload['nota_final']));
        $this->assertTrue(is_float($payload['componentes']['nps_medio']) || is_null($payload['componentes']['nps_medio']));
        $this->assertTrue(is_float($payload['componentes']['var_faturamento_pct']) || is_null($payload['componentes']['var_faturamento_pct']));
        $this->assertTrue(is_float($payload['componentes']['var_margem_pct']) || is_null($payload['componentes']['var_margem_pct']));
        $this->assertTrue(is_float($payload['componentes']['absenteismo_pct']) || is_null($payload['componentes']['absenteismo_pct']));
    }

    #[Test]
    public function test_valores_congelados_do_caminho_legado(): void
    {
        $payload = $this->computePayload();

        // Fotografia do comportamento ATUAL (2026-07-30, capturada em execução
        // contra o código não modificado) — NÃO é expectativa teórica.
        // Qualquer divergência futura é o sinal que este gate existe para dar.
        //
        // Nota sobre var_margem_pct=null: o hotfix de 2026-07-24
        // (AdmanMetricDiffService::resolveMargemPct) faz a variação de margem
        // usar SEMPRE o `.diff` nativo da Adman, nunca cálculo local — e isso
        // só é permitido quando `comparison_mode === 'previous_equal_length_window'`.
        // O mês EM CURSO (`current_month`) resolve pra
        // `comparison_mode = 'same_interval_previous_month'` (MetricPeriodResolver),
        // então `var_margem_pct` fica `null` MESMO com dado de margem real
        // sincronizado (empresa A) — não é um efeito do fixture desta suíte,
        // é o comportamento vigente de produção hoje.
        $this->assertSame(3, $payload['empresas_carteira']);
        $this->assertSame(2, $payload['empresas_com_baseline']);
        $this->assertSame(3, $payload['vinculos_financeiros']);
        $this->assertSame('official', $payload['score_status']);
        $this->assertSame('sem_bonus', $payload['faixa_bonus']);

        $this->assertEqualsWithDelta(1.0, $payload['componentes']['nps_medio'], 0.001,
            'Mês em curso (agosto) → piso NPS 1.0 (computeNpsWindow, mês não fechado).');
        $this->assertEqualsWithDelta(6.50, $payload['componentes']['var_faturamento_pct'], 0.001,
            'Média das % com baseline: A (+3.00%) e B/Shopee (+10.00%) — C sem baseline anterior fica de fora.');
        $this->assertNull($payload['componentes']['var_margem_pct'],
            'Mês em curso: comparison_mode=same_interval_previous_month nunca usa calculated_fallback pra margem % (hotfix 2026-07-24).');

        $this->assertEqualsWithDelta(1.0, $payload['pontos_componentes']['margem'], 0.001,
            'margemPontos: nComMargemReal=0 (var_margem_pct null pra todos) + nShopeePlaceholder=1 → placeholder puro 1.0.');
        $this->assertEqualsWithDelta(5.00, $payload['pontos_componentes']['faturamento'], 0.001,
            'reguaFaturamento(6.50%) > 5% → 5.0 pts.');

        $this->assertSame(0, $payload['margem_amostra']['n_real']);
        $this->assertSame(2, $payload['margem_amostra']['n_elegivel']);
        $this->assertEqualsWithDelta(0.0, $payload['margem_amostra']['cobertura'], 0.0001);

        $this->assertEqualsWithDelta(2.33, $payload['nota_final'], 0.001,
            '(nps piso 1.0 + fatPts 5.0 + margemPts 1.0) / 3 = 2.333... → 2.33.');
    }

    #[Test]
    public function test_shape_sem_carteira_tambem_esta_congelado(): void
    {
        // Profissional sem NENHUM vínculo elegível (Pitfall 4 do RESEARCH —
        // caminho de retorno antecipado, fisicamente distante do resto do
        // método, fácil de esquecer numa mudança de shape).
        $user = $this->criarUserComCargo('Sem Carteira Flag Off 120');

        $service = app(DesempenhoScoreService::class);
        $payload = $service->compute($user, Carbon::parse('2026-08-01'));

        $chavesLegadasSemCarteira = [
            'user_id', 'user_name', 'mes_referencia', 'sem_carteira', 'motivo',
            'empresas_carteira', 'empresas_com_baseline', 'componentes',
            'pontos_componentes', 'nota_final', 'faixa_bonus', 'faixa_promovida',
            'empresas_unicas', 'vinculos_servico', 'vinculos_financeiros',
            'vinculos_sem_fonte_financeira', 'score_status', 'componentes_disponiveis',
        ];

        $this->assertSame(
            $chavesLegadasSemCarteira,
            array_values(array_intersect(array_keys($payload), $chavesLegadasSemCarteira))
        );
        $this->assertSame(
            self::CHAVES_ADITIVAS_PERMITIDAS,
            array_values(array_diff(array_keys($payload), $chavesLegadasSemCarteira))
        );

        $this->assertTrue($payload['sem_carteira']);
        $this->assertSame('Sem carteira em agosto/2026', $payload['motivo']);
        $this->assertSame(0, $payload['empresas_carteira']);
        $this->assertSame(0, $payload['empresas_com_baseline']);
        $this->assertNull($payload['componentes']['nps_medio']);
        $this->assertNull($payload['componentes']['var_faturamento_pct']);
        $this->assertNull($payload['componentes']['var_margem_pct']);
        $this->assertNull($payload['componentes']['absenteismo_pct']);
        $this->assertNull($payload['nota_final']);
        $this->assertNull($payload['faixa_bonus']);
        $this->assertFalse($payload['faixa_promovida']);
        $this->assertSame(0, $payload['empresas_unicas']);
        $this->assertSame(0, $payload['vinculos_servico']);
        $this->assertSame(0, $payload['vinculos_financeiros']);
        $this->assertSame(0, $payload['vinculos_sem_fonte_financeira']);
        $this->assertSame('blocked', $payload['score_status']);
        $this->assertFalse($payload['componentes_disponiveis']['nps_medio']);
        $this->assertFalse($payload['componentes_disponiveis']['var_faturamento_pct']);
        $this->assertFalse($payload['componentes_disponiveis']['var_margem_pct']);
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suite. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suite roda com `--filter`, mesmo
 * padrão já documentado em `DesempenhoElegibilidadeTest`/`DesempenhoShopeeScoreTest`).
 */
class PayloadBaselineFlagOffTestProviderStub extends MetricsProviderFactory
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
