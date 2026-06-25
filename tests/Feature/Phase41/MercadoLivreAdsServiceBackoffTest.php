<?php

namespace Tests\Feature\Phase41;

use App\Models\Company;
use App\Models\MlAdvertiser;
use App\Models\MlToken;
use App\Services\MercadoLivreService;
use App\Services\Sugadores\MercadoLivreAdsService;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suite Feature — Phase 41 Plan 41-02 (RED).
 *
 * Cobre o refactor do MercadoLivreAdsService:
 *   - callWithBackoff: 429 (Retry-After + jitter), 5xx (exponencial), 401 (refresh 1x), 403 (sem retry), maxAttempts esgotado
 *   - Cache advertiser em ml_advertisers (hit/miss/expirado)
 *   - withRateLimit (60/min por seller_id) — aborta com RuntimeException quando excedido
 *   - RateLimiter::for('ml-api') registrado em AppServiceProvider
 *   - getLastRunMetrics expoe 6 chaves + resetMetrics zera entre chamadas
 *
 * Pattern PHPUnit 11 (atributo Test; nao usar doc-comment legacy).
 * Http::fake + Http::preventStrayRequests bloqueia chamadas reais a api.mercadolibre.com.
 */
class MercadoLivreAdsServiceBackoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bloqueia qualquer request HTTP que escape do Http::fake.
        Http::preventStrayRequests();

        // Cache e rate limiter limpos pra isolar tests entre si.
        Cache::flush();

        // Determinismo temporal pros tests de cache (now()->subDays(7) preciso).
        Carbon::setTestNow(Carbon::create(2026, 6, 25, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        // Limpa buckets ml-api conhecidos pra nao vazar entre tests.
        foreach (['unknown', 'SELLER123', '465723451', 'cached-seller'] as $seller) {
            RateLimiter::clear("ml-api:{$seller}");
        }

        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria uma Company + MlToken ativo (fixture minima pro service rodar).
     * Pattern herdado de Phase 38 MercadoLivreAdsServiceTest.
     */
    private function makeCompanyWithToken(): Company
    {
        $company = Company::create([
            'name'        => 'ByMobille - Phase 41',
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

    private function service(): MercadoLivreAdsService
    {
        return $this->app->make(MercadoLivreAdsService::class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Backoff scenarios (REQ-41-03)
    // ═══════════════════════════════════════════════════════════════════════

    /** Test 1 — 429 com Retry-After dispara espera + retry; sucesso na 2a chamada. */
    #[Test]
    public function discoverAdvertiser_retry_em_429_com_Retry_After(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::sequence()
                ->push([], 429, ['Retry-After' => '1'])
                ->push([
                    'advertisers' => [[
                        'advertiser_id' => 71098,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200),
        ]);

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(71098, $result['advertiser_id']);
        $metrics = $this->service()->getLastRunMetrics();
        $this->assertGreaterThanOrEqual(2, $metrics['total_calls']);
        $this->assertSame(1, $metrics['rate_limit_429']);
        // Retry-After=1s → wait minimo 1000ms + jitter 0..1000ms.
        $this->assertGreaterThanOrEqual(1000, $metrics['backoff_sleep_ms']);
    }

    /** Test 2 — 5xx em sequencia dispara backoff exponencial; sucesso na 3a tentativa. */
    #[Test]
    public function discoverAdvertiser_backoff_exponencial_em_500(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::sequence()
                ->push(['error' => 'srv1'], 500)
                ->push(['error' => 'srv2'], 500)
                ->push([
                    'advertisers' => [[
                        'advertiser_id' => 71098,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200),
        ]);

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(71098, $result['advertiser_id']);
        $metrics = $this->service()->getLastRunMetrics();
        $this->assertSame(3, $metrics['total_calls']);
        // Exponencial 2^1 + 2^2 = 2s + 4s = 6000ms minimos (sem jitter).
        $this->assertGreaterThanOrEqual(6000, $metrics['backoff_sleep_ms']);
    }

    /** Test 3 — 401 dispara refresh token 1x via MercadoLivreService::refreshToken(); sucesso depois. */
    #[Test]
    public function discoverAdvertiser_refresh_token_em_401(): void
    {
        $company = $this->makeCompanyWithToken();
        $token   = MlToken::where('company_id', $company->id)->first();

        // Mocka MercadoLivreService pra observar refreshToken; ensureValidToken
        // delega ao real (token ja valido — expires_at +1h).
        $mlMock = Mockery::mock(MercadoLivreService::class);
        $mlMock->shouldReceive('ensureValidToken')
            ->andReturn($token);
        $mlMock->shouldReceive('refreshToken')
            ->once()
            ->andReturnUsing(function ($t) {
                $t->update(['access_token' => 'fake-token-refreshed']);
                return $t->fresh();
            });
        $this->app->instance(MercadoLivreService::class, $mlMock);

        Http::fake([
            '*/advertising/advertisers*' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)
                ->push([
                    'advertisers' => [[
                        'advertiser_id' => 71098,
                        'site_id'       => 'MLB',
                        'seller_id'     => '465723451',
                    ]],
                ], 200),
        ]);

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(71098, $result['advertiser_id']);
        $metrics = $this->service()->getLastRunMetrics();
        $this->assertSame(1, $metrics['refresh_token_count']);
    }

    /** Test 4 — 403 aborta IMEDIATA sem retry; metric total_calls == 1. */
    #[Test]
    public function discoverAdvertiser_aborta_em_403_sem_retry(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response(
                ['error' => 'forbidden', 'message' => 'scope missing'],
                403,
            ),
        ]);

        $service = $this->service();

        try {
            $service->discoverAdvertiser($company);
            $this->fail('Esperava RuntimeException em 403, mas service retornou normalmente.');
        } catch (\RuntimeException $e) {
            // Mensagem precisa mencionar 403 ou scope/permissao (T-41-02 anti-leak token).
            $this->assertMatchesRegularExpression(
                '/(403|scope|permiss)/i',
                $e->getMessage(),
                'Mensagem deve indicar 403/scope/permissao.',
            );
        }

        $metrics = $service->getLastRunMetrics();
        $this->assertSame(1, $metrics['total_calls'], '403 nao deve retentar.');
    }

    /** Test 5 — 500 sempre dispara RuntimeException apos MAX_ATTEMPTS=5; total_calls == 5. */
    #[Test]
    public function discoverAdvertiser_aborta_apos_maxAttempts(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response(['error' => 'persistent srv'], 500),
        ]);

        $service = $this->service();

        try {
            $service->discoverAdvertiser($company);
            $this->fail('Esperava RuntimeException apos esgotar maxAttempts.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $metrics = $service->getLastRunMetrics();
        $this->assertSame(5, $metrics['total_calls'], 'MAX_ATTEMPTS=5 deve ser respeitado.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Cache scenarios (REQ-41-04)
    // ═══════════════════════════════════════════════════════════════════════

    /** Test 6 — cache miss: discoverAdvertiser grava ml_advertisers row com payload. */
    #[Test]
    public function discoverAdvertiser_cache_miss_grava_em_ml_advertisers(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 999,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
        ]);

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(999, $result['advertiser_id']);
        $this->assertSame(1, MlAdvertiser::where('company_id', $company->id)->count());

        $cached = MlAdvertiser::where('company_id', $company->id)->first();
        $this->assertSame('999', (string) $cached->advertiser_id);
        $this->assertNotNull($cached->discovered_at);
        // discovered_at >= agora menos 1 minuto (acabou de ser gravado).
        $this->assertTrue($cached->discovered_at->gte(now()->subMinute()));
        $this->assertIsArray($cached->raw_data);
    }

    /** Test 7 — cache hit: discovered_at < 7d retorna direto, sem chamada HTTP. */
    #[Test]
    public function discoverAdvertiser_cache_hit_pula_http(): void
    {
        $company = $this->makeCompanyWithToken();

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => '555',
            'seller_id'     => 'cached-seller',
            'site_id'       => 'MLB',
            'raw_data'      => ['cached' => true],
            'discovered_at' => now()->subDays(3), // dentro do TTL 7d
        ]);

        Http::fake(); // qualquer chamada falha o test

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(555, $result['advertiser_id']);
        $this->assertTrue($result['cached'] ?? false, 'Cache hit deve setar cached=true.');
        Http::assertNothingSent();

        $metrics = $this->service()->getLastRunMetrics();
        $this->assertSame(0, $metrics['total_calls'], 'Cache hit nao deve incrementar total_calls.');
    }

    /** Test 8 — cache expirado (>7d) re-busca via HTTP e atualiza row (updateOrCreate). */
    #[Test]
    public function discoverAdvertiser_cache_expirado_chama_http_e_atualiza(): void
    {
        $company = $this->makeCompanyWithToken();

        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => '111',
            'seller_id'     => 'old-seller',
            'site_id'       => 'MLB',
            'raw_data'      => ['old' => true],
            'discovered_at' => now()->subDays(8), // EXPIROU
        ]);

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 222,
                    'site_id'       => 'MLB',
                    'seller_id'     => 'new-seller',
                ]],
            ], 200),
        ]);

        $result = $this->service()->discoverAdvertiser($company);

        $this->assertSame(222, $result['advertiser_id']);
        // updateOrCreate: continua 1 row, mas atualizada.
        $this->assertSame(1, MlAdvertiser::where('company_id', $company->id)->count());

        $fresh = MlAdvertiser::where('company_id', $company->id)->first();
        $this->assertSame('222', (string) $fresh->advertiser_id);
        $this->assertTrue($fresh->discovered_at->gte(now()->subMinute()));

        $metrics = $this->service()->getLastRunMetrics();
        $this->assertSame(1, $metrics['total_calls']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Rate limiter scenarios (REQ-41-05)
    // ═══════════════════════════════════════════════════════════════════════

    /** Test 9 — bucket ml-api:SELLER123 cheio (60 hits) aborta a 61a chamada. */
    #[Test]
    public function withRateLimit_aborta_quando_60_no_mesmo_minuto(): void
    {
        $company = $this->makeCompanyWithToken();

        // Pre-popula advertiser cache pra discoverAdvertiser ser hit e nao
        // consumir a janela inteira — usamos listCampaigns que aceita
        // advertiser_id direto.
        MlAdvertiser::create([
            'company_id'    => $company->id,
            'advertiser_id' => '71098',
            'seller_id'     => 'SELLER123',
            'site_id'       => 'MLB',
            'raw_data'      => [],
            'discovered_at' => now(),
        ]);

        // Estoura o bucket ml-api:SELLER123 ANTES da chamada do service.
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit('ml-api:SELLER123', 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('ml-api:SELLER123', 60));

        Http::fake([
            '*/product_ads/campaigns/search*' => Http::response(['results' => [], 'paging' => ['total' => 0]], 200),
        ]);

        try {
            $this->service()->listCampaigns($company, 71098, '2026-06-01', '2026-06-25');
            $this->fail('Esperava RuntimeException por rate limit excedido.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/rate limit/i',
                $e->getMessage(),
                'Mensagem deve mencionar rate limit.',
            );
            $this->assertStringContainsString('SELLER123', $e->getMessage());
        }
    }

    /** Test 10 — AppServiceProvider registra ml-api limiter com perMinute(60). */
    #[Test]
    public function AppServiceProvider_registra_ml_api_limiter(): void
    {
        $limiter = RateLimiter::limiter('ml-api');

        $this->assertNotNull(
            $limiter,
            "Bucket 'ml-api' nao foi registrado em AppServiceProvider::boot().",
        );

        // Invoca o callback (RateLimiter::for expoe Closure que aceita Request).
        $result = $limiter(new \Illuminate\Http\Request());

        $limit = is_array($result) ? $result[0] : $result;
        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(60, $limit->maxAttempts, "Bucket 'ml-api' deve ser 60/min (REQ-41-05).");
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Metricas (REQ-41-06)
    // ═══════════════════════════════════════════════════════════════════════

    /** Test 11 — getLastRunMetrics expoe as 6 chaves esperadas. */
    #[Test]
    public function getLastRunMetrics_tem_chaves_esperadas(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
        ]);

        $this->service()->discoverAdvertiser($company);
        $metrics = $this->service()->getLastRunMetrics();

        foreach (['total_calls', 'pages_read', 'rate_limit_429', 'refresh_token_count', 'total_duration_ms', 'backoff_sleep_ms'] as $key) {
            $this->assertArrayHasKey($key, $metrics, "Chave {$key} ausente em getLastRunMetrics.");
            $this->assertIsInt($metrics[$key], "Chave {$key} deve ser int.");
        }
        $this->assertGreaterThanOrEqual(0, $metrics['total_duration_ms']);
    }

    /** Test 12 — resetMetrics zera entre chamadas (cada metodo publico reseta). */
    #[Test]
    public function resetMetrics_zera_entre_chamadas(): void
    {
        $company = $this->makeCompanyWithToken();

        Http::fake([
            '*/advertising/advertisers*' => Http::response([
                'advertisers' => [[
                    'advertiser_id' => 71098,
                    'site_id'       => 'MLB',
                    'seller_id'     => '465723451',
                ]],
            ], 200),
            '*/product_ads/campaigns/search*' => Http::response([
                'results' => [['id' => 'C1', 'name' => 'X']],
                'paging'  => ['total' => 1],
            ], 200),
        ]);

        // Primeira chamada → metricas != 0.
        $service = $this->service();
        $service->discoverAdvertiser($company);
        $afterDiscover = $service->getLastRunMetrics();
        $this->assertGreaterThan(0, $afterDiscover['total_calls']);

        // Segunda chamada em metodo diferente → metricas refletem APENAS a 2a chamada.
        $service->listCampaigns($company, 71098, '2026-06-01', '2026-06-25');
        $afterListCampaigns = $service->getLastRunMetrics();

        $this->assertSame(
            1,
            $afterListCampaigns['total_calls'],
            'resetMetrics deve zerar contadores no inicio de cada metodo publico.',
        );
        $this->assertGreaterThanOrEqual(
            1,
            $afterListCampaigns['pages_read'],
            'listCampaigns deve incrementar pages_read em cada iteracao do do-while.',
        );
    }
}
