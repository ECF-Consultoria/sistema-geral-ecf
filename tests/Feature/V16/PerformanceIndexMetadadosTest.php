<?php

namespace Tests\Feature\V16;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 92 Plan 01 — passthrough dos 6 metadados de elegibilidade (Fase 91)
 * no payload de `/performance` + filtro de auditoria `?contexto=` view-only.
 *
 * Cobre DESEMP-08:
 *  - SC2 — cada item do ranking (prop Inertia `ranking`) carrega os 6
 *    metadados (`empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`,
 *    `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis`)
 *    lidos direto do `$resultado` já calculado — sem recomputar.
 *  - SC2 (blocked) — um profissional só-Shopee (`score_status='blocked'`,
 *    `nota_final=null`) permanece no ranking (`sem_carteira=false`, DESEMP-07)
 *    com `vinculos_sem_fonte_financeira > 0`.
 *  - SC3 — `?contexto=todos|performance|shopee` é estritamente de exibição:
 *    `nota_final`/`score_status` são IDÊNTICOS entre os 3 valores; valor
 *    inválido cai em 'todos'.
 *  - SC1 (gate de ausência) — nenhum "segundo score" por marketplace
 *    (`score_shopee`/`score_ml`/`ranking_shopee`/`ranking_ml`) existe no código.
 *
 * @see .planning/phases/92-.../92-01-PLAN.md
 * @see .planning/phases/91-.../91-02-SUMMARY.md
 */
class PerformanceIndexMetadadosTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // Agosto/2026 mês em curso — mesma janela usada por DesempenhoElegibilidadeTest.
        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        // Stub local do MetricsProviderFactory — força o path Adman (zero HTTP).
        // Não reaproveita o stub de outro arquivo de teste: classe secundária
        // de outro arquivo não é autoload-confiável quando a suite roda com
        // --filter (padrão já documentado em DesempenhoElegibilidadeTest).
        $this->app->instance(MetricsProviderFactory::class, new PerformanceIndexMetadadosTestProviderStub());

        // Fase 102 (deviation Rule 1 — regressão real causada por 102-01 fora
        // do edit-set declarado): compute() passou a delegar var_margem_pct a
        // AdmanMetricDiffService, que retorna emptyMetrics() sem HTTP quando a
        // empresa não tem adman_account_id. Isola qualquer request real.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-92',
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

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin 92-01 ' . uniqid(),
            'email'    => 'admin.9201.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

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

    private function criarEmpresa(string $createdAt = '-3 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        return $company->fresh();
    }

    private function mockAdman(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-10')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    private function itemDoRanking($response, int $userId): ?array
    {
        $ranking = $response->viewData('page')['props']['ranking'] ?? [];
        foreach ($ranking as $item) {
            if (($item['id'] ?? null) === $userId) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Pré-aquece o cache do score de todos analista/estrategista para o mês em
     * curso — em prod o `desempenho:warm-cache` mantém isso quente. Sem o warm,
     * o gate (estendido ao Em curso em 2026-07-22) devolveria `calculando` no
     * lugar dos dados, e os testes de payload não teriam o que assertar.
     */
    private function aquecerRankingEmCurso(): void
    {
        $svc = app(\App\Services\DesempenhoScoreService::class);
        $mes = Carbon::now()->startOfMonth();
        \App\Models\User::where('active', true)
            ->whereExists(fn ($q) => $q->from('user_setores as us')
                ->join('cargos as c', 'c.id', '=', 'us.cargo_id')
                ->whereColumn('us.user_id', 'users.id')
                ->whereIn('c.slug', ['analista', 'estrategista']))
            ->get()
            ->each(fn ($u) => $svc->computeCached($u, $mes));
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    #[Test]
    public function test_ranking_inclui_os_6_metadados_de_elegibilidade_por_linha(): void
    {
        $this->actingAsAdmin();

        $user    = $this->criarUserComCargo('Analista Official 92', $this->cargoAnalistaId);
        $empresa = $this->criarEmpresa();
        // Fase 102 — adman_account_id necessário pro AdmanMetricDiffService
        // conseguir calcular var_margem_pct (custId vazio = emptyMetrics(),
        // sem HTTP, var_margem_pct sempre null → score_status vira 'partial').
        // Este teste só precisa de um valor NÃO-NULO (não asserta o número
        // exato) — mantém revenue/margem originais.
        $empresa->forceFill(['adman_account_id' => 'CUST-9201-OFFICIAL', 'marketplace' => 'meli'])->save();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresa->id, $servicoPerf, true);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresa, '2026-08', revenue: 10300, margem: 10280);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 10000);
        // Histórico pré-baseline (item 1 · trava de cobertura): junho prova que
        // a empresa opera desde antes do baseline → qualifica no faturamento e
        // score_status fica 'official'. Sem isso a trava a deixaria 'partial'.
        $this->mockAdman($empresa, '2026-06', revenue: 9500, margem: 9500);

        $this->aquecerRankingEmCurso();
        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $user->id);
        $this->assertNotNull($item, 'User deve aparecer no ranking.');

        foreach (['empresas_unicas', 'vinculos_servico', 'vinculos_financeiros', 'vinculos_sem_fonte_financeira', 'score_status', 'componentes_disponiveis'] as $chave) {
            $this->assertArrayHasKey($chave, $item, "Item do ranking deve conter a chave '{$chave}'.");
        }

        $this->assertSame('official', $item['score_status']);
        $this->assertSame(1, $item['empresas_unicas']);
        $this->assertSame(1, $item['vinculos_servico']);
        $this->assertSame(1, $item['vinculos_financeiros']);
        $this->assertSame(0, $item['vinculos_sem_fonte_financeira']);
    }

    #[Test]
    public function test_profissional_blocked_permanece_no_ranking_com_score_status_e_vinculos_sem_fonte(): void
    {
        $this->actingAsAdmin();

        // Cenário Matheus (91-01): 1 empresa, só vínculo Shopee → blocked.
        $user    = $this->criarUserComCargo('Matheus Só-Shopee 92', $this->cargoAnalistaId);
        $empresa = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresa->id, $user->id, 'consultor', $servicoShopee);
        $this->mockAdman($empresa, '2026-08', revenue: 11000, margem: 10500);
        $this->mockAdman($empresa, '2026-07', revenue: 10000, margem: 10000);

        $this->aquecerRankingEmCurso();
        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $user->id);
        $this->assertNotNull($item, 'blocked TEM vínculo ativo — DESEMP-07 não remove do ranking (diferente de sem_carteira).');
        $this->assertFalse($item['sem_carteira']);
        $this->assertSame('blocked', $item['score_status']);
        $this->assertNull($item['nota_final']);
        $this->assertGreaterThan(0, $item['vinculos_sem_fonte_financeira']);
    }

    #[Test]
    public function test_contexto_e_view_only_nota_final_e_score_status_identicos_entre_valores(): void
    {
        $this->actingAsAdmin();

        // Um official (financeiro elegível) e um blocked (só-Shopee) — a
        // garantia precisa valer para os dois, não só pro caminho feliz.
        $official    = $this->criarUserComCargo('Analista Contexto Official', $this->cargoAnalistaId);
        $empresaOff  = $this->criarEmpresa();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $this->criarContrato($empresaOff->id, $servicoPerf, true);
        $this->inserirPivot($empresaOff->id, $official->id, 'consultor', $servicoPerf);
        $this->mockAdman($empresaOff, '2026-08', revenue: 10300, margem: 10280);
        $this->mockAdman($empresaOff, '2026-07', revenue: 10000, margem: 10000);

        $blocked    = $this->criarUserComCargo('Analista Contexto Blocked', $this->cargoAnalistaId);
        $empresaBlk = $this->criarEmpresa();
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);
        $this->inserirPivot($empresaBlk->id, $blocked->id, 'consultor', $servicoShopee);

        $this->aquecerRankingEmCurso();
        $respostas = [];
        foreach (['todos', 'performance', 'shopee', 'xpto-invalido'] as $valor) {
            $resp = $this->get('/performance?contexto=' . $valor);
            $resp->assertStatus(200);
            $respostas[$valor] = $resp;
        }

        // Valor inválido resolve para 'todos' na prop de exibição.
        $this->assertSame('todos', $respostas['xpto-invalido']->viewData('page')['props']['contexto']);
        $this->assertSame('performance', $respostas['performance']->viewData('page')['props']['contexto']);
        $this->assertSame('shopee', $respostas['shopee']->viewData('page')['props']['contexto']);
        $this->assertSame('todos', $respostas['todos']->viewData('page')['props']['contexto']);

        foreach ([$official, $blocked] as $user) {
            $itemTodos = $this->itemDoRanking($respostas['todos'], $user->id);
            $this->assertNotNull($itemTodos);

            foreach (['performance', 'shopee', 'xpto-invalido'] as $valor) {
                $item = $this->itemDoRanking($respostas[$valor], $user->id);
                $this->assertNotNull($item, "User {$user->id} deve continuar no ranking com contexto={$valor}.");
                $this->assertSame(
                    $itemTodos['nota_final'],
                    $item['nota_final'],
                    "nota_final NÃO pode mudar com ?contexto={$valor} (view-only, DESEMP-02)."
                );
                $this->assertSame(
                    $itemTodos['score_status'],
                    $item['score_status'],
                    "score_status NÃO pode mudar com ?contexto={$valor} (view-only, DESEMP-02)."
                );
            }
        }
    }

    #[Test]
    public function test_gate_ausencia_de_segundo_score_por_marketplace(): void
    {
        // SC1 — mesmo padrão de gate usado na Fase 91: nenhum arquivo do
        // controller pode introduzir um "segundo score" separado por setor.
        $conteudoController = file_get_contents(app_path('Http/Controllers/PerformanceController.php'));

        foreach (['score_shopee', 'score_ml', 'ranking_shopee', 'ranking_ml'] as $termoProibido) {
            $this->assertStringNotContainsStringIgnoringCase(
                $termoProibido,
                $conteudoController,
                "PerformanceController não pode conter '{$termoProibido}' — proibido por DESEMP-02/SC1."
            );
        }
    }
}

/**
 * Stub local do `MetricsProviderFactory` — força o path Adman (caseFor
 * sempre 'so-adman', forCompany sempre vazio) em todos os cenários desta
 * suite. Zero HTTP ao ML/Adman real. Não reaproveita stub de outro arquivo
 * (autoload não confiável com --filter — ver DesempenhoElegibilidadeTest).
 */
class PerformanceIndexMetadadosTestProviderStub extends MetricsProviderFactory
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
