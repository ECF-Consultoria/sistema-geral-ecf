<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\EstruturaAnuncio;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Services\Publicador\Alavancas\AnaliseAlavancasService;
use App\Support\Publicador\Validacao\SimuladorVoceRecebe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-06: quanto a loja recebe (normal x promoção), o que o ML banca, margem e alertas. */
class AnaliseRecebeTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Tarifa de teste: 15% do preço da query, para os números baterem. */
    private const TARIFA = 0.15;

    /** Frete real da sondagem (R$ 79): list_cost 14,45. */
    private const FRETE = 14.45;

    /** @var array<string, array> corpos de /items por id */
    private array $anuncios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function anuncio(string $id, array $extra = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'status' => 'active',
            'listing_type_id' => 'gold_special', 'category_id' => 'MLB1000', 'condition' => 'new', 'available_quantity' => 50,
            'shipping' => ['logistic_type' => 'fulfillment', 'mode' => 'me2', 'free_shipping' => true, 'dimensions' => '15x15x20,500'],
        ], $extra);
    }

    private function montar(string $ancora = 'company'): void
    {
        $this->montarAlavancas($ancora);
        RateLimiter::clear('alavancas:analise:'.$this->contaAlavanca()->chaveConta());

        $this->responder('GET', '#^/items$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $lista = array_map(fn ($id) => isset($this->anuncios[$id])
                ? ['code' => 200, 'body' => $this->anuncios[$id]]
                : ['code' => 404, 'body' => ['message' => 'not found']], explode(',', $q['ids']));

            return Http::response($lista, 200);
        });
        $this->responder('GET', '#^/sites/MLB/listing_prices$#', function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return Http::response(['listing_type_id' => $q['listing_type_id'], 'sale_fee_amount' => round($q['price'] * self::TARIFA, 2)], 200);
        });
        $this->responder('GET', '#^/users/\d+/shipping_options/free$#', self::fixtureSondagem('conta/shipping_options_free_79'));
    }

    private function com(string ...$ids): void
    {
        foreach ($ids as $id) {
            $this->anuncios[$id] = $this->anuncio($id);
        }
    }

    private function chamadasDe(string $caminhoRegex): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => preg_match($caminhoRegex, $c['caminho'])));
    }

    private function analisar(array $pedidos): array
    {
        return app(AnaliseAlavancasService::class)->analisar($this->contaAlavanca(), $pedidos);
    }

    public function test_deal_calcula_recebe_normal_e_na_promocao_com_tarifa_e_frete_da_api(): void
    {
        $this->montar();
        $this->com('MLB1');

        $r = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'DEAL', 'preco_promocao' => 80]]);
        $i = $r['itens'][0];

        $this->assertFalse($r['parcial']);
        $this->assertTrue($i['calculado']);
        $this->assertSame(SimuladorVoceRecebe::calcular(100.0, 15.0, self::FRETE), $i['recebe_normal']);
        $this->assertSame(SimuladorVoceRecebe::calcular(80.0, 12.0, self::FRETE), $i['recebe_promocao']);
        $this->assertSame(20.0, $i['desconto_percentual']);
        $this->assertSame(100.0, $i['preco_atual']);
        $this->assertSame(80.0, $i['preco_promocao']);
        $this->assertFalse($i['estimativa']);
        $this->assertFalse($i['depende_do_carrinho']);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_tarifa_leva_a_logistica_do_proprio_anuncio(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['shipping' => ['logistic_type' => 'xd_drop_off', 'mode' => 'me2']]);

        $this->analisar([['item_id' => 'MLB1']]);

        $tarifa = $this->chamadasDe('#listing_prices#')[0];
        $this->assertSame('xd_drop_off', $tarifa['query']['logistic_type']);
        $this->assertSame('me2', $tarifa['query']['shipping_mode']);
        $this->assertSame('MLB1000', $tarifa['query']['category_id']);
        $this->assertSame('BRL', $tarifa['query']['currency_id']);
        $frete = $this->chamadasDe('#shipping_options/free#')[0];
        $this->assertSame('xd_drop_off', $frete['query']['logistic_type']);
        $this->assertSame('15x15x20,500', $frete['query']['dimensions']);
    }

    public function test_sem_dimensoes_calcula_sem_frete_e_nao_chama_shipping_options(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['shipping' => ['dimensions' => null]]);

        $i = $this->analisar([['item_id' => 'MLB1']])['itens'][0];

        $this->assertNull($i['recebe_normal']['frete']);
        $this->assertFalse($i['recebe_normal']['frete_conhecido']);
        $this->assertSame(85.0, $i['recebe_normal']['voce_recebe']);
        $this->assertNotEmpty($i['avisos']);
        $this->assertSame([], $this->chamadasDe('#shipping_options/free#'));
    }

    public function test_dimensoes_da_embalagem_do_vendedor_servem_de_reserva(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['shipping' => ['dimensions' => null]]);
        $this->anuncios['MLB1']['attributes'] = [
            ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_name' => '10 cm', 'value_struct' => ['number' => 10, 'unit' => 'cm']],
            ['id' => 'SELLER_PACKAGE_WIDTH', 'value_name' => '12 cm', 'value_struct' => ['number' => 12, 'unit' => 'cm']],
            ['id' => 'SELLER_PACKAGE_LENGTH', 'value_name' => '30 cm', 'value_struct' => ['number' => 30, 'unit' => 'cm']],
            ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_name' => '800 g', 'value_struct' => ['number' => 800, 'unit' => 'g']],
        ];

        $i = $this->analisar([['item_id' => 'MLB1']])['itens'][0];

        $this->assertSame('10x12x30,800', $this->chamadasDe('#shipping_options/free#')[0]['query']['dimensions']);
        $this->assertTrue($i['recebe_normal']['frete_conhecido']);
    }

    public function test_sem_frete_gratis_o_frete_do_vendedor_e_zero_conhecido(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['shipping' => ['free_shipping' => false]]);

        $i = $this->analisar([['item_id' => 'MLB1']])['itens'][0];

        $this->assertSame(0.0, $i['recebe_normal']['frete']);
        $this->assertTrue($i['recebe_normal']['frete_conhecido']);
        $this->assertSame([], $this->chamadasDe('#shipping_options/free#'));
    }

    public function test_cofinanciada_mostra_ml_banca_em_linha_separada_sem_somar_no_recebe(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['original_price' => 100, 'price' => 90]);

        $i = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'MARKETPLACE_CAMPAIGN', 'preco_promocao' => 90,
            'meli_percentage' => 10, 'seller_percentage' => 5]])['itens'][0];

        $this->assertSame(10.0, $i['ml_banca']);
        $this->assertTrue($i['estimativa']);
        $this->assertSame(SimuladorVoceRecebe::calcular(90.0, 13.5, self::FRETE), $i['recebe_promocao']);
    }

    public function test_boost_vira_desconto_extra_do_ml_fora_da_soma(): void
    {
        $this->montar();
        $this->com('MLB1');

        $i = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'SMART', 'preco_promocao' => 80,
            'boost' => ['ativo' => true, 'desconto_ml' => 5, 'preco_boost' => 78]]])['itens'][0];

        $this->assertSame(5.0, $i['desconto_extra_ml']);
        $this->assertTrue($i['estimativa']);
        $this->assertSame(SimuladorVoceRecebe::calcular(80.0, 12.0, self::FRETE), $i['recebe_promocao']);
    }

    public function test_volume_e_cupom_dependem_do_carrinho_sem_numero_inventado(): void
    {
        $this->montar();
        $this->com('MLB1', 'MLB2');

        $r = $this->analisar([
            ['item_id' => 'MLB1', 'promotion_type' => 'VOLUME', 'preco_promocao' => 0],
            ['item_id' => 'MLB2', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN'],
        ])['itens'];

        foreach ($r as $i) {
            $this->assertTrue($i['depende_do_carrinho']);
            $this->assertNull($i['recebe_promocao']);
            $this->assertNull($i['preco_promocao']);
            $this->assertNull($i['margem']);
        }
    }

    public function test_margem_com_custo_da_precificacao_e_imposto_da_oferta(): void
    {
        $this->montar('company');
        $this->com('MLB1');
        $oferta = EstruturaOferta::create(['company_id' => $this->ancora->id, 'sku' => 'A-1', 'fase' => 'simples', 'nome' => 'A']);
        EstruturaPrecificacao::create(['oferta_id' => $oferta->id, 'custo' => 40, 'imposto' => 6]);
        EstruturaAnuncio::create(['oferta_id' => $oferta->id, 'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB1', 'titulo' => 'x']);

        $i = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'DEAL', 'preco_promocao' => 80]])['itens'][0];

        $recebe = SimuladorVoceRecebe::calcular(80.0, 12.0, self::FRETE)['voce_recebe'];
        $esperado = round($recebe - 40 - 80 * 0.06, 2);
        $this->assertSame($esperado, $i['margem']['valor']);
        $this->assertSame(round($esperado / 80 * 100, 2), $i['margem']['percentual']);
        $this->assertSame(40.0, $i['margem']['custo']);
    }

    public function test_mlb_empresa_sem_company_nunca_tem_margem(): void
    {
        $this->montar('mlb_empresa');
        $this->com('MLB1');

        $i = $this->analisar([['item_id' => 'MLB1', 'preco_promocao' => 80]])['itens'][0];

        $this->assertNull($i['margem']);
        $this->assertTrue($i['calculado']);
    }

    public function test_dois_itens_da_mesma_categoria_tipo_e_preco_dividem_uma_tarifa(): void
    {
        $this->montar();
        $this->com('MLB1', 'MLB2');

        $this->analisar([['item_id' => 'MLB1'], ['item_id' => 'MLB2']]);

        $this->assertCount(1, $this->chamadasDe('#listing_prices#'));
    }

    public function test_limite_por_minuto_devolve_parcial_e_marca_o_resto_sem_calculo(): void
    {
        config(['publicador.alavancas.limites.chamadas_analise_por_minuto' => 2]);
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['price' => 100, 'shipping' => ['free_shipping' => false]]);
        $this->anuncios['MLB2'] = $this->anuncio('MLB2', ['price' => 110, 'shipping' => ['free_shipping' => false]]);
        $this->anuncios['MLB3'] = $this->anuncio('MLB3', ['price' => 120, 'shipping' => ['free_shipping' => false]]);

        $r = $this->analisar([['item_id' => 'MLB1'], ['item_id' => 'MLB2'], ['item_id' => 'MLB3']]);

        $this->assertTrue($r['parcial']);
        $this->assertSame([true, true, false], array_column($r['itens'], 'calculado'));
        $this->assertSame(['MLB1', 'MLB2', 'MLB3'], array_column($r['itens'], 'item_id'));
        $this->assertCount(2, $this->chamadasDe('#listing_prices#'));
    }

    public function test_ordem_e_a_do_pedido_e_item_fora_da_conta_vira_erro(): void
    {
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['price' => 300]);
        $this->anuncios['MLB2'] = $this->anuncio('MLB2', ['price' => 50]);
        $this->anuncios['MLB4'] = $this->anuncio('MLB4', ['seller_id' => 999]);

        $r = $this->analisar([['item_id' => 'MLB2'], ['item_id' => 'MLB9'], ['item_id' => 'MLB4'], ['item_id' => 'MLB1']])['itens'];

        $this->assertSame(['MLB2', 'MLB9', 'MLB4', 'MLB1'], array_column($r, 'item_id'));
        $this->assertSame('Produto não encontrado nesta conta.', $r[1]['erro']);
        $this->assertSame('Produto não encontrado nesta conta.', $r[2]['erro']);
        $this->assertFalse($r[2]['calculado']);
        // Do outro vendedor nada é calculado: só as tarifas de MLB2 e MLB1.
        $this->assertCount(2, $this->chamadasDe('#listing_prices#'));
    }

    public function test_alertas_de_recebido_estoque_e_reputacao_sem_bloquear(): void
    {
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'seller_reputation' => ['level_id' => '3_yellow']];
        $this->montar();
        $this->anuncios['MLB1'] = $this->anuncio('MLB1', ['available_quantity' => 2]);

        $dod = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'DOD', 'preco_promocao' => 80, 'estoque_minimo' => 5]])['itens'][0];
        $desconto = $this->analisar([['item_id' => 'MLB1', 'promotion_type' => 'PRICE_DISCOUNT', 'preco_promocao' => 80]])['itens'][0];

        $this->assertSame(['recebido', 'estoque'], array_column($dod['alertas'], 'codigo'));
        $this->assertSame(['recebido', 'reputacao'], array_column($desconto['alertas'], 'codigo'));
        $this->assertTrue($dod['calculado']);
        $this->assertTrue($desconto['calculado']);
    }

    public function test_tarifa_aceita_a_resposta_em_lista_da_doc(): void
    {
        $this->montar();
        $this->com('MLB1');
        $doc = self::fixtureAlavanca('doc/analise/listing_prices');
        $doc[0]['listing_type_id'] = 'gold_special';
        $doc[0]['sale_fee_amount'] = 17.5;
        $this->responder('GET', '#^/sites/MLB/listing_prices$#', $doc);

        $i = $this->analisar([['item_id' => 'MLB1']])['itens'][0];

        $this->assertSame(17.5, $i['recebe_normal']['tarifa']);
    }
}
