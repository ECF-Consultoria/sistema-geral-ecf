<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\PanoramaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-05 (D-02, AL166-03): o panorama junta as fontes e cada uma falha sozinha. */
class PanoramaTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Estado lido pela closure do fake: muda aqui, nunca no fake. */
    private bool $convitesFora = false;

    private bool $publicidadeFora = false;

    private function panorama(): PanoramaService
    {
        return app(PanoramaService::class);
    }

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', fn (Request $r) => $this->convitesFora
            ? Http::response(['message' => 'erro'], 500)
            : Http::response(self::fixtureAlavanca('doc/promocoes/users_promotions'), 200));
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));
        $this->responder('GET', '#^/advertising/advertisers$#', self::fixtureAlavanca('doc/publicidade/advertisers'));
        $this->responder('GET', '#/product_ads/campaigns/search$#', fn (Request $r) => $this->publicidadeFora
            ? Http::response(['message' => 'erro'], 500)
            : Http::response(self::fixtureAlavanca('doc/publicidade/campaigns_search'), 200));
        $this->responder('GET', '#^/advertising/advertisers/bonifications$#', self::fixtureAlavanca('doc/publicidade/bonifications'));
    }

    public function test_junta_conta_convites_cupons_publicidade_e_atacado(): void
    {
        $this->cenario();

        $p = $this->panorama()->montar($this->contaAlavanca());

        $this->assertSame(['conta', 'convites', 'cupons', 'publicidade', 'atacado'], array_keys($p));
        $this->assertArrayHasKey('nickname', $p['conta']);
        $this->assertArrayHasKey('reputacao', $p['conta']);
        $this->assertArrayNotHasKey('erro', $p['conta']);

        // Só tipos que chegam como convite do ML (fora SELLER_CAMPAIGN e o cupom).
        $tipos = array_column($p['convites']['itens'], 'tipo');
        sort($tipos);
        $this->assertSame(['DEAL', 'DOD', 'MARKETPLACE_CAMPAIGN', 'SMART', 'VOLUME'], $tipos);
        foreach ($p['convites']['itens'] as $c) {
            $this->assertArrayHasKey('dias_para_vencer', $c);
            $this->assertIsArray($c['alertas']);
        }

        $this->assertCount(1, $p['cupons']['itens']);
        $this->assertEquals(8200, $p['cupons']['itens'][0]['saldo']);

        $this->assertSame(1, $p['publicidade']['campanhas_ativas']);
        $this->assertArrayHasKey('business', $p['atacado']);
        $this->assertIsBool($p['atacado']['business']);
    }

    public function test_convite_vencido_nao_aparece_e_o_que_vence_logo_recebe_alerta(): void
    {
        $this->cenario();
        $convites = self::fixtureAlavanca('doc/promocoes/users_promotions');
        $convites['results'] = [
            ['id' => 'P-MLB1', 'type' => 'DEAL', 'status' => 'candidate', 'deadline_date' => '2020-01-01T23:59:59', 'name' => 'Velho'],
            ['id' => 'P-MLB2', 'type' => 'DEAL', 'status' => 'candidate', 'deadline_date' => now('America/Sao_Paulo')->addDay()->format('Y-m-d').'T23:59:59', 'name' => 'Amanhã'],
            ['id' => 'P-MLB3', 'type' => 'DEAL', 'status' => 'finished', 'name' => 'Sem prazo e encerrado'],
            ['id' => 'P-MLB4', 'type' => 'DEAL', 'status' => 'candidate', 'name' => 'Sem prazo'],
        ];
        $convites['paging'] = ['total' => 4];
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', $convites);

        $itens = $this->panorama()->montar($this->contaAlavanca())['convites']['itens'];

        $this->assertSame(['P-MLB2', 'P-MLB4'], array_column($itens, 'id'));
        $this->assertSame('prazo', $itens[0]['alertas'][0]['codigo']);
        $this->assertSame([], $itens[1]['alertas']);
    }

    public function test_convites_fora_vira_erro_curto_e_as_outras_fontes_seguem(): void
    {
        $this->cenario();
        $this->convitesFora = true;

        $p = $this->panorama()->montar($this->contaAlavanca());

        $this->assertSame(['erro' => 'Não deu para ler agora.'], $p['convites']);
        // Os cupons vêm da mesma fonte (a lista de convites).
        $this->assertSame(['erro' => 'Não deu para ler agora.'], $p['cupons']);
        $this->assertArrayNotHasKey('erro', $p['conta']);
        $this->assertArrayNotHasKey('erro', $p['publicidade']);
        $this->assertSame(1, $p['publicidade']['campanhas_ativas']);
        $this->assertArrayHasKey('business', $p['atacado']);
    }

    public function test_publicidade_fora_vira_erro_curto_e_as_outras_fontes_seguem(): void
    {
        $this->cenario();
        $this->publicidadeFora = true;

        $p = $this->panorama()->montar($this->contaAlavanca());

        $this->assertSame(['erro' => 'Não deu para ler agora.'], $p['publicidade']);
        $this->assertNotEmpty($p['convites']['itens']);
        $this->assertCount(1, $p['cupons']['itens']);
        $this->assertArrayNotHasKey('erro', $p['conta']);
    }

    public function test_conta_fora_derruba_tambem_o_atacado(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/users/me$#', ['message' => 'erro'], 500);

        $p = $this->panorama()->montar($this->contaAlavanca());

        $this->assertSame(['erro' => 'Não deu para ler agora.'], $p['conta']);
        $this->assertSame(['erro' => 'Não deu para ler agora.'], $p['atacado']);
        $this->assertNotEmpty($p['convites']['itens']);
    }

    public function test_erro_de_fonte_nao_fica_em_cache(): void
    {
        $this->cenario();
        $this->convitesFora = true;
        $this->assertArrayHasKey('erro', $this->panorama()->montar($this->contaAlavanca())['convites']);

        $this->convitesFora = false;
        $p = $this->panorama()->montar($this->contaAlavanca());

        $this->assertArrayNotHasKey('erro', $p['convites']);
        $this->assertNotEmpty($p['convites']['itens']);
        $this->assertCount(1, $p['cupons']['itens']);
    }

    public function test_atualizar_refaz_as_leituras(): void
    {
        $this->cenario();
        $this->panorama()->montar($this->contaAlavanca());
        $antes = count($this->chamadas);

        $this->panorama()->montar($this->contaAlavanca());
        $this->assertCount($antes, $this->chamadas, 'a 2ª montagem sai do cache');

        $this->panorama()->montar($this->contaAlavanca(), true);
        $this->assertGreaterThan($antes, count($this->chamadas));
        $this->assertSame([], $this->chamadasNaoGet());
    }
}
