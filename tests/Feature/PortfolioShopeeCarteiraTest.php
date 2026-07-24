<?php

namespace Tests\Feature;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\MlToken;
use App\Models\ShopeeMetric;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 109 Plano 02 (SHOP-CAR-01/02) — cobertura da fonte Shopee plugada nas
 * 4 telas de carteira (transparência, carteira individual, carteira
 * consolidada, self-view `/portfolio` → Portfolio/Show.jsx).
 *
 * Complementa (não substitui) as suítes V16/V18 pré-existentes cuja
 * regressão este plano resolve (ver 109-01-SUMMARY.md "Regressão Conhecida")
 * — aquelas cobrem o caso "vínculo Shopee elegível SEM dado sincronizado"
 * (financial_metrics_eligible/dedup/null). Esta suíte cobre os cenários
 * NOVOS desta fase: números REAIS de `shopee_metrics` (faturamento +
 * investimento), o desempate de fonte travado com dado real dos dois lados,
 * e a auto-visualização do responsável Shopee.
 *
 * Carbon fixo em 2026-07-20 — `current_month` (default, sem ?modo=/?mes=)
 * resolve current=01..20/07, baseline=01..20/06 (MetricPeriodResolver).
 */
class PortfolioShopeeCarteiraTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00'));
        // Nenhum teste desta suíte deve disparar HTTP real/fake à Adman —
        // as empresas usadas não têm cust_id (early-return sem HTTP no
        // AdmanMetricDiffService) e o caminho Shopee é 100% leitura local.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Quick 260724-dho — cria um mlToken ATIVO pra empresa, simulando OAuth
     * conectado independentemente do vínculo do profissional testado (o bug
     * era o badge usar essa flag GLOBAL da empresa em vez da fonte financeira
     * do vínculo do profissional).
     */
    private function criarMlTokenAtivo(Company $company): void
    {
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'ml-' . $company->id,
            'access_token'  => 'token',
            'refresh_token' => 'refresh',
            'expires_at'    => now()->addDay(),
            'status'        => 'active',
        ]);
    }

    /** Semeia ShopeeMetric diário (inclusive) — 1 linha por dia com o mesmo revenue/ad_expense. */
    private function semearShopee(Company $company, string $inicio, string $fim, float $revenue, ?float $adExpense = null): void
    {
        $cursor  = Carbon::parse($inicio);
        $fimData = Carbon::parse($fim);
        while ($cursor->lte($fimData)) {
            ShopeeMetric::create([
                'company_id'     => $company->id,
                'reference_date' => $cursor->toDateString(),
                'revenue'        => $revenue,
                'orders_count'   => 1,
                'sold_quantity'  => 1,
                'ad_expense'     => $adExpense,
            ]);
            $cursor->addDay();
        }
    }

    /**
     * Vincula um user a um cargo (analista/estrategista) via `user_setores`
     * — condição obrigatória pra ele aparecer na listagem de
     * `renderCarteirasConsolidadas()` (mesmo padrão de CarteirasConsolidadasContextoTest).
     */
    private function vincularCargo(User $u, string $cargoSlug): void
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Performance 109', 'slug' => 'performance-109-' . uniqid(),
            'active' => true, 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id' => $setorId, 'nome' => ucfirst($cargoSlug), 'slug' => $cargoSlug,
            'active' => true, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_setores')->insert([
            'user_id' => $u->id, 'setor_id' => $setorId, 'cargo_id' => $cargoId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Localiza o card de um user no payload `user_portfolios` por id. */
    private function localizarCard(array $userPortfolios, int $userId): ?array
    {
        foreach ($userPortfolios as $card) {
            if ($card['id'] === $userId) {
                return $card;
            }
        }
        return null;
    }

    // ─── Transparência (Task 1) ──────────────────────────────────────────

    #[Test]
    public function test_transparencia_empresa_shopee_only_mostra_faturamento_e_margem_null(): void
    {
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.transparencia', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $linha = $props['empresas'][0];
        $this->assertSame('shopee', $linha['fonte']);
        $this->assertEqualsWithDelta(2000.0, $linha['faturamento'], 0.01, '20 dias * 100.0/dia = 2000.0.');
        $this->assertEqualsWithDelta(100.0, $linha['faturamento_var_pct'], 0.01, '(2000-1000)/1000*100 = +100%.');
        $this->assertNull($linha['margem_rs'], 'Shopee nunca fornece margem R$.');
        $this->assertNull($linha['margem_pct'], 'Shopee nunca fornece margem %.');
        $this->assertNotSame('sem_fonte', $linha['status']);
        $this->assertNotSame('sem_dados', $linha['status']);
    }

    // ─── Quick 260724-dho — badge de plataforma alinhado ao vínculo ───────

    #[Test]
    public function test_transparencia_empresa_dual_mltoken_ativo_mas_vinculo_shopee_mostra_fonte_shopee(): void
    {
        // Empresa com mlToken ATIVO (OAuth conectado, ex.: usado por outro
        // serviço/profissional) mas o vínculo financeiro DESTE profissional
        // é só Shopee — o badge tem que refletir o vínculo, não a plataforma
        // global da empresa.
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->criarMlTokenAtivo($company);
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.transparencia', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['empresas'][0];
        $this->assertSame('shopee', $linha['fonte']);
        $this->assertFalse($linha['has_ml_oauth'], 'has_ml_oauth deve ser false — o vínculo deste profissional é Shopee, mesmo com mlToken ativo.');
    }

    // ─── Carteira individual — AdminCarteira (Task 1) ────────────────────

    #[Test]
    public function test_carteira_individual_empresa_shopee_only_mostra_faturamento_investimento_margem_null(): void
    {
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $linha = $props['empresas'][0];
        $this->assertSame('shopee', $linha['fonte']);
        $this->assertEqualsWithDelta(2000.0, $linha['faturamento'], 0.01);
        $this->assertEqualsWithDelta(200.0, $linha['ad_spend'], 0.01, '20 dias * 10.0/dia investimento = 200.0.');
        $this->assertNull($linha['margem_contribuicao']);
        $this->assertNull($linha['margem_variacao_pct']);
        $this->assertNull($linha['tacos'], 'Shopee ainda não expõe TACOS na carteira.');
        $this->assertEqualsWithDelta(2000.0, $props['resumo']['total_faturamento'], 0.01);
    }

    #[Test]
    public function test_carteira_individual_vinculo_shopee_sem_dados_sincronizados_mostra_null_nao_zero(): void
    {
        // Vínculo Shopee elegível MAS SEM nenhuma linha em shopee_metrics
        // ainda (contrato assinado, token não conectado) — null, nunca 0.0
        // "silencioso" nem 'sem_fonte' (isso é só pra vínculo NÃO elegível).
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['empresas'][0];
        $this->assertNull($linha['faturamento']);
        $this->assertNull($linha['ad_spend']);
        $this->assertSame('sem_dados', $linha['status']);
        $this->assertNotSame('sem_fonte', $linha['status']);
    }

    #[Test]
    public function test_carteira_individual_desempate_adman_vence_quando_dois_vinculos_com_dado_real(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');

        // AdmanMetric real (performance) + ShopeeMetric real (shopee) na
        // MESMA empresa — desempate travado: 'adman' vence, NUNCA soma.
        AdmanMetric::create([
            'company_id'          => $cenario['company']->id,
            'reference_date'      => now()->startOfMonth()->addDays(2)->toDateString(),
            'revenue'             => 1000.0,
            'ad_spend'            => 100.0,
            'contribution_margin' => 200.0,
        ]);
        $this->semearShopee($cenario['company'], '2026-07-01', '2026-07-20', 500.0, 50.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas'], 'Empresa com 2 vínculos do mesmo profissional deve aparecer 1x.');
        $servicos = collect($props['empresas'][0]['servicos'])->keyBy('setor');
        $this->assertTrue($servicos['performance']['financial_metrics_eligible']);
        $this->assertFalse($servicos['shopee']['financial_metrics_eligible']);
        $this->assertSame(1000.0, $props['resumo']['total_faturamento'], 'Faturamento vem SÓ do Adman (10000.0 somando Shopee seria o bug de duplicação).');
    }

    #[Test]
    public function test_carteira_individual_empresa_dual_mltoken_ativo_mas_vinculo_shopee_mostra_fonte_shopee(): void
    {
        // Quick 260724-dho — regressão do bug: empresa com mlToken ATIVO
        // (global) mas o vínculo financeiro DESTE profissional é só Shopee.
        // Antes: badge mostrava ML (has_ml_oauth=true, fonte='ml') mesmo
        // sendo o número exibido de origem Shopee.
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->criarMlTokenAtivo($company);
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['empresas'][0];
        $this->assertSame('shopee', $linha['fonte'], 'Fonte tem que ser shopee, não ml/ml_adman, mesmo com mlToken global ativo.');
        $this->assertFalse($linha['has_ml_oauth'], 'has_ml_oauth deve ser false — evita pintar o SVG do ML quando o vínculo é Shopee.');
    }

    #[Test]
    public function test_carteira_individual_empresa_performance_pura_mantem_badge_ml(): void
    {
        // Regressão de guarda: empresa performance pura (sem Shopee) continua
        // com badge ML/adman e has_ml_oauth=true inalterados.
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->criarMlTokenAtivo($cenario['company']);

        AdmanMetric::create([
            'company_id'          => $cenario['company']->id,
            'reference_date'      => now()->startOfMonth()->addDays(2)->toDateString(),
            'revenue'             => 1000.0,
            'ad_spend'            => 100.0,
            'contribution_margin' => 200.0,
        ]);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['empresas'][0];
        $this->assertContains($linha['fonte'], ['ml', 'ml_adman'], 'Empresa performance pura com mlToken ativo mantém badge ML.');
        $this->assertTrue($linha['has_ml_oauth']);
    }

    // ─── Carteira consolidada admin — Carteiras.jsx (Task 2) ─────────────

    #[Test]
    public function test_carteira_consolidada_shopee_only_soma_shopee_metrics_em_curso(): void
    {
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $this->vincularCargo($profissional, 'analista');
        $company = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $profissional->id);
        $this->assertNotNull($card);
        $this->assertEqualsWithDelta(2000.0, $card['total_revenue'], 0.01, '20 dias * 100.0/dia = 2000.0.');
        $this->assertEqualsWithDelta(200.0, $card['total_ad_spend'], 0.01, '20 dias * 10.0/dia = 200.0.');
        $this->assertNull($card['avg_margin'], 'Shopee nunca fornece margem.');
    }

    #[Test]
    public function test_carteira_consolidada_shopee_only_soma_shopee_metrics_bonus_atual(): void
    {
        // last_closed_month a partir de 2026-07-20 -> competencia 2026-06
        // inteira (01..30/06); baseline = janela-de-mesmo-tamanho anterior.
        $admin        = $this->criarAdmin();
        $profissional = User::factory()->create();
        $this->vincularCargo($profissional, 'analista');
        $company = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-06-01', '2026-06-30', 100.0, 10.0);
        $this->semearShopee($company, '2026-05-01', '2026-05-31', 50.0, 5.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own', ['modo' => 'bonus_atual']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $profissional->id);
        $this->assertNotNull($card);
        $this->assertEqualsWithDelta(3000.0, $card['total_revenue'], 0.01, '30 dias * 100.0/dia = 3000.0.');
        $this->assertEqualsWithDelta(300.0, $card['total_ad_spend'], 0.01, '30 dias * 10.0/dia = 300.0.');
    }

    #[Test]
    public function test_carteira_consolidada_desempate_adman_vence_nao_duplica(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->vincularCargo($cenario['analista'], 'analista');
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');

        AdmanMetric::create([
            'company_id'          => $cenario['company']->id,
            'reference_date'      => now()->startOfMonth()->addDays(2)->toDateString(),
            'revenue'             => 1000.0,
            'ad_spend'            => 100.0,
            'contribution_margin' => 200.0,
        ]);
        $this->semearShopee($cenario['company'], '2026-07-01', '2026-07-20', 500.0, 50.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);
        $this->assertSame(1000.0, $card['total_revenue'], 'Faturamento vem SÓ do Adman — 11000.0 somado seria o bug.');
    }

    // ─── Self-view /portfolio → Portfolio/Show.jsx (Task 2) ──────────────

    #[Test]
    public function test_self_view_portfolio_responsavel_shopee_mostra_faturamento_investimento_margem_null(): void
    {
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $this->semearShopee($company, '2026-07-01', '2026-07-20', 100.0, 10.0);
        $this->semearShopee($company, '2026-06-01', '2026-06-20', 50.0, 5.0);

        $response = $this->actingAs($profissional)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['companies']);
        $linha = $props['companies'][0];
        $this->assertEqualsWithDelta(2000.0, $linha['revenue'], 0.01);
        $this->assertEqualsWithDelta(200.0, $linha['ad_spend'], 0.01);
        $this->assertNull($linha['contribution_margin_pct']);
        $this->assertNull($linha['contribution_margin_var_pct']);
        $this->assertEqualsWithDelta(2000.0, $props['summary']['total_revenue'], 0.01);

        // Features ricas (sugadores/PPA/NPS/meta) continuam presentes no
        // payload — SÓ o bloco financeiro por empresa muda nesta fase.
        $this->assertArrayHasKey('performance_profissional', $props);
        $this->assertArrayHasKey('nps_history', $props);
        $this->assertArrayHasKey('sugador_counters', $props);
        $this->assertArrayHasKey('ppa_counters', $props);
        $this->assertArrayHasKey('meta_carteira_calculada', $props);
    }

    #[Test]
    public function test_self_view_portfolio_vinculo_shopee_sem_dados_mostra_null(): void
    {
        $profissional = User::factory()->create();
        $company      = Company::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $response = $this->actingAs($profissional)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['companies'][0];
        $this->assertNull($linha['revenue']);
        $this->assertNull($linha['ad_spend']);
    }
}
