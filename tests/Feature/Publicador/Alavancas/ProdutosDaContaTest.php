<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\ProdutosDaContaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-04: produtos da conta ao vivo, para as duas âncoras. */
class ProdutosDaContaTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Multiget que devolve um corpo válido para cada id pedido. */
    private function multigetGerado(): void
    {
        $this->responder('GET', '#^/items$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $lista = array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 10, 'status' => 'active',
                'listing_type_id' => 'gold_special', 'condition' => 'new', 'available_quantity' => 5,
            ]], explode(',', $q['ids']));

            return Http::response($lista, 200);
        });
    }

    private function buscaCom(array $resposta): void
    {
        $this->responder('GET', '#^/users/\d+/items/search$#', $resposta);
    }

    private function chamadasMultiget(): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => $c['caminho'] === '/items' && isset($c['query']['ids'])));
    }

    public function test_lista_a_primeira_pagina_e_faz_tres_multigets_para_50_ids(): void
    {
        $this->montarAlavancas('company');
        $this->buscaCom(self::fixtureAlavanca('doc/produtos/items_search'));
        $this->multigetGerado();

        $r = app(ProdutosDaContaService::class)->listar($this->contaAlavanca(), null, 1);

        $this->assertCount(50, $r['itens']);
        $this->assertSame(120, $r['total']);
        $this->assertSame(50, $r['por_pagina']);
        $busca = collect($this->chamadas)->firstWhere('caminho', '/users/1555596317/items/search');
        $this->assertSame('active', $busca['query']['status']);
        $this->assertSame('50', (string) $busca['query']['limit']);
        $this->assertSame('0', (string) $busca['query']['offset']);

        $lotes = $this->chamadasMultiget();
        $this->assertCount(3, $lotes);
        $this->assertSame([20, 20, 10], array_map(fn ($c) => count(explode(',', $c['query']['ids'])), $lotes));
        $this->assertSame(ProdutosDaContaService::ATRIBUTOS, $lotes[0]['query']['attributes']);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_item_404_no_multiget_e_pulado_e_o_resto_vem_normalizado(): void
    {
        $this->montarAlavancas('company');
        $this->buscaCom(['results' => ['MLB1000000001', 'MLB1000000002', 'MLB1000000003'], 'paging' => ['total' => 3]]);
        $this->responder('GET', '#^/items$#', self::fixtureAlavanca('doc/produtos/items_multiget'));
        Log::spy();

        $r = app(ProdutosDaContaService::class)->listar($this->contaAlavanca(), null, 1);

        $this->assertSame(['MLB1000000001', 'MLB1000000003'], array_column($r['itens'], 'id'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '[Alavancas] item falhou no multiget'))->once();
        $primeiro = $r['itens'][0];
        $this->assertSame('SKU-MLB1000000001', $primeiro['sku']);
        $this->assertSame(10.0, $primeiro['pacote']['altura']);
        $this->assertSame(300.0, $primeiro['pacote']['peso']);
        $this->assertSame('cross_docking', $primeiro['frete']['logistic_type']);
        $this->assertTrue($primeiro['elegivel']);
    }

    public function test_funciona_igual_para_mlb_empresa_sem_company(): void
    {
        $this->montarAlavancas('mlb_empresa');
        $this->buscaCom(['results' => ['MLB1000000001'], 'paging' => ['total' => 1]]);
        $this->multigetGerado();

        $c = $this->contaAlavanca();
        $this->assertNull($c->company);
        $r = app(ProdutosDaContaService::class)->listar($c, null, 1);

        $this->assertCount(1, $r['itens']);
        $this->assertSame('/users/1555596317/items/search', $this->chamadas[0]['caminho']);
    }

    public function test_pagina_alem_do_teto_nao_chama_o_ml(): void
    {
        $this->montarAlavancas('company');

        $r = app(ProdutosDaContaService::class)->listar($this->contaAlavanca(), null, 22);

        $this->assertSame([], $r['itens']);
        $this->assertSame('Para ver além dos primeiros 1.000 produtos, busque por SKU ou título.', $r['aviso']);
        $this->assertCount(0, $this->chamadas);
    }

    public function test_busca_por_sku_e_depois_por_q_quando_nao_acha(): void
    {
        $this->montarAlavancas('company');
        $this->responder('GET', '#^/users/\d+/items/search$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return Http::response(isset($q['q'])
                ? ['results' => ['MLB1000000001'], 'paging' => ['total' => 1]]
                : ['results' => [], 'paging' => ['total' => 0]], 200);
        });
        $this->multigetGerado();

        $r = app(ProdutosDaContaService::class)->listar($this->contaAlavanca(), 'CAD-01', 1);

        $buscas = array_values(array_filter($this->chamadas, fn ($c) => str_ends_with($c['caminho'], '/items/search')));
        $this->assertCount(2, $buscas);
        $this->assertSame('CAD-01', $buscas[0]['query']['seller_sku']);
        $this->assertSame('CAD-01', $buscas[1]['query']['q']);
        $this->assertCount(1, $r['itens']);
        $this->assertFalse($r['busca_local']);
    }

    public function test_q_recusado_com_400_filtra_localmente_pelo_titulo(): void
    {
        $this->montarAlavancas('company');
        $this->responder('GET', '#^/users/\d+/items/search$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (isset($q['q'])) {
                return Http::response(['message' => 'invalid', 'error' => 'bad_request', 'status' => 400], 400);
            }

            return Http::response(isset($q['seller_sku'])
                ? ['results' => [], 'paging' => ['total' => 0]]
                : ['results' => ['MLB1000000001', 'MLB1000000002'], 'paging' => ['total' => 2]], 200);
        });
        $this->multigetGerado();

        $r = app(ProdutosDaContaService::class)->listar($this->contaAlavanca(), 'produto mlb1000000002', 1);

        $this->assertTrue($r['busca_local']);
        $this->assertSame(['MLB1000000002'], array_column($r['itens'], 'id'));
    }

    public function test_a_mesma_listagem_em_5_minutos_nao_chama_o_ml_de_novo(): void
    {
        $this->montarAlavancas('company');
        $this->buscaCom(['results' => ['MLB1000000001'], 'paging' => ['total' => 1]]);
        $this->multigetGerado();
        $servico = app(ProdutosDaContaService::class);
        $c = $this->contaAlavanca();

        $servico->listar($c, 'x', 1);
        $antes = count($this->chamadas);
        $servico->listar($c, 'x', 1);
        $this->assertCount($antes, $this->chamadas);

        $servico->listar($c, 'x', 2);
        $this->assertGreaterThan($antes, count($this->chamadas));
    }

    public function test_gratis_usado_e_pausado_saem_inelegiveis_com_motivo(): void
    {
        $this->montarAlavancas('company');
        $this->responder('GET', '#^/items$#', self::fixtureAlavanca('doc/produtos/items_multiget'));

        $mapa = app(ProdutosDaContaService::class)->porIds($this->contaAlavanca(),
            ['MLB1000000003', 'MLB1000000004', 'MLB1000000005', 'MLB1000000007']);

        $this->assertFalse($mapa['MLB1000000003']['elegivel']);
        $this->assertSame(['Anúncio grátis não entra em promoção.'], $mapa['MLB1000000003']['motivos']);
        $this->assertSame(['Só produto novo entra em promoção.'], $mapa['MLB1000000004']['motivos']);
        $this->assertSame(['Anúncio não está ativo.'], $mapa['MLB1000000005']['motivos']);
        $this->assertSame('SKU-ATRIBUTO', $mapa['MLB1000000007']['sku']);
    }

    public function test_por_ids_em_lotes_de_20_e_ignora_id_invalido(): void
    {
        $this->montarAlavancas('company');
        $this->multigetGerado();
        $ids = array_map(fn ($i) => 'MLB'.(2000000000 + $i), range(1, 45));

        $mapa = app(ProdutosDaContaService::class)->porIds($this->contaAlavanca(), [...$ids, 'MLB1; DROP', 'abc', $ids[0]]);

        $this->assertCount(45, $mapa);
        $this->assertCount(3, $this->chamadasMultiget());
    }

    public function test_item_de_outro_vendedor_com_code_200_fica_fora_do_mapa(): void
    {
        $this->montarAlavancas('company');
        $this->responder('GET', '#^/items$#', self::fixtureAlavanca('doc/produtos/items_multiget'));
        Log::spy();

        $mapa = app(ProdutosDaContaService::class)->porIds($this->contaAlavanca(), ['MLB1000000001', 'MLB1000000006']);

        $this->assertArrayHasKey('MLB1000000001', $mapa);
        $this->assertArrayNotHasKey('MLB1000000006', $mapa);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '[Alavancas] item MLB1000000006 não é do vendedor'))->once();
    }
}
