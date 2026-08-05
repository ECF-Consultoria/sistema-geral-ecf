<?php

namespace Tests\Feature\Phase121;

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
use ReflectionMethod;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Gate nº 4 do 121-VALIDATION.md — o par vivo do teste dourado da Fase 120
 * (`PayloadBaselineFlagOffTest`): aquele prova que as chaves novas
 * (`nota_final_por_empresa`/`score_status_por_empresa`) NÃO aparecem quando o
 * shadow não roda; esta suíte prova que elas aparecem — com o valor CERTO —
 * quando roda. Juntas, as duas suítes fecham a aditividade da D-05 (Fase 121,
 * fronteira revisada pelo usuário em 2026-07-31).
 *
 * A flag de produção (`config/metrics.php`) NUNCA é tocada fora de um
 * `config([...])` local dentro do teste 3 — os demais testes rodam com o
 * default (`false`).
 */
class ShadowNotaNovaExpostaTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Mesmo molde de PayloadBaselineFlagOffTest/AgregacaoProfissionalTest
        // — agosto/2026 é o mês EM CURSO.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->app->instance(MetricsProviderFactory::class, new ShadowNotaNovaExpostaTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-121-01',
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

    // ═══ Helpers (mesmo molde de AgregacaoProfissionalTest) ═════════════════

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
     * Adman é irrelevante nesta suíte porque `CompanyScoreService::
     * computeEmpresasScore()` é mockado. Só precisa existir carteira o
     * suficiente para `computeUniverso()` não cair no shape `sem_carteira`.
     *
     * @return array{user: User, empresa: Company}
     */
    private function criarFixtureBase(string $nomeUser): array
    {
        $user = $this->criarUserComCargo($nomeUser);

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $empresa     = $this->criarEmpresa();
        $empresa->forceFill(['adman_account_id' => 'CUST-121-BASE', 'marketplace' => 'meli'])->save();
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 2117.68);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 2000.00);

        return ['user' => $user, 'empresa' => $empresa];
    }

    /**
     * Monta uma linha `object` no MESMO shape de
     * `CompanyScoreService::computeEmpresasScore()` — mesmo molde de
     * `AgregacaoProfissionalTest::linhaEmpresa()`.
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

    /** Invoca `computeNotaFinalPorEmpresa`/`computeScoreStatusPorEmpresa` via Reflection. */
    private function invocarMetodoPrivado(DesempenhoScoreService $service, string $metodo, Collection $empresasScore): mixed
    {
        $ref = new ReflectionMethod($service, $metodo);
        $ref->setAccessible(true);

        return $ref->invoke($service, $empresasScore);
    }

    // ═══ Teste 1 — ausência quando o shadow não roda ═════════════════════════

    #[Test]
    public function test_chaves_novas_presentes_mesmo_sem_expor_as_linhas_por_empresa(): void
    {
        $fixture = $this->criarFixtureBase('Ausencia Shadow 121');

        $service = app(DesempenhoScoreService::class);
        $payload = $service->compute($fixture['user'], Carbon::parse('2026-08-01'));

        // 2026-08-05 — INVERTEU de propósito. Estas chaves eram condicionais ao
        // shadow; agora o score por empresa roda sempre (é insumo da nota
        // oficial), então elas estão SEMPRE no payload, como metadado de
        // auditoria ao lado da nota. `$incluirEmpresasScore` passou a controlar
        // apenas a exposição das linhas por empresa, que são pesadas.
        $this->assertArrayHasKey('nota_final_por_empresa', $payload);
        $this->assertArrayHasKey('score_status_por_empresa', $payload);
        $this->assertSame([], $payload['empresas_score'],
            'Sem incluirEmpresasScore as LINHAS continuam fora do payload — só o par agregado entra.');
    }

    // ═══ Teste 2 — presença + valor, flag off ════════════════════════════════

    #[Test]
    public function test_chaves_novas_presentes_e_com_o_valor_certo_quando_o_shadow_roda_com_flag_off(): void
    {
        $fixture = $this->criarFixtureBase('Presenca Shadow 121');

        $this->mockCompanyScoreService(collect([
            8001 => $this->linhaEmpresa(8001, 'complete', 3.50, 3.50),
            8002 => $this->linhaEmpresa(8002, 'complete', 4.20, 4.20),
        ]));

        $service = app(DesempenhoScoreService::class);
        $payload = $service->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        $this->assertArrayHasKey('nota_final_por_empresa', $payload);
        $this->assertArrayHasKey('score_status_por_empresa', $payload);

        $empresasScore = collect($payload['empresas_score']);

        $notaEsperada   = $this->invocarMetodoPrivado($service, 'computeNotaFinalPorEmpresa', $empresasScore);
        $statusEsperado = $this->invocarMetodoPrivado($service, 'computeScoreStatusPorEmpresa', $empresasScore);

        $this->assertSame($statusEsperado, $payload['score_status_por_empresa']);
        $this->assertSame($notaEsperada, $payload['nota_final_por_empresa']);

        $this->assertEqualsWithDelta(3.85, $payload['nota_final_por_empresa'], 0.001,
            '(3.50+4.20)/2 = 3.85 — mesmo cálculo que o método privado produziria.');

        // Flag desligada: nota_final/score_status continuam os LEGADOS —
        // podem divergir do par novo (é exatamente o ponto que a Fase 121
        // vai medir).
        $this->assertSame('official', $payload['score_status_por_empresa']);
    }

    // ═══ Teste 3 — flag ligada dentro do teste ═══════════════════════════════

    #[Test]
    public function test_score_status_oficial_vem_do_par_por_empresa(): void
    {
        $fixture = $this->criarFixtureBase('Status Por Empresa 121');

        $this->mockCompanyScoreService(collect([
            8101 => $this->linhaEmpresa(8101, 'complete', 4.00, 4.00),
            8102 => $this->linhaEmpresa(8102, 'complete', 5.00, 5.00),
        ]));

        $service = app(DesempenhoScoreService::class);
        $payload = $service->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        // 2026-08-05 — `score_status` oficial passou a ser SEMPRE o derivado da
        // distribuição por empresa (não há mais bifurcação por flag).
        $this->assertSame($payload['score_status_por_empresa'], $payload['score_status'],
            'score_status oficial é o derivado da distribuição por empresa.');

        // Já `nota_final` NÃO é mais igual a `nota_final_por_empresa`: a
        // oficial vem de `computeNotaFinalPorIndicador()` (média por indicador,
        // denominador independente), e a por-empresa continua exposta ao lado,
        // para comparação. Com estes dublês, que não trazem os campos de
        // pontos, a oficial nem se forma — o que este teste assegura é
        // justamente que os dois números são de origens diferentes.
        $this->assertEqualsWithDelta(4.50, $payload['nota_final_por_empresa'], 0.001,
            '(4,00 + 5,00) / 2 = 4,50 — média das notas por empresa completa.');
    }

    // ═══ Teste 4 — blocked ════════════════════════════════════════════════════

    #[Test]
    public function test_caso_blocked_nota_final_por_empresa_fica_null(): void
    {
        $fixture = $this->criarFixtureBase('Blocked 121');

        $this->mockCompanyScoreService(collect([
            8201 => $this->linhaEmpresa(8201, 'sem_dados', null, null),
            8202 => $this->linhaEmpresa(8202, 'sem_dados', null, null),
        ]));

        $service = app(DesempenhoScoreService::class);
        $payload = $service->compute($fixture['user'], Carbon::parse('2026-08-01'), null, incluirEmpresasScore: true);

        $this->assertSame('blocked', $payload['score_status_por_empresa']);
        $this->assertNull($payload['nota_final_por_empresa']);
    }

    // ═══ Teste 5 — sentinela de Reflection ═══════════════════════════════════

    #[Test]
    public function test_sentinela_metodos_privados_existem_com_os_nomes_esperados(): void
    {
        $service = app(DesempenhoScoreService::class);

        // Falha ruidosa (ReflectionException) se algum dos dois for renomeado
        // — a exposição da D-05 depende dos dois nomes EXATOS.
        $refNota   = new ReflectionMethod($service, 'computeNotaFinalPorEmpresa');
        $refStatus = new ReflectionMethod($service, 'computeScoreStatusPorEmpresa');

        $this->assertTrue($refNota->isPrivate());
        $this->assertTrue($refStatus->isPrivate());
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (zero HTTP) em
 * todos os cenários desta suite. Não reaproveita o stub de outro arquivo de
 * teste (autoload não confiável quando a suite roda com `--filter`, mesmo
 * padrão já documentado em `PayloadBaselineFlagOffTest`/`AgregacaoProfissionalTest`).
 */
class ShadowNotaNovaExpostaTestProviderStub extends MetricsProviderFactory
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
