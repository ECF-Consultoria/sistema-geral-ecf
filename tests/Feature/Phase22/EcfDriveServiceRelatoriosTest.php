<?php

// Phase 22 — testes do domínio /relatorios/* do wrapper EcfDriveService.

namespace Tests\Feature\Phase22;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveServiceRelatoriosTest extends TestCase
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

    public function test_relatorioMensal_sem_param_chama_path_base(): void
    {
        Http::fake([
            '*/relatorios/mensal' => Http::response([
                'periodo' => '202605',
                'resumo'  => ['gmv' => ['atual' => 42859191.37]],
            ], 200),
        ]);

        $r = (new EcfDriveService())->relatorioMensal();

        $this->assertSame('202605', $r['periodo']);
        Http::assertSent(fn ($req) =>
            str_ends_with(parse_url($req->url(), PHP_URL_PATH), '/relatorios/mensal')
        );
    }

    public function test_relatorioMensal_com_tim_month_id_chama_path_com_id(): void
    {
        Http::fake([
            '*/relatorios/mensal/202604*' => Http::response([
                'periodo' => '202604',
            ], 200),
        ]);

        $r = (new EcfDriveService())->relatorioMensal('202604');

        $this->assertSame('202604', $r['periodo']);
        Http::assertSent(fn ($req) =>
            str_contains($req->url(), '/relatorios/mensal/202604')
        );
    }

    public function test_relatorioMensal_cacheia_24h(): void
    {
        Http::fake([
            '*/relatorios/mensal*' => Http::response(['periodo' => '202605'], 200),
        ]);

        $s = new EcfDriveService();
        $s->relatorioMensal();
        $s->relatorioMensal();

        Http::assertSentCount(1);
    }
}
