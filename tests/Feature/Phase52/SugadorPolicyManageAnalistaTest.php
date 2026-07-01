<?php

namespace Tests\Feature\Phase52;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\Sugador;
use App\Models\User;
use App\Policies\SugadorPolicy;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 52 A1 — SugadorPolicy::manage inclui analista.
 *
 * Cobertura obrigatória (§9 da tabela no docblock do Policy):
 *  - analista com CORE_SUGADORES → manage=true (mudança nova)
 *  - analista sem CORE_SUGADORES → manage=false (proteção)
 *  - publicador sem CORE_SUGADORES → manage=false (não regride)
 *  - admin → manage=true (bypass) — regressão
 *  - gestor com CORE_SUGADORES_GLOBAL → manage=true — regressão
 *  - lider com CORE_SUGADORES_GLOBAL → manage=true — regressão
 */
class SugadorPolicyManageAnalistaTest extends TestCase
{
    use RefreshDatabase;

    private SugadorPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new SugadorPolicy();
    }

    /**
     * Helper: cria setor com uma permission key e attacha o user como membro.
     * Segue o pattern de Phase13ComercialTest::userComPermissao.
     */
    private function userComPermissao(string $permissionKey, string $slug = 'operacional'): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor Teste ' . $slug,
            'slug'   => $slug . '-' . uniqid(),
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

    /**
     * Cenário 1 — Analista com CORE_SUGADORES: DEVE conseguir manage (mudança nova A1).
     */
    public function test_analista_com_permissao_manage_true(): void
    {
        $analista = $this->userComPermissao(Permissions::CORE_SUGADORES, 'analista');

        $this->assertTrue(
            $this->policy->manage($analista),
            'Analista com permissão CORE_SUGADORES deve conseguir configurar (A1).'
        );
    }

    /**
     * Cenário 2 — Analista sem permission: manage=false (proteção contra role sem permission).
     */
    public function test_analista_sem_permissao_manage_false(): void
    {
        // User consultor sem attach a nenhum setor — sem CORE_SUGADORES.
        $user = User::factory()->create(['role' => 'consultor']);

        $this->assertFalse(
            $this->policy->manage($user),
            'User sem CORE_SUGADORES não deve conseguir configurar.'
        );
    }

    /**
     * Cenário 3 — Publicador (sem CORE_SUGADORES): manage=false (não regride).
     * Setor Publicação com permissão MLB_PUBLICACOES apenas.
     */
    public function test_publicador_manage_false(): void
    {
        $publicador = $this->userComPermissao(Permissions::MLB_PUBLICACOES, 'publicacao');

        $this->assertFalse(
            $this->policy->manage($publicador),
            'Publicador (sem CORE_SUGADORES) não deve conseguir configurar.'
        );
    }

    /**
     * Cenário 4 — Admin: manage=true via short-circuit isAdmin() (regressão).
     */
    public function test_admin_manage_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertTrue(
            $this->policy->manage($admin),
            'Admin sempre pode configurar (bypass isAdmin).'
        );
    }

    /**
     * Cenário 5 — Gestor (via CORE_SUGADORES_GLOBAL): manage=true (regressão).
     */
    public function test_gestor_manage_true(): void
    {
        $gestor = $this->userComPermissao(Permissions::CORE_SUGADORES_GLOBAL, 'gestor-pub');

        $this->assertTrue(
            $this->policy->manage($gestor),
            'Gestor com CORE_SUGADORES_GLOBAL continua podendo configurar (regressão).'
        );
    }

    /**
     * Cenário 6 — Líder (via CORE_SUGADORES_GLOBAL): manage=true (regressão).
     */
    public function test_lider_manage_true(): void
    {
        $lider = $this->userComPermissao(Permissions::CORE_SUGADORES_GLOBAL, 'lider-pub');

        $this->assertTrue(
            $this->policy->manage($lider),
            'Líder com CORE_SUGADORES_GLOBAL continua podendo configurar (regressão).'
        );
    }
}
