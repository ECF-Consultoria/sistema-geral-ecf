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
     * Task 3 (AGRE-02/04) — chaves novas de topo permitidas. Qualquer chave
     * que apareça sem passar por uma edição explícita desta constante reprova
     * o teste; é a concessão auditável do gate.
     *
     * 2026-08-05: `nota_final_por_empresa`/`score_status_por_empresa` deixaram
     * de ser condicionais ao shadow e passaram a estar SEMPRE no payload —
     * viraram metadado de auditoria ao lado da nota oficial, que agora vem da
     * agregação por indicador.
     */
    private const CHAVES_ADITIVAS_PERMITIDAS = [
        'empresas_score',
        'nota_final_por_empresa',
        'score_status_por_empresa',
        // `nota_final_legado` — a nota pelo método antigo (régua sobre a %
        // agregada da carteira), preservada como metadado de auditoria para
        // comparar contra a nota oficial sem recomputar.
        'nota_final_legado',
    ];

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
        $aditivasComponentes      = ['var_margem_pp']; // Task 3 (AGRE-04) — metadado de auditoria do shadow.

        $this->assertSame(
            $chavesLegadasComponentes,
            array_values(array_intersect(array_keys($payload['componentes']), $chavesLegadasComponentes))
        );
        $this->assertSame(
            $aditivasComponentes,
            array_values(array_diff(array_keys($payload['componentes']), $chavesLegadasComponentes))
        );

        $this->assertSame(['nps', 'faturamento', 'margem'], array_keys($payload['pontos_componentes']));
        // 2026-08-05 — `margem_amostra` passou a trazer SEMPRE `base`/`legado`:
        // o score por empresa deixou de ser shadow condicional e agora roda em
        // todo `compute()`, então a cobertura reportada é sempre a de
        // `margem_var_pp` (pontos percentuais), com os números antigos
        // preservados em `legado` para o gate FIXMARG-03.
        $this->assertSame(
            ['n_real', 'n_elegivel', 'cobertura', 'base', 'legado'],
            array_keys($payload['margem_amostra'])
        );
        $this->assertSame('margem_var_pp', $payload['margem_amostra']['base']);
        $this->assertSame(
            ['n_real', 'n_elegivel', 'cobertura'],
            array_keys($payload['margem_amostra']['legado'])
        );
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
    public function test_valores_congelados_da_agregacao_por_indicador(): void
    {
        $payload = $this->computePayload();

        // ─── Valores DERIVADOS do fixture, não copiados da saída ────────────
        // Reescrito em 2026-08-05, quando a nota passou a vir de
        // `computeNotaFinalPorIndicador()`. Os números abaixo foram calculados
        // à mão a partir da fixture ANTES de rodar o teste, de propósito: um
        // gate preenchido com o que o código imprimiu não prova nada — só
        // registra o comportamento, inclusive se estiver errado.
        //
        // Régua POR LOJA (CompanyScoreService), com as réguas de sempre:
        //   empresaA  fat +3,00%  → 4 pts │ margem null │ nps 1,0
        //   empresaB  fat +10,00% → 5 pts │ margem null (Shopee não fornece CMV)
        //                                 │ nps 1,0
        //   empresaC  fat null (sem baseline em julho) │ margem null │ nps 1,0
        //
        // Média POR INDICADOR, cada um com seu próprio denominador:
        //   faturamento = (4 + 5) / 2 = 4,50   ← C não entra, não tem valor
        //   margem      = null                 ← nenhuma loja tem margem
        //   nps         = (1 + 1 + 1) / 3 = 1,00
        //   nota        = (4,50 + 1,00) / 2 = 2,75
        //
        // Por que margem é null em TODA loja: o hotfix de 2026-07-24
        // (AdmanMetricDiffService::resolveMargemPct) só aceita o `.diff` nativo
        // da Adman quando `comparison_mode === 'previous_equal_length_window'`.
        // Mês EM CURSO resolve para `same_interval_previous_month`, então não
        // há variação de margem — comportamento de produção, não artefato do
        // fixture.
        //
        // Contraste com o método antigo, que dava 2,33: ele tirava a MEDIANA
        // das % (+3% e +10% → 6,5%) e só então aplicava a régua (6,5% > 5% →
        // 5 pts), premiando a carteira com a nota máxima de faturamento quando
        // nenhuma das duas lojas individualmente merecia 5. É exatamente a
        // inversão de ordem que esta mudança corrige.
        $this->assertSame(3, $payload['empresas_carteira']);
        $this->assertSame(2, $payload['empresas_com_baseline']);
        $this->assertSame(3, $payload['vinculos_financeiros']);
        $this->assertSame('sem_bonus', $payload['faixa_bonus']);

        // `partial` (não mais `official`): das 3 lojas só a Shopee fecha os
        // componentes que se espera dela (2 de 2); A e C ficam incompletas por
        // falta de margem, e 1/3 de cobertura está abaixo do patamar de 0,7.
        // Em mês FECHADO — o que paga bônus — a margem existe e o status volta
        // a `official`.
        $this->assertSame('partial', $payload['score_status']);

        $this->assertEqualsWithDelta(1.0, $payload['componentes']['nps_medio'], 0.001,
            'Mês em curso (agosto) → piso NPS 1.0 (computeNpsWindow, mês não fechado).');
        $this->assertEqualsWithDelta(6.50, $payload['componentes']['var_faturamento_pct'], 0.001,
            'Metadado legado preservado: mediana das % com baseline — A (+3%) e B (+10%).');
        $this->assertNull($payload['componentes']['var_margem_pct'],
            'Mês em curso: comparison_mode=same_interval_previous_month nunca usa calculated_fallback pra margem % (hotfix 2026-07-24).');

        $this->assertEqualsWithDelta(4.50, $payload['pontos_componentes']['faturamento'], 0.001,
            'Média dos pontos POR LOJA: A=4 (+3%) e B=5 (+10%); C sem baseline fica fora do denominador.');
        $this->assertNull($payload['pontos_componentes']['margem'],
            'Nenhuma loja tem margem: A e C sem diff no mês em curso, B é Shopee (fora da média desde 2026-08-05).');
        $this->assertEqualsWithDelta(1.00, $payload['pontos_componentes']['nps'], 0.001,
            'Piso 1,0 nas 3 lojas.');

        $this->assertSame(0, $payload['margem_amostra']['n_real']);
        $this->assertSame(2, $payload['margem_amostra']['n_elegivel']);
        $this->assertEqualsWithDelta(0.0, $payload['margem_amostra']['cobertura'], 0.0001);

        // (faturamento 4,50 + nps 1,00) / 2 = 2,75 — margem não entra no
        // denominador porque nenhuma loja tem o indicador.
        // Método antigo dava 2,33: (1,0 + 5,0 + 1,0)/3, com o faturamento
        // valendo 5 por causa da régua aplicada sobre a mediana das % (6,5%) e
        // a margem valendo o placeholder Shopee 1,0.
        $this->assertEqualsWithDelta(2.75, $payload['nota_final'], 0.001,
            '(faturamento 4,50 + nps 1,00) / 2 = 2,75.');

        // Sem arredondamento intermediário: a nota é o quociente exato, não o
        // resultado de somar componentes já arredondados.
        $this->assertSame(
            ($payload['pontos_componentes']['faturamento'] + $payload['pontos_componentes']['nps']) / 2,
            $payload['nota_final'],
            'nota_final tem que ser o quociente exato dos componentes presentes.'
        );
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
        // `shapeSemCarteira()` é um retorno ANTECIPADO: sai antes de calcular o
        // score por empresa, então não ganha `nota_final_por_empresa` nem
        // `score_status_por_empresa` — só `empresas_score` (vazio). Por isso a
        // lista aqui é própria, e não `CHAVES_ADITIVAS_PERMITIDAS`.
        $this->assertSame(
            ['empresas_score'],
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
