<?php

namespace Tests\Feature\Publicador\Concerns;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Uma cadeira de escritório (MLB193945) pronta para conferir, na conta #459,
 * com o ML simulado pelas respostas REAIS da sondagem de 01/10.
 *
 * O fake HTTP é registrado UMA vez e responde pelo estado do teste
 * (`$this->condicionais`, `$this->validate`…): `Http::fake` acumula e o
 * primeiro stub que casa vence (learnings §5).
 */
trait CenarioCadeira
{
    use CarregaSchemas;

    protected const ATRIBUTOS = [
        'BRAND' => ['value_name' => 'ECF'],
        'MODEL' => ['value_name' => 'Executiva'],
        'BACKREST_HEIGHT' => ['value_name' => '50 cm'],
        'SEAT_DEPTH' => ['value_name' => '45 cm'],
        'OFFICE_CHAIR_WIDTH' => ['value_name' => '60 cm'],
        'MAX_CHAIR_HEIGHT' => ['value_name' => '110 cm'],
        'REQUIRES_ASSEMBLY' => ['value_id' => '242085'],
        'IS_GAMER' => ['value_id' => '242084'],
        'IS_ERGONOMIC' => ['value_id' => '242085'],
        'IS_SWIVEL' => ['value_id' => '242085'],
        'INCLUDES_ASSEMBLY_MANUAL' => ['value_id' => '242085'],
    ];

    protected Company $empresa;

    protected PubRascunho $r;

    protected RascunhoRepository $repo;

    /** @var list<string> o que o `/attributes/conditional` devolve agora */
    protected array $condicionais = [];

    /** @var list<string>|null tipos disponíveis; nulo = a resposta real (todos) */
    protected ?array $tipos = null;

    /** @var array<string, list<string>> SKU → MLBs ativos da conta */
    protected array $skuEm = [];

    /** @var \Closure(array): mixed o `/items/validate` agora */
    protected \Closure $validate;

    protected array $efetivos = ['titulos' => [], 'precos' => [], 'mlbs' => []];

    /** O `/users/me` agora; nulo = a conta #459 real (UP, sem depósitos). */
    protected ?array $usuario = null;

    /** @var list<int> esperas pedidas pelo cliente HTTP (o teste não dorme) */
    protected array $esperas = [];

    protected function montarCenario(): void
    {
        $this->empresa = Company::factory()->create();
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class),
            function (int $s) { $this->esperas[] = $s; }));
        $this->mock(DadosEfetivosService::class, fn ($m) => $m->shouldReceive('daOferta')->andReturnUsing(fn () => $this->efetivos));

        // O schema já guardado (24h): nenhuma chamada pública no teste.
        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria, 'atributos' => $schema->atributos,
            'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms, 'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        $this->repo = new RascunhoRepository();
        $oferta = EstruturaOferta::create(['company_id' => $this->empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        $this->r = $this->repo->criar($oferta, [new Alvo('gold_special', 'Cadeira Escritório Executiva ECF Giratória')]);
        $this->repo->gravarCategoria($this->r, $schema);
        $this->repo->gravarAtributos($this->r, self::ATRIBUTOS);
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'GTIN' => ['value_name' => '7896553367645']]);
        $this->r->update(['descricao' => 'Cadeira executiva giratória.', 'envio' => ['modo' => 'me2', 'frete_gratis' => true, 'retirada' => false],
            'garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias']]);
        $foto = $this->r->imagens()->create(['caminho' => 'publicador/x.jpg', 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => '123-MLB1_102026']);
        $this->repo->gravarAtribuicoes($this->r, [['imagem' => $foto->id, 'grupo' => R::GERAL, 'posicao' => 0]]);
        $this->r = $this->r->fresh();

        // O validate da conta #459 devolve 400 só com avisos (N-16): é aprovação.
        $this->validate = fn (array $corpo) => Http::response(self::fixture('conta/categorias/MLB193945/validate_base_up'), 400);
    }

    /** @param array<string, mixed> $extra  stubs a mais (os da publicação) — vêm DEPOIS dos da conferência */
    protected function fakeMl(array $extra = []): void
    {
        Http::fake([
            '*/users/me' => fn () => Http::response($this->usuario ?? self::fixture('conta/usuario')),
            '*/shipping_preferences*' => Http::response(self::fixture('conta/shipping_preferences')),
            '*/attributes/conditional*' => fn () => Http::response(['required_attributes' => array_map(fn ($id) => ['id' => $id, 'name' => $id], $this->condicionais), 'callbacks' => [], 'status' => 200]),
            '*/available_listing_types*' => function () {
                $real = self::fixture('conta/categorias/MLB193945/available_listing_types');
                if ($this->tipos !== null) {
                    $real['available'] = array_values(array_filter($real['available'], fn ($t) => in_array($t['id'], $this->tipos, true)));
                }

                return Http::response($real);
            },
            '*/items/search*' => fn (Request $req) => Http::response(['seller_id' => '1555596317', 'results' => $this->skuEm[$req->data()['seller_sku'] ?? ''] ?? [], 'paging' => ['total' => 0]]),
            '*/items/validate' => fn (Request $req) => ($this->validate)($req->data()),
            ...$extra,
        ]);
    }

    protected static function fixture(string $relativo): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta'];
    }

    protected function variante(array $atributos, int $estoque = 3, float $preco = 150.0): void
    {
        $unica = $this->repo->snapshot($this->r->fresh())->variantes[0];
        $this->repo->gravarVariacao($this->r->fresh(), [], [$unica->comDados(['estoque' => $estoque, 'precos' => ['gold_special' => $preco], 'atributos' => $atributos])]);
    }

    protected function chamadas(string $sufixo): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), $sufixo)));
    }
}
