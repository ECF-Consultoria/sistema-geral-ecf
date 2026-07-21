<?php

namespace Tests\Feature\Portfolio;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 3 do plano de otimização (2026-07-21) — carteira de transparência (§8.3),
 * página NOVA e aditiva. Valida rota, autorização (admin/self/líder) e o
 * contrato de período (?modo=/?mes=). Usa profissional SEM carteira (empresas=[])
 * para as asserções de nível de período/auth sem HTTP externo.
 */
class CarteiraTransparenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_admin_ve_transparencia_com_contrato_de_periodo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $alvo  = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('portfolio.transparencia', $alvo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/Transparencia')
                ->where('periodo.is_closed', false)
                ->where('bonus', null)
                ->has('empresas')
                ->has('resumo.total_empresas')
            );
    }

    #[Test]
    public function test_modo_bonus_atual_resolve_competencia_fechada(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $alvo  = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('portfolio.transparencia', $alvo) . '?modo=bonus_atual')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolio/Transparencia')
                ->where('periodo.is_closed', true)
                ->where('modo', 'bonus_atual')
                ->where('bonus.competence_month', '2026-06')
            );
    }

    #[Test]
    public function test_self_ve_a_propria_transparencia(): void
    {
        $user = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($user)
            ->get(route('portfolio.transparencia', $user))
            ->assertOk();
    }

    #[Test]
    public function test_nao_admin_nao_ve_de_outro(): void
    {
        $user  = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $outro = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $this->actingAs($user)
            ->get(route('portfolio.transparencia', $outro))
            ->assertForbidden();
    }
}
