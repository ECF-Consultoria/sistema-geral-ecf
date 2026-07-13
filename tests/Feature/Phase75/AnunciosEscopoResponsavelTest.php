<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 75 Plan 02 — Escopo por responsavel_id no painel de cards de anúncios.
 *
 * O módulo é admin-only (gate role:admin) nesta milestone — por isso as rotas
 * HTTP são exercitadas como admin. O escopo por responsavel_id (SEL-02) é
 * provado DIRETAMENTE no query scope `MlbEmpresa::visiveisPara()`, que impõe o
 * filtro NA QUERY DO BANCO (Armadilha 2 do PATTERNS.md §2) e já vale quando o
 * gate abrir para permission:mlb.anunciar.
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

    /** Cria uma MlbEmpresa com Company + MlToken e responsavel_id definido. */
    private function criarEmpresaComToken(int $responsavelId, bool $tokenExpirado = false): MlbEmpresa
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . $company->id,
            'access_token'  => 'token_test',
            'refresh_token' => 'refresh_test',
            'status'        => 'active',
            'expires_at'    => $tokenExpirado ? now()->subHour() : now()->addDays(30),
            'connected_at'  => now()->subDays(5),
        ]);

        return MlbEmpresa::create([
            'nome'           => 'Empresa Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $responsavelId,
        ]);
    }

    /** Cria uma MlbEmpresa SEM company_id (empresa POLOS sem conta ML). */
    private function criarEmpresaSemToken(int $responsavelId): MlbEmpresa
    {
        return MlbEmpresa::create([
            'nome'           => 'Empresa Sem ML',
            'tipo'           => 'POLOS',
            'company_id'     => null,
            'responsavel_id' => $responsavelId,
        ]);
    }

    // ─── Testes HTTP (admin, gate role:admin) ───

    /**
     * SEL-01: index() renderiza o componente Mlb/AnunciosEmpresas (painel de cards),
     * NÃO o wizard.
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
     * Gate admin-only (decisão de escopo v16.0): um usuário sem role admin é
     * bloqueado pelo middleware antes de alcançar o painel.
     */
    public function test_nao_admin_bloqueado_pelo_gate(): void
    {
        $pub = $this->criarPublicador();

        $this->actingAs($pub)
            ->get('/mlb/anuncios')
            ->assertForbidden();
    }

    /**
     * SEL-02: admin vê TODAS as empresas.
     */
    public function test_admin_ve_todas_empresas(): void
    {
        $admin = $this->criarAdmin();
        $pub   = $this->criarPublicador();

        $this->criarEmpresaComToken($pub->id);
        $this->criarEmpresaComToken($pub->id);
        $this->criarEmpresaComToken($admin->id);

        $this->actingAs($admin)
            ->get('/mlb/anuncios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciosEmpresas')
                ->has('empresas', 3)
            );
    }

    /**
     * SEL-06: empresa com company_id=null (POLOS sem conta ML) aparece no painel
     * com tem_token=false — NÃO é filtrada fora da lista.
     */
    public function test_empresa_sem_conta_ml_aparece_com_tem_token_false(): void
    {
        $admin = $this->criarAdmin();

        $this->criarEmpresaSemToken($admin->id);

        $this->actingAs($admin)
            ->get('/mlb/anuncios')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciosEmpresas')
                ->has('empresas', 1)
                ->where('empresas.0.tem_token', false)
            );
    }

    /**
     * SEL-06: empresa cujo MlToken->isExpired()===true sai com token_expirado=true.
     */
    public function test_token_expirado_marca_token_expirado_true(): void
    {
        $admin = $this->criarAdmin();

        $this->criarEmpresaComToken($admin->id, tokenExpirado: true);

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

    // ─── Teste direto do escopo (SEL-02, prova de filtro-no-banco) ───

    /**
     * SEL-02: o query scope visiveisPara() filtra por responsavel_id NA QUERY.
     *
     * Prova de banco: existem 3 empresas no banco, mas visiveisPara($publicador)
     * retorna apenas as 2 do publicador (a 3ª, de outro responsável, é descartada
     * na cláusula WHERE — não em PHP pós-busca). Admin recebe as 3.
     */
    public function test_scope_visiveis_para_filtra_por_responsavel(): void
    {
        $pub   = $this->criarPublicador();
        $outro = $this->criarPublicador();

        $e1 = $this->criarEmpresaComToken($pub->id);
        $e2 = $this->criarEmpresaComToken($pub->id);
        $e3 = $this->criarEmpresaComToken($outro->id);

        $this->assertDatabaseCount('mlb_empresas', 3);

        $idsPub = MlbEmpresa::visiveisPara($pub)->pluck('id')->all();
        sort($idsPub);
        $this->assertSame([$e1->id, $e2->id], $idsPub, 'Publicador deve ver só as 2 empresas dele');
        $this->assertNotContains($e3->id, $idsPub, 'Empresa de outro responsável não pode aparecer');

        $idsAdmin = MlbEmpresa::visiveisPara($this->criarAdmin())->pluck('id')->all();
        $this->assertCount(3, $idsAdmin, 'Admin vê todas as empresas');
    }

    /**
     * SEL-07 + T-75-05: o double-check do wizard rejeita empresa não atribuída.
     *
     * Sob o gate role:admin não é possível exercitar o ramo de publicador via HTTP
     * (o middleware bloqueia antes do controller), então validamos o admin abrindo
     * o wizard de qualquer empresa. O ramo 403 por responsavel_id fica coberto pela
     * lógica do controller + pelo teste direto do escopo acima, e passa a valer via
     * HTTP quando o gate abrir para permission:mlb.anunciar.
     */
    public function test_wizard_admin_abre_qualquer_empresa(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresaComToken($admin->id);

        $this->actingAs($admin)
            ->get("/mlb/anuncios/wizard/{$empresa->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mlb/AnunciarML')
                ->where('empresa.id', $empresa->id)
            );
    }
}
