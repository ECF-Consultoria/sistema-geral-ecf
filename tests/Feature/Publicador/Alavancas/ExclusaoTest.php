<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\GravarExclusaoDaConta;
use App\Services\Publicador\Alavancas\Acoes\GravarExclusaoDoItem;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-08 (AL166-12): lista de exclusão das campanhas automáticas, por conta e por produto. */
class ExclusaoTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** O que a leitura da conta devolve agora (formato da doc: "excluded" | "not_excluded"). */
    private string $conta = 'not_excluded';

    private string $item = 'not_excluded';

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller$#', fn () => Http::response(['excluded' => $this->conta], 200));
        $this->responder('GET', '#^/seller-promotions/exclusion-list/seller/MLB\d+$#', fn () => Http::response(['excluded' => $this->item], 200));
        $this->responder('POST', '#^/seller-promotions/exclusion-list/(seller|item)$#', []);
        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => $id === 'MLB9' ? 999 : 1555596317, 'title' => "Produto {$id}", 'price' => 100, 'original_price' => null,
                'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special', 'available_quantity' => 10, 'attributes' => [],
            ]], $ids), 200);
        });
    }

    private function executar(object $acao): PubAlavancaEscrita
    {
        return app(EscritorAlavancas::class)->executar($acao, $this->admin);
    }

    private function daConta(bool $excluir): GravarExclusaoDaConta
    {
        return new GravarExclusaoDaConta($this->contaAlavanca(), ['excluir' => $excluir]);
    }

    private function doItem(string $item, bool $excluir): GravarExclusaoDoItem
    {
        return new GravarExclusaoDoItem($this->contaAlavanca(), ['item_id' => $item, 'excluir' => $excluir]);
    }

    public function test_excluir_a_conta_manda_exclusion_status_em_string(): void
    {
        $this->cenario();

        $linha = $this->executar($this->daConta(true));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $post = $this->chamadasNaoGet()[0];
        $this->assertSame('POST', $post['metodo']);
        $this->assertSame('/seller-promotions/exclusion-list/seller', $post['caminho']);
        $this->assertSame(['app_version' => 'v2'], $post['query']);
        $this->assertSame(['exclusion_status' => 'true'], $post['corpo']);
        $this->assertSame('exclusao', $linha->alavanca);
        $this->assertSame('exclusao.conta', $linha->acao);
    }

    public function test_voltar_a_conta_manda_false(): void
    {
        $this->cenario();
        $this->conta = 'excluded';

        $linha = $this->executar($this->daConta(false));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame(['exclusion_status' => 'false'], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_pedir_o_estado_que_ja_esta_e_recusado_sem_post(): void
    {
        $this->cenario();
        $this->conta = 'excluded';

        $linha = $this->executar($this->daConta(true));

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-EXC-01', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_excluir_um_produto_manda_item_e_status(): void
    {
        $this->cenario();

        $linha = $this->executar($this->doItem('MLB1', true));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $post = $this->chamadasNaoGet()[0];
        $this->assertSame('/seller-promotions/exclusion-list/item', $post['caminho']);
        $this->assertSame(['app_version' => 'v2'], $post['query']);
        $this->assertSame(['item_id' => 'MLB1', 'exclusion_status' => 'true'], $post['corpo']);
        $this->assertSame('exclusao.item', $linha->acao);
        $this->assertSame('MLB1', $linha->item_id);
    }

    public function test_produto_ja_excluido_e_recusado_sem_post(): void
    {
        $this->cenario();
        $this->item = 'excluded';

        $linha = $this->executar($this->doItem('MLB1', true));

        $this->assertSame('ALAV-EXC-01', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_produto_de_outro_vendedor_e_recusado_mesmo_com_code_200(): void
    {
        $this->cenario();

        $linha = $this->executar($this->doItem('MLB9', true));

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-EXC-02', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_produto_que_o_ml_nao_devolve_e_recusado(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/items$#', fn () => Http::response([['code' => 404, 'body' => ['message' => 'not found']]], 200));

        $linha = $this->executar($this->doItem('MLB1', true));

        $this->assertSame('ALAV-EXC-02', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_resumo_explica_o_efeito_da_conta_e_do_produto(): void
    {
        $this->cenario();

        $this->assertContains('As campanhas automáticas do Mercado Livre deixam de incluir produtos desta conta.', $this->daConta(true)->resumo()['avisos']);
        $this->conta = 'excluded';
        $this->assertContains('As campanhas automáticas do Mercado Livre voltam a poder incluir produtos desta conta.', $this->daConta(false)->resumo()['avisos']);
        $this->assertContains('As campanhas automáticas do Mercado Livre deixam de incluir este produto.', $this->doItem('MLB1', true)->resumo()['avisos']);
    }

    public function test_leitura_da_conta_le_o_formato_da_doc(): void
    {
        $this->cenario();
        $leitura = app(\App\Services\Publicador\Alavancas\PromocoesLeitura::class);

        $this->conta = 'excluded';
        $this->assertTrue($leitura->exclusaoDaConta($this->contaAlavanca())['excluida']);
        $this->conta = 'not_excluded';
        $this->assertFalse($leitura->exclusaoDaConta($this->contaAlavanca())['excluida']);
    }
}
