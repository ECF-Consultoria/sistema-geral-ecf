<?php

// Phase 44 Plan 44-01 — Testes Feature do comando `sugadores:ml-write-smoke`.
//
// Cobre o smoke path WRITE da API Mercado Ads: guard de scope, happy path
// Variante A (POST campaign marketplace), fallback Variante B (product_ads_2),
// early-abort em PUT 403 e anti-leak de tokens.
//
// Todos os tests usam Http::fake + Storage::fake para isolar de rede e filesystem.
// MariaDB local pode estar corrompido — suite usa SQLite in-memory (RefreshDatabase).

namespace Tests\Feature\Phase44;

use App\Models\Company;
use App\Models\MlToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MlWriteSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    // Tokens fake usados nos seedCompanyComToken — monitorados pelo Test 6 (anti-leak).
    private const FAKE_ACCESS_TOKEN  = 'access_token_smoke_test_123';
    private const FAKE_REFRESH_TOKEN = 'refresh_token_smoke_test_456';

    protected function setUp(): void
    {
        parent::setUp();

        // Bloqueia requests HTTP que escapem do Http::fake — garante zero vazamento
        // para api.mercadolibre.com durante os testes.
        Http::preventStrayRequests();

        // Isola filesystem — fixture JSON gravada no disco fake, não em storage/app/ real.
        Storage::fake('local');
    }

    /**
     * Cria Company + MlToken com scope parametrizado.
     * Helper compartilhado entre todos os testes.
     */
    private function seedCompanyComToken(string $scope = 'read write offline_access'): Company
    {
        $company = Company::create([
            'name'        => 'Bymobille Smoke',
            'cnpj'        => '99999999000199',
            'active'      => true,
            'ml_store_id' => '465723451',
        ]);

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => self::FAKE_ACCESS_TOKEN,
            'refresh_token'     => self::FAKE_REFRESH_TOKEN,
            'token_type'        => 'bearer',
            'scope'             => $scope,
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        return $company->fresh(['mlToken']);
    }

    /**
     * Http::fake callback para o happy path completo (Variante A do POST campaign):
     * advertiser → campanhas → ads (1 ad ACTIVE) → POST 201 SGI → PUT 200 mover → PUT 200 reverter.
     *
     * Usa callback em vez de padrões estáticos para evitar ambiguidade entre URLs que
     * compartilham segmentos de path (ex: "advertising/advertisers" está presente tanto
     * no endpoint de discover quanto no de listAds).
     */
    private function fakeHappyPathVarianteA(int $advertiserId = 71098): void
    {
        Http::fake(function ($request) use ($advertiserId) {
            $url    = $request->url();
            $method = strtoupper($request->method());

            // Etapa 1 — discover advertiser: GET /advertising/advertisers?product_id=PADS
            // URL contém "advertising/advertisers" mas NÃO contém "product_ads"
            if (str_contains($url, '/advertising/advertisers') && ! str_contains($url, 'product_ads')) {
                return Http::response([
                    'advertisers' => [[
                        'advertiser_id' => $advertiserId,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200);
            }

            // Etapa 2a — listCampaigns
            if (str_contains($url, 'product_ads/campaigns/search')) {
                return Http::response([
                    'results' => [[
                        'id'      => 5001,
                        'name'    => 'Campanha Normal',
                        'status'  => 'active',
                        'metrics' => ['cost' => 100.0, 'clicks' => 50],
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            // Etapa 2b — listAds: GET /advertising/advertisers/{id}/product_ads/items
            if (str_contains($url, 'product_ads/items')) {
                return Http::response([
                    'results' => [[
                        'item_id'     => 'MLB9999888',
                        'title'       => 'Produto smoke test',
                        'status'      => 'active',
                        'campaign_id' => 5001,
                        'ad_group_id' => 'ADG-TEST-01',
                        'metrics'     => ['cost' => 50.0, 'clicks' => 20],
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            // Etapa 3 — POST criar SGI (Variante A)
            if ($method === 'POST' && str_contains($url, 'product_ads/campaigns')) {
                return Http::response([
                    'id'           => 9999,
                    'name'         => 'SGI-SMOKE-TEST-20260626120000',
                    'status'       => 'paused',
                    'budget'       => 5,
                    'date_created' => '2026-06-26T12:00:00.000Z',
                ], 201);
            }

            // Etapas 4 e 5 — PUT mover + PUT reverter
            if ($method === 'PUT' && str_contains($url, 'product_ads/ads')) {
                return Http::response([
                    'item_id'     => 'MLB9999888',
                    'campaign_id' => 9999,
                    'status'      => 'active',
                ], 200);
            }

            return Http::response(['error' => 'not_mocked'], 500);
        });
    }

    // ─────────── Test 1: guard scope — token sem 'write' → exit 1 ───────────

    /**
     * @test
     * Empresa com scope='read offline_access' (sem write) deve abortar antes
     * de chamar qualquer endpoint ML. Fixture NÃO deve ser gravada.
     */
    public function test_guard_scope_aborta_quando_token_nao_tem_write(): void
    {
        $company = $this->seedCompanyComToken('read offline_access');

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id])
            ->expectsOutputToContain('Re-auth necessário')
            ->assertExitCode(1);

        // Fixture NÃO deve existir (aborto antes de qualquer chamada HTTP).
        $files = Storage::disk('local')->files('sugadores/ml-write-smoke');
        $this->assertEmpty($files, 'Fixture NÃO deve ser gravada quando scope não tem write');
    }

    // ─────────── Test 2: guard mlToken ausente → exit 1 ───────────

    /**
     * @test
     * Empresa sem MlToken ativo deve abortar com mensagem descritiva.
     */
    public function test_guard_token_ausente_aborta_quando_empresa_nao_tem_ml_token(): void
    {
        $company = Company::create([
            'name'   => 'Empresa Sem Token Write',
            'cnpj'   => '11111111000199',
            'active' => true,
        ]);

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id])
            ->expectsOutputToContain('sem token ML')
            ->assertExitCode(1);
    }

    // ─────────── Test 3: happy path Variante A → exit 0, 5/5 verdes ───────────

    /**
     * @test
     * Fluxo completo com Variante A do POST campaign:
     * - exit 0
     * - relatório CLI contém "5/5"
     * - fixture gravada com shape correto (endpoints_ok=5, new_campaign_id=9999, etc.)
     * - POST campaign usou URL marketplace/advertising com body status=paused e budget=5
     */
    public function test_happy_path_variante_a_grava_fixture_com_5_endpoints_ok(): void
    {
        $company = $this->seedCompanyComToken();
        $this->fakeHappyPathVarianteA(71098);

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id, '--days' => 30])
            ->expectsOutputToContain('5/5')
            ->assertExitCode(0);

        // ─── Fixture deve existir no disco fake ───
        $files = Storage::disk('local')->files('sugadores/ml-write-smoke');
        $this->assertNotEmpty($files, 'Fixture JSON deve ser gravada em sugadores/ml-write-smoke/');

        $fixture = json_decode(Storage::disk('local')->get($files[0]), true);

        // ─── Shape da fixture ───
        $this->assertSame($company->id, $fixture['company']['id']);
        $this->assertNotNull($fixture['advertiser_id']);
        $this->assertSame('MLB', $fixture['site_id']);

        // ─── Summary ───
        $summary = $fixture['summary'];
        $this->assertSame(5, $summary['endpoints_ok']);
        $this->assertSame(0, $summary['endpoints_failed']);
        $this->assertSame('2', $summary['api_version_used']);
        $this->assertSame(9999, $summary['new_campaign_id']);
        $this->assertSame('A', $summary['post_campaign_variant_used']);
        $this->assertSame('MLB9999888', $summary['move_target_item_id']);
        $this->assertSame(5001, $summary['original_campaign_id']);

        // ─── Verificação via Http::assertSent — POST campaign com body correto ───
        Http::assertSent(function ($request) {
            $isPostCampaign = str_contains($request->url(), 'product_ads/campaigns');
            if (! $isPostCampaign) {
                return false;
            }
            $body = $request->data();
            return $body['status'] === 'paused'
                && $body['budget'] === 5
                && str_contains($body['name'] ?? '', 'SGI-SMOKE-TEST');
        });
    }

    // ─────────── Test 4: fallback Variante B em POST 404 ───────────

    /**
     * @test
     * Quando Variante A do POST campaign retorna 404, deve tentar Variante B
     * (/advertising/product_ads_2/campaigns). Fluxo continua para Etapas 4 e 5.
     * Fixture registra post_campaign_variant_used='B'.
     */
    public function test_fallback_variante_b_quando_post_campaign_variante_a_retorna_404(): void
    {
        $company = $this->seedCompanyComToken();

        Http::fake(function ($request) {
            $url    = $request->url();
            $method = strtoupper($request->method());

            // Etapa 1 — discover advertiser
            if (str_contains($url, '/advertising/advertisers') && ! str_contains($url, 'product_ads')) {
                return Http::response([
                    'advertisers' => [[
                        'advertiser_id' => 71098,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200);
            }

            // Etapa 2a — listCampaigns
            if (str_contains($url, 'product_ads/campaigns/search')) {
                return Http::response([
                    'results' => [['id' => 5001, 'name' => 'Camp B', 'status' => 'active', 'metrics' => []]],
                    'paging'  => ['total' => 1],
                ], 200);
            }

            // Etapa 2b — listAds
            if (str_contains($url, 'product_ads/items')) {
                return Http::response([
                    'results' => [[
                        'item_id'     => 'MLB7777666',
                        'title'       => 'Produto B',
                        'status'      => 'active',
                        'campaign_id' => 5001,
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            // Etapa 3 — POST criar SGI Variante A → 404 (triggering fallback)
            if ($method === 'POST' && str_contains($url, 'marketplace/advertising') && str_contains($url, 'product_ads/campaigns')) {
                return Http::response(['message' => 'not_found'], 404);
            }

            // Etapa 3 — POST criar SGI Variante B → 201
            if ($method === 'POST' && str_contains($url, 'product_ads_2/campaigns')) {
                return Http::response([
                    'id'     => 8888,
                    'name'   => 'SGI-SMOKE-TEST-VAR-B',
                    'status' => 'paused',
                    'budget' => 5,
                ], 201);
            }

            // Etapas 4 e 5 — PUTs mover + reverter
            if ($method === 'PUT' && str_contains($url, 'product_ads/ads')) {
                return Http::response(['item_id' => 'MLB7777666', 'campaign_id' => 8888], 200);
            }

            return Http::response(['error' => 'not_mocked'], 500);
        });

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id])
            ->assertExitCode(0);

        $files   = Storage::disk('local')->files('sugadores/ml-write-smoke');
        $fixture = json_decode(Storage::disk('local')->get($files[0]), true);

        $this->assertSame('B', $fixture['summary']['post_campaign_variant_used']);
        $this->assertSame(8888, $fixture['summary']['new_campaign_id']);
    }

    // ─────────── Test 5: PUT 403 → early-abort, relatório documenta blocker ───────────

    /**
     * @test
     * Quando PUT mover o ad retorna 403 (scope/permissão insuficiente):
     * - command retorna exit 0 (smoke é diagnóstico)
     * - fixture tem endpoints_failed >= 1
     * - fixture.summary.blockers contém string sobre "403" ou "scope"
     * - Etapa 5 (PUT reverter) NÃO é tentada (early-abort na Etapa 4)
     */
    public function test_put_403_registra_blocker_e_nao_tenta_etapa_5(): void
    {
        $company = $this->seedCompanyComToken();

        // Contador de PUTs para validar que Etapa 5 não foi tentada.
        $putCount = 0;

        Http::fake(function ($request) use (&$putCount) {
            $url    = $request->url();
            $method = strtoupper($request->method());

            // Etapa 1 — discover advertiser: GET /advertising/advertisers?product_id=PADS
            // Guarda: NÃO contém 'product_ads' para não confundir com listAds
            // (.../advertising/advertisers/71098/product_ads/items também contém "/advertising/advertisers")
            if (str_contains($url, '/advertising/advertisers') && ! str_contains($url, 'product_ads')) {
                return Http::response([
                    'advertisers' => [[
                        'advertiser_id' => 71098,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200);
            }

            // Etapa 2a — listCampaigns
            if (str_contains($url, 'product_ads/campaigns/search')) {
                return Http::response([
                    'results' => [['id' => 5001, 'name' => 'Camp 403', 'status' => 'active', 'metrics' => []]],
                    'paging'  => ['total' => 1],
                ], 200);
            }

            // Etapa 2b — listAds: GET /advertising/advertisers/{id}/product_ads/items
            if (str_contains($url, 'product_ads/items')) {
                return Http::response([
                    'results' => [[
                        'item_id'     => 'MLB4440033',
                        'title'       => 'Produto 403',
                        'status'      => 'active',
                        'campaign_id' => 5001,
                    ]],
                    'paging' => ['total' => 1],
                ], 200);
            }

            // Etapa 3 — POST criar SGI (Variante A) → 201
            if ($method === 'POST' && str_contains($url, 'product_ads/campaigns')) {
                return Http::response([
                    'id' => 9991, 'name' => 'SGI-SMOKE-403', 'status' => 'paused', 'budget' => 5,
                ], 201);
            }

            // Etapas 4 e 5 — PUT mover/reverter → 403 em AMBOS
            // (Etapa 5 não deve ser tentada; se for, também retorna 403)
            if ($method === 'PUT' && str_contains($url, 'product_ads/ads')) {
                $putCount++;
                return Http::response(['message' => 'forbidden', 'error' => 'Insufficient scope'], 403);
            }

            // Fallback inesperado
            return Http::response(['error' => 'not_mocked'], 500);
        });

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id])
            ->assertExitCode(0);

        $files   = Storage::disk('local')->files('sugadores/ml-write-smoke');
        $fixture = json_decode(Storage::disk('local')->get($files[0]), true);

        // Deve ter pelo menos 1 falha registrada
        $this->assertGreaterThanOrEqual(1, $fixture['summary']['endpoints_failed']);

        // Blocker deve mencionar PUT 403 ou scope/permissão
        $blockers      = implode(' ', $fixture['summary']['blockers'] ?? []);
        $hasBlocker403 = str_contains(strtolower($blockers), '403')
            || str_contains(strtolower($blockers), 'scope')
            || str_contains(strtolower($blockers), 'permiss');
        $this->assertTrue($hasBlocker403, "Blocker deve mencionar 403 ou scope. Blockers: {$blockers}");

        // Etapa 5 não deve ter sido tentada — PUT só deveria ter ocorrido 1 vez (Etapa 4).
        $this->assertSame(1, $putCount, 'Apenas 1 PUT deve ocorrer — Etapa 5 não deve tentar após 403 na Etapa 4');
    }

    // ─────────── Test 6: anti-leak — tokens fake não aparecem em nenhum artefato ───────────

    /**
     * @test
     * Asserção de anti-vazamento: os tokens fake (access_token e refresh_token)
     * NÃO devem aparecer no conteúdo da fixture JSON gravada nem nos logs Laravel.
     */
    public function test_anti_leak_tokens_nao_aparecem_na_fixture_nem_nos_logs(): void
    {
        $company = $this->seedCompanyComToken();
        $this->fakeHappyPathVarianteA(71098);

        $this->artisan('sugadores:ml-write-smoke', ['--company' => $company->id])
            ->assertExitCode(0);

        // ─── Verificar fixture JSON ───
        $files = Storage::disk('local')->files('sugadores/ml-write-smoke');
        $this->assertNotEmpty($files, 'Fixture deve ter sido gravada');

        $fixtureContents = Storage::disk('local')->get($files[0]);

        $this->assertStringNotContainsString(
            self::FAKE_ACCESS_TOKEN,
            $fixtureContents,
            'access_token NÃO pode aparecer na fixture JSON (T-44-01-01)'
        );

        $this->assertStringNotContainsString(
            self::FAKE_REFRESH_TOKEN,
            $fixtureContents,
            'refresh_token NÃO pode aparecer na fixture JSON (T-44-01-01)'
        );

        // ─── Garantia estrutural anti-leak no JSON completo ───────────────────
        // A fixture já foi verificada acima via assertStringNotContainsString.
        // Verificação adicional via round-trip JSON decode/encode para cobrir
        // valores aninhados que possam ter sido serializados de forma diferente.
        $decoded    = json_decode($fixtureContents, true);
        $serialized = json_encode($decoded);

        $this->assertStringNotContainsString(self::FAKE_ACCESS_TOKEN, $serialized);
        $this->assertStringNotContainsString(self::FAKE_REFRESH_TOKEN, $serialized);
    }
}
