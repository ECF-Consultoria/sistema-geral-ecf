<?php

namespace Tests\Feature\Phase131;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Fase 131 Plano 03 (UI-05, D-09, D-10, T-131-03-01/02) —
 * gate de permissão `admin.contratos` da rota `admin.contratos.index`.
 *
 * O cenário mais importante desta suíte é o terceiro (permission concedida
 * via setor): ele prova que a UI-05 não está inoperante — se algum dia a
 * rota for colada dentro do grupo `role:admin`, este teste fica vermelho.
 */
class ContratoAdminPermissaoTest extends TestCase
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
            'slug'   => 'contratos-teste-'.uniqid(),
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

    public function test_usuario_sem_a_key_recebe_403(): void
    {
        $response = $this->actingAs($this->naoAdmin())->get(route('admin.contratos.index'));

        $response->assertForbidden();
    }

    public function test_role_admin_recebe_200_via_short_circuit(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.contratos.index'));

        $response->assertOk();
    }

    public function test_usuario_com_a_permission_via_setor_recebe_200(): void
    {
        $user = $this->userComPermissaoViaSetor(Permissions::ADMIN_CONTRATOS);

        $response = $this->actingAs($user)->get(route('admin.contratos.index'));

        $response->assertOk();
    }

    /**
     * Asserção de fonte/comportamento sobre o middleware da rota nomeada —
     * pega também o caso de a rota ser envolvida por um grupo externo
     * (`role:admin`), não só o texto literal do arquivo de rotas.
     */
    public function test_a_rota_usa_permission_admin_contratos_e_nunca_role_admin(): void
    {
        $rota = Route::getRoutes()->getByName('admin.contratos.index');

        $this->assertNotNull($rota, 'A rota admin.contratos.index precisa existir.');

        $middlewares = $rota->gatherMiddleware();

        $temPermissionCerta = collect($middlewares)->contains(
            fn (string $m) => $m === 'permission:'.Permissions::ADMIN_CONTRATOS
        );
        $temRoleAdmin = collect($middlewares)->contains(
            fn (string $m) => $m === 'role:admin'
        );

        $this->assertTrue($temPermissionCerta, 'A rota deve usar permission:admin.contratos — middlewares: '.implode(', ', $middlewares));
        $this->assertFalse($temRoleAdmin, 'A rota NUNCA pode estar sob role:admin (UI-05/D-09) — middlewares: '.implode(', ', $middlewares));
    }
}
