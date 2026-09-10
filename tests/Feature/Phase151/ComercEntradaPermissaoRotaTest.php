<?php

namespace Tests\Feature\Phase151;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 151 Plano 05 (COMERC-01/02/03, D-15, T-151-15/T-151-16) — gate de
 * permissão da rota `comercial.entrada.index`.
 *
 * Réplica do padrão de `tests/Feature/Phase131/ContratoAdminPermissaoTest.php`:
 * asserção por NOME de rota via `Route::getRoutes()->getByName()` +
 * `gatherMiddleware()`, não por prefixo/texto do arquivo de rotas — pega
 * também o caso de a rota ser envolvida por um grupo externo depois.
 */
class ComercEntradaPermissaoRotaTest extends TestCase
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
            'nome'   => 'Setor Entrada Teste',
            'slug'   => 'entrada-teste-'.uniqid(),
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

    /** Caso 1 + 2 + 3: gatherMiddleware() da rota nova tem a permission certa e nenhuma das erradas. */
    public function test_a_rota_entrada_usa_permission_comercial_entrada_e_nunca_role_admin_nem_cadastrar_empresa(): void
    {
        $rota = Route::getRoutes()->getByName('comercial.entrada.index');

        $this->assertNotNull($rota, 'A rota comercial.entrada.index precisa existir.');

        $middlewares = $rota->gatherMiddleware();

        $temPermissionCerta = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:'.Permissions::COMERCIAL_ENTRADA
        );
        $temRoleAdmin = collect($middlewares)->contains(
            fn (string $m) => $m === 'role:admin'
        );
        $temPermissionCadastro = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:'.Permissions::COMERCIAL_CADASTRAR_EMPRESA
        );

        $this->assertTrue($temPermissionCerta, 'A rota deve usar permission:comercial.entrada — middlewares: '.implode(', ', $middlewares));
        $this->assertFalse($temRoleAdmin, 'A rota NUNCA pode estar sob role:admin (D-15) — middlewares: '.implode(', ', $middlewares));
        $this->assertFalse($temPermissionCadastro, 'A rota NUNCA pode herdar permission:comercial.cadastrar_empresa (D-15) — middlewares: '.implode(', ', $middlewares));
    }

    /** Caso 4: usuário sem a key recebe 403. */
    public function test_usuario_sem_a_key_recebe_403(): void
    {
        $response = $this->actingAs($this->naoAdmin())->get(route('comercial.entrada.index'));

        $response->assertForbidden();
    }

    /** Caso 5: usuário com a permission via setor (sem role:admin) recebe 200. */
    public function test_usuario_com_a_permission_via_setor_recebe_200(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::COMERCIAL_ENTRADA);

        $response = $this->actingAs($user)->get(route('comercial.entrada.index'));

        $response->assertOk();
    }

    /**
     * Caso 6 (regressão D-15 reafirmada dentro da suíte da 138): `admin.contratos.index`
     * continua com `permission:admin.contratos` e sem `role:admin` depois desta mudança de rotas.
     */
    public function test_admin_contratos_continua_com_permission_propria_e_sem_role_admin(): void
    {
        $rota = Route::getRoutes()->getByName('admin.contratos.index');

        $this->assertNotNull($rota, 'A rota admin.contratos.index precisa continuar existindo.');

        $middlewares = $rota->gatherMiddleware();

        $temPermissionCerta = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:'.Permissions::ADMIN_CONTRATOS
        );
        $temRoleAdmin = collect($middlewares)->contains(
            fn (string $m) => $m === 'role:admin'
        );

        $this->assertTrue($temPermissionCerta, 'admin.contratos.index deve continuar com permission:admin.contratos — middlewares: '.implode(', ', $middlewares));
        $this->assertFalse($temRoleAdmin, 'admin.contratos.index NUNCA pode estar sob role:admin — middlewares: '.implode(', ', $middlewares));
    }

    /** Caso 7: o catálogo de permissões conhece a chave nova, no grupo Comercial. */
    public function test_catalogo_de_permissoes_contem_comercial_entrada_no_grupo_comercial(): void
    {
        $catalogo = Permissions::catalog();

        $this->assertArrayHasKey('Comercial', $catalogo);

        $chaves = collect($catalogo['Comercial'])->pluck('key')->all();

        $this->assertContains(Permissions::COMERCIAL_ENTRADA, $chaves);
    }
}
