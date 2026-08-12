<?php

namespace Tests\Feature\Phase135;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 135 Plano 09 — Task 1: gate de acesso do painel operacional
 * (`permission:core.onboarding`, distinto do `role:admin` do CRUD de
 * template — D-04). Task 3 acrescenta as duas ações da Coordenação
 * (confirmar responsável / concluir passo manual) a esta mesma suíte.
 */
class OnboardingPainelAcoesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria um setor com `core.onboarding` concedido em `setor_permissoes` e
     * devolve um user (não-admin) membro dele — mesmo padrão de
     * `tests/Feature/Phase123/PerformanceAutorizacaoTest.php`.
     */
    private function userComPermissaoPainel(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Coordenação Onboarding 135-09',
            'slug'       => 'coordenacao-onboarding-135-09',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'core.onboarding',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ─── Task 1: gate de acesso ─────────────────────────────────────────────

    #[Test]
    public function get_onboarding_sem_permission_e_sem_ser_admin_retorna_403(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($consultor)->get(route('onboarding.painel.index'));

        $response->assertForbidden();
    }

    #[Test]
    public function get_onboarding_com_permission_retorna_200(): void
    {
        $this->withoutVite();
        $user = $this->userComPermissaoPainel();

        $this->assertTrue($user->hasPermission('core.onboarding'), 'setup: user deveria ter core.onboarding via setor_permissoes');

        $response = $this->actingAs($user)->get(route('onboarding.painel.index'));

        $response->assertOk();
    }

    #[Test]
    public function get_onboarding_como_admin_sem_a_permission_explicita_retorna_200(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
    }
}
