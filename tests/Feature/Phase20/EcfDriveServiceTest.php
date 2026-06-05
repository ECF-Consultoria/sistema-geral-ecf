<?php

// Phase 20 — testes do wrapper EcfDriveService via Http::fake.

namespace Tests\Feature\Phase20;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Testa o wrapper HTTP EcfDriveService sem depender da API real.
 * Usa Http::fake para simular respostas da API ECF Drive.
 */
class EcfDriveServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ecf.base' => 'https://files.ecfconsultoria.com.br/api/v1',
            'services.ecf.key'  => 'fake-key',
        ]);
        Cache::flush();
    }

    public function test_listGrants_envia_headers_corretos_e_retorna_data(): void
    {
        Http::fake([
            '*/clientes/grants*' => Http::response([
                'data'  => [
                    ['custId' => '111', 'razaoSocial' => 'Empresa A'],
                    ['custId' => '222', 'razaoSocial' => 'Empresa B'],
                    ['custId' => '333', 'razaoSocial' => 'Empresa C'],
                ],
                'total' => 3,
            ], 200),
        ]);

        $service = new EcfDriveService();
        $r = $service->listGrants(['page' => 1, 'limit' => 100]);

        $this->assertCount(3, $r['data']);
        Http::assertSent(fn ($req) =>
            $req->hasHeader('X-Api-Key', 'fake-key')
            && $req->hasHeader('Accept', 'application/json')
            && str_contains($req->url(), '/clientes/grants')
        );
    }

    public function test_listGrants_lanca_runtime_em_5xx(): void
    {
        Http::fake(['*/clientes/grants*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        (new EcfDriveService())->listGrants();
    }

    public function test_cliente_retorna_payload(): void
    {
        Http::fake([
            '*/clientes/1234*' => Http::response(['custId' => '1234', 'razaoSocial' => 'Loja X'], 200),
        ]);

        $r = (new EcfDriveService())->cliente('1234');
        $this->assertSame('1234', $r['custId']);
    }

    public function test_ping_retorna_true_em_2xx(): void
    {
        Http::fake(['*/auth/me*' => Http::response(['ok' => true], 200)]);

        $this->assertTrue((new EcfDriveService())->ping());
    }

    public function test_ping_retorna_false_em_4xx_sem_lancar(): void
    {
        Http::fake(['*/auth/me*' => Http::response('unauthorized', 401)]);

        $this->assertFalse((new EcfDriveService())->ping());
    }

    public function test_grantsExpirandoEm_usa_cache_5min(): void
    {
        Http::fake(['*/clientes/grants*' => Http::response(['data' => [], 'total' => 0], 200)]);

        $s = new EcfDriveService();
        $s->grantsExpirandoEm(30);
        $s->grantsExpirandoEm(30); // segunda chamada deve usar cache

        Http::assertSentCount(1);
    }
}
