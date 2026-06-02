<?php

namespace Tests\Unit;

use App\Models\MlbColeta;
use App\Services\MlColetaService;
use App\Services\MlKeywordMinerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Testes unitários do MlColetaService — pipeline HTTP da API oficial do Mercado Livre.
 * Usa Http::fake() (sem rede real) e CACHE_STORE=array (phpunit.xml) — sem DB.
 *
 * Cobre: D-01 (app token cacheado), D-02 (questions 401 não aborta),
 * D-03 (429 dispara backoff e não derruba o lote).
 *
 * @group phase17
 */
class MlColetaServiceTest extends TestCase
{
    /**
     * Instância de MlbColeta sem persistência — o pipeline só lê keyword/categoria_id/id.
     */
    private function coleta(string $keyword = 'fone bluetooth'): MlbColeta
    {
        return new MlbColeta(['keyword' => $keyword]);
    }

    public function test_app_token_cacheado(): void
    {
        // D-01: a segunda chamada NÃO faz nova requisição HTTP (cache hit)
        Cache::flush();
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok123', 'expires_in' => 21600], 200),
        ]);

        $service = new MlColetaService();
        $t1 = $service->getAppToken();
        $t2 = $service->getAppToken();

        $this->assertSame('tok123', $t1);
        $this->assertSame($t1, $t2);
        Http::assertSentCount(1); // só 1 POST /oauth/token — a segunda veio do cache
    }

    public function test_pipeline_sem_questions(): void
    {
        // D-02: 401 em /questions/search NÃO aborta o pipeline; questions_disponivel=false
        Cache::flush();
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok', 'expires_in' => 21600], 200),
            '*/domain_discovery*'   => Http::response([['domain_id' => 'MLB-FONE', 'category_id' => 'MLB1051', 'category_name' => 'Fones']], 200),
            '*/products/search*'    => Http::response(['results' => [
                ['id' => 'MLB1', 'name' => 'Fone Bluetooth Esportivo'],
                ['id' => 'MLB2', 'name' => 'Fone Bluetooth Sem Fio'],
            ]], 200),
            '*/highlights/*'        => Http::response(['content' => []], 200),
            '*/trends/*'            => Http::response([['keyword' => 'bluetooth']], 200),
            '*/items/*/description' => Http::response(['plain_text' => 'descricao do produto'], 200),
            '*/questions/search*'   => Http::response(['message' => 'forbidden'], 401),
            '*/reviews/*'           => Http::response(['reviews' => []], 200),
            '*/items/*'             => Http::response(['id' => 'MLB1', 'title' => 'Fone Bluetooth', 'sold_quantity' => 10], 200),
        ]);

        $service   = new MlColetaService();
        $resultado = $service->executarPipeline($this->coleta(), new MlKeywordMinerService());

        $this->assertFalse($resultado['meta']['questions_disponivel']);
        $this->assertNotEmpty($resultado['ranking_keywords']);
    }

    public function test_429_degradacao_graciosa(): void
    {
        // D-03: 429 em um item dispara backoff e a falha de 1 item não derruba o lote
        Cache::flush();
        Http::fake([
            '*/oauth/token'         => Http::response(['access_token' => 'tok', 'expires_in' => 21600], 200),
            '*/domain_discovery*'   => Http::response([['domain_id' => 'MLB-FONE', 'category_id' => 'MLB1051']], 200),
            '*/products/search*'    => Http::response(['results' => [
                ['id' => 'MLB1', 'name' => 'Fone Bluetooth Esportivo'],
                ['id' => 'MLB2', 'name' => 'Fone Bluetooth Sem Fio'],
            ]], 200),
            '*/highlights/*'        => Http::response(['content' => []], 200),
            '*/trends/*'            => Http::response([], 200),
            '*/items/*/description' => Http::response(['plain_text' => 'descricao'], 200),
            '*/questions/search*'   => Http::response(['questions' => []], 200),
            '*/reviews/*'           => Http::response(['reviews' => []], 200),
            '*/items/*'             => Http::sequence()
                ->push(['error' => 'too_many_requests'], 429, ['Retry-After' => '1'])
                ->push(['id' => 'MLB2', 'title' => 'Fone Bluetooth Sem Fio', 'sold_quantity' => 5], 200)
                ->whenEmpty(Http::response(['id' => 'MLBx', 'sold_quantity' => null], 200)),
        ]);

        $service   = new MlColetaService();
        $resultado = $service->executarPipeline($this->coleta(), new MlKeywordMinerService());

        // Pipeline completou apesar do 429 e produziu ranking a partir dos títulos
        $this->assertNotEmpty($resultado['ranking_keywords']);
        $this->assertSame(2, $resultado['meta']['total_produtos_analisados']);
    }
}
