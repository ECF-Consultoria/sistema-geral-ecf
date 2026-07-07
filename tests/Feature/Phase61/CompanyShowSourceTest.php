<?php

namespace Tests\Feature\Phase61;

use App\Models\Company;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 61 Plan 61-03 (Wave 2, DASH-06) — Enriquecimento UNCONDITIONAL do
 * `CompanyController::show` com o campo `company.source`
 * (`adman|ml|unified|none`) para alimentar o `<SourceBadge>` do header da
 * carteira individual (`/companies/{id}`).
 *
 * Foco:
 *  - Enriquecimento SEM feature flag — DASH-06 é SC obrigatório do ROADMAP
 *    Phase 61 e `MetricsProviderFactory::caseFor()` é I/O-free (só accessors
 *    denormalizados). Diferente de 61-01/61-04 (Portfolio) que sao
 *    flag-guarded, aqui é sempre presente.
 *  - Mapeamento canonico ADR DATA-04 → vocabulario SourceBadge:
 *      'ambos'    → 'unified'  (Adman + ML ativos)
 *      'so-ml'    → 'ml'       (só mlToken active)
 *      'so-adman' → 'adman'    (só adman_account_id, sem mlToken active)
 *      'none'     → 'none'     (sem integração)
 *  - Zero regressao em contrato de props: chaves existentes preservadas, so
 *    ADICAO da chave `source`.
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — vocabulário e casos
 * de detecção.
 */
class CompanyShowSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin bypassa o guard `userCanViewCompany` do controller (isAdmin()
        // retorna true incondicionalmente). Manter escopo do teste em enriquecimento
        // de payload — autorizacao é responsabilidade de outro teste.
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    /**
     * Attach de MlToken active — simula empresa com integracao ML plugada.
     */
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

    // ═══════════════════════════════════════════════════════════════
    // Test 1 — Empresa só-Adman → source='adman'
    // ═══════════════════════════════════════════════════════════════

    public function test_empresa_so_adman_recebe_source_adman(): void
    {
        // ADR DATA-04 caso "so-adman": adman_account_id presente + sem
        // mlToken active. AdmanMetricsProvider::supports() → true;
        // MlMetricsProvider::supports() → false.
        $company = Company::factory()->create([
            'adman_account_id' => '12345',
            'ml_store_id'      => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('companies.show', $company));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Companies/Show')
                ->where('company.source', 'adman'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 2 — Empresa só-ML → source='ml'
    // ═══════════════════════════════════════════════════════════════

    public function test_empresa_so_ml_recebe_source_ml(): void
    {
        // ADR DATA-04 caso "so-ml": mlToken active + adman_account_id NULL.
        // Prova tambem SC #2 do ROADMAP Phase 60 — empresa ML-only nao gera
        // erro Adman no controller (accessor `cust_id` retorna ml_store_id
        // no fallback, mas `is_ml_driven=true` desvia do branch Adman).
        $company = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => '999999',
        ]);
        $this->attachMlToken($company);

        $response = $this->actingAs($this->admin)
            ->get(route('companies.show', $company));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Companies/Show')
                ->where('company.source', 'ml'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 3 — Empresa AMBOS → source='unified'
    // ═══════════════════════════════════════════════════════════════

    public function test_empresa_ambos_recebe_source_unified(): void
    {
        // ADR DATA-04 caso "ambos": adman_account_id presente + mlToken active.
        // MetricsProviderFactory::caseFor() retorna 'ambos' → SourceBadge
        // renderiza "Agregado" (rotulo anti-jargao, nunca "unified" cru na UI).
        $company = Company::factory()->create([
            'adman_account_id' => '54321',
            'ml_store_id'      => '888888',
        ]);
        $this->attachMlToken($company);

        $response = $this->actingAs($this->admin)
            ->get(route('companies.show', $company));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Companies/Show')
                ->where('company.source', 'unified'),
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Test 4 — Empresa sem integracao → source='none'
    // ═══════════════════════════════════════════════════════════════

    public function test_empresa_sem_integracao_recebe_source_none(): void
    {
        // ADR DATA-04 caso "none": sem mlToken E sem adman_account_id.
        // No frontend, o header aplica guarda extra `!== 'none'` para nao
        // poluir o titulo da empresa com badge de ausencia de integracao —
        // mas o payload PRECISA carregar o valor pra manter contrato do enum.
        $company = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('companies.show', $company));

        $response->assertOk();
        $response->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Companies/Show')
                ->where('company.source', 'none'),
        );
    }
}
