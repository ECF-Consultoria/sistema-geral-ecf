<?php

namespace Tests\Feature\Phase62;

use App\Models\Company;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 62 Plan 62-01 (Wave 1, META-04) — Filtro `subject_id` no
 * ActivityLogController::index. Suporta o link "Ver log completo" do drawer
 * (Plan 62-03), que aponta pra `/activity-log?subject_type=App\Models\Goal&subject_id=X`.
 */
class ActivityLogSubjectFilterTest extends TestCase
{
    use RefreshDatabase;

    /** T13 — Passar subject_id filtra APENAS as entries daquele subject. */
    public function test_activity_log_filtra_por_subject_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::create([
            'name'   => 'Empresa Filtro Activity',
            'cnpj'   => '11223344000155',
            'active' => true,
        ]);

        $goalX = Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'tacos', // presente no CHECK constraint SQLite
            'target_value' => 10.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'active'       => true,
        ]);
        $goalY = Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'nps',
            'target_value' => 50.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'active'       => true,
        ]);

        $this->actingAs($admin);
        // Gera activity_log em ambas.
        $goalX->update(['target_value' => 111.00]);
        $goalY->update(['target_value' => 55.00]);

        $response = $this->get(route('activity-log.index', [
            'subject_type' => 'App\\Models\\Goal',
            'subject_id'   => $goalX->id,
        ]));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ActivityLog/Index')
                ->where('logs.data', function ($logs) use ($goalX, $goalY) {
                    // Todas as entries devem ser da goalX. GoalY nao pode vazar.
                    $logsCollection = collect($logs);
                    $this->assertGreaterThan(0, $logsCollection->count(), 'Deve retornar pelo menos 1 entry da goalX');

                    foreach ($logsCollection as $log) {
                        $this->assertEquals($goalX->id, $log['subject_id']);
                        $this->assertNotEquals($goalY->id, $log['subject_id']);
                    }
                    return true;
                })
            );
    }
}
