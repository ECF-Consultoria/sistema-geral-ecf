<?php

namespace Tests\Feature\Phase61;

use App\Models\AdmanMetric;
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
 * Phase 61 Plan 61-06 (Wave 3 — E2E) — Dashboard ML multi-fonte.
 *
 * Fecha o SC #1 do ROADMAP Phase 61 ("Dashboard ML unifica fontes num KPI")
 * cobrindo E2E os 3 casos canônicos do ADR DATA-04 (só-Adman / só-ML / ambos)
 * + o caso "none" + regressão da feature flag `UNIFIED_METRICS_ENABLED` OFF.
 *
 * Foco:
 *  - Flag OFF preserva payload legacy do Dashboard ML (`stats.source_counts`
 *    e `companies_performance.N.source` LITERALMENTE ausentes).
 *  - Flag ON expõe `stats.source_counts` agregado com contagens corretas
 *    para cada uma das 4 fontes formalizadas na ADR.
 *  - Empresas só-Adman recebem `source='adman'`; só-ML recebem `source='ml'`;
 *    ambos recebem `source='unified'`; sem integração recebem `source='none'`.
 *  - Empresas ML-only não geram exception/500 (memory
 *    `project_ml_only_companies_adman_endpoints`).
 *  - Empresa "none" é incluída em `stats.total_companies` (universo intocado).
 *
 * Rota exercitada: `mercadolivre.dashboard` → `DashboardController::index` →
 * `DashboardController::adminDashboard` (branching por role validado em
 * `DashboardController::index` linha 88).
 *
 * Universo de empresas do adminDashboard (linhas 242-250 do controller):
 *  - active=true
 *  - SEM mlbEmpresa (evita dupla contagem com /mlb/empresas)
 *  - COM contratosServico ativo em Servico com setor='performance'
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — vocabulário e casos
 * dos 4 valores de `source`.
 */
class DashboardMultiFonteE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Servico $servicoPerformance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        // Servico com setor=performance — pré-requisito de todas as empresas
        // que caem no universo do adminDashboard (linhas 245-249 do controller).
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

    /**
     * Cria 1 linha em `adman_metrics` no dia D-1 para exercitar o path
     * completo de agregação por empresa (revenue + ad_spend).
     */
    private function seedAdmanMetric(Company $company): void
    {
        AdmanMetric::create([
            'company_id'          => $company->id,
            'reference_date'      => now()->subDay()->toDateString(),
            'revenue'             => 1000.00,
            'ad_spend'            => 100.00,
            'sold_quantity'       => 10,
            'net_billing'         => 900.00,
            'sales_fee'           => 50.00,
            'taxes'               => 40.00,
            'contribution_margin' => 490.00,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Flag OFF: payload legacy preservado no Dashboard ML.
    //          Regressão bit-a-bit (`stats.source_counts` e `.source`
    //          LITERALMENTE ausentes — não null).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_dashboard_ml_nao_expoe_source_counts(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        // Fixture minima: 1 empresa Adman-only pra ter algo no dashboard.
        $this->criarEmpresa(['adman_account_id' => '11111']);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->missing('stats.source_counts')
                ->missing('companies_performance.0.source'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Flag ON + 4 fixtures (só-Adman / só-ML / ambos / none):
    //          `stats.source_counts` == {adman:1, ml:1, unified:1, none:1}.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_dashboard_ml_expoe_source_counts_agregado_4_casos(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04: os 4 casos formalizados em paralelo no mesmo request.
        $soAdman = $this->criarEmpresa(['adman_account_id' => '11111']);
        $soMl    = $this->criarEmpresa(['ml_store_id' => '22222'], withMlToken: true);
        $ambos   = $this->criarEmpresa(['adman_account_id' => '33333'], withMlToken: true);
        $none    = $this->criarEmpresa([]); // sem adman_account_id, sem mlToken

        $this->seedAdmanMetric($soAdman);
        $this->seedAdmanMetric($ambos);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('stats.source_counts', fn (AssertableInertia $sc) => $sc
                    ->where('adman', 1)
                    ->where('ml', 1)
                    ->where('unified', 1)
                    ->where('none', 1)
                )
                ->where('stats.total_companies', 4),
        );

        // Confirma que `source_counts` soma == `stats.total_companies`
        // (universo intocado — flag só adiciona metadados).
        $props  = $response->viewData('page')['props'];
        $counts = $props['stats']['source_counts'];
        $total  = $props['stats']['total_companies'];

        $this->assertSame(
            $total,
            $counts['adman'] + $counts['ml'] + $counts['unified'] + $counts['none'],
            'source_counts deve somar exatamente stats.total_companies',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Flag ON + só-Adman: `companies_performance[N].source === 'adman'`.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_so_adman_recebe_source_adman(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04 caso "so-adman": adman_account_id presente + sem
        // mlToken active. MetricsProviderFactory::caseFor() → 'so-adman' →
        // helper factoryToSource() mapeia para 'adman'.
        $soAdman = $this->criarEmpresa(['adman_account_id' => 'ABC123']);
        $this->seedAdmanMetric($soAdman);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->where('companies_performance.0.id', $soAdman->id)
                ->where('companies_performance.0.source', 'adman'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Flag ON + só-ML (sem AdmanMetric): render OK sem crash,
    //          `companies_performance[N].source === 'ml'`.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_so_ml_renderiza_sem_crash_com_source_ml(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04 caso "so-ml": mlToken active + adman_account_id NULL.
        // Memory `project_ml_only_companies_adman_endpoints` documenta que
        // essas empresas retornavam 422 em endpoints Adman MCP durante v11.0.
        // A nova camada Phase 61 usa apenas `caseFor()` (I/O-free) — nunca
        // atinge endpoint Adman com cust_id inválido.
        //
        // Ausencia intencional de AdmanMetric: prova que o SUM DB fallback
        // dos cards do dashboard tolera empresas sem registros históricos.
        $soMl = $this->criarEmpresa(['ml_store_id' => '999999'], withMlToken: true);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk(); // sem 422 / 500 / exception
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->where('companies_performance.0.id', $soMl->id)
                ->where('companies_performance.0.source', 'ml'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 5 — Flag ON + none: aparece com `source='none'` e é incluída
    //          em `stats.total_companies`.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_none_aparece_no_dashboard_com_source_none(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        // ADR DATA-04 caso "none": sem mlToken E sem adman_account_id.
        // O DTO sentinela permite ao consumidor distinguir "empresa sem
        // integração" de "erro na leitura" (este último emerge como
        // exception, não DTO). Aqui provamos que a empresa NÃO some do
        // universo — aparece com `source='none'`.
        $none = $this->criarEmpresa([]);

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard/Admin')
                ->has('companies_performance', 1)
                ->where('companies_performance.0.id', $none->id)
                ->where('companies_performance.0.source', 'none')
                ->where('stats.total_companies', 1)
                ->where('stats.source_counts.none', 1),
        );
    }
}
