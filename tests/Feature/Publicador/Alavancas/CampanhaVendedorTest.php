<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\AlterarCampanha;
use App\Services\Publicador\Alavancas\Acoes\CriarCampanha;
use App\Services\Publicador\Alavancas\Acoes\ExcluirCampanha;
use App\Services\Publicador\Alavancas\Acoes\InscreverNoConvite;
use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-08 (AL166-11): campanha do vendedor e leve X pague Y do vendedor — criar, alterar e excluir. */
class CampanhaVendedorTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @var array o que `GET /seller-promotions/promotions/C-MLB1` devolve */
    private array $promocao = [];

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/seller-promotions/promotions$#', self::fixtureAlavanca('doc/acoes/post_promotion_seller_campaign'));
        $this->responder('PUT', '#^/seller-promotions/promotions/C-MLB1$#', ['id' => 'C-MLB1']);
        $this->responder('DELETE', '#^/seller-promotions/promotions/C-MLB1$#', []);
        $this->responder('GET', '#^/seller-promotions/promotions/C-MLB1$#', fn () => Http::response($this->promocao, 200));
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

    private function criar(array $dados = []): CriarCampanha
    {
        return new CriarCampanha($this->contaAlavanca(), ['promotion_type' => 'SELLER_CAMPAIGN', 'name' => 'Semana do vendedor',
            'start_date' => $this->dia(0), 'finish_date' => $this->dia(6), ...$dados]);
    }

    private function volume(array $dados = []): CriarCampanha
    {
        return $this->criar(['promotion_type' => 'VOLUME', 'sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2,
            'allow_combination' => true, ...$dados]);
    }

    private function lida(string $tipo, array $extra = []): array
    {
        return ['id' => 'C-MLB1', 'type' => $tipo, 'status' => 'pending', 'name' => 'Atual',
            'start_date' => $this->dia(2).'T00:00:00', 'finish_date' => $this->dia(5).'T23:59:59', ...$extra];
    }

    public function test_criar_campanha_sempre_flexivel_com_datas_inteiras_e_guarda_o_id(): void
    {
        $this->cenario();

        // Um sub_type vindo do pedido (o antigo percentual fixo) é ignorado.
        $linha = $this->executar($this->criar(['sub_type' => 'FIXED_PERCENTAGE']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $posts = $this->chamadasNaoGet();
        $this->assertCount(1, $posts);
        $this->assertSame('POST', $posts[0]['metodo']);
        $this->assertSame('/seller-promotions/promotions', $posts[0]['caminho']);
        $this->assertSame(['app_version' => 'v2'], $posts[0]['query']);
        $this->assertSame([
            'promotion_type' => 'SELLER_CAMPAIGN', 'name' => 'Semana do vendedor', 'sub_type' => 'FLEXIBLE_PERCENTAGE',
            'start_date' => $this->dia(0).'T00:00:00', 'finish_date' => $this->dia(6).'T23:59:59',
        ], $posts[0]['corpo']);
        $this->assertSame('C-MLB360923', $linha->promotion_id);
        $this->assertSame('promocao', $linha->alavanca);
    }

    public function test_criar_recusa_data_no_passado_e_prazos_acima_do_ml(): void
    {
        $this->cenario();

        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(-1)])), 'ALAV-CAMP-01');
        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(0), 'finish_date' => $this->dia(14)])), 'ALAV-CAMP-02');
        $this->recusada($this->executar($this->criar(['start_date' => $this->dia(3), 'finish_date' => $this->dia(2)])), 'ALAV-CAMP-02');
    }

    public function test_quatorze_dias_inclusivos_passam(): void
    {
        $this->cenario();

        $linha = $this->executar($this->criar(['finish_date' => $this->dia(13)]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
    }

    public function test_resumo_do_criar_avisa_para_incluir_os_produtos(): void
    {
        $this->cenario();

        $r = $this->volume()->resumo();

        $this->assertContains('Depois de criar, inclua os produtos na campanha.', $r['avisos']);
        $this->assertSame('Leve 3, pague 2', $r['linhas'][0]['valor']);
    }

    public function test_criar_volume_bngm_leva_quantidades_e_allow_combination(): void
    {
        $this->cenario();

        $linha = $this->executar($this->volume());

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame([
            'promotion_type' => 'VOLUME', 'sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2, 'allow_combination' => true,
            'name' => 'Semana do vendedor', 'start_date' => $this->dia(0).'T00:00:00', 'finish_date' => $this->dia(6).'T23:59:59',
        ], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_criar_volume_bnsp_e_sponth_levam_desconto_em_vez_de_pay_quantity(): void
    {
        $this->cenario();

        foreach (['BNSP', 'SPONTH'] as $sub) {
            $this->chamadas = [];
            $linha = $this->executar($this->volume(['sub_type' => $sub, 'pay_quantity' => null, 'discount_percentage' => 30, 'allow_combination' => false]));

            $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
            $corpo = $this->chamadasNaoGet()[0]['corpo'];
            $this->assertSame($sub, $corpo['sub_type']);
            $this->assertEquals(30, $corpo['discount_percentage']);
            $this->assertFalse($corpo['allow_combination']);
            $this->assertArrayNotHasKey('pay_quantity', $corpo);
        }
    }

    public function test_criar_volume_recusa_regra_incompleta(): void
    {
        $this->cenario();

        $this->recusada($this->executar($this->volume(['pay_quantity' => null])), 'ALAV-VOL-01');
        $this->recusada($this->executar($this->volume(['pay_quantity' => 3])), 'ALAV-VOL-01');
        $this->recusada($this->executar($this->volume(['sub_type' => 'BNSP', 'pay_quantity' => null])), 'ALAV-VOL-01');
        $this->recusada($this->executar($this->volume(['sub_type' => null])), 'ALAV-VOL-01');
    }

    public function test_volume_nao_tem_o_teto_de_14_dias(): void
    {
        $this->cenario();

        $linha = $this->executar($this->volume(['finish_date' => $this->dia(30)]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
    }

    public function test_alterar_campanha_pendente_so_o_nome_manda_so_o_nome(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('SELLER_CAMPAIGN', ['sub_type' => 'FLEXIBLE_PERCENTAGE']);

        $linha = $this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN',
            'name' => 'Novo nome', 'start_date' => $this->dia(2)]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $put = $this->chamadasNaoGet()[0];
        $this->assertSame('PUT', $put['metodo']);
        $this->assertSame('/seller-promotions/promotions/C-MLB1', $put['caminho']);
        $this->assertSame(['app_version' => 'v2'], $put['query']);
        $this->assertSame(['promotion_type' => 'SELLER_CAMPAIGN', 'name' => 'Novo nome'], $put['corpo']);
    }

    public function test_alterar_campanha_iniciada_nao_muda_o_inicio_mas_muda_o_fim(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('SELLER_CAMPAIGN', ['status' => 'started', 'start_date' => $this->dia(-1).'T00:00:00']);

        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN',
            'start_date' => $this->dia(0)])), 'ALAV-CAMP-04');

        $linha = $this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN',
            'finish_date' => $this->dia(8)]));
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame(['promotion_type' => 'SELLER_CAMPAIGN', 'finish_date' => $this->dia(8).'T23:59:59'], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_alterar_campanha_recusa_periodo_acima_de_14_dias_e_pedido_sem_mudanca(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('SELLER_CAMPAIGN');

        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN',
            'finish_date' => $this->dia(16)])), 'ALAV-CAMP-02');
        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN',
            'name' => 'Atual'])), 'ALAV-CAMP-05');
    }

    public function test_alterar_volume_ativo_so_aceita_o_nome(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('VOLUME', ['status' => 'started', 'sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2, 'allow_combination' => false]);

        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME',
            'buy_quantity' => 4])), 'ALAV-VOL-02');

        $linha = $this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME', 'name' => 'Outro']));
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame(['promotion_type' => 'VOLUME', 'name' => 'Outro'], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_alterar_volume_programado_reenvia_todos_os_atributos_do_subtipo(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('VOLUME', ['sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2, 'allow_combination' => true]);

        $linha = $this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME', 'buy_quantity' => 4]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame(['promotion_type' => 'VOLUME', 'sub_type' => 'BNGM', 'buy_quantity' => 4, 'pay_quantity' => 2,
            'allow_combination' => true, 'name' => 'Atual'], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_alterar_volume_trocando_o_subtipo_exige_o_atributo_novo(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('VOLUME', ['sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2, 'allow_combination' => true]);

        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME',
            'sub_type' => 'BNSP'])), 'ALAV-VOL-01');

        $linha = $this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME',
            'sub_type' => 'BNSP', 'discount_percentage' => 25]));
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $corpo = $this->chamadasNaoGet()[0]['corpo'];
        $this->assertSame('BNSP', $corpo['sub_type']);
        $this->assertEquals(25, $corpo['discount_percentage']);
        $this->assertArrayNotHasKey('pay_quantity', $corpo);
    }

    public function test_qualquer_mudanca_de_data_em_volume_e_recusada(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('VOLUME', ['sub_type' => 'BNGM', 'buy_quantity' => 3, 'pay_quantity' => 2, 'allow_combination' => true]);

        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME',
            'finish_date' => $this->dia(9)])), 'ALAV-VOL-03');
        $this->recusada($this->executar(new AlterarCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME',
            'start_date' => $this->dia(3)])), 'ALAV-VOL-03');
    }

    public function test_excluir_manda_delete_sem_corpo_com_promotion_type(): void
    {
        $this->cenario();
        $this->promocao = $this->lida('SELLER_CAMPAIGN');

        $acao = new ExcluirCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'SELLER_CAMPAIGN']);
        $this->assertContains('Os produtos saem da campanha.', $acao->resumo()['avisos']);
        $linha = $this->executar($acao);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame('/seller-promotions/promotions/C-MLB1', $delete['caminho']);
        $this->assertSame(['promotion_type' => 'SELLER_CAMPAIGN', 'app_version' => 'v2'], $delete['query']);
        $this->assertEmpty($delete['corpo']);
    }

    public function test_excluir_campanha_que_o_ml_nao_acha_e_recusada(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/C-MLB1$#', ['message' => 'not found'], 404);

        $this->recusada($this->executar(new ExcluirCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1', 'promotion_type' => 'VOLUME'])), 'ALAV-CAMP-06');
    }

    public function test_id_de_promocao_com_caracter_estranho_nao_vira_caminho(): void
    {
        $this->cenario();

        $linha = $this->executar(new ExcluirCampanha($this->contaAlavanca(), ['promotion_id' => 'C-MLB1/../x', 'promotion_type' => 'SELLER_CAMPAIGN']));

        $this->assertNotSame(PubAlavancaEscrita::OK, $linha->resultado);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_item_entra_na_campanha_do_vendedor_com_preco_e_e_alterado_so_para_baixo(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'original_price' => null,
                'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special', 'available_quantity' => 10, 'attributes' => [],
            ]], $ids), 200);
        });
        $this->responder('GET', '#^/seller-promotions/promotions/C-MLB1/items$#', ['results' => [
            ['id' => 'MLB1', 'status' => 'candidate', 'price' => 100, 'original_price' => 100]], 'paging' => ['total' => 1, 'limit' => 50]]);
        $this->responder('POST', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/acoes/post_item_ok'));

        $linha = $this->executar(new InscreverNoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_id' => 'C-MLB1',
            'promotion_type' => 'SELLER_CAMPAIGN', 'deal_price' => 90]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $corpo = $this->chamadasNaoGet()[0]['corpo'];
        $this->assertSame('C-MLB1', $corpo['promotion_id']);
        $this->assertSame('SELLER_CAMPAIGN', $corpo['promotion_type']);
        $this->assertEquals(90, $corpo['deal_price']);
    }
}
