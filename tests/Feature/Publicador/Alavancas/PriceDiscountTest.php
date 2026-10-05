<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\CriarDescontoIndividual;
use App\Services\Publicador\Alavancas\Acoes\RemoverDescontoIndividual;
use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-07 (AL166-10): criar e remover desconto individual, com as regras do ML conferidas antes. */
class PriceDiscountTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @var list<array> o que `GET /seller-promotions/items/{item}` devolve */
    private array $doItem = [];

    /** @var array<string, array> sobrescritas do produto por id (status, condition, listing_type_id) */
    private array $produtos = [];

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'original_price' => null,
                'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special', 'available_quantity' => 10, 'attributes' => [],
                ...($this->produtos[$id] ?? []),
            ]], $ids), 200);
        });
        $this->responder('GET', '#^/seller-promotions/items/MLB\d+$#', fn () => Http::response($this->doItem, 200));
        $this->responder('POST', '#^/seller-promotions/items/MLB\d+$#', self::fixtureAlavanca('doc/acoes/post_item_ok'));
        $this->responder('DELETE', '#^/seller-promotions/items/MLB\d+$#', []);
    }

    private function candidato(): void
    {
        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'candidate', 'price' => 100, 'original_price' => 100,
            'min_discounted_price' => 80, 'max_discounted_price' => 95, 'suggested_discounted_price' => 90]];
    }

    private function dia(int $mais): string
    {
        return DatasDoMl::hoje()->addDays($mais)->format('Y-m-d');
    }

    private function criar(array $dados = [], string $item = 'MLB1'): CriarDescontoIndividual
    {
        return new CriarDescontoIndividual($this->contaAlavanca(), ['item_id' => $item, 'deal_price' => 90, 'start_date' => $this->dia(0), 'finish_date' => $this->dia(2), ...$dados]);
    }

    private function executar(object $acao): PubAlavancaEscrita
    {
        return app(EscritorAlavancas::class)->executar($acao, $this->admin);
    }

    private function recusada(PubAlavancaEscrita $linha, string $regra): void
    {
        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame($regra, $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_criar_manda_post_com_datas_inteiras_e_sem_promotion_id(): void
    {
        $this->cenario();
        $this->candidato();

        $linha = $this->executar($this->criar(['top_deal_price' => 85]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $posts = $this->chamadasNaoGet();
        $this->assertCount(1, $posts);
        $this->assertSame('POST', $posts[0]['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $posts[0]['caminho']);
        $this->assertSame(['app_version' => 'v2'], $posts[0]['query']);
        $this->assertEquals([
            'promotion_type' => 'PRICE_DISCOUNT', 'deal_price' => 90, 'top_deal_price' => 85,
            'start_date' => $this->dia(0).'T00:00:00', 'finish_date' => $this->dia(2).'T23:59:59',
        ], $posts[0]['corpo']);
        $this->assertSame('PRICE_DISCOUNT', $linha->promotion_type);
    }

    public function test_criar_sem_top_deal_nao_manda_o_campo(): void
    {
        $this->cenario();
        $this->candidato();

        $this->executar($this->criar());

        $this->assertArrayNotHasKey('top_deal_price', $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_resumo_do_criar_tem_os_dois_avisos_e_a_linha_do_mercado_pontos(): void
    {
        $this->cenario();
        $this->candidato();

        $r = $this->criar(['top_deal_price' => 85])->resumo();

        $this->assertSame(100.0, $r['preco_atual']);
        $this->assertSame(90.0, $r['preco_promocao']);
        $this->assertSame(10.0, $r['desconto_percentual']);
        $this->assertSame($this->dia(2), $r['prazo']['fim']);
        $this->assertContains('Aumentar o preço do anúncio depois remove este desconto.', $r['avisos']);
        $this->assertContains('Se houver campanha tradicional ativa, o desconto só começa quando ela acabar.', $r['avisos']);
        $this->assertSame('Mercado Pontos 3–6', $r['linhas'][0]['rotulo']);
        $this->assertSame('PRICE_DISCOUNT', $r['analise']['promotion_type']);
    }

    public function test_produto_pausado_usado_ou_gratis_e_recusado_sem_post(): void
    {
        $this->cenario();
        $this->candidato();
        $this->produtos = [
            'MLB2' => ['status' => 'paused'],
            'MLB3' => ['condition' => 'used'],
            'MLB4' => ['listing_type_id' => 'free'],
        ];

        foreach (['MLB2', 'MLB3', 'MLB4'] as $item) {
            $this->recusada($this->executar($this->criar([], $item)), 'ALAV-DESC-07');
        }
    }

    public function test_produto_que_ja_tem_desconto_individual_e_recusado(): void
    {
        $this->cenario();
        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'started', 'price' => 90, 'original_price' => 100]];
        $this->recusada($this->executar($this->criar()), 'ALAV-DESC-08');

        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'pending', 'price' => 90, 'original_price' => 100]];
        $this->recusada($this->executar($this->criar()), 'ALAV-DESC-08');
    }

    public function test_regras_locais_recusam_antes_de_enviar(): void
    {
        $this->cenario();
        $this->candidato();

        $this->recusada($this->executar($this->criar(['deal_price' => 96])), 'ALAV-DESC-01');
        $this->recusada($this->executar($this->criar(['finish_date' => $this->dia(14)])), 'ALAV-DESC-05');
        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(-1)])), 'ALAV-DESC-04');
        $this->recusada($this->executar($this->criar(['deal_price' => 79])), 'ALAV-DESC-06');
    }

    public function test_erro_do_ml_vira_mensagem_em_portugues_com_a_resposta_crua(): void
    {
        $this->cenario();
        $this->candidato();
        $this->responder('POST', '#^/seller-promotions/items/MLB1$#', ['message' => 'Invalid discount', 'error' => 'bad_request', 'status' => 400,
            'cause' => [['error_code' => 'buyer_discount_not_in_range', 'error_message' => 'buyer discount not in range']]], 400);

        $linha = $this->executar($this->criar());

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame('buyer_discount_not_in_range', $linha->erro_codigo);
        $this->assertStringContainsString('entre 5% e menos de 80%', (string) $linha->mensagem);
        $this->assertStringContainsString('buyer_discount_not_in_range', json_encode($linha->resposta));
    }

    public function test_remover_manda_delete_sem_corpo_e_so_com_promotion_type(): void
    {
        $this->cenario();
        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'started', 'price' => 90, 'original_price' => 100]];

        $linha = $this->executar(new RemoverDescontoIndividual($this->contaAlavanca(), ['item_id' => 'MLB1']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $delete['caminho']);
        $this->assertSame(['promotion_type' => 'PRICE_DISCOUNT', 'app_version' => 'v2'], $delete['query']);
        $this->assertEmpty($delete['corpo']);
    }

    public function test_remover_sem_desconto_ativo_ou_programado_e_recusado(): void
    {
        $this->cenario();
        $this->candidato();

        $this->recusada($this->executar(new RemoverDescontoIndividual($this->contaAlavanca(), ['item_id' => 'MLB1'])), 'ALAV-DESC-09');
    }
}
