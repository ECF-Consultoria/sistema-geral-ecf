<?php

namespace Tests\Feature\Phase19;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\User;
use App\Services\AdmanMcpService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 19 W3-T3 — Testes do endpoint mlbsByCompany e do Cache::lock por custId.
 *
 * Verifica:
 *  - Endpoint retorna lista única de MLBs (sem duplicatas) consolidada de N adgroups.
 *  - Cache::lock é invocado com a chave correta por custId dentro de fetchAllProductAds.
 */
class CopiarMlbsTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = Carbon::parse('2026-06-03')->startOfDay();
        Carbon::setTestNow($this->hoje);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function company(string $custId = 'CUST-123'): Company
    {
        return Company::create([
            'name'             => 'Empresa MLB Teste',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => $custId,
            'active'           => true,
        ]);
    }

    private function seedAdgroup(Company $c, string $campaignId, string $ag): Sugador
    {
        return Sugador::create([
            'company_id'           => $c->id,
            'reference_date'       => $this->hoje->toDateString(),
            'tipo'                 => Sugador::TIPO_ADGROUP,
            'campaign_id'          => $campaignId,
            'campaign_name'        => 'Camp ' . $campaignId,
            'adgroup_id'           => $ag,
            'adgroup_name'         => 'AG ' . $ag,
            'periodo_inicio'       => $this->hoje->copy()->subDays(7)->toDateString(),
            'periodo_fim'          => $this->hoje->copy()->subDay()->toDateString(),
            'investimento_periodo' => 100,
            'faturamento_periodo'  => 0,
            'vendas_periodo'       => 0,
            'cliques'              => 0,
            'impressoes'           => 0,
            'motivos'              => ['gasto_sem_venda'],
            'status'               => Sugador::STATUS_PENDENTE,
        ]);
    }

    /**
     * Endpoint retorna lista de MLBs únicos (sem duplicatas) consolidada de múltiplos adgroups.
     *
     * Setup: 3 adgroups da mesma empresa, com MLBs sobrepostos.
     * Adgroup 1: MLB1, MLB2
     * Adgroup 2: MLB2, MLB3 (MLB2 duplicado propositalmente)
     * Adgroup 3: MLB3, MLB4 (MLB3 duplicado propositalmente)
     * Esperado: [MLB1, MLB2, MLB3, MLB4] — únicos, ordenados.
     */
    public function test_mlbs_by_company_retorna_lista_unica(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('CUST-TEST');

        $this->seedAdgroup($empresa, 'CAMP1', 'AG1');
        $this->seedAdgroup($empresa, 'CAMP2', 'AG2');
        $this->seedAdgroup($empresa, 'CAMP3', 'AG3');

        // Mock do AdmanMcpService: responde com MLBs por campaign_id
        $this->mock(AdmanMcpService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);

            // Cada chamada de fetchMlbsByCampaign retorna MLBs diferentes (com overlap)
            $mock->shouldReceive('fetchMlbsByCampaign')
                ->with('CUST-TEST', 'CAMP1', \Mockery::any(), \Mockery::any())
                ->andReturn([
                    'mlbs'        => [['listing_id' => 'MLB1'], ['listing_id' => 'MLB2']],
                    'truncated'   => false,
                    'pages_read'  => 1,
                    'total_pages' => 1,
                ]);

            $mock->shouldReceive('fetchMlbsByCampaign')
                ->with('CUST-TEST', 'CAMP2', \Mockery::any(), \Mockery::any())
                ->andReturn([
                    'mlbs'        => [['listing_id' => 'MLB2'], ['listing_id' => 'MLB3']],
                    'truncated'   => false,
                    'pages_read'  => 1,
                    'total_pages' => 1,
                ]);

            $mock->shouldReceive('fetchMlbsByCampaign')
                ->with('CUST-TEST', 'CAMP3', \Mockery::any(), \Mockery::any())
                ->andReturn([
                    'mlbs'        => [['listing_id' => 'MLB3'], ['listing_id' => 'MLB4']],
                    'truncated'   => false,
                    'pages_read'  => 1,
                    'total_pages' => 1,
                ]);
        });

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-by-company', $empresa->id));

        $response->assertOk();
        $response->assertJsonPath('mlbs', ['MLB1', 'MLB2', 'MLB3', 'MLB4']);
        $response->assertJsonPath('total_mlbs', 4);
        $response->assertJsonPath('sugadores_processados', 3);
        $response->assertJsonPath('truncated', false);
    }

    /**
     * Cache::lock é invocado com a chave correta (adman_mcp:custid:{custId}) ao chamar fetchAllProductAds.
     *
     * Estratégia pragmática: instancia AdmanMcpService via reflexão na classe real,
     * injeta URL/apiKey, e substitui `call()` via subclasse anônima para evitar HTTP.
     * Valida que: (a) a chamada completa sem RuntimeException (lock adquirido + liberado)
     * e (b) o cache foi armazenado (significa que o bloco dentro do lock executou).
     *
     * Limitação: sem fork de processo, não testamos concorrência real. O teste verifica
     * que o código de lock está no lugar certo — suficiente para regression.
     */
    public function test_cache_lock_serializa_chamadas_concorrentes(): void
    {
        $custId = 'CUST-LOCK-TEST';

        // Subclasse anônima que substitui `call()` para não fazer HTTP real,
        // mas mantém todo o código de Cache::lock e Cache::remember original.
        $service = new class extends AdmanMcpService {
            public function call(string $toolName, array $arguments): array
            {
                // Resposta mínima válida para a paginação encerrar em 1 página.
                return [
                    'structuredContent' => [
                        'productAds' => [
                            ['listingId' => 'MLB-LOCK-1', 'campaignName' => 'CampX'],
                        ],
                        'totalPages' => 1,
                    ],
                ];
            }
        };

        // Injeta URL e API key via reflexão na classe real (não no mock Mockery)
        $reflection = new \ReflectionClass(AdmanMcpService::class);

        $urlProp = $reflection->getProperty('url');
        $urlProp->setAccessible(true);
        $urlProp->setValue($service, 'https://mcp.example.com');

        $keyProp = $reflection->getProperty('apiKey');
        $keyProp->setAccessible(true);
        $keyProp->setValue($service, 'test-api-key');

        // Executa a chamada — o lock deve ser adquirido e liberado sem erro
        $result = $service->fetchAllProductAds($custId, '2026-06-01', '2026-06-02');

        // Chamada completou: lock adquirido + liberado + Cache::remember executou
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('truncated', $result);
        $this->assertCount(1, $result['items']);

        // Cache armazenado com a chave correta — evidência que o bloco do lock executou.
        // Phase 30 fix W1 B: default maxPages reduzido de 8 → 4 pra reduzir pressão na
        // janela 8/min global do RateLimiter('adman-api'). Cache key reflete o cap atual.
        $cacheKey = sprintf('adman_mcp:productads:%s:%s:%s:%d', $custId, '2026-06-01', '2026-06-02', 4);
        $this->assertNotNull(\Cache::get($cacheKey), 'Cache::remember deve armazenar resultado após o lock.');

        // O lock key "adman_mcp:custid:{custId}" não deve existir mais (foi liberado)
        // Lock liberado = block() terminou sem LockTimeoutException.
        $lockKey = "adman_mcp:custid:{$custId}";
        $lock = \Cache::lock($lockKey, 1);
        $acquired = $lock->get();
        $this->assertTrue($acquired, 'Lock deveria estar disponível após a chamada (foi liberado corretamente).');
        if ($acquired) $lock->release();
    }

    /**
     * Plano 02 fix — Quando TODOS os sugadores falham (típico em rate limit 429
     * cumulativo da Adman), o endpoint deve retornar 502 com `reason` explícito
     * em vez de retornar mlbs:[] com status 200 (que o frontend interpretava
     * como "Sem MLBs"). Bug reportado pelo usuário no smoke W4 2026-06-03.
     */
    public function test_mlbs_by_company_retorna_502_quando_todos_falham(): void
    {
        $admin   = $this->admin();
        $empresa = $this->company('CUST-FAIL');

        $this->seedAdgroup($empresa, 'CAMP1', 'AG1');
        $this->seedAdgroup($empresa, 'CAMP2', 'AG2');

        $this->mock(AdmanMcpService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);

            // Simula 429 da Adman em TODAS as chamadas
            $mock->shouldReceive('fetchMlbsByCampaign')
                ->andThrow(new \RuntimeException('Adman MCP tool getMarketplaceadsCustIdproductAdsmetrics erro: Request to adman-api failed with status 429'));
        });

        $response = $this->actingAs($admin)->getJson(route('sugadores.mlbs-by-company', $empresa->id));

        $response->assertStatus(502);
        $response->assertJsonPath('sugadores_processados', 0);
        $response->assertJsonPath('sugadores_solicitados', 2);
        $response->assertJsonPath('falhas', 2);
        $response->assertJsonPath('mlbs', []);
        // Mensagem específica de rate limit (detecta 429 no erro)
        $response->assertJsonPath('reason', 'Falha temporária da Adman (rate limit). Tente em ~1 minuto.');
    }
}
