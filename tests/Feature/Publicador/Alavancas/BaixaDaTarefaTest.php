<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\Company;
use App\Models\PubAlavancaEscrita;
use App\Models\PubTarefa;
use App\Services\Publicador\Alavancas\Acoes\CriarDescontoIndividual;
use App\Services\Publicador\Alavancas\Acoes\RemoverDescontoIndividual;
use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Services\Publicador\Tarefas\TarefasPosPublicacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * Baixa automática (09/10/2026): escrita OK das Alavancas num MLB de tarefa pós-publicação aberta, da
 * MESMA conta, marca o item do checklist com o `escrita_id` e quem escreveu. Tirar/remover não conta,
 * escrita recusada não conta, tarefa concluída não muda, e falhar na baixa nunca mexe na escrita.
 */
class BaixaDaTarefaTest extends TestCase
{
    use CenarioAlavancas;
    use CenarioTarefas;
    use RefreshDatabase;

    /** @var list<array> o que `GET /seller-promotions/items/{item}` devolve */
    private array $doItem = [];

    /** @var array<string, array> sobrescritas do produto por id */
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
        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'candidate', 'price' => 100, 'original_price' => 100,
            'min_discounted_price' => 80, 'max_discounted_price' => 95, 'suggested_discounted_price' => 90]];
    }

    private function desconto(string $item = 'MLB1'): PubAlavancaEscrita
    {
        $hoje = DatasDoMl::hoje();

        return app(EscritorAlavancas::class)->executar(new CriarDescontoIndividual($this->contaAlavanca(), [
            'item_id' => $item, 'deal_price' => 90, 'start_date' => $hoje->format('Y-m-d'), 'finish_date' => $hoje->addDays(2)->format('Y-m-d'),
        ]), $this->admin);
    }

    public function test_desconto_criado_num_mlb_da_tarefa_marca_central_de_promocoes_e_comeca_a_tarefa(): void
    {
        $this->cenario();
        $t = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special', 'MLB2' => 'gold_pro']);

        $linha = $this->desconto('MLB1');

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $t->refresh();
        $item = $t->checklist['central_promocao'];
        $this->assertSame(PubTarefa::ITEM_FEITO, $item['estado']);
        $this->assertSame($linha->id, $item['escrita_id']);
        $this->assertSame(['id' => $this->admin->id, 'nome' => $this->admin->name], $item['por']);
        $this->assertSame(PubTarefa::EM_ANDAMENTO, $t->status);
        $this->assertNotNull($t->iniciada_em);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $t->checklist['cupom']['estado'], 'só o item da alavanca usada');
        $this->assertNull($t->responsavel_id, 'a baixa não muda o responsável');
    }

    public function test_remover_o_desconto_nao_da_baixa(): void
    {
        $this->cenario();
        $t = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);
        $this->doItem = [['type' => 'PRICE_DISCOUNT', 'status' => 'started', 'price' => 90, 'original_price' => 100]];

        $linha = app(EscritorAlavancas::class)->executar(new RemoverDescontoIndividual($this->contaAlavanca(), ['item_id' => 'MLB1']), $this->admin);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $t->fresh()->checklist['central_promocao']['estado']);
        $this->assertSame(PubTarefa::PENDENTE, $t->fresh()->status);
    }

    public function test_escrita_recusada_nao_da_baixa(): void
    {
        $this->cenario();
        $t = $this->tarefaDa($this->ancora, ['MLB2' => 'gold_special']);
        $this->produtos = ['MLB2' => ['status' => 'paused']];

        $linha = $this->desconto('MLB2');

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $t->fresh()->checklist['central_promocao']['estado']);
    }

    public function test_so_a_tarefa_da_mesma_conta_e_aberta_ganha_a_baixa(): void
    {
        $this->cenario();
        $outraConta = Company::factory()->create();
        $daOutra = $this->tarefaDa($outraConta, ['MLB1' => 'gold_special']);
        $concluida = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);
        $concluida->update(['status' => PubTarefa::FEITA, 'concluida_em' => now()]);
        $aberta = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);

        $this->desconto('MLB1');

        $this->assertSame(PubTarefa::ITEM_FEITO, $aberta->fresh()->checklist['central_promocao']['estado']);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $daOutra->fresh()->checklist['central_promocao']['estado'], 'outra conta, mesmo MLB: não mexe');
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $concluida->fresh()->checklist['central_promocao']['estado'], 'tarefa concluída não muda');
    }

    public function test_item_ja_marcado_a_mao_fica_como_esta(): void
    {
        $this->cenario();
        $t = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);
        $caio = $this->comPermissao(nome: 'Caio');
        app(TarefasPosPublicacao::class)->marcar($t, 'central_promocao', PubTarefa::ITEM_FEITO, null, $caio);

        $this->desconto('MLB1');

        $item = $t->fresh()->checklist['central_promocao'];
        $this->assertSame(['id' => $caio->id, 'nome' => 'Caio'], $item['por']);
        $this->assertNull($item['escrita_id']);
    }

    public function test_falha_na_baixa_nao_muda_o_resultado_da_escrita(): void
    {
        $this->cenario();
        $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);
        $this->mock(TarefasPosPublicacao::class, fn ($m) => $m->shouldReceive('baixaPorEscrita')->andThrow(new \RuntimeException('tabela fora')));

        $linha = $this->desconto('MLB1');

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
        $this->assertCount(1, $this->chamadasNaoGet(), 'a escrita saiu uma vez só');
    }

    // ═══ O mapa ação → item do checklist (sem ML) ════════════════════════════

    private function linha(array $campos): PubAlavancaEscrita
    {
        return PubAlavancaEscrita::create([
            'company_id' => $this->ancora->id, 'conta_chave' => $this->ancora->chaveContaMl(), 'ml_seller_id' => '1555596317',
            'user_id' => $this->admin->id, 'ator_nome' => $this->admin->name, 'item_id' => 'MLB1',
            'resultado' => PubAlavancaEscrita::OK, 'payload' => ['dados' => []], ...$campos,
        ]);
    }

    public function test_o_mapa_da_baixa_por_acao(): void
    {
        $this->montarAlavancas();
        $chave = fn (array $c) => TarefasPosPublicacao::chaveDaEscrita(new PubAlavancaEscrita($c));

        $this->assertSame('central_promocao', $chave(['acao' => 'convite.inscrever', 'alavanca' => 'promocao']));
        $this->assertSame('central_promocao', $chave(['acao' => 'convite.alterar', 'alavanca' => 'promocao']));
        $this->assertSame('central_promocao', $chave(['acao' => 'desconto.criar', 'alavanca' => 'promocao']));
        $this->assertSame('cupom', $chave(['acao' => 'convite.inscrever', 'alavanca' => 'cupom']), 'pôr o produto no cupom do vendedor');
        $this->assertSame('atacado', $chave(['acao' => 'atacado.gravar', 'alavanca' => 'atacado', 'payload' => ['dados' => ['faixas' => [['percentual' => 5, 'quantidade_minima' => 3]]]]]));
        $this->assertNull($chave(['acao' => 'atacado.gravar', 'alavanca' => 'atacado', 'payload' => ['dados' => ['faixas' => []]]]), 'gravar vazio é apagar as faixas');
        foreach (['convite.remover', 'convite.remover_todas', 'desconto.remover', 'cupom.excluir', 'campanha.excluir', 'exclusao.item', 'exclusao.conta'] as $acao) {
            $this->assertNull($chave(['acao' => $acao, 'alavanca' => 'promocao']), $acao);
        }
    }

    public function test_atacado_e_cupom_dao_baixa_no_item_deles(): void
    {
        $this->montarAlavancas();
        $t = $this->tarefaDa($this->ancora, ['MLB1' => 'gold_special']);
        $servico = app(TarefasPosPublicacao::class);

        $servico->baixaPorEscrita($this->linha(['alavanca' => 'atacado', 'acao' => 'atacado.gravar',
            'payload' => ['dados' => ['item_id' => 'MLB1', 'faixas' => [['percentual' => 5, 'quantidade_minima' => 3]]]]]));
        $servico->baixaPorEscrita($this->linha(['alavanca' => 'cupom', 'acao' => 'convite.inscrever', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN']));
        $servico->baixaPorEscrita($this->linha(['alavanca' => 'promocao', 'acao' => 'desconto.criar', 'resultado' => PubAlavancaEscrita::INCERTO]));

        $c = $t->fresh()->checklist;
        $this->assertSame(PubTarefa::ITEM_FEITO, $c['atacado']['estado']);
        $this->assertSame(PubTarefa::ITEM_FEITO, $c['cupom']['estado']);
        $this->assertSame(PubTarefa::ITEM_PENDENTE, $c['central_promocao']['estado'], 'INCERTO não é OK');
    }
}
