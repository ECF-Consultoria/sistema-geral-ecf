<?php

// Phase 22 — testes do domínio /clientes/* do wrapper EcfDriveService.

namespace Tests\Feature\Phase22;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveServiceClientesTest extends TestCase
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

    public function test_listClientes_envia_headers_e_cacheia_5min(): void
    {
        Http::fake([
            '*/clientes*' => Http::response([
                'data'  => [
                    ['custId' => '1007473885', 'razaoSocial' => 'ECF CONSULTORIA'],
                ],
                'total' => 1,
            ], 200),
        ]);

        $s = new EcfDriveService();
        $r1 = $s->listClientes(['ativo' => true, 'page' => 1]);
        $r2 = $s->listClientes(['ativo' => true, 'page' => 1]); // segunda call vem do cache

        $this->assertCount(1, $r1['data']);
        $this->assertSame($r1, $r2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) =>
            $req->hasHeader('X-Api-Key', 'fake-key')
            && str_contains($req->url(), '/clientes')
        );
    }

    public function test_clienteHistorico_nao_usa_cache(): void
    {
        Http::fake([
            '*/clientes/1234/historico*' => Http::response([
                'data' => [['snapshotDate' => '2026-05-14']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->clienteHistorico('1234');
        $s->clienteHistorico('1234');

        Http::assertSentCount(2); // sem cache: 2 chamadas
    }

    public function test_acoesPendentes_cacheia_5min(): void
    {
        Http::fake([
            '*/clientes/acoes-pendentes*' => Http::response([
                'data' => [['custId' => '999', 'acaoRecomendadaCcp' => 'RENOVAR']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->acoesPendentes();
        $s->acoesPendentes();

        Http::assertSentCount(1);
    }

    public function test_listClientes_lanca_runtime_em_5xx(): void
    {
        Http::fake(['*/clientes*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        (new EcfDriveService())->listClientes();
    }
}
