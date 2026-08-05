<?php

namespace Tests\Feature\Phase120;

use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\ShopeeMetric;
use App\Models\Servico;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Gate nº 2 do VALIDATION (120-VALIDATION.md) — o shadow (`$incluirEmpresasScore`)
 * não pode vazar para a leitura interativa (D-04) e não pode ser silenciosamente
 * pulado pelo `Cache::remember` do warm (C-02).
 *
 * Testes 1-3 (Task 2): o `consolidar-mes` dispara o shadow com garantia
 * (chama `compute()` direto, sem cache); o `warm-cache` recomputa quando o
 * payload cacheado ainda não tem `empresas_score` (payload pré-Fase-120 ou
 * populado por leitura interativa); e NÃO recomputa quando o payload já tem a
 * chave — é o guard da C-02 provado nos dois sentidos.
 *
 * Testes 4-6 (Task 3): nenhum controller de leitura interativa dispara
 * `CompanyScoreService` com a flag desligada (contagem zero, D-04 em
 * runtime); nenhum dos três arquivos de controller referencia
 * `incluirEmpresasScore` (gate estático, protege contra ativação futura sem
 * perceber); e o shadow ligado produz EXATAMENTE os mesmos números legados
 * que o shadow desligado (prova de isolamento do Pitfall 2 do RESEARCH —
 * `computeEmpresasScore()` chama os mesmos vizinhos dos métodos legados,
 * `MetricDiffDispatcher`/`NpsPorEmpresaService`, e não pode contaminá-los).
 */
class ShadowRoteamentoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Dublê contador — precisa ser ligado ANTES de qualquer resolução de
        // DesempenhoScoreService, já que a injeção é por construtor (8º
        // parâmetro promovido). Reflection localiza o construtor herdado do
        // CompanyScoreService normalmente (o dublê não declara o seu).
        ContadorCompanyScoreService::$chamadas = 0;
        $this->app->bind(CompanyScoreService::class, ContadorCompanyScoreService::class);

        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 "hoje" — junho/2026 fica bem fechado (2 meses atrás).
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        // Stub defensivo — mesmo padrão de PayloadBaselineFlagOffTest. A
        // fixture desta suíte é só-Shopee (ShopeeMetricDiffService lê direto
        // da tabela, sem HTTP), mas o stub evita qualquer chamada real caso
        // algum vizinho resolva a factory por outro caminho.
        $this->app->instance(MetricsProviderFactory::class, new ShadowRoteamentoTestProviderStub());

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-120-02',
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

    private function criarEmpresa(string $createdAt = '-6 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        return $company->fresh();
    }

    /** 1 row de ShopeeMetric por dia em [$inicio,$fim] — janela densa (dispatcher exige dias comuns). */
    private function semearShopeeDiario(Company $c, string $inicio, string $fim, float $revenue): void
    {
        $cursor  = Carbon::parse($inicio);
        $fimData = Carbon::parse($fim);
        while ($cursor->lte($fimData)) {
            ShopeeMetric::create([
                'company_id'     => $c->id,
                'reference_date' => $cursor->toDateString(),
                'revenue'        => $revenue,
            ]);
            $cursor->addDay();
        }
    }

    /**
     * Carteira mínima e determinística: 1 profissional, 1 empresa SÓ-SHOPEE.
     * Fonte só-Shopee garante que o gate FIXMARG-03 do `consolidar-mes` NUNCA
     * bloqueia a persistência — `margem_amostra.n_elegivel=0` (Shopee é
     * filtrada do denominador de margem) força `cobertura=1.0` por definição
     * (`DesempenhoScoreService::compute()`, cálculo de `margem_amostra`). O
     * foco desta suíte é o ROTEAMENTO do shadow, não a régua de margem.
     */
    private function montarFixture(string $mesYm): User
    {
        $user          = $this->criarUserComCargo("Shadow {$mesYm}");
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);

        $company = $this->criarEmpresa();
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoShopee);

        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesYm]);
        $this->semearShopeeDiario($company, $periodo['current_start'], $periodo['current_end'], 11000);
        $this->semearShopeeDiario($company, $periodo['baseline_start'], $periodo['baseline_end'], 10000);

        return $user;
    }

    // ═══ Testes 1-3 (Task 2) — roteamento nos comandos + guard C-02 ═══════════

    #[Test]
    public function test_consolidar_mes_dispara_o_shadow_com_garantia(): void
    {
        $user = $this->montarFixture('2026-06');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertSuccessful();

        $this->assertGreaterThan(0, ContadorCompanyScoreService::$chamadas,
            'consolidar-mes chama compute() direto (sem Cache::remember) — o shadow tem que rodar sempre.');

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->first();

        $this->assertNotNull($snap, 'Fonte só-Shopee nunca aciona o gate FIXMARG-03 — o snapshot tem que persistir.');
        $this->assertArrayHasKey('empresas_score', $snap->breakdown_json,
            'O shadow persiste empresas_score no breakdown_json de graça (a Fase 122 estrutura por cima).');
    }

    #[Test]
    public function test_warm_cache_recomputa_quando_o_payload_cacheado_nao_tem_empresas_score(): void
    {
        $user = $this->montarFixture('2026-06');
        $mes  = Carbon::parse('2026-06-01');

        $service = app(DesempenhoScoreService::class);
        $chave   = $service->cacheKey($user->id, $mes);

        // Payload deliberadamente PRÉ-Fase-120 — sem a chave empresas_score.
        // Simula tanto uma chave nunca recomputada desde antes desta fase
        // quanto uma populada por leitura interativa com o shadow desligado.
        Cache::put($chave, ['sem_carteira' => false, 'nota_final' => 3.0], now()->addMinutes(10));

        $this->artisan('desempenho:warm-cache', ['--user' => [$user->id], '--mes' => '2026-06'])->assertSuccessful();

        $this->assertGreaterThan(0, ContadorCompanyScoreService::$chamadas,
            'C-02: payload cacheado sem empresas_score tem que forçar o recompute com o shadow ligado.');

        $recomputado = Cache::get($chave);
        $this->assertIsArray($recomputado);
        $this->assertArrayHasKey('empresas_score', $recomputado,
            'Sem este guard o shadow seria silenciosamente pulado neste ciclo (armadilha do Cache::remember).');
    }

    #[Test]
    public function test_warm_cache_nao_recomputa_quando_o_payload_cacheado_ja_tem_empresas_score(): void
    {
        $user = $this->montarFixture('2026-06');
        $mes  = Carbon::parse('2026-06-01');

        $service = app(DesempenhoScoreService::class);
        $chave   = $service->cacheKey($user->id, $mes);

        // Payload já Fase-120 — empresas_score presente (o conteúdo em si não
        // importa pro guard, só a existência da chave).
        Cache::put($chave, ['sem_carteira' => false, 'nota_final' => 3.0, 'empresas_score' => []], now()->addMinutes(10));

        $this->artisan('desempenho:warm-cache', ['--user' => [$user->id], '--mes' => '2026-06'])->assertSuccessful();

        $this->assertSame(0, ContadorCompanyScoreService::$chamadas,
            'Cache::forget() incondicional foi rejeitado — recomputaria ~70s/user a cada ciclo de 8min, '
            . 'exatamente o custo que o warm existe para evitar.');
    }

    // ═══ Testes 4-6 (Task 3) — o shadow não vaza para a tela + isolamento ═════

    #[Test]
    public function test_leitura_interativa_dispara_company_score_service_exatamente_uma_vez(): void
    {
        $user = $this->montarFixture('2026-06');
        $mes  = Carbon::parse('2026-06-01');

        $service = app(DesempenhoScoreService::class);

        // Forma EXATA como PerformanceController (linhas 194, 200, 422, 1255),
        // PortfolioController (2106, 2132) e DashboardController (1103) chamam
        // hoje — exatamente 2 argumentos, sem o parâmetro de shadow.
        $service->computeCached($user, $mes);

        // 2026-08-05 — INVERTEU de propósito. Este teste garantia que a leitura
        // interativa NUNCA pagasse o custo do score por empresa (D-04, quando
        // ele era um shadow opcional). Agora esse cálculo É a nota oficial:
        // pular seria não ter nota.
        //
        // O que continua sendo protegido é o que de fato importa para o tempo
        // de tela: UMA única chamada por `compute()`, nunca uma por
        // consumidor. O custo incremental é menor do que parece — o
        // `AdmanMetricDiffService` já é acionado por empresa pelos componentes
        // agregados e serve as chamadas seguintes de cache/memo; o que entra
        // de novo é sobretudo query local de NPS por empresa.
        //
        // A defesa real do tempo de tela continua sendo `computeCached()` —
        // ver `test_warm_cache_nao_forca_recomputo_quando_ja_ha_payload_fase_120`
        // acima, que impede o ciclo de warm de recomputar sem necessidade.
        $this->assertSame(1, ContadorCompanyScoreService::$chamadas,
            'O score por empresa alimenta a nota oficial — roda 1× por compute(), nunca 1× por consumidor.');
    }

    #[Test]
    public function test_controllers_de_leitura_interativa_nao_passam_o_parametro_de_shadow(): void
    {
        $arquivos = [
            app_path('Http/Controllers/PerformanceController.php'),
            app_path('Http/Controllers/PortfolioController.php'),
            app_path('Http/Controllers/DashboardController.php'),
        ];

        foreach ($arquivos as $arquivo) {
            $this->assertFileExists($arquivo);

            $conteudo = file_get_contents($arquivo);

            $this->assertStringNotContainsString(
                'incluirEmpresasScore',
                $conteudo,
                basename($arquivo) . ' não pode ativar o shadow — D-04: o caminho novo custa HTTP síncrono à '
                . 'Adman e dobraria o tempo de tela.'
            );
        }
    }

    #[Test]
    public function test_shadow_ligado_nao_muda_nenhum_valor_das_chaves_legadas(): void
    {
        $user = $this->montarFixture('2026-06');
        $mes  = Carbon::parse('2026-06-01');

        $service = app(DesempenhoScoreService::class);

        // compute() direto nas duas chamadas — nunca computeCached(), pra não
        // haver interferência de cache entre elas (a mesma chave serviria a
        // segunda chamada do cache da primeira).
        $semShadow = $service->compute($user, $mes, null, incluirEmpresasScore: false);
        $comShadow = $service->compute($user, $mes, null, incluirEmpresasScore: true);

        $camposIdenticos = [
            ['componentes', 'nps_medio'],
            ['componentes', 'var_faturamento_pct'],
            ['componentes', 'var_margem_pct'],
            ['componentes', 'absenteismo_pct'],
            ['pontos_componentes', 'nps'],
            ['pontos_componentes', 'faturamento'],
            ['pontos_componentes', 'margem'],
        ];

        foreach ($camposIdenticos as [$grupo, $campo]) {
            $this->assertSame(
                $semShadow[$grupo][$campo],
                $comShadow[$grupo][$campo],
                "componentes.{$grupo}.{$campo} não pode divergir entre shadow ligado e desligado."
            );
        }

        $this->assertSame($semShadow['nota_final'], $comShadow['nota_final']);
        $this->assertSame($semShadow['score_status'], $comShadow['score_status']);
        $this->assertSame($semShadow['faixa_bonus'], $comShadow['faixa_bonus']);

        // 2026-08-05 — `margem_amostra` tem o MESMO shape nas duas chamadas
        // (cobertura sobre `margem_var_pp`, mais `base` e `legado`), porque o
        // score por empresa deixou de ser condicional e roda sempre.
        // `$incluirEmpresasScore` hoje decide APENAS se as linhas por empresa
        // vão expostas no payload — nunca se o cálculo acontece.
        $this->assertSame($semShadow['margem_amostra'], $comShadow['margem_amostra'],
            'margem_amostra não pode depender de o payload expor ou não as linhas por empresa.');
        $this->assertSame('margem_var_pp', $comShadow['margem_amostra']['base']);
        $this->assertArrayHasKey('legado', $comShadow['margem_amostra']);
        $this->assertArrayHasKey('legado', $semShadow['margem_amostra']);

        // Única divergência permitida: `empresas_score` (vazio × populado) —
        // é literalmente o que o parâmetro controla.
        $this->assertSame([], $semShadow['empresas_score'], 'Sem incluirEmpresasScore: linhas não vão no payload.');
        $this->assertNotSame([], $comShadow['empresas_score'], 'Com incluirEmpresasScore: linhas vão no payload.');
    }
}

/**
 * Dublê contador de `CompanyScoreService` — Fase 120 (gate nº 2 do
 * VALIDATION). Sobrescreve `computeEmpresasScore()` só para contar quantas
 * vezes o shadow efetivamente roda, delegando ao comportamento real
 * (`parent::`) para manter o payload realista e não mascarar nenhuma
 * divergência que o teste de isolamento (teste 6) precisa detectar.
 */
class ContadorCompanyScoreService extends CompanyScoreService
{
    public static int $chamadas = 0;

    public function computeEmpresasScore(User $user, Carbon $mes, array $periodo, ?Collection $invalidadas = null): Collection
    {
        self::$chamadas++;

        return parent::computeEmpresasScore($user, $mes, $periodo, $invalidadas);
    }
}

/**
 * Stub local do `MetricsProviderFactory` — mesmo padrão defensivo de
 * `PayloadBaselineFlagOffTest`, zero HTTP real independente do caminho.
 */
class ShadowRoteamentoTestProviderStub extends MetricsProviderFactory
{
    public function __construct()
    {
        // Intencionalmente não chama parent::__construct().
    }

    public function caseFor(Company $company): string
    {
        return 'so-shopee';
    }

    public function forCompany(Company $company): array
    {
        return [];
    }
}
