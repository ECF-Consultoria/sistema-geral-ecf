<?php

namespace Tests\Feature\V16;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 90 Plan 01 — CART-06/CART-07 (backend).
 *
 * Cobre a mesma correção de elegibilidade financeira multi-serviço da Fase 89
 * (`renderCarteiraProfissional`), agora aplicada em `renderCarteirasConsolidadas()`
 * (visão admin de cards por profissional) + o filtro `?contexto=` funcional nas
 * DUAS telas (consolidada e individual).
 *
 * Diferente de `CarteiraFinanceiroElegibilidadeTest` (Fase 89, escopo individual),
 * esta suite precisa que o profissional apareça na LISTAGEM de cards — o que exige
 * vínculo em `user_setores` com cargo analista/estrategista (helpers locais
 * `vincularCargo()`, mesmo padrão de `DesempenhoScoreSnapshotTest::setUp()`).
 *
 * ISOLAMENTO HTTP (Fase 103 Plan 01 — decisao travada 6): os testes 9/10
 * desta suite alcancam `route('portfolio.show', ...)`
 * (`renderCarteiraProfissional`), que agora chama
 * `AdmanMetricDiffService::compute()` por empresa elegivel (leitura ao vivo
 * fail-open da Adman). `Http::preventStrayRequests()` + fake 404 nos 2
 * endpoints forcam `calculated_fallback` deterministico (sem rede) pra toda
 * a suite. Nenhum teste aqui asserta `margem_variacao_pct`/`avg_margin` —
 * os NUMEROS nao mudam, so o isolamento de rede.
 */
class CarteirasConsolidadasContextoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-90',
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

    /**
     * Vincula um user a um cargo (analista/estrategista) via `user_setores`
     * — condição obrigatória pra ele aparecer na listagem de
     * `renderCarteirasConsolidadas()` (whereExists em user_setores+cargos).
     */
    private function vincularCargo(User $u, string $cargoSlug): void
    {
        $cargoId = $cargoSlug === 'estrategista' ? $this->cargoEstrategistaId : $this->cargoAnalistaId;

        DB::table('user_setores')->insert([
            'user_id'    => $u->id,
            'setor_id'   => $this->setorId,
            'cargo_id'   => $cargoId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function dataNoMesCorrente(): string
    {
        return now()->startOfMonth()->addDays(2)->toDateString();
    }

    private function seedAdmanMetric(int $companyId, float $revenue, float $adSpend = 0.0, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'           => $companyId,
            'reference_date'       => $this->dataNoMesCorrente(),
            'revenue'              => $revenue,
            'ad_spend'             => $adSpend,
            'contribution_margin'  => $margem,
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
     * Monta o cenário-chave travado no plano: Luiz (estrategista Performance)
     * + Felipe (estrategista Shopee) da MESMA empresa X, com AdmanMetric real.
     *
     * @return array{company: Company, luiz: User, felipe: User}
     */
    private function criarCenarioLuizFelipe(): array
    {
        $company     = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->seedAdmanMetric($company->id, 1000.0, 100.0, 200.0);

        $luiz   = User::factory()->create(['name' => 'Luiz']);
        $felipe = User::factory()->create(['name' => 'Felipe']);

        $this->inserirPivot($company->id, $luiz->id, 'estrategista', $servicoPerf);
        $this->vincularCargo($luiz, 'estrategista');

        $this->inserirLinhaShopee($company->id, $felipe->id, 'estrategista');
        $this->vincularCargo($felipe, 'estrategista');

        return ['company' => $company, 'luiz' => $luiz, 'felipe' => $felipe];
    }

    /**
     * Test 1 — CART-06 ponto 2 (o cenário-chave travado pelo usuário).
     */
    public function test_shopee_only_nao_herda_financeiro_ml_na_consolidada(): void
    {
        $admin    = $this->criarAdmin();
        $cenario  = $this->criarCenarioLuizFelipe();

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $cardLuiz   = $this->localizarCard($props['user_portfolios'], $cenario['luiz']->id);
        $cardFelipe = $this->localizarCard($props['user_portfolios'], $cenario['felipe']->id);

        $this->assertNotNull($cardLuiz, 'Card do Luiz deve existir.');
        $this->assertNotNull($cardFelipe, 'Card do Felipe deve existir (nao some).');

        $this->assertSame(1000.0, $cardLuiz['total_revenue']);

        $this->assertSame(0.0, $cardFelipe['total_revenue'], 'Felipe (so-Shopee) nao pode herdar faturamento ML.');
        $this->assertNull($cardFelipe['avg_tacos']);
        $this->assertNull($cardFelipe['avg_margin']);

        $this->assertSame(1, $props['totais']['empresas_unicas'], 'Empresa X compartilhada conta 1x na uniao.');
        $this->assertSame(2, $props['totais']['vinculos_servico']);
    }

    /**
     * Test 2 — CART-06 ponto 1: mesmo profissional, ML+Shopee na MESMA
     * empresa. Nao pode duplicar financeiro.
     */
    public function test_card_ml_e_shopee_mesma_empresa_nao_duplica_financeiro(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');
        $this->vincularCargo($cenario['analista'], 'analista');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);

        $this->assertSame(1000.0, $card['total_revenue'], '2000.0 seria o bug de duplicacao.');
        $this->assertSame(1, $card['empresas_unicas']);
        $this->assertSame(2, $card['vinculos_servico']);
    }

    /**
     * Test 3 — Regressao: analista so-ML comum continua recebendo financeiro
     * corretamente.
     */
    public function test_regressao_card_analista_ml_comum(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->vincularCargo($cenario['analista'], 'analista');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0, 100.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);

        $this->assertSame(1000.0, $card['total_revenue']);
        $this->assertSame(100.0, $card['total_ad_spend']);
        // Cache Adman e cache-only em teste (miss) — fallback ads/rev*100.
        $this->assertSame(10.0, $card['avg_tacos']);
        $this->assertSame(1, $card['empresas_unicas']);
        $this->assertSame(1, $card['vinculos_financeiros']);
    }

    /**
     * Test 4 — Filtro ?contexto=shopee esconde card sem vinculo Shopee
     * (Luiz some), Felipe permanece com financeiro zerado.
     */
    public function test_filtro_contexto_shopee_esconde_card_sem_vinculo_shopee(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioLuizFelipe();

        $response = $this->actingAs($admin)->get(route('portfolio.own', ['contexto' => 'shopee']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $cardLuiz   = $this->localizarCard($props['user_portfolios'], $cenario['luiz']->id);
        $cardFelipe = $this->localizarCard($props['user_portfolios'], $cenario['felipe']->id);

        $this->assertNull($cardLuiz, 'Luiz (so-Performance) deve desaparecer no filtro Shopee.');
        $this->assertNotNull($cardFelipe);
        $this->assertSame(0.0, $cardFelipe['total_revenue']);

        foreach ($props['user_portfolios'] as $card) {
            $this->assertLessThanOrEqual(0.0, $card['total_revenue'], 'Filtro Shopee NUNCA mostra R$ de ML.');
        }
    }

    /**
     * Test 5 — Filtro ?contexto=performance esconde card so-Shopee (Felipe
     * some), Luiz permanece com financeiro completo.
     */
    public function test_filtro_contexto_performance_esconde_card_so_shopee(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioLuizFelipe();

        $response = $this->actingAs($admin)->get(route('portfolio.own', ['contexto' => 'performance']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $cardLuiz   = $this->localizarCard($props['user_portfolios'], $cenario['luiz']->id);
        $cardFelipe = $this->localizarCard($props['user_portfolios'], $cenario['felipe']->id);

        $this->assertNotNull($cardLuiz);
        $this->assertSame(1000.0, $cardLuiz['total_revenue']);
        $this->assertNull($cardFelipe, 'Felipe (so-Shopee) deve desaparecer no filtro Performance.');
    }

    /**
     * Test 6 — Valor invalido de ?contexto= cai em "todos" (whitelist).
     */
    public function test_contexto_invalido_cai_em_todos(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioLuizFelipe();

        $response = $this->actingAs($admin)->get(route('portfolio.own', ['contexto' => "hacker'--"]));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame('todos', $props['contexto']);
        $this->assertNotNull($this->localizarCard($props['user_portfolios'], $cenario['luiz']->id));
        $this->assertNotNull($this->localizarCard($props['user_portfolios'], $cenario['felipe']->id));
    }

    /**
     * Test 7 — Totais do topo usam UNIAO de company_id, nunca soma de
     * empresas_unicas entre cards (empresa compartilhada por 2 profissionais
     * conta 1 vez).
     */
    public function test_contador_agregado_usa_uniao_de_company_id(): void
    {
        $admin = $this->criarAdmin();

        $company     = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->seedAdmanMetric($company->id, 1000.0);

        $luiz  = User::factory()->create(['name' => 'Luiz']);
        $maria = User::factory()->create(['name' => 'Maria']);

        $this->inserirPivot($company->id, $luiz->id, 'estrategista', $servicoPerf);
        $this->vincularCargo($luiz, 'estrategista');

        $this->inserirPivot($company->id, $maria->id, 'consultor', $servicoPerf);
        $this->vincularCargo($maria, 'analista');

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertNotNull($this->localizarCard($props['user_portfolios'], $luiz->id));
        $this->assertNotNull($this->localizarCard($props['user_portfolios'], $maria->id));

        $this->assertSame(1, $props['totais']['empresas_unicas'], 'Empresa X compartilhada por 2 profissionais conta 1 vez.');
        $this->assertSame(2, $props['totais']['vinculos_servico']);
    }

    /**
     * Test 8 — source_counts (flag unified_metrics_enabled) nao conta
     * empresa Shopee-only como fonte do profissional.
     */
    public function test_source_counts_nao_conta_empresa_shopee_only(): void
    {
        config(['metrics.unified_metrics_enabled' => true]);

        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioLuizFelipe();

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $cardLuiz   = $this->localizarCard($props['user_portfolios'], $cenario['luiz']->id);
        $cardFelipe = $this->localizarCard($props['user_portfolios'], $cenario['felipe']->id);

        $this->assertArrayHasKey('source_counts', $cardFelipe);
        $somaFelipe = array_sum($cardFelipe['source_counts']);
        $this->assertSame(0, $somaFelipe, 'Empresa Shopee-only nao e fonte financeira do Felipe.');

        $this->assertArrayHasKey('source_counts', $cardLuiz);
        $this->assertSame(1, $cardLuiz['source_counts']['adman'], 'Empresa X sem mlToken -> so-adman.');
    }

    /**
     * Test 9 — Filtro ?contexto= funciona tambem na carteira individual
     * (renderCarteiraProfissional).
     */
    public function test_filtro_contexto_funciona_na_carteira_individual(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', [$cenario['analista'], 'contexto' => 'shopee']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $servicos = $props['empresas'][0]['servicos'];
        $this->assertCount(1, $servicos, 'So o vinculo shopee deve aparecer no filtro shopee.');
        $this->assertSame('shopee', $servicos[0]['setor']);

        $this->assertSame(0.0, $props['resumo']['total_faturamento']);
    }

    /**
     * Test 10 — resumo individual expoe os novos contadores de vinculos
     * (sem filtro — default "todos").
     */
    public function test_resumo_individual_expoe_contadores_de_vinculos(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertSame(2, $props['resumo']['vinculos_servico']);
        $this->assertSame(1, $props['resumo']['vinculos_sem_fonte_financeira']);
        $this->assertSame('todos', $props['contexto']);
    }

    /**
     * Test 11 — Plan 90-02: payload da consolidada NAO emite mais o alias
     * legado `companies_count` (removido no mesmo commit que troca o .jsx).
     * RED antes da remocao do alias em `renderCarteirasConsolidadas()`,
     * GREEN depois.
     */
    public function test_payload_consolidada_nao_emite_companies_count(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->vincularCargo($cenario['analista'], 'analista');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.own'));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $card = $this->localizarCard($props['user_portfolios'], $cenario['analista']->id);
        $this->assertNotNull($card);

        $this->assertArrayNotHasKey('companies_count', $card, 'Alias legado deve ter sido removido do payload.');
        $this->assertArrayHasKey('empresas_unicas', $card);
    }
}
