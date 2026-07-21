<?php

namespace Tests\Feature\V18;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 104 Plan 01 (v18.0) — payload `periodo`/`bonus` no ranking `/performance`
 * (PerformanceController::index()) e reconhecimento de `?modo=bonus_atual` +
 * bloco `bonus` na carteira (PortfolioController — individual e consolidada).
 *
 * Cobre:
 *  - UIP-02/UIP-03 — `payload.periodo` vem do `MetricPeriodResolver::resolve()`
 *    chamado DIRETO no controller (shape completo, 14 chaves incl. is_closed/
 *    bonus_*), NÃO do sub-array de `compute()` (só 6 chaves).
 *  - `payload.bonus` = {competence_month, payment_month} quando o período
 *    resolvido é fechado (`is_closed=true`); `null` quando em curso.
 *  - `?modo=bonus_atual` resolve o último mês fechado via
 *    `MetricPeriodResolver` (`last_closed_month`) — competência do mês
 *    fechado, não o mês em curso.
 *  - `?modo=bonus_atual` SIMÉTRICO nas 3 telas: ranking (PerformanceController)
 *    + carteira individual e consolidada (PortfolioController).
 *  - DESEMP-02 — `?modo=` é só conveniência de UI pra decidir QUAL mês; não
 *    cria segundo score (gate textual no PerformanceController já cobre isso
 *    em PerformanceIndexMetadadosTest — não duplicado aqui).
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO: `Http::preventStrayRequests()` no setUp.
 * Nenhum user/empresa é criado nos testes do ranking (payload.periodo/bonus
 * independe de haver linhas no ranking) — zero HTTP disparado. Os testes da
 * carteira também não criam empresas elegíveis (payload.periodo/bonus não
 * depende de haver empresas na carteira).
 *
 * @see .planning/phases/104-ui-de-periodo-v18-0/104-01-PLAN.md
 */
class PeriodoBonusPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ISOLAMENTO HTTP OBRIGATÓRIO — nenhuma requisição real à Adman.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ═══ Task 1 — PerformanceController (ranking /performance) ══════════════

    #[Test]
    public function test_ranking_modo_em_curso_expoe_periodo_em_curso_e_bonus_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin = $this->criarAdmin();

        // Ausência de ?modo= deve se comportar como em_curso (default atual).
        $response = $this->actingAs($admin)->get('/performance');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('periodo', $props, 'payload deve carregar o objeto periodo (não mais string estática).');
        $this->assertIsArray($props['periodo']);
        $this->assertTrue($props['periodo']['is_current_month']);
        $this->assertFalse($props['periodo']['is_closed']);
        $this->assertNull($props['bonus'], 'bonus deve ser null no modo em-curso.');

        // Explícito ?modo=em_curso — mesmo resultado.
        $responseExplicito = $this->actingAs($admin)->get('/performance?modo=em_curso');
        $responseExplicito->assertOk();
        $propsExplicito = $responseExplicito->viewData('page')['props'];
        $this->assertTrue($propsExplicito['periodo']['is_current_month']);
        $this->assertNull($propsExplicito['bonus']);
    }

    #[Test]
    public function test_ranking_modo_bonus_atual_resolve_ultimo_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->get('/performance?modo=bonus_atual');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        // Último mês fechado a partir de 2026-07-20 é junho/2026 (competência),
        // pago em julho/2026. Baseline = janela-de-mesmo-tamanho (30 dias
        // imediatamente anteriores a 01/06 — 02/05..31/05).
        $this->assertSame('2026-06-01', $props['periodo']['current_start']);
        $this->assertSame('2026-06-30', $props['periodo']['current_end']);
        $this->assertSame('2026-05-02', $props['periodo']['baseline_start']);
        $this->assertSame('2026-05-31', $props['periodo']['baseline_end']);
        $this->assertFalse($props['periodo']['is_current_month']);
        $this->assertTrue($props['periodo']['is_closed']);

        $this->assertNotNull($props['bonus'], 'bonus deve estar presente no modo bônus-atual (período fechado).');
        $this->assertSame('2026-06', $props['bonus']['competence_month']);
        $this->assertSame('2026-07', $props['bonus']['payment_month']);

        // A competência do mês fechado — não o mês em curso — deve ter sido
        // usada como referência do score (mes_selecionado no payload).
        $this->assertSame('2026-06', $props['mes_selecionado']);
    }

    #[Test]
    public function test_ranking_mes_especifico_fechado_expoe_baseline_janela_mesmo_tamanho_e_bonus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->get('/performance?mes=2026-05');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('2026-05-01', $props['periodo']['current_start']);
        $this->assertSame('2026-05-31', $props['periodo']['current_end']);
        $this->assertSame('2026-03-31', $props['periodo']['baseline_start']);
        $this->assertSame('2026-04-30', $props['periodo']['baseline_end']);
        $this->assertTrue($props['periodo']['is_closed']);

        $this->assertNotNull($props['bonus']);
        $this->assertSame('2026-05', $props['bonus']['competence_month']);
    }

    // ═══ Task 2 — PortfolioController (carteira individual + consolidada) ═══

    #[Test]
    public function test_carteira_individual_modo_bonus_atual_resolve_ultimo_mes_fechado_e_bonus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();

        $response = $this->actingAs($admin)->get(
            route('portfolio.show', $profissional) . '?modo=bonus_atual'
        );
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('2026-06-01', $props['periodo']['current_start']);
        $this->assertSame('2026-06-30', $props['periodo']['current_end']);
        $this->assertSame('2026-05-02', $props['periodo']['baseline_start']);
        $this->assertSame('2026-05-31', $props['periodo']['baseline_end']);
        $this->assertTrue($props['periodo']['is_closed']);

        $this->assertArrayHasKey('bonus', $props, 'carteira individual deve carregar bloco bonus no modo bônus-atual.');
        $this->assertSame('2026-06', $props['bonus']['competence_month']);
        $this->assertSame('2026-07', $props['bonus']['payment_month']);
    }

    #[Test]
    public function test_carteira_individual_sem_modo_permanece_mes_em_curso_com_bonus_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['periodo']['is_current_month']);
        $this->assertFalse($props['periodo']['is_closed']);
        $this->assertNull($props['bonus']);
    }

    #[Test]
    public function test_carteira_consolidada_modo_bonus_atual_resolve_ultimo_mes_fechado_e_bonus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->get(route('portfolio.own') . '?modo=bonus_atual');
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('2026-06-01', $props['periodo']['current_start']);
        $this->assertSame('2026-06-30', $props['periodo']['current_end']);
        $this->assertTrue($props['periodo']['is_closed']);

        $this->assertArrayHasKey('bonus', $props, 'carteira consolidada deve carregar bloco bonus no modo bônus-atual.');
        $this->assertSame('2026-06', $props['bonus']['competence_month']);
        $this->assertSame('2026-07', $props['bonus']['payment_month']);
    }

    #[Test]
    public function test_carteira_consolidada_sem_modo_permanece_mes_em_curso_com_bonus_null(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertTrue($props['periodo']['is_current_month']);
        $this->assertNull($props['bonus']);
    }
}
