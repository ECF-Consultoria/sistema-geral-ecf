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

    // ─── Helpers da Task 3 (D-02) ───────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Payload mínimo aceito por users.update, no formato de CargoDevNoUsuarioTest::payload(). */
    private function payload(User $u, array $vinculos, array $extra = []): array
    {
        return array_merge([
            'name'     => $u->name,
            'email'    => $u->email,
            'is_admin' => false,
            'is_dev'   => (bool) $u->is_dev,
            'active'   => true,
            'vinculos' => $vinculos,
        ], $extra);
    }

    // ─── Teste 7 ────────────────────────────────────────────────────────────

    public function test_put_com_dois_vinculos_no_mesmo_setor_grava_as_duas_linhas(): void
    {
        $alvo = User::factory()->create();
        $ator = $this->admin();

        $vinculos = [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoEstrategistaId, 'is_principal' => false],
        ];

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, $vinculos))
            ->assertRedirect();

        $linhas = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $this->setorPerformanceId)
            ->get();

        $this->assertCount(2, $linhas);

        $analista     = $linhas->firstWhere('cargo_id', $this->cargoAnalistaId);
        $estrategista = $linhas->firstWhere('cargo_id', $this->cargoEstrategistaId);
        $this->assertNotNull($analista);
        $this->assertNotNull($estrategista);
        $this->assertSame(1, (int) $analista->is_principal);
        $this->assertSame(0, (int) $estrategista->is_principal);
    }

    // ─── Teste 8 ────────────────────────────────────────────────────────────

    public function test_segundo_put_so_com_analista_remove_estrategista_e_preserva_id_e_assigned_at(): void
    {
        $alvo = User::factory()->create();
        $ator = $this->admin();

        $vinculosIniciais = [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoEstrategistaId, 'is_principal' => false],
        ];
        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, $vinculosIniciais));

        $analistaAntes = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('cargo_id', $this->cargoAnalistaId)
            ->first();
        $this->assertNotNull($analistaAntes);

        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
        ]));

        $linhas = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $this->setorPerformanceId)
            ->get();

        $this->assertCount(1, $linhas, 'A linha de estrategista deveria ter sido removida.');

        $analistaDepois = $linhas->first();
        $this->assertSame($analistaAntes->id, $analistaDepois->id, 'O id da linha de analista precisa ser preservado.');
        $this->assertSame($analistaAntes->assigned_at, $analistaDepois->assigned_at, 'O assigned_at original precisa ser preservado.');
    }

    // ─── Teste 9 ────────────────────────────────────────────────────────────

    public function test_par_repetido_no_payload_gera_erro_de_validacao_e_nao_altera_nada(): void
    {
        $alvo = User::factory()->create();
        $ator = $this->admin();

        $antes = DB::table('user_setores')->where('user_id', $alvo->id)->count();

        $vinculos = [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => false],
        ];

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, $vinculos))
            ->assertSessionHasErrors(['vinculos.1.cargo_id' => 'Este cargo já está em outro vínculo do mesmo setor.']);

        $depois = DB::table('user_setores')->where('user_id', $alvo->id)->count();
        $this->assertSame($antes, $depois, 'Nenhuma linha pode ter sido alterada quando a validação recusa o payload.');
    }

    // ─── Teste 10 ───────────────────────────────────────────────────────────

    public function test_setor_repetido_com_vinculo_sem_cargo_gera_erro_de_validacao(): void
    {
        $alvo = User::factory()->create();
        $ator = $this->admin();

        $antes = DB::table('user_setores')->where('user_id', $alvo->id)->count();

        $vinculos = [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => null, 'is_principal' => true],
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoEstrategistaId, 'is_principal' => false],
        ];

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, $vinculos))
            ->assertSessionHasErrors([
                'vinculos.0.cargo_id' => 'Quando o mesmo setor aparece mais de uma vez, cada vínculo precisa de um cargo.',
            ]);

        $depois = DB::table('user_setores')->where('user_id', $alvo->id)->count();
        $this->assertSame($antes, $depois, 'Nenhuma linha pode ter sido alterada quando a validação recusa o payload.');
    }

    // ─── Teste 11 ───────────────────────────────────────────────────────────

    public function test_vinculo_dev_sobrevive_ao_put_com_dois_cargos_do_performance(): void
    {
        $alvo = User::factory()->create();
        $ator = $this->admin();

        // Concede o cargo Dev primeiro (payload sem vinculos — igual a CargoDevNoUsuarioTest).
        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, [], ['is_dev' => true]));

        $vinculos = [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoEstrategistaId, 'is_principal' => false],
        ];

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, $vinculos, ['is_dev' => true]))
            ->assertRedirect();

        $total = DB::table('user_setores')->where('user_id', $alvo->id)->count();
        $this->assertSame(3, $total, 'Performance (2 linhas) + Dev (1 linha) = 3.');

        $setorDevId = Setor::where('slug', User::SETOR_DEV_SLUG)->value('id');
        $vinculoDev = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $setorDevId)
            ->first();
        $this->assertNotNull($vinculoDev, 'O vínculo do cargo Dev não pode ser derrubado por um save comum do Performance.');
    }

    // ─── Teste 11b (CR-01 da revisão) ──────────────────────────────────────

    /**
     * Reproduz o payload REAL da tela: o `openEdit()` antigo copiava
     * `u.setores` inteiro (inclusive a linha do setor Desenvolvimento) para
     * `vinculos`, e o `submit()` reenviava tudo. O `syncVinculos()` exclui o
     * setor Dev das linhas atuais, então a linha Dev do payload caía no
     * INSERT e estourava o unique de 3 colunas — 500 para todo Dev não-admin.
     */
    public function test_put_de_dev_nao_admin_reenviando_a_linha_dev_como_a_tela_faz_nao_quebra(): void
    {
        $alvo = User::factory()->create(['role' => 'consultor']);
        $ator = $this->admin();

        // Estado de partida: analista no Performance + cargo Dev.
        $this->actingAs($ator)->put("/users/{$alvo->id}", $this->payload($alvo, [
            ['setor_id' => $this->setorPerformanceId, 'cargo_id' => $this->cargoAnalistaId, 'is_principal' => true],
        ], ['is_dev' => true]))->assertRedirect();

        $setorDevId = (int) Setor::where('slug', User::SETOR_DEV_SLUG)->value('id');
        $this->assertGreaterThan(0, $setorDevId, 'O setor Desenvolvimento precisa estar semeado.');

        $linhaDevAntes = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $setorDevId)
            ->first();
        $this->assertNotNull($linhaDevAntes, 'Pré-condição: a linha Dev precisa existir.');

        // Monta `vinculos` exatamente como a tela montava: a partir de
        // `u.setores` da listagem, SEM filtrar o setor Dev.
        $u = collect($this->actingAs($ator)->get('/users')->viewData('page')['props']['users'])
            ->firstWhere('id', $alvo->id);
        $vinculosDaTela = collect($u['setores'])->map(fn ($s) => [
            'setor_id'     => $s['id'],
            'cargo_id'     => $s['cargo_id'],
            'is_principal' => (bool) $s['is_principal'],
        ])->values()->all();
        $this->assertTrue(
            collect($vinculosDaTela)->contains(fn ($v) => (int) $v['setor_id'] === $setorDevId),
            'Pré-condição: a listagem expõe a linha Dev em u.setores.'
        );

        // A pessoa ganha o segundo cargo de Desempenho no mesmo save.
        $vinculosDaTela[] = [
            'setor_id'     => $this->setorPerformanceId,
            'cargo_id'     => $this->cargoEstrategistaId,
            'is_principal' => false,
        ];

        $this->actingAs($ator)
            ->put("/users/{$alvo->id}", $this->payload($alvo, $vinculosDaTela, ['is_dev' => true, 'name' => 'Nome Novo CR01']))
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        // Linha Dev intacta: uma só, a MESMA de antes.
        $linhasDev = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $setorDevId)
            ->get();
        $this->assertCount(1, $linhasDev, 'A linha Dev não pode duplicar nem sumir.');
        $this->assertSame($linhaDevAntes->id, $linhasDev->first()->id);
        $this->assertSame(0, (int) $linhasDev->first()->is_principal, 'A linha Dev nunca é a principal.');

        // Demais vínculos gravados.
        $performance = DB::table('user_setores')
            ->where('user_id', $alvo->id)
            ->where('setor_id', $this->setorPerformanceId)
            ->pluck('cargo_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
        $this->assertEqualsCanonicalizing([$this->cargoAnalistaId, $this->cargoEstrategistaId], $performance);

        $this->assertSame('Nome Novo CR01', $alvo->fresh()->name);
        $this->assertTrue((bool) $alvo->fresh()->is_dev);
    }

    // ─── Teste 12 ───────────────────────────────────────────────────────────

    public function test_index_expoe_duas_entradas_no_mesmo_setor_com_cargos_diferentes(): void
    {
        $alvo = User::factory()->create(['name' => 'Fulano Dois Cargos']);
        $this->inserirVinculo($alvo->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->inserirVinculo($alvo->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        $resposta = $this->actingAs($this->admin())->get('/users');
        $resposta->assertOk();

        $users = collect($resposta->viewData('page')['props']['users']);
        $u = $users->firstWhere('id', $alvo->id);

        $this->assertNotNull($u, 'Usuário alvo precisa aparecer na listagem.');

        $setoresDoUsuario = collect($u['setores'])->where('id', $this->setorPerformanceId);
        $this->assertCount(2, $setoresDoUsuario, 'Duas entradas com o mesmo id de setor, uma por cargo.');

        $cargos = $setoresDoUsuario->pluck('cargo_id')->sort()->values()->all();
        $this->assertEqualsCanonicalizing([$this->cargoAnalistaId, $this->cargoEstrategistaId], $cargos);
    }
}
