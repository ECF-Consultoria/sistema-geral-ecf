<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\PubVariantePreco;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Review 172 CR-01: excluir no Portal a oferta ÂNCORA de um produto agrupado (D-06) não pode
 * congelar o preço de uma cor em todas as variantes nem apagar o preço ao vivo das demais.
 */
class OfertaAncoraDoGrupoExcluidaTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    /** @var array<string, float> SKU normalizado → preço anunciado no Clássico */
    private array $precos = ['mesa-1' => 100.0, 'mesa-2' => 120.0, 'mesa-3' => 140.0];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();

        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        // A Precificação devolve o preço de cada oferta pelo SKU dela (lido do ESTADO do teste, learnings §5).
        $this->mock(EstruturaPrecificacaoService::class, function ($m) {
            $m->shouldReceive('pagina')->andReturnUsing(function ($empresa, array $ids) {
                $por = [];
                foreach (EstruturaOferta::whereIn('id', $ids)->get() as $o) {
                    $por[$o->id] = ['classico' => ['anunciado' => $this->precos[mb_strtolower($o->sku)] ?? null]];
                }

                return ['por_oferta' => $por];
            });
        });

        $this->empresa = Company::factory()->create();
    }

    /** @return array{0: PubProduto, 1: list<EstruturaOferta>} */
    private function grupo(int $cores): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'MESA', 'nome' => 'Mesa',
            'categoria_ml_id' => self::CADEIRA, 'categoria_ml_nome' => 'Cadeiras']);
        $ofertas = [];
        foreach (array_slice(['Azul', 'Preto', 'Branco'], 0, $cores) as $i => $nome) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'MESA-'.($i + 1), 'eixo' => 'cor', 'valor' => $nome, 'custo' => 10, 'estoque' => 5]);
            $ofertas[] = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'MESA-'.($i + 1),
                'fase' => 'simples', 'nome' => "Mesa {$nome}"]);
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $ofertas[0]->id, 'estrutura_produto_id' => $p->id,
            'sku' => 'MESA', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);
        app(PortalParaRascunhoService::class)->preencher($pub);

        return [$pub, $ofertas];
    }

    private function excluir(EstruturaOferta $oferta): void
    {
        app(EstruturaOfertaService::class)->excluir($oferta->fresh(), AtorDoPortal::daEquipe(User::factory()->create()), viaProduto: true);
    }

    /** @return array<string, ?float> SKU da variante → preço GRAVADO no Clássico */
    private function precosGravados(PubProduto $pub): array
    {
        $r = PubRascunho::where('produto_id', $pub->id)->firstOrFail();
        $alvo = $r->alvos()->where('listing_type_id', 'gold_special')->firstOrFail();
        $saida = [];
        foreach ((new RascunhoRepository())->snapshot($r)->variantes as $v) {
            $sku = $v->dados['atributos']['SELLER_SKU']['value_name'] ?? '?';
            $variante = $r->variantes()->where('combinacao_chave', $v->chave)->first();
            $saida[$sku] = ($p = PubVariantePreco::where('variante_id', $variante->id)->where('alvo_id', $alvo->id)->value('preco')) === null ? null : (float) $p;
        }
        ksort($saida);

        return $saida;
    }

    public function test_grupo_e_reancorado_na_proxima_cor_e_so_a_cor_excluida_congela_o_seu_preco(): void
    {
        [$pub, $ofertas] = $this->grupo(3);
        $this->assertSame(['MESA-1' => null, 'MESA-2' => null, 'MESA-3' => null], $this->precosGravados($pub), 'nada digitado antes');

        $this->excluir($ofertas[0]);

        $pub = $pub->fresh();
        $this->assertSame($ofertas[1]->id, $pub->oferta_id, 'reancorado na próxima cor livre');
        $this->assertSame($pub->estrutura_produto_id, $pub->fresh()->estrutura_produto_id);
        $this->assertSame('MESA', $pub->sku, 'o SKU do grupo é o do produto do Portal');
        $this->assertSame(['MESA-1' => 100.0, 'MESA-2' => null, 'MESA-3' => null], $this->precosGravados($pub),
            'só a cor excluída congela; as outras seguem ao vivo pela sua oferta');
    }

    public function test_sem_outra_cor_livre_cada_variante_congela_o_preco_da_sua_cor(): void
    {
        [$pub, $ofertas] = $this->grupo(2);
        // A outra cor já é um produto avulso: não há oferta livre para reancorar.
        PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $ofertas[1]->id, 'sku' => 'MESA-2', 'nome' => 'Mesa Preto',
            'origem' => PubProduto::ORIGEM_PORTAL]);

        $this->excluir($ofertas[0]);

        $pub = $pub->fresh();
        $this->assertNull($pub->oferta_id);
        $this->assertSame(['MESA-1' => 100.0, 'MESA-2' => 120.0], $this->precosGravados($pub),
            'cada cor fica com o SEU preço, nunca o da âncora em todas');
    }
}
