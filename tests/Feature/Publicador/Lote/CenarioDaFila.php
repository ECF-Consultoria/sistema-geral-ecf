<?php

namespace Tests\Feature\Publicador\Lote;

use App\Models\EstruturaOferta;
use App\Models\PubFilaPublicacao;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;

/**
 * A conta #459 do `CenarioCadeira` com VÁRIAS cadeiras prontas para conferir, o ML simulado (conferência e
 * publicação) e os atalhos das rotas da publicação em lote. O relógio é parado pelo teste ANTES de montar o
 * cenário (o token vence em 5 h — viajar para além disso pediria o refresh).
 */
trait CenarioDaFila
{
    use CenarioCadeira;

    protected User $admin;

    /** @var list<PubProduto> */
    protected array $cadeiras = [];

    private int $postsDeItem = 0;

    protected function montarFila(int $quantas = 3): void
    {
        $this->montarCenario();
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Vitória Publicadora']);
        $this->fakeMl([
            '*/oauth/token' => fn () => Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1555596317]),
            // As respostas reais da sondagem: abaixo de R$ 79 o ML banca (`free_shipping_by_meli`); a partir dela é do vendedor.
            '*/shipping_options/free*' => function (Request $q) {
                parse_str((string) parse_url($q->url(), PHP_URL_QUERY), $query);

                return Http::response(self::fixture((float) ($query['item_price'] ?? 0) >= 79 ? 'conta/shipping_options_free_79' : 'conta/shipping_options_free_78_99'));
            },
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [],
                'tags' => [], 'permalink' => 'https://produto.mercadolivre.com.br/'.basename(parse_url($q->url(), PHP_URL_PATH)).'-cadeira-_JM']),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB90000000%02d', ++$this->postsDeItem), 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
        Http::preventStrayRequests();

        $this->cadeiras = [$this->produto];
        for ($n = 2; $n <= $quantas; $n++) {
            $this->cadeiras[] = $this->outraCadeira($n);
        }
    }

    /** Mais uma cadeira na mesma conta, montada como a do `CenarioCadeira` (pronta para conferir). */
    protected function outraCadeira(int $n, ?float $preco = 150.0): PubProduto
    {
        $oferta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => "CAD-0{$n}", 'fase' => 'simples', 'nome' => "Cadeira {$n}"]);
        $p = PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $oferta->id, 'sku' => "CAD-0{$n}", 'nome' => "Cadeira {$n}", 'origem' => PubProduto::ORIGEM_PORTAL]);
        $r = $this->repo->criar($p, [new Alvo('gold_special', "Cadeira Escritório Executiva ECF Modelo {$n}")]);
        $this->repo->gravarCategoria($r, self::schema(self::CADEIRA));
        $this->repo->gravarAtributos($r, self::ATRIBUTOS);
        $unica = $this->repo->snapshot($r->fresh())->variantes[0];
        $this->repo->gravarVariacao($r->fresh(), [], [$unica->comDados(['estoque' => 3, 'precos' => ['gold_special' => $preco],
            'atributos' => ['SELLER_SKU' => ['value_name' => "CAD-0{$n}"], 'GTIN' => ['value_name' => '7896553367645']]])]);
        $r->update(['descricao' => "Cadeira executiva {$n}.", 'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias']]);
        $foto = $r->imagens()->create(['caminho' => "publicador/cad-{$n}.jpg", 'sha256' => str_repeat(dechex($n % 16), 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => "PIC-{$n}"]);
        $this->repo->gravarAtribuicoes($r, [['imagem' => $foto->id, 'grupo' => R::GERAL, 'posicao' => 0]]);

        return $p;
    }

    protected function rascunhoDe(PubProduto $p): PubRascunho
    {
        return PubRascunho::where('produto_id', $p->id)->firstOrFail();
    }

    /** Confere com o ML simulado; o `validate` da #459 devolve só avisos (N-16). */
    protected function conferirTodas(?array $quais = null): void
    {
        foreach ($quais ?? $this->cadeiras as $p) {
            $v = app(ConferenciaService::class)->conferir($this->rascunhoDe($p));
            $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        }
    }

    protected function conta(): string
    {
        return 'company-'.$this->empresa->id;
    }

    protected function rotaLote(string $nome, array $extra = []): string
    {
        return route('mlb.anuncios.publicador.lote.'.$nome, ['conta' => $this->conta(), ...$extra]);
    }

    /** @param list<PubProduto>|null $quais */
    protected function agendar(?array $quais = null, array $opcoes = ['ciente' => true]): TestResponse
    {
        return $this->actingAs($this->admin)->postJson($this->rotaLote('agendar'), [
            'produtos' => array_map(fn (PubProduto $p) => $p->id, $quais ?? $this->cadeiras),
            ...$opcoes,
        ]);
    }

    protected function passada(): void
    {
        $this->artisan('publicador:fila-publicacao')->assertSuccessful();
    }

    protected function fila(): PubFilaPublicacao
    {
        return PubFilaPublicacao::query()->where('conta_chave', $this->conta())->latest('id')->firstOrFail();
    }

    protected function itemDe(PubProduto $p): PubFilaPublicacaoItem
    {
        return PubFilaPublicacaoItem::query()->where('produto_id', $p->id)->latest('id')->firstOrFail();
    }

    /** O `PublicarRascunhoJob` que o worker rodaria: a publicação de verdade, com o ML simulado. */
    protected function terminarPublicacao(PubProduto $p): PubPublicacao
    {
        $pub = PubPublicacao::query()->where('rascunho_id', $this->rascunhoDe($p)->id)->latest('id')->firstOrFail();
        $this->assertTrue(app(\App\Services\Publicador\PublicacaoService::class)->executarFatia($pub->fresh(), 45));

        return $pub->fresh();
    }

    protected function publicacoes(): int
    {
        return PubPublicacao::query()->count();
    }

    protected function postsDeItem(): int
    {
        return count(Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/items')));
    }

    protected function criados(PubPublicacao $p): array
    {
        return $p->itens()->where('status', PubPublicacaoItem::CREATED)->pluck('ml_item_id')->all();
    }
}
