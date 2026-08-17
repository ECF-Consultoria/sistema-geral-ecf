<?php

namespace Tests\Feature\V16;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 89 Plan 01 — CART-03/04/05.
 *
 * Cobre o coracao da refatoracao de `renderCarteiraProfissional()`: a soma
 * financeira (revenue/margem/ad_spend/tacos) so pode considerar `company_id`
 * com AO MENOS UM vinculo `financial_metrics_eligible=true` do profissional
 * — e nunca duplicar quando o profissional tem 2 vinculos elegiveis+nao
 * elegiveis na MESMA empresa (dedup por `company_id` unico, nao por vinculo).
 *
 * `AdmanMetric` e por-EMPRESA (nao por-servico) — reference_date usa
 * `now()->startOfMonth()->addDays(2)` pra cair sempre dentro da janela do
 * mes em curso, independente do dia em que a suite roda.
 *
 * ISOLAMENTO HTTP (Fase 103 Plan 01 — decisao travada 6): desde que
 * `renderCarteiraProfissional()` passou a chamar
 * `AdmanMetricDiffService::compute()` por empresa elegivel (leitura ao vivo
 * fail-open da Adman), esta suite precisa de `Http::preventStrayRequests()`
 * + fake 404 nos 2 endpoints pra forcar `calculated_fallback` deterministico
 * (sem rede). Nenhum teste aqui asserta `margem_variacao_pct`/
 * `variacao_margem_pct` — os NUMEROS nao mudam, so o isolamento de rede.
 */
class CarteiraFinanceiroElegibilidadeTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
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
     * CART-04 — profissional (papel Analista/'consultor') com vinculo SO
     * Shopee de uma empresa que TAMBEM tem contrato performance ativo com
     * AdmanMetric real. A empresa aparece na carteira (1 vinculo, shopee),
     * mas o financeiro da linha e do resumo nao pode conter o faturamento
     * ML — o profissional nao gerencia ML nessa empresa.
     */
    public function test_shopee_only_analista_nao_herda_financeiro_ml(): void
    {
        $admin = $this->criarAdmin();

        $company     = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->seedAdmanMetric($company->id, 1000.0, 100.0, 200.0);

        $profissional = User::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'consultor');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas'], 'Empresa Shopee-only deve aparecer 1x na carteira.');
        $linha = $props['empresas'][0];
        $this->assertNull($linha['faturamento'], 'Vinculo Shopee-only nao pode herdar faturamento ML.');
        $this->assertNull($linha['margem_contribuicao'], 'Vinculo Shopee-only nao pode herdar margem ML.');
        $this->assertNull($linha['ad_spend'], 'Vinculo Shopee-only nao pode herdar ad_spend ML.');
        $this->assertNull($linha['tacos'], 'Vinculo Shopee-only nao pode herdar tacos ML.');

        $this->assertSame(0.0, $props['resumo']['total_faturamento']);
        $this->assertSame(0.0, $props['resumo']['total_ad_spend']);
    }

    /**
     * CART-04 (papel Estrategista) — mesmo cenario, papel 'estrategista'.
     */
    public function test_shopee_only_estrategista_nao_herda_financeiro_ml(): void
    {
        $admin = $this->criarAdmin();

        $company     = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->seedAdmanMetric($company->id, 1000.0, 100.0, 200.0);

        $profissional = User::factory()->create();
        $this->inserirLinhaShopee($company->id, $profissional->id, 'estrategista');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $linha = $props['empresas'][0];
        $this->assertNull($linha['faturamento']);
        $this->assertNull($linha['margem_contribuicao']);

        $this->assertSame(0.0, $props['resumo']['total_faturamento']);
        $this->assertSame(0.0, $props['resumo']['total_ad_spend']);
    }

    /**
     * CART-05 — o teste mais importante da fase. Profissional responsavel
     * por Performance E Shopee da MESMA empresa (2 vinculos, 1 elegivel).
     * O dedup deve consultar `AdmanMetric` 1x por company_id UNICO — nunca
     * somar 2x so porque o profissional tem 2 vinculos na empresa.
     */
    public function test_ml_e_shopee_mesma_empresa_nao_duplica_financeiro(): void
    {
        $admin = $this->criarAdmin();

        $cenario = $this->criarCenarioMlComResponsaveis();
        // Fase 136 (D-10) — 'adman' só vence com `cust_id` de fato; sem isso
        // o fixture (Company::factory() sem adman_account_id) resolveria
        // 'shopee'. Setado aqui pra preservar o cenário original do teste.
        $cenario['company']->update(['adman_account_id' => 'CUST-136-V16-CFE']);
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');
        $this->seedAdmanMetric($cenario['company']->id, 1000.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas'], 'Empresa com 2 vinculos do mesmo profissional deve aparecer 1x.');
        $this->assertSame(1000.0, $props['resumo']['total_faturamento'], 'Faturamento NUNCA pode duplicar (2000.0 seria o bug).');
    }

    /**
     * Regressao — analista ML comum (sem Shopee) continua recebendo o
     * financeiro normalmente, incluindo os NOVOS campos ad_spend/tacos
     * (CART-03) que nao existiam antes desta fase.
     */
    public function test_analista_ml_comum_continua_recebendo_financeiro_com_ad_spend_tacos(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->seedAdmanMetric($cenario['company']->id, 1000.0, 100.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $linha = $props['empresas'][0];
        $this->assertSame(1000.0, $linha['faturamento']);
        $this->assertSame(100.0, $linha['ad_spend']);
        // Cache Adman e cache-only em teste (miss) — fallback ads/rev*100.
        $this->assertSame(10.0, $linha['tacos']);
    }

    /**
     * CART-03 — empresas mistas na mesma carteira: empresa A elegivel
     * (performance) + empresa B so-shopee. B nao pode contaminar o total.
     *
     * Tambem cobre o warning 3 do plan-checker (assert dedicado de
     * `resumo.empresas_sem_margem`): A e ELEGIVEL com contribution_margin
     * null (problema de sync de verdade — CONTA), B e Shopee-only com margem
     * null POR DESIGN (sem fonte financeira — NAO conta). Sem o gate por
     * elegibilidade no controller, o contador seria 2 e este teste falha.
     */
    public function test_empresas_mistas_carteira_so_soma_elegiveis(): void
    {
        $admin = $this->criarAdmin();

        $analista = User::factory()->create();

        // Empresa A — elegivel (performance), com AdmanMetric mas SEM
        // contribution_margin (margem null = falha real de sync/reporte).
        $companyA    = Company::factory()->create(['adman_account_id' => 'ACC-A-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($companyA->id, $servicoPerf, true);
        $this->inserirPivot($companyA->id, $analista->id, 'consultor', $servicoPerf);
        $this->seedAdmanMetric($companyA->id, 1000.0, 100.0);

        // Empresa B — vinculo SO shopee (margem null por design, sem fonte).
        $companyB = Company::factory()->create(['adman_account_id' => 'ACC-B-' . uniqid()]);
        $this->inserirLinhaShopee($companyB->id, $analista->id, 'consultor');
        $this->seedAdmanMetric($companyB->id, 500.0, 50.0);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $analista));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(2, $props['empresas'], 'As 2 empresas devem aparecer na listagem (visibilidade nao e gated).');
        $this->assertSame(1000.0, $props['resumo']['total_faturamento'], 'Empresa B (so-shopee) nao pode contaminar o total.');
        $this->assertSame(100.0, $props['resumo']['total_ad_spend'], 'Empresa B (so-shopee) nao pode contaminar o total de ad_spend.');

        // Warning 3 do plan-checker — empresas_sem_margem conta SO elegiveis:
        // A (elegivel, margem null = sync) CONTA; B (Shopee-only, margem null
        // por design) NAO conta. Implementacao sem o filtro devolveria 2.
        $this->assertSame(1, $props['resumo']['empresas_sem_margem'], 'So a empresa ELEGIVEL com margem null conta; Shopee-only (sem fonte por design) nao entra no contador.');
    }
}
