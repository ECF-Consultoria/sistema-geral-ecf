<?php

namespace Tests\Feature\Phase62;

use App\Models\Company;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 62 Plan 62-01 (Wave 1, META-04) — Auth do endpoint PUT /goals/{goal}.
 *
 * Ate a Phase 62, PUT estava gated por role:admin no middleware. Objetivo:
 * abrir edicao pra estrategista vinculado a empresa via pivot company_users.role,
 * mantendo bloqueio pra consultor/mentor/estranhos.
 *
 * Bonus: bloqueio delete-via-toggle — estrategista NAO pode desativar meta
 * enviando `active=false` no payload. Apenas admin desativa.
 */
class GoalUpdateAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
            'name'   => 'Empresa Teste META-04',
            'cnpj'   => '12345678000199',
            'active' => true,
        ]);
    }

    private function attachUserToCompany(User $user, Company $company, string $role): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function createGoalForCompany(Company $company, array $overrides = []): Goal
    {
        return Goal::create(array_merge([
            'company_id'   => $company->id,
            'metric'       => 'tacos', // percentage-only, presente no CHECK constraint SQLite (migration original)
            'target_value' => 10.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'description'  => 'Meta inicial',
            'active'       => true,
        ], $overrides));
    }

    private function payload(): array
    {
        return [
            'target_value' => 20.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'description'  => 'Meta editada',
        ];
    }

    /** T1 — admin autoriza (200 redirect). */
    public function test_admin_pode_editar_meta(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoalForCompany($company);

        $this->actingAs($admin)
            ->put(route('goals.update', $goal->id), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('goals', [
            'id'           => $goal->id,
            'target_value' => 20.00,
        ]);
    }

    /** T2 — estrategista vinculado autoriza. */
    public function test_estrategista_vinculado_pode_editar_meta(): void
    {
        $estrategista = User::factory()->create(['role' => 'consultor']);
        $company = $this->makeCompany();
        $this->attachUserToCompany($estrategista, $company, 'estrategista');
        $goal = $this->createGoalForCompany($company);

        $this->actingAs($estrategista)
            ->put(route('goals.update', $goal->id), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('goals', [
            'id'           => $goal->id,
            'target_value' => 20.00,
        ]);
    }

    /** T3 — consultor da carteira sem role estrategista NAO edita. */
    public function test_consultor_da_carteira_nao_edita_meta(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $company = $this->makeCompany();
        $this->attachUserToCompany($consultor, $company, 'consultor');
        $goal = $this->createGoalForCompany($company);

        $this->actingAs($consultor)
            ->put(route('goals.update', $goal->id), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseHas('goals', [
            'id'           => $goal->id,
            'target_value' => 10.00, // valor original preservado
        ]);
    }

    /** T4 — mentor sem vinculo pivot NAO edita. */
    public function test_mentor_sem_vinculo_nao_edita_meta(): void
    {
        $mentor = User::factory()->create(['role' => 'mentor']);
        $company = $this->makeCompany();
        $goal = $this->createGoalForCompany($company);

        $this->actingAs($mentor)
            ->put(route('goals.update', $goal->id), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseHas('goals', [
            'id'           => $goal->id,
            'target_value' => 10.00,
        ]);
    }

    /** T5 — user aleatorio (nenhum vinculo) NAO edita. */
    public function test_user_aleatorio_sem_vinculo_nao_edita_meta(): void
    {
        $random = User::factory()->create(['role' => 'consultor']);
        $company = $this->makeCompany();
        $goal = $this->createGoalForCompany($company);

        $this->actingAs($random)
            ->put(route('goals.update', $goal->id), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseHas('goals', [
            'id'           => $goal->id,
            'target_value' => 10.00,
        ]);
    }

    /**
     * T11 — Estrategista NAO consegue desativar meta via active=false
     * (delete-via-toggle bloqueado). Payload aceita, mas coluna `active`
     * descartada silenciosamente.
     */
    public function test_estrategista_nao_desativa_meta_via_active_false(): void
    {
        $estrategista = User::factory()->create(['role' => 'consultor']);
        $company = $this->makeCompany();
        $this->attachUserToCompany($estrategista, $company, 'estrategista');
        $goal = $this->createGoalForCompany($company, ['active' => true]);

        $this->actingAs($estrategista)
            ->put(route('goals.update', $goal->id), array_merge($this->payload(), [
                'active' => false,
            ]))
            ->assertRedirect();

        $goal->refresh();
        $this->assertTrue($goal->active, 'active deve permanecer true (chave descartada pra nao-admin)');
        // Confirma que o resto do payload passou:
        $this->assertEquals(20.00, (float) $goal->target_value);
    }

    /** T12 — Admin AINDA consegue desativar via active=false (backward compat). */
    public function test_admin_ainda_desativa_meta_via_active_false(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoalForCompany($company, ['active' => true]);

        $this->actingAs($admin)
            ->put(route('goals.update', $goal->id), array_merge($this->payload(), [
                'active' => false,
            ]))
            ->assertRedirect();

        $goal->refresh();
        $this->assertFalse($goal->active, 'admin mantem poder de desativar');
    }
}
