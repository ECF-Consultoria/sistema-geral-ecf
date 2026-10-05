<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\CacheAlavancas;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Services\Publicador\Alavancas\LeitorContaAlavancas;
use App\Services\Publicador\ClienteMlPublicador;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\Feature\Publicador\Alavancas\Fakes\AcaoDeTeste;
use Tests\TestCase;

/** 166-03: escrita nunca repetida às cegas; 423 limitado; reentrega sem duplicar. */
class RobustezTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** @var list<array{0: int, 1: mixed}> respostas do POST, uma por envio (o estado muda, o fake não é refeito) */
    private array $filaPost = [];

    private int $posts = 0;

    private function escritor(): EscritorAlavancas
    {
        return new EscritorAlavancas(app(ClienteMlPublicador::class), app(LeitorContaAlavancas::class), app(CacheAlavancas::class),
            function (int $s) { $this->esperas[] = $s; });
    }

    private function executar(): PubAlavancaEscrita
    {
        return $this->escritor()->executar(new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123']), $this->admin);
    }

    private function postsEnfileirados(array $fila): void
    {
        $this->filaPost = $fila;
        $this->responder('POST', '#^/seller-promotions/items/#', function () {
            $this->posts++;
            [$status, $corpo] = array_shift($this->filaPost) ?? [200, ['ok' => true]];

            return Http::response($corpo, $status);
        });
    }

    public function test_423_e_depois_200_envia_duas_vezes(): void
    {
        $this->montarAlavancas();
        $this->postsEnfileirados([[423, ['message' => 'locked']], [200, ['ok' => true]]]);

        $linha = $this->executar();

        $this->assertSame(2, $this->posts);
        $this->assertSame([2], $this->esperas);
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
    }

    public function test_423_sempre_para_no_terceiro_envio(): void
    {
        $this->montarAlavancas();
        $this->postsEnfileirados([[423, ['m' => 1]], [423, ['m' => 1]], [423, ['m' => 1]], [423, ['m' => 1]]]);

        $linha = $this->executar();

        $this->assertSame(3, $this->posts);
        $this->assertSame([2, 2], $this->esperas);
        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertStringContainsString('ocupado', $linha->mensagem);
    }

    public function test_503_nao_repete_e_vira_incerto(): void
    {
        $this->montarAlavancas();
        $this->postsEnfileirados([[503, ['message' => 'indisponivel']], [200, ['ok' => true]]]);

        $linha = $this->executar();

        $this->assertSame(1, $this->posts);
        $this->assertSame(PubAlavancaEscrita::INCERTO, $linha->resultado);
        $this->assertStringContainsString('pode ter sido feita', $linha->mensagem);
    }

    public function test_timeout_nao_repete_e_vira_incerto(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/seller-promotions/items/#', function () {
            $this->posts++;

            return Http::failedConnection();
        });

        $linha = $this->executar();

        $this->assertSame(1, $this->posts);
        $this->assertSame(PubAlavancaEscrita::INCERTO, $linha->resultado);
    }

    public function test_429_com_retry_after_espera_e_termina_ok(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/seller-promotions/items/#', function () {
            $this->posts++;

            return $this->posts === 1
                ? Http::response(['message' => 'too many'], 429, ['Retry-After' => '1'])
                : Http::response(['ok' => true], 200);
        });

        $linha = $this->executar();

        $this->assertSame(2, $this->posts);
        $this->assertSame([1], $this->esperas);
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
    }

    public function test_reentrega_com_enviado_em_vira_incerto_sem_chamada(): void
    {
        $this->montarAlavancas();
        $escritor = $this->escritor();
        $acao = new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123']);
        $linha = $escritor->abrirLinha($acao, $this->admin);
        $linha->update(['enviado_em' => now()]);

        $r = $escritor->executar($acao, $this->admin, null, $linha);

        $this->assertSame(PubAlavancaEscrita::INCERTO, $r->resultado);
        $this->assertSame([], $this->chamadas);
    }

    public function test_linha_ja_concluida_nao_e_tocada(): void
    {
        $this->montarAlavancas();
        $escritor = $this->escritor();
        $acao = new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123']);
        $linha = $escritor->abrirLinha($acao, $this->admin)->marcar(PubAlavancaEscrita::OK);

        $r = $escritor->executar($acao, $this->admin, null, $linha);

        $this->assertSame(PubAlavancaEscrita::OK, $r->resultado);
        $this->assertSame([], $this->chamadas);
    }

    public function test_consulta_por_post_permitida_envia_um_post(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', ['recomendacoes' => []]);

        $r = $this->escritor()->consultaPorPost($this->contaAlavanca(), '/prices-per-quantity/v1/recommendations', ['item_id' => 'MLB1']);

        $this->assertTrue($r->ok());
        $this->assertCount(1, $this->chamadasNaoGet());
    }

    public function test_consulta_por_post_fora_da_lista_lanca_sem_http(): void
    {
        $this->montarAlavancas();

        try {
            $this->escritor()->consultaPorPost($this->contaAlavanca(), '/seller-promotions/items/MLB1', []);
            $this->fail('devia lançar');
        } catch (\LogicException) {
            $this->assertSame([], $this->chamadas);
        }
    }

    public function test_consulta_por_post_em_conta_nao_liberada_recusa_sem_http(): void
    {
        $this->montarAlavancas('company', liberada: false);

        try {
            $this->escritor()->consultaPorPost($this->contaAlavanca(), '/prices-per-quantity/v1/recommendations', []);
            $this->fail('devia recusar');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-LIB', $e->regra);
            $this->assertSame([], $this->chamadas);
        }
    }
}
