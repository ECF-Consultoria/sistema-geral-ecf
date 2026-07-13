<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 75 — Painel de cards de anúncios ancorado em Company+MlToken.
 *
 * O módulo lista `companies` que têm ml_token (não mais mlb_empresas). O wizard
 * recebe route model binding de Company. Escopo por responsavel_id foi deferido —
 * gate role:admin garante que todo acessante é admin e vê todas as contas.
 *
 * @group phase75
 */
class AnunciosEscopoResponsavelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function criarPublicador(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /**
     * Cria uma Company com MlToken associado.
     *
     * @param  bool $tokenExpirado  Quando true, expires_at é colocado no passado.
     * @return Company
     */
    private function criarCompanyComToken(bool $tokenExpirado = false): Company
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . uniqid(),
            'access_token'  => 'token_test',
            'refresh_token' => 'refresh_test',
            'status'        => 'active',
            'expires_at'    => $tokenExpirado ? now()->subHour() : now()->addDays(30),
            'connected_at'  => now()->subDays(5),
        ]);

        return $company;
    }

    /** Cria uma Company SEM MlToken. */
    private function criarCompanySemToken(): Company
    {
        return Company::factory()->create();
    }

    // ─── Testes HTTP (admin, gate role:admin) ───

    /**
     * SEL-01: index() renderiza o componente Mlb/AnunciosEmpresas (painel de cards).
     */
    public function test_index_renderiza_painel_de_cards(): void
    {
        $admin = $this->criarAdmin();

        $this->actingAs($admin)
            ->get('/mlb/anuncios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciosEmpresas')
                ->has('empresas')
            );
    }

    /**
     * Gate admin-only: um usuário sem role admin é bloqueado pelo middleware.
     */
    public function test_nao_admin_bloqueado_pelo_gate(): void
    {
        $pub = $this->criarPublicador();

        $this->actingAs($pub)
            ->get('/mlb/anuncios')
            ->assertForbidden();
    }

    /**
     * SEL-02: admin vê TODAS as companies com ml_token (e só as com token).
     *
     * 2 companies COM MlToken + 1 SEM → painel lista 2.
     */
    public function test_admin_ve_apenas_companies_com_ml_token(): void
    {
        $admin = $this->criarAdmin();

        $this->criarCompanyComToken();
        $this->criarCompanyComToken();
        $this->criarCompanySemToken(); // não deve aparecer no painel

        $this->actingAs($admin)
            ->get('/mlb/anuncios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciosEmpresas')
                ->has('empresas', 2)
            );
    }

    /**
     * SEL-06: company cujo MlToken->isExpired()===true sai com token_expirado=true.
     */
    public function test_token_expirado_marca_token_expirado_true(): void
    {
        $admin = $this->criarAdmin();

        $this->criarCompanyComToken(tokenExpirado: true);

        $this->actingAs($admin)
            ->get('/mlb/anuncios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciosEmpresas')
                ->has('empresas', 1)
                ->where('empresas.0.tem_token', true)
                ->where('empresas.0.token_expirado', true)
            );
    }

    /**
     * SEL-07: wizard de company COM token abre (200) e renderiza Mlb/AnunciarML.
     *
     * empresa.id no prop deve ser o company_id (âncora do novo modelo).
     */
    public function test_wizard_company_com_token_abre_ok(): void
    {
        $admin   = $this->criarAdmin();
        $company = $this->criarCompanyComToken();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$company->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->where('empresa.id', $company->id)
                ->where('empresa.company_id', $company->id)
                ->where('empresa.tem_token', true)
            );
    }

    /**
     * SEL-07: wizard de company SEM token retorna 404.
     */
    public function test_wizard_company_sem_token_retorna_404(): void
    {
        $admin   = $this->criarAdmin();
        $company = $this->criarCompanySemToken();

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$company->id}")
            ->assertNotFound();
    }
}
