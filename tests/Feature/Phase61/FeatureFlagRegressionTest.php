<?php

namespace Tests\Feature\Phase61;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase 61 Plan 61-06 (Wave 3 — E2E) — Regressão da feature flag OFF.
 *
 * Cobre o SC #4 do ROADMAP Phase 60 aplicado ao contexto Phase 61: com
 * `UNIFIED_METRICS_ENABLED=false`, as rotas afetadas pela Phase 61 preservam
 * status HTTP 200 e comportamento legacy. Complementa
 * `DashboardMultiFonteE2ETest` e `PortfolioMultiFonteE2ETest` (que provam
 * o shape do payload) com asserts focados no roteamento e no default da flag.
 *
 * Rotas afetadas cobertas:
 *  - `mercadolivre.dashboard` — DashboardController (61-01/61-05)
 *  - `portfolio.own` — PortfolioController (61-01/61-04)
 *  - `portfolio.show` — PortfolioController (61-01/61-04)
 *  - `companies.show` — CompanyController (61-03 — enriquecimento UNCONDITIONAL,
 *    regressão aqui é apenas de status HTTP; o shape carrega `company.source`
 *    mesmo com a flag OFF pois é badge estético obrigatório do ROADMAP).
 *
 * Baseline Phase 60 (46/46) é verificado fora deste arquivo pelo runner
 * combinado do plan verify (`php artisan test tests/Feature/Phase60`) —
 * documentado no SUMMARY do plan 61-06. Rodar suite via `artisan test`
 * dentro de um teste seria mais frágil (recursivo + inflaria duração).
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — seção "Rollout e
 * feature flag" (linhas 298-303).
 */
class FeatureFlagRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria uma empresa Adman-only elegível pro adminDashboard (com contrato
     * ativo em servico setor=performance). Usado nos smoke tests abaixo pra
     * garantir universo não-vazio (path completo exercitado).
     */
    private function criarEmpresaPerformance(): Company
    {
        $servico = Servico::create([
            'nome'          => 'Gestão Performance',
            'valor_padrao'  => 1000.00,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $company = Company::factory()->create([
            'adman_account_id' => 'FLAG-OFF-REGR-01',
            'name'             => 'Empresa Regressao Flag OFF',
        ]);

        ContratoServico::create([
            'company_id'      => $company->id,
            'servico_id'      => $servico->id,
            'valor_contratado' => 1000.00,
            'data_contratacao' => now()->subMonth()->toDateString(),
            'ativo'           => true,
        ]);

        // 1 row em `adman_metrics` no dia D-1 pra alimentar a query de
        // agregação do adminDashboard (mesma estratégia do
        // `Phase60\BaselineRegressionTest::setUp`).
        AdmanMetric::create([
            'company_id'          => $company->id,
            'reference_date'      => now()->subDay()->toDateString(),
            'revenue'             => 100.00,
            'ad_spend'            => 10.00,
            'sold_quantity'       => 5,
            'net_billing'         => 90.00,
            'sales_fee'           => 5.00,
            'taxes'               => 4.00,
            'shipping_cost'       => 2.00,
            'product_cost'        => 30.00,
            'contribution_margin' => 49.00,
        ]);

        return $company->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Default: `metrics.unified_metrics_enabled` == false
    //          quando nenhuma env é setada (comportamento seguro).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_default_false_em_config(): void
    {
        // ATENÇÃO: NÃO chamar Config::set aqui. O objetivo é validar o
        // DEFAULT declarado em `config/metrics.php` (Task 1 do plan 61-01):
        // `filter_var(env('UNIFIED_METRICS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`.
        //
        // Este teste protege contra regressão silenciosa do default —
        // se alguém trocar `env(..., false)` por `env(..., true)` ou
        // remover o filter_var, este teste quebra imediatamente.
        $this->assertFalse(
            (bool) config('metrics.unified_metrics_enabled'),
            'Default de metrics.unified_metrics_enabled DEVE ser false — proteção contra rollout acidental (ADR DATA-04 seção Rollout).',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Flag OFF: `mercadolivre.dashboard` permanece 200.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_route_dashboard_ml_permanece_200(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        if (! Route::has('mercadolivre.dashboard')) {
            $this->fail(
                'Rota `mercadolivre.dashboard` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara regressão da flag OFF.',
            );
        }

        $this->criarEmpresaPerformance();

        $response = $this->actingAs($this->admin)
            ->get(route('mercadolivre.dashboard'));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Flag OFF: `portfolio.own` permanece 200 (admin cai em
    //          renderCarteirasConsolidadas).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_route_portfolio_own_permanece_200(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        if (! Route::has('portfolio.own')) {
            $this->fail(
                'Rota `portfolio.own` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara regressão da flag OFF.',
            );
        }

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.own'));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Flag OFF: `companies.show` permanece 200. Nota: o
    //          enriquecimento em 61-03 é UNCONDITIONAL — `company.source`
    //          é sempre presente. A regressão aqui é APENAS de status
    //          HTTP (o shape do payload muda em 61-03 independente da
    //          flag por decisão do plan 61-03).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_route_companies_show_permanece_200(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        if (! Route::has('companies.show')) {
            $this->fail(
                'Rota `companies.show` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara regressão da flag OFF.',
            );
        }

        // Empresa simples sem contrato Performance — companies.show não
        // exige contrato ativo (diferente de adminDashboard).
        $company = Company::factory()->create([
            'adman_account_id' => 'FLAG-OFF-COMP',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('companies.show', $company));

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 5 — Flag OFF: `portfolio.show` permanece 200 (analista com
    //          carteira vazia — smoke apenas de status HTTP).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_route_portfolio_show_permanece_200(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        if (! Route::has('portfolio.show')) {
            $this->fail(
                'Rota `portfolio.show` esperada mas ausente — atualizar teste com nome real via '
                . '`php artisan route:list`. markTestSkipped mascara regressão da flag OFF.',
            );
        }

        // Analista sem carteira — admin ainda pode abrir o show (bypass
        // do guard `userCanViewCompany` via isAdmin()).
        $analista = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
    }
}
