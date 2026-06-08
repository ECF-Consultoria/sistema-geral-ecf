<?php

namespace Tests\Feature\Phase30;

use App\Jobs\AnalyzeCompanySugadoresJob;
use App\Jobs\FetchAdmanMlbsByCampaignJob;
use App\Models\Company;
use App\Services\AdmanMcpService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 30 W1 — Suíte de smoke do throttled queue Adman + checkpoint paginação.
 *
 * Cobre as 3 tasks autônomas do Plan 30-01:
 *  - Task 1: RateLimiter global 'adman-api' + middleware nos 2 Jobs + usleep removido
 *  - Task 2: Checkpoint persistente + cap re-dispatch 10 + maxPages 500
 *  - Task 3: AdmanMcpService::fetchAllProductAds aceita startPage
 */
class ThrottledAdmanQueueTest extends TestCase
{
    // ─────────── Task 1: RateLimiter + middleware + usleep removido ───────────

    /** Test 1 — bucket 'adman-api' registrado em AppServiceProvider::boot() */
    public function test_rate_limiter_adman_api_esta_registrado(): void
    {
        $limiter = RateLimiter::limiter('adman-api');

        $this->assertNotNull(
            $limiter,
            "Bucket 'adman-api' não foi registrado em AppServiceProvider::boot()."
        );

        $result = $limiter(new \Illuminate\Http\Request());

        $limit = is_array($result) ? $result[0] : $result;
        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(8, $limit->maxAttempts, "Bucket 'adman-api' deve ser 8/min (Phase 30 D-01).");
    }

    /** Test 2 — AnalyzeCompanySugadoresJob declara middleware RateLimited('adman-api') */
    public function test_analyze_company_sugadores_job_aplica_middleware_adman_api(): void
    {
        $company = new Company(['id' => 1, 'name' => 'Teste', 'active' => true]);
        $job     = new AnalyzeCompanySugadoresJob($company);

        $middlewares = $job->middleware();
        $this->assertNotEmpty($middlewares);
        $this->assertInstanceOf(RateLimited::class, $middlewares[0]);

        // Acesso ao key via reflection (propriedade pública $key em Laravel 12)
        // Em Laravel 12, RateLimited armazena o nome do bucket em $limiterName (protected)
        $ref = new \ReflectionObject($middlewares[0]);
        $prop = $ref->getProperty('limiterName');
        $prop->setAccessible(true);
        $this->assertSame('adman-api', $prop->getValue($middlewares[0]));
    }

    /** Test 3 — FetchAdmanMlbsByCampaignJob declara middleware RateLimited('adman-api') */
    public function test_fetch_adman_mlbs_job_aplica_middleware_adman_api(): void
    {
        $job = new FetchAdmanMlbsByCampaignJob('123', '2026-05-01', '2026-05-31');

        $middlewares = $job->middleware();
        $this->assertNotEmpty($middlewares);
        $this->assertInstanceOf(RateLimited::class, $middlewares[0]);

        // Em Laravel 12, RateLimited armazena o nome do bucket em $limiterName (protected)
        $ref = new \ReflectionObject($middlewares[0]);
        $prop = $ref->getProperty('limiterName');
        $prop->setAccessible(true);
        $this->assertSame('adman-api', $prop->getValue($middlewares[0]));
    }

    /** Test 4 — usleep(6_500_000) removido das 2 chamadas em AdmanMcpService */
    public function test_usleep_throttle_interno_removido_do_adman_mcp_service(): void
    {
        $source = file_get_contents(app_path('Services/AdmanMcpService.php'));

        // Remove linhas que sejam comentário (// ou * dentro de docblock)
        $linesUteis = array_filter(
            preg_split("/\r?\n/", $source),
            fn ($line) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $line),
        );
        $sourceUtil = implode("\n", $linesUteis);

        $count = substr_count($sourceUtil, 'usleep(6_500_000)');
        $this->assertSame(
            0,
            $count,
            "usleep(6_500_000) ainda presente em AdmanMcpService — deveria ter sido removido (Phase 30 D-02). Ocorrências: {$count}."
        );
    }

    // ─────────── Task 2: Checkpoint + cap MAX_DISPATCH_COUNT + maxPages 500 ───────────

    /** Test 5 — construtor aceita 6 params com defaults pros novos campos */
    public function test_fetch_adman_mlbs_job_aceita_construtor_estendido(): void
    {
        $job = new FetchAdmanMlbsByCampaignJob(
            custId:          '123',
            dateFrom:        '2026-05-01',
            dateTo:          '2026-05-31',
            startPage:       42,
            mlbsAcumulados:  [['mlb_id' => 'MLB001']],
            dispatchCount:   3,
        );

        $this->assertSame(42, $job->startPage);
        $this->assertSame([['mlb_id' => 'MLB001']], $job->mlbsAcumulados);
        $this->assertSame(3, $job->dispatchCount);
    }

    /** Test 6 — MAX_PAGES_FULL_SCAN ajustado para 500 (D-04) */
    public function test_max_pages_full_scan_e_500(): void
    {
        $this->assertSame(
            500,
            FetchAdmanMlbsByCampaignJob::MAX_PAGES_FULL_SCAN,
            'Phase 30 D-04: cap reduzido de 1000 para 500.'
        );
    }

    /** Test 7 — Cap de re-dispatch existe e vale 10 (D-03 Pitfall 2) */
    public function test_max_dispatch_count_e_10(): void
    {
        $this->assertSame(
            10,
            FetchAdmanMlbsByCampaignJob::MAX_DISPATCH_COUNT,
            'Phase 30 D-03: cap de re-dispatch previne loop infinito.'
        );
    }

    /** Test 8 — uniqueId é agnóstico ao startPage (mesma chave em continuações) */
    public function test_unique_id_e_agnostico_ao_start_page(): void
    {
        $jobInicial = new FetchAdmanMlbsByCampaignJob('123', '2026-05-01', '2026-05-31');
        $jobContinuacao = new FetchAdmanMlbsByCampaignJob('123', '2026-05-01', '2026-05-31', startPage: 50, dispatchCount: 2);

        $this->assertSame(
            $jobInicial->uniqueId(),
            $jobContinuacao->uniqueId(),
            'Continuação deve compartilhar a mesma uniqueId pra evitar paralelismo.'
        );
    }

    // ─────────── Task 3: AdmanMcpService::fetchAllProductAds aceita startPage ───────────

    /** Test 9 — assinatura aceita 7º parâmetro startPage */
    public function test_fetch_all_product_ads_aceita_parametro_start_page(): void
    {
        $method = new ReflectionMethod(AdmanMcpService::class, 'fetchAllProductAds');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(
            7,
            count($params),
            'fetchAllProductAds deve ter pelo menos 7 parâmetros (último = startPage).'
        );

        $startPageParam = $params[6] ?? null;
        $this->assertNotNull($startPageParam);
        $this->assertSame('startPage', $startPageParam->getName());
        $this->assertTrue($startPageParam->isDefaultValueAvailable());
        $this->assertSame(1, $startPageParam->getDefaultValue());
    }

    /** Test 10 — chamada com startPage=42 usa page=42 na 1ª chamada MCP (smoke via source) */
    public function test_fetch_all_product_ads_usa_start_page_como_pagina_inicial(): void
    {
        $source = file_get_contents(app_path('Services/AdmanMcpService.php'));

        // Confirma que a inicialização do $page foi substituída por $startPage
        $this->assertMatchesRegularExpression(
            '/\$page\s*=\s*\$startPage\s*;/',
            $source,
            'Phase 30 D-03: $page deve ser inicializado com $startPage (não hardcoded 1).'
        );
    }

    /** Test 11 — backwards compat: chamado sem startPage usa default 1 */
    public function test_fetch_all_product_ads_mantem_compat_sem_start_page(): void
    {
        $method = new ReflectionMethod(AdmanMcpService::class, 'fetchAllProductAds');
        $params = $method->getParameters();

        // Default do startPage = 1 garante que chamadas existentes (que não passam o 7º param)
        // continuam idênticas ao comportamento anterior.
        $startPageParam = $params[6];
        $this->assertSame(1, $startPageParam->getDefaultValue());
    }

    // ─────────── Fix W1: RateLimiter dentro de AdmanMcpService::call() ───────────

    /** Test 12 — call() throw quando bucket adman-api estourado (protege controller síncrono) */
    public function test_call_lanca_excecao_quando_bucket_adman_api_estourado(): void
    {
        \Illuminate\Support\Facades\RateLimiter::clear('adman-api');
        for ($i = 0; $i < 8; $i++) {
            \Illuminate\Support\Facades\RateLimiter::hit('adman-api', 60);
        }

        $service = $this->app->make(AdmanMcpService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Limite Adman MCP atingido/');

        // qualquer chamada deveria explodir pq o bucket está cheio
        $service->call('any-tool', ['custId' => '123']);

        \Illuminate\Support\Facades\RateLimiter::clear('adman-api');
    }

    /** Test 13 — Service tem import correto do RateLimiter facade (smoke source) */
    public function test_adman_mcp_service_importa_rate_limiter(): void
    {
        $source = file_get_contents(app_path('Services/AdmanMcpService.php'));
        $this->assertStringContainsString(
            'use Illuminate\\Support\\Facades\\RateLimiter;',
            $source,
            'AdmanMcpService deve importar RateLimiter facade.'
        );
        $this->assertStringContainsString(
            "RateLimiter::tooManyAttempts('adman-api'",
            $source,
            'AdmanMcpService::call() deve checar bucket adman-api antes do HTTP.'
        );
    }

    /** Test 14 — fetchAllProductAds captura rate-limit local e retorna parcial (Fix C) */
    public function test_fetch_all_product_ads_retorna_parcial_quando_rate_limit_local(): void
    {
        $source = file_get_contents(app_path('Services/AdmanMcpService.php'));

        // Confirma que a captura do RuntimeException 'Limite Adman MCP atingido' está dentro do loop
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\RuntimeException\s+\$e\s*\)/',
            $source,
            'fetchAllProductAds deve capturar RuntimeException pra distinguir rate-limit local de erros upstream.'
        );
        $this->assertStringContainsString(
            "str_contains(\$e->getMessage(), 'Limite Adman MCP atingido')",
            $source,
            'Captura deve filtrar SÓ a mensagem "Limite Adman MCP atingido" — outros RuntimeException sobem.'
        );
        $this->assertStringContainsString(
            'rate_limited',
            $source,
            'Retorno deve incluir flag rate_limited pra invalidar cache.'
        );
        $this->assertStringContainsString(
            "Cache::forget(\$cacheKey)",
            $source,
            'Cache invalidado quando rate_limited=true pra não persistir parcial por 30min.'
        );
    }
}
