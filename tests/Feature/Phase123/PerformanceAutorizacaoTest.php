<?php

namespace Tests\Feature\Phase123;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 123 Plano 07 (Task 1) — CR-01 do `123-REVIEW.md`/`123-VERIFICATION.md`:
 * `PerformanceController::show()` e `::evolucao()` não checavam QUEM está
 * pedindo o desempenho de QUEM. A Fase 123 acrescentou a esse payload nome
 * de empresa cliente, faturamento em R$, margem e nota por empresa — dado
 * financeiro/de compensação de terceiro. Prova os dois lados (dono/outro) e
 * o caso líder-vê-equipe / líder-não-vê-fora-da-equipe, espelhando
 * literalmente a regra de `PortfolioController::transparencia()`.
 *
 * Também cobre WR-01 (`?mes=` fora de 01-12 nunca produz 500).
 */
class PerformanceAutorizacaoTest extends Phase123TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        // Badge global de alertas críticos — infraestrutura alheia a esta
        // fase, pré-aquecer evita ruído/HTTP inesperado nas asserções.
        Cache::put('alertas.criticos_nao_ackeados.count', 0, 300);
    }

    /**
     * Concede `core.performance` ao setor de `criarUserElegivel()` via
     * `setor_permissoes` — mesmo padrão de
     * `tests/Feature/PublicacaoDesempenhoRouteTest.php:81-86`. Não faz o
     * user líder de nada.
     */
    private function concederCorePerformanceAoSetor(): void
    {
        DB::table('setor_permissoes')->insert([
            'setor_id'       => $this->setorId,
            'permission_key' => 'core.performance',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    // ═══ (a) dono vê o próprio desempenho ═════════════════════════════════

    #[Test]
    public function nao_admin_com_permission_ve_o_proprio_show_200(): void
    {
        $this->concederCorePerformanceAoSetor();
        $alvo = $this->criarUserElegivel('Dono');
        $this->actingAs($alvo);

        $this->assertTrue($alvo->fresh()->hasPermission('core.performance'),
            'setup: user deveria ter core.performance via setor_permissoes');

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Show'));
    }

    #[Test]
    public function nao_admin_com_permission_ve_a_propria_evolucao_200(): void
    {
        $this->concederCorePerformanceAoSetor();
        $alvo = $this->criarUserElegivel('Dono Evolucao');
        $this->actingAs($alvo);

        $this->getJson(route('performance.evolucao', $alvo))
            ->assertOk()
            ->assertJsonPath('user_id', $alvo->id);
    }

    // ═══ (b) não-admin não vê o desempenho de outro ═══════════════════════

    #[Test]
    public function nao_admin_com_permission_nao_ve_show_de_outro_403(): void
    {
        $this->concederCorePerformanceAoSetor();
        $atual = $this->criarUserElegivel('Nao Dono');
        $outro = $this->criarUserElegivel('Outro Profissional');
        $this->actingAs($atual);

        $this->get(route('performance.show', $outro))
            ->assertStatus(403);
    }

    #[Test]
    public function nao_admin_com_permission_nao_ve_evolucao_de_outro_403(): void
    {
        $this->concederCorePerformanceAoSetor();
        $atual = $this->criarUserElegivel('Nao Dono Evolucao');
        $outro = $this->criarUserElegivel('Outro Profissional Evolucao');
        $this->actingAs($atual);

        $this->getJson(route('performance.evolucao', $outro))
            ->assertStatus(403);
    }

    // ═══ (c) líder do setor Performance continua vendo a própria equipe ══

    #[Test]
    public function lider_do_setor_performance_ve_membro_da_equipe_200(): void
    {
        // Setor NOVO com slug EXATO 'performance' — Permissions::AUTO_LIDERANCA_PERFORMANCE
        // (app/Models/User.php:236) checa o slug literal, não 'performance-123'
        // (o setor default de Phase123TestCase).
        $setorPerformanceId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance (líder)',
            'slug'       => 'performance',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lider  = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $membro = $this->criarUserElegivel('Membro da Equipe');

        DB::table('setor_lideres')->insert([
            'setor_id'    => $setorPerformanceId,
            'user_id'     => $lider->id,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $membro->id,
            'setor_id'     => $setorPerformanceId,
            'cargo_id'     => null,
            'is_principal' => false,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Sanity check pré-flight: exercita AUTO_LIDERANCA_PERFORMANCE de
        // verdade, não uma permission manual — evita falso positivo se o
        // pacote automático mudar de critério.
        $this->assertTrue($lider->fresh()->hasPermission('core.performance'),
            'setup: líder do setor "performance" deveria ganhar core.performance via AUTO_LIDERANCA_PERFORMANCE');

        $this->actingAs($lider);

        $this->get(route('performance.show', $membro))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Performance/Show'));
    }

    #[Test]
    public function lider_de_um_setor_nao_ve_desempenho_de_quem_nao_esta_sob_sua_lideranca_403(): void
    {
        $setorPerformanceId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance (líder 2)',
            'slug'       => 'performance',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lider     = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $foraDaEquipe = $this->criarUserElegivel('Fora da Equipe');

        DB::table('setor_lideres')->insert([
            'setor_id'    => $setorPerformanceId,
            'user_id'     => $lider->id,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // $foraDaEquipe é membro só do setor 'performance-123' (via
        // criarUserElegivel) — NUNCA vinculado a $setorPerformanceId.
        $this->assertTrue($lider->fresh()->isLider());

        $this->actingAs($lider);

        $this->get(route('performance.show', $foraDaEquipe))
            ->assertStatus(403);
    }

    // ═══ (d) WR-01 — ?mes= fora de 01-12 nunca produz 500 ═════════════════

    #[Test]
    public function mes_invalido_no_ranking_admin_nunca_produz_500(): void
    {
        $this->adminLogado();

        $this->get('/performance?mes=2026-13')
            ->assertOk();
    }

    #[Test]
    public function mes_invalido_no_show_nunca_produz_500(): void
    {
        $this->adminLogado();
        $qualquer = $this->criarUserElegivel('Qualquer');

        $this->get(route('performance.show', $qualquer) . '?mes=9999-99')
            ->assertOk();
    }
}
