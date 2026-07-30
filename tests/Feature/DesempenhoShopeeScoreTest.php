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
            'faturamento com baseline (shopee_metrics) + margem placeholder → official.');
        $this->assertNotNull($r['nota_final']);
        $this->assertEqualsWithDelta(1.0, $r['pontos_componentes']['margem'], 0.001,
            'margemPontos: só-Shopee (nComMargemReal=0) → placeholder puro = 1.0.');
        $this->assertNull($r['componentes']['var_margem_pct'],
            'Shopee não fornece margem real — a % exposta continua null (placeholder não vaza pro número exibido).');
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

    // ═══ D-05 (Fase 120 · Plano 03 · gate nº 3) — espelhos flag-ligada ═══════
    //
    // Dos 7 testes desta classe, os 4 acima ("regressão zero", "só-Shopee",
    // "misto blend" e "invalidação") dependem de `margemPontos()` — o blend
    // ponderado por contagem que só existe no caminho LEGADO. Os 4 métodos
    // abaixo são NOVOS e ACRESCENTADOS (nunca reescrevem os originais —
    // `git diff` deste arquivo, a partir do commit do Plano 01, só tem
    // linhas adicionadas): cada um duplica o MESMO setup do original
    // correspondente, chama `compute()` DUAS vezes na MESMA fixture — uma
    // com a flag desligada (`$legado`) e uma com a flag ligada
    // (`$novo`, via `config(['metrics.performance_company_first_score' =>
    // true])`) — e congela os DOIS resultados como literais, capturados em
    // execução (2026-07-30), nunca `computeCached()`.
    //
    // Por que duas chamadas na mesma fixture, em vez de comparar contra o
    // literal hardcoded do teste original acima: os 3 originais
    // "regressão zero"/"misto"/"invalidação" hoje FALHAM por um achado
    // PRÉ-EXISTENTE e fora do escopo desta fase (`120-01-SUMMARY.md` —
    // hotfix de 2026-07-24 em `AdmanMetricDiffService::resolveMargemPct()`:
    // mês EM CURSO nunca usa `calculated_fallback` pra margem, então
    // `var_margem_pct`/`margem_pontos` saem `null` mesmo com margem real
    // sincronizada). Comparar contra o literal do teste original teria
    // herdado essa mesma obsolescência. Chamando `compute()` duas vezes na
    // MESMA fixture, o `$legado` capturado aqui É o comportamento real e
    // atual do caminho antigo — o par `$legado`×`$novo` é honesto e
    // reprodutível, independente do achado do hotfix.
    //
    // AGRE-04 (prova viva): `pontos_componentes.margem` e
    // `componentes.var_margem_pct` são IDÊNTICOS entre `$legado` e `$novo`
    // em TODOS os 4 espelhos — a bifurcação (Task 1 do Plano 03) só troca
    // `nota_final`/`score_status`, nunca os 4 componentes agregados, que
    // continuam vindo do caminho antigo (`computeVarFaturamento`/
    // `computeVarMargem`/`margemPontos`) nos DOIS ramos.

    #[Test]
    public function test_so_performance_regressao_zero_margem_pontos_e_nota_identicos_ao_baseline_com_flag_ligada(): void
    {
        // Mesmo setup do teste acima (empresa CUST-REGRESSAO, só-performance).
        $user = $this->criarUserComCargo('Só Performance Regressão 109 Flag Ligada');

        $empresa = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-REGRESSAO-FLAG', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 2000.00);
        $this->mockAdman($empresa, '2026-06', revenue: 9500, margem: 1900.00);

        $service = app(DesempenhoScoreService::class);
        $legado  = $service->compute($user, Carbon::parse('2026-08-01'));
        config(['metrics.performance_company_first_score' => true]);
        $novo = $service->compute($user, Carbon::parse('2026-08-01'));

        // AGRE-04 — os componentes agregados são idênticos nos dois ramos.
        $this->assertSame($legado['pontos_componentes']['margem'], $novo['pontos_componentes']['margem']);
        $this->assertSame($legado['componentes']['var_margem_pct'], $novo['componentes']['var_margem_pct']);

        // Valores REAIS capturados em execução (2026-07-30) — o achado do
        // hotfix (ver comentário de bloco acima) faz `var_margem_pct`/
        // `margem_pontos` saírem `null` nos dois ramos para esta fixture
        // (mês em curso), mesmo com margem real sincronizada.
        $this->assertNull($legado['pontos_componentes']['margem']);
        $this->assertNull($legado['componentes']['var_margem_pct']);

        // Antigo × novo — a divergência real desta fixture:
        //   LEGADO: nota_final=2.50 — computeNotaFinal() tolera o componente
        //     margem ausente e tira a média só dos 2 presentes
        //     (nps piso 1.0 + fatPts 4.0)/2 = 2.50 — score_status='partial'
        //     (varFat presente mas margemPontos=null).
        //   NOVO:   nota_final=null — computeNotaFinalPorEmpresa() exige que
        //     a EMPRESA seja 'complete' (os 3 componentes presentes) pra
        //     contar; com margem ausente ela é 'partial' (D-01), e sem
        //     nenhuma empresa completa a média não tem o que promediar —
        //     score_status='partial' pela cobertura (0 de 1 = 0% < 70%).
        // Isto NÃO é a divergência clássica régua-da-média×média-das-réguas
        // (que exige >= 2 empresas) — é a divergência de RIGOR do D-01: o
        // agregado legado tolera componente ausente na SUA PRÓPRIA média;
        // o caminho novo exige completude por empresa antes de entrar em
        // qualquer média. Ambas são leituras "corretas" dentro de cada
        // desenho — a Fase 121 quantifica o impacto.
        $this->assertEqualsWithDelta(2.50, $legado['nota_final'], 0.001);
        $this->assertSame('partial', $legado['score_status']);
        $this->assertNull($novo['nota_final']);
        $this->assertSame('partial', $novo['score_status']);
    }

    #[Test]
    public function test_so_shopee_official_nota_final_nao_null_margem_placeholder_1_com_flag_ligada(): void
    {
        // Mesmo setup do teste acima (empresa só-Shopee).
        $user = $this->criarUserComCargo('Só Shopee Placeholder 109 Flag Ligada');

        $empresa       = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresa, '2026-08', revenue: 11000);
        $this->mockShopee($empresa, '2026-07', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $legado  = $service->compute($user, Carbon::parse('2026-08-01'));
        config(['metrics.performance_company_first_score' => true]);
        $novo = $service->compute($user, Carbon::parse('2026-08-01'));

        // AGRE-04 — idênticos nos dois ramos.
        $this->assertSame($legado['pontos_componentes']['margem'], $novo['pontos_componentes']['margem']);
        $this->assertSame($legado['componentes']['var_margem_pct'], $novo['componentes']['var_margem_pct']);
        $this->assertEqualsWithDelta(1.0, $legado['pontos_componentes']['margem'], 0.001,
            'Placeholder Shopee — idêntico ao teste original acima, não muda com a flag.');
        $this->assertNull($legado['componentes']['var_margem_pct']);

        // Antigo × novo — carteira 100% Shopee com UMA única empresa: sem
        // outra empresa pra promediar, o caminho novo simplesmente devolve a
        // `nota_empresa` daquela única linha — que aqui coincide
        // numericamente com o agregado legado (2.33 nos dois ramos). Nenhuma
        // divergência de granularidade é observável com 1 empresa só — é
        // esperado (regressão-zero por construção, não por desenho de
        // régua). Ambos `official`: trava da Fase 109 preservada nos dois
        // ramos, sem `if` especial para Shopee em nenhum dos dois.
        $this->assertEqualsWithDelta(2.33, $legado['nota_final'], 0.001);
        $this->assertSame('official', $legado['score_status']);
        $this->assertEqualsWithDelta(2.33, $novo['nota_final'], 0.001);
        $this->assertSame('official', $novo['score_status']);
    }

    #[Test]
    public function test_misto_ml_shopee_margem_pontos_blend_ponderado_com_flag_ligada(): void
    {
        // Mesmo setup do teste acima (empresaA Adman + empresaB Shopee).
        $user = $this->criarUserComCargo('Misto Blend 109 Flag Ligada');

        $empresaA    = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-BLEND-A-FLAG', 'marketplace' => 'meli'])->save();
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
        $legado  = $service->compute($user, Carbon::parse('2026-08-01'));
        config(['metrics.performance_company_first_score' => true]);
        $novo = $service->compute($user, Carbon::parse('2026-08-01'));

        // AGRE-04 — idênticos nos dois ramos.
        $this->assertSame($legado['pontos_componentes']['margem'], $novo['pontos_componentes']['margem']);
        $this->assertSame($legado['componentes']['var_margem_pct'], $novo['componentes']['var_margem_pct']);
        $this->assertEqualsWithDelta(1.0, $legado['pontos_componentes']['margem'], 0.001,
            'Blend legado: nComMargemReal=0 (achado do hotfix, ver bloco acima) + nShopeePlaceholder=1 -> 1.0 puro.');

        // Antigo × novo — a divergência REAL desta fixture está no
        // `score_status`, não no valor numérico de `nota_final` (que
        // coincide em 2.33 nos dois ramos para estes números específicos):
        //   LEGADO: score_status='official' — o blend `margemPontos()`
        //     NUNCA devolve null enquanto houver PELO MENOS UMA empresa
        //     Shopee no denominador (mesmo que a margem real da empresa A
        //     esteja indisponível); o blend "tapa o buraco" com o
        //     placeholder e o agregado parece 100% coberto.
        //   NOVO:   score_status='partial' — por empresa, a empresaA está
        //     'partial' (margem real ausente, D-01: só 2 de 3 componentes),
        //     só a empresaB (Shopee) está 'complete'; cobertura = 1 de 2 =
        //     50% < 70% -> partial. A cobertura por empresa EXPÕE o que o
        //     blend agregado escondia.
        // Esta é a divergência que a Fase 121 precisa quantificar: o
        // caminho novo é mais RIGOROSO (expõe cobertura real), não errado.
        $this->assertEqualsWithDelta(2.33, $legado['nota_final'], 0.001);
        $this->assertSame('official', $legado['score_status']);
        $this->assertEqualsWithDelta(2.33, $novo['nota_final'], 0.001);
        $this->assertSame('partial', $novo['score_status']);
    }

    #[Test]
    public function test_invalidacao_empresa_shopee_nao_infla_denominador_do_blend_com_flag_ligada(): void
    {
        // Mesmo setup do teste acima (empresaA Adman + empresaB Shopee válida
        // + empresaC Shopee invalidada na competência de agosto/2026).
        $user = $this->criarUserComCargo('Invalidação Shopee 109 Flag Ligada');

        $empresaA = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-INVAL-A-FLAG', 'marketplace' => 'meli'])->save();
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

        $empresaC = $this->criarEmpresa();
        $this->inserirPivot($empresaC->id, $user->id, 'consultor', $servicoShopee);
        $this->mockShopee($empresaC, '2026-08', revenue: 50000);
        $this->mockShopee($empresaC, '2026-07', revenue: 10000);

        BonusInvalidacao::create([
            'company_id'  => $empresaC->id,
            'competencia' => '2026-08-01',
            'motivo'      => 'Fase 120 Plano 03 — espelho flag-ligada não pode reabsorver empresa invalidada',
        ]);

        $service = app(DesempenhoScoreService::class);
        $legado  = $service->compute($user, Carbon::parse('2026-08-01'));
        config(['metrics.performance_company_first_score' => true]);
        $novo = $service->compute($user, Carbon::parse('2026-08-01'));

        // AGRE-04 — idênticos nos dois ramos.
        $this->assertSame($legado['pontos_componentes']['margem'], $novo['pontos_componentes']['margem']);
        $this->assertSame($legado['componentes']['var_margem_pct'], $novo['componentes']['var_margem_pct']);
        $this->assertEqualsWithDelta(1.0, $legado['pontos_componentes']['margem'], 0.001,
            'nShopeePlaceholder pós-invalidação=1 (só empresaB — C invalidada não conta), mesmo achado do hotfix acima.');

        // Mesma divergência estrutural do teste "misto" acima (blend tapa o
        // buraco vs cobertura por empresa expõe), com o adicional desta
        // suíte: a empresa invalidada (C) tem que estar AUSENTE de
        // `empresas_score` no caminho novo — T-120-07.
        $this->assertEqualsWithDelta(2.33, $legado['nota_final'], 0.001);
        $this->assertSame('official', $legado['score_status']);
        $this->assertEqualsWithDelta(2.33, $novo['nota_final'], 0.001);
        $this->assertSame('partial', $novo['score_status']);

        $idsPresentes = collect($novo['empresas_score'])->pluck('company_id')->sort()->values()->all();
        $this->assertSame(
            [$empresaA->id, $empresaB->id],
            $idsPresentes,
            'empresaC invalidada não pode reaparecer em empresas_score do caminho novo.'
        );
    }

    // ═══ cacheKey v14 (Fase 120: bump v13→v14, AGRE-03) ══════════════════════

    #[Test]
    public function test_cache_key_bumpado_para_v14(): void
    {
        $user = $this->criarUserComCargo('Cache V14 120');
        $mes  = Carbon::parse('2026-08-01');

        $service = app(DesempenhoScoreService::class);
        $chave   = $service->cacheKey($user->id, $mes);

        $this->assertSame('desempenho.compute.v14.' . $user->id . '.current_month', $chave);
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
