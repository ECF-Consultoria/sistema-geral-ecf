<?php

namespace Tests\Feature\Phase130;

use App\Models\Setor;
use App\Models\User;
use App\Support\AudienciaRedeSeguranca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 130 Plano 02 (D-02) — AudienciaRedeSeguranca::adminsEComercial().
 *
 * Prova a armadilha central desta task: um membro comum do setor Comercial
 * (sem liderança, sem a permission `comercial.cadastrar_empresa`) PRECISA
 * aparecer na audiência — é exatamente o caso que
 * `AudienciaComercial::lideresEPermissionados()` deixa de fora.
 */
class AudienciaRedeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    private function criarSetorComercial(): Setor
    {
        return Setor::firstOrCreate(
            ['slug' => 'comercial'],
            ['nome' => 'Comercial', 'active' => true, 'is_system' => false],
        );
    }

    public function test_admin_ativo_aparece(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $ids = AudienciaRedeSeguranca::adminsEComercial()->pluck('id')->all();

        $this->assertContains($admin->id, $ids);
    }

    public function test_admin_inativo_nao_aparece(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => false]);

        $ids = AudienciaRedeSeguranca::adminsEComercial()->pluck('id')->all();

        $this->assertNotContains($admin->id, $ids);
    }

    public function test_membro_comum_do_comercial_sem_lideranca_e_sem_permission_aparece(): void
    {
        // Este é o teste que prova a D-02 contra a armadilha do
        // lideresEPermissionados(): membro comum, NÃO líder, sem a
        // permission comercial.cadastrar_empresa vinculada ao setor.
        $setor = $this->criarSetorComercial();

        $analista = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setor->membros()->attach($analista->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        $ids = AudienciaRedeSeguranca::adminsEComercial()->pluck('id')->all();

        $this->assertContains($analista->id, $ids, 'Membro comum do setor Comercial deve estar na audiência (D-02).');
    }

    public function test_membro_inativo_do_comercial_nao_aparece(): void
    {
        $setor = $this->criarSetorComercial();

        $inativo = User::factory()->create(['role' => 'consultor', 'active' => false]);
        $setor->membros()->attach($inativo->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        $ids = AudienciaRedeSeguranca::adminsEComercial()->pluck('id')->all();

        $this->assertNotContains($inativo->id, $ids);
    }

    public function test_usuario_ativo_de_outro_setor_nao_aparece(): void
    {
        $this->criarSetorComercial();

        $setorDev = Setor::firstOrCreate(
            ['slug' => 'desenvolvimento'],
            ['nome' => 'Desenvolvimento', 'active' => true, 'is_system' => false],
        );

        $devUser = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $setorDev->membros()->attach($devUser->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        $ids = AudienciaRedeSeguranca::adminsEComercial()->pluck('id')->all();

        $this->assertNotContains($devUser->id, $ids);
    }

    public function test_admin_que_tambem_e_membro_do_comercial_aparece_uma_unica_vez(): void
    {
        $setor = $this->criarSetorComercial();

        $adminComercial = User::factory()->create(['role' => 'admin', 'active' => true]);
        $setor->membros()->attach($adminComercial->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        $audiencia = AudienciaRedeSeguranca::adminsEComercial();
        $ids       = $audiencia->pluck('id')->all();

        $this->assertSame(1, count(array_filter($ids, fn ($id) => $id === $adminComercial->id)));
    }

    public function test_setor_comercial_inexistente_devolve_so_admins_sem_lancar_excecao(): void
    {
        // Nenhum setor 'comercial' criado neste teste — cenário de banco
        // recém-instalado ou seed que ainda não rodou.
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $audiencia = AudienciaRedeSeguranca::adminsEComercial();

        $this->assertContains($admin->id, $audiencia->pluck('id')->all());
        $this->assertCount(1, $audiencia);
    }
}
