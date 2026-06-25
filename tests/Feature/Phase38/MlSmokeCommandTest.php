<?php

// Phase 38 Plan 38-02 — Testes Feature do comando `sugadores:ml-smoke`.
// Cobre o comando Artisan que orquestra MercadoLivreAdsService (Plan 01) + grava
// fixture JSON + imprime relatório CLI. Todos os tests usam Http::fake +
// Storage::fake para isolar de rede e do filesystem real.

namespace Tests\Feature\Phase38;

use App\Models\Company;
use App\Models\MlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MlSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bloqueia qualquer request HTTP que escape do Http::fake — garante que
        // nenhum teste vaze chamada real para api.mercadolibre.com.
        Http::preventStrayRequests();

        // Isola a gravação da fixture do filesystem real (storage/app/).
        Storage::fake('local');
    }

    /**
     * Cria uma Company que SE PARECE com a Bymobille - Teste, com MlToken ativo.
     * Usada nos tests 3 e 4 (path feliz e falha parcial).
     */
    private function makeBymobileLike(): Company
    {
        $company = Company::create([
            'name'        => 'ByMobille - Teste',
            'cnpj'        => '99999999000099',
            'active'      => true,
            'ml_store_id' => '465723451',
        ]);

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token-xyz',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        return $company->fresh();
    }

    /**
     * Configura Http::fake com payloads realistas para o path feliz:
     * advertiser (1) → campanhas (2) → ads/items (3 com métricas).
     */
    private function fakeHappyPath(int $advertiserId = 71098): void
    {
        Http::fake([
            // Discovery do advertiser_id (endpoint comprovado Phase 20).
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => $advertiserId,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),

            // Listagem de campanhas (endpoint comprovado Phase 20).
            '*/product_ads/campaigns/search*' => Http::response([
                'results' => [
                    [
                        'id'      => 'CMP-001',
                        'name'    => 'Campanha Lançamento',
                        'status'  => 'active',
                        'metrics' => [
                            'cost'           => 150.0,
                            'clicks'         => 200,
                            'prints'         => 5000,
                            'total_amount'   => 600.0,
                            'acos'           => 25.0,
                            'roas'           => 4.0,
                            'cpc'            => 0.75,
                            'units_quantity' => 12,
                        ],
                    ],
                    [
                        'id'      => 'CMP-002',
                        'name'    => 'Catálogo Premium',
                        'status'  => 'active',
                        'metrics' => [
                            'cost'           => 75.0,
                            'clicks'         => 80,
                            'prints'         => 2000,
                            'total_amount'   => 300.0,
                            'acos'           => 25.0,
                            'roas'           => 4.0,
                            'cpc'            => 0.94,
                            'units_quantity' => 6,
                        ],
                    ],
                ],
                'paging' => ['total' => 2],
            ], 200),

            // Listagem de ads/items (endpoint CANDIDATO — smoke confirma shape).
            '*/product_ads/items*' => Http::response([
                'results' => [
                    [
                        'item_id'         => 'MLB1234567',
                        'title'           => 'Produto teste A',
                        'thumbnail'       => 'https://http2.mlstatic.com/D_thumb_A.jpg',
                        'type'            => 'product',
                        'catalog_listing' => false,
                        'campaign_id'     => 'CMP-001',
                        'metrics'         => [
                            'cost'           => 50.0,
                            'clicks'         => 80,
                            'prints'         => 2000,
                            'total_amount'   => 250.0,
                            'acos'           => 20.0,
                            'roas'           => 5.0,
                            'cpc'            => 0.625,
                            'ctr'            => 4.0,
                            'units_quantity' => 5,
                        ],
                    ],
                    [
                        'item_id'         => 'MLB7654321',
                        'title'           => 'Produto teste B',
                        'thumbnail'       => 'https://http2.mlstatic.com/D_thumb_B.jpg',
                        'type'            => 'product',
                        'catalog_listing' => true,
                        'campaign_id'     => 'CMP-001',
                        'metrics'         => [
                            'cost'           => 30.0,
                            'clicks'         => 40,
                            'prints'         => 1000,
                            'total_amount'   => 100.0,
                            'acos'           => 30.0,
                            'roas'           => 3.33,
                            'cpc'            => 0.75,
                            'ctr'            => 4.0,
                            'units_quantity' => 2,
                        ],
                    ],
                    [
                        'item_id'         => 'MLB1112223',
                        'title'           => 'Produto teste C',
                        'thumbnail'       => 'https://http2.mlstatic.com/D_thumb_C.jpg',
                        'type'            => 'product',
                        'catalog_listing' => false,
                        'campaign_id'     => 'CMP-002',
                        'metrics'         => [
                            'cost'           => 20.0,
                            'clicks'         => 30,
                            'prints'         => 800,
                            'total_amount'   => 80.0,
                            'acos'           => 25.0,
                            'roas'           => 4.0,
                            'cpc'            => 0.67,
                            'ctr'            => 3.75,
                            'units_quantity' => 2,
                        ],
                    ],
                ],
                'paging' => ['total' => 3],
            ], 200),
        ]);
    }

    // ─────────── Test 1: empresa inexistente → exit code != 0 ───────────

    public function test_command_fails_when_company_id_does_not_exist(): void
    {
        $this->artisan('sugadores:ml-smoke', ['--company' => 999999, '--days' => 30])
            ->expectsOutputToContain('Empresa não encontrada')
            ->assertExitCode(1);
    }

    // ─────────── Test 2: empresa sem mlToken → exit code != 0 ───────────

    public function test_command_fails_when_company_has_no_ml_token(): void
    {
        // Cria Company SEM MlToken vinculado.
        $company = Company::create([
            'name'   => 'Empresa Sem Token',
            'cnpj'   => '11111111000111',
            'active' => true,
        ]);

        $this->artisan('sugadores:ml-smoke', ['--company' => $company->id])
            ->expectsOutputToContain('sem token ML')
            ->assertExitCode(1);
    }

    // ─────────── Test 3: path feliz grava fixture com shape correto ───────────

    public function test_command_writes_fixture_with_expected_shape_when_all_endpoints_succeed(): void
    {
        $company = $this->makeBymobileLike();
        $this->fakeHappyPath(71098);

        // Roda o comando — deve completar com sucesso.
        $this->artisan('sugadores:ml-smoke', ['--company' => $company->id, '--days' => 30])
            ->assertExitCode(0);

        // Verifica que a fixture foi gravada no disco fake.
        $today        = now()->toDateString();
        $relativePath = "sugadores/ml-smoke/{$company->id}-{$today}.json";

        Storage::disk('local')->assertExists($relativePath);

        $contents = Storage::disk('local')->get($relativePath);
        $fixture  = json_decode($contents, true);

        // ─── Chaves obrigatórias da fixture (schema §CONTEXT.md Fixture JSON) ───
        $this->assertIsArray($fixture);
        $this->assertArrayHasKey('company_id', $fixture);
        $this->assertArrayHasKey('company_name', $fixture);
        $this->assertArrayHasKey('run_at', $fixture);
        $this->assertArrayHasKey('days_window', $fixture);
        $this->assertArrayHasKey('advertiser', $fixture);
        $this->assertArrayHasKey('campaigns', $fixture);
        $this->assertArrayHasKey('ads', $fixture);
        $this->assertArrayHasKey('metrics', $fixture);
        $this->assertArrayHasKey('report', $fixture);

        // ─── Conteúdo esperado ───
        $this->assertSame($company->id, $fixture['company_id']);
        $this->assertSame('ByMobille - Teste', $fixture['company_name']);
        $this->assertSame(30, $fixture['days_window']);
        $this->assertSame(71098, $fixture['advertiser']['advertiser_id']);
        $this->assertSame(2, $fixture['campaigns']['count']);

        // ─── Relatório com endpoints_ok não-vazio ───
        $this->assertIsArray($fixture['report']['endpoints_ok']);
        $this->assertNotEmpty($fixture['report']['endpoints_ok']);

        // ─── Campos do contrato §2.3 presentes (vindos das métricas dos ads) ───
        $this->assertContains('clicks', $fixture['report']['contract_fields_present']);
        $this->assertContains('impressions', $fixture['report']['contract_fields_present']);
        $this->assertContains('investment', $fixture['report']['contract_fields_present']);
        $this->assertContains('revenue', $fixture['report']['contract_fields_present']);

        // ─── ANTI-LEAK: access_token NUNCA pode aparecer na fixture (T-38-06) ───
        $this->assertStringNotContainsString('fake-token', $contents);
    }

    // ─────────── Test 4: relatório CLI contém seções obrigatórias mesmo em falha parcial ───────────

    public function test_command_report_prints_required_sections_when_endpoint_fails(): void
    {
        $company = $this->makeBymobileLike();

        // Discovery OK + campanhas OK + ads/items retorna 404 (endpoint candidato).
        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),

            '*/product_ads/campaigns/search*' => Http::response([
                'results' => [
                    [
                        'id'      => 'CMP-001',
                        'name'    => 'Campanha X',
                        'status'  => 'active',
                        'metrics' => ['cost' => 10.0, 'clicks' => 5, 'prints' => 100],
                    ],
                ],
                'paging' => ['total' => 1],
            ], 200),

            // Endpoint CANDIDATO retorna 404 — smoke é diagnóstico, segue até o fim.
            '*/product_ads/items*' => Http::response(['message' => 'not_found'], 404),
        ]);

        $this->artisan('sugadores:ml-smoke', ['--company' => $company->id, '--days' => 30])
            ->expectsOutputToContain('endpoints_failed')
            ->expectsOutputToContain('blockers')
            // Smoke é diagnóstico — endpoint falhado vira blocker, não exit code != 0.
            ->assertExitCode(0);
    }
}
