<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\PromocoesLeitura;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-04: leitura da Central de promoções (só GET, app_version=v2). */
class PromocoesLeituraTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Quando verdadeiro, o servidor de mentira recusa qualquer search_after (cursor expirado). */
    private bool $cursorExpira = false;

    private function leitura(): PromocoesLeitura
    {
        return app(PromocoesLeitura::class);
    }

    private function cenario(string $ancora = 'company'): void
    {
        $this->montarAlavancas($ancora);
        $this->responder('GET', '#^/items$#', self::fixtureAlavanca('doc/produtos/items_multiget'));
    }

    private function chamadasPromocoes(): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => str_starts_with($c['caminho'], '/seller-promotions')));
    }

    public function test_convites_normaliza_e_pede_com_app_version_v2(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', self::fixtureAlavanca('doc/promocoes/users_promotions'));

        $r = $this->leitura()->convites($this->contaAlavanca());

        $c = $this->chamadasPromocoes()[0];
        $this->assertSame('/seller-promotions/users/1555596317', $c['caminho']);
        $this->assertSame('v2', $c['query']['app_version']);
        $this->assertSame('50', (string) $c['query']['limit']);
        $this->assertSame('0', (string) $c['query']['offset']);
        $this->assertCount(7, $r['itens']);
        $this->assertSame(7, $r['total']);
        $this->assertFalse($r['truncado']);

        $deal = $r['itens'][0];
        $this->assertSame(['id' => 'P-MLB1001', 'tipo' => 'DEAL', 'nome' => 'Ofertas da Semana', 'status' => 'started'], array_intersect_key($deal,
            array_flip(['id', 'tipo', 'nome', 'status'])));
        $this->assertSame('2026-10-07T23:59:59', $deal['prazo']);
        $this->assertArrayHasKey('dias_para_vencer', $deal);
        $this->assertSame(5, $r['itens'][1]['beneficios']['meli_percent']);
    }

    public function test_convites_trunca_depois_do_limite_de_paginas(): void
    {
        $this->cenario();
        config(['publicador.alavancas.limites.paginas_convites' => 2]);
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', function (Request $req) {
            return Http::response(['results' => array_map(fn ($i) => ['id' => "P-{$i}", 'type' => 'DEAL'], range(1, 50)),
                'paging' => ['offset' => 0, 'limit' => 50, 'total' => 500]], 200);
        });

        $r = $this->leitura()->convites($this->contaAlavanca());

        $this->assertTrue($r['truncado']);
        $this->assertCount(2, $this->chamadasPromocoes());
        $this->assertSame(500, $r['total']);
    }

    public function test_itens_da_promocao_com_filtro_titulo_e_capacidades(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));

        $r = $this->leitura()->itensDaPromocao($this->contaAlavanca(), 'P-MLB123', 'DEAL', null, ['status' => 'candidate']);

        $c = $this->chamadasPromocoes()[0];
        $this->assertSame('/seller-promotions/promotions/P-MLB123/items', $c['caminho']);
        $this->assertSame('DEAL', $c['query']['promotion_type']);
        $this->assertSame('candidate', $c['query']['status']);
        $this->assertSame('50', (string) $c['query']['limit']);
        $this->assertSame('v2', $c['query']['app_version']);
        $this->assertArrayNotHasKey('search_after', $c['query']);

        $this->assertSame('cursor-fixture-001', $r['proximo']);
        $this->assertFalse($r['reiniciado']);
        $i = $r['itens'][0];
        $this->assertSame('MLB1000000001', $i['item_id']);
        $this->assertSame('Produto de teste MLB1000000001', $i['titulo']);
        $this->assertSame(70, $i['min_preco']);
        $this->assertSame(95, $i['max_preco']);
        $this->assertSame(85, $i['preco_sugerido']);
        $this->assertTrue($i['capacidades']['inscrever']);
        $this->assertTrue($i['capacidades']['preco']);
        $this->assertTrue($r['itens'][2]['capacidades']['remover']);
    }

    public function test_cursor_vai_como_search_after(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));

        $this->leitura()->itensDaPromocao($this->contaAlavanca(), 'P-MLB123', 'DEAL', 'cursor-fixture-001');

        $this->assertSame('cursor-fixture-001', $this->chamadasPromocoes()[0]['query']['search_after']);
    }

    public function test_cursor_expirado_recomeca_uma_vez_sem_cursor(): void
    {
        $this->cenario();
        $this->cursorExpira = true;
        $this->responder('GET', '#/promotions/[^/]+/items#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if ($this->cursorExpira && isset($q['search_after'])) {
                return Http::response(['message' => 'search_after expired', 'error' => 'bad_request', 'status' => 400], 400);
            }

            return Http::response(self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'), 200);
        });

        $r = $this->leitura()->itensDaPromocao($this->contaAlavanca(), 'P-MLB123', 'DEAL', 'velho');

        $this->assertTrue($r['reiniciado']);
        $this->assertCount(3, $r['itens']);
        $chamadas = $this->chamadasPromocoes();
        $this->assertCount(2, $chamadas);
        $this->assertArrayHasKey('search_after', $chamadas[0]['query']);
        $this->assertArrayNotHasKey('search_after', $chamadas[1]['query']);
    }

    public function test_entrada_invalida_vira_alav_ent_sem_chamada(): void
    {
        $this->cenario();
        $c = $this->contaAlavanca();
        $l = $this->leitura();

        foreach ([
            fn () => $l->itensDaPromocao($c, 'P-MLB1', 'INVENTADO'),
            fn () => $l->itensDaPromocao($c, 'P/../x', 'DEAL'),
            fn () => $l->itensDaPromocao($c, 'P-1?x=1', 'DEAL'),
            fn () => $l->itensDaPromocao($c, 'P-1', 'DEAL', 'cur sor;'),
            fn () => $l->itensDaPromocao($c, 'P-1', 'DEAL', null, ['status' => 'qualquer']),
            fn () => $l->promocoesDoItem($c, 'MLB1/../2'),
            fn () => $l->exclusaoDoItem($c, 'abc'),
            fn () => $l->entradasDaPromocao($c, 'P 1', 'DEAL', ['MLB1']),
        ] as $caso) {
            try {
                $caso();
                $this->fail('deveria lançar ALAV-ENT');
            } catch (RegraViolada $e) {
                $this->assertSame('ALAV-ENT', $e->regra);
            }
        }
        $this->assertCount(0, $this->chamadas);
    }

    public function test_smart_so_inscreve_com_offer_id_candidate_da_leitura(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_SMART'));

        $r = $this->leitura()->itensDaPromocao($this->contaAlavanca(), 'P-MLB3003', 'SMART');

        $this->assertTrue($r['itens'][0]['capacidades']['inscrever']);
        $this->assertFalse($r['itens'][0]['capacidades']['preco']);
        $this->assertTrue($r['itens'][0]['boost']['ativo']);
        $this->assertFalse($r['itens'][1]['capacidades']['inscrever']);
        $this->assertStringContainsString('no Mercado Livre', $r['itens'][1]['capacidades']['motivo']);
        $this->assertSame(3, $r['itens'][1]['meli_percentage']);
    }

    public function test_promocoes_do_item_leem_o_item_e_trazem_capacidades(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/promocoes/items_promotions'));

        $r = $this->leitura()->promocoesDoItem($this->contaAlavanca(), 'MLB1');

        $this->assertSame('/seller-promotions/items/MLB1', $this->chamadasPromocoes()[0]['caminho']);
        $this->assertSame(['DEAL', 'DOD', 'PRICE_DISCOUNT'], array_column($r, 'tipo'));
        $this->assertSame('P-MLB1001', $r[0]['promocao_id']);
        $this->assertTrue($r[0]['capacidades']['alterar']);
        $this->assertTrue($r[1]['capacidades']['remover']);          // DOD pending
        $this->assertSame(5, $r[1]['estoque_min']);
        $this->assertNull($r[2]['promocao_id']);
        $this->assertTrue($r[2]['capacidades']['inscrever']);
        $this->assertSame(90, $r[2]['preco_sugerido']);
    }

    public function test_item_na_promocao_nunca_usa_cache(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/promocoes/items_promotions'));
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));
        $c = $this->contaAlavanca();
        $l = $this->leitura();

        // Sem promotion_id: lê pelo item e filtra o tipo.
        $dod = $l->itemNaPromocao($c, null, 'DOD', 'MLB1');
        $this->assertSame('pending', $dod['status']);
        $l->itemNaPromocao($c, null, 'DOD', 'MLB1');
        $this->assertCount(2, $this->chamadasPromocoes());
        $this->assertNull($l->itemNaPromocao($c, null, 'LIGHTNING', 'MLB1'));

        // Com promotion_id: lê os itens da promoção filtrando pelo item.
        $this->chamadas = [];
        $e = $l->itemNaPromocao($c, 'P-MLB1001', 'DEAL', 'MLB1000000003');
        $l->itemNaPromocao($c, 'P-MLB1001', 'DEAL', 'MLB1000000003');
        $this->assertSame('started', $e['status']);
        $this->assertSame('MLB1000000003', $this->chamadasPromocoes()[0]['query']['item_id']);
        $this->assertCount(2, $this->chamadasPromocoes());
    }

    public function test_entradas_da_promocao_acham_varios_numa_chamada(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));

        $r = $this->leitura()->entradasDaPromocao($this->contaAlavanca(), 'P-MLB1001', 'DEAL', ['MLB1000000001', 'MLB1000000007', 'MLB1000000003']);

        $this->assertCount(1, $this->chamadasPromocoes());
        $this->assertSame(['MLB1000000001', 'MLB1000000007', 'MLB1000000003'], array_keys($r));
        $this->assertTrue($r['MLB1000000001']['capacidades']['inscrever']);
        $this->assertTrue($r['MLB1000000003']['capacidades']['alterar']);
    }

    public function test_entradas_da_promocao_param_no_limite_de_paginas_com_id_inexistente(): void
    {
        $this->cenario();
        config(['publicador.alavancas.limites.paginas_preload' => 3]);
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));

        $r = $this->leitura()->entradasDaPromocao($this->contaAlavanca(), 'P-MLB1001', 'DEAL', ['MLB1000000001', 'MLB9999999999']);

        $this->assertCount(3, $this->chamadasPromocoes());
        $this->assertSame(['MLB1000000001'], array_keys($r));
    }

    public function test_exclusao_da_conta_e_do_item(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller$#', self::fixtureAlavanca('doc/promocoes/exclusion_seller'));
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller/MLB1$#', ['item_id' => 'MLB1', 'exclusion_status' => false]);

        $this->assertSame(['excluida' => true], $this->leitura()->exclusaoDaConta($this->contaAlavanca()));
        $this->assertSame(['item_id' => 'MLB1', 'excluido' => false], $this->leitura()->exclusaoDoItem($this->contaAlavanca(), 'MLB1'));
    }

    public function test_detalhe_da_promocao_traz_so_os_campos_conhecidos(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB1001$#', self::fixtureAlavanca('doc/promocoes/promotion_DEAL'));

        $p = $this->leitura()->promocao($this->contaAlavanca(), 'P-MLB1001', 'DEAL');

        $this->assertSame('Ofertas da Semana', $p['name']);
        $this->assertSame('DEAL', $this->chamadasPromocoes()[0]['query']['promotion_type']);
    }

    public function test_funciona_para_mlb_empresa_sem_company_e_so_faz_get_com_app_version(): void
    {
        $this->cenario('mlb_empresa');
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', self::fixtureAlavanca('doc/promocoes/users_promotions'));
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));
        $this->responder('GET', '#^/seller-promotions/(items|exclusion-list)/#', ['exclusion_status' => 'false']);
        $c = $this->contaAlavanca();
        $l = $this->leitura();

        $l->convites($c);
        $l->itensDaPromocao($c, 'P-MLB1001', 'DEAL');
        $l->promocoesDoItem($c, 'MLB1');
        $l->exclusaoDaConta($c);

        $this->assertSame([], $this->chamadasNaoGet());
        foreach ($this->chamadasPromocoes() as $ch) {
            $this->assertSame('v2', $ch['query']['app_version'], $ch['caminho']);
        }
    }

    public function test_fonte_so_chama_get(): void
    {
        $fonte = file_get_contents(base_path('app/Services/Publicador/Alavancas/PromocoesLeitura.php'));
        preg_match_all("/daConta\([^;]*?'(\w+)'/s", $fonte, $m);
        $this->assertSame(['GET'], array_values(array_unique($m[1])));
    }
}
