<?php

namespace Tests\Feature\Phase159;

use App\Models\Cargo;
use App\Models\Setor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 159 Plano 159-01 — prova automatizada de SC1 (D-01 + D-02).
 *
 * Testes 1-6 (Task 2): migration `2026_09_30_100000_amplia_unique_user_setores_por_cargo`
 * troca o unique de `user_setores` de (user_id, setor_id) para (user_id, setor_id,
 * cargo_id), aceitando dois cargos no mesmo setor para a mesma pessoa (D-01).
 *
 * Testes 7-12 (Task 3): `UserController` grava e edita esses dois cargos pela
 * tela /users (D-02).
 *
 * Fixture de setor/cargos espelha `tests/Feature/PerformanceCargoFilterTest::setUp`
 * (setor "Performance" reusado se já existir — `setores.nome` é UNIQUE).
 */
class UserSetoresDoisCargosTest extends TestCase
{
    use RefreshDatabase;

    private int $setorPerformanceId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorPerformanceId = (int) (DB::table('setores')->where('slug', 'performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorPerformanceId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorPerformanceId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /** Nomes dos índices de `user_setores` no SQLite dos testes. */
    private function indexNames(): array
    {
        return collect(DB::select("PRAGMA index_list('user_setores')"))->pluck('name')->all();
    }

    private function inserirVinculo(int $userId, int $setorId, ?int $cargoId, bool $principal = false): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $userId,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => $principal,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** Instância nova da migration (anônima) — `require` (não `require_once`) para poder chamar up()/down() à vontade. */
    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_30_100000_amplia_unique_user_setores_por_cargo.php');
    }

    // ─── Teste 1 ────────────────────────────────────────────────────────────

    public function test_migration_troca_o_unique_de_2_para_3_colunas(): void
    {
        $idx = $this->indexNames();

        $this->assertContains('user_setores_user_id_setor_id_cargo_id_unique', $idx);
        $this->assertNotContains('user_setores_user_id_setor_id_unique', $idx);
    }

    // ─── Teste 2 ────────────────────────────────────────────────────────────

    public function test_aceita_dois_cargos_no_mesmo_setor(): void
    {
        $user = User::factory()->create();

        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        $this->assertSame(2, DB::table('user_setores')->where('user_id', $user->id)->count());
    }

    // ─── Teste 3 ────────────────────────────────────────────────────────────

    public function test_recusa_cargo_repetido_no_mesmo_setor(): void
    {
        $user = User::factory()->create();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $this->expectException(QueryException::class);

        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, false);
    }

    // ─── Teste 4 ────────────────────────────────────────────────────────────

    public function test_reexecutar_up_da_migration_e_idempotente(): void
    {
        // Schema já migrado (RefreshDatabase rodou todas as migrations). Reaplicar
        // up() não pode lançar nem duplicar o índice.
        $this->migration()->up();

        $idx = $this->indexNames();
        $ocorrencias = collect($idx)->filter(fn ($n) => $n === 'user_setores_user_id_setor_id_cargo_id_unique')->count();

        $this->assertSame(1, $ocorrencias, 'O índice novo não pode duplicar ao reexecutar up().');
        $this->assertContains('user_setores_user_id_setor_id_cargo_id_unique', $idx);
    }

    // ─── Teste 5 ────────────────────────────────────────────────────────────

    public function test_down_recusa_quando_existe_pessoa_com_dois_cargos_no_mesmo_setor(): void
    {
        $user = User::factory()->create();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        try {
            $this->migration()->down();
            $this->fail('down() deveria lançar RuntimeException quando há pessoa com dois cargos no mesmo setor.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dois cargos no mesmo setor', $e->getMessage());
        }

        // Schema fica intacto — índice novo continua presente.
        $this->assertContains('user_setores_user_id_setor_id_cargo_id_unique', $this->indexNames());
    }

    // ─── Teste 6 ────────────────────────────────────────────────────────────

    public function test_down_e_up_sem_duplicidade_restaura_e_reaplica(): void
    {
        $user = User::factory()->create();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $migration = $this->migration();

        $migration->down();
        $idx = $this->indexNames();
        $this->assertContains('user_setores_user_id_setor_id_unique', $idx);
        $this->assertNotContains('user_setores_user_id_setor_id_cargo_id_unique', $idx);

        $migration->up();
        $idx = $this->indexNames();
        $this->assertContains('user_setores_user_id_setor_id_cargo_id_unique', $idx);
        $this->assertNotContains('user_setores_user_id_setor_id_unique', $idx);
    }
}
