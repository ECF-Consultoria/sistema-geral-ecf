<?php

namespace Tests\Feature\Phase61;

use App\Models\Company;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 61 Plan 61-01 (Wave 1) — Enriquecimento condicional do
 * PortfolioController com o campo `source` (`adman|ml|unified|none`) e
 * `source_counts` agregado.
 *
 * Foco:
 *  - Flag `metrics.unified_metrics_enabled` OFF preserva payload legacy.
 *  - Flag ON adiciona `source` em cada empresa de `companies[]` (Portfolio/Show)
 *    e `source_counts` em cada profissional de `user_portfolios[]`
 *    (Portfolio/Carteiras).
 *  - Empresas ML-only e sem-integração renderizam sem exception (ADR DATA-04
 *    casos "so-ml" e "none"), ratificando SC #2 do ROADMAP Phase 60.
 *
 * Rotas exercitadas:
 *  - `portfolio.show` (admin visualizando carteira de um analista) →
 *    `PortfolioController::renderPortfolio`.
 *  - `portfolio.own` (admin) → `PortfolioController::renderCarteirasConsolidadas`.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — vocabulário dos 4
 * valores válidos de `source` e casos de detecção.
 */
class PortfolioSourceEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);

        // Setor Performance + cargo Analista (via user_setores) — permite que
        // `renderCarteirasConsolidadas` monte a lista de analistas/estrategistas.
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-61',
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

    // ─────────────────────────── Helpers ───────────────────────────

    /**
     * Cria user consultor com cargo analista atrelado ao setor Performance.
     */
    private function criarAnalista(): User
    {
        $user = User::factory()->create([
            'role'   => 'consultor',
            'active' => true,
        ]);

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

    private function attachMlToken(Company $company): void
    {
        MlToken::create([
            'company_id'        => $company->id,
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

    /**
     * Attach de empresa na carteira do user via pivot company_users
     * (role='consultor' — padrão do renderPortfolio).
     */
    private function attachCarteira(User $user, Company $company): void
    {
        $user->companies()->attach($company->id, [
            'role'        => 'consultor',
            'assigned_at' => now(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Flag OFF: payload de Portfolio/Show NÃO contém `source`
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_portfolio_show_nao_contem_source_em_companies(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        $analista = $this->criarAnalista();
        $company  = Company::factory()->create(['adman_account_id' => '12345']);
        $this->attachCarteira($analista, $company);

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 1)
                ->missing('companies.0.source'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Flag ON: `companies[].source` com enum válido (ADR DATA-04)
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_show_enriquece_companies_com_source_valido(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // 3 empresas cobrindo 3 casos: adman-only, ambos (Adman + ML) e ml-only.
        $soAdman = Company::factory()->create(['adman_account_id' => '11111']);
        $ambos   = Company::factory()->create(['adman_account_id' => '22222']);
        $soMl    = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => '33333']);

        $this->attachMlToken($ambos);
        $this->attachMlToken($soMl);

        $this->attachCarteira($analista, $soAdman);
        $this->attachCarteira($analista, $ambos);
        $this->attachCarteira($analista, $soMl);

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 3, fn (AssertableInertia $c) => $c
                    ->has('source')
                    ->where('source', fn (string $v) => in_array($v, ['adman', 'ml', 'unified', 'none'], true))
                    ->etc()
                ),
        );

        // Verificação cross: cada empresa mapeia pro `source` esperado.
        $props    = $response->viewData('page')['props'];
        $bySource = collect($props['companies'])->pluck('source', 'id')->all();

        $this->assertSame('adman',   $bySource[$soAdman->id]);
        $this->assertSame('unified', $bySource[$ambos->id]);
        $this->assertSame('ml',      $bySource[$soMl->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Flag ON: `user_portfolios[].source_counts` presente
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_own_admin_enriquece_user_portfolios_com_source_counts(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();
        // Carteira: 1 adman + 1 ml + 1 unified — soma bate com companies_count.
        $c1 = Company::factory()->create(['adman_account_id' => '11111']);
        $c2 = Company::factory()->create(['adman_account_id' => null, 'ml_store_id' => '22222']);
        $c3 = Company::factory()->create(['adman_account_id' => '33333']);

        $this->attachMlToken($c2);
        $this->attachMlToken($c3);

        foreach ([$c1, $c2, $c3] as $c) {
            $this->attachCarteira($analista, $c);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.own'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Carteiras')
                ->has('user_portfolios', 1, fn (AssertableInertia $up) => $up
                    ->has('source_counts', fn (AssertableInertia $sc) => $sc
                        ->has('adman')
                        ->has('ml')
                        ->has('unified')
                        ->has('none')
                    )
                    ->etc()
                ),
        );

        $props   = $response->viewData('page')['props'];
        $carteira = collect($props['user_portfolios'])->firstWhere('id', $analista->id);
        $counts   = $carteira['source_counts'];

        $this->assertSame(1, $counts['adman']);
        $this->assertSame(1, $counts['ml']);
        $this->assertSame(1, $counts['unified']);
        $this->assertSame(0, $counts['none']);
        $this->assertSame(3, $counts['adman'] + $counts['ml'] + $counts['unified'] + $counts['none']);
        $this->assertSame(3, $carteira['companies_count']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Flag ON: empresa ML-only não gera erro Adman (SC #2)
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_ml_only_nao_gera_erro_adman_em_portfolio_show(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // Empresa ML-only: mlToken active + adman_account_id NULL.
        // ADR DATA-04 caso "so-ml" — MetricsProviderFactory::caseFor() = 'so-ml';
        // controller enriquece com source='ml' sem chamar endpoints Adman.
        $mlOnly = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => '999999',
        ]);
        $this->attachMlToken($mlOnly);
        $this->attachCarteira($analista, $mlOnly);

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.show', $analista));

        $response->assertOk(); // sem exception, sem 422/500
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 1)
                ->where('companies.0.source', 'ml'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 5 — Flag ON: empresa sem integração aparece com source='none'
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_empresa_sem_integracao_aparece_com_source_none(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // ADR DATA-04 caso "none": sem mlToken E sem adman_account_id.
        // DTO sentinela permite ao consumidor distinguir "empresa sem
        // integração" de "erro na leitura".
        $none = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);
        $this->attachCarteira($analista, $none);

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 1)
                ->where('companies.0.source', 'none'),
        );
    }
}
