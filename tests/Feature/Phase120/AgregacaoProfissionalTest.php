<?php

namespace Tests\Feature\Phase120;

use App\Models\AdmanMetric;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 120 Plano 03 — cobre AGRE-01, AGRE-05 e AGRE-06 do
 * `REQUIREMENTS-v21.md`: a agregação do profissional por MÉDIA das
 * `nota_empresa` (D-01), a guarda de cobertura de 70% que decide
 * `official`/`partial`/`blocked` (D-02/D-03), e a preservação da trava da
 * Fase 109 para profissional só-Shopee, sem nenhum `if` especial (AGRE-06).
 *
 * Todos os cenários ativam o caminho novo via `config(['metrics.
 * performance_company_first_score' => true])` DENTRO do próprio teste — a
 * flag de PRODUÇÃO (`config/metrics.php`) continua `false`; nenhum teste
 * aqui muda o default.
 *
 * Testes 1-5 mockam `CompanyScoreService::computeEmpresasScore()` (Mockery
 * partial via `$this->mock()`) para controlar precisamente o shape de
 * `$empresasScore` que alimenta `computeNotaFinalPorEmpresa()`/
 * `computeScoreStatusPorEmpresa()` — o comportamento de
 * `CompanyScoreService` (fonte vencedora, placeholder Shopee, taxonomia de
 * status por empresa) já está provado pela Fase 119
 * (`CompanyScoreServiceFonteTest`/`CompanyScoreServiceStatusTest`); esta
 * suíte testa a CAMADA DE AGREGAÇÃO em cima dele. O restante do payload
 * (4 componentes legados, universo) continua vindo de uma fixture real
 * mínima — `compute()` é chamado por inteiro, nunca `computeCached()`.
 *
 * Testes 6-7 usam fixture 100% real (sem mock) porque testam o ESCOPO da
 * agregação (T-120-06/T-120-07) e o default da flag — nada a controlar
 * artificialmente ali.
 */
class AgregacaoProfissionalTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Mesmo molde de PayloadBaselineFlagOffTest/DesempenhoShopeeScoreTest —
        // agosto/2026 é o mês EM CURSO.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->app->instance(MetricsProviderFactory::class, new AgregacaoProfissionalTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-120-03',
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

    // ═══ Helpers ══════════════════════════════════════════════════════════

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

    /**
     * Fixture MÍNIMA e válida (`sem_carteira=false`) usada pelos testes 1-5
     * — o conteúdo real do Adman é irrelevante ali, porque
     * `CompanyScoreService::computeEmpresasScore()` é mockado. Só precisa
     * existir carteira o suficiente para `computeUniverso()` não cair no
     * shape `sem_carteira`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function criarFixtureBase(string $nomeUser): array
    {
        $user = $this->criarUserComCargo($nomeUser);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $empresa     = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-AGRE-BASE', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 2000.00);

        return ['user' => $user, 'empresa' => $empresa];
    }

    /**
     * Monta uma linha `object` no MESMO shape de
     * `CompanyScoreService::computeEmpresasScore()` — só os campos que
     * `computeNotaFinalPorEmpresa()`/`computeScoreStatusPorEmpresa()`/
     * `varMargemPpAgregado()` de fato leem.
     */
    private function linhaEmpresa(
        int $companyId,
        string $status,
        ?float $notaEmpresa,
        ?float $notaEmpresaParcial,
        ?float $margemVarPp = null
    ): object {
        return (object) [
            'company_id'           => $companyId,
            'company_name'         => "Empresa {$companyId}",
            'status'               => $status,
            'nota_empresa'         => $notaEmpresa,
            'nota_empresa_parcial' => $notaEmpresaParcial,
            'margem_var_pp'        => $margemVarPp,
        ];
    }

    /** Substitui `CompanyScoreService::computeEmpresasScore()` pela Collection fornecida. */
    private function mockCompanyScoreService(Collection $linhas): void
    {
        $this->mock(CompanyScoreService::class, function ($mock) use ($linhas) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn($linhas);
        });
    }

    // ═══ Teste 1 — AGRE-01: média exata das completas ═══════════════════════

    #[Test]
    public function test_nota_final_e_exatamente_a_media_das_notas_das_empresas_completas(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $fixture = $this->criarFixtureBase('Media Completas AGRE01');

        $this->mockCompanyScoreService(collect([
            2001 => $this->linhaEmpresa(2001, 'complete', 3.50, 3.50),
            2002 => $this->linhaEmpresa(2002, 'complete', 4.20, 4.20),
            2003 => $this->linhaEmpresa(2003, 'complete', 5.00, 5.00),
        ]));

        $r = app(DesempenhoScoreService::class)->compute($fixture['user'], Carbon::parse('2026-08-01'));

        $mediaEsperada = round(collect($r['empresas_score'])->avg(fn ($e) => $e->nota_empresa), 2);

        $this->assertEqualsWithDelta($mediaEsperada, $r['nota_final'], 0.001,
            'nota_final tem que ser EXATAMENTE a média das nota_empresa das 3 completas.');
        $this->assertEqualsWithDelta(4.23, $r['nota_final'], 0.001,
            '(3.50+4.20+5.00)/3 = 4.2333... -> 4.23.');
        $this->assertSame('official', $r['score_status'], 'Cobertura 100% (3 de 3 complete) -> official.');
    }

    // ═══ Teste 2 — AGRE-05/D-01: incompleta fora do denominador ═════════════

    #[Test]
    public function test_empresa_incompleta_fica_fora_do_denominador_e_nao_puxa_a_nota_para_cima(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $fixture = $this->criarFixtureBase('Denominador AGRE05');

        // Caso concreto do 120-CONTEXT.md: completa em ~4,53; parcial com
        // nota_empresa_parcial MAIOR (~4,80) — se entrasse na média, a nota
        // subiria, que é exatamente o abuso que a D-01 fecha.
        $this->mockCompanyScoreService(collect([
            3001 => $this->linhaEmpresa(3001, 'complete', 4.53, 4.53),
            3002 => $this->linhaEmpresa(3002, 'partial', null, 4.80),
        ]));

        $r = app(DesempenhoScoreService::class)->compute($fixture['user'], Carbon::parse('2026-08-01'));

        $this->assertEqualsWithDelta(4.53, $r['nota_final'], 0.001,
            'nota_final é a nota da COMPLETA, não a média das duas (que seria (4.53+4.80)/2=4.665, MAIOR).');

        $porEmpresa = collect($r['empresas_score'])->keyBy('company_id');
        $this->assertNull($porEmpresa[3002]->nota_empresa,
            'D-01: empresa parcial não tem nota_empresa (estrita) — só a parcial (média dos presentes).');
        $this->assertNotNull($porEmpresa[3002]->nota_empresa_parcial,
            'A linha existe no payload com nota_empresa_parcial preenchida — só não conta no denominador da média.');
    }

    // ═══ Teste 3 — AGRE-05/D-02/D-03: patamar de cobertura ═══════════════════

    #[Test]
    public function test_cobertura_abaixo_do_patamar_derruba_para_partial_sem_zerar_a_nota(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        // Cenário 1 — 3 de 10 complete = 30% (< 70%) -> partial, nota_final
        // continua sendo a média das 3 completas (a NOTA não zera, só a
        // etiqueta de confiança muda).
        $fixtureBaixa = $this->criarFixtureBase('Cobertura Baixa AGRE05');
        $linhasBaixa  = collect();
        foreach (range(1, 10) as $i) {
            $linhasBaixa[4000 + $i] = $i <= 3
                ? $this->linhaEmpresa(4000 + $i, 'complete', 3.00, 3.00)
                : $this->linhaEmpresa(4000 + $i, 'partial', null, 2.50);
        }
        $this->mockCompanyScoreService($linhasBaixa);

        $rBaixa = app(DesempenhoScoreService::class)->compute($fixtureBaixa['user'], Carbon::parse('2026-08-01'));

        $this->assertSame('partial', $rBaixa['score_status'], 'Cobertura 30% (3 de 10) < 70% -> partial.');
        $this->assertNotNull($rBaixa['nota_final'], 'partial NÃO zera a nota — continua a média das completas.');
        $this->assertEqualsWithDelta(3.00, $rBaixa['nota_final'], 0.001);

        // Cenário 2 — 8 de 10 complete = 80% (>= 70%) -> official. Caso
        // concreto do 120-CONTEXT.md: 20 de 26 é 77% (official); 15 de 26 é
        // 58% (partial) — aqui replicado em escala menor (8/10 e 3/10).
        $fixtureAlta = $this->criarFixtureBase('Cobertura Alta AGRE05');
        $linhasAlta  = collect();
        foreach (range(1, 10) as $i) {
            $linhasAlta[5000 + $i] = $i <= 8
                ? $this->linhaEmpresa(5000 + $i, 'complete', 4.00, 4.00)
                : $this->linhaEmpresa(5000 + $i, 'partial', null, 2.50);
        }
        $this->mockCompanyScoreService($linhasAlta);

        $rAlta = app(DesempenhoScoreService::class)->compute($fixtureAlta['user'], Carbon::parse('2026-08-01'));

        $this->assertSame('official', $rAlta['score_status'], 'Cobertura 80% (8 de 10) >= 70% -> official.');
        $this->assertEqualsWithDelta(4.00, $rAlta['nota_final'], 0.001);
    }

    // ═══ Teste 4 — AGRE-06: só-Shopee continua official sem if especial ═════

    #[Test]
    public function test_so_shopee_continua_official_sem_codigo_especial(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $fixture = $this->criarFixtureBase('So Shopee AGRE06');

        // Todas as linhas 'complete' — o placeholder de margem 1.0 conta como
        // componente presente (D-02 da Fase 119), então uma carteira 100%
        // Shopee tem cobertura 100% e cai em 'official' pela MESMA regra de
        // cobertura usada para qualquer outra carteira — nenhum `if` de
        // exceção para Shopee em computeScoreStatusPorEmpresa().
        $this->mockCompanyScoreService(collect([
            6001 => $this->linhaEmpresa(6001, 'complete', 3.07, 3.07, margemVarPp: null),
            6002 => $this->linhaEmpresa(6002, 'complete', 3.50, 3.50, margemVarPp: null),
        ]));

        $r = app(DesempenhoScoreService::class)->compute($fixture['user'], Carbon::parse('2026-08-01'));

        $this->assertSame('official', $r['score_status']);
        $this->assertNotNull($r['nota_final']);
        $this->assertTrue(
            collect($r['empresas_score'])->every(fn ($e) => $e->status === 'complete'),
            'Trava da Fase 109 preservada: todas as empresas Shopee entram complete (placeholder de margem).'
        );
    }

    // ═══ Teste 5 — D-02: blocked só no caso extremo ══════════════════════════

    #[Test]
    public function test_blocked_so_quando_nenhuma_empresa_tem_nota_alguma(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $fixture = $this->criarFixtureBase('Blocked Extremo D02');

        $this->mockCompanyScoreService(collect([
            7001 => $this->linhaEmpresa(7001, 'sem_dados', null, null),
            7002 => $this->linhaEmpresa(7002, 'sem_dados', null, null),
        ]));

        $r = app(DesempenhoScoreService::class)->compute($fixture['user'], Carbon::parse('2026-08-01'));

        $this->assertSame('blocked', $r['score_status']);
        $this->assertNull($r['nota_final'], 'D-91-01 força nota_final=null quando score_status=blocked (vale nos dois ramos).');
    }

    // ═══ Teste 6 — T-120-06/T-120-07: escopo da carteira e invalidação ═══════

    #[Test]
    public function test_agregacao_nao_sai_do_escopo_da_carteira_nem_reabsorve_empresa_invalidada(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $userX = $this->criarUserComCargo('Escopo Proprio AGRE06Scope');
        $userY = $this->criarUserComCargo('Outro Profissional AGRE06Scope');

        $servicoPerf   = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);

        // Empresa A — da carteira de X (performance), com Adman completo.
        $empresaA = $this->criarEmpresa();
        $empresaA->forceFill(['adman_account_id' => 'CUST-ESCOPO-A', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresaA->id, $servicoPerf, true);
        $this->inserirPivot($empresaA->id, $userX->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaA, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresaA, '2026-07', revenue: 10000, margem: 2000.00);

        // Empresa B — da carteira de X (shopee), sem dados financeiros
        // mockados (irrelevante pro teste — só precisa aparecer no universo).
        $empresaB = $this->criarEmpresa();
        $this->inserirPivot($empresaB->id, $userX->id, 'consultor', $servicoShopee);

        // Empresa C — da carteira de X, mas INVALIDADA na competência de
        // agosto/2026 (T-120-07) — precisa sumir de empresas_score.
        $empresaC = $this->criarEmpresa();
        $empresaC->forceFill(['adman_account_id' => 'CUST-ESCOPO-C', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresaC->id, $servicoPerf, true);
        $this->inserirPivot($empresaC->id, $userX->id, 'consultor', $servicoPerf);
        BonusInvalidacao::create([
            'company_id'  => $empresaC->id,
            'competencia' => '2026-08-01',
            'motivo'      => 'Fase 120 — teste de escopo não pode reabsorver empresa invalidada',
        ]);

        // Empresa D — de OUTRO profissional (userY), NUNCA da carteira de X
        // (T-120-06) — precisa estar ausente de empresas_score de X.
        $empresaD = $this->criarEmpresa();
        $empresaD->forceFill(['adman_account_id' => 'CUST-ESCOPO-D', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresaD->id, $servicoPerf, true);
        $this->inserirPivot($empresaD->id, $userY->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaD, '2026-08', revenue: 999999999);
        $this->mockAdman($empresaD, '2026-07', revenue: 1);

        $r = app(DesempenhoScoreService::class)->compute($userX, Carbon::parse('2026-08-01'));

        $idsEsperados = collect([$empresaA->id, $empresaB->id])->sort()->values()->all();
        $idsObtidos   = collect($r['empresas_score'])->pluck('company_id')->sort()->values()->all();

        $this->assertSame($idsEsperados, $idsObtidos,
            'empresas_score tem que ser EXATAMENTE [A, B] — C (invalidada) e D (outro profissional) ausentes.');
    }

    // ═══ Teste 7 — T-120-03: flag nasce desligada por default ═══════════════

    #[Test]
    public function test_flag_nasce_desligada_por_default(): void
    {
        // Sem NENHUM config([...]) de override aqui — é o default de
        // config/metrics.php que está sob teste.
        $this->assertFalse(config('metrics.performance_company_first_score'),
            'A flag de produção continua false — nenhum teste desta suíte muda o default.');
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suite. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suite roda com `--filter`, mesmo
 * padrão já documentado em `PayloadBaselineFlagOffTest`/`DesempenhoShopeeScoreTest`).
 */
class AgregacaoProfissionalTestProviderStub extends MetricsProviderFactory
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
