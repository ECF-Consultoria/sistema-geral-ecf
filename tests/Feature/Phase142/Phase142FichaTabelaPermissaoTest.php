<?php

namespace Tests\Feature\Phase142;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\EmpresaFaixaFaturamento;
use App\Models\GrupoFaixaFaturamento;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 142 Plano 02 — Tarefa 3: a ARMADILHA CENTRAL do plano. Quem tem `admin.contratos` sem ser
 * admin precisa conseguir ABRIR **e** SALVAR a ficha com a MESMA permissão — se salvar exigisse
 * `isAdmin()` (a autorização da classe-mãe `SalvarFaixasFaturamentoRequest`), a tela abriria
 * normalmente e devolveria 403 exatamente no botão Salvar.
 *
 * Molde de montagem de permissão via setor: `Phase131/ContratoAdminPermissaoTest`.
 */
class Phase142FichaTabelaPermissaoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function naoAdmin(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /** User não-admin que pertence a um setor com a permission_key gravada. */
    private function userComPermissaoViaSetor(string $permissionKey): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Contratos Teste',
            'slug'   => 'contratos-ficha-teste-'.uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => $permissionKey,
        ]);
        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    private function faixas(float $valor = 1_500.00): array
    {
        return [
            ['ordem' => 1, 'limite_superior' => 300_000.00, 'valor' => $valor, 'valor_e_piso' => false],
            ['ordem' => 2, 'limite_superior' => null, 'valor' => $valor * 2, 'valor_e_piso' => true],
        ];
    }

    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia'         => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
        ];
    }

    // ── (a) permissão de setor abre E salva ───────────────────────────────

    #[Test]
    public function usuario_com_admin_contratos_via_setor_abre_a_ficha(): void
    {
        $user    = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $company = Company::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('admin.contratos.tabela.show', $company));

        $response->assertOk();
    }

    #[Test]
    public function usuario_com_admin_contratos_via_setor_salva_com_sucesso(): void
    {
        // É este teste que trava a armadilha central: se `salvar()` exigisse `isAdmin()`
        // (autorização da classe-mãe), este usuário abriria a ficha acima com 200 e levaria 403
        // aqui — a tela existiria, mas o botão Salvar não funcionaria para quem ela foi feita.
        $user    = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $company = Company::factory()->create();

        $response = $this->actingAs($user)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => $this->faixas(),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();

        $linhas = EmpresaFaixaFaturamento::where('company_id', $company->id)->get();
        $this->assertCount(2, $linhas);
    }

    // ── (b) o mesmo usuário consegue remover e salvarGrupo ────────────────

    #[Test]
    public function usuario_com_admin_contratos_via_setor_consegue_remover(): void
    {
        $user    = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $company = Company::factory()->create();
        EmpresaFaixaFaturamento::create([
            'company_id' => $company->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 999.00, 'valor_e_piso' => false, 'origem' => EmpresaFaixaFaturamento::ORIGEM_MANUAL,
        ]);

        $response = $this->actingAs($user)->delete(route('admin.contratos.tabela.remover', $company));

        $response->assertStatus(302);
        $this->assertSame(0, EmpresaFaixaFaturamento::where('company_id', $company->id)->count());
    }

    #[Test]
    public function usuario_com_admin_contratos_via_setor_consegue_salvar_grupo(): void
    {
        $user  = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $grupo = CompanyGroup::create(['name' => 'Grupo Permissão Teste '.uniqid()]);

        $response = $this->actingAs($user)->post(route('admin.contratos.tabela.grupo.salvar', $grupo), [
            'faixas' => $this->faixas(),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
        $this->assertCount(2, GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->get());
    }

    #[Test]
    public function usuario_com_admin_contratos_via_setor_consegue_remover_grupo(): void
    {
        $user  = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);
        $grupo = CompanyGroup::create(['name' => 'Grupo Permissão Teste '.uniqid()]);
        GrupoFaixaFaturamento::create([
            'company_group_id' => $grupo->id, 'ordem' => 1, 'limite_superior' => null,
            'valor' => 999.00, 'valor_e_piso' => false,
        ]);

        $response = $this->actingAs($user)->delete(route('admin.contratos.tabela.grupo.remover', $grupo));

        $response->assertStatus(302);
        $this->assertSame(0, GrupoFaixaFaturamento::where('company_group_id', $grupo->id)->count());
    }

    // ── (c) sem admin.contratos e sem role admin: 403 nos cinco endpoints ─

    #[Test]
    public function usuario_sem_a_permissao_toma_403_nos_cinco_endpoints(): void
    {
        $user    = $this->naoAdmin();
        $company = Company::factory()->create();
        $grupo   = CompanyGroup::create(['name' => 'Grupo Sem Permissão '.uniqid()]);

        $this->actingAs($user)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('admin.contratos.tabela.show', $company))
            ->assertForbidden();

        $this->actingAs($user)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => $this->faixas(),
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('admin.contratos.tabela.remover', $company))->assertForbidden();

        $this->actingAs($user)->post(route('admin.contratos.tabela.grupo.salvar', $grupo), [
            'faixas' => $this->faixas(),
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('admin.contratos.tabela.grupo.remover', $grupo))->assertForbidden();
    }

    // ── (d) admin puro (sem a permission nomeada) continua passando ───────

    #[Test]
    public function admin_puro_sem_a_permission_nomeada_continua_passando(): void
    {
        $admin   = $this->admin();
        $company = Company::factory()->create();

        $this->actingAs($admin)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('admin.contratos.tabela.show', $company))
            ->assertOk();

        $response = $this->actingAs($admin)->post(route('admin.contratos.tabela.salvar', $company), [
            'faixas' => $this->faixas(),
        ]);
        $response->assertStatus(302);
        $response->assertSessionHasNoErrors();
    }
}
