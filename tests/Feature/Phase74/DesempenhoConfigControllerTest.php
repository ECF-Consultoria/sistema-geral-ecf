<?php

namespace Tests\Feature\Phase74;

use App\Models\BonusFaixa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 74 · Plan 74-10 (Arquivo A) — Suite Feature do
 * `DesempenhoConfigController` + `UpdateBonusFaixaRequest`.
 *
 * Cobre DESEMP-12 (config admin editável de faixas de bônus):
 *  - RBAC: só admin acessa (analista/estrategista → 403)
 *  - GET índice renderiza `Desempenho/Configuracao` com prop `faixas` seed
 *  - PATCH update: happy path + falhas de validação (range, gte, sobreposição)
 *  - Edge cases da validação de sobreposição (D-13): slug maximo aceita
 *    [5.00, 5.00]; faixas inativas ficam fora do check.
 *  - Toggle-active preserva row (flip ativo=true↔false).
 *
 * Estratégia (D-26):
 *  - `RefreshDatabase` + `PRAGMA foreign_keys = ON`
 *  - Faixas seed vêm da migration `2026_07_09_140003_seed_bonus_faixas_iniciais.php`
 *    automaticamente — RefreshDatabase roda todas as migrations.
 *  - `AssertableInertia` para inspeção de props do response Inertia.
 *
 * @see .planning/phases/74-.../74-10-PLAN.md (Task 1)
 * @see .planning/phases/74-.../74-CONTEXT.md §D-10, D-11, D-13
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-12
 */
class DesempenhoConfigControllerTest extends TestCase
{
    use RefreshDatabase;

    /** IDs de setor/cargos criados para o pivot `user_setores`. */
    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Setor Performance + cargos para gerar analista/estrategista via pivot.
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-74-cfg',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'   => 'admin',
            'active' => true,
        ]);
    }

    private function criarUserComCargo(int $cargoId, string $roleGlobal = 'consultor'): User
    {
        $user = User::factory()->create([
            'role'   => $roleGlobal,
            'active' => true,
        ]);
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        return $user;
    }

    private function criarAnalista(): User
    {
        return $this->criarUserComCargo($this->cargoAnalistaId, 'consultor');
    }

    private function criarEstrategista(): User
    {
        return $this->criarUserComCargo($this->cargoEstrategistaId, 'mentor');
    }

    private function faixa(string $slug): BonusFaixa
    {
        return BonusFaixa::where('slug', $slug)->firstOrFail();
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    // ─── RBAC: middleware role:admin + FormRequest::authorize ───────────────

    #[Test]
    public function test_get_configuracao_como_analista_retorna_403(): void
    {
        // DESEMP-12 · guard middleware role:admin — analista não passa.
        $analista = $this->criarAnalista();
        $r = $this->actingAs($analista)->get('/desempenho/configuracao');
        $r->assertStatus(403);
    }

    #[Test]
    public function test_get_configuracao_como_estrategista_retorna_403(): void
    {
        // DESEMP-12 · o guard NÃO libera para cargos internos do setor
        // Performance — só role global 'admin' passa.
        $estrategista = $this->criarEstrategista();
        $r = $this->actingAs($estrategista)->get('/desempenho/configuracao');
        $r->assertStatus(403);
    }

    #[Test]
    public function test_get_configuracao_como_admin_retorna_200_com_faixas_seed(): void
    {
        // DESEMP-12 · GET index como admin retorna 200 e as 4 faixas seed
        // (sem_bonus, basico, intermediario, maximo) presentes.
        $admin = $this->criarAdmin();

        $r = $this->actingAs($admin)->get('/desempenho/configuracao');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page
            ->component('Desempenho/Configuracao')
            ->has('faixas', 4)
        );

        // Confirma que os 4 slugs canônicos estão presentes.
        $slugs = BonusFaixa::orderBy('ordem')->pluck('slug')->all();
        $this->assertSame(['sem_bonus', 'basico', 'intermediario', 'maximo'], $slugs);
    }

    // ─── Update happy path ──────────────────────────────────────────────────

    #[Test]
    public function test_patch_faixa_atualiza_nota_min_e_nota_max_com_sucesso(): void
    {
        // DESEMP-12 · admin edita a faixa `intermediario` sem sobrepor com
        // outras ativas (basico [4.00, 4.49], maximo [5.00, 5.00]) —
        // intermediario passa de [4.50, 4.99] para [4.60, 4.99].
        $admin = $this->criarAdmin();
        $inter = $this->faixa('intermediario');

        $r = $this->actingAs($admin)
            ->patch("/desempenho/configuracao/faixas/{$inter->id}", [
                'nome'      => 'Intermediário',
                'descricao' => 'Bônus intermediário editado.',
                'nota_min'  => 4.60,
                'nota_max'  => 4.99,
                'ordem'     => 3,
            ]);

        $r->assertRedirect();
        $r->assertSessionHas('success');

        $inter->refresh();
        $this->assertEquals(4.60, (float) $inter->nota_min);
        $this->assertEquals(4.99, (float) $inter->nota_max);
    }

    // ─── Validações primárias (rules) ───────────────────────────────────────

    #[Test]
    public function test_patch_faixa_rejeita_nota_min_maior_que_nota_max(): void
    {
        // DESEMP-12 · regra `gte:nota_min` bloqueia payload invertido.
        $admin = $this->criarAdmin();
        $inter = $this->faixa('intermediario');

        $r = $this->actingAs($admin)
            ->from('/desempenho/configuracao')
            ->patch("/desempenho/configuracao/faixas/{$inter->id}", [
                'nome'      => 'Intermediário',
                'descricao' => 'edit',
                'nota_min'  => 4.99,
                'nota_max'  => 4.50,
                'ordem'     => 3,
            ]);

        $r->assertRedirect('/desempenho/configuracao');
        $r->assertSessionHasErrors(['nota_max']);
    }

    #[Test]
    public function test_patch_faixa_permite_igualdade_para_slug_maximo(): void
    {
        // DESEMP-12 (D-13) · a faixa `maximo` ACEITA nota_min == nota_max
        // (invariante do seed [5.00, 5.00] — representa a nota exata 5.00).
        $admin  = $this->criarAdmin();
        $maximo = $this->faixa('maximo');

        $r = $this->actingAs($admin)
            ->patch("/desempenho/configuracao/faixas/{$maximo->id}", [
                'nome'      => 'Máximo',
                'descricao' => 'ok',
                'nota_min'  => 5.00,
                'nota_max'  => 5.00,
                'ordem'     => 4,
            ]);

        $r->assertRedirect();
        $r->assertSessionHas('success');
        $r->assertSessionHasNoErrors();
    }

    #[Test]
    public function test_patch_faixa_rejeita_range_fora_de_0_5(): void
    {
        // DESEMP-12 · regra `between:0,5` bloqueia valores fora da escala.
        $admin = $this->criarAdmin();
        $inter = $this->faixa('intermediario');

        // nota_min = -1 → erro em nota_min
        $r1 = $this->actingAs($admin)
            ->from('/desempenho/configuracao')
            ->patch("/desempenho/configuracao/faixas/{$inter->id}", [
                'nome'      => 'x',
                'descricao' => null,
                'nota_min'  => -1,
                'nota_max'  => 4.99,
                'ordem'     => 3,
            ]);
        $r1->assertRedirect('/desempenho/configuracao');
        $r1->assertSessionHasErrors(['nota_min']);

        // nota_max = 6 → erro em nota_max
        $r2 = $this->actingAs($admin)
            ->from('/desempenho/configuracao')
            ->patch("/desempenho/configuracao/faixas/{$inter->id}", [
                'nome'      => 'x',
                'descricao' => null,
                'nota_min'  => 4.50,
                'nota_max'  => 6,
                'ordem'     => 3,
            ]);
        $r2->assertRedirect('/desempenho/configuracao');
        $r2->assertSessionHasErrors(['nota_max']);
    }

    // ─── Regra composta: sobreposição entre faixas ativas ───────────────────

    #[Test]
    public function test_validacao_sobreposicao_de_faixas_ativas(): void
    {
        // DESEMP-12 (D-13) · admin tenta expandir `basico` de [4.00, 4.49]
        // para [4.00, 4.60] — invade a faixa `intermediario` [4.50, 4.99].
        // O validador `withValidator` do FormRequest deve barrar com erro
        // em `nota_min` cuja mensagem cita "Sobreposição com a faixa".
        $admin  = $this->criarAdmin();
        $basico = $this->faixa('basico');

        $r = $this->actingAs($admin)
            ->from('/desempenho/configuracao')
            ->patch("/desempenho/configuracao/faixas/{$basico->id}", [
                'nome'      => 'Básico',
                'descricao' => 'edit',
                'nota_min'  => 4.00,
                'nota_max'  => 4.60,
                'ordem'     => 2,
            ]);

        $r->assertRedirect('/desempenho/configuracao');
        $r->assertSessionHasErrors(['nota_min']);
        $this->assertStringContainsString(
            'Sobreposição com a faixa',
            session('errors')->first('nota_min')
        );
    }

    // ─── Toggle-active preserva row ────────────────────────────────────────

    #[Test]
    public function test_toggle_active_alterna_ativo_true_false(): void
    {
        // DESEMP-12 · PATCH toggle alterna o flag `ativo` mantendo a row
        // intacta (histórico preservado — não deletamos nem soft-deletamos).
        $admin  = $this->criarAdmin();
        $basico = $this->faixa('basico');
        $this->assertTrue($basico->ativo);

        // 1ª chamada — desativa.
        $r1 = $this->actingAs($admin)
            ->patch("/desempenho/configuracao/faixas/{$basico->id}/toggle-active");
        $r1->assertRedirect();
        $r1->assertSessionHas('success');
        $this->assertFalse($basico->fresh()->ativo);

        // 2ª chamada — reativa.
        $r2 = $this->actingAs($admin)
            ->patch("/desempenho/configuracao/faixas/{$basico->id}/toggle-active");
        $r2->assertRedirect();
        $this->assertTrue($basico->fresh()->ativo);

        // Row continua existindo — nenhum delete/soft-delete.
        $this->assertDatabaseHas('bonus_faixas', [
            'id'   => $basico->id,
            'slug' => 'basico',
        ]);
    }

    #[Test]
    public function test_toggle_active_como_analista_retorna_403(): void
    {
        // DESEMP-12 · guard middleware role:admin protege a rota toggle-active.
        $analista = $this->criarAnalista();
        $basico   = $this->faixa('basico');

        $r = $this->actingAs($analista)
            ->patch("/desempenho/configuracao/faixas/{$basico->id}/toggle-active");
        $r->assertStatus(403);
        $this->assertTrue($basico->fresh()->ativo,
            'Analista NÃO deve conseguir desativar faixa — flag preserva true.');
    }

    // ─── Faixa inativa fora do check de sobreposição ────────────────────────

    #[Test]
    public function test_faixa_desativada_nao_participa_de_validacao_sobreposicao(): void
    {
        // DESEMP-12 (D-13) · faixas inativas ficam fora do check —
        // admin pode reservá-las como rascunho. Depois de desativar
        // `intermediario`, expandir `basico` para invadir o antigo range
        // [4.50, 4.99] deve ser ACEITO (não há mais faixa ativa naquele
        // range).
        $admin  = $this->criarAdmin();
        $basico = $this->faixa('basico');
        $inter  = $this->faixa('intermediario');

        // Desativa intermediario primeiro.
        $inter->update(['ativo' => false]);

        $r = $this->actingAs($admin)
            ->patch("/desempenho/configuracao/faixas/{$basico->id}", [
                'nome'      => 'Básico',
                'descricao' => 'edit expandido',
                'nota_min'  => 4.00,
                'nota_max'  => 4.60,
                'ordem'     => 2,
            ]);

        // Agora aceita — a única faixa ativa restante nesse range é a
        // maximo em [5.00, 5.00], que não se sobrepõe com [4.00, 4.60].
        $r->assertRedirect();
        $r->assertSessionHas('success');
        $r->assertSessionHasNoErrors();

        $basico->refresh();
        $this->assertEquals(4.60, (float) $basico->nota_max);
    }
}
