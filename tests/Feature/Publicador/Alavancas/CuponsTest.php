<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\AlterarCupom;
use App\Services\Publicador\Alavancas\Acoes\CriarCupom;
use App\Services\Publicador\Alavancas\Acoes\ExcluirCupom;
use App\Services\Publicador\Alavancas\Acoes\InscreverNoConvite;
use App\Services\Publicador\Alavancas\Acoes\RemoverDoConvite;
use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-08 (AL166-13): cupom do vendedor — criar, alterar (orçamento só aumenta), excluir e produtos no cupom. */
class CuponsTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @var array o que `GET /seller-promotions/promotions/C-MLB1234` devolve */
    private array $cupom = [];

    /** @var list<array> itens do cupom na leitura */
    private array $itensDoCupom = [];

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/seller-promotions/promotions$#', self::fixtureAlavanca('doc/acoes/post_promotion_coupon'));
        $this->responder('PUT', '#^/seller-promotions/promotions/C-MLB1234$#', ['id' => 'C-MLB1234']);
        $this->responder('DELETE', '#^/seller-promotions/promotions/C-MLB1234$#', []);
        $this->responder('GET', '#^/seller-promotions/promotions/C-MLB1234$#', fn () => Http::response($this->cupom, 200));
        $this->responder('GET', '#^/seller-promotions/promotions/C-MLB1234/items$#', fn () => Http::response(
            ['results' => $this->itensDoCupom, 'paging' => ['total' => count($this->itensDoCupom), 'limit' => 50]], 200));
        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'original_price' => null,
                'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special', 'available_quantity' => 10, 'attributes' => [],
            ]], $ids), 200);
        });
        $this->responder('POST', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/acoes/post_item_ok'));
        $this->responder('DELETE', '#^/seller-promotions/items/MLB1$#', []);
    }

    private function dia(int $mais): string
    {
        return DatasDoMl::hoje()->addDays($mais)->format('Y-m-d');
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

    private function criar(array $dados = []): CriarCupom
    {
        return new CriarCupom($this->contaAlavanca(), ['name' => 'Cupom de teste', 'sub_type' => 'FIXED_PERCENTAGE', 'fixed_percentage' => 10,
            'min_purchase_amount' => 100, 'max_purchase_amount' => 50, 'budget' => 1000, 'start_date' => $this->dia(0), 'finish_date' => $this->dia(9),
            'partial_coupon_code' => 'MEUCOD', ...$dados]);
    }

    private function alterar(array $dados): AlterarCupom
    {
        return new AlterarCupom($this->contaAlavanca(), ['promotion_id' => 'C-MLB1234', ...$dados]);
    }

    private function lido(array $extra = []): void
    {
        $this->cupom = ['id' => 'C-MLB1234', 'type' => 'SELLER_COUPON_CAMPAIGN', 'sub_type' => 'FIXED_PERCENTAGE', 'status' => 'pending', 'name' => 'Atual',
            'fixed_percentage' => 10, 'min_purchase_amount' => 100, 'max_purchase_amount' => 50, 'budget' => 1000, 'remaining_budget' => 1000,
            'start_date' => $this->dia(2).'T00:00:00Z', 'finish_date' => $this->dia(9).'T02:59:59Z', ...$extra];
    }

    public function test_criar_percentual_com_codigo_manda_o_corpo_da_doc(): void
    {
        $this->cenario();

        $linha = $this->executar($this->criar());

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $post = $this->chamadasNaoGet()[0];
        $this->assertSame('POST', $post['metodo']);
        $this->assertSame('/seller-promotions/promotions', $post['caminho']);
        $this->assertSame(['app_version' => 'v2'], $post['query']);
        $this->assertEquals([
            'promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'name' => 'Cupom de teste', 'sub_type' => 'FIXED_PERCENTAGE', 'fixed_percentage' => 10,
            'min_purchase_amount' => 100, 'max_purchase_amount' => 50, 'budget' => 1000,
            'start_date' => $this->dia(0).'T00:00:00', 'finish_date' => $this->dia(9).'T23:59:59', 'partial_coupon_code' => 'MEUCOD',
        ], $post['corpo']);
        $this->assertSame('C-MLB1234', $linha->promotion_id);
        $this->assertSame('cupom', $linha->alavanca);
    }

    public function test_criar_sem_codigo_nao_manda_partial_coupon_code(): void
    {
        $this->cenario();

        $this->executar($this->criar(['partial_coupon_code' => null]));

        $this->assertArrayNotHasKey('partial_coupon_code', $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_criar_valor_fixo_manda_fixed_amount(): void
    {
        $this->cenario();

        $linha = $this->executar($this->criar(['sub_type' => 'FIXED_AMOUNT', 'fixed_amount' => 20, 'fixed_percentage' => null, 'max_purchase_amount' => null]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $corpo = $this->chamadasNaoGet()[0]['corpo'];
        $this->assertEquals(20, $corpo['fixed_amount']);
        $this->assertArrayNotHasKey('fixed_percentage', $corpo);
        $this->assertArrayNotHasKey('max_purchase_amount', $corpo);
    }

    public function test_regras_exigem_o_teto_do_percentual_e_o_valor_do_subtipo(): void
    {
        $this->assertArrayHasKey('max_purchase_amount', CriarCupom::regras());

        $semTeto = validator(['name' => 'x', 'sub_type' => 'FIXED_PERCENTAGE', 'fixed_percentage' => 10, 'min_purchase_amount' => 100, 'budget' => 10,
            'start_date' => '2030-01-01', 'finish_date' => '2030-01-02'], CriarCupom::regras());
        $this->assertTrue($semTeto->fails());
        $this->assertTrue($semTeto->errors()->has('max_purchase_amount'));

        $valorFixo = validator(['name' => 'x', 'sub_type' => 'FIXED_AMOUNT', 'fixed_amount' => 5, 'min_purchase_amount' => 100, 'budget' => 10,
            'start_date' => '2030-01-01', 'finish_date' => '2030-01-02'], CriarCupom::regras());
        $this->assertFalse($valorFixo->fails(), json_encode($valorFixo->errors()));
    }

    public function test_criar_recusa_periodo_fora_de_1_a_31_dias_e_inicio_no_passado(): void
    {
        $this->cenario();

        $this->recusada($this->executar($this->criar(['finish_date' => $this->dia(31)])), 'ALAV-CUP-02');
        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(-1)])), 'ALAV-CUP-02');
        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(4), 'finish_date' => $this->dia(3)])), 'ALAV-CUP-02');
    }

    public function test_trinta_e_um_dias_inclusivos_passam(): void
    {
        $this->cenario();

        $linha = $this->executar($this->criar(['finish_date' => $this->dia(30)]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
    }

    public function test_criar_fora_do_mlb_e_recusado(): void
    {
        $this->cenario();
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'site_id' => 'MLA'];

        $this->recusada($this->executar($this->criar()), 'ALAV-CUP-01');
    }

    public function test_resumo_avisa_que_cupom_sem_produto_nao_vale_e_explica_o_codigo(): void
    {
        $this->cenario();

        $r = $this->criar()->resumo();

        $this->assertContains('Cupom sem produtos não vale para nenhuma venda: inclua os produtos depois de criar.', $r['avisos']);
        $codigo = collect($r['linhas'])->firstWhere('rotulo', 'Código')['valor'];
        $this->assertStringContainsString('MGSTOMEUCOD', $codigo);
        $this->assertStringContainsString('5 primeiras letras do apelido', $codigo);
    }

    public function test_alterar_orcamento_menor_e_recusado_com_o_valor_atual(): void
    {
        $this->cenario();
        $this->lido();

        $linha = $this->executar($this->alterar(['budget' => 900]));

        $this->recusada($linha, 'ALAV-CUP-03');
        $this->assertStringContainsString('R$ 1.000,00', (string) $linha->mensagem);
    }

    public function test_alterar_orcamento_maior_manda_so_o_orcamento(): void
    {
        $this->cenario();
        $this->lido();

        $linha = $this->executar($this->alterar(['budget' => 1500]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $put = $this->chamadasNaoGet()[0];
        $this->assertSame('PUT', $put['metodo']);
        $this->assertSame('/seller-promotions/promotions/C-MLB1234', $put['caminho']);
        $this->assertSame(['app_version' => 'v2'], $put['query']);
        $this->assertEquals(['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'budget' => 1500], $put['corpo']);
    }

    public function test_cupom_ativo_recusa_percentual_e_aceita_fim_orcamento_e_nome(): void
    {
        $this->cenario();
        $this->lido(['status' => 'started', 'start_date' => $this->dia(-1).'T03:00:00Z']);

        $this->recusada($this->executar($this->alterar(['fixed_percentage' => 12])), 'ALAV-CUP-04');

        $linha = $this->executar($this->alterar(['finish_date' => $this->dia(12), 'budget' => 2000, 'name' => 'Novo']));
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertEquals(['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'name' => 'Novo', 'budget' => 2000,
            'finish_date' => $this->dia(12).'T23:59:59'], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_cupom_programado_aceita_percentual_e_recusa_periodo_longo_ou_pedido_vazio(): void
    {
        $this->cenario();
        $this->lido();

        $this->recusada($this->executar($this->alterar(['finish_date' => $this->dia(40)])), 'ALAV-CUP-02');
        $this->recusada($this->executar($this->alterar(['name' => 'Atual', 'budget' => 1000])), 'ALAV-CUP-05');

        $linha = $this->executar($this->alterar(['fixed_percentage' => 12, 'max_purchase_amount' => 60]));
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertEquals(['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'fixed_percentage' => 12, 'max_purchase_amount' => 60], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_valor_fixo_nao_se_aplica_a_cupom_percentual(): void
    {
        $this->cenario();
        $this->lido();

        $this->recusada($this->executar($this->alterar(['fixed_amount' => 5])), 'ALAV-CUP-07');
    }

    public function test_excluir_manda_delete_sem_corpo(): void
    {
        $this->cenario();
        $this->lido();

        $linha = $this->executar(new ExcluirCupom($this->contaAlavanca(), ['promotion_id' => 'C-MLB1234']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame('/seller-promotions/promotions/C-MLB1234', $delete['caminho']);
        $this->assertSame(['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'app_version' => 'v2'], $delete['query']);
        $this->assertEmpty($delete['corpo']);
        $this->assertSame('cupom', $linha->alavanca);
    }

    public function test_produto_entra_no_cupom_sem_preco_e_a_linha_fica_como_cupom(): void
    {
        $this->cenario();
        $this->itensDoCupom = [['id' => 'MLB1', 'status' => 'candidate', 'price' => 0, 'original_price' => 100, 'fixed_percentage' => 10, 'sub_type' => 'FIXED_PERCENTAGE']];

        $linha = $this->executar(new InscreverNoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_id' => 'C-MLB1234', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $post = $this->chamadasNaoGet()[0];
        $this->assertSame('/seller-promotions/items/MLB1', $post['caminho']);
        $this->assertEquals(['promotion_id' => 'C-MLB1234', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN'], $post['corpo']);
        $this->assertSame('cupom', $linha->alavanca);
    }

    public function test_produto_sai_do_cupom_e_a_linha_fica_como_cupom(): void
    {
        $this->cenario();
        $this->itensDoCupom = [['id' => 'MLB1', 'status' => 'started', 'price' => 0, 'original_price' => 100, 'fixed_percentage' => 10, 'sub_type' => 'FIXED_PERCENTAGE']];

        $linha = $this->executar(new RemoverDoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_id' => 'C-MLB1234', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $delete['caminho']);
        $this->assertSame('SELLER_COUPON_CAMPAIGN', $delete['query']['promotion_type']);
        $this->assertSame('C-MLB1234', $delete['query']['promotion_id']);
        $this->assertSame('cupom', $linha->alavanca);
    }
}
