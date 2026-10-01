<?php

namespace Tests\Feature\DemandasDev;

use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Tickets e Demandas Dev em teste com o time dev: ocultos para quem não é Dev,
 * no MENU e na ROTA. Módulo ainda não sincronizado vale como oculto (senão
 * ficaria aberto na janela entre o deploy e o `modules:sync`).
 */
class ModulosOcultosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function adminComum(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function dev(): User
    {
        $u = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $u->forceFill(['is_dev' => true])->save();

        return $u->refresh();
    }

    public function test_sem_sync_os_modulos_valem_como_ocultos_para_quem_nao_e_dev(): void
    {
        $admin = $this->adminComum();

        $this->actingAs($admin)->get('/tickets')->assertNotFound();
        $this->actingAs($admin)->get('/dev/demandas')->assertNotFound();
        $this->actingAs($admin)->post('/tickets', ['titulo' => 'x'])->assertNotFound();
        $this->actingAs($admin)->get('/chamados')->assertRedirect('/tickets'); // e /tickets dá 404

        $this->actingAs($admin)->get('/profile')->assertInertia(fn (Assert $p) => $p
            ->where('auth.tickets', false)
            ->where('auth.demandas_dev', false));
    }

    public function test_dev_entra_mesmo_com_os_modulos_ocultos(): void
    {
        $dev = $this->dev();

        $this->actingAs($dev)->get('/tickets')->assertOk();
        $this->actingAs($dev)->get('/dev/demandas')->assertOk();
        $this->actingAs($dev)->get('/profile')->assertInertia(fn (Assert $p) => $p
            ->where('auth.tickets', true)
            ->where('auth.demandas_dev', true));
    }

    public function test_sync_cria_os_dois_ja_ocultos_e_liberar_no_controle_dev_abre_para_todos(): void
    {
        $this->artisan('modules:sync')->assertSuccessful();

        $this->assertFalse(Module::where('key', 'chamados')->value('visivel_para_todos'));
        $this->assertFalse(Module::where('key', 'dev.demandas')->value('visivel_para_todos'));
        // Os demais módulos continuam nascendo visíveis.
        $this->assertTrue(Module::where('key', 'dev.desenvolvimento')->value('visivel_para_todos'));

        $admin = $this->adminComum();
        $colaborador = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $this->actingAs($colaborador)->get('/tickets')->assertNotFound();

        // Liberar = ligar em Dev → Controle Dev (a flag), sem deploy.
        Module::where('key', 'chamados')->first()->update(['visivel_para_todos' => true]);
        Module::where('key', 'dev.demandas')->first()->update(['visivel_para_todos' => true]);

        $this->actingAs($colaborador)->get('/tickets')->assertOk();
        $this->actingAs($admin)->get('/dev/demandas')->assertOk();
    }
}
