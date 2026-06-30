<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 49 Plan 49-02 (REQ-49-02).
 *
 * Verifica que a rota GET /publicacao/desempenho:
 *  1. Retorna 200 para user admin (short-circuit hasPermission)
 *  2. Retorna 403 para user sem permission mlb.dashboard
 *  3. Retorna 200 para user com permission mlb.dashboard (via setor_permissoes)
 *  4. Renderiza component Performance/Index com prop setor='polos'
 *
 * Estratégia: SQLite in-memory via RefreshDatabase. Admin usa short-circuit.
 * User com permission mlb.dashboard criado via setor + setor_permissoes + user_setores.
 */
class PublicacaoDesempenhoRouteTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Cria e autentica user admin (short-circuit total em hasPermission).
     */
    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Pub49 ' . uniqid(),
            'email'    => 'admin.pub49.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    /**
     * Cria user consultor SEM nenhum setor/permission.
     */
    private function criarUserSemPermissao(): User
    {
        return User::create([
            'name'     => 'Sem Perm ' . uniqid(),
            'email'    => 'semperm.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
    }

    /**
     * Cria user consultor com permission mlb.dashboard via setor_permissoes.
     */
    private function criarUserComMlbDashboard(): User
    {
        $user = User::create([
            'name'     => 'MLB Dash ' . uniqid(),
            'email'    => 'mlbdash.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        // Setor mínimo com a permission mlb.dashboard
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Publicações Test ' . uniqid(),
            'slug'       => 'publicacoes-test-' . uniqid(),
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'mlb.dashboard',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id'   => $setorId,
            'nome'       => 'Publicador',
            'slug'       => 'publicador-' . uniqid(),
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Admin → 200
    // ═════════════════════════════════════════════════════════════════════════

    public function test_admin_acessa_rota_e_recebe_200(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/publicacao/desempenho');

        $response->assertStatus(200);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. User sem permission → 403
    // ═════════════════════════════════════════════════════════════════════════

    public function test_user_sem_permission_recebe_403(): void
    {
        $user = $this->criarUserSemPermissao();
        $this->actingAs($user);

        $response = $this->get('/publicacao/desempenho');

        $response->assertStatus(403);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. User com permission mlb.dashboard → 200
    // ═════════════════════════════════════════════════════════════════════════

    public function test_user_com_mlb_dashboard_acessa_rota_e_recebe_200(): void
    {
        $user = $this->criarUserComMlbDashboard();
        $this->actingAs($user);

        $response = $this->get('/publicacao/desempenho');

        $response->assertStatus(200);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. Admin → renderiza Performance/Index com setor='polos'
    // ═════════════════════════════════════════════════════════════════════════

    public function test_admin_renderiza_performance_index_com_setor_polos(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/publicacao/desempenho');

        $response->assertStatus(200);

        $props = $response->viewData('page')['props'] ?? [];

        $this->assertSame('polos', $props['setor'] ?? null,
            'Prop setor deve ser "polos" (delegado a indexPolos())');
    }
}
