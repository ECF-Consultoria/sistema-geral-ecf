<?php

namespace Tests\Feature\V16;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 91 Plan 01 — suite TDD do `computeUniverso` por VÍNCULO DE SERVIÇO
 * (`CarteiraContextService::forUser()`), em vez da carteira consolidada
 * (`$user->companies()`).
 *
 * Cobre DESEMP-01, DESEMP-04, DESEMP-05, DESEMP-06, DESEMP-07:
 *  - DESEMP-01/04 — o universo financeiro (var_faturamento/var_margem) usa
 *    SÓ vínculos `financial_metrics_eligible=true`, deduplicado por empresa.
 *  - DESEMP-05 — `compute()` expõe os 6 metadados de auditoria
 *    (`empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`,
 *    `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis`).
 *  - DESEMP-06 — `score_status` (`official`/`partial`/`blocked`). Decisão
 *    D-91-01 (91-CONTEXT.md, usuário 2026-07-16 via AskUserQuestion):
 *    `blocked` (zero vínculos financeiros elegíveis — ex.: só-Shopee) força
 *    `nota_final=null` + `faixa_bonus=null` — SEM nota oficial até a
 *    diretoria aprovar régua. Sem tratamento especial de sort: `nota_final`
 *    null já vai pro fim do ranking existente (`sortByDesc(nota ?? -1)`).
 *    D-91-02 resolve a semântica dos 3 status: `official` = todos os
 *    componentes disponíveis (inclui MISTO Performance+Shopee — o financeiro
 *    vem só do subconjunto elegível); `partial` = tem vínculo financeiro
 *    elegível mas algum componente indisponível no período; `blocked` = zero
 *    vínculos financeiros elegíveis.
 *  - DESEMP-07 — `sem_carteira=true` só com ZERO vínculos ativos de
 *    qualquer setor (Matheus, só-Shopee, permanece no ranking com `blocked`).
 *
 * Cobre também o bump de cache `desempenho.compute.v3` → `v4` obrigatório
 * nesta fase (mesmo padrão de `BonusDualPathRegressaoTest::test_cache_
 * bumpado_para_v3`, agora um passo adiante).
 *
 * `computeNpsMedio`/`notasPorAtribuicao`/`notasLegado` são INTOCADOS por
 * esta fase — os cenários abaixo dependem apenas do fallback DESEMP-03
 * (sem respostas no mês → `nps_medio = 0.0`), nunca criam NpsSurvey/
 * NpsResponse.
 *
 * @see .planning/phases/91-.../91-01-PLAN.md
 * @see .planning/phases/91-.../91-CONTEXT.md §D-91-01, D-91-02
 */
class DesempenhoElegibilidadeTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    /** IDs de setor/cargo para a pivot `user_setores` (fonte canônica da dimensão). */
    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory com o schema real (constraints + cascade) — padrão do projeto.
        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 é o mês EM CURSO (janela dia 1-15 vs dia 1-15 de julho) —
        // o dia 10 (usado pelo helper mockAdman) existe nas duas janelas.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        // Stub local do MetricsProviderFactory — força o path Adman em ambas
        // as janelas (zero HTTP ao ML). Não reaproveita o stub do arquivo
        // Phase74 de propósito: classe secundária de outro arquivo de teste
        // não é autoload-confiável quando a suite roda com --filter.
        $this->app->instance(MetricsProviderFactory::class, new DesempenhoElegibilidadeTestProviderStub());

        // Fase 102 (fix plan-checker — BLOCKER) — ISOLAMENTO HTTP OBRIGATÓRIO:
        // compute() agora delega margem a AdmanMetricDiffService (HTTP quando
        // a empresa tem adman_account_id). O fake "sem .diff" força
        // calculated_fallback determinístico — golden vem 100% do fixture
        // local, nunca de prod.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-91',
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

    private function criarUserComCargo(string $nome, int $cargoId): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /** Empresa com `created_at` -3 meses — fora do filtro "empresa nova" do DESEMP-04. */
    private function criarEmpresa(string $createdAt = '-3 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        return $company->fresh();
    }

    /**
     * 1 row de AdmanMetric no dia 10 do mês informado — dia comum às duas
     * janelas (dia 1-15 mês corrente vs dia 1-15 mês anterior).
     */
    private function mockAdman(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_so_shopee_recebe_blocked_com_nota_null_e_permanece_no_ranking(): void
    {
        // Cenário Matheus (medido em prod) — 1 empresa, só vínculo Shopee.
        $user    = $this->criarUserComCargo('Matheus Só-Shopee', $this->cargoAnalistaId);
        $empresa = $this->criarEmpresa();

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        // Dados financeiros da empresa Shopee — NÃO podem vazar pro score
        // bloqueado (é exatamente o bug que esta fase corrige).
        $this->mockAdman($empresa, '2026-08', revenue: 11000, margem: 10500);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertFalse($r['sem_carteira'],
            'Matheus tem vínculo Shopee ativo — não é sem_carteira (DESEMP-07).');
        $this->assertSame('blocked', $r['score_status']);
        $this->assertNull($r['nota_final'],
            'D-91-01: blocked força nota_final=null (sem nota oficial até a diretoria aprovar régua).');
        $this->assertNull($r['faixa_bonus']);
        $this->assertFalse($r['faixa_promovida']);
        $this->assertNull($r['componentes']['var_faturamento_pct'],
            'Financeiro da empresa Shopee NÃO pode vazar pro score bloqueado.');
        $this->assertNull($r['componentes']['var_margem_pct']);
        // Fase 105 (v18.0 · NPSWIN-01) — FALLOUT ESPERADO: `$mes` (2026-08-01)
        // é o mês EM CURSO nesta suíte (setTestNow 15/08/2026) →
        // `computeNpsWindow()` exclui o componente direto (`null`, nunca
        // desloca nem usa 0.0 — D1). Não é mais o fallback DESEMP-03 de
        // "sem respostas → 0.0" (esse só se aplica a mês FECHADO).
        $this->assertNull($r['componentes']['nps_medio'],
            'Mês em curso → nps_medio excluído (null), não mais 0.0 (Fase 105 · NPSWIN-01).');
        $this->assertSame(1, $r['empresas_unicas']);
        $this->assertSame(1, $r['vinculos_servico']);
        $this->assertSame(0, $r['vinculos_financeiros']);
        $this->assertSame(1, $r['vinculos_sem_fonte_financeira']);
        $this->assertSame(0, $r['empresas_com_baseline']);
    }

    #[Test]
    public function test_misto_e_official_com_financeiro_so_do_vinculo_elegivel(): void
    {
        // D-91-02: profissional MISTO (Performance + Shopee) é OFFICIAL — o
        // financeiro vem só do subconjunto elegível dele.
        $user = $this->criarUserComCargo('Analista Misto', $this->cargoAnalistaId);

        // Empresa A — Performance elegível: +3.00% faturamento.
        // Fase 102 (BON-03) var_margem_pct: precisa de adman_account_id
        // (senão AdmanMetricDiffService retorna emptyMetrics(), sem HTTP) e a
        // margem passa a ser percentageMargin (não mais R$ absoluto):
        // pctBaseline=2000/10000×100=20,00%; pctAtual=2117,68/10300×100=
        // 20,56% (exato) → diff=(20,56-20,00)/20,00×100=+2,80% (mesmo valor
        // numérico da v17, agora sob a fórmula pct-based — não é coincidência
        // de preservação, é a álgebra escolhida pro fixture).
        $empresaA    = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-MISTO-A', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaA, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresaA, '2026-07', revenue: 10000, margem: 2000.00);

        // Empresa B — Shopee, sem fonte financeira. Números absurdos: se
        // vazarem pro cálculo, o teste detecta (var_faturamento_pct explodiria).
        $empresaB      = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresaB->id, $user->id, 'consultor', $servicoShopee);
        $this->mockAdman($empresaB, '2026-08', revenue: 99999);
        $this->mockAdman($empresaB, '2026-07', revenue: 1);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertSame('official', $r['score_status']);
        $this->assertEqualsWithDelta(3.00, $r['componentes']['var_faturamento_pct'], 0.001);
        $this->assertEqualsWithDelta(2.80, $r['componentes']['var_margem_pct'], 0.001);
        $this->assertSame(1, $r['empresas_com_baseline']);
        $this->assertSame(2, $r['empresas_unicas']);
        $this->assertSame(2, $r['vinculos_servico']);
        $this->assertSame(1, $r['vinculos_financeiros']);
        $this->assertSame(1, $r['vinculos_sem_fonte_financeira']);
        // Fase 105 (v18.0 · NPSWIN-01) — FALLOUT ESPERADO: `$mes` (2026-08-01)
        // é mês EM CURSO → nps_medio excluído (null), não mais 0.0→clamp 1pt.
        // nota_final passa a ser a média só dos financeiros disponíveis:
        // régua_fat(+3.00%)=4pts; régua_margem(+2.80%)=4pts → (4 + 4) / 2 = 4.00
        // (era 3.00 quando o NPS 0.0 ainda entrava na média).
        $this->assertEqualsWithDelta(4.00, $r['nota_final'], 0.001);
        $this->assertSame('basico', $r['faixa_bonus'],
            'nota 4.00 cai na faixa basico [4.00,4.49] (seed bonus_faixas) — antes era sem_bonus com o NPS 0.0 puxando a média pra baixo.');
        $this->assertSame(2, $r['empresas_carteira'],
            'Compat DESEMP-05: empresas_carteira reflete empresas_unicas.');
    }

    #[Test]
    public function test_dois_vinculos_na_mesma_empresa_nao_duplicam_financeiro(): void
    {
        // Pitfall 4 do 91-RESEARCH: um profissional com Performance E Shopee
        // na MESMA empresa não pode contar a empresa 2× no SUM financeiro.
        $user = $this->criarUserComCargo('Analista Dedup', $this->cargoAnalistaId);

        $empresa       = $this->criarEmpresa();
        $servicoPerf   = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);

        $this->mockAdman($empresa, '2026-08', revenue: 10500);
        $this->mockAdman($empresa, '2026-07', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertSame(1, $r['empresas_unicas']);
        $this->assertSame(2, $r['vinculos_servico']);
        $this->assertSame(1, $r['vinculos_financeiros']);
        $this->assertSame(1, $r['empresas_com_baseline'],
            'Se duplicasse por vínculo, empresas_com_baseline seria 2.');
        $this->assertEqualsWithDelta(5.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Se a empresa entrasse 2× no SUM, a % de variação ficaria distorcida.');
    }

    #[Test]
    public function test_partial_quando_vinculo_elegivel_sem_dados_financeiros_no_periodo(): void
    {
        // D-91-02: partial = tem vínculo financeiro elegível, mas o
        // componente financeiro está indisponível no período (sem baseline
        // Adman) — a nota é calculada normalmente com o que existe (NPS).
        $user = $this->criarUserComCargo('Analista Partial', $this->cargoAnalistaId);

        $empresa     = $this->criarEmpresa();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        // ZERO rows AdmanMetric de propósito — sem dado financeiro no período.

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertSame('partial', $r['score_status']);
        $this->assertNull($r['componentes']['var_faturamento_pct']);
        $this->assertNull($r['componentes']['var_margem_pct']);
        // Fase 105 (v18.0 · NPSWIN-01) — FALLOUT ESPERADO: `$mes` (2026-08-01)
        // é mês EM CURSO → nps_medio também é `null` (excluído, não mais
        // 0.0→clamp 1pt). Com os 3 componentes null, `computeNotaFinal`
        // retorna null — não há mais nenhum componente disponível pra média
        // (antes o NPS 0.0 sozinho sustentava nota_final=1.00).
        $this->assertNull($r['componentes']['nps_medio']);
        $this->assertNull($r['nota_final'],
            'Fase 105: mês em curso exclui NPS também — sem nenhum componente disponível, nota_final vira null (era 1.00 quando o NPS 0.0 sozinho sustentava a média).');
        $this->assertNull($r['faixa_bonus']);
    }

    #[Test]
    public function test_sem_carteira_so_quando_zero_vinculos_ativos(): void
    {
        // DESEMP-07 — zero linhas em company_users (nenhum setor).
        $user = $this->criarUserComCargo('Analista Sem Carteira', $this->cargoAnalistaId);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertTrue($r['sem_carteira']);
        $this->assertIsString($r['motivo']);
        $this->assertStringContainsString('Sem carteira em', $r['motivo']);
        $this->assertNull($r['nota_final']);
        // shapeSemCarteira() também expõe os 6 metadados novos, zerados.
        $this->assertSame(0, $r['empresas_unicas']);
        $this->assertSame(0, $r['vinculos_servico']);
        $this->assertSame(0, $r['vinculos_financeiros']);
        $this->assertSame(0, $r['vinculos_sem_fonte_financeira']);
        $this->assertSame('blocked', $r['score_status']);
        $this->assertFalse($r['componentes_disponiveis']['nps_medio']);
        $this->assertFalse($r['componentes_disponiveis']['var_faturamento_pct']);
        $this->assertFalse($r['componentes_disponiveis']['var_margem_pct']);
    }

    #[Test]
    public function test_compute_expoe_os_6_metadados_de_elegibilidade(): void
    {
        // DESEMP-05 — reusa fixture mínima performance (variante do teste 2).
        $user = $this->criarUserComCargo('Analista Metadados', $this->cargoAnalistaId);

        $empresa     = $this->criarEmpresa();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 10280);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-08-01'));

        $this->assertArrayHasKey('empresas_unicas', $r);
        $this->assertArrayHasKey('vinculos_servico', $r);
        $this->assertArrayHasKey('vinculos_financeiros', $r);
        $this->assertArrayHasKey('vinculos_sem_fonte_financeira', $r);
        $this->assertArrayHasKey('score_status', $r);
        $this->assertArrayHasKey('componentes_disponiveis', $r);
        $this->assertArrayHasKey('nps_medio', $r['componentes_disponiveis']);
        $this->assertArrayHasKey('var_faturamento_pct', $r['componentes_disponiveis']);
        $this->assertArrayHasKey('var_margem_pct', $r['componentes_disponiveis']);
        $this->assertSame($r['empresas_unicas'], $r['empresas_carteira'],
            'Compat DESEMP-05: Fase 92 consome empresas_carteira sem recomputar.');
    }

    #[Test]
    public function test_cache_bumpado_para_v6(): void
    {
        // Bump v5→v6 obrigatório da Fase 105 (v18.0 · NPSWIN-01/02, ex-v4→v5
        // da Fase 102/BON-01/02/03) — mesmo padrão de
        // BonusDualPathRegressaoTest::test_cache_bumpado_para_v6, um passo
        // adiante. User sem carteira de propósito: computeCached corta cedo
        // no shape sem_carteira e não toca o MetricsProviderFactory.
        //
        // A chave esperada vem do helper público `cacheKey()` (nunca
        // hardcoded aqui): `$mes` (2026-08-01) é o mês CORRENTE nesta suíte
        // (`Carbon::setTestNow` congela 15/08/2026) → period_key='current_month'.
        $user = $this->criarUserComCargo('Analista Cache v6', $this->cargoAnalistaId);

        $mes     = Carbon::parse('2026-08-01');
        $chaveV5 = sprintf('desempenho.compute.v5.%d.%s', $user->id, $mes->format('Y-m'));

        $service = app(DesempenhoScoreService::class);
        $chaveV6 = $service->cacheKey($user->id, $mes);

        // Lixo reconhecível na chave v5 — representa o valor que o Redis de
        // prod tem antes deste deploy.
        Cache::put($chaveV5, ['nota_final' => 99.9], 600);

        $resultado = $service->computeCached($user, $mes);

        $this->assertNotSame(99.9, $resultado['nota_final'] ?? null,
            'computeCached NÃO pode devolver o valor cacheado sob a chave v5');
        $this->assertNull($resultado['nota_final'],
            'o retorno deve ser o shape recalculado (sem carteira → nota_final null)');
        $this->assertTrue($resultado['sem_carteira']);
        $this->assertTrue(Cache::has($chaveV6),
            'computeCached deve gravar o resultado sob a chave desempenho.compute.v6 (via helper cacheKey())');
        $this->assertSame(['nota_final' => 99.9], Cache::get($chaveV5),
            'a chave v5 não é apagada — vira órfã e expira por TTL');
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (caseFor
 * sempre 'so-adman', forCompany sempre vazio) em todos os cenários desta
 * suite. Zero HTTP ao ML/Adman real.
 *
 * Não importa o stub equivalente de `tests/Feature/Phase74/
 * DesempenhoScoreServiceTest.php` de propósito: classe secundária de outro
 * arquivo de teste não é autoload-confiável quando a suite roda com
 * `--filter` (91-01-PLAN.md).
 */
class DesempenhoElegibilidadeTestProviderStub extends MetricsProviderFactory
{
    public function __construct()
    {
        // Intencionalmente não chama parent::__construct() — as deps reais
        // (AdmanMetricsProvider/MlMetricsProvider) nunca são exercitadas.
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
