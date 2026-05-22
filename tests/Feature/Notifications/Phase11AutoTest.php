<?php

namespace Tests\Feature\Notifications;

use App\Jobs\CalculateGoalResults;
use App\Jobs\CalculatePortfolioGoalResults;
use App\Jobs\CalculateSetorGoalResults;
use App\Models\Company;
use App\Models\Goal;
use App\Models\GoalResult;
use App\Models\PortfolioGoal;
use App\Models\PortfolioGoalResult;
use App\Models\Setor;
use App\Models\SetorGoal;
use App\Models\SetorGoalResult;
use App\Models\User;
use App\Notifications\MetaAtingidaNotification;
use App\Notifications\MetaAtribuidaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Tests\TestCase;

/**
 * Suíte canônica da Phase 11 — Disparos Automáticos de Metas.
 *
 * Cobre 6 testes (1 por requirement AUTO-01..AUTO-06):
 *   - Tests 1/2/3: hooks `static::created` em SetorGoal/Goal/PortfolioGoal disparam
 *     `MetaAtribuidaNotification` para o público certo.
 *   - Tests 4/5/6: helpers `dispatchAtingidaIfNeeded` em cada job disparam
 *     `MetaAtingidaNotification` com idempotência via `notificado_em`.
 *
 * Convenção da Phase 9/10 mantida: NUNCA usar `Notification::fake()` — esta
 * suíte valida persistência real no canal `database`. Mockear quebraria a
 * tabela `notifications` que estamos observando.
 *
 * **Decisão arquitetural (Tarefa 4 do plano):** os tests 4/5/6 invocam o método
 * `protected dispatchAtingidaIfNeeded` dos jobs via Reflection, em vez de tentar
 * rodar `handle()` com fixtures de `AdmanMetric` / `Publicacao`. Isso isola o
 * comportamento de notificação (objeto do teste) da lógica de cálculo (já
 * coberta por outras suítes), mantendo o teste estável e focado.
 */
class Phase11AutoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper — invoca método `protected` de um job via Reflection.
     *
     * Usa `setAccessible` para contornar a visibilidade. Idiomático em testes
     * que precisam observar comportamento extraído para auxiliar protegido sem
     * relaxar a visibilidade de produção.
     *
     * @param  array<int,mixed>  $args
     */
    private function invocarHelperProtegido(object $job, string $metodo, array $args): mixed
    {
        $ref = new \ReflectionClass($job);
        $m   = $ref->getMethod($metodo);
        $m->setAccessible(true);
        return $m->invokeArgs($job, $args);
    }

    /**
     * Test 1 — AUTO-01: criar `SetorGoal` notifica todos os membros do setor.
     *
     * Setup: setor com 3 membros + 1 user fora do setor. Após `SetorGoal::create`,
     * espera-se 3 notifications (uma por membro), todas do tipo
     * `MetaAtribuidaNotification`, e nenhuma para o user de fora.
     */
    public function test_auto_01_setor_goal_created_notifica_membros(): void
    {
        $setor = Setor::create(['nome' => 'Publicação Teste', 'active' => true]);

        // 3 membros atrelados ao setor + 1 user fora.
        $membros = User::factory()->count(3)->create(['role' => 'consultor']);
        foreach ($membros as $m) {
            $setor->membros()->attach($m->id, [
                'is_principal' => true,
                'assigned_at'  => now(),
            ]);
        }
        $foraDoSetor = User::factory()->create(['role' => 'consultor']);

        SetorGoal::create([
            'setor_id'     => $setor->id,
            'metric'       => 'publicacoes_mes',
            'target_value' => 5000,
            'value_type'   => 'absolute',
            'period_type'  => 'monthly',
            'description'  => 'Meta de publicações do setor',
            'active'       => true,
        ]);

        // 3 notifications criadas, uma por membro.
        $this->assertDatabaseCount('notifications', 3);

        $tipoEsperado = MetaAtribuidaNotification::class;
        foreach ($membros as $m) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $m->id,
                'notifiable_type' => User::class,
                'type'            => $tipoEsperado,
            ]);
        }

        // O user de fora não foi notificado.
        $this->assertSame(
            0,
            DatabaseNotification::where('notifiable_id', $foraDoSetor->id)->count()
        );
    }

    /**
     * Test 2 — AUTO-02: criar `Goal` notifica apenas consultor + mentor da empresa.
     *
     * Setup: 1 empresa com 1 consultor + 1 mentor + 1 user random sem vínculo.
     * Espera 2 notifications. O user random NÃO recebe. Admins NÃO recebem
     * (D-06 do 11-01-PLAN.md — admins ficam para AUTO-05/atingimento).
     */
    public function test_auto_02_goal_created_notifica_consultor_e_mentor(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $mentor    = User::factory()->create(['role' => 'mentor']);
        $random    = User::factory()->create(['role' => 'consultor']);
        // Admin existente — não deve receber (AUTO-02 não inclui admins).
        $admin = User::factory()->create(['role' => 'admin']);

        $company = Company::create([
            'name'   => 'Empresa Teste',
            'cnpj'   => '12345678000199',
            'active' => true,
        ]);
        $company->users()->attach($consultor->id, ['role' => 'consultor', 'assigned_at' => now()]);
        $company->users()->attach($mentor->id,    ['role' => 'estrategista', 'assigned_at' => now()]);

        Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'tacos',
            'target_value' => 20.0,
            'period_type'  => 'monthly',
            'active'       => true,
            'description'  => 'Reduzir TACOS abaixo de 20%',
        ]);

        // 2 notifications (consultor + mentor). Nenhuma para admin/random.
        $this->assertDatabaseCount('notifications', 2);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $consultor->id,
            'notifiable_type' => User::class,
            'type'            => MetaAtribuidaNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $mentor->id,
            'notifiable_type' => User::class,
            'type'            => MetaAtribuidaNotification::class,
        ]);

        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $random->id)->count());
        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $admin->id)->count());
    }

    /**
     * Test 3 — AUTO-03: criar `PortfolioGoal` notifica apenas o dono da carteira.
     *
     * Setup: user X dono + 1 user random (não dono). Após
     * `PortfolioGoal::create(['user_id' => X->id, ...])`, espera 1 notification,
     * dono é o destinatário.
     */
    public function test_auto_03_portfolio_goal_created_notifica_dono(): void
    {
        $dono   = User::factory()->create(['role' => 'consultor']);
        $random = User::factory()->create(['role' => 'consultor']);

        PortfolioGoal::create([
            'user_id'      => $dono->id,
            'role'         => 'consultor',
            'metric'       => 'tacos',
            'target_value' => 18.0,
            'value_type'   => 'percentage',
            'aggregation'  => 'avg',
            'period_type'  => 'monthly',
            'active'       => true,
            'description'  => 'TACOS médio da carteira <= 18%',
        ]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $dono->id,
            'notifiable_type' => User::class,
            'type'            => MetaAtribuidaNotification::class,
        ]);

        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $random->id)->count());
    }

    /**
     * Test 4 — AUTO-04: meta de setor atingida (pct>=100) notifica admins +
     * líderes do setor uma única vez (idempotência via `notificado_em`).
     *
     * Setup: 2 admins + 2 líderes + 1 membro do setor. Cria SetorGoal +
     * SetorGoalResult com `pct_achieved=120`. Invoca `dispatchAtingidaIfNeeded`
     * via Reflection. Espera 4 notifications (2 admins + 2 líderes); membros
     * NÃO recebem (eles recebem em AUTO-01).
     *
     * Reexecuta o helper — `notificado_em` agora está preenchido, então o
     * dispatch é skip e a contagem permanece em 4.
     */
    public function test_auto_04_setor_goal_atingida_notifica_admins_e_lideres_uma_vez(): void
    {
        $setor = Setor::create(['nome' => 'Setor Atingimento', 'active' => true]);

        $admin1 = User::factory()->create(['role' => 'admin']);
        $admin2 = User::factory()->create(['role' => 'admin']);
        $lider1 = User::factory()->create(['role' => 'consultor']);
        $lider2 = User::factory()->create(['role' => 'consultor']);
        $membro = User::factory()->create(['role' => 'consultor']);

        foreach ([$lider1, $lider2] as $l) {
            $setor->lideres()->attach($l->id, [
                'assigned_by' => null,
                'assigned_at' => now(),
            ]);
        }
        $setor->membros()->attach($membro->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        // SetorGoal::create dispara hook AUTO-01 → 1 notification ao membro.
        $meta = SetorGoal::create([
            'setor_id'     => $setor->id,
            'metric'       => 'publicacoes_mes',
            'target_value' => 100,
            'value_type'   => 'absolute',
            'period_type'  => 'monthly',
            'description'  => 'Bater 100 publicações',
            'active'       => true,
        ]);

        // Pré-condição: 1 notification (a do AUTO-01 ao membro).
        $this->assertDatabaseCount('notifications', 1);

        $result = SetorGoalResult::create([
            'setor_goal_id'  => $meta->id,
            'period_start'   => now()->startOfMonth()->toDateString(),
            'period_end'     => now()->endOfMonth()->toDateString(),
            'value_realized' => 120,
            'pct_achieved'   => 120,
            'calculated_at'  => now(),
        ]);

        $job = new CalculateSetorGoalResults();
        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$meta, $result]);

        // 1 (AUTO-01) + 4 (AUTO-04: 2 admins + 2 líderes) = 5 totais.
        $this->assertDatabaseCount('notifications', 5);

        foreach ([$admin1, $admin2, $lider1, $lider2] as $u) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $u->id,
                'notifiable_type' => User::class,
                'type'            => MetaAtingidaNotification::class,
            ]);
        }

        // O membro NÃO recebe MetaAtingida (só recebeu MetaAtribuida em AUTO-01).
        $this->assertSame(
            0,
            DatabaseNotification::where('notifiable_id', $membro->id)
                ->where('type', MetaAtingidaNotification::class)
                ->count()
        );

        // Idempotência: re-invocar não cria duplicatas.
        $result->refresh();
        $this->assertNotNull($result->notificado_em);

        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$meta, $result]);
        $this->assertDatabaseCount('notifications', 5);
    }

    /**
     * Test 5 — AUTO-05: meta de empresa atingida (achieved=true) notifica
     * consultor + mentor + admins, uma única vez.
     *
     * Setup: 1 empresa + 1 consultor + 1 mentor + 2 admins. Cria Goal
     * (hook AUTO-02 dispara 2 notifications para consultor+mentor), depois
     * cria GoalResult manualmente com `achieved=true, notificado_em=null` e
     * invoca o helper. Espera +4 notifications (consultor + mentor + 2 admins).
     *
     * Idempotência: reexecutar não dispara novas (notificado_em populado).
     */
    public function test_auto_05_goal_atingida_notifica_consultor_mentor_e_admins(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);
        $mentor    = User::factory()->create(['role' => 'mentor']);
        $admin1    = User::factory()->create(['role' => 'admin']);
        $admin2    = User::factory()->create(['role' => 'admin']);

        $company = Company::create([
            'name'   => 'Empresa AUTO-05',
            'cnpj'   => '99887766000155',
            'active' => true,
        ]);
        $company->users()->attach($consultor->id, ['role' => 'consultor', 'assigned_at' => now()]);
        $company->users()->attach($mentor->id,    ['role' => 'estrategista', 'assigned_at' => now()]);

        // Hook AUTO-02 → 2 notifications (consultor + mentor).
        $goal = Goal::create([
            'company_id'   => $company->id,
            'metric'       => 'tacos',
            'target_value' => 20.0,
            'period_type'  => 'monthly',
            'active'       => true,
            'description'  => 'TACOS <= 20%',
        ]);

        $this->assertDatabaseCount('notifications', 2);

        $result = GoalResult::create([
            'goal_id'        => $goal->id,
            'period'         => now()->format('Y-m'),
            'realized_value' => 15.0,
            'target_value'   => 20.0,
            'achieved'       => true,
            'calculated_at'  => now(),
        ]);

        $job = new CalculateGoalResults(now()->format('Y-m'));
        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$goal, $result]);

        // 2 (AUTO-02) + 4 (AUTO-05: consultor + mentor + 2 admins) = 6 totais.
        $this->assertDatabaseCount('notifications', 6);

        foreach ([$consultor, $mentor, $admin1, $admin2] as $u) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $u->id,
                'notifiable_type' => User::class,
                'type'            => MetaAtingidaNotification::class,
            ]);
        }

        // Idempotência: notificado_em populado, re-invocar não cria duplicatas.
        $result->refresh();
        $this->assertNotNull($result->notificado_em);

        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$goal, $result]);
        $this->assertDatabaseCount('notifications', 6);
    }

    /**
     * Test 6 — AUTO-06: meta de carteira atingida (achieved=true) notifica
     * dono + admins, uma única vez.
     *
     * Setup: 1 user dono + 2 admins. Cria PortfolioGoal (hook AUTO-03 dispara
     * 1 notification ao dono), depois cria PortfolioGoalResult com
     * `achieved=true, notificado_em=null` e invoca o helper. Espera +3
     * notifications (dono + 2 admins).
     *
     * Idempotência: reexecutar não dispara novas.
     */
    public function test_auto_06_portfolio_goal_atingida_notifica_dono_e_admins(): void
    {
        $dono   = User::factory()->create(['role' => 'consultor']);
        $admin1 = User::factory()->create(['role' => 'admin']);
        $admin2 = User::factory()->create(['role' => 'admin']);

        // Hook AUTO-03 → 1 notification ao dono.
        $goal = PortfolioGoal::create([
            'user_id'      => $dono->id,
            'role'         => 'consultor',
            'metric'       => 'tacos',
            'target_value' => 18.0,
            'value_type'   => 'percentage',
            'aggregation'  => 'avg',
            'period_type'  => 'monthly',
            'active'       => true,
            'description'  => 'TACOS carteira <= 18%',
        ]);

        $this->assertDatabaseCount('notifications', 1);

        $result = PortfolioGoalResult::create([
            'portfolio_goal_id' => $goal->id,
            'period'            => now()->format('Y-m'),
            'companies_count'   => 5,
            'realized_value'    => 15.0,
            'target_value'      => 18.0,
            'achieved'          => true,
            'calculated_at'     => now(),
        ]);

        $job = new CalculatePortfolioGoalResults(now()->format('Y-m'));
        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$goal, $result]);

        // 1 (AUTO-03) + 3 (AUTO-06: dono + 2 admins) = 4 totais.
        $this->assertDatabaseCount('notifications', 4);

        foreach ([$dono, $admin1, $admin2] as $u) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $u->id,
                'notifiable_type' => User::class,
                'type'            => MetaAtingidaNotification::class,
            ]);
        }

        // Idempotência: re-invocar não cria duplicatas.
        $result->refresh();
        $this->assertNotNull($result->notificado_em);

        $this->invocarHelperProtegido($job, 'dispatchAtingidaIfNeeded', [$goal, $result]);
        $this->assertDatabaseCount('notifications', 4);
    }
}
