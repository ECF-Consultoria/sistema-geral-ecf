<?php

/**
 * Phase 42 Plan 42-05 — Suite Feature do esconde-sidebar + linkAdsML por origem.
 *
 * Cobre:
 *  - T1/T2: item 'Onboarding ML' NAO aparece em AppLayout.jsx (REQ-42-07 / D-02).
 *           Estrategia: parse direto do arquivo JSX (Inertia nao renderiza
 *           HTML do JSX no SSR primario; o item desaparece pela remocao do
 *           array NAV_TREE).
 *  - T3: rota direta /dev/sugadores-ml-onboarding continua acessivel (admin)
 *        — D-02: rota PERMANECE como ferramenta tecnica.
 *  - T4-T7: integracao linkAdsML via SugadorController::show — payload
 *           Inertia `url_ads` contem URL Mercado Ads quando sugador origem ML,
 *           OU URL legacy Adman caso contrario (REQ-42-09).
 *
 * Comentarios em pt-BR conforme convencao do projeto.
 */

namespace Tests\Feature\Phase42;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SidebarAndAdsLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Data fixa para reference_date consistente.
        Carbon::setTestNow('2026-06-26 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /** Cria admin autenticado. */
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    /** Cria consultor autenticado. */
    private function actingAsConsultor(): User
    {
        $u = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
        $this->actingAs($u);
        return $u;
    }

    /** Cria company minima active=true. */
    private function makeCompany(string $name = 'Empresa Teste'): Company
    {
        return Company::create([
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-' . random_int(1, 999),
            'active'           => true,
        ]);
    }

    /** Helper: cria sugador minimo pendente para a empresa. */
    private function makeSugador(Company $c, array $overrides = []): Sugador
    {
        $base = [
            'company_id'           => $c->id,
            'reference_date'       => now()->toDateString(),
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => 'C1',
            'campaign_name'        => 'Camp 1',
            'adgroup_id'           => 'AG1',
            'adgroup_name'         => 'AG 1',
            'periodo_inicio'       => now()->subDays(30)->toDateString(),
            'periodo_fim'          => now()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 10,
            'impressoes'           => 100,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => Sugador::STATUS_PENDENTE,
            // raw_data default null — sera sobrescrito por overrides.
            'raw_data'             => null,
        ];

        return Sugador::create(array_merge($base, $overrides));
    }

    /** Le AppLayout.jsx (path relativo a base_path). */
    private function appLayoutSource(): string
    {
        $path = base_path('resources/js/Layouts/AppLayout.jsx');
        $this->assertFileExists($path, 'AppLayout.jsx deve existir em resources/js/Layouts.');
        return file_get_contents($path) ?: '';
    }

    // ─────────────────── T1..T3: Sidebar / Rota direta ───────────────────

    /**
     * T1: item 'Onboarding ML' nao aparece em AppLayout.jsx (D-02 / REQ-42-07).
     * Estrategia: parse direto do arquivo (mais robusto que assertion sobre HTML
     * inicial do Inertia, pois o JSX so monta sidebar no client). Valido
     * tambem que o usuario admin nao recebe erro ao carregar pagina autenticada.
     */
    #[Test]
    public function sidebar_nao_contem_onboarding_ml_para_admin(): void
    {
        $this->actingAsAdmin();

        // Faz GET em pagina autenticada qualquer (dashboard) — nao pode quebrar.
        $response = $this->get('/dashboard');
        $this->assertContains($response->getStatusCode(), [200, 302], 'GET /dashboard deve retornar 200 ou 302 (redirect carteira).');

        // Asserts sobre o source do AppLayout: item escondido.
        $src = $this->appLayoutSource();
        $this->assertStringNotContainsString("label: 'Onboarding ML'", $src, "Item 'Onboarding ML' deve estar removido do array de sidebar.");
        $this->assertStringNotContainsString("dev.sugadores_ml_onboarding.index", $src, 'Rota nomeada nao deve aparecer no AppLayout.');
        $this->assertStringContainsString('Phase 42 D-02', $src, 'Comentario de rastreabilidade D-02 deve estar presente.');
    }

    /**
     * T2: idem T1 para consultor — sidebar nao tem o item (excludeRoles ja excluia,
     * mas agora o item nao existe nem mesmo no array bruto).
     */
    #[Test]
    public function sidebar_nao_contem_onboarding_ml_para_consultor(): void
    {
        $this->actingAsConsultor();

        $response = $this->get('/dashboard');
        $this->assertContains($response->getStatusCode(), [200, 302]);

        $src = $this->appLayoutSource();
        $this->assertStringNotContainsString("label: 'Onboarding ML'", $src);
        $this->assertStringNotContainsString('dev.sugadores_ml_onboarding.index', $src);
    }

    /**
     * T3: rota direta /dev/sugadores-ml-onboarding continua acessivel para admin.
     * Confirma D-02: rota PERMANECE registrada como ferramenta tecnica (role:admin).
     * Nao deletamos arquivos, so escondemos o item do menu visual.
     */
    #[Test]
    public function rota_direta_continua_acessivel_para_admin(): void
    {
        $this->actingAsAdmin();

        $response = $this->get('/dev/sugadores-ml-onboarding');

        // O controller existe e responde — pode ser 200 (render Inertia) ou
        // 500 caso o ProviderComparisonService trave em dependencia externa;
        // o ponto critico aqui eh NAO retornar 404 (rota nao foi removida).
        $this->assertNotEquals(404, $response->getStatusCode(), 'Rota /dev/sugadores-ml-onboarding NAO pode ter virado 404 — D-02 manda preservar.');
        $this->assertNotEquals(403, $response->getStatusCode(), 'Admin nao pode receber 403 nessa rota.');
    }

    // ────── T4..T7: linkAdsML via SugadorController::show ──────

    /**
     * T4: sugador com raw_data caracteristico ML (`metrics`, `item_id`, `type`).
     * URL deve apontar para Mercado Ads (product-ads).
     */
    #[Test]
    public function url_ads_aponta_para_mercado_ads_quando_origem_ml(): void
    {
        $this->actingAsAdmin();
        $c = $this->makeCompany('Empresa ML');
        $s = $this->makeSugador($c, [
            'campaign_id' => 'CAMP-ML-1',
            'raw_data'    => [
                'id'      => 'AG1',
                'title'   => 'AG titulo',
                'type'    => 'product_ad',
                'item_id' => 'MLB123',
                'metrics' => ['cost' => 10.0, 'clicks' => 5, 'prints' => 100],
            ],
        ]);

        $response = $this->get("/sugadores/{$s->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Show')
            ->where('url_ads', fn ($u) => is_string($u) && str_contains($u, 'product-ads'))
        );
    }

    /**
     * T5: sugador com raw_data Adman-like (sem chaves caracteristicas ML).
     * URL legacy: /anuncios/campanhas/{campaign_id}.
     */
    #[Test]
    public function url_ads_mantem_formato_adman_quando_raw_nao_eh_ml(): void
    {
        $this->actingAsAdmin();
        $c = $this->makeCompany('Empresa Adman');
        $s = $this->makeSugador($c, [
            'campaign_id' => 'CAMP-ADMAN-1',
            // Payload Adman tipico: chaves diferentes, nao tem `metrics` aninhado
            // nem pair `item_id` + `type`.
            'raw_data'    => [
                'campaignId' => 123,
                'accountId'  => 456,
                'cost'       => 50.0,
            ],
        ]);

        $response = $this->get("/sugadores/{$s->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Show')
            ->where('url_ads', fn ($u) => is_string($u)
                && !str_contains($u, 'product-ads')
                && str_contains($u, '/anuncios/campanhas/CAMP-ADMAN-1')
            )
        );
    }

    /**
     * T6: sugador origem ML sem campaign_id retorna base (sem query string).
     *
     * SKIP: schema `sugadores.campaign_id` e NOT NULL — esse cenario nao acontece
     * em producao. Edge case da implementacao defensiva do linkAdsML coberto
     * indiretamente pelo Unit test (LinkAdsMlUnitTest::link_ml_sem_campaign_id).
     */
    #[Test]
    public function url_ads_sem_campaign_id_retorna_base(): void
    {
        $this->markTestSkipped(
            'sugadores.campaign_id e NOT NULL — cenario impossivel em producao. '
            . 'Branch defensivo coberto pelo Unit test LinkAdsMlUnitTest.'
        );
    }

    /**
     * T7: sugador sem raw_data (legacy) + campaign_id valido — cai no path Adman.
     * Garante backward compatibility (zero regressao).
     */
    #[Test]
    public function url_ads_sem_raw_data_cai_em_adman(): void
    {
        $this->actingAsAdmin();
        $c = $this->makeCompany('Empresa Legacy');
        $s = $this->makeSugador($c, [
            'campaign_id' => 'C123',
            'raw_data'    => null,
        ]);

        $response = $this->get("/sugadores/{$s->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sugadores/Show')
            ->where('url_ads', fn ($u) => is_string($u)
                && !str_contains($u, 'product-ads')
                && str_contains($u, '/anuncios/campanhas/C123')
            )
        );
    }
}
