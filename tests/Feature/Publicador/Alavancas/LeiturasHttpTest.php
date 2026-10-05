<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\DatasDoMl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-10: as leituras JSON das Alavancas (contrato para a tela). */
class LeiturasHttpTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function url(string $sufixo): string
    {
        return "/mlb/anuncios/publicador/empresas/{$this->ancora->chaveContaMl()}/alavancas/{$sufixo}";
    }

    private function lerJson(string $sufixo)
    {
        return $this->actingAs($this->admin)->getJson($this->url($sufixo));
    }

    private function enviarJson(string $sufixo, array $corpo)
    {
        return $this->actingAs($this->admin)->postJson($this->url($sufixo), $corpo);
    }

    /** Multiget que devolve um anúncio válido da conta para cada id pedido. */
    private function multiget(): void
    {
        $this->responder('GET', '#^/items$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'status' => 'active',
                'listing_type_id' => 'gold_special', 'category_id' => 'MLB1000', 'condition' => 'new', 'available_quantity' => 50,
                'shipping' => ['logistic_type' => 'fulfillment', 'mode' => 'me2', 'free_shipping' => true],
            ]], explode(',', $q['ids'])), 200);
        });
    }

    private function cenarioPromocoes(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', self::fixtureAlavanca('doc/promocoes/users_promotions'));
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', self::fixtureAlavanca('doc/promocoes/promotion_items_DEAL'));
        $this->responder('GET', '#^/items$#', self::fixtureAlavanca('doc/produtos/items_multiget'));
    }

    private function cenarioPublicidade(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/advertising/advertisers$#', self::fixtureAlavanca('doc/publicidade/advertisers'));
        $this->responder('GET', '#/product_ads/campaigns/search$#', self::fixtureAlavanca('doc/publicidade/campaigns_search'));
        $this->responder('GET', '#/product_ads/ad_groups/search$#', self::fixtureAlavanca('doc/publicidade/ad_groups_search'));
        $this->responder('GET', '#^/advertising/advertisers/bonifications$#', self::fixtureAlavanca('doc/publicidade/bonifications'));
    }

    // ═══ Panorama ═══

    public function test_panorama_junta_as_fontes(): void
    {
        $this->cenarioPublicidade();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', self::fixtureAlavanca('doc/promocoes/users_promotions'));
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));

        $r = $this->lerJson('panorama')->assertOk();

        $this->assertSame(['conta', 'convites', 'cupons', 'publicidade', 'atacado'], array_keys($r->json()));
        $this->assertArrayNotHasKey('erro', $r->json('conta'));
    }

    public function test_panorama_responde_200_com_uma_fonte_fora(): void
    {
        $this->cenarioPublicidade();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', ['message' => 'erro'], 500);
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));

        $r = $this->lerJson('panorama')->assertOk();

        $this->assertSame('Não deu para ler agora.', $r->json('convites.erro'));
        $this->assertArrayNotHasKey('erro', $r->json('publicidade'));
        $this->assertArrayNotHasKey('erro', $r->json('conta'));
    }

    // ═══ Promoções ═══

    public function test_promocoes_devolve_os_convites_com_alertas(): void
    {
        $this->cenarioPromocoes();

        $r = $this->lerJson('promocoes')->assertOk();

        $this->assertCount(7, $r->json('itens'));
        $this->assertIsArray($r->json('itens.0.alertas'));
    }

    public function test_itens_da_promocao_valida_o_tipo_e_devolve_itens_e_proximo(): void
    {
        $this->cenarioPromocoes();

        $this->lerJson('promocoes/P-MLB123/itens?tipo=XYZ')->assertStatus(422);
        $this->lerJson('promocoes/P-MLB123/itens')->assertStatus(422);
        $this->lerJson('promocoes/P-MLB123/itens?tipo=DEAL&status=qualquer')->assertStatus(422);

        $r = $this->lerJson('promocoes/P-MLB123/itens?tipo=DEAL&status=candidate')->assertOk();
        $this->assertSame('cursor-fixture-001', $r->json('proximo'));
        $this->assertSame('MLB1000000001', $r->json('itens.0.item_id'));
    }

    public function test_promocao_com_caracteres_invalidos_na_rota_da_404(): void
    {
        $this->cenarioPromocoes();

        $this->lerJson('promocoes/P..MLB;1/itens?tipo=DEAL')->assertNotFound();
        $this->assertSame([], $this->chamadas);
    }

    public function test_promocoes_do_item(): void
    {
        $this->cenarioPromocoes();
        $this->responder('GET', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/promocoes/items_promotions'));

        $this->lerJson('produtos/MLB1/promocoes')->assertOk()->assertJsonStructure(['itens']);
        $this->lerJson('produtos/XYZ/promocoes')->assertNotFound();
    }

    // ═══ Produtos e análise ═══

    public function test_produtos_com_pagina_e_busca(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/users/\d+/items/search$#', self::fixtureAlavanca('doc/produtos/items_search'));
        $this->multiget();

        $r = $this->lerJson('produtos?pagina=2&busca=CAD')->assertOk();

        $this->assertSame(2, $r->json('pagina'));
        $this->assertSame(50, $r->json('por_pagina'));
        $this->assertArrayHasKey('itens', $r->json());
        $this->assertArrayHasKey('total', $r->json());
        $this->lerJson('produtos?pagina=0')->assertStatus(422);
    }

    public function test_analise_recusa_mais_que_o_limite_e_aceita_ate_o_limite(): void
    {
        $this->montarAlavancas();
        $this->multiget();
        $this->responder('GET', '#^/sites/MLB/listing_prices$#', fn (Request $req) => Http::response(['listing_type_id' => 'gold_special', 'sale_fee_amount' => 15], 200));
        $this->responder('GET', '#^/users/\d+/shipping_options/free$#', self::fixtureSondagem('conta/shipping_options_free_79'));

        $onze = array_map(fn ($i) => ['item_id' => "MLB{$i}", 'promotion_type' => 'DEAL', 'preco_promocao' => 80], range(1, 11));
        $this->enviarJson('analise', ['itens' => $onze])->assertStatus(422);
        $this->enviarJson('analise', ['itens' => []])->assertStatus(422);
        $this->enviarJson('analise', ['itens' => [['item_id' => 'xyz']]])->assertStatus(422);

        $r = $this->enviarJson('analise', ['itens' => [
            ['item_id' => 'MLB1', 'promotion_type' => 'DEAL', 'preco_promocao' => 80],
            ['item_id' => 'MLB2', 'promotion_type' => 'DEAL', 'preco_promocao' => 90],
        ]])->assertOk();

        $this->assertCount(2, $r->json('itens'));
        $this->assertArrayHasKey('parcial', $r->json());
        $this->assertSame([], $this->chamadasNaoGet());
    }

    // ═══ Cupons e exclusão ═══

    public function test_cupons_e_exclusao(): void
    {
        $this->cenarioPromocoes();
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller$#', ['excluded' => true]);
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller/MLB\d+$#', ['excluded' => false]);

        $this->lerJson('cupons')->assertOk()->assertJsonStructure(['itens', 'truncado']);
        $this->lerJson('exclusao')->assertOk()->assertJson(['excluida' => true]);
        $this->lerJson('exclusao/MLB1')->assertOk()->assertJson(['item_id' => 'MLB1', 'excluido' => false]);
    }

    public function test_falha_de_leitura_num_endpoint_vira_502_com_texto_fixo(): void
    {
        $this->cenarioPromocoes();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', ['message' => 'erro interno do ML'], 500);

        $r = $this->lerJson('promocoes')->assertStatus(502);

        $this->assertSame(['message' => 'Não deu para ler agora. Tente de novo em instantes.'], $r->json());
    }

    // ═══ Publicidade ═══

    public function test_publicidade_usa_os_ultimos_30_dias_e_junta_campanhas_e_bonificacoes(): void
    {
        $this->cenarioPublicidade();

        $r = $this->lerJson('publicidade')->assertOk();

        $this->assertSame(DatasDoMl::hoje()->format('Y-m-d'), $r->json('ate'));
        $this->assertSame(DatasDoMl::hoje()->subDays(29)->format('Y-m-d'), $r->json('de'));
        $this->assertArrayHasKey('anunciante', $r->json());
        $this->assertNotEmpty($r->json('campanhas'));
        $this->assertArrayHasKey('resumo', $r->json());
        $this->assertArrayHasKey('saldo_total', $r->json('bonificacoes'));
    }

    public function test_publicidade_sem_product_ads_devolve_so_a_explicacao(): void
    {
        $this->cenarioPublicidade();
        $this->responder('GET', '#^/advertising/advertisers$#', ['message' => 'No permissions found', 'status' => 404], 404);

        $r = $this->lerJson('publicidade')->assertOk();

        $this->assertSame(['indisponivel'], array_keys($r->json()));
    }

    public function test_publicidade_com_janela_maior_que_90_dias_da_422_alav_data(): void
    {
        $this->cenarioPublicidade();
        $de = DatasDoMl::hoje()->subDays(120)->format('Y-m-d');

        $this->lerJson("publicidade?de={$de}")->assertStatus(422)->assertJson(['regra' => 'ALAV-DATA']);
        $this->lerJson('publicidade?de=ontem')->assertStatus(422);
    }

    public function test_ad_groups(): void
    {
        $this->cenarioPublicidade();

        $this->lerJson('publicidade/ad-groups?itens=MLB1,MLB2')->assertOk()->assertJsonStructure(['ad_groups']);
        $this->lerJson('publicidade/ad-groups?itens=MLB1,xx')->assertStatus(422);
    }

    // ═══ Atacado ═══

    public function test_atacado_diz_se_a_conta_tem_business_e_explica_quando_nao_tem(): void
    {
        $this->montarAlavancas();
        $this->lerJson('atacado')->assertOk()->assertJson(['business' => true, 'explicacao' => null]);

        $this->montarAlavancas();
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'tags' => ['normal']];
        $r = $this->lerJson('atacado?atualizar=1')->assertOk();
        $this->assertFalse($r->json('business'));
        $this->assertSame('O Mercado Livre libera o preço por quantidade por convite; esta conta ainda não tem essa liberação.', $r->json('explicacao'));
    }

    public function test_atacado_do_item_devolve_faixas_com_versao(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB1/prices$#', self::fixtureAlavanca('doc/atacado/prices_com_faixas'));

        $this->lerJson('atacado/MLB1')->assertOk()->assertJsonStructure(['versao', 'preco_padrao', 'faixas', 'tem_faixas']);
    }

    public function test_recomendacoes_em_conta_nao_liberada_da_403_sem_nenhuma_chamada(): void
    {
        $this->montarAlavancas('company', false);

        $this->enviarJson('atacado/MLB1/recomendacoes', ['quantidades' => [2], 'preco' => 100])
            ->assertStatus(403)->assertJson(['regra' => 'ALAV-LIB']);

        $this->assertSame([], $this->chamadas, 'nem o /users/me');
    }

    public function test_recomendacoes_em_conta_liberada_so_faz_o_post_de_recomendacoes(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', self::fixtureAlavanca('doc/atacado/recommendations'));

        $r = $this->enviarJson('atacado/MLB1/recomendacoes', ['quantidades' => [2, 5, 10], 'preco' => 100])->assertOk();

        $this->assertCount(3, $r->json('recomendacoes'));
        $posts = $this->chamadasNaoGet();
        $this->assertCount(1, $posts);
        $this->assertSame('/prices-per-quantity/v1/recommendations', $posts[0]['caminho']);
    }

    public function test_recomendacoes_valida_o_corpo(): void
    {
        $this->montarAlavancas();

        $this->enviarJson('atacado/MLB1/recomendacoes', ['quantidades' => [], 'preco' => 100])->assertStatus(422);
        $this->enviarJson('atacado/MLB1/recomendacoes', ['quantidades' => [0], 'preco' => 100])->assertStatus(422);
        $this->enviarJson('atacado/MLB1/recomendacoes', ['quantidades' => [2], 'preco' => 0])->assertStatus(422);
        $this->assertSame([], $this->chamadasNaoGet());
    }
}
