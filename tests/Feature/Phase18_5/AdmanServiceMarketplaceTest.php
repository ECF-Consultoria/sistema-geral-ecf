<?php

namespace Tests\Feature\Phase18_5;

use App\Services\AdmanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 18.5 W3-T2 — Cobertura do marketplace dinamico no AdmanService.
 *
 * Prova que:
 *  1) fetchPerformance(..., 'meli')   bate em /meli/performance/...
 *  2) fetchPerformance(..., 'shopee') bate em /shopee/performance/...
 *  3) Sem parametro: default 'meli' (compat preservada para callers
 *     nao migrados nesta fase).
 *
 * Intercepta as chamadas HTTP via Http::fake() — nao toca a API real.
 *
 * @group phase18_5
 */
class AdmanServiceMarketplaceTest extends TestCase
{
    /**
     * Resposta minima que o AdmanService aceita (precisa ter summarizedData
     * mesmo que vazio, para que o caller nao quebre se for usado em sync).
     */
    private function respostaPerformanceVazia(): array
    {
        return [
            'summarizedData' => [
                'grossBilling' => ['value' => 0.0],
            ],
            'items' => [],
        ];
    }

    /**
     * TEST 1 — Marketplace explicito 'meli' bate em /meli/performance/...
     */
    public function test_fetch_performance_meli_chama_path_meli(): void
    {
        Http::fake([
            '*' => Http::response($this->respostaPerformanceVazia(), 200),
        ]);

        $service = new AdmanService();
        $service->fetchPerformance('CUST1', '2026-06-01', '2026-06-01', 3, 'meli');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/meli/performance/CUST1');
        });
    }

    /**
     * TEST 2 — Marketplace explicito 'shopee' bate em /shopee/performance/...
     */
    public function test_fetch_performance_shopee_chama_path_shopee(): void
    {
        Http::fake([
            '*' => Http::response($this->respostaPerformanceVazia(), 200),
        ]);

        $service = new AdmanService();
        $service->fetchPerformance('CUST1', '2026-06-01', '2026-06-01', 3, 'shopee');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/shopee/performance/CUST1');
        });
    }

    /**
     * TEST 3 — Sem parametro: default 'meli' preserva compat com callers
     * antigos (MLB-only) nao migrados na Phase 18.5 W2-T2.
     */
    public function test_fetch_performance_sem_marketplace_continua_meli_para_compat(): void
    {
        Http::fake([
            '*' => Http::response($this->respostaPerformanceVazia(), 200),
        ]);

        $service = new AdmanService();
        // Nota: chamada com 3 args (custId, from, to) — assinatura antiga.
        $service->fetchPerformance('CUST1', '2026-06-01', '2026-06-01');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/meli/performance/CUST1');
        });
    }
}
