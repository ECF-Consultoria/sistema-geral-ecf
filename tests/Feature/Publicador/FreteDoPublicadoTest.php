<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\ConferirFreteDoPublicadoJob;
use App\Models\EstruturaPrecificacao;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubTarefa;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\FreteDoPublicadoService;
use App\Services\Publicador\FreteParaAPrecificacaoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 11/10/2026 — a terceira conferência de frete: o que o Mercado Livre cobra do anúncio JÁ PUBLICADO contra o
 * que a Precificação usou. O usuário: "o mais confiável é no final, quando publicar e dentro do Mercado Livre
 * tiver o preço que realmente estamos pagando; tem que bater com os dois anteriores".
 *
 * O ML é simulado: nenhum anúncio de verdade nasce aqui.
 */
class FreteDoPublicadoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private const PORTAL = [
        'titulos' => ['gold_special' => null, 'gold_pro' => null],
        'precos' => ['gold_special' => 207.19, 'gold_pro' => 223.26],
        'promocoes' => ['gold_special' => 172.66, 'gold_pro' => 186.05],
        'sem_frete' => ['gold_special' => false, 'gold_pro' => false],
        'mlbs' => [],
    ];

    private const PACOTE = [
        'SELLER_PACKAGE_HEIGHT' => ['value_name' => '60 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '50 cm'],
        'SELLER_PACKAGE_LENGTH' => ['value_name' => '40 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '9000 g'],
    ];

    /**
     * O anúncio como o ML o devolve: o modo de envio e o preço em que ficou. R$ 190 cai na MESMA faixa de frete
     * do preço da promoção (R$ 172,66, entre 150 e 200): aí vale direto o custo por anúncio.
     */
    private array $anuncio = ['mode' => 'me2', 'logistic_type' => 'drop_off', 'dimensions' => '60x50x40,9000', 'price' => 190.00];

    /** O custo por anúncio (`item_id`); nulo = o ML responde 500. */
    private ?float $freteDoAnuncio = 20.00;

    /** O custo da cotação avulsa (a da conferência, e a da faixa da promoção). */
    private float $freteCotado = 20.00;

    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario();
        $this->fakeMl([
            '*/shipping_options/free*' => function (Request $q) {
                $porItem = isset($q->data()['item_id']);
                if ($porItem && $this->freteDoAnuncio === null) {
                    return Http::response(['message' => 'erro'], 500);
                }

                return Http::response(['coverage' => ['all_country' => ['list_cost' => $porItem ? $this->freteDoAnuncio : $this->freteCotado, 'billable_weight' => 20000]]]);
            },
            '*/items/*/description' => fn () => Http::response(['text' => '', 'plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [], 'tags' => [],
                'permalink' => 'https://produto.mercadolivre.com.br/x', 'price' => $this->anuncio['price'], 'listing_type_id' => 'gold_special',
                'shipping' => ['mode' => $this->anuncio['mode'], 'logistic_type' => $this->anuncio['logistic_type'], 'dimensions' => $this->anuncio['dimensions'], 'free_shipping' => true]]),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB90000000%02d', ++$this->posts), 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
        $this->efetivos = self::PORTAL;
        $this->fretes = ['por_tipo' => [
            'gold_special' => ['valor' => 20.00, 'origem' => 'digitado', 'oferta_id' => $this->produto->oferta_id],
            'gold_pro' => ['valor' => 20.00, 'origem' => 'digitado', 'oferta_id' => $this->produto->oferta_id],
        ], 'por_variante' => []];
        $this->repo->gravarAtributos($this->r, [...self::ATRIBUTOS, ...self::PACOTE]);
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->r = $this->r->fresh();
    }

    /** Confere (com a conferência de frete) e publica de verdade, no ML simulado. */
    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), $ator, cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        return $p->fresh();
    }

    private function frete(PubPublicacao $p): ?array
    {
        return $p->itens()->first()->fresh()->avisos['frete'] ?? null;
    }

    private function cotacoes(): array
    {
        return array_map(fn ($par) => $par[0]->data(), Http::recorded(fn (Request $r) => str_contains($r->url(), '/shipping_options/free'))->values()->all());
    }

    public function test_publicar_dispara_a_conferencia_de_frete_do_anuncio_com_um_minuto_de_folga(): void
    {
        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PUBLISHED, $p->status);
        $jobs = Queue::pushed(ConferirFreteDoPublicadoJob::class);
        $this->assertCount(1, $jobs, 'um disparo por publicação');
        $this->assertSame($p->id, $jobs->first()->publicacaoId);
        $this->assertSame('default', $jobs->first()->queue, 'ninguém espera por isto na tela: fila comum');
        $this->assertNotNull($jobs->first()->delay);
    }

    public function test_frete_do_anuncio_igual_ao_da_precificacao_fica_conferido_sem_aviso(): void
    {
        $p = $this->publicar();
        $this->freteDoAnuncio = 20.60;

        $resumo = app(FreteDoPublicadoService::class)->conferir($p);

        $this->assertSame(['conferidos' => 1, 'divergentes' => 0, 'sem_mercado_envios' => 0, 'sem_resposta' => 0], $resumo);
        $f = $this->frete($p);
        $this->assertTrue($f['aplicavel']);
        $this->assertSame('igual', $f['nivel']);
        $this->assertNull($f['mensagem']);
        $this->assertEquals([20.60, 20.0, 20.0], [$f['ml'], $f['portal'], $f['na_conferencia']], 'os três números lado a lado: anúncio, Precificação e conferência');
        $this->assertSame(20000, $f['peso_faturado']);
    }

    public function test_frete_do_anuncio_diferente_avisa_e_pede_para_refazer_o_preco(): void
    {
        $p = $this->publicar();
        $this->freteDoAnuncio = 47.85;

        $resumo = app(FreteDoPublicadoService::class)->conferir($p);

        $this->assertSame(1, $resumo['divergentes']);
        $f = $this->frete($p);
        $this->assertSame('reprecificar', $f['nivel']);
        $this->assertSame('Frete do Clássico: o Mercado Livre cobra R$ 47,85 e a Precificação usou R$ 20,00 (digitado no Portal). Diferença de R$ 27,85. O preço foi calculado com frete menor que o real: refaça o preço.', $f['mensagem']);
        $this->assertSame($this->produto->oferta_id, $f['oferta_id']);

        // A tela do editor e a da tarefa recebem o mesmo resumo, com o botão liberado.
        $doEditor = app(EditorRascunhoService::class)->estado($this->r->fresh())['publicacao']['itens'][0]['frete'];
        $this->assertTrue($doEditor['pode_levar']);
        $this->assertSame($f['mensagem'], $doEditor['mensagem']);
        $this->assertSame(['item_id', 'aplicavel', 'nivel', 'ml', 'portal', 'mensagem', 'levado_em', 'pode_levar'], array_keys($doEditor));

        $tarefa = PubTarefa::query()->where('rascunho_id', $this->r->id)->first();
        $this->assertNotNull($tarefa, 'a publicação abriu a tarefa pós-publicação');
        $dasTarefas = FreteDoPublicadoService::dasTarefas(collect([$tarefa]));
        $this->assertSame($doEditor, $dasTarefas[$tarefa->id]['MLB9000000001']);
    }

    /** O caso da Poltrona Opala: saiu "a combinar". O ML responde um custo por item, mas não cobra frete nenhum. */
    public function test_anuncio_a_combinar_nao_se_aplica_e_nem_pergunta_o_custo(): void
    {
        $p = $this->publicar();
        $antes = count($this->cotacoes());
        $this->anuncio = ['mode' => 'not_specified', 'logistic_type' => 'not_specified', 'dimensions' => null, 'price' => 756.46];
        $this->freteDoAnuncio = 240.90;

        $resumo = app(FreteDoPublicadoService::class)->conferir($p);

        $this->assertSame(1, $resumo['sem_mercado_envios']);
        $f = $this->frete($p);
        $this->assertFalse($f['aplicavel']);
        $this->assertSame('not_specified', $f['modo']);
        $this->assertArrayNotHasKey('nivel', $f);
        $this->assertCount($antes, $this->cotacoes(), 'o número de R$ 240,90 viria das medidas padrão da categoria: nem é pedido');
        $this->assertFalse(FreteDoPublicadoService::paraTela(1, $f)['pode_levar']);
    }

    /**
     * O anúncio sai no preço CHEIO e a Precificação cotou no da PROMOÇÃO. Em faixas de frete diferentes, o que
     * se compara é a cotação na faixa da promoção, com a logística e as medidas do próprio anúncio.
     */
    public function test_preco_cheio_e_promocao_em_faixas_diferentes_compara_na_faixa_da_promocao(): void
    {
        $p = $this->publicar();
        // O anúncio a R$ 250 (faixa de 200+) cobra 35; na faixa da promoção (R$ 172,66) a cotação dá 20,40.
        $this->anuncio['price'] = 250.00;
        $this->freteDoAnuncio = 35.00;
        $this->freteCotado = 20.40;

        app(FreteDoPublicadoService::class)->conferir($p);

        $f = $this->frete($p);
        $this->assertSame('igual', $f['nivel'], 'na mesma faixa de preço, o frete bate');
        $this->assertEquals([20.40, 35.00], [$f['ml'], $f['no_anuncio']], 'o valor no preço cheio fica guardado ao lado');
        $this->assertFalse($f['faixa_diferente']);

        $ultima = collect($this->cotacoes())->last();
        $this->assertEquals(172.66, $ultima['item_price']);
        $this->assertSame(['gold_special', 'drop_off', '60x50x40,9000'], [$ultima['listing_type_id'], $ultima['logistic_type'], $ultima['dimensions']]);
    }

    public function test_mercado_livre_sem_responder_nao_grava_nada_e_conta_para_tentar_de_novo(): void
    {
        $p = $this->publicar();
        $this->freteDoAnuncio = null;

        $resumo = app(FreteDoPublicadoService::class)->conferir($p);

        $this->assertSame(1, $resumo['sem_resposta']);
        $this->assertNull($this->frete($p));
    }

    public function test_levar_o_frete_do_anuncio_grava_na_precificacao_uma_vez_so(): void
    {
        $p = $this->publicar();
        $this->freteDoAnuncio = 47.85;
        app(FreteDoPublicadoService::class)->conferir($p);
        $item = $p->itens()->first();
        $quem = User::factory()->create(['role' => 'admin']);
        $revisao = $this->r->fresh()->revisao;

        $resumo = app(FreteParaAPrecificacaoService::class)->aplicarDoItem($item->fresh(), $this->r->fresh(), $quem);

        $this->assertSame(['aplicadas' => 1, 'ofertas' => ['CAD-01']], $resumo);
        $this->assertEquals(47.85, EstruturaPrecificacao::where('oferta_id', $this->produto->oferta_id)->value('frete_classico'));
        $this->assertNotNull($item->fresh()->avisos['frete']['levado_em']);
        $this->assertSame($revisao, $this->r->fresh()->revisao, 'o anúncio já está no ar: o rascunho não é tocado');
        $this->assertFalse(FreteDoPublicadoService::paraTela($item->id, $item->fresh()->avisos['frete'])['pode_levar']);

        try {
            app(FreteParaAPrecificacaoService::class)->aplicarDoItem($item->fresh(), $this->r->fresh(), $quem);
            $this->fail('levou o mesmo frete duas vezes');
        } catch (RegraViolada $e) {
            $this->assertSame('FRT-01', $e->regra);
        }
    }

    public function test_pela_rota_so_leva_frete_de_anuncio_deste_produto_e_que_divergiu(): void
    {
        $p = $this->publicar();
        $item = $p->itens()->first();
        $admin = User::factory()->create(['role' => 'admin']);
        $rota = fn (int $itemId) => route('mlb.anuncios.publicador.itens.frete-precificacao', ['produto' => $this->produto->id, 'item' => $itemId]);

        // Ainda sem a conferência do anúncio: nada a levar.
        $this->withoutVite()->actingAs($admin)->postJson($rota($item->id))->assertStatus(422)->assertJsonPath('regra', 'FRT-01');

        // Item que não é deste produto: 404.
        $this->actingAs($admin)->postJson($rota($item->id + 999))->assertNotFound();

        $this->freteDoAnuncio = 47.85;
        app(FreteDoPublicadoService::class)->conferir($p);
        $this->actingAs($admin)->postJson($rota($item->id))
            ->assertOk()
            ->assertJsonPath('frete_levado.aplicadas', 1)
            ->assertJsonPath('publicacao.itens.0.frete.pode_levar', false);
    }
}
