<?php

namespace Tests\Feature\DevModulos;

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MVP Cargo Dev — trava o comportamento da visibilidade de módulos no menu.
 *
 * O ponto sensível é o gate `isAdminDev()` da tela /dev/modulos: é ele que
 * garante a premissa "o Dev pode ocultar coisas ATÉ dos Admins". Um admin
 * comum (role=admin, is_dev=false) NÃO pode ver nem alterar a visibilidade.
 */
class DevModulosVisibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /** Dev = admin com is_dev=true (is_dev é guarded → forceFill). */
    private function devUser(): User
    {
        $u = User::factory()->create(['role' => 'admin']);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    /** Admin comum = role=admin, is_dev=false (default). */
    private function adminComum(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_comum_recebe_403_na_tela_de_modulos(): void
    {
        $this->actingAs($this->adminComum())
            ->get('/dev/modulos')
            ->assertForbidden();
    }

    public function test_dev_acessa_a_tela_de_modulos(): void
    {
        Module::factory()->create(['route_prefix' => 'amazon.dashboard']);

        $this->actingAs($this->devUser())
            ->get('/dev/modulos')
            ->assertOk();
    }

    public function test_admin_comum_nao_pode_alterar_visibilidade(): void
    {
        $m = Module::factory()->create(['visivel_para_todos' => true]);

        $this->actingAs($this->adminComum())
            ->patch("/dev/modulos/{$m->id}/visibilidade", ['visivel_para_todos' => false])
            ->assertForbidden();

        $this->assertTrue($m->fresh()->visivel_para_todos, 'Admin comum não pode ocultar módulo.');
    }

    public function test_dev_oculta_modulo_e_registry_reflete_a_rota_oculta(): void
    {
        $m = Module::factory()->create([
            'route_prefix'       => 'amazon.dashboard',
            'visivel_para_todos' => true,
        ]);

        $registry = app(ModuleRegistry::class);
        $this->assertNotContains('amazon.dashboard', $registry->hiddenRoutes());

        $this->actingAs($this->devUser())
            ->patch("/dev/modulos/{$m->id}/visibilidade", ['visivel_para_todos' => false])
            ->assertRedirect();

        $this->assertFalse($m->fresh()->visivel_para_todos);
        // Module::saved → ModuleRegistry::flush() → próximo hiddenRoutes() já reflete.
        $this->assertContains('amazon.dashboard', $registry->hiddenRoutes());
    }

    public function test_modulo_sem_route_prefix_nunca_entra_nas_rotas_ocultas(): void
    {
        Module::factory()->create([
            'route_prefix'       => null,
            'visivel_para_todos' => false,
        ]);

        $this->assertSame([], app(ModuleRegistry::class)->hiddenRoutes());
    }
}
