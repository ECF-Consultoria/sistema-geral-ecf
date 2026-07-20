<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;
use App\Models\User;

/**
 * Fase 103 Plan 02 (v18.0) — `renderCarteirasConsolidadas()` passa a resolver
 * período via `MetricPeriodResolver` (Fase 100), trocando a janela rolante em
 * DIAS (`?period=1/7/30/180`, sem baseline) pela janela mensal do resolver.
 *
 * Escopo MÍNIMO (decisão travada 3 do 103-02-PLAN.md): a consolidada troca
 * SÓ a fonte do período e passa a expor `periodo` no payload — SEM chip de
 * variação/baseline novo por card (isso é UI/Fase 104). `avg_margin`
 * permanece com a semântica atual (AVG(contribution_margin_pct)).
 *
 * Cobre:
 *  - CAR-03 — payload top-level expõe `periodo` (shape do resolver) no mês
 *    em curso (default, sem `?mes=`).
 *  - CAR-01 — mês em curso permanece byte-idêntico (soma na janela
 *    current_start..current_end do resolver, mesmo total que a janela
 *    rolante daria hoje).
 *  - Escopo mínimo — card não ganha chave de variação/baseline nova;
 *    `avg_margin` presente com a semântica atual.
 *  - CAR-01 — `?mes=YYYY-MM` resolve `closed_period`; somas restritas ao mês
 *    selecionado (registro fora da janela NÃO entra).
 *
 * ISOLAMENTO HTTP: `renderCarteirasConsolidadas` usa só
 * `getCachedGrossBillingsMany`/`getCachedAccountMetricsMany` (leituras de
 * cache — `Cache::many`, SEM HTTP) e NÃO chama `AdmanMetricDiffService::compute()`
 * (fora de escopo — decisão travada 3). `Http::preventStrayRequests()`
 * incluído por higiene, sem nenhum fake registrado — qualquer chamada HTTP
 * faria a suíte falhar.
 *
 * @see .planning/phases/103-carteira-por-periodo-v18-0/103-02-PLAN.md
 */
class CarteiraConsolidadaPeriodoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Higiene — a consolidada só lê cache (Cache::many), nenhum fake
        // deveria ser necessário; qualquer HTTP real faria a suíte falhar.
        Http::preventStrayRequests();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-103-02',
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

    /**
     * Vincula o user ao cargo analista (obrigatório pra aparecer na
     * listagem de `renderCarteirasConsolidadas`, que filtra via
     * `whereExists` em `user_setores` + `cargos.slug`).
     */
    private function vincularCargoAnalista(User $u): void
    {
        DB::table('user_setores')->insert([
            'user_id'    => $u->id,
            'setor_id'   => $this->setorId,
            'cargo_id'   => $this->cargoAnalistaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `reference_date` explícito (nunca "hoje relativo") — determinismo com
     * `Carbon::setTestNow` fixado no topo de cada teste.
     */
    private function seedAdmanMetric(int $companyId, string $referenceDate, float $revenue, float $adSpend = 0.0, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $companyId,
            'reference_date'      => $referenceDate,
            'revenue'             => $revenue,
            'ad_spend'            => $adSpend,
            'contribution_margin' => $margem,
        ]);
    }

    /**
     * Localiza o card de um user no payload `user_portfolios` por id.
     */
    private function localizarCard(array $userPortfolios, int $userId): ?array
    {
        foreach ($userPortfolios as $card) {
            if ($card['id'] === $userId) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Cenário base: 1 analista com empresa elegível (contrato Performance +
     * pivot `company_users` com `servico_id`), vinculado ao cargo analista.
     *
     * @return array{company_id: int, analista: User}
     */
    private function criarCenarioAnalistaElegivel(): array
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->vincularCargoAnalista($cenario['analista']);

        return ['company_id' => $cenario['company']->id, 'analista' => $cenario['analista']];
    }

    // ═══ Testes ════════════════════════════════════════════════════════════

    // ─── Test A (CAR-03) · payload.periodo, mês em curso (default) ─────────

    #[Test]
    public function test_payload_periodo_mes_em_curso_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $admin = $this->criarAdmin();
        $this->criarCenarioAnalistaElegivel();

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertArrayHasKey('periodo', $props);
        $this->assertSame('2026-07-01', $props['periodo']['current_start']);
        $this->assertSame('2026-07-20', $props['periodo']['current_end']);
        $this->assertSame('2026-06-01', $props['periodo']['baseline_start']);
        $this->assertSame('2026-06-20', $props['periodo']['baseline_end']);
        $this->assertSame('operational', $props['periodo']['mode']);
        $this->assertTrue($props['periodo']['is_current_month']);
        $this->assertFalse($props['periodo']['is_closed']);
    }

    // ─── Test B (CAR-01) · mês em curso, somas byte-idênticas ───────────────

    #[Test]
    public function test_somas_mes_em_curso_byte_identicas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioAnalistaElegivel();

        // Dentro da janela current_month (01-20/07) — a lógica ANTIGA
        // ($since=now()->subDays(30)) também incluiria esse dia, então o
        // total deve ser IDÊNTICO (byte-idêntico) ao comportamento rolante.
        $this->seedAdmanMetric($cenario['company_id'], '2026-07-10', 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);
        $this->assertSame(1000.0, $card['total_revenue']);
    }

    // ─── Test C (escopo mínimo) · sem variação/baseline nova por card ───────

    #[Test]
    public function test_card_nao_ganha_chave_de_variacao_nova(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioAnalistaElegivel();
        $this->seedAdmanMetric($cenario['company_id'], '2026-07-10', 1000.0, 100.0, 300.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);

        $this->assertArrayHasKey('avg_margin', $card, 'avg_margin deve continuar presente (semântica atual, AVG(contribution_margin_pct)).');
        $this->assertArrayNotHasKey('margem_variacao_pct', $card, 'Escopo mínimo — sem variação nova por card nesta fase.');
        $this->assertArrayNotHasKey('avg_margin_anterior', $card, 'Escopo mínimo — sem baseline novo por card nesta fase.');
    }

    // ─── Test D (CAR-01) · ?mes=YYYY-MM resolve closed_period ───────────────

    #[Test]
    public function test_mes_query_resolve_closed_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));

        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioAnalistaElegivel();

        // Dentro de maio (dentro da janela) — deve entrar na soma.
        $this->seedAdmanMetric($cenario['company_id'], '2026-05-15', 500.0);
        // Fora da janela de maio (mês em curso, julho) — NÃO deve entrar.
        $this->seedAdmanMetric($cenario['company_id'], '2026-07-10', 9999.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own', ['mes' => '2026-05']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('2026-05-01', $props['periodo']['current_start']);
        $this->assertSame('2026-05-31', $props['periodo']['current_end']);
        $this->assertSame('closed_period', $props['periodo']['mode']);

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);
        $this->assertSame(500.0, $card['total_revenue'], 'Só o registro de maio (dentro da janela) deve entrar na soma.');
    }
}
