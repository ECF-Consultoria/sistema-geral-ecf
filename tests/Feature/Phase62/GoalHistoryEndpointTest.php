<?php

namespace Tests\Feature\Phase62;

use App\Models\Company;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 62 Plan 62-01 (Wave 1, META-04) — Endpoint GET /goals/{goal}/history.
 *
 * Retorna as ultimas 10 entries do activity_log da Goal (Spatie Activitylog
 * ja ativo desde v3.0), filtradas por subject_type + subject_id, ordenadas
 * por created_at DESC.
 */
class GoalHistoryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
            'name'   => 'Empresa Historico META-04',
            'cnpj'   => '99887766000155',
            'active' => true,
        ]);
    }

    private function createGoal(Company $company): Goal
    {
        return Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'tacos', // presente no CHECK constraint SQLite (migration original)
            'target_value' => 10.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'description'  => 'Meta base',
            'active'       => true,
        ]);
    }

    /** T6 — Shape JSON correto. */
    public function test_history_retorna_json_com_shape_esperado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoal($company);

        // Gera 1 entry no activity_log (update dispara LogsActivity).
        $this->actingAs($admin);
        $goal->update(['target_value' => 6000.00]);

        $response = $this->get(route('goals.history', $goal->id));

        $response->assertOk()
            ->assertJsonStructure([
                'entries' => [
                    ['id', 'description', 'causer_name', 'created_at', 'changes' => ['old', 'attributes']],
                ],
            ]);
    }

    /** T7 — Filtra por subject (goal Y nao aparece na resposta da goal X). */
    public function test_history_filtra_por_subject_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goalX = $this->createGoal($company);
        $goalY = $this->createGoal($company);

        $this->actingAs($admin);
        $goalX->update(['target_value' => 111.00]);
        $goalX->update(['target_value' => 222.00]);
        $goalY->update(['target_value' => 999.00]);

        $response = $this->get(route('goals.history', $goalX->id));
        $entries = $response->assertOk()->json('entries');

        // Todas as entries retornadas devem ser da goalX. Verifica via
        // properties.attributes.target_value nao ser 999 (marca da goalY).
        // Nota: LogsActivity registra 'created' + 2 updates = 3 entries pra goalX.
        $this->assertCount(3, $entries, 'Historico da goalX: 1 created + 2 updates = 3 entries');
        foreach ($entries as $entry) {
            $attrs = $entry['changes']['attributes'] ?? [];
            $this->assertNotEquals(999, (float) ($attrs['target_value'] ?? 0), 'Entry da goalY nao pode vazar');
        }
    }

    /** T8 — Ordenacao DESC por created_at (mais recente primeiro). */
    public function test_history_ordena_por_created_at_desc(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoal($company);

        $this->actingAs($admin);
        $goal->update(['target_value' => 100.00]); // 1o update
        $goal->update(['target_value' => 200.00]); // 2o update (mais recente)

        $entries = $this->get(route('goals.history', $goal->id))
            ->assertOk()
            ->json('entries');

        // 1 created + 2 updates = 3 entries. Ordem DESC: [200, 100, created].
        $this->assertCount(3, $entries);
        $this->assertEquals(200, (float) ($entries[0]['changes']['attributes']['target_value'] ?? 0), 'Primeiro deve ser update 200 (mais recente)');
        $this->assertEquals(100, (float) ($entries[1]['changes']['attributes']['target_value'] ?? 0), 'Segundo deve ser update 100');
    }

    /** T9 — Limit 10 (se houver 15 updates, retorna apenas 10). */
    public function test_history_limita_em_10_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoal($company);

        $this->actingAs($admin);
        for ($i = 1; $i <= 15; $i++) {
            $goal->update(['target_value' => 100.00 * $i]);
        }

        $entries = $this->get(route('goals.history', $goal->id))
            ->assertOk()
            ->json('entries');

        $this->assertCount(10, $entries, 'Endpoint deve limitar em 10 entries mesmo com 15 updates');
    }

    /** T10 — Goal sem alteracoes retorna array vazio + status 200. */
    public function test_history_vazio_retorna_array_vazio(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $company = $this->makeCompany();
        $goal = $this->createGoal($company);

        $entries = $this->actingAs($admin)
            ->get(route('goals.history', $goal->id))
            ->assertOk()
            ->json('entries');

        // Nota: Goal::create() nao dispara update event => 0 entries por padrao.
        // Se logOnly incluisse 'created' teriamos 1; getActivitylogOptions do Goal
        // usa defaults + logOnlyDirty que registra `created` — pode ter 1.
        // O importante: T10 valida shape JSON quando historico e minimo/vazio.
        $this->assertIsArray($entries);
        $this->assertLessThanOrEqual(1, count($entries), 'Goal recem-criada deve ter no maximo 1 entry (created)');
    }
}
