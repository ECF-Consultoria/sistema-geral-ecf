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
 * Phase 61 Plan 61-06 (Wave 3 — E2E) — Portfolio multi-fonte.
 *
 * Fecha o SC #2 do ROADMAP Phase 61 ("Analista/Estrategista tolerantes a
 * ML-only") e complementa o SC #3 (badge ML na carteira individual — este
 * também coberto por `CompanyShowSourceTest` de 61-03) cobrindo E2E os
 * 3 casos canônicos do ADR DATA-04 (só-Adman / só-ML / ambos) + none em
 * Portfolio/Show + Portfolio/Carteiras, com regressão da feature flag OFF.
 *
 * Rotas exercitadas:
 *  - `portfolio.show` (admin visualiza carteira de um analista) →
 *    `PortfolioController::renderPortfolio` (Portfolio/Show.jsx).
 *  - `portfolio.own` (admin) → `PortfolioController::renderCarteirasConsolidadas`
 *    (Portfolio/Carteiras.jsx).
 *
 * Enriquecimento condicional atrás da flag `metrics.unified_metrics_enabled`:
 *  - Show: adiciona `companies[N].source` (adman|ml|unified|none).
 *  - Carteiras: adiciona `user_portfolios[N].source_counts` agregado.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md`.
 */
class PortfolioMultiFonteE2ETest extends TestCase
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
        // `renderCarteirasConsolidadas` monte a lista de analistas via
        // whereExists no pivot user_setores → cargos (fonte da verdade nova
        // desde quick 260610-f69).
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-e2e-61-06',
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
     * (role='consultor' — padrão do renderPortfolio e consultorCompanies()).
     */
    private function attachCarteira(User $user, Company $company): void
    {
        $user->companies()->attach($company->id, [
            'role'        => 'consultor',
            'assigned_at' => now(),
        ]);
    }

    /**
     * Cria empresa cobrindo um dos 4 casos ADR DATA-04.
     *
     * caso: 'so-adman' | 'so-ml' | 'ambos' | 'none'
     */
    private function criarEmpresaPorCaso(string $caso, string $suffix = ''): Company
    {
        return match ($caso) {
            'so-adman' => Company::factory()->create([
                'adman_account_id' => 'ADM' . $suffix,
                'ml_store_id'      => null,
            ]),
            'so-ml' => tap(
                Company::factory()->create([
                    'adman_account_id' => null,
                    'ml_store_id'      => 'ML' . $suffix,
                ]),
                fn (Company $c) => $this->attachMlToken($c),
            ),
            'ambos' => tap(
                Company::factory()->create([
                    'adman_account_id' => 'ADM' . $suffix,
                    'ml_store_id'      => 'ML' . $suffix,
                ]),
                fn (Company $c) => $this->attachMlToken($c),
            ),
            'none' => Company::factory()->create([
                'adman_account_id' => null,
                'ml_store_id'      => null,
            ]),
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Flag OFF: Portfolio/Show NÃO expõe `source` em companies[].
    //          Regressão bit-a-bit do payload legacy.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_portfolio_show_nao_expoe_source_em_companies(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        $analista = $this->criarAnalista();

        // Fixture mista pra provar que a AUSENCIA vale independente do caso.
        $adman = $this->criarEmpresaPorCaso('so-adman', '111');
        $ml    = $this->criarEmpresaPorCaso('so-ml', '222');

        $this->attachCarteira($analista, $adman);
        $this->attachCarteira($analista, $ml);

        // Ajuste 2026-07-09 — auto-view (analista logado abrindo própria carteira)
        // continua renderizando Portfolio/Show. Admin abrindo OUTRO user agora
        // vai para Portfolio/AdminCarteira (view enxuta). Aqui testamos o shape
        // do renderPortfolio, então usamos o próprio analista.
        $response = $this->actingAs($analista)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 2)
                ->missing('companies.0.source')
                ->missing('companies.1.source'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Flag ON: Portfolio/Show expõe `companies[N].source` por
    //          empresa com valor ∈ {adman, ml, unified, none} — mapeado
    //          corretamente por caso ADR.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_show_expoe_source_por_empresa_nos_4_casos(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        $soAdman = $this->criarEmpresaPorCaso('so-adman', '111');
        $soMl    = $this->criarEmpresaPorCaso('so-ml', '222');
        $ambos   = $this->criarEmpresaPorCaso('ambos', '333');
        $none    = $this->criarEmpresaPorCaso('none', '444');

        foreach ([$soAdman, $soMl, $ambos, $none] as $c) {
            $this->attachCarteira($analista, $c);
        }

        // Ajuste 2026-07-09 — auto-view (analista logado abrindo própria carteira)
        // continua renderizando Portfolio/Show. Admin abrindo OUTRO user agora
        // vai para Portfolio/AdminCarteira (view enxuta). Aqui testamos o shape
        // do renderPortfolio, então usamos o próprio analista.
        $response = $this->actingAs($analista)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 4, fn (AssertableInertia $c) => $c
                    ->has('source')
                    ->where('source', fn (string $v) => in_array($v, ['adman', 'ml', 'unified', 'none'], true))
                    ->etc()
                ),
        );

        // Cross-check: cada empresa mapeia pro `source` do caso ADR.
        $props    = $response->viewData('page')['props'];
        $bySource = collect($props['companies'])->pluck('source', 'id')->all();

        $this->assertSame('adman',   $bySource[$soAdman->id]);
        $this->assertSame('ml',      $bySource[$soMl->id]);
        $this->assertSame('unified', $bySource[$ambos->id]);
        $this->assertSame('none',    $bySource[$none->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Flag ON: Portfolio/Show empresa ML-only não gera erro
    //          Adman (SC #2 do ROADMAP Phase 61 — memory
    //          `project_ml_only_companies_adman_endpoints`).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_show_empresa_ml_only_nao_quebra_render(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // ML-only isolada — antes causava 422 no endpoint Adman MCP.
        // Agora o Portfolio enriquece com source='ml' sem chamar Adman.
        $mlOnly = $this->criarEmpresaPorCaso('so-ml', '999');
        $this->attachCarteira($analista, $mlOnly);

        // Ajuste 2026-07-09 — auto-view (analista logado abrindo própria carteira)
        // continua renderizando Portfolio/Show. Admin abrindo OUTRO user agora
        // vai para Portfolio/AdminCarteira (view enxuta). Aqui testamos o shape
        // do renderPortfolio, então usamos o próprio analista.
        $response = $this->actingAs($analista)
            ->get(route('portfolio.show', $analista));

        $response->assertOk(); // sem 500 / exception / 422 mascarado
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 1)
                ->where('companies.0.source', 'ml'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Flag ON: Portfolio/Carteiras (admin) expõe `source_counts`
    //          por profissional (agregação ADR DATA-04).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_carteiras_admin_expoe_source_counts_por_user(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // Carteira mista: 1 adman + 1 ml + 1 unified → soma bate com
        // companies_count. Não incluímos "none" aqui pra provar que a
        // agregação distingue os 4 buckets separadamente (none=0).
        $adman = $this->criarEmpresaPorCaso('so-adman', '111');
        $ml    = $this->criarEmpresaPorCaso('so-ml', '222');
        $ambos = $this->criarEmpresaPorCaso('ambos', '333');

        foreach ([$adman, $ml, $ambos] as $c) {
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
                        ->where('adman', 1)
                        ->where('ml', 1)
                        ->where('unified', 1)
                        ->where('none', 0)
                    )
                    ->etc()
                ),
        );

        // Confirma soma == companies_count (universo intocado).
        $props    = $response->viewData('page')['props'];
        $carteira = collect($props['user_portfolios'])->firstWhere('id', $analista->id);
        $counts   = $carteira['source_counts'];

        $this->assertSame(
            $carteira['companies_count'],
            $counts['adman'] + $counts['ml'] + $counts['unified'] + $counts['none'],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 5 — Flag OFF: Portfolio/Carteiras (admin) NÃO expõe
    //          `source_counts` — regressão do payload legacy.
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_off_portfolio_carteiras_admin_nao_expoe_source_counts(): void
    {
        Config::set('metrics.unified_metrics_enabled', false);

        $analista = $this->criarAnalista();
        // Mesmo cenário do Test 4 (mista) — prova que ausencia vale
        // independente da composição da carteira.
        $adman = $this->criarEmpresaPorCaso('so-adman', '111');
        $ml    = $this->criarEmpresaPorCaso('so-ml', '222');
        $ambos = $this->criarEmpresaPorCaso('ambos', '333');

        foreach ([$adman, $ml, $ambos] as $c) {
            $this->attachCarteira($analista, $c);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('portfolio.own'));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Carteiras')
                ->has('user_portfolios', 1)
                ->missing('user_portfolios.0.source_counts'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 6 — Flag ON: Portfolio/Show empresa "none" aparece com
    //          `source='none'` (não é omitida do universo).
    // ═══════════════════════════════════════════════════════════════

    public function test_flag_on_portfolio_show_empresa_none_aparece(): void
    {
        Config::set('metrics.unified_metrics_enabled', true);

        $analista = $this->criarAnalista();

        // ADR DATA-04 caso "none": sem mlToken E sem adman_account_id.
        // DTO sentinela permite distinguir "sem integração" de "erro" —
        // empresa NÃO desaparece do universo da carteira.
        $none = $this->criarEmpresaPorCaso('none', '000');
        $this->attachCarteira($analista, $none);

        // Ajuste 2026-07-09 — auto-view (analista logado abrindo própria carteira)
        // continua renderizando Portfolio/Show. Admin abrindo OUTRO user agora
        // vai para Portfolio/AdminCarteira (view enxuta). Aqui testamos o shape
        // do renderPortfolio, então usamos o próprio analista.
        $response = $this->actingAs($analista)
            ->get(route('portfolio.show', $analista));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Portfolio/Show')
                ->has('companies', 1)
                ->where('companies.0.id', $none->id)
                ->where('companies.0.source', 'none'),
        );
    }
}
