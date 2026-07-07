<?php

namespace Tests\Feature\Phase61;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\MlToken;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 61 Plan 61-01 (Wave 1) — Enriquecimento condicional do
 * DashboardController::adminDashboard com `source` (por empresa) e
 * `source_counts` (agregado em `stats`).
 *
 * Foco:
 *  - Flag `metrics.unified_metrics_enabled` OFF preserva payload legacy:
 *    `stats` sem `source_counts` e `companies_performance[N]` sem `source`.
 *  - Flag ON adiciona os metadados sem alterar `stats.total_companies` e
 *    demais campos (delta zero comportamental).
 *  - Empresas ML-only + sem-integração renderizam sem exception (ADR
 *    DATA-04 casos "so-ml" e "none").
 *
 * Rota exercitada: `mercadolivre.dashboard` — delega pra `index()` que
 * chama `adminDashboard()` quando o usuário é admin (roteamento validado
 * em `routes/web.php` linha 143 + DashboardController::index linha 49).
 *
 * O universo de empresas do adminDashboard exige (linhas 198-206 do
 * controller):
 *  - active=true
 *  - SEM mlbEmpresa
 *  - COM contratosServico ativo em Servico com setor='performance'
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — vocabulário dos 4
 * valores válidos de `source`.
 */
class DashboardSourceEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Servico $servicoPerformance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        // Servico com setor=performance — pré-requisito de todas as empresas
        // que caem no universo do adminDashboard (linhas 201-205 do controller).
        $this->servicoPerformance = Servico::create([
            'nome'          => 'Gestão Performance',
            'valor_padrao'  => 1000.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
    }

    // ─────────────────────────── Helpers ───────────────────────────

    /**
     * Cria empresa elegível pro adminDashboard (contrato Performance ativo,
     * sem mlbEmpresa) com integração configurável por caso ADR DATA-04.
     */
    private function criarEmpresa(array $overrides = [], bool $withMlToken = false): Company
    {
        $c = Company::factory()->create(array_merge([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ], $overrides));

        ContratoServico::create([
            'company_id'      => $c->id,
            'servico_id'      => $this->servicoPerformance->id,
            'valor_contratado' => 1000.00,
            'data_contratacao' => now()->subMonth()->toDateString(),
            'ativo'           => true,
        ]);

        if ($withMlToken) {
            MlToken::create([
                'company_id'        => $c->id,
                'ml_user_id'        => '465723451',
                'access_token'      => 'fake-token',
                'refresh_token'     => 'fake-refresh',
                'token_type'        => 'bearer',
                'scope'             => 'read offline_access',
                'expires_at'        => now()->addHour(),
                'last_refreshed_at' => now(),
                'status'            => 'active',
                'connected_at'      => now(),
            ]);
        }

        return $c->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Flag OFF: `stats` sem `source_counts`, sem `source` em
    //          `companies_performance[N]` (payload legacy preservado)
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_admin_dashboard_nao_contem_source_metadata(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        $this->criarEmpresa(['adman_account_id' => '11111']);
        $this->criarEmpresa(['adman_account_id' => '22222'], withMlToken: true);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->missing('stats.source_counts')
                ->has('companies_performance', 2)
                ->missing('companies_performance.0.source')
                ->missing('companies_performance.1.source'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Flag ON: `companies_performance[N].source` com enum válido
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_companies_performance_enriquecido_com_source(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $soAdman = $this->criarEmpresa(['adman_account_id' => '11111']);
        $ambos   = $this->criarEmpresa(['adman_account_id' => '22222'], withMlToken: true);
        $soMl    = $this->criarEmpresa(['ml_store_id' => '33333'], withMlToken: true);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 3, fn (AssertableInertia $c) => $c
                    ->has('source')
                    ->where('source', fn (string $v) => in_array($v, ['adman', 'ml', 'unified', 'none'], true))
                    ->etc()
                ),
        );

        $props    = $response->viewData('page')['props'];
        $bySource = collect($props['companies_performance'])->pluck('source', 'id')->all();

        $this->assertSame('adman',   $bySource[$soAdman->id]);
        $this->assertSame('unified', $bySource[$ambos->id]);
        $this->assertSame('ml',      $bySource[$soMl->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Flag ON: `stats.source_counts` com soma == total_companies
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_stats_source_counts_soma_igual_ao_total_companies(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $this->criarEmpresa(['adman_account_id' => '11111']);
        $this->criarEmpresa(['adman_account_id' => '22222'], withMlToken: true);
        $this->criarEmpresa(['ml_store_id' => '33333'], withMlToken: true);
        $this->criarEmpresa([]); // none

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('stats.source_counts', fn (AssertableInertia $sc) => $sc
                    ->has('adman')
                    ->has('ml')
                    ->has('unified')
                    ->has('none')
                )
                ->where('stats.total_companies', 4),
        );

        $props  = $response->viewData('page')['props'];
        $counts = $props['stats']['source_counts'];
        $total  = $props['stats']['total_companies'];

        $this->assertSame(1, $counts['adman']);
        $this->assertSame(1, $counts['ml']);
        $this->assertSame(1, $counts['unified']);
        $this->assertSame(1, $counts['none']);
        $this->assertSame($total, $counts['adman'] + $counts['ml'] + $counts['unified'] + $counts['none']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Flag ON: empresa ML-only renderiza sem erro Adman
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_ml_only_renderiza_sem_erro_com_source_ml(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04 caso "so-ml": mlToken active + adman_account_id NULL.
        // Memória `project_ml_only_companies_adman_endpoints` documenta que
        // essas empresas retornavam 422 em endpoints Adman MCP; a nova
        // camada nunca deve provocar isso quando só faz `caseFor()`.
        $ml = $this->criarEmpresa(['ml_store_id' => '999999'], withMlToken: true);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->where('companies_performance.0.id', $ml->id)
                ->where('companies_performance.0.source', 'ml'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 5 — Flag ON: empresa sem integração aparece com source='none'
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_sem_integracao_aparece_com_source_none(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04 caso "none": sem mlToken E sem adman_account_id.
        $none = $this->criarEmpresa([]);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->where('companies_performance.0.id', $none->id)
                ->where('companies_performance.0.source', 'none'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 6 — `stats.total_companies` idêntico entre flag OFF e ON
    //          (metadados adicionados; universo intocado — delta zero)
    // ═══════════════════════════════════════════════════════════════

    public function test_stats_total_companies_identico_flag_off_vs_on(): void
    {
        // Fixture comum aos dois requests.
        $this->criarEmpresa(['adman_account_id' => '11111']);
        $this->criarEmpresa(['adman_account_id' => '22222'], withMlToken: true);
        $this->criarEmpresa(['ml_store_id' => '33333'], withMlToken: true);

        Config::set('metrics.unified_metrics_enabled', false);
        $respOff = $this->actingAs($this->admin)->get(route('mercadolivre.dashboard'));
        $respOff->assertOk();
        $totalOff = $respOff->viewData('page')['props']['stats']['total_companies'];

        Config::set('metrics.unified_metrics_enabled', true);
        $respOn = $this->actingAs($this->admin)->get(route('mercadolivre.dashboard'));
        $respOn->assertOk();
        $totalOn = $respOn->viewData('page')['props']['stats']['total_companies'];

        $this->assertSame(3, $totalOff, 'Flag OFF deve contar as 3 empresas do fixture');
        $this->assertSame($totalOff, $totalOn, 'Flag ON não altera o universo — só adiciona metadados');
    }
}
