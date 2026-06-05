<?php

// Phase 22 — testes do domínio /carteira/* do wrapper EcfDriveService.

namespace Tests\Feature\Phase22;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveServiceCarteiraTest extends TestCase
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

    public function test_carteiraResumo_cacheia_5min(): void
    {
        Http::fake([
            '*/carteira/resumo*' => Http::response([
                'mesAtual' => '202605',
                'gmv'      => ['atual' => 42859191.37, 'deltaPct' => -11.74],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->carteiraResumo();
        $s->carteiraResumo();

        Http::assertSentCount(1);
    }

    public function test_carteiraHistorico_cacheia_24h_e_envia_periodicidade(): void
    {
        Http::fake(['*/carteira/historico*' => Http::response(['data' => []], 200)]);

        $s = new EcfDriveService();
        $s->carteiraHistorico('mensal', ['from' => '2025-06']);
        $s->carteiraHistorico('mensal', ['from' => '2025-06']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'periodicidade=mensal')
            && str_contains($req->url(), 'from=2025-06')
        );
    }

    public function test_carteiraDistribuicaoMedalhas_aceita_tim_month_opcional(): void
    {
        Http::fake(['*/carteira/distribuicao/medalhas*' => Http::response(['distribuicao' => []], 200)]);

        (new EcfDriveService())->carteiraDistribuicaoMedalhas('202605');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'tim_month_id=202605'));
    }

    public function test_carteiraBreakdown_envia_dimensao(): void
    {
        Http::fake(['*/carteira/breakdown*' => Http::response(['distribuicao' => []], 200)]);

        (new EcfDriveService())->carteiraBreakdown('frete');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'dimensao=frete'));
    }

    public function test_carteiraSegmentacao_lanca_invalidargument_com_dimensoes_vazio(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV não-vazia');

        (new EcfDriveService())->carteiraSegmentacao('   ');
    }

    public function test_carteiraSegmentacao_envia_dimensoes_csv(): void
    {
        Http::fake(['*/carteira/segmentacao*' => Http::response(['data' => []], 200)]);

        (new EcfDriveService())->carteiraSegmentacao('programa,cluster');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'dimensoes=programa%2Ccluster'));
    }
}
