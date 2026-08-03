<?php

namespace Tests\Feature\Phase122;

use App\Models\AdmanMetric;
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
 * Fase 122 Plano 02 — SNAP-05/D-122-04: `margem_amostra` passa a medir
 * cobertura de `margem_var_pp` (pontos percentuais, por empresa) quando o
 * shadow roda, preservando os números legados (variação relativa agregada,
 * `contribution_margin_pct.diff_pct`) numa sub-chave `legado`.
 *
 * Testes 1-4 mockam `CompanyScoreService::computeEmpresasScore()` (Mockery
 * partial via `$this->mock()`) — mesmo padrão de
 * `tests/Feature/Phase120/AgregacaoProfissionalTest.php` — pra controlar
 * precisamente o shape de `$empresasScore` sem depender de fixture financeira
 * real nem de HTTP à Adman. O restante do payload continua vindo de uma
 * fixture mínima real (`criarFixtureBase`), só o bastante pra `compute()`
 * não cair no shape `sem_carteira`.
 *
 * Teste 5 usa a MESMA fixture real (sem mock) nos dois ramos — prova que
 * `margem_amostra.legado` (shadow ligado) é byte-idêntico a `margem_amostra`
 * (shadow desligado), na mesma competência.
 */
class MargemAmostraPpTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Mesmo molde de AgregacaoProfissionalTest — agosto/2026 é o mês EM CURSO.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->app->instance(MetricsProviderFactory::class, new MargemAmostraPpTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-122-02',
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
     * Fixture MÍNIMA e válida (`sem_carteira=false`) — o conteúdo real do
     * Adman é irrelevante nos testes 1-4, porque
     * `CompanyScoreService::computeEmpresasScore()` é mockado. Só precisa
     * existir carteira o suficiente pra `computeUniverso()` não cair no
     * shape `sem_carteira`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function criarFixtureBase(string $nomeUser): array
    {
        $user = $this->criarUserComCargo($nomeUser);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $empresa     = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-MPP-BASE', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 2000.00);

        return ['user' => $user, 'empresa' => $empresa];
    }

    /**
     * Monta uma linha `object` no MESMO shape de
     * `CompanyScoreService::computeEmpresasScore()` — só os campos que o
     * cálculo de `margem_amostra` (D-122-04) de fato lê.
     */
    private function linhaPp(int $companyId, ?string $fonteFinanceira, ?float $margemVarPp): object
    {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => $fonteFinanceira,
            'margem_var_pp'         => $margemVarPp,
            'nota_empresa'          => null,
            'nota_empresa_parcial'  => null,
            'status'                => 'partial',
        ];
    }

    /** Substitui `CompanyScoreService::computeEmpresasScore()` pela Collection fornecida. */
    private function mockCompanyScoreService(Collection $linhas): void
    {
        $this->mock(CompanyScoreService::class, function ($mock) use ($linhas) {
            $mock->shouldReceive('computeEmpresasScore')->andReturn($linhas);
        });
    }

    // ═══ Teste 1 — shadow desligado: shape legado intocado ═══════════════════

    #[Test]
    public function test_shadow_desligado_margem_amostra_tem_exatamente_3_chaves_com_os_valores_de_hoje(): void
    {
        $fixture = $this->criarFixtureBase('Shadow Off MPP01');

        $r = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: false);

        $this->assertSame(
            ['n_real', 'n_elegivel', 'cobertura'],
            array_keys($r['margem_amostra']),
            'Com o shadow desligado, margem_amostra não pode ganhar nenhuma chave nova (gate nº 4 do 121-VALIDATION.md).'
        );
    }

    // ═══ Teste 2 — shadow ligado: cobertura de margem_var_pp ══════════════════

    #[Test]
    public function test_shadow_ligado_margem_amostra_mede_cobertura_de_margem_var_pp(): void
    {
        $fixture = $this->criarFixtureBase('Shadow On MPP02');

        $this->mockCompanyScoreService(collect([
            8001 => $this->linhaPp(8001, 'adman', 3.5),
            8002 => $this->linhaPp(8002, 'adman', -1.2),
            8003 => $this->linhaPp(8003, 'adman', 0.0),
            8004 => $this->linhaPp(8004, 'adman', null),
        ]));

        $r = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        $this->assertSame(3, $r['margem_amostra']['n_real']);
        $this->assertSame(4, $r['margem_amostra']['n_elegivel']);
        $this->assertEqualsWithDelta(0.75, $r['margem_amostra']['cobertura'], 0.0001);
        $this->assertSame('margem_var_pp', $r['margem_amostra']['base']);
        $this->assertArrayHasKey('legado', $r['margem_amostra']);
    }

    // ═══ Teste 3 — Shopee e sem_fonte ficam FORA do denominador de pp ═════════

    #[Test]
    public function test_empresa_shopee_e_empresa_sem_fonte_ficam_fora_do_denominador_de_pp(): void
    {
        $fixture = $this->criarFixtureBase('Fora Denominador MPP03');

        $this->mockCompanyScoreService(collect([
            9001 => $this->linhaPp(9001, 'adman', 2.0),
            9002 => $this->linhaPp(9002, 'adman', -3.0),
            9003 => $this->linhaPp(9003, 'shopee', null),
            9004 => $this->linhaPp(9004, null, null),
        ]));

        $r = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        $this->assertSame(2, $r['margem_amostra']['n_elegivel'],
            'Só as 2 linhas adman contam — Shopee e sem_fonte ficam fora do denominador de pp.');
        $this->assertSame(2, $r['margem_amostra']['n_real']);
        $this->assertEqualsWithDelta(1.0, $r['margem_amostra']['cobertura'], 0.0001);
    }

    // ═══ Teste 4 — carteira só-Shopee: ausência não é degradação ══════════════

    #[Test]
    public function test_carteira_so_shopee_tem_n_elegivel_zero_e_cobertura_1(): void
    {
        $fixture = $this->criarFixtureBase('So Shopee MPP04');

        $this->mockCompanyScoreService(collect([
            10001 => $this->linhaPp(10001, 'shopee', null),
            10002 => $this->linhaPp(10002, 'shopee', null),
        ]));

        $r = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        $this->assertSame(0, $r['margem_amostra']['n_elegivel']);
        $this->assertSame(0, $r['margem_amostra']['n_real']);
        $this->assertEqualsWithDelta(1.0, $r['margem_amostra']['cobertura'], 0.0001,
            'Ausência legítima de expectativa de margem (só-Shopee) NÃO é degradação (110-CONTEXT.md).');
    }

    // ═══ Teste 5 — legado reproduz exatamente o shadow desligado ══════════════

    #[Test]
    public function test_margem_amostra_legado_reproduz_exatamente_o_shadow_desligado_na_mesma_fixture(): void
    {
        $fixture = $this->criarFixtureBase('Legado Identico MPP05');
        $mes     = Carbon::parse('2026-08-01');

        $semShadow = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], $mes, null, incluirEmpresasScore: false);

        // Mock só entra em cena na 2ª chamada — a 1ª (shadow desligado) nem
        // resolve CompanyScoreService::computeEmpresasScore().
        $this->mockCompanyScoreService(collect([
            11001 => $this->linhaPp(11001, 'adman', 1.5),
        ]));

        $comShadow = app(DesempenhoScoreService::class)
            ->compute($fixture['user'], $mes, null, incluirEmpresasScore: true);

        $this->assertSame(
            $semShadow['margem_amostra'],
            $comShadow['margem_amostra']['legado'],
            'legado tem que reproduzir EXATAMENTE os 3 números que o shadow desligado devolve.'
        );
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suíte. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suíte roda com `--filter`, mesmo
 * padrão documentado em `PayloadBaselineFlagOffTest`/`AgregacaoProfissionalTest`).
 */
class MargemAmostraPpTestProviderStub extends MetricsProviderFactory
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
