<?php

namespace Tests\Feature\Phase159;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fase 159 Plano 159-02 — prova automatizada de D-10 (SC1).
 *
 * Testes 1-10 (Task 1): `Admin/SetorMembroController` (segunda tela que grava
 * `user_setores`) passa a adicionar cargo a quem já é membro e a remover por
 * cargo, sem desfazer o que a tela /users grava.
 *
 * Testes 11-13 (Task 2): consumidores de `Setor::membros()` (SetorGoal,
 * NotificacaoController, LiderancaController) deixam de contar/notificar a
 * pessoa com dois cargos no mesmo setor duas vezes.
 *
 * Fixture de setor/cargos espelha `UserSetoresDoisCargosTest::setUp`.
 */
class SetorMembroDoisCargosTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function inserirVinculo(int $userId, int $setorId, ?int $cargoId, bool $principal = false): int
    {
        return DB::table('user_setores')->insertGetId([
            'user_id'      => $userId,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => $principal,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function linhasDoPar(int $userId, int $setorId)
    {
        return DB::table('user_setores')->where('user_id', $userId)->where('setor_id', $setorId)->get();
    }

    // ─── Teste 1 ────────────────────────────────────────────────────────────

    public function test_adicionar_cargo_diferente_a_quem_ja_e_membro_cria_segunda_linha(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $response = $this->actingAs($ator)->post(route('admin.setores.membros.store', $this->setorPerformanceId), [
            'user_id'  => $user->id,
            'cargo_id' => $this->cargoEstrategistaId,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertCount(2, $this->linhasDoPar($user->id, $this->setorPerformanceId));
    }

    // ─── Teste 2 ────────────────────────────────────────────────────────────

    public function test_adicionar_o_mesmo_cargo_de_novo_e_recusado(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $response = $this->actingAs($ator)->post(route('admin.setores.membros.store', $this->setorPerformanceId), [
            'user_id'  => $user->id,
            'cargo_id' => $this->cargoAnalistaId,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Usuário já tem este cargo neste setor.');

        $this->assertCount(1, $this->linhasDoPar($user->id, $this->setorPerformanceId));
    }

    // ─── Teste 3 ────────────────────────────────────────────────────────────

    public function test_membro_com_cargo_null_recebendo_cargo_atualiza_a_linha_existente(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, null, true);

        $response = $this->actingAs($ator)->post(route('admin.setores.membros.store', $this->setorPerformanceId), [
            'user_id'  => $user->id,
            'cargo_id' => $this->cargoAnalistaId,
        ]);

        $response->assertRedirect();

        $linhas = $this->linhasDoPar($user->id, $this->setorPerformanceId);
        $this->assertCount(1, $linhas, 'Não pode nascer linha nova — a linha sem cargo é atualizada.');
        $this->assertSame($this->cargoAnalistaId, (int) $linhas->first()->cargo_id);
    }

    // ─── Teste 4 ────────────────────────────────────────────────────────────

    public function test_post_sem_cargo_para_quem_ja_e_membro_e_recusado(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $response = $this->actingAs($ator)->post(route('admin.setores.membros.store', $this->setorPerformanceId), [
            'user_id' => $user->id,
        ]);

        $response->assertRedirect();
        $session = $response->getSession();
        $this->assertStringContainsString('já é membro deste setor', (string) $session->get('error'));

        $this->assertCount(1, $this->linhasDoPar($user->id, $this->setorPerformanceId));
    }

    // ─── Teste 5 ────────────────────────────────────────────────────────────

    public function test_delete_com_vinculo_apaga_so_aquela_linha(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $vinculoEstrategista = $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        $response = $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor'   => $this->setorPerformanceId,
            'user'    => $user->id,
            'vinculo' => $vinculoEstrategista,
        ]));

        $response->assertRedirect();

        $linhas = $this->linhasDoPar($user->id, $this->setorPerformanceId);
        $this->assertCount(1, $linhas);
        $this->assertSame($this->cargoAnalistaId, (int) $linhas->first()->cargo_id);
    }

    // ─── Teste 6 ────────────────────────────────────────────────────────────

    public function test_delete_sem_vinculo_para_quem_tem_dois_cargos_e_recusado(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        $response = $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor' => $this->setorPerformanceId,
            'user'  => $user->id,
        ]));

        $response->assertRedirect();
        $session = $response->getSession();
        $this->assertStringContainsString('um cargo por vez', (string) $session->get('error'));

        $this->assertCount(2, $this->linhasDoPar($user->id, $this->setorPerformanceId));
    }

    // ─── Teste 7 ────────────────────────────────────────────────────────────

    public function test_delete_sem_vinculo_para_quem_tem_uma_linha_apaga_como_antes(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);

        $response = $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor' => $this->setorPerformanceId,
            'user'  => $user->id,
        ]));

        $response->assertRedirect();
        $this->assertCount(0, $this->linhasDoPar($user->id, $this->setorPerformanceId));
    }

    // ─── Teste 8 ────────────────────────────────────────────────────────────

    public function test_delete_com_vinculo_de_outro_setor_ou_outro_usuario_da_404_e_nao_apaga(): void
    {
        $user = User::factory()->create();
        $outroUser = User::factory()->create();
        $ator = $this->admin();

        $outroSetorId = DB::table('setores')->insertGetId([
            'nome'       => 'Setor Outro 159-02',
            'slug'       => 'setor-outro-159-02',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vinculoDoUser = $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $vinculoDeOutroSetor = $this->inserirVinculo($user->id, $outroSetorId, null, true);
        $vinculoDeOutroUser = $this->inserirVinculo($outroUser->id, $this->setorPerformanceId, $this->cargoEstrategistaId, true);

        // vínculo de outro SETOR (pertence ao user certo, mas setor errado na rota)
        $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor'   => $this->setorPerformanceId,
            'user'    => $user->id,
            'vinculo' => $vinculoDeOutroSetor,
        ]))->assertNotFound();

        // vínculo de outro USUÁRIO (pertence ao setor certo, mas usuário errado na rota)
        $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor'   => $this->setorPerformanceId,
            'user'    => $user->id,
            'vinculo' => $vinculoDeOutroUser,
        ]))->assertNotFound();

        $this->assertCount(1, $this->linhasDoPar($user->id, $this->setorPerformanceId));
        $this->assertTrue(DB::table('user_setores')->where('id', $vinculoDoUser)->exists());
        $this->assertTrue(DB::table('user_setores')->where('id', $vinculoDeOutroSetor)->exists());
        $this->assertTrue(DB::table('user_setores')->where('id', $vinculoDeOutroUser)->exists());
    }

    // ─── Teste 9 ────────────────────────────────────────────────────────────

    public function test_remover_a_linha_principal_promove_a_de_menor_id_restante(): void
    {
        $user = User::factory()->create();
        $ator = $this->admin();
        $vinculoAnalista = $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoAnalistaId, false);
        $vinculoEstrategista = $this->inserirVinculo($user->id, $this->setorPerformanceId, $this->cargoEstrategistaId, true);

        $this->actingAs($ator)->delete(route('admin.setores.membros.destroy', [
            'setor'   => $this->setorPerformanceId,
            'user'    => $user->id,
            'vinculo' => $vinculoEstrategista,
        ]))->assertRedirect();

        $restante = DB::table('user_setores')->where('id', $vinculoAnalista)->first();
        $this->assertSame(1, (int) $restante->is_principal, 'A linha de menor id restante vira principal.');
    }

    // ─── Teste 10 ───────────────────────────────────────────────────────────

    public function test_show_expoe_vinculo_id_por_linha_e_index_conta_pessoas_distintas(): void
    {
        $pessoaDoisCargos = User::factory()->create(['name' => 'Pessoa Dois Cargos 159-02']);
        $this->inserirVinculo($pessoaDoisCargos->id, $this->setorPerformanceId, $this->cargoAnalistaId, true);
        $this->inserirVinculo($pessoaDoisCargos->id, $this->setorPerformanceId, $this->cargoEstrategistaId, false);

        $ator = $this->admin();

        $show = $this->actingAs($ator)->get(route('admin.setores.show', $this->setorPerformanceId));
        $show->assertOk();

        $membros = collect($show->viewData('page')['props']['membros']);
        $doPessoa = $membros->where('id', $pessoaDoisCargos->id);

        $this->assertCount(2, $doPessoa, 'Duas entradas para a pessoa, uma por cargo.');
        $vinculoIds = $doPessoa->pluck('vinculo_id')->filter()->unique();
        $this->assertCount(2, $vinculoIds, 'Cada entrada precisa ter vinculo_id distinto.');
        $this->assertTrue($doPessoa->pluck('cargo_nome')->contains('Analista'));
        $this->assertTrue($doPessoa->pluck('cargo_nome')->contains('Estrategista'));

        $index = $this->actingAs($ator)->get(route('admin.setores.index'));
        $index->assertOk();

        $setores = collect($index->viewData('page')['props']['setores']);
        $performance = $setores->firstWhere('id', $this->setorPerformanceId);
        $this->assertSame(1, $performance['membros_count'], 'A pessoa com dois cargos conta como 1 membro.');
    }
}
