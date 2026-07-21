<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 2 do plano de otimização (2026-07-21) — `/performance/{id}` (show)
 * alinhado ao MESMO contrato de período do ranking: `?modo=em_curso|
 * bonus_atual` e `?mes=YYYY-MM`, com payload `periodo`/`bonus`/`nps_window`.
 *
 * Usa um profissional SEM carteira (compute() retorna sem_carteira cedo, sem
 * HTTP) — as asserções são de nível de PERÍODO, independentes da carteira.
 */
class PerformanceShowPeriodoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        // 20/07/2026: mês em curso = julho; último fechado = junho.
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $a = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($a);
        return $a;
    }

    #[Test]
    public function test_show_bonus_atual_traz_competencia_junho_e_nps_julho(): void
    {
        $this->admin();
        $alvo = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->get(route('performance.show', $alvo) . '?modo=bonus_atual')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('periodo.is_closed', true)
                ->where('bonus.competence_month', '2026-06')
                ->where('bonus.payment_month', '2026-07')
                ->where('nps_window.collection_month', '2026-07')
                ->where('nps_window.status', 'coletando')
            );
    }

    #[Test]
    public function test_show_em_curso_default_sem_bonus(): void
    {
        $this->admin();
        $alvo = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->get(route('performance.show', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('periodo.is_closed', false)
                ->where('modo', null)
                ->where('bonus', null)
                ->where('nps_window', null)
            );
    }

    #[Test]
    public function test_show_mes_especifico_via_query(): void
    {
        $this->admin();
        $alvo = User::factory()->create(['role' => 'consultor', 'active' => true]);

        // Junho fechado via ?mes=2026-06 (mês passado, is_closed=true).
        $this->get(route('performance.show', $alvo) . '?mes=2026-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Performance/Show')
                ->where('periodo.is_closed', true)
                ->where('mes_selecionado', '2026-06-01')
            );
    }
}
