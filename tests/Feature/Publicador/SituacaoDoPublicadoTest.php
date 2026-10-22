<?php

namespace Tests\Feature\Publicador;

use App\Models\PubPublicacao;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\SituacaoDoAnuncioMl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Demo 23/10/2026 — a lista final depois de publicar, como o teste no navegador na #459 (MGSTOREL) mostrou:
 *   - N17: com o aviso 4057 o anúncio saiu "a combinar" e a lista não disse nada;
 *   - N23: a variação "Marrom" (cor no atributo, sem eixo) aparecia como "Único";
 *   - H21: o ML pausou os anúncios logo depois e a tela seguia dizendo "Publicado".
 *
 * O ML é simulado: nenhum anúncio de verdade nasce aqui.
 */
class SituacaoDoPublicadoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** O anúncio como o ML o devolve agora em `GET /items/{id}`. */
    private array $noMl = ['status' => 'active', 'sub_status' => [], 'shipping' => ['mode' => 'me2', 'free_shipping' => true, 'logistic_type' => 'drop_off']];

    /** Nulo = o `GET /items/{id}` responde 500. */
    private bool $mlResponde = true;

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario();
        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => $this->mlResponde
                ? Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'permalink' => 'https://produto.mercadolivre.com.br/x', 'tags' => [], ...$this->noMl])
                : Http::response(['message' => 'erro'], 500),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB80000000%02d', ++$this->posts), 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
        $this->efetivos = ['titulos' => ['gold_special' => null], 'precos' => ['gold_special' => 207.19], 'mlbs' => []];
        // A cor é atributo do produto (sem eixo): a variação única é "Único" no rascunho, mas o anúncio sai Marrom.
        $this->repo->gravarAtributos($this->r, [...self::ATRIBUTOS, 'COLOR' => ['value_name' => 'Marrom']]);
        $this->r = $this->r->fresh();
    }

    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), $ator, cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        return $p->fresh();
    }

    private function itemNaTela(): array
    {
        return app(EditorRascunhoService::class)->estado($this->r->fresh())['publicacao']['itens'][0];
    }

    public function test_n17_anuncio_que_saiu_a_combinar_diz_isso_na_hora_sem_esperar_a_conferencia_de_frete(): void
    {
        // O aviso 4057: a conta não tem a logística da categoria e o ML tira o Mercado Envios.
        $this->noMl['shipping'] = ['mode' => 'not_specified', 'free_shipping' => false, 'logistic_type' => 'not_specified'];
        $this->publicar();

        $i = $this->itemNaTela();
        $this->assertNull($i['frete'], 'a conferência de frete do publicado ainda não rodou (fila, um minuto depois)');
        $this->assertSame(['modo' => 'not_specified', 'texto' => 'sem Mercado Envios: entrega a combinar com o comprador', 'a_combinar' => true], $i['envio']);
    }

    public function test_n17_mercado_envios_com_frete_gratis_tambem_e_dito(): void
    {
        $this->publicar();

        $this->assertSame('frete grátis pelo Mercado Envios', $this->itemNaTela()['envio']['texto']);
        $this->assertFalse($this->itemNaTela()['envio']['a_combinar']);
    }

    public function test_n17_item_antigo_sem_o_envio_gravado_usa_a_resposta_do_post(): void
    {
        $this->assertSame('Mercado Envios, frete pago pelo comprador',
            SituacaoDoAnuncioMl::envio(['status' => 'active'], ['status' => 201, 'corpo' => ['shipping' => ['mode' => 'me2', 'free_shipping' => false]]])['texto']);
        $this->assertNull(SituacaoDoAnuncioMl::envio(null, null), 'sem leitura nenhuma, não inventa');
    }
}
