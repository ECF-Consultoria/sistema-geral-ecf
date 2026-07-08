<?php

namespace Tests\Feature\Phase72;

use App\Models\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 72 Plan 04 T2 — Suite Feature do PATCH /nps/configuracao/dia-cobranca.
 *
 * Cobre SC #1 do ROADMAP Phase 72 + REQ NPS-E-01 (config global dia_cobranca):
 *   1. Admin persiste dia 1..31 valido → assertDatabaseHas na tabela configuracoes
 *   2. Admin atualiza dia ja existente → get() retorna novo valor
 *   3. Dia fora do range (0, 32, texto) → 302 + assertSessionHasErrors('dia')
 *   4. Non-admin (consultor) → 403 (middleware role:admin)
 *
 * Padrao herdado da Phase 70/71 — usa ->post/->patch (nao ->postJson/->patchJson)
 * para bater com fluxo real do Inertia (302 + session errors).
 *
 * Referencias:
 *   - .planning/phases/72-dashboards-pendencias-dia-cobranca/72-01-PLAN.md T2
 *   - app/Http/Controllers/NpsController.php::atualizarDiaCobranca
 *   - routes/web.php linha 239-241 (rota PATCH admin-only)
 */
class NpsDiaCobrancaConfigTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrao herdado.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Cria user admin — helper de auth para os testes deste arquivo.
     */
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria user consultor — usado no guard 403.
     */
    private function consultor(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — admin persiste dia valido via PATCH
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_persiste_dia_cobranca_valido_via_patch(): void
    {
        // Fluxo canonico: admin envia PATCH com dia=10 (valido), controller
        // valida e persiste em configuracoes.chave='nps_dia_cobranca'.
        // Store guarda VALOR COMO STRING (padrao key-value); o service casta
        // de volta com clamp defensivo.
        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.dia-cobranca.update'),
            ['dia' => 10]
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'nps_dia_cobranca',
            'valor' => '10',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — admin atualiza dia existente (updateOrCreate)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_atualiza_dia_existente(): void
    {
        // Configuracao::set usa updateOrCreate — segunda chamada com mesma
        // chave atualiza o row existente em vez de duplicar. Cobre o cenario
        // de admin trocar o dia (25 → 30) sem lixo no banco.
        Configuracao::set('nps_dia_cobranca', '25');

        $this->actingAs($this->admin());

        $response = $this->patch(
            route('nps.configuracao.dia-cobranca.update'),
            ['dia' => 30]
        );

        $response->assertRedirect();
        $this->assertSame('30', Configuracao::get('nps_dia_cobranca'),
            'valor deveria ser atualizado para 30 (nao duplicado)');

        // Sanity check: apenas 1 row em configuracoes com essa chave.
        $this->assertSame(1,
            Configuracao::where('chave', 'nps_dia_cobranca')->count(),
            'updateOrCreate nao deveria duplicar rows para a mesma chave'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — dia fora do range 1..31 retorna 302 + errors
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_dia_fora_range_retorna_422(): void
    {
        // Validacao do controller: dia deve ser integer|min:1|max:31.
        // Padrao Inertia — Laravel retorna 302 + flashes errors na session
        // (nao 422 JSON). O nome deste teste preserva a semantica "422" do
        // plan-file mas asserta como o Laravel realmente responde.
        $this->actingAs($this->admin());

        // 0 → underflow
        $this->patch(route('nps.configuracao.dia-cobranca.update'), ['dia' => 0])
            ->assertStatus(302)
            ->assertSessionHasErrors(['dia']);

        // 32 → overflow
        $this->patch(route('nps.configuracao.dia-cobranca.update'), ['dia' => 32])
            ->assertStatus(302)
            ->assertSessionHasErrors(['dia']);

        // 'abc' → tipo invalido (string)
        $this->patch(route('nps.configuracao.dia-cobranca.update'), ['dia' => 'abc'])
            ->assertStatus(302)
            ->assertSessionHasErrors(['dia']);

        // Sanity check: nenhuma das tentativas invalidas persistiu no banco.
        $this->assertDatabaseMissing('configuracoes', [
            'chave' => 'nps_dia_cobranca',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — non-admin recebe 403 (middleware role:admin)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_non_admin_recebe_403(): void
    {
        // Rota esta dentro do grupo Route::middleware('role:admin')->group()
        // no routes/web.php — consultor autenticado recebe 403 ANTES do
        // controller ser executado. Nenhuma configuracao e persistida.
        $this->actingAs($this->consultor());

        $response = $this->patch(
            route('nps.configuracao.dia-cobranca.update'),
            ['dia' => 15]
        );

        $response->assertStatus(403);

        // Sanity check: guard bloqueou antes do controller, nada persistido.
        $this->assertDatabaseMissing('configuracoes', [
            'chave' => 'nps_dia_cobranca',
        ]);
    }
}
