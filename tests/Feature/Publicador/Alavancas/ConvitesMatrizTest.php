<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\AlterarNoConvite;
use App\Services\Publicador\Alavancas\Acoes\InscreverNoConvite;
use App\Services\Publicador\Alavancas\Acoes\RemoverDeTodas;
use App\Services\Publicador\Alavancas\Acoes\RemoverDoConvite;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Services\Publicador\Alavancas\LeiturasDaAcao;
use App\Services\Publicador\Alavancas\TiposDePromocao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/**
 * 166-07 (AL166-09): inscrever, alterar, tirar e tirar de todas pela matriz do RESEARCH.
 * Conta liberada, ML simulado; confere a ÚNICA requisição não-GET (método, caminho, query e corpo).
 */
class ConvitesMatrizTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @var array<string, list<array>> itens de cada promoção ("TIPO|id") no formato do ML */
    private array $promos = [];

    /** @var array<string, list<array>> o que `GET /seller-promotions/items/{item}` devolve por item */
    private array $doItem = [];

    private function cenario(): void
    {
        $this->montarAlavancas();
        // Multiget: tudo é desta conta, menos os ids MLB999… (de outro vendedor, com code 200).
        $this->responder('GET', '#^/items$#', function (Request $r) {
            $ids = explode(',', (string) ($r->data()['ids'] ?? ''));

            return Http::response(array_map(fn ($id) => ['code' => 200, 'body' => [
                'id' => $id, 'seller_id' => str_starts_with($id, 'MLB999') ? 999000111 : 1555596317, 'title' => "Produto {$id}",
                'price' => 100, 'original_price' => null, 'status' => 'active', 'condition' => 'new', 'listing_type_id' => 'gold_special',
                'available_quantity' => 10, 'attributes' => [],
            ]], $ids), 200);
        });
        // Itens de uma promoção (com o filtro item_id da leitura por item).
        $this->responder('GET', '#^/seller-promotions/promotions/[^/]+/items$#', function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $caminho = (string) parse_url($r->url(), PHP_URL_PATH);
            $id = explode('/', $caminho)[3];
            $linhas = $this->promos[($q['promotion_type'] ?? '').'|'.$id] ?? [];
            if (isset($q['item_id'])) {
                $linhas = array_values(array_filter($linhas, fn ($l) => $l['id'] === $q['item_id']));
            }

            return Http::response(['results' => $linhas, 'paging' => ['offset' => 0, 'limit' => 50, 'total' => count($linhas)]], 200);
        });
        // Promoções de um item (também onde vivem DOD/LIGHTNING/PRICE_DISCOUNT, sem promotion_id).
        $this->responder('GET', '#^/seller-promotions/items/MLB\d+$#', function (Request $r) {
            $item = basename((string) parse_url($r->url(), PHP_URL_PATH));

            return Http::response($this->doItem[$item] ?? [], 200);
        });
        foreach (['POST', 'PUT'] as $metodo) {
            $this->responder($metodo, '#^/seller-promotions/items/MLB\d+$#', self::fixtureAlavanca('doc/acoes/post_item_ok'));
        }
        $this->responder('DELETE', '#^/seller-promotions/items/MLB\d+$#', ['successful_ids' => [['offer_id' => 'OFFER-1', 'error' => null]], 'errors' => []]);
    }

    /** Põe a entrada no lugar onde a leitura vai buscá-la (por promoção ou pelo item). */
    private function entrada(string $tipo, ?string $promocao, array $linha, string $item = 'MLB1'): void
    {
        if (in_array($tipo, TiposDePromocao::SEM_PROMOTION_ID, true)) {
            $this->doItem[$item][] = ['type' => $tipo, ...$linha];
        } else {
            $this->promos["{$tipo}|{$promocao}"][] = ['id' => $item, ...$linha];
            // O item também aparece na lista do próprio item (usada por RemoverDeTodas).
            $this->doItem[$item][] = ['id' => $promocao, 'type' => $tipo, ...$linha];
        }
    }

    private function executar(object $acao): PubAlavancaEscrita
    {
        return app(EscritorAlavancas::class)->executar($acao, $this->admin);
    }

    private function inscrever(string $tipo, ?string $promocao, array $dados = []): InscreverNoConvite
    {
        return new InscreverNoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_type' => $tipo, 'promotion_id' => $promocao, ...$dados]);
    }

    private function alterar(string $tipo, string $promocao, array $dados = []): AlterarNoConvite
    {
        return new AlterarNoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_type' => $tipo, 'promotion_id' => $promocao, ...$dados]);
    }

    private function remover(string $tipo, ?string $promocao, array $dados = []): RemoverDoConvite
    {
        return new RemoverDoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_type' => $tipo, 'promotion_id' => $promocao, ...$dados]);
    }

    private function recusada(PubAlavancaEscrita $linha, string $regra): void
    {
        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame($regra, $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet(), 'nada pode ser escrito numa recusa');
    }

    // ═══ Inscrever: um caso por tipo ═══

    /** @return array<string, array{0: string, 1: ?string, 2: array, 3: array, 4: array}> */
    public static function inscricoes(): array
    {
        $cand = ['status' => 'candidate', 'price' => 100, 'original_price' => 100];
        $faixa = ['min_discounted_price' => 70, 'max_discounted_price' => 95];

        return [
            'DEAL com preço e top' => ['DEAL', 'P-1', [...$cand, ...$faixa], ['deal_price' => 85, 'top_deal_price' => 80],
                ['promotion_id' => 'P-1', 'promotion_type' => 'DEAL', 'deal_price' => 85, 'top_deal_price' => 80]],
            'DEAL só com preço' => ['DEAL', 'P-1', [...$cand, ...$faixa], ['deal_price' => 85],
                ['promotion_id' => 'P-1', 'promotion_type' => 'DEAL', 'deal_price' => 85]],
            'cofinanciada sem preço' => ['MARKETPLACE_CAMPAIGN', 'C-1', $cand, [],
                ['promotion_id' => 'C-1', 'promotion_type' => 'MARKETPLACE_CAMPAIGN']],
            'VOLUME sem preço' => ['VOLUME', 'V-1', $cand, [], ['promotion_id' => 'V-1', 'promotion_type' => 'VOLUME']],
            'cupom sem preço' => ['SELLER_COUPON_CAMPAIGN', 'K-1', $cand, [], ['promotion_id' => 'K-1', 'promotion_type' => 'SELLER_COUPON_CAMPAIGN']],
            'DOD sem promotion_id' => ['DOD', null, $cand, ['deal_price' => 80], ['promotion_type' => 'DOD', 'deal_price' => 80]],
            'LIGHTNING com estoque' => ['LIGHTNING', null, [...$cand, 'stock' => ['min' => 5, 'max' => 50]], ['deal_price' => 80, 'stock' => 10],
                ['promotion_type' => 'LIGHTNING', 'deal_price' => 80, 'stock' => 10]],
            'pré-acordado com offer_id da leitura' => ['PRE_NEGOTIATED', 'N-1', [...$cand, 'offer_id' => 'OFFER-N1'], [],
                ['promotion_id' => 'N-1', 'promotion_type' => 'PRE_NEGOTIATED', 'offer_id' => 'OFFER-N1']],
            'liquidação com offer_id da leitura' => ['UNHEALTHY_STOCK', 'U-1', [...$cand, 'offer_id' => 'OFFER-U1'], [],
                ['promotion_id' => 'U-1', 'promotion_type' => 'UNHEALTHY_STOCK', 'offer_id' => 'OFFER-U1']],
            'SMART com CANDIDATE-' => ['SMART', 'S-1', [...$cand, 'offer_id' => 'CANDIDATE-MLB1-1'], [],
                ['promotion_id' => 'S-1', 'promotion_type' => 'SMART', 'offer_id' => 'CANDIDATE-MLB1-1']],
            'preço competitivo com CANDIDATE-' => ['PRICE_MATCHING', 'M-1', [...$cand, 'offer_id' => 'CANDIDATE-MLB1-2'], [],
                ['promotion_id' => 'M-1', 'promotion_type' => 'PRICE_MATCHING', 'offer_id' => 'CANDIDATE-MLB1-2']],
            'campanha do vendedor com preço' => ['SELLER_CAMPAIGN', 'SC-1', $cand, ['deal_price' => 85, 'top_deal_price' => 80],
                ['promotion_id' => 'SC-1', 'promotion_type' => 'SELLER_CAMPAIGN', 'deal_price' => 85, 'top_deal_price' => 80]],
        ];
    }

    #[DataProvider('inscricoes')]
    public function test_inscrever_manda_exatamente_a_matriz(string $tipo, ?string $promocao, array $linha, array $dados, array $corpo): void
    {
        $this->cenario();
        $this->entrada($tipo, $promocao, $linha);

        $acao = $this->inscrever($tipo, $promocao, $dados);
        $resumo = $acao->resumo();
        $this->assertSame($tipo === 'SELLER_COUPON_CAMPAIGN' ? 'cupom' : 'promocao', $acao->alavanca());
        $this->assertSame('Produto MLB1', $resumo['titulo']);
        $this->assertSame($tipo, $resumo['analise']['promotion_type']);

        $linhaHistorico = $this->executar($acao);

        $this->assertSame(PubAlavancaEscrita::OK, $linhaHistorico->resultado, (string) $linhaHistorico->mensagem);
        $posts = $this->chamadasNaoGet();
        $this->assertCount(1, $posts);
        $this->assertSame('POST', $posts[0]['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $posts[0]['caminho']);
        $this->assertSame(['app_version' => 'v2'], $posts[0]['query']);
        $this->assertEquals($corpo, $posts[0]['corpo']);
    }

    public function test_offer_id_do_navegador_e_ignorado_e_vale_o_da_leitura(): void
    {
        $this->cenario();
        $this->entrada('SMART', 'S-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100, 'offer_id' => 'CANDIDATE-MLB1-REAL']);

        $linha = $this->executar($this->inscrever('SMART', 'S-1', ['offer_id' => 'CANDIDATE-FALSO']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $this->assertSame('CANDIDATE-MLB1-REAL', $this->chamadasNaoGet()[0]['corpo']['offer_id']);
    }

    public function test_resumo_traz_preco_desconto_prazo_e_parte_do_ml(): void
    {
        $this->cenario();
        $this->entrada('SMART', 'S-1', ['status' => 'candidate', 'price' => 90, 'original_price' => 100, 'offer_id' => 'CANDIDATE-MLB1-1',
            'meli_percentage' => 3, 'seller_percentage' => 7, 'start_date' => '2026-10-10T00:00:00', 'end_date' => '2026-10-20T23:59:59']);

        $r = $this->inscrever('SMART', 'S-1')->resumo();

        $this->assertSame(100.0, $r['preco_atual']);
        $this->assertSame(90.0, $r['preco_promocao']);
        $this->assertSame(10.0, $r['desconto_percentual']);
        $this->assertSame(3.0, $r['ml_banca']);
        $this->assertSame('2026-10-10T00:00:00', $r['prazo']['inicio']);
        $this->assertSame(3, $r['analise']['meli_percentage']);
    }

    // ═══ Inscrever: recusas ═══

    public function test_preco_em_tipo_que_o_ml_define_e_recusado(): void
    {
        $this->cenario();
        $this->entrada('MARKETPLACE_CAMPAIGN', 'C-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100]);

        $this->recusada($this->executar($this->inscrever('MARKETPLACE_CAMPAIGN', 'C-1', ['deal_price' => 80])), 'ALAV-CONV-02');
    }

    public function test_item_que_nao_e_candidato_e_recusado(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);
        $this->recusada($this->executar($this->inscrever('DEAL', 'P-1', ['deal_price' => 85])), 'ALAV-CONV-01');
    }

    public function test_item_fora_da_promocao_do_ml_e_recusado(): void
    {
        $this->cenario();
        $this->promos['MARKETPLACE_CAMPAIGN|C-1'] = [];
        $this->recusada($this->executar($this->inscrever('MARKETPLACE_CAMPAIGN', 'C-1')), 'ALAV-CONV-01');
    }

    public function test_smart_sem_offer_id_candidate_manda_aceitar_no_mercado_livre(): void
    {
        $this->cenario();
        $this->entrada('SMART', 'S-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100]);

        $linha = $this->executar($this->inscrever('SMART', 'S-1'));

        $this->recusada($linha, 'ALAV-CONV-03');
        $this->assertStringContainsString('ceite no Mercado Livre', (string) $linha->mensagem);
    }

    public function test_deal_com_preco_fora_da_faixa_e_lightning_com_estoque_fora_sao_recusados(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100, 'min_discounted_price' => 70, 'max_discounted_price' => 95]);
        $this->entrada('LIGHTNING', null, ['status' => 'candidate', 'price' => 100, 'original_price' => 100, 'stock' => ['min' => 5, 'max' => 50]]);

        $this->recusada($this->executar($this->inscrever('DEAL', 'P-1', ['deal_price' => 60])), 'ALAV-CONV-06');
        $this->recusada($this->executar($this->inscrever('DEAL', 'P-1', ['deal_price' => 96])), 'ALAV-CONV-06');
        $this->recusada($this->executar($this->inscrever('LIGHTNING', null, ['deal_price' => 80, 'stock' => 100])), 'ALAV-CONV-07');
        $this->recusada($this->executar($this->inscrever('LIGHTNING', null, ['deal_price' => 80])), 'ALAV-CONV-07');
    }

    public function test_produto_de_outro_vendedor_e_recusado_mesmo_com_code_200(): void
    {
        $this->cenario();
        $this->entrada('MARKETPLACE_CAMPAIGN', 'C-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100], 'MLB999');

        $acao = new InscreverNoConvite($this->contaAlavanca(), ['item_id' => 'MLB999', 'promotion_type' => 'MARKETPLACE_CAMPAIGN', 'promotion_id' => 'C-1']);
        $linha = $this->executar($acao);

        $this->recusada($linha, 'ALAV-CONV-00');
        $this->assertSame('Produto não encontrado nesta conta.', $linha->mensagem);
    }

    // ═══ Alterar ═══

    public function test_alterar_deal_manda_put_com_preco_e_top(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'started', 'price' => 90, 'original_price' => 100, 'min_discounted_price' => 70, 'max_discounted_price' => 95]);

        $linha = $this->executar($this->alterar('DEAL', 'P-1', ['deal_price' => 80, 'top_deal_price' => 75]));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $put = $this->chamadasNaoGet()[0];
        $this->assertSame('PUT', $put['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $put['caminho']);
        $this->assertSame(['app_version' => 'v2'], $put['query']);
        $this->assertEquals(['promotion_id' => 'P-1', 'promotion_type' => 'DEAL', 'deal_price' => 80, 'top_deal_price' => 75], $put['corpo']);
    }

    public function test_campanha_do_vendedor_iniciada_baixa_preco_e_aceita_remove_loyalty(): void
    {
        $this->cenario();
        $this->entrada('SELLER_CAMPAIGN', 'SC-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);

        $this->assertSame(PubAlavancaEscrita::OK, $this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['deal_price' => 85]))->resultado);
        $this->assertEquals(['promotion_id' => 'SC-1', 'promotion_type' => 'SELLER_CAMPAIGN', 'deal_price' => 85], $this->chamadasNaoGet()[0]['corpo']);

        $this->chamadas = [];
        $this->assertSame(PubAlavancaEscrita::OK, $this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['remove_loyalty' => true]))->resultado);
        $this->assertEquals(['promotion_id' => 'SC-1', 'promotion_type' => 'SELLER_CAMPAIGN', 'remove_loyalty' => true], $this->chamadasNaoGet()[0]['corpo']);
    }

    public function test_campanha_do_vendedor_programada_aceita_preco_maior(): void
    {
        $this->cenario();
        $this->entrada('SELLER_CAMPAIGN', 'SC-1', ['status' => 'pending', 'price' => 90, 'original_price' => 100]);

        $this->assertSame(PubAlavancaEscrita::OK, $this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['deal_price' => 95]))->resultado);
    }

    public function test_campanha_do_vendedor_iniciada_recusa_preco_maior_e_mexer_no_top(): void
    {
        $this->cenario();
        $this->entrada('SELLER_CAMPAIGN', 'SC-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);

        $this->recusada($this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['deal_price' => 95])), 'ALAV-CAMP-03');
        $this->recusada($this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['deal_price' => 90])), 'ALAV-CAMP-03');
        $this->recusada($this->executar($this->alterar('SELLER_CAMPAIGN', 'SC-1', ['deal_price' => 85, 'top_deal_price' => 80])), 'ALAV-CAMP-03');
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function naoEditaveis(): array
    {
        return [
            'cofinanciada' => ['MARKETPLACE_CAMPAIGN', 'C-1'],
            'VOLUME' => ['VOLUME', 'V-1'],
            'DOD' => ['DOD', null],
            'LIGHTNING' => ['LIGHTNING', null],
            'desconto individual' => ['PRICE_DISCOUNT', null],
        ];
    }

    #[DataProvider('naoEditaveis')]
    public function test_tipo_que_nao_se_edita_e_recusado(string $tipo, ?string $promocao): void
    {
        $this->cenario();
        $this->entrada($tipo, $promocao, ['status' => 'started', 'price' => 90, 'original_price' => 100]);

        $acao = new AlterarNoConvite($this->contaAlavanca(), ['item_id' => 'MLB1', 'promotion_type' => $tipo, 'promotion_id' => $promocao, 'deal_price' => 80]);

        $this->recusada($this->executar($acao), 'ALAV-CONV-04');
    }

    // ═══ Remover ═══

    /** @return array<string, array{0: string, 1: ?string, 2: array, 3: array}> */
    public static function remocoes(): array
    {
        return [
            'DEAL' => ['DEAL', 'P-1', ['status' => 'started'], ['promotion_type' => 'DEAL', 'promotion_id' => 'P-1', 'app_version' => 'v2']],
            'cofinanciada leva offer_id' => ['MARKETPLACE_CAMPAIGN', 'C-1', ['status' => 'started', 'offer_id' => 'OFFER-C1'],
                ['promotion_type' => 'MARKETPLACE_CAMPAIGN', 'promotion_id' => 'C-1', 'offer_id' => 'OFFER-C1', 'app_version' => 'v2']],
            'SMART leva offer_id' => ['SMART', 'S-1', ['status' => 'pending', 'offer_id' => 'OFFER-S1'],
                ['promotion_type' => 'SMART', 'promotion_id' => 'S-1', 'offer_id' => 'OFFER-S1', 'app_version' => 'v2']],
            'cupom' => ['SELLER_COUPON_CAMPAIGN', 'K-1', ['status' => 'started'], ['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'promotion_id' => 'K-1', 'app_version' => 'v2']],
            'DOD programada sem promotion_id' => ['DOD', null, ['status' => 'pending'], ['promotion_type' => 'DOD', 'app_version' => 'v2']],
            'LIGHTNING programada' => ['LIGHTNING', null, ['status' => 'pending'], ['promotion_type' => 'LIGHTNING', 'app_version' => 'v2']],
        ];
    }

    #[DataProvider('remocoes')]
    public function test_remover_manda_delete_so_com_query_e_sem_corpo(string $tipo, ?string $promocao, array $linha, array $query): void
    {
        $this->cenario();
        $this->entrada($tipo, $promocao, ['price' => 90, 'original_price' => 100, ...$linha]);

        $resultado = $this->executar($this->remover($tipo, $promocao, ['offer_id' => 'OFFER-FALSO']));

        $this->assertSame(PubAlavancaEscrita::OK, $resultado->resultado, (string) $resultado->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame('/seller-promotions/items/MLB1', $delete['caminho']);
        $this->assertSame($query, $delete['query']);
        $this->assertEmpty($delete['corpo']);
    }

    public function test_dod_e_relampago_ativos_nao_se_removem(): void
    {
        $this->cenario();
        $this->entrada('DOD', null, ['status' => 'started', 'price' => 90, 'original_price' => 100]);
        $this->entrada('LIGHTNING', null, ['status' => 'started', 'price' => 90, 'original_price' => 100]);

        $this->recusada($this->executar($this->remover('DOD', null)), 'ALAV-CONV-05');
        $this->recusada($this->executar($this->remover('LIGHTNING', null)), 'ALAV-CONV-05');
    }

    // ═══ Tirar de todas ═══

    public function test_remover_de_todas_lista_as_promocoes_e_avisa_do_dod_e_relampago(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);
        $this->entrada('MARKETPLACE_CAMPAIGN', 'C-1', ['status' => 'pending', 'price' => 90, 'original_price' => 100, 'offer_id' => 'O-1']);
        $this->entrada('SMART', 'S-1', ['status' => 'candidate', 'price' => 90, 'original_price' => 100]);

        $resumo = (new RemoverDeTodas($this->contaAlavanca(), ['item_id' => 'MLB1']))->resumo();

        $this->assertCount(2, $resumo['linhas'], 'candidato não conta: não há o que tirar');
        $this->assertSame('Campanha tradicional', $resumo['linhas'][0]['rotulo']);
        $this->assertStringContainsString('P-1', $resumo['linhas'][0]['valor']);
        $this->assertContains('Ofertas do dia e relâmpago não saem por este caminho.', $resumo['avisos']);
        $this->assertNull($resumo['analise']);
    }

    public function test_remover_de_todas_manda_delete_so_com_app_version(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);

        $linha = $this->executar(new RemoverDeTodas($this->contaAlavanca(), ['item_id' => 'MLB1']));

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $delete = $this->chamadasNaoGet()[0];
        $this->assertSame('DELETE', $delete['metodo']);
        $this->assertSame(['app_version' => 'v2'], $delete['query']);
        $this->assertEmpty($delete['corpo']);
    }

    public function test_remover_de_todas_com_errors_no_corpo_e_erro_com_a_resposta_crua(): void
    {
        $this->cenario();
        $this->entrada('DEAL', 'P-1', ['status' => 'started', 'price' => 90, 'original_price' => 100]);
        $this->responder('DELETE', '#^/seller-promotions/items/MLB1$#', self::fixtureAlavanca('doc/acoes/delete_todas_parcial'));

        $linha = $this->executar(new RemoverDeTodas($this->contaAlavanca(), ['item_id' => 'MLB1']));

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame(200, $linha->http_status);
        $this->assertStringContainsString('successful_ids', json_encode($linha->resposta));
        $this->assertStringContainsString('423_ENTITY_LOCKED', json_encode($linha->resposta));
    }

    // ═══ Leituras compartilhadas ═══

    public function test_pre_carregar_tres_itens_do_mesmo_convite_le_uma_vez_e_as_acoes_nao_leem_de_novo(): void
    {
        $this->cenario();
        foreach (['MLB1', 'MLB2', 'MLB3'] as $item) {
            $this->entrada('DEAL', 'P-1', ['status' => 'candidate', 'price' => 100, 'original_price' => 100, 'min_discounted_price' => 70, 'max_discounted_price' => 95], $item);
        }
        $conta = $this->contaAlavanca();
        $leituras = LeiturasDaAcao::para($conta);

        $leituras->preCarregar(array_map(fn ($i) => ['item_id' => $i, 'promotion_id' => 'P-1', 'promotion_type' => 'DEAL'], ['MLB1', 'MLB2', 'MLB3']));

        $multigets = fn () => count(array_filter($this->chamadas, fn ($c) => $c['caminho'] === '/items'));
        $passadas = fn () => count(array_filter($this->chamadas, fn ($c) => str_starts_with($c['caminho'], '/seller-promotions/promotions/')));
        $this->assertSame(1, $multigets());
        $this->assertSame(1, $passadas());
        $antes = count($this->chamadas);

        foreach (['MLB1', 'MLB2', 'MLB3'] as $item) {
            (new InscreverNoConvite($conta, ['item_id' => $item, 'promotion_type' => 'DEAL', 'promotion_id' => 'P-1', 'deal_price' => 85]))
                ->usarLeituras($leituras)->validar();
        }

        $this->assertCount($antes, $this->chamadas, 'validar() depois do pré-carregamento não faz leitura nova');
    }
}
