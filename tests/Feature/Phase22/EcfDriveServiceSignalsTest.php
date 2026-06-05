<?php

// Phase 22 — testes do domínio /signals/* do wrapper EcfDriveService.

namespace Tests\Feature\Phase22;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveServiceSignalsTest extends TestCase
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

    public function test_listSignals_cacheia_1min(): void
    {
        Http::fake([
            '*/signals*' => Http::response([
                'data' => [['id' => 91, 'severity' => 'critical']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->listSignals(['acked' => false, 'limit' => 50, 'page' => 1]);
        $s->listSignals(['acked' => false, 'limit' => 50, 'page' => 1]);

        Http::assertSentCount(1);
    }

    public function test_listSignals_envia_filtros_corretamente(): void
    {
        Http::fake(['*/signals*' => Http::response(['data' => []], 200)]);

        (new EcfDriveService())->listSignals(['severity' => 'critical', 'acked' => false]);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'severity=critical')
            && str_contains($req->url(), 'acked=0')  // false serializado como 0 pelo http_build_query
        );
    }

    public function test_ackSignal_faz_post_e_invalida_inbox(): void
    {
        // Primeiro lista para popular cache da inbox
        Http::fake([
            '*/signals/91/ack' => Http::response(['ok' => true, 'id' => 91], 200),
            '*/signals*'       => Http::response(['data' => [['id' => 91]]], 200),
        ]);

        $s = new EcfDriveService();

        // Hidrata cache da inbox
        $s->listSignals(['acked' => false, 'limit' => 50, 'page' => 1]);

        // Faz ack
        $r = $s->ackSignal(91);
        $this->assertTrue($r['ok']);

        // Confirma que a chave da inbox foi invalidada — próxima call faz HTTP novamente
        $s->listSignals(['acked' => false, 'limit' => 50, 'page' => 1]);

        // 1 listSignals inicial + 1 POST ack + 1 listSignals pós-ack = 3 requests
        Http::assertSentCount(3);
        Http::assertSent(fn ($req) =>
            $req->method() === 'POST' && str_contains($req->url(), '/signals/91/ack')
        );
    }

    public function test_ackSignal_lanca_runtime_em_5xx(): void
    {
        Http::fake(['*/signals/99/ack' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        (new EcfDriveService())->ackSignal(99);
    }
}
