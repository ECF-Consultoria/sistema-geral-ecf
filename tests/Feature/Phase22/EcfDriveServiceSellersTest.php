<?php

// Phase 22 — testes do domínio /sellers/* do wrapper EcfDriveService.

namespace Tests\Feature\Phase22;

use App\Services\EcfDriveService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcfDriveServiceSellersTest extends TestCase
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

    // ─── W2-T1: seller, sellerMetricasMensal, sellerMetricasDiario ───

    public function test_seller_retorna_payload_consolidado(): void
    {
        Http::fake([
            '*/sellers/1007473885*' => Http::response([
                'custId'        => '1007473885',
                'razaoSocial'   => 'ECF CONSULTORIA',
                'medalhaAtual'  => ['nivelSolucion' => 'PLATINUM'],
            ], 200),
        ]);

        $r = (new EcfDriveService())->seller('1007473885');

        $this->assertSame('1007473885', $r['custId']);
        $this->assertSame('PLATINUM', $r['medalhaAtual']['nivelSolucion']);
    }

    public function test_sellerMetricasMensal_cacheia_1h(): void
    {
        Http::fake([
            '*/sellers/X/metricas/mensal*' => Http::response([
                'data' => [['timMonthId' => '202605', 'tgmvLc' => '6965.37']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->sellerMetricasMensal('X', ['from' => '2026-01', 'to' => '2026-12']);
        $s->sellerMetricasMensal('X', ['from' => '2026-01', 'to' => '2026-12']);

        Http::assertSentCount(1);
    }

    public function test_sellerMetricasMensal_aceita_fields_raw(): void
    {
        Http::fake(['*/sellers/X/metricas/mensal*' => Http::response(['data' => []], 200)]);

        (new EcfDriveService())->sellerMetricasMensal('X', ['fields' => 'raw']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'fields=raw'));
    }

    public function test_sellerMetricasDiario_nao_cacheia(): void
    {
        Http::fake(['*/sellers/X/metricas/diario*' => Http::response(['data' => []], 200)]);

        $s = new EcfDriveService();
        $s->sellerMetricasDiario('X');
        $s->sellerMetricasDiario('X');

        Http::assertSentCount(2);
    }

    // ─── W2-T2: sellerMedalhas, sellerSignals, ranking, compararSellers ───

    public function test_sellerMedalhas_cacheia_6h(): void
    {
        Http::fake([
            '*/sellers/X/medalhas*' => Http::response([
                'data' => [['timMonthId' => '202605', 'nivelSolucion' => 'PLATINUM']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->sellerMedalhas('X');
        $s->sellerMedalhas('X');

        Http::assertSentCount(1);
    }

    public function test_sellerSignals_cacheia_5min(): void
    {
        Http::fake([
            '*/sellers/X/signals*' => Http::response([
                'data' => [['id' => 10, 'eventType' => 'TGMV_DROP']],
            ], 200),
        ]);

        $s = new EcfDriveService();
        $s->sellerSignals('X');
        $s->sellerSignals('X');

        Http::assertSentCount(1);
    }

    public function test_ranking_caminho_feliz_retorna_top(): void
    {
        Http::fake([
            '*/sellers/ranking*' => Http::response([
                'metrica' => 'tgmv_lc',
                'data'    => [
                    ['rank' => 1, 'custId' => '570267839', 'valor' => 2733708.08],
                ],
            ], 200),
        ]);

        $r = (new EcfDriveService())->ranking('tgmv_lc', 1);

        $this->assertSame(1, $r['data'][0]['rank']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'metrica=tgmv_lc'));
    }

    public function test_ranking_lanca_invalidargument_em_metrica_desconhecida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("não suportada");

        (new EcfDriveService())->ranking('metrica_inexistente');
    }

    public function test_compararSellers_lanca_invalidargument_com_21_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1 a 20');

        $ids = array_map(fn ($i) => (string) $i, range(1, 21));
        (new EcfDriveService())->compararSellers($ids);
    }

    public function test_compararSellers_envia_cust_ids_csv(): void
    {
        Http::fake(['*/sellers/comparar*' => Http::response(['data' => []], 200)]);

        (new EcfDriveService())->compararSellers(['A', 'B', 'C']);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'cust_ids=A%2CB%2CC'));
    }
}
