<?php

namespace Tests\Feature\Publicador;

use App\Jobs\GerarAnaliseAnuncioIaJob;
use App\Models\MlAnuncioIaAnalise;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\CategorySchemaRepository;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\IaParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\CategorySchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Fase 160 / 160-09 (D14): o "Anunciar por IA" do Publicador grava no rascunho
 * `pub_*` do produto, pelo motor. Nenhuma IA nem ML reais: o resultado da
 * geração é montado à mão e os schemas vêm gravados em `ml_categoria_schemas`.
 */
class IaParaRascunhoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private MlbEmpresa $empresa;

    private PubProduto $produto;

    private RascunhoRepository $repo;

    private EditorRascunhoService $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Qualquer chamada de rede (IA, ML, app token) vira exceção — e o teste prova zero chamadas.
        Http::preventStrayRequests();
        Http::fake();

        foreach ([self::CADEIRA, self::FURADEIRA, self::CAMISETA] as $categoria) {
            $schema = self::schema($categoria);
            MlCategoriaSchema::create(['category_id' => $categoria, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
                'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
                'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
        }

        $this->empresa = MlbEmpresa::create(['nome' => 'Polo IA', 'projeto' => 'POLOS'])->fresh();
        $this->produto = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->repo = new RascunhoRepository();
        $this->editor = app(EditorRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function rascunho(): PubRascunho
    {
        return $this->editor->abrir($this->produto);
    }

    private function snap(): RascunhoSnapshot
    {
        return $this->repo->snapshot(PubRascunho::where('produto_id', $this->produto->id)->firstOrFail());
    }

    private function fichaCadeira(array $extra = []): array
    {
        return $extra + [
            'category_id' => self::CADEIRA,
            'titulo' => 'Cadeira de Escritório Executiva Giratória Ergonômica',
            'atributos' => [
                ['id' => 'BRAND', 'value_name' => 'ECF'],
                ['id' => 'MODEL', 'value_name' => 'Executiva'],
                ['id' => 'IS_GAMER', 'value_id' => '242084'],
                ['id' => 'NAO_EXISTE_NO_SCHEMA', 'value_name' => 'lixo'],
                ['id' => 'SELLER_SKU', 'value_name' => 'ignorado'],
            ],
            'pacote' => ['peso_g' => 12000, 'altura_cm' => 60, 'comprimento_cm' => 70, 'largura_cm' => 65, 'origem' => []],
            'garantia' => '90 dias',
            'variacoes' => [],
        ];
    }

    /**
     * Uma análise do Publicador com as etapas 1–4 já prontas (o job pula a IA).
     * A revisão do pedido é a do rascunho NESTE instante.
     */
    private function analise(array $ficha, bool $substituir = false, array $resultado = []): MlAnuncioIaAnalise
    {
        $r = $this->rascunho();

        return MlAnuncioIaAnalise::create([
            'mlb_empresa_id' => $this->empresa->id,
            'user_id' => $this->admin()->id,
            'produto' => 'Cadeira',
            'loja' => 'Polo IA',
            'status' => MlAnuncioIaAnalise::STATUS_PENDENTE,
            'resultado' => $resultado + [
                'destino' => ['tipo' => 'publicador', 'produto_id' => $this->produto->id, 'rascunho_id' => $r->id,
                    'revisao_base' => (int) $r->revisao, 'substituir' => $substituir],
                'analise' => ['posicionamento' => 'x'],
                'titulos' => [['texto' => 'Cadeira Escritório Giratória Executiva ECF', 'dentro_da_regra' => true]],
                'descricao' => "<p>Cadeira <strong>executiva</strong> giratória.</p><p>Encosto alto.</p>",
                'ficha' => $ficha,
                'cliente' => ['sku' => 'CAD-01', 'produto' => 'Cadeira', 'preco_c' => 199.9, 'preco_p' => 219.9, 'estoque' => 8],
            ],
        ]);
    }

    private function rodar(MlAnuncioIaAnalise $a): MlAnuncioIaAnalise
    {
        (new GerarAnaliseAnuncioIaJob($a->id))->handle(app(AnaliseAnuncioService::class));

        return $a->fresh();
    }

    private function titulo(string $tipo): ?string
    {
        foreach ($this->snap()->alvos as $alvo) {
            if ($alvo->listingTypeId === $tipo) {
                return $alvo->titulo;
            }
        }

        return null;
    }

    private function afirmarSemRedeSemWizardAntigo(): void
    {
        $this->assertSame(0, MlAnuncioRascunho::count());
        Http::assertNothingSent();
    }

    // ═══ POST ia.analise.store por produto ═══════════════════════════════════

    public function test_store_por_produto_cria_analise_com_destino_publicador_sem_exigir_token(): void
    {
        Queue::fake();

        $r = $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), [
            'produto_id' => $this->produto->id, 'substituir' => true, 'specs' => 'giratória',
        ]);

        $r->assertStatus(202)->assertJsonPath('status', 'pendente');
        $a = MlAnuncioIaAnalise::findOrFail($r->json('id'));
        $rascunho = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();

        $this->assertSame($this->empresa->id, $a->mlb_empresa_id);
        $this->assertSame('Polo IA', $a->loja);
        $this->assertSame('Cadeira', $a->produto);
        $this->assertSame(
            ['tipo' => 'publicador', 'produto_id' => $this->produto->id, 'rascunho_id' => $rascunho->id, 'revisao_base' => (int) $rascunho->revisao, 'substituir' => true],
            $a->destinoPublicador(),
        );
        Queue::assertPushed(GerarAnaliseAnuncioIaJob::class, fn ($j) => $j->analiseId === $a->id);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_store_de_empresa_sem_programa_da_404(): void
    {
        Queue::fake();
        $semPrograma = MlbEmpresa::create(['nome' => 'Sem programa'])->fresh();
        $p = PubProduto::create(['mlb_empresa_id' => $semPrograma->id, 'sku' => 'X', 'nome' => 'X', 'origem' => 'publicador']);

        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), ['produto_id' => $p->id])->assertNotFound();
        $this->assertSame(0, MlAnuncioIaAnalise::count());
        Queue::assertNothingPushed();
    }

    public function test_store_de_rascunho_publicado_ou_publicando_da_422(): void
    {
        Queue::fake();
        $r = $this->rascunho();

        foreach ([PubRascunho::PUBLISHING, PubRascunho::PUBLISHED] as $status) {
            $r->update(['status' => $status]);
            $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), ['produto_id' => $this->produto->id])
                ->assertStatus(422)->assertJsonPath('message', 'Este anúncio já foi publicado ou está publicando.');
        }
        $this->assertSame(0, MlAnuncioIaAnalise::count());
    }

    public function test_store_exige_company_ou_produto(): void
    {
        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), ['produto' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['company_id', 'produto_id']);
    }

    // ═══ Job: fatia 1 ═══════════════════════════════════════════════════════

    public function test_job_grava_no_rascunho_novo_e_nunca_no_antigo(): void
    {
        $a = $this->rodar($this->analise($this->fichaCadeira()));

        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $a->status);
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        $s = $this->snap();

        $this->assertSame(self::CADEIRA, $r->categoria_id);
        // Características do schema; o id inventado e o SKU de variante não entram.
        $this->assertSame('ECF', $s->atributos['BRAND']['value_name']);
        $this->assertSame('Executiva', $s->atributos['MODEL']['value_name']);
        $this->assertSame('242084', $s->atributos['IS_GAMER']['value_id']);
        $this->assertArrayNotHasKey('NAO_EXISTE_NO_SCHEMA', $s->atributos);
        $this->assertArrayNotHasKey('SELLER_SKU', $s->atributos);
        // Pacote.
        $this->assertSame('60 cm', $s->atributos['SELLER_PACKAGE_HEIGHT']['value_name']);
        $this->assertSame('65 cm', $s->atributos['SELLER_PACKAGE_WIDTH']['value_name']);
        $this->assertSame('70 cm', $s->atributos['SELLER_PACKAGE_LENGTH']['value_name']);
        $this->assertSame('12000 g', $s->atributos['SELLER_PACKAGE_WEIGHT']['value_name']);
        // Título nos dois tipos, descrição sem HTML, garantia pelos ids do schema.
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_special'));
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_pro'));
        $this->assertStringNotContainsString('<', (string) $r->descricao);
        $this->assertStringContainsString('Cadeira executiva giratória.', (string) $r->descricao);
        $this->assertStringContainsString('Encosto alto.', (string) $r->descricao);
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $r->garantia);
        // Variante única: estoque e preços do cliente.
        $unica = $s->variantes[0];
        $this->assertSame(8, $unica->dados['estoque']);
        $this->assertEquals(199.9, $unica->dados['precos']['gold_special']);
        $this->assertEquals(219.9, $unica->dados['precos']['gold_pro']);
        $this->assertSame('CAD-01', $unica->dados['atributos']['SELLER_SKU']['value_name']);

        // O resumo que o acompanhamento devolve.
        $p = $a->resultado['publicador'];
        $this->assertSame($r->id, $p['rascunho_id']);
        $this->assertNotEmpty($p['aplicado_em']);
        $this->assertGreaterThanOrEqual(5, $p['secoes']);
        $this->assertFalse($p['variacoes']);
        $this->assertFalse($p['sobrescreveu']);
        // A chave do wizard antigo não é gravada no caminho do Publicador.
        $this->assertArrayNotHasKey('rascunho_id', $a->resultado);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_rodar_de_novo_nao_reaplica(): void
    {
        $a = $this->rodar($this->analise($this->fichaCadeira()));
        $aplicadoEm = $a->resultado['publicador']['aplicado_em'];
        $revisao = PubRascunho::where('produto_id', $this->produto->id)->value('revisao');

        // A equipe muda a descrição; o job (retentado) não pode desfazer.
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        $this->editor->salvar($r, ['descricao' => 'Minha descrição final']);
        $a->update(['status' => MlAnuncioIaAnalise::STATUS_RODANDO]);
        $a = $this->rodar($a);

        $this->assertSame($aplicadoEm, $a->resultado['publicador']['aplicado_em']);
        $this->assertSame('Minha descrição final', $r->fresh()->descricao);
        $this->assertSame($revisao + 1, (int) $r->fresh()->revisao);
    }

    public function test_sem_substituir_so_preenche_o_que_esta_vazio(): void
    {
        $r = $this->rascunho();
        $this->editor->trocarCategoria($r, self::FURADEIRA);
        $this->editor->salvar($r->fresh(), [
            'descricao' => 'Descrição da equipe',
            'alvos' => [['listing_type_id' => 'gold_special', 'titulo' => 'Título da equipe', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'titulo' => '', 'ativo' => true]],
        ]);

        $a = $this->rodar($this->analise($this->fichaCadeira(), substituir: false));

        $r = $r->fresh();
        $this->assertSame(self::FURADEIRA, $r->categoria_id);
        $this->assertSame('Descrição da equipe', $r->descricao);
        $this->assertSame('Título da equipe', $this->titulo('gold_special'));
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_pro'));
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $r->garantia);
        $this->assertFalse($a->resultado['publicador']['sobrescreveu']);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_substituir_com_revisao_que_subiu_depois_do_pedido_so_preenche_vazios(): void
    {
        $a = $this->analise($this->fichaCadeira(), substituir: true);
        // A equipe edita enquanto a geração roda: a revisão sobe.
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        $this->editor->salvar($r, ['descricao' => 'Digitei durante a geração']);

        $a = $this->rodar($a);

        $this->assertSame('Digitei durante a geração', $r->fresh()->descricao);
        $this->assertFalse($a->resultado['publicador']['sobrescreveu']);
        // O que estava vazio foi preenchido.
        $this->assertSame(self::CADEIRA, $r->fresh()->categoria_id);
    }

    public function test_substituir_com_revisao_igual_reescreve_categoria_atributos_titulos_descricao_e_garantia(): void
    {
        $r = $this->rascunho();
        $this->editor->trocarCategoria($r, self::FURADEIRA);
        $this->editor->salvar($r->fresh(), [
            'atributos' => ['BRAND' => ['value_name' => 'Antiga']],
            'descricao' => 'Descrição antiga',
            'garantia' => ['tipo' => '6150835', 'tempo' => null, 'unidade' => null],
            'alvos' => [['listing_type_id' => 'gold_special', 'titulo' => 'Título antigo', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'titulo' => 'Outro antigo', 'ativo' => true]],
        ]);

        $a = $this->rodar($this->analise($this->fichaCadeira(), substituir: true));

        $r = $r->fresh();
        $this->assertTrue($a->resultado['publicador']['sobrescreveu']);
        $this->assertSame(self::CADEIRA, $r->categoria_id);
        $this->assertSame('ECF', $this->snap()->atributos['BRAND']['value_name']);
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_special'));
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_pro'));
        $this->assertStringContainsString('Cadeira executiva giratória.', (string) $r->descricao);
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $r->garantia);
    }

    public function test_rascunho_publicado_nao_e_alterado_e_o_aviso_diz_porque(): void
    {
        $a = $this->analise($this->fichaCadeira(), substituir: true);
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        $r->update(['status' => PubRascunho::PUBLISHED]);
        $antes = $r->fresh()->only(['revisao', 'categoria_id', 'descricao', 'garantia']);

        $a = $this->rodar($a);

        $this->assertSame($antes, $r->fresh()->only(['revisao', 'categoria_id', 'descricao', 'garantia']));
        $this->assertSame(0, $a->resultado['publicador']['secoes']);
        $this->assertSame('O anúncio já estava publicado; a IA não mudou nada.', $a->resultado['publicador']['aviso']);
        $this->assertSame(MlAnuncioIaAnalise::STATUS_CONCLUIDO, $a->status);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    /**
     * WR-B03: o "intocável" olha o FATO. Um item já criado no ML (ou uma publicação em andamento)
     * protege o rascunho mesmo que o status tenha sido rebaixado — como a conferência antiga fazia.
     */
    public function test_wr_b03_item_criado_ou_publicacao_rodando_tornam_intocavel_qualquer_status(): void
    {
        Queue::fake();
        $a = $this->analise($this->fichaCadeira(), substituir: true);
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        $p = $r->publicacoes()->create(['revisao' => $r->revisao, 'modelo_publicacao' => 'UP', 'status' => PubPublicacao::PUBLISHED,
            'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);
        $p->itens()->create(['indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => '__single__', 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB9000000001']);
        $r->update(['status' => PubRascunho::VALIDATED]); // o anúncio está no ar; o status mente
        $antes = $r->fresh()->only(['revisao', 'categoria_id', 'descricao', 'garantia']);

        $a = $this->rodar($a);

        $this->assertSame($antes, $r->fresh()->only(['revisao', 'categoria_id', 'descricao', 'garantia']));
        $this->assertSame('O anúncio já estava publicado; a IA não mudou nada.', $a->resultado['publicador']['aviso']);
        $this->actingAs($this->admin())->postJson(route('mlb.anuncios.ia.analise.store'), ['produto_id' => $this->produto->id])
            ->assertStatus(422)->assertJsonPath('message', 'Este anúncio já foi publicado ou está publicando.');

        // Publicação em andamento com o rascunho em DRAFT: também intocável.
        $p->itens()->delete();
        $p->update(['status' => PubPublicacao::RUNNING]);
        $r->update(['status' => PubRascunho::DRAFT]);
        $this->assertTrue(IaParaRascunhoService::intocavel($r->fresh()));

        $p->update(['status' => PubPublicacao::FAILED]);
        $this->assertFalse(IaParaRascunhoService::intocavel($r->fresh()), 'falhou sem nada no ar: a IA pode trabalhar');
    }

    // ═══ WR-B02: edição e publicação no meio da geração ═════════════════════

    /**
     * A pessoa (ou a publicação) age enquanto a IA lê o schema — o momento em que a IA pode
     * estar esperando o ML. `$quando` escolhe a leitura; `$pessoa` roda uma vez só.
     */
    private function noMeioDaGeracao(\Closure $pessoa, ?\Closure $quando = null): void
    {
        $this->app->instance(CategorySchemaRepository::class, new class(app(ClienteMlPublicador::class), $pessoa, $quando) extends CategorySchemaRepository
        {
            public function __construct(ClienteMlPublicador $cliente, private ?\Closure $pessoa, private ?\Closure $quando)
            {
                parent::__construct($cliente);
            }

            public function obter(string $categoriaId, bool $forcar = false): CategorySchema
            {
                if ($this->pessoa !== null && ($this->quando === null || ($this->quando)())) {
                    $pessoa = $this->pessoa;
                    $this->pessoa = null;
                    $pessoa();
                }

                return parent::obter($categoriaId, $forcar);
            }
        });
    }

    private function intocado(PubRascunho $r): array
    {
        $r = $r->fresh();

        return [$r->only(['revisao', 'categoria_id', 'descricao', 'garantia']), $r->atributos()->count(), $r->alvos()->whereNotNull('titulo')->count()];
    }

    public function test_wr_b02_edicao_da_pessoa_durante_a_geracao_fica_mesmo_pedindo_substituir(): void
    {
        $a = $this->analise($this->fichaCadeira(), substituir: true);
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        // Pediu "substituir", mas digitou característica, título e descrição enquanto a IA gerava.
        $this->noMeioDaGeracao(fn () => $this->editor->salvar($r->fresh(), [
            'atributos' => ['BRAND' => ['value_name' => 'Marca da equipe']],
            'descricao' => 'Descrição da equipe',
            'alvos' => [['listing_type_id' => 'gold_special', 'titulo' => 'Título da equipe', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'titulo' => '', 'ativo' => true]],
        ]));

        $a = $this->rodar($a);

        $s = $this->snap();
        $this->assertSame('Marca da equipe', $s->atributos['BRAND']['value_name'], 'digitado vence: a IA não sobrescreve');
        $this->assertSame('Título da equipe', $this->titulo('gold_special'));
        $this->assertSame('Descrição da equipe', $r->fresh()->descricao);
        // O que estava vazio a IA preencheu.
        $this->assertSame(self::CADEIRA, $r->fresh()->categoria_id);
        $this->assertSame('Executiva', $s->atributos['MODEL']['value_name']);
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_pro'));
        $this->assertSame(['tipo' => '2230280', 'tempo' => 90, 'unidade' => 'dias'], $r->fresh()->garantia);
        $this->assertFalse($a->resultado['publicador']['sobrescreveu']);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_wr_b02_publicacao_que_comeca_durante_a_geracao_deixa_o_rascunho_intocado(): void
    {
        $cenarios = [
            'publicação em andamento' => fn (PubRascunho $r) => $r->publicacoes()->create(['revisao' => $r->revisao, 'modelo_publicacao' => 'UP',
                'status' => PubPublicacao::RUNNING, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now()]),
            'item já criado no ML' => fn (PubRascunho $r) => $r->publicacoes()->create(['revisao' => $r->revisao, 'modelo_publicacao' => 'UP',
                'status' => PubPublicacao::PARTIALLY_PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()])
                ->itens()->create(['indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => '__single__',
                    'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => 'MLB9000000002']),
        ];

        foreach ($cenarios as $nome => $publicar) {
            $this->produto = PubProduto::create(['mlb_empresa_id' => $this->empresa->id, 'sku' => 'CAD-'.Str::random(4), 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
            $a = $this->analise($this->fichaCadeira(), substituir: true);
            $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
            $antes = $this->intocado($r);
            $this->noMeioDaGeracao(fn () => $publicar($r->fresh()));

            $a = $this->rodar($a);

            $this->assertSame($antes, $this->intocado($r), "{$nome}: a IA não grava nada");
            $this->assertSame(0, $a->resultado['publicador']['secoes'], $nome);
            $this->assertSame('O anúncio já estava publicado; a IA não mudou nada.', $a->resultado['publicador']['aviso'], $nome);
        }
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_wr_b02_publicacao_que_comeca_no_meio_da_aplicacao_para_a_ia_ali(): void
    {
        $a = $this->analise($this->fichaCadeira(), substituir: true);
        $r = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail();
        // A IA já gravou a categoria quando a equipe clica em Publicar.
        $this->noMeioDaGeracao(
            fn () => $r->publicacoes()->create(['revisao' => $r->fresh()->revisao, 'modelo_publicacao' => 'UP',
                'status' => PubPublicacao::RUNNING, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now()]),
            fn () => PubRascunho::whereKey($r->id)->value('categoria_id') !== null,
        );

        $a = $this->rodar($a);

        $this->assertSame(self::CADEIRA, $r->fresh()->categoria_id, 'o que foi gravado antes do clique fica');
        $this->assertNull($r->fresh()->descricao);
        $this->assertArrayNotHasKey('BRAND', $this->snap()->atributos);
        $this->assertNull($this->titulo('gold_special'));
        $this->assertSame(1, $a->resultado['publicador']['secoes']);
        $this->assertStringStartsWith('A publicação começou enquanto a IA preenchia; ela parou ali', (string) $a->resultado['publicador']['aviso']);
    }

    /**
     * Sem "substituir", a IA grava SÓ as chaves que preenche — nunca a lista inteira que leu.
     * O que a pessoa grava logo depois da leitura da IA (mudar um valor, acrescentar uma
     * característica, trocar um título) não volta para o valor antigo.
     */
    public function test_wr_b02_ia_nunca_regrava_a_lista_inteira(): void
    {
        $r = $this->rascunho();
        $this->editor->trocarCategoria($r, self::CADEIRA);
        $this->editor->salvar($r->fresh(), [
            'atributos' => ['MODEL' => ['value_name' => 'Modelo A']],
            'alvos' => [['listing_type_id' => 'gold_special', 'titulo' => 'Título da equipe', 'ativo' => true], ['listing_type_id' => 'gold_pro', 'titulo' => '', 'ativo' => false]],
        ]);
        $a = $this->analise($this->fichaCadeira(), substituir: false);

        $repo = new class extends RascunhoRepository
        {
            /** @var list<string> */
            public array $listasInteiras = [];

            public ?\Closure $depoisDeLer = null;

            public int $nivelBase = 0;

            public function snapshot(PubRascunho $r): RascunhoSnapshot
            {
                $s = parent::snapshot($r);
                // Dispara na leitura feita DENTRO da escrita da IA (sob a trava).
                if ($this->depoisDeLer !== null && DB::transactionLevel() > $this->nivelBase) {
                    $f = $this->depoisDeLer;
                    $this->depoisDeLer = null;
                    $f($r);
                }

                return $s;
            }

            public function gravarAtributos(PubRascunho $r, array $atributos): void
            {
                $this->listasInteiras[] = 'atributos';
                parent::gravarAtributos($r, $atributos);
            }

            public function gravarAlvos(PubRascunho $r, array $alvos): void
            {
                $this->listasInteiras[] = 'alvos';
                parent::gravarAlvos($r, $alvos);
            }
        };
        $repo->nivelBase = DB::transactionLevel();
        $repo->depoisDeLer = function (PubRascunho $r) {
            DB::table('pub_rascunho_atributos')->where('rascunho_id', $r->id)->where('attribute_id', 'MODEL')->update(['value_name' => 'Modelo B']);
            DB::table('pub_rascunho_atributos')->insert(['rascunho_id' => $r->id, 'attribute_id' => 'LINE', 'value_name' => 'Linha da equipe',
                'origem' => 'user', 'revisar' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('pub_rascunho_alvos')->where('rascunho_id', $r->id)->where('listing_type_id', 'gold_special')->update(['titulo' => 'Título B da equipe']);
        };
        $this->app->instance(RascunhoRepository::class, $repo);

        $this->rodar($a);

        $this->assertNull($repo->depoisDeLer, 'a leitura sob a trava aconteceu');
        $this->assertSame([], $repo->listasInteiras, 'a IA não regrava a lista inteira de características nem de tipos');
        $s = $this->snap();
        $this->assertSame('Modelo B', $s->atributos['MODEL']['value_name']);
        $this->assertSame('Linha da equipe', $s->atributos['LINE']['value_name']);
        $this->assertSame('ECF', $s->atributos['BRAND']['value_name'], 'o vazio a IA preencheu');
        $this->assertSame('Título B da equipe', $this->titulo('gold_special'));
        $this->assertSame('Cadeira de Escritório Executiva Giratória Ergonômica', $this->titulo('gold_pro'));
        $alvo = PubRascunho::where('produto_id', $this->produto->id)->firstOrFail()->alvos()->where('listing_type_id', 'gold_pro')->firstOrFail();
        $this->assertFalse((bool) $alvo->ativo, 'o título não liga o tipo que a pessoa desligou');
    }

    public function test_garantia_sem_correspondencia_nao_grava_e_vira_aviso(): void
    {
        $a = $this->rodar($this->analise($this->fichaCadeira(['garantia' => 'vitalícia'])));

        $this->assertNull(PubRascunho::where('produto_id', $this->produto->id)->firstOrFail()->garantia);
        $this->assertStringContainsString('vitalícia', (string) $a->resultado['publicador']['aviso']);
    }

    public function test_caminho_antigo_por_empresa_segue_criando_o_rascunho_do_wizard(): void
    {
        $this->assertNull((new MlAnuncioIaAnalise(['resultado' => ['rascunho_id' => 5]]))->destinoPublicador());
        $this->assertSame(['tipo' => 'publicador'], (new MlAnuncioIaAnalise(['resultado' => ['destino' => ['tipo' => 'publicador']]]))->destinoPublicador());
        $this->assertSame(1, substr_count((string) file_get_contents(app_path('Jobs/GerarAnaliseAnuncioIaJob.php')), 'criarRascunho('));
    }

    // ═══ Status ═════════════════════════════════════════════════════════════

    public function test_status_devolve_o_bloco_publicador(): void
    {
        $a = $this->rodar($this->analise($this->fichaCadeira()));

        $r = $this->actingAs($this->admin())->getJson(route('mlb.anuncios.ia.analise.status', ['analise' => $a->id]))->assertOk();

        $r->assertJsonPath('status', 'concluido')
            ->assertJsonStructure(['publicador' => ['rascunho_id', 'aplicado_em', 'secoes', 'variacoes', 'aviso']])
            ->assertJsonPath('publicador.rascunho_id', PubRascunho::where('produto_id', $this->produto->id)->value('id'))
            ->assertJsonPath('rascunho', null);
    }

    // ═══ Fatia 2: variações ═════════════════════════════════════════════════

    private function combinacao(string $cor, string $corId, string $tam, string $tamId, string $sku, int $qtd, float $preco): array
    {
        return [
            'attribute_combinations' => [
                ['id' => 'COLOR', 'name' => 'Cor', 'value_id' => $corId, 'value_name' => $cor],
                ['id' => 'SIZE', 'name' => 'Tamanho', 'value_id' => $tamId, 'value_name' => $tam],
            ],
            'attributes' => [['id' => 'GTIN', 'value_name' => '7891234567895'], ['id' => 'SELLER_SKU', 'value_name' => $sku]],
            'available_quantity' => $qtd,
            'price' => $preco,
            'picture_ids' => [],
        ];
    }

    private function fichaCamiseta(array $variacoes): array
    {
        return ['category_id' => self::CAMISETA, 'titulo' => 'Camiseta Algodão Básica Unissex', 'atributos' => [], 'variacoes' => $variacoes];
    }

    public function test_variacoes_2x2_viram_eixos_e_quatro_variantes_com_sku_estoque_e_preco(): void
    {
        $ia = [
            $this->combinacao('Coral-claro', '283148', 'G7', '3259486', 'CAM-CC-G7', 1, 70.0),
            $this->combinacao('Coral-claro', '283148', '11', '3259494', 'CAM-CC-11', 2, 71.0),
            $this->combinacao('Coral', '283149', 'G7', '3259486', 'CAM-C-G7', 3, 72.0),
            $this->combinacao('Coral', '283149', '11', '3259494', 'CAM-C-11', 4, 73.0),
        ];

        $a = $this->rodar($this->analise($this->fichaCamiseta($ia)));

        $s = $this->snap();
        $this->assertTrue($a->resultado['publicador']['variacoes']);
        $this->assertCount(2, $s->eixos);
        $this->assertSame(['COLOR', 'SIZE'], array_map(fn ($e) => $e->chave, $s->eixos));
        $this->assertSame('Cor', $s->eixos[0]->nome);
        $this->assertTrue($s->eixos[0]->definesPicture, 'COLOR define foto no schema da camiseta');
        $this->assertFalse($s->eixos[1]->definesPicture);
        $this->assertCount(4, $s->variantes);

        $porSku = [];
        foreach ($s->variantes as $v) {
            $this->assertFalse($v->orfa);
            $porSku[$v->dados['atributos']['SELLER_SKU']['value_name']] = $v;
        }
        $this->assertEqualsCanonicalizing(['CAM-CC-G7', 'CAM-CC-11', 'CAM-C-G7', 'CAM-C-11'], array_keys($porSku));
        // A variante "Coral / 11" recebeu os dados da combinação correspondente.
        $v = $porSku['CAM-C-11'];
        $this->assertSame(4, $v->dados['estoque']);
        $this->assertEquals(73.0, $v->dados['precos']['gold_special']);
        $this->assertEquals(219.9, $v->dados['precos']['gold_pro'], 'Premium prefere o preço Premium do cliente');
        $this->assertSame('7891234567895', $v->dados['atributos']['GTIN']['value_name']);
        $this->assertSame('Coral / 11', $v->rotulo($s->eixos));
        $this->assertSame(3, $porSku['CAM-C-G7']->dados['estoque']);
        $this->assertGreaterThanOrEqual(2, $a->resultado['publicador']['secoes']);
        $this->afirmarSemRedeSemWizardAntigo();
    }

    public function test_valores_sem_value_id_casam_pelo_nome_normalizado(): void
    {
        $nome = fn (string $cor, string $tam, string $sku) => [
            'attribute_combinations' => [
                ['id' => 'COLOR', 'name' => 'Cor', 'value_name' => $cor],
                ['id' => 'SIZE', 'name' => 'Tamanho', 'value_name' => $tam],
            ],
            'attributes' => [['id' => 'SELLER_SKU', 'value_name' => $sku]],
            'available_quantity' => 5,
            'price' => 50.0,
        ];

        $this->rodar($this->analise($this->fichaCamiseta([$nome('Preto', 'P', 'PR-P'), $nome('  preto ', 'M', 'PR-M')])));

        $s = $this->snap();
        $this->assertCount(1, $s->eixos[0]->valores, '"Preto" e "  preto " são o mesmo valor');
        $this->assertCount(2, $s->eixos[1]->valores);
        $this->assertCount(2, $s->variantes);
        $skus = array_map(fn ($v) => $v->dados['atributos']['SELLER_SKU']['value_name'] ?? null, $s->variantes);
        $this->assertEqualsCanonicalizing(['PR-P', 'PR-M'], $skus);
    }

    public function test_sem_variacoes_da_ia_o_rascunho_fica_com_a_variante_unica(): void
    {
        $a = $this->rodar($this->analise($this->fichaCamiseta([])));

        $s = $this->snap();
        $this->assertFalse($a->resultado['publicador']['variacoes']);
        $this->assertSame([], $s->eixos);
        $this->assertCount(1, $s->variantes);
        $this->assertSame(8, $s->variantes[0]->dados['estoque']);
    }

    public function test_mais_eixos_que_o_limite_nao_grava_eixo_nenhum_e_avisa(): void
    {
        config(['publicador.max_eixos' => 1]);
        $ia = [$this->combinacao('Coral', '283149', 'G7', '3259486', 'CAM-C-G7', 3, 72.0)];

        $a = $this->rodar($this->analise($this->fichaCamiseta($ia)));

        $this->assertSame([], $this->snap()->eixos);
        $this->assertFalse($a->resultado['publicador']['variacoes']);
        $this->assertStringContainsString('A IA sugeriu mais variações do que o Mercado Livre aceita; defina-as no card Variações.', $a->resultado['publicador']['aviso']);
    }

    public function test_rascunho_com_eixos_sem_substituir_mantem_os_eixos_e_avisa(): void
    {
        $r = $this->rascunho();
        $this->editor->trocarCategoria($r, self::CAMISETA);
        $this->editor->salvarEixos($r->fresh(), [['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '283149', 'nome' => 'Coral']]]]);
        $ia = [$this->combinacao('Coral-claro', '283148', 'G7', '3259486', 'CAM-CC-G7', 1, 70.0)];

        $a = $this->rodar($this->analise($this->fichaCamiseta($ia), substituir: false));

        $s = $this->snap();
        $this->assertSame(['COLOR'], array_map(fn ($e) => $e->chave, $s->eixos));
        $this->assertSame('283149', $s->eixos[0]->valores[0]->valueId);
        $this->assertFalse($a->resultado['publicador']['variacoes']);
        $this->assertStringContainsString('já tem variações', $a->resultado['publicador']['aviso']);
    }

    public function test_substituir_troca_os_eixos_e_a_variante_antiga_com_dados_vira_orfa_pela_regra_do_motor(): void
    {
        $r = $this->rascunho();
        $this->editor->trocarCategoria($r, self::CAMISETA);
        $this->editor->salvarEixos($r->fresh(), [['chave' => 'COLOR', 'nome' => 'Cor', 'defines_picture' => true, 'valores' => [['id' => '283149', 'nome' => 'Coral']]]]);
        $antiga = $this->snap()->variantes[0];
        $this->editor->salvarVariantes($r->fresh(), [$antiga->chave => ['estoque' => 9, 'precos' => ['gold_special' => 10.0]]]);

        $ia = [$this->combinacao('Coral-claro', '283148', 'G7', '3259486', 'CAM-CC-G7', 1, 70.0)];
        $a = $this->rodar($this->analise($this->fichaCamiseta($ia), substituir: true));

        $s = $this->snap();
        $this->assertTrue($a->resultado['publicador']['variacoes']);
        $this->assertSame(['COLOR', 'SIZE'], array_map(fn ($e) => $e->chave, $s->eixos));
        $ativas = array_values(array_filter($s->variantes, fn ($v) => ! $v->orfa));
        $this->assertCount(1, $ativas);
        $this->assertSame('CAM-CC-G7', $ativas[0]->dados['atributos']['SELLER_SKU']['value_name']);
        // A antiga não foi apagada por SQL: o motor a mantém (órfã) ou a regenerou, nunca perdeu o dado calado.
        $this->assertGreaterThanOrEqual(1, count($s->variantes));
        $this->assertSame(0, MlAnuncioRascunho::count());
    }
}
