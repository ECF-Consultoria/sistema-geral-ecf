<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\CacheAlavancas;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\Feature\Publicador\Alavancas\Fakes\AcaoDeTeste;
use Tests\TestCase;

/** 166-03: o histórico (D-05) nasce antes do HTTP e guarda a resposta crua, sem token. */
class HistoricoEscritaTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function executar(array $dados = []): PubAlavancaEscrita
    {
        $acao = new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123', ...$dados]);

        return app(EscritorAlavancas::class)->executar($acao, $this->admin);
    }

    public function test_a_linha_ja_existe_como_pendente_com_enviado_em_na_hora_do_post(): void
    {
        $this->montarAlavancas();
        $visto = null;
        $this->responder('POST', '#^/seller-promotions/items/MLB123$#', function () use (&$visto) {
            $linha = PubAlavancaEscrita::first();
            $visto = [$linha?->resultado, $linha?->enviado_em !== null];

            return Http::response(['ok' => true], 200);
        });

        $this->executar();

        $this->assertSame([PubAlavancaEscrita::PENDENTE, true], $visto);
    }

    public function test_erro_do_ml_guarda_codigo_mensagem_e_resposta_crua(): void
    {
        $this->montarAlavancas();
        $corpo = ['message' => 'Error', 'error' => 'bad_request', 'status' => 400,
            'cause' => [['error_code' => 'ERROR_CREDIBILITY_DISCOUNTED_PRICE', 'error_message' => 'Discounted price is not credible']]];
        $this->responder('POST', '#^/seller-promotions/items/#', $corpo, 400);

        $linha = $this->executar();

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame(400, $linha->http_status);
        $this->assertSame('ERROR_CREDIBILITY_DISCOUNTED_PRICE', $linha->erro_codigo);
        $this->assertStringContainsString('preço da promoção', $linha->mensagem);
        $this->assertSame($corpo, $linha->resposta);
    }

    public function test_resposta_texto_vira_texto(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/seller-promotions/items/#', fn () => Http::response('texto puro', 200));

        $linha = $this->executar();

        $this->assertSame(['_texto' => 'texto puro'], $linha->resposta);
    }

    public function test_nenhum_token_nem_authorization_no_historico(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB123/prices$#', ['version' => '7']);
        $this->responder('POST', '#^/seller-promotions/items/#', ['ok' => true]);

        $linha = $this->executar(['com_preparo' => true]);
        $json = json_encode($linha->toArray());

        $this->assertStringNotContainsString('fake-access-token', $json);
        $this->assertStringNotContainsString('Bearer', $json);
        $this->assertStringNotContainsString('Authorization', $json);
        $this->assertStringNotContainsString('authorization', $json);
    }

    public function test_preparo_vem_antes_e_o_post_leva_o_cabecalho_devolvido(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB123/prices$#', ['version' => '7']);
        $this->responder('POST', '#^/seller-promotions/items/#', ['ok' => true]);

        $linha = $this->executar(['com_preparo' => true]);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
        $post = $this->chamadasNaoGet()[0];
        $this->assertSame('7', $post['cabecalhos']['X-Version'][0] ?? null);
        $ordem = array_map(fn ($c) => $c['metodo'].' '.$c['caminho'], $this->chamadas);
        $this->assertLessThan(array_search('POST /seller-promotions/items/MLB123', $ordem, true), array_search('GET /items/MLB123/prices', $ordem, true));
        $this->assertSame('7', $linha->payload['cabecalhos']['X-Version']);
    }

    public function test_preparo_404_vira_erro_sem_post(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB123/prices$#', ['message' => 'nao existe'], 404);

        $linha = $this->executar(['com_preparo' => true]);

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame(404, $linha->http_status);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_regra_violada_no_validar_vira_recusada_sem_escrita(): void
    {
        $this->montarAlavancas();

        $linha = $this->executar(['recusar' => true]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-TESTE', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_excecao_no_validar_vira_erro_interno_sem_escrita(): void
    {
        $this->montarAlavancas();

        $linha = $this->executar(['quebrar' => true]);

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame('ALAV-INTERNO', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_ok_invalida_o_cache_da_conta_e_erro_nao(): void
    {
        $this->montarAlavancas();
        $cache = app(CacheAlavancas::class);
        $conta = $this->contaAlavanca();
        $calculos = 0;
        $ler = function () use ($cache, $conta, &$calculos) {
            return $cache->lembrar($conta, 'teste', [], 300, function () use (&$calculos) {
                return ++$calculos;
            });
        };

        $ler();
        $this->responder('POST', '#^/seller-promotions/items/#', ['m' => 'x'], 400);
        $this->executar();
        $ler();
        $this->assertSame(1, $calculos, 'ERRO não invalida');

        $this->rotasMl = [];
        $this->responder('POST', '#^/seller-promotions/items/#', ['ok' => true]);
        $this->executar();
        $ler();
        $this->assertSame(2, $calculos, 'OK invalida');
    }

    public function test_reentrega_de_linha_pendente_com_enviado_em_vira_incerto_sem_chamada(): void
    {
        $this->montarAlavancas();
        $escritor = app(EscritorAlavancas::class);
        $acao = new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123']);
        $linha = $escritor->abrirLinha($acao, $this->admin);
        $linha->update(['enviado_em' => now()]);

        $r = $escritor->executar($acao, $this->admin, null, $linha);

        $this->assertSame(PubAlavancaEscrita::INCERTO, $r->resultado);
        $this->assertSame([], $this->chamadas);
    }
}
