<?php

namespace Tests\Feature\Phase62;

use App\Models\Company;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 62 Plan 62-05 (Wave 3, META-01) — Payload de goals + results em CompanyController::show.
 *
 * Valida shape do bloco `company.goals[N]` retornado pelo Inertia:
 *  - inclui novas chaves value_type/period_type/results
 *  - results limitados a 12 mais recentes, ordenados ASC por period
 *  - preserva filtro `active=true` (regressão zero)
 *  - types corretos (float pra numeros, bool pra achieved)
 *
 * Alimenta <GoalProgressPanel /> na Section "Metas Ativas" (Task 2).
 */
class CompanyShowGoalsPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function createCompanyForAdmin(): Company
    {
        return Company::create([
            'name'   => 'Empresa META-01 Show',
            'cnpj'   => '11223344000199',
            'active' => true,
        ]);
    }

    /**
     * Helper — cria uma Goal ativa numa empresa. Campos default coerentes com
     * o CHECK constraint do SQLite (metric ∈ conjunto legado).
     */
    private function createGoal(Company $company, array $overrides = []): Goal
    {
        return Goal::create(array_merge([
            'company_id'   => $company->id,
            'metric'       => 'tacos',
            'target_value' => 10.00,
            'value_type'   => 'percentage',
            'period_type'  => 'monthly',
            'description'  => 'Meta base META-01',
            'active'       => true,
        ], $overrides));
    }

    /**
     * Helper — cria um GoalResult inline (não há factory dedicada em v14.0).
     */
    private function createResult(Goal $goal, string $period, float $realized, float $target, bool $achieved): GoalResult
    {
        return GoalResult::create([
            'goal_id'        => $goal->id,
            'period'         => $period,
            'realized_value' => $realized,
            'target_value'   => $target,
            'achieved'       => $achieved,
            'calculated_at'  => now(),
        ]);
    }

    /** T1 — shape base: payload retorna goals[N] com todas as chaves esperadas. */
    public function test_payload_goals_expoe_shape_completo_com_results(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();

        // Uso `nps` como segunda metric pra permanecer compativel com o CHECK
        // constraint do SQLite (in-memory), onde `acos` nao esta no ENUM legado.
        $goal1 = $this->createGoal($company, ['metric' => 'tacos', 'target_value' => 10.00]);
        $this->createGoal($company, ['metric' => 'nps', 'target_value' => 8.5]);

        $this->createResult($goal1, '2026-05', 12.5, 10.00, false);
        $this->createResult($goal1, '2026-06', 9.8,  10.00, true);

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Companies/Show')
                ->has('company.goals', 2)
                ->has('company.goals.0', fn (Assert $goal) => $goal
                    ->hasAll(['id', 'metric', 'metric_label', 'target_value', 'value_type', 'period_type', 'active', 'results'])
                    ->etc()
                )
                ->etc()
            );
    }

    /** T2 — results ordenados ASC por period. */
    public function test_results_ordenados_ascendente_por_period(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();
        $goal = $this->createGoal($company);

        // Inserção fora de ordem cronológica; mapper deve normalizar.
        $this->createResult($goal, '2026-07', 5.5, 10.00, true);
        $this->createResult($goal, '2026-05', 7.0, 10.00, true);
        $this->createResult($goal, '2026-06', 6.0, 10.00, true);

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company.goals.0.results', 3)
                ->where('company.goals.0.results.0.period', '2026-05')
                ->where('company.goals.0.results.1.period', '2026-06')
                ->where('company.goals.0.results.2.period', '2026-07')
                ->etc()
            );
    }

    /** T3 — limit 12: goal com 15 results retorna apenas 12 (os mais recentes). */
    public function test_results_limitado_em_12_periodos_mais_recentes(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();
        $goal = $this->createGoal($company);

        // 15 periods sequenciais: 2025-01 .. 2026-03.
        // Eager load pega os 12 MAIS RECENTES (desc + limit 12), depois mapper
        // ordena ASC pra exibição. Resultado: 2025-04 .. 2026-03 (12 items).
        for ($i = 0; $i < 15; $i++) {
            $month = ($i % 12) + 1;
            $year  = 2025 + intdiv($i, 12);
            $period = sprintf('%04d-%02d', $year, $month);
            $this->createResult($goal, $period, 5.0 + $i, 10.00, false);
        }

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company.goals.0.results', 12)
                // Primeiro dos 12 mais recentes ordenados ASC deve ser 2025-04
                // (2025-01/02/03 ficaram fora por serem os 3 mais antigos).
                ->where('company.goals.0.results.0.period', '2025-04')
                ->where('company.goals.0.results.11.period', '2026-03')
                ->etc()
            );
    }

    /** T4 — goal sem nenhum GoalResult expõe results = [] (não null). */
    public function test_goal_sem_results_retorna_array_vazio(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();
        $this->createGoal($company);

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company.goals.0.results', 0)
                ->etc()
            );
    }

    /** T5 — apenas goals `active=true` retornam no payload (regressao preservada). */
    public function test_apenas_goals_ativas_retornam_no_payload(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();

        $this->createGoal($company, ['active' => true,  'metric' => 'tacos']);
        $this->createGoal($company, ['active' => false, 'metric' => 'acos']); // não deve aparecer

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company.goals', 1)
                ->where('company.goals.0.metric', 'tacos')
                ->etc()
            );
    }

    /** T6 — types corretos: numeric fields são float, achieved é bool. */
    public function test_result_expoe_numeros_como_float_e_bool_como_bool(): void
    {
        $admin = $this->createAdmin();
        $company = $this->createCompanyForAdmin();
        $goal = $this->createGoal($company);
        $this->createResult($goal, '2026-06', 12.34, 10.00, true);

        $this->actingAs($admin)
            ->get(route('companies.show', $company->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('company.goals.0.results.0', fn (Assert $r) => $r
                    ->hasAll(['id', 'period', 'realized_value', 'target_value', 'achieved'])
                    ->where('realized_value', 12.34)
                    ->where('target_value', 10.00)
                    ->where('achieved', true)
                    ->etc()
                )
                ->etc()
            );
    }
}
