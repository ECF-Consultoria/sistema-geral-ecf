<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\BonusInvalidacao;
use App\Models\Company;
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
 * Fase 109 Plan 03 (SHOP-DES-01/02) — wiring da fonte Shopee no
 * `DesempenhoScoreService` (universo/score/ranking) e margem placeholder=1
 * para as empresas Shopee (piso da régua, arquitetura future-ready).
 *
 * Cobre as `<acceptance_criteria>` das Tasks 1 e 2 do `109-03-PLAN.md`:
 *  - Task 1 — `computeVarFaturamento` roteia por fonte via
 *    `MetricDiffDispatcher` (empresa Shopee entra no faturamento do score);
 *    regra de desempate travada (empresa com performance E shopee elegíveis
 *    resolve fonte='adman').
 *  - Task 2 — helper `margemPontos()` (blend ponderado por contagem):
 *    só-performance é regressão zero; só-Shopee produz nota_final != null
 *    com margem placeholder=1.0; misto faz blend; empresa Shopee invalidada
 *    na competência NÃO infla o denominador do blend.
 *
 * @see .planning/phases/109-.../109-03-PLAN.md <design_margem_placeholder>
 */
class DesempenhoShopeeScoreTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 é o mês EM CURSO (janela dia 1-15 vs dia 1-15 de julho) —
        // o dia 10 (usado pelos helpers mock*) existe nas duas janelas.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        // Stub local — força o path Adman em toda janela quando aplicável
        // (empresas Shopee nunca chamam este factory: o dispatcher roteia
        // direto pro ShopeeMetricDiffService, 100% leitura local). Zero HTTP.
        $this->app->instance(MetricsProviderFactory::class, new DesempenhoShopeeScoreTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-109-03',
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

    // ═══ Task 1 — dispatch por fonte no universo/faturamento ════════════════

    #[Test]
    public function test_faturamento_inclui_revenue_shopee_via_dispatcher(): void
    {
        $user = $this->criarUserComCargo('Faturamento Misto 109');

        $empresaAdman = $this->criarEmpresa();
        $empresaAdman->forceFill(['adman_account_id' => 'CUST-FAT-A', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresaAdman->id, $servicoPerf, true);
        $this->inserirPivot($empresaAdman->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaAdman, '2026-08', revenue: 11000); // +10%
        $this->mockAdman($empresaAdman, '2026-07', revenue: 10000);

        $empresaShopee = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresaShopee->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaShopee, '2026-08', revenue: 12000); // +20%
        $this->mockShopee($empresaShopee, '2026-07', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertEqualsWithDelta(15.0, $r['componentes']['var_faturamento_pct'], 0.001,
            'Fase 109: revenue Shopee entra na média de faturamento via MetricDiffDispatcher — (10%+20%)/2=15%.');
        $this->assertSame(2, $r['empresas_com_baseline']);
    }

    #[Test]
    public function test_empresa_com_performance_e_shopee_resolve_fonte_adman_desempate(): void
    {
        // Regra de desempate TRAVADA (idêntica ao Plano 02/Carteira): a MESMA
        // empresa com vínculo performance E shopee elegíveis resolve fonte='adman'.
        $user = $this->criarUserComCargo('Desempate Fonte 109');

        $empresa = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-DESEMPATE', 'marketplace' => 'meli'])->save();
        $servicoPerf   = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        // Adman real: +5%. Shopee ABSURDO na MESMA empresa — se vazasse pro
        // cálculo (dispatcher roteando errado), a % explodiria.
        $this->mockAdman($empresa, '2026-08', revenue: 10500);
        $this->mockAdman($empresa, '2026-07', revenue: 10000);
        $this->mockShopee($empresa, '2026-08', revenue: 999999);
        $this->mockShopee($empresa, '2026-07', revenue: 1);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertEqualsWithDelta(5.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Desempate travado: empresa com performance E shopee resolve fonte=adman (Shopee absurdo NÃO vaza).');
        $this->assertSame(1, $r['empresas_unicas']);
        $this->assertSame(2, $r['vinculos_servico']);
        $this->assertSame(2, $r['vinculos_financeiros'],
            'Os 2 vínculos (performance + shopee) são individualmente financial_metrics_eligible=true.');
    }

    // ═══ Task 2 — margem placeholder=1 (blend ponderado) ════════════════════

    #[Test]
    public function test_so_performance_regressao_zero_margem_pontos_e_nota_identicos_ao_baseline(): void
    {
        // Invariante inegociável do design (109-03-PLAN.md): profissional
        // SÓ-performance (nShopeePlaceholder=0) tem margemPontos IDÊNTICO a
        // reguaMargem(varMargemReal) — sem blend, sem placeholder.
        $user = $this->criarUserComCargo('Só Performance Regressão 109');

        $empresa = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-REGRESSAO', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 2000.00);
        // Histórico pré-baseline (trava de cobertura) — junho fica fora das
        // janelas jul/ago, só prova que a empresa opera desde antes do baseline.
        $this->mockAdman($empresa, '2026-06', revenue: 9500, margem: 1900.00);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertEqualsWithDelta(2.80, $r['componentes']['var_margem_pct'], 0.001);
        $this->assertEqualsWithDelta(4.0, $r['pontos_componentes']['margem'], 0.001,
            'Regressão zero: só-performance → margemPontos idêntico a reguaMargem(2.80%)=4.0, sem blend.');
        $this->assertEqualsWithDelta(3.00, $r['nota_final'], 0.001,
            'nota_final idêntica ao baseline pré-Fase-109: (nps piso 1.0 + régua_fat 4 + régua_margem 4)/3 = 3.00.');
        $this->assertSame('official', $r['score_status']);
        $this->assertSame(1, $r['vinculos_financeiros']);
        $this->assertSame(0, $r['vinculos_sem_fonte_financeira']);
    }

    #[Test]
    public function test_so_shopee_official_nota_final_nao_null_margem_placeholder_1(): void
    {
        $user = $this->criarUserComCargo('Só Shopee Placeholder 109');

        $empresa       = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresa, '2026-08', revenue: 11000);
        $this->mockShopee($empresa, '2026-07', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertNotSame('blocked', $r['score_status']);
        $this->assertNotSame('partial', $r['score_status']);
        $this->assertSame('official', $r['score_status'],
            'Carteira só-Shopee: as 2 dimensões esperadas (faturamento + NPS) estão presentes → complete → official.');
        $this->assertNotNull($r['nota_final']);

        // 2026-08-05 — a margem da loja Shopee saiu da conta em vez de valer
        // o placeholder 1,0 (a nota MÍNIMA). A Shopee não fornece CMV, então a
        // ausência é falta de dado da plataforma, não desempenho ruim: manter
        // 1,0 castigava quem tem carteira Shopee por algo fora do seu controle.
        $this->assertNull($r['pontos_componentes']['margem'],
            'Shopee fica FORA da média de margem — não entra mais com placeholder 1,0.');

        // 2026-08-10 (quick 260810-mt8) — a NOTA passou a dividir SEMPRE por 3,
        // com o indicador ausente valendo zero: (5,0 + 0 + 1,0) / 3 = 2,0.
        // Decisão do Maycon, tomada com este caso na mesa.
        //
        // Os três métodos, na mesma carteira, para deixar a diferença explícita:
        //   placeholder 1,0 (até 2026-08-05):        (5,0 + 1,0 + 1,0)/3 = 2,33
        //   média dos presentes (05/08 a 10/08):     (5,0 + 1,0)/2       = 3,00
        //   divisor fixo com ausente = 0 (hoje):     (5,0 + 0 + 1,0)/3   = 2,00
        //
        // CONSEQUÊNCIA: carteira só-Shopee tem teto de (5+0+5)/3 = 3,33 e nunca
        // alcança os 4,00 da primeira faixa de bônus. É a regra, não um efeito
        // colateral — ver `computeNotaFinalPorIndicador()`.
        $this->assertEqualsWithDelta(5.0, $r['pontos_componentes']['faturamento'], 0.001,
            'Faturamento +10% (10000 → 11000) → 5 pts.');
        $this->assertEqualsWithDelta(2.0, $r['nota_final'], 0.001,
            '(faturamento 5,0 + margem ausente 0 + nps 1,0) / 3 = 2,0.');

        $this->assertNull($r['componentes']['var_margem_pct'],
            'Shopee não fornece margem real — a % exposta continua null.');
    }

    #[Test]
    public function test_misto_ml_shopee_margem_pontos_blend_ponderado(): void
    {
        $user = $this->criarUserComCargo('Misto Blend 109');

        $empresaA    = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-BLEND-A', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaA, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresaA, '2026-07', revenue: 10000, margem: 2000.00);
        $this->mockAdman($empresaA, '2026-06', revenue: 9500, margem: 1900.00);

        $empresaB      = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresaB->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaB, '2026-08', revenue: 11000);
        $this->mockShopee($empresaB, '2026-07', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        // margemPontos = (reguaMargem(2.80%)=4.0 × nComMargemReal=1 + 1.0 × nShopeePlaceholder=1) / 2 = 2.50.
        $this->assertEqualsWithDelta(2.80, $r['componentes']['var_margem_pct'], 0.001,
            'var_margem_pct exposto continua a % REAL só da empresa A (performance) — placeholder não vaza aqui.');
        $this->assertEqualsWithDelta(2.50, $r['pontos_componentes']['margem'], 0.001);
        $this->assertSame('official', $r['score_status']);
    }

    #[Test]
    public function test_invalidacao_empresa_shopee_nao_infla_denominador_do_blend(): void
    {
        // Paridade com computeVarFaturamento/computeVarMargem (item 3/4):
        // empresa invalidada na competência some do bônus — inclusive do
        // denominador do blend margemPontos (nShopeePlaceholder).
        $user = $this->criarUserComCargo('Invalidação Shopee 109');

        // Empresa A — performance, margem real (contribui pra nComMargemReal).
        $empresaA = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-INVAL-A', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaA, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresaA, '2026-07', revenue: 10000, margem: 2000.00);
        $this->mockAdman($empresaA, '2026-06', revenue: 9500, margem: 1900.00);

        // Empresa B — Shopee, VÁLIDA (não invalidada).
        $empresaB      = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresaB->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaB, '2026-08', revenue: 11000); // +10%
        $this->mockShopee($empresaB, '2026-07', revenue: 10000);

        // Empresa C — Shopee, INVALIDADA na competência de agosto/2026. Número
        // absurdo — se contasse (faturamento OU denominador do blend), o
        // teste detecta.
        $empresaC = $this->criarEmpresa();
        $this->inserirPivot($empresaC->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaC, '2026-08', revenue: 50000); // +400% se contasse
        $this->mockShopee($empresaC, '2026-07', revenue: 10000);

        BonusInvalidacao::create([
            'company_id'  => $empresaC->id,
            'competencia' => '2026-08-01',
            'motivo'      => 'Fase 109 — teste de invalidação não pode inflar nShopeePlaceholder',
        ]);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        // Faturamento: só A (+3.00%) e B (+10.00%) entram — C invalidada sai
        // (mesmo tratamento pré-existente de computeVarFaturamento).
        $this->assertEqualsWithDelta(6.50, $r['componentes']['var_faturamento_pct'], 0.001,
            'Empresa C invalidada não entra na média de faturamento — (3.00+10.00)/2=6.50, não (3.00+10.00+400.00)/3.');
        $this->assertSame(2, $r['empresas_com_baseline']);

        // Margem: nComMargemReal=1 (empresa A); nShopeePlaceholder=1 (só B —
        // C invalidada NÃO conta). Se a invalidação vazasse, seria
        // (4.0×1 + 1.0×2)/3 = 2.00 (bug); o correto é (4.0×1 + 1.0×1)/2 = 2.50.
        $this->assertEqualsWithDelta(2.80, $r['componentes']['var_margem_pct'], 0.001,
            'var_margem_pct real só reflete a empresa A (única com margem real).');
        $this->assertEqualsWithDelta(2.50, $r['pontos_componentes']['margem'], 0.001,
            'nShopeePlaceholder pós-invalidação=1 (só empresaB) — se a invalidada contasse, margemPontos seria 2.00.');
    }

    // ═══ Espelhos "flag ligada" — REMOVIDOS em 2026-08-05 ════════════════════
    //
    // Existiam 4 testes aqui (`..._com_flag_ligada`), cada um duplicando o
    // setup de um dos originais acima e chamando `compute()` duas vezes na
    // mesma fixture — uma com `metrics.performance_company_first_score`
    // desligada e outra com ela ligada — para congelar a diferença entre os
    // dois ramos da bifurcação.
    //
    // A bifurcação acabou: a nota oficial passou a ser sempre
    // `computeNotaFinalPorIndicador()`, e a flag não escolhe mais nada. Os 4
    // espelhos viraram duplicatas exatas dos originais — mesma fixture, mesmo
    // resultado nas duas chamadas — e um teste que não pode mais falhar por
    // motivo próprio só custa tempo de suíte.
    //
    // Quem cobre a diferença entre os modelos de agregação hoje:
    //  - `Phase120\AgregacaoProfissionalTest` — o company-first, via
    //    `nota_final_por_empresa`;
    //  - `Phase120\PayloadBaselineFlagOffTest::test_valores_congelados_da_agregacao_por_indicador`
    //    — a nota oficial ponta a ponta, com linhas reais do CompanyScoreService.

    // ═══ cacheKey (2026-08-05 v16→v17: nota vem da agregação por indicador;
    // 2026-08-10 v17→v18 margem no mês corrente e v18→v19 divisor fixo) ══════

    #[Test]
    public function test_cache_key_bumpado_para_v19(): void
    {
        $user = $this->criarUserComCargo('Cache V19');
        $mes  = Carbon::parse('2026-08-01');

        $service = app(DesempenhoScoreService::class);
        $chave   = $service->cacheKey($user->id, $mes);

        $this->assertSame('desempenho.compute.v20.' . $user->id . '.current_month', $chave);
    }

    // ═══ Divisor fixo 3 (quick 260810-mt8, 2026-08-10) ══════════════════════

    /**
     * Carteira SEM NENHUM indicador continua com `nota_final` null — jamais 0.
     *
     * É a guarda que separa "não temos como medir" de "mediu e deu zero". Se o
     * divisor fixo fosse aplicado sem esta condição, todo profissional sem dado
     * apareceria com nota 0,00 no ranking, abaixo de quem foi medido de verdade,
     * e a trava D-91-01 (`blocked` para carteira sem vínculo financeiro) perderia
     * o sentido — ela existe justamente para NÃO dar nota a quem não tem base.
     */
    #[Test]
    public function test_carteira_sem_nenhum_indicador_tem_nota_null_e_nunca_zero(): void
    {
        $user = $this->criarUserComCargo('Sem Indicador Nenhum mt8');

        // Vínculo de POLOS: setor sem fonte financeira (nem Adman nem Shopee),
        // logo sem faturamento e sem margem; e sem NPS coletado.
        $empresa      = $this->criarEmpresa();
        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPolos);

        $r = app(DesempenhoScoreService::class)->compute($user, Carbon::parse('2026-08-01'));

        $this->assertNull($r['nota_final'], 'Sem indicador algum a nota é null — nunca 0,00.');
        $this->assertNotSame(0.0, $r['nota_final']);
        $this->assertSame('blocked', $r['score_status'],
            'D-91-01: carteira sem vínculo financeiro não recebe nota oficial.');
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suite. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suite roda com `--filter`, mesmo
 * padrão já documentado em `DesempenhoElegibilidadeTest`).
 */
class DesempenhoShopeeScoreTestProviderStub extends MetricsProviderFactory
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
