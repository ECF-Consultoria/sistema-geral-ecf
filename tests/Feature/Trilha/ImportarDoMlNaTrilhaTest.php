<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Trilha\ImportarAnuncioNaTrilhaJob;
use App\Models\Company;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoOrigemMl;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Models\PubProduto;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Publicador\PreparoIaAgenda;
use App\Services\Trilha\CadastroNaTrilha;
use App\Services\Trilha\ImportacaoDoMercadoLivre;
use App\Support\Publicador\RegrasDoTitulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * "Importar do Mercado Livre" na trilha (3ª entrega, F8, desenho §18 — fechado com o usuário em 03/11/2026), pelas
 * rotas da equipe, com o Job rodando na hora (fila `sync`):
 *
 * - o anúncio vira produto da Lista com variações (cor E tamanho pelo `eixo_2`), fotos (só na 1ª variação de cada cor),
 *   caixa, a ficha do anúncio, a IA completando SÓ o vazio, o título escrito pelo robô do Publicador (nunca o do
 *   anúncio) e as faltas que são só da equipe — custo, família e ambiente;
 * - sem SKU, a Ref é o MLB com a posição; a IA do título (ou os termos) fora → título vazio com aviso e a falta;
 * - nada é atualizado: reimportar é `na_lista`; Ref que já existe e SKU com anúncio na espera (D37) são recusados sem
 *   gravar nada; categoria fora do ar e anúncio de outra conta são erro; categoria não anunciável entra sem categoria,
 *   sem ficha e sem título; foto que não baixa é aviso;
 * - o Job vai para a fila da IA, não para a `high`; o evento é `passo: mercado_livre`; a origem guarda a jardinagem;
 * - **nenhuma chamada HTTP com a transação aberta** (D30): o `Http::fake` anota toda requisição feita com
 *   `DB::transactionLevel()` acima do nível do teste, e toda importação confere que a lista ficou vazia;
 * - nenhum "Portal" nem `/portal` no JSON; o custo em consultas é o mesmo a cada anúncio.
 *
 * Nenhuma chamada real (`Http::preventStrayRequests`), nada publicado, a IA é um dublê.
 */
class ImportarDoMlNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private const VENDEDOR = '999000111';

    private const ORIGINAL = 'Cadeira Escritório Giratória';

    private const DO_ROBO = 'Cadeira Ergonômica Giratória Encosto Tela Home Office';

    private Company $empresa;

    private User $dev;

    /** O nível de transação do próprio teste (o `RefreshDatabase` abre uma). */
    private int $nivelDoTeste = 0;

    /** @var list<string> requisições feitas com a transação aberta (D30: tem de ficar vazia) */
    public array $httpNaTransacao = [];

    /** @var array<string, array> os anúncios que o ML de mentira devolve */
    private array $itens = [];

    /** @var list<string> pedaços de URL de foto que não baixam */
    private array $fotosQueFalham = [];

    private bool $termosFora = false;

    // ─── A IA de mentira ───

    public array $pedidosDaFicha = [];

    public array $pedidosDoTitulo = [];

    public array $fichaDaIa = ['MATERIAL' => 'Couro sintético', 'BRAND' => 'Inventada'];

    public array $titulosDaIa = ['classico' => self::DO_ROBO, 'premium' => 'Cadeira Giratória Escritório Ergonômica Encosto Tela'];

    public bool $tituloFora = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nivelDoTeste = DB::transactionLevel();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->ml($r));

        $teste = $this;
        $this->app->instance(AnaliseAnuncioService::class, new class($teste) extends AnaliseAnuncioService
        {
            public function __construct(private $t) {}

            public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo, bool $comNaoSeAplica = false): array
            {
                $this->t->pedidosDaFicha[] = compact('produto', 'specs', 'titulo', 'catalogo');

                return ['dados' => ['atributos' => $this->t->fichaDaIa], 'meta' => ['modelo' => 'teste']];
            }

            public function titulosPorTermos(string $produto, string $caminhoCategoria, array $termos, array $escolhidos, int $maximo, string $fatos = '', string $marca = '', string $evitar = ''): array
            {
                $this->t->pedidosDoTitulo[] = compact('produto', 'caminhoCategoria', 'termos', 'maximo', 'fatos', 'marca', 'evitar');
                if ($this->t->tituloFora) {
                    throw new \RuntimeException('provedor fora');
                }

                return ['dados' => $this->t->titulosDaIa, 'meta' => ['modelo' => 'teste']];
            }
        });

        $this->empresa = $this->empresaDaTrilha();
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => self::VENDEDOR, 'access_token' => 'APP_USR-x',
            'refresh_token' => 'TG-x', 'expires_at' => now()->addHours(5), 'status' => 'active']);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    // ═══ O anúncio vira produto ═════════════════════════════════════════════

    public function test_anuncio_simples_vira_produto_com_titulo_do_robo_ficha_fotos_origem_e_so_as_faltas_da_equipe(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $r = $this->importar(['MLB111'])->assertOk();

        $this->assertFalse($r->json('em_andamento'));
        $this->assertSame(['novos' => 1, 'na_lista' => 0, 'recusados' => 0, 'erros' => 0], $r->json('totais'));
        $item = $r->json('itens.0');
        $produto = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole();
        $this->assertSame([
            'mlb' => 'MLB111', 'titulo' => self::DO_ROBO, 'titulo_do_anuncio' => self::ORIGINAL, 'situacao' => 'novo', 'passo' => null,
            'produto_id' => (int) $produto->id, 'nome' => self::ORIGINAL, 'codigo' => 'CAD-MLB111', 'variacoes' => 1,
            'fotos' => ['trazidas' => 3, 'falharam' => 0], 'ficha' => ['gravados' => 3, 'recusados' => 0, 'pela_ia' => 1],
            'avisos' => [], 'faltas' => ['custo', 'família', 'ambiente'], 'mensagem' => null,
        ], $item);

        // O produto: o nome é o do anúncio; o TÍTULO DE VENDA é o do robô, nunca o do anúncio.
        $this->assertSame(self::ORIGINAL, $produto->nome);
        $this->assertSame(self::DO_ROBO, $produto->titulo);
        $this->assertFalse(RegrasDoTitulo::mesmo($produto->titulo, self::ORIGINAL));
        $this->assertSame(['MLB193945', 'Cadeiras de Escritório'], [$produto->categoria_ml_id, $produto->categoria_ml_nome]);
        $this->assertSame('Cadeira giratória com encosto em tela.', $produto->descricao);
        $variacao = EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->with('volumes')->sole();
        $this->assertSame(['CAD-MLB111', 7], [$variacao->codigo, $variacao->estoque]);
        $vol = $variacao->volumes->sole();
        $this->assertSame([60.0, 45.0, 12.0, 4.5], [(float) $vol->comprimento, (float) $vol->largura, (float) $vol->altura, (float) $vol->peso]);
        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $variacao->id)->count());

        // A ficha: o que veio do anúncio fica; a IA só preencheu o vazio (a Marca "Inventada" não entrou).
        $ficha = EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->get()->keyBy('atributo_id');
        $this->assertSame('Ergo', $ficha['BRAND']->valor);
        $this->assertSame(['Couro sintético', '10'], [$ficha['MATERIAL']->valor, $ficha['MATERIAL']->valor_id]);
        $this->assertSame(['48', 'cm'], [$ficha['SEAT_DEPTH']->valor, $ficha['SEAT_DEPTH']->unidade]);
        $this->assertCount(1, $this->pedidosDaFicha, 'UMA chamada paga para a ficha');
        $this->assertStringContainsString('MATERIAL', $this->pedidosDaFicha[0]['catalogo']);
        $this->assertStringNotContainsString('BRAND', $this->pedidosDaFicha[0]['catalogo'], 'a IA recebe só o que falta');

        // O título: o MESMO pedido do Publicador, com o contexto do PRODUTO e o original como "não repita".
        $this->assertCount(1, $this->pedidosDoTitulo, 'UMA chamada paga para o título');
        $pedido = $this->pedidosDoTitulo[0];
        $this->assertSame(self::ORIGINAL, $pedido['produto']);
        $this->assertSame('Casa > Cadeiras de Escritório', $pedido['caminhoCategoria']);
        $this->assertSame(60, $pedido['maximo']);
        $this->assertSame('Ergo', $pedido['marca'], 'a marca vai como proibição');
        $this->assertSame(self::ORIGINAL, $pedido['evitar']);
        $this->assertSame('cadeira escritorio giratoria', $pedido['termos'][0], 'os termos relacionados ao produto primeiro');
        $this->assertStringContainsString('Material: Couro sintético', $pedido['fatos'], 'os fatos são os da ficha gravada');
        $this->assertStringNotContainsString('Ergo', $pedido['fatos'], 'sem a marca nos fatos');

        // A origem (o selo e a deduplicação) com a jardinagem gravada — nula, o estado do mesmo anúncio pelo outro
        // caminho diria "a IA ainda vai passar" para sempre.
        $origem = EstruturaProdutoOrigemMl::query()->where('produto_id', $produto->id)->sole();
        $this->assertSame(['MLB111', self::ORIGINAL], [$origem->ml_item_id, $origem->titulo_original]);
        $this->assertNotNull($origem->jardinagem);
        $this->assertSame(['MATERIAL'], $origem->jardinagem['campos_ia']);
        $this->assertStringContainsString('Rafael', (string) $origem->importado_por);

        // UM evento da trilha, com o passo e o anúncio.
        $eventos = $this->eventosDaTrilha();
        $this->assertCount(1, $eventos);
        $this->assertSame([CadastroNaTrilha::PASSO_MERCADO_LIVRE, 'MLB111'], [$eventos[0]->getExtraProperty('passo'), $eventos[0]->getExtraProperty('mlb')]);
        $this->assertSame((int) $this->dev->id, (int) $eventos[0]->causer_id);

        // Nada escrito no ML; nada foi ao Publicador (faltam custo, família e ambiente).
        $this->assertCount(0, Http::recorded(fn (Request $q) => $q->method() !== 'GET'));
        $this->assertSame(0, PubProduto::query()->count());

        // D41: a gaveta mostra o título do anúncio original (só no produto importado).
        $retrato = $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$produto->id}/cadastro")->assertOk();
        $this->assertSame(self::ORIGINAL, $retrato->json('produto.titulo_do_anuncio'));
        $this->assertTrue($retrato->json('produto.origem_ml'));
        $this->assertSame(self::DO_ROBO, $retrato->json('produto.titulo'));

        // Nenhum texto nem endereço do Portal.
        $this->assertSemPortal($r);
        $this->assertSemPortal($this->estado());
        $this->assertSemPortal($retrato);
    }

    public function test_cor_e_tamanho_gravam_os_dois_eixos_e_as_fotos_so_na_1a_variacao_de_cada_cor(): void
    {
        $comb = fn ($cor, $tam) => [['id' => 'COLOR', 'name' => 'Cor', 'value_name' => $cor], ['id' => 'SIZE', 'name' => 'Tamanho', 'value_name' => $tam]];
        $sku = fn (string $s) => [['id' => 'SELLER_SKU', 'value_name' => $s]];
        $this->anuncioNoAcervo('MLB222', $this->anuncio('MLB222', [
            'title'      => 'Meia Cano Longo',
            'pictures'   => $this->fotos('MLB222', 6),
            'variations' => [
                ['id' => 1, 'available_quantity' => 3, 'picture_ids' => ['P1', 'P2', 'P3'], 'attribute_combinations' => $comb('Branco', '34-39'), 'attributes' => $sku('MEIA-B-34')],
                ['id' => 2, 'available_quantity' => 5, 'picture_ids' => ['P4', 'P5', 'P6'], 'attribute_combinations' => $comb('Verde', '34-39'), 'attributes' => $sku('MEIA-V-34')],
                ['id' => 3, 'available_quantity' => 6, 'picture_ids' => ['P4'], 'attribute_combinations' => $comb('Verde', '39-43'), 'attributes' => $sku('MEIA-V-39')],
            ],
        ]));

        $item = $this->importar(['MLB222'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $item['variacoes']);
        $this->assertSame(['trazidas' => 6, 'falharam' => 0], $item['fotos']);
        $this->assertSame(['custo', 'família', 'ambiente'], $item['faltas'], 'as fotos contam por cor');
        $produto = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole();
        $vars = EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->orderBy('ordem')->get();
        $this->assertSame([['MEIA-B-34', 'cor', 'Branco', 'tamanho', '34-39'], ['MEIA-V-34', 'cor', 'Verde', 'tamanho', '34-39'], ['MEIA-V-39', 'cor', 'Verde', 'tamanho', '39-43']],
            $vars->map(fn ($v) => [$v->codigo, $v->eixo, $v->valor, $v->eixo_2, $v->valor_2])->all());
        // A galeria é da cor: a 1ª variação de cada cor tem as fotos; o 2º tamanho da Verde usa as dela.
        $this->assertSame([3, 3, 0], $vars->map(fn ($v) => EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->count())->all());
    }

    public function test_anuncio_sem_sku_ganha_o_mlb_com_a_posicao_como_ref(): void
    {
        $comb = fn ($cor) => [['id' => 'COLOR', 'name' => 'Cor', 'value_name' => $cor]];
        $this->anuncioNoAcervo('MLB333', $this->anuncio('MLB333', [
            'attributes' => $this->atributos(null),
            'pictures'   => $this->fotos('MLB333', 6),
            'variations' => [
                ['id' => 1, 'available_quantity' => 2, 'picture_ids' => ['P1', 'P2', 'P3'], 'attribute_combinations' => $comb('Preto')],
                ['id' => 2, 'available_quantity' => 4, 'picture_ids' => ['P4', 'P5', 'P6'], 'attribute_combinations' => $comb('Cinza')],
            ],
        ]));

        $item = $this->importar(['MLB333'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['MLB333-1', 'MLB333-2'], EstruturaProdutoVariacao::query()->where('company_id', $this->empresa->id)
            ->orderBy('ordem')->pluck('codigo')->all());
        $this->assertSame('MLB333-1', $item['codigo']);
    }

    public function test_o_titulo_nunca_e_o_do_anuncio_mesmo_quando_a_ia_o_devolve(): void
    {
        $this->titulosDaIa = ['classico' => self::ORIGINAL, 'premium' => self::ORIGINAL];
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $titulo = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole()->titulo;
        $this->assertNotNull($titulo, 'o servidor diferencia (troca a ordem das duas últimas palavras)');
        $this->assertFalse(RegrasDoTitulo::mesmo($titulo, self::ORIGINAL), "“{$titulo}” repete o título do anúncio");
        $this->assertSame($titulo, $item['titulo']);
    }

    public function test_ia_do_titulo_fora_deixa_o_titulo_vazio_com_aviso_e_a_falta(): void
    {
        $this->tituloFora = true;
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao'], 'o produto fica');
        $this->assertNull($item['titulo']);
        $this->assertSame(self::ORIGINAL, $item['titulo_do_anuncio']);
        $this->assertContains(ImportacaoDoMercadoLivre::AVISO_TITULO, $item['avisos']);
        $this->assertSame(['custo', 'título', 'família', 'ambiente'], $item['faltas'], 'e fica na Lista por essa falta');
        $this->assertNull(EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole()->titulo);
    }

    public function test_termos_da_categoria_fora_deixam_o_titulo_para_a_equipe_sem_chamar_a_ia(): void
    {
        $this->termosFora = true;
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao']);
        $this->assertNull($item['titulo']);
        $this->assertContains(ImportacaoDoMercadoLivre::AVISO_TITULO, $item['avisos']);
        $this->assertSame([], $this->pedidosDoTitulo, 'sem termos, a chamada paga do título nem sai');
    }

    // ═══ A fila e o estado ══════════════════════════════════════════════════

    public function test_o_job_vai_para_a_fila_da_ia_e_nao_para_a_high(): void
    {
        Queue::fake();
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $r = $this->importar(['MLB111'])->assertOk();

        $this->assertTrue($r->json('em_andamento'));
        // Na fila: o título do anúncio já vem; o de venda (`titulo`) só quando o robô escrever.
        $this->assertSame(['fila', null, null, self::ORIGINAL],
            [$r->json('itens.0.situacao'), $r->json('itens.0.passo'), $r->json('itens.0.titulo'), $r->json('itens.0.titulo_do_anuncio')]);
        $this->assertNotSame('high', PreparoIaAgenda::fila());
        Queue::assertPushedOn(PreparoIaAgenda::fila(), ImportarAnuncioNaTrilhaJob::class,
            fn (ImportarAnuncioNaTrilhaJob $j) => $j->mlb === 'MLB111' && $j->userId === (int) $this->dev->id && $j->companyId === (int) $this->empresa->id);
        Queue::assertNotPushed(ImportarAnuncioNaTrilhaJob::class, fn (ImportarAnuncioNaTrilhaJob $j) => $j->queue === 'high');
        $job = new ImportarAnuncioNaTrilhaJob(1, 'MLB1', 1);
        $this->assertSame([1, 600], [$job->tries, $job->timeout]);

        // Pedir de novo o que está na fila não enfileira outro.
        $this->importar(['MLB111'])->assertOk();
        Queue::assertPushed(ImportarAnuncioNaTrilhaJob::class, 1);
        $this->assertSame(0, EstruturaProduto::query()->count(), 'nada grava antes do Job');
    }

    public function test_sem_a_pessoa_o_job_nao_roda_e_o_anuncio_sai_com_erro(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        Queue::fake();
        $this->importar(['MLB111'])->assertOk();
        $outro = User::factory()->create();
        $id = $outro->id;
        $outro->delete();

        (new ImportarAnuncioNaTrilhaJob((int) $this->empresa->id, 'MLB111', (int) $id))->handle(app(ImportacaoDoMercadoLivre::class));

        $this->assertSame(['erro', ImportacaoDoMercadoLivre::MSG_SEM_PESSOA], [$this->estado()->json('itens.0.situacao'), $this->estado()->json('itens.0.mensagem')]);
        $this->assertSame(0, EstruturaProduto::query()->count());
    }

    public function test_a_trava_do_anuncio_e_a_mesma_do_outro_caminho_de_importacao(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        Queue::fake();
        $this->importar(['MLB111'])->assertOk();
        $trava = Cache::lock("estrutura:produtos-ml:importando:{$this->empresa->id}:MLB111", 60);
        $this->assertTrue($trava->get());

        (new ImportarAnuncioNaTrilhaJob((int) $this->empresa->id, 'MLB111', (int) $this->dev->id))->handle(app(ImportacaoDoMercadoLivre::class));

        $this->assertSame(['erro', ImportacaoDoMercadoLivre::MSG_EM_ANDAMENTO], [$this->estado()->json('itens.0.situacao'), $this->estado()->json('itens.0.mensagem')]);
        $this->assertSame(0, EstruturaProduto::query()->count());
        $trava->release();
    }

    public function test_limpar_esquece_o_painel_menos_o_que_esta_na_fila(): void
    {
        Queue::fake();
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        $existente = $this->produtoCompleto($this->empresa, 'Cadeira da Loja', 'CAD-MLB222');
        $this->anuncioNoAcervo('MLB222', $this->anuncio('MLB222'), ['skus' => ['CAD-MLB222']]);
        $this->importar(['MLB111', 'MLB222'])->assertOk();
        $this->assertSame(['fila', 'recusado'], array_column($this->estado()->json('itens'), 'situacao'));

        $r = $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/mercado-livre/limpar")->assertOk();

        $this->assertSame(['MLB111'], array_column($r->json('itens'), 'mlb'));
        $this->assertSame(1, EstruturaProduto::query()->whereKey($existente['produto']->id)->count());
    }

    // ═══ Nada é atualizado ══════════════════════════════════════════════════

    public function test_reimportar_responde_na_lista_e_nao_cria_outro(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        $primeiro = $this->importar(['MLB111'])->assertOk()->json('itens.0');
        $antes = $this->contagens();

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame(['na_lista', $primeiro['produto_id'], self::ORIGINAL, ImportacaoDoMercadoLivre::MSG_JA_NA_LISTA, null, self::ORIGINAL],
            [$item['situacao'], $item['produto_id'], $item['nome'], $item['mensagem'], $item['titulo'], $item['titulo_do_anuncio']]);
        $this->assertSame([], $item['avisos'], 'o pedido novo não herda o painel de antes');
        $this->assertSame($antes, $this->contagens());
        $lista = $this->lista(['mostrar_na_lista' => 1])->json('itens.0.na_lista');
        $this->assertSame(['produto_id' => $primeiro['produto_id'], 'nome' => self::ORIGINAL, 'como' => 'importado'], $lista);
    }

    public function test_ref_que_ja_existe_recusa_dizendo_de_qual_produto_e_e_nada_muda(): void
    {
        $existente = $this->produtoCompleto($this->empresa, 'Cadeira da Loja', 'cad-mlb111');
        // MLB111: o acervo já sabe o SKU (recusa no pedido, sem Job); MLB112: não sabe (recusa no Job, depois do GET).
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'), ['skus' => ['CAD-MLB111']]);
        $mesmoSku = $this->anuncio('MLB112');
        $mesmoSku['attributes'] = $this->atributos('CAD-MLB111');
        $this->anuncioNoAcervo('MLB112', $mesmoSku);
        $antes = $this->contagens();
        $retratoAntes = $this->retratoDoBanco($existente['produto']);

        $r = $this->importar(['MLB111', 'MLB112'])->assertOk();

        foreach ($r->json('itens') as $item) {
            $this->assertSame('recusado', $item['situacao'], json_encode($item, JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('Cadeira da Loja', $item['mensagem']);
            $this->assertNull($item['produto_id']);
        }
        $this->assertSame(['novos' => 0, 'na_lista' => 0, 'recusados' => 2, 'erros' => 0], $r->json('totais'));
        $this->assertSame($antes, $this->contagens());
        $this->assertSame($retratoAntes, $this->retratoDoBanco($existente['produto']), 'o cadastro da loja fica como estava');
        $this->assertSame([], $this->pedidosDaFicha);
    }

    public function test_sku_com_anuncio_na_espera_e_recusado_e_a_espera_fica(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        $espera = EstruturaAnuncioEspera::create(['company_id' => $this->empresa->id, 'sku_colado' => ' cad-mlb111 ',
            'motivo' => EstruturaAnuncioEspera::MOTIVO_SEM_OFERTA, 'tipo' => 'classico', 'codigo_mlb' => 'MLB777', 'titulo' => 'Cadeira no ar']);
        $antes = $this->contagens();

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame(['recusado', sprintf(ImportacaoDoMercadoLivre::MSG_ESPERA, 'CAD-MLB111')], [$item['situacao'], $item['mensagem']]);
        $this->assertSame($antes, $this->contagens(), 'nada grava: o produto nasceria ligado ao anúncio da espera, no ar e travado');
        $this->assertNotNull($espera->fresh());
        $this->assertSame(0, EstruturaAnuncio::query()->count());
    }

    // ═══ Categoria, fotos, conta ════════════════════════════════════════════

    public function test_categoria_que_nao_aceita_produto_novo_entra_sem_categoria_sem_ficha_sem_titulo_e_com_aviso(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111', ['category_id' => 'MLB5']));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertContains(ImportacaoDoMercadoLivre::AVISO_SEM_CATEGORIA, $item['avisos']);
        $this->assertNull($item['titulo']);
        $this->assertNotContains(ImportacaoDoMercadoLivre::AVISO_TITULO, $item['avisos'], 'o aviso da categoria já diz que o título fica para depois');
        $this->assertSame(['gravados' => 0, 'recusados' => 0, 'pela_ia' => 0], $item['ficha']);
        $this->assertContains('categoria', $item['faltas']);
        $produto = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole();
        $this->assertNull($produto->categoria_ml_id);
        $this->assertNull($produto->titulo);
        $this->assertSame(0, EstruturaProdutoAtributo::query()->where('produto_id', $produto->id)->count());
        $this->assertSame([[], []], [$this->pedidosDaFicha, $this->pedidosDoTitulo], 'nenhuma chamada paga');
        $this->assertSame(['trazidas' => 3, 'falharam' => 0], $item['fotos'], 'as fotos entram');
        $this->assertNotNull(EstruturaProdutoOrigemMl::query()->where('produto_id', $produto->id)->sole()->jardinagem);
    }

    public function test_moda_que_pede_tabela_de_medidas_entra_com_o_aviso(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111', ['category_id' => 'MLB7']));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao'], json_encode($item, JSON_UNESCAPED_UNICODE));
        $this->assertContains(ImportacaoDoMercadoLivre::AVISO_MODA, $item['avisos']);
        $this->assertSame('MLB7', EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole()->categoria_ml_id);
    }

    public function test_categoria_fora_do_ar_e_erro_e_nada_grava(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111', ['category_id' => 'MLB404']));
        $antes = $this->contagens();

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame(['erro', ImportacaoDoMercadoLivre::MSG_CATEGORIA_FORA], [$item['situacao'], $item['mensagem']]);
        $this->assertSame($antes, $this->contagens());
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles());
    }

    public function test_foto_que_nao_baixa_vira_aviso_e_o_produto_fica(): void
    {
        $this->fotosQueFalham = ['D_MLB111_2'];
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame('novo', $item['situacao']);
        $this->assertSame(['trazidas' => 2, 'falharam' => 1], $item['fotos']);
        $this->assertContains('1 foto do anúncio não pôde ser baixada.', $item['avisos']);
        $this->assertContains('fotos (2 de 3)', $item['faltas']);
        // A de tamanho cheio e, na falha, a reserva — as duas tentadas.
        $tentadas = Http::recorded(fn (Request $q) => str_contains($q->url(), 'D_MLB111_2'))->map(fn ($par) => $par[0]->url())->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['https://http2.mlstatic.com/D_MLB111_2-F.jpg', 'https://http2.mlstatic.com/D_MLB111_2-O.jpg'], $tentadas);
    }

    public function test_anuncio_de_outra_conta_e_erro(): void
    {
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111', ['seller_id' => 123]));
        $antes = $this->contagens();

        $item = $this->importar(['MLB111'])->assertOk()->json('itens.0');

        $this->assertSame(['erro', ImportacaoDoMercadoLivre::MSG_OUTRA_CONTA], [$item['situacao'], $item['mensagem']]);
        $this->assertSame($antes, $this->contagens());
    }

    public function test_pedido_invalido_e_422_em_mlbs_e_nada_vai_para_a_fila(): void
    {
        Queue::fake();
        $outra = $this->empresaDaTrilha();
        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        MlAcervoItem::create(['company_id' => $outra->id, 'ml_item_id' => 'MLB999', 'title' => 'De outra loja', 'status' => 'active', 'coletado_em' => now()]);
        $url = "/trilha/{$this->empresa->id}/mercado-livre/importar";
        $pedir = fn (array $corpo) => $this->actingAs($this->dev)->postJson($url, $corpo)->assertStatus(422)->json('errors.mlbs.0');

        $this->assertSame(ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO, $pedir(['mlbs' => ['MLB999']]), 'o anúncio de outra loja');
        $this->assertSame(ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO, $pedir(['mlbs' => ['MLB111', 'MLB999']]), 'um fora derruba o pedido');
        $this->assertSame(ImportacaoDoMercadoLivre::MSG_NENHUM, $pedir(['mlbs' => []]));
        $this->assertSame(ImportacaoDoMercadoLivre::MSG_NENHUM, $pedir([]));
        $this->assertSame('Escolha até 20 anúncios por vez.', $pedir(['mlbs' => array_map(fn ($i) => "MLB{$i}", range(1, 21))]));
        $this->assertSame(ImportacaoDoMercadoLivre::MSG_FORA_DO_ACERVO, $pedir(['mlbs' => [['x']]]));
        MlToken::query()->where('company_id', $this->empresa->id)->delete();
        $this->assertSame(ImportacaoDoMercadoLivre::MSG_DESCONECTADA, $pedir(['mlbs' => ['MLB111']]));
        $this->assertStringNotContainsString('http', ImportacaoDoMercadoLivre::MSG_DESCONECTADA, 'sem link');

        Queue::assertNothingPushed();
        $this->assertSame([], $this->estado()->json('itens'));
    }

    // ═══ A lista ════════════════════════════════════════════════════════════

    public function test_a_lista_segue_o_contrato_e_marca_o_que_ja_esta_na_lista(): void
    {
        $coleta = now()->subHour();
        $importado = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'IMP-1', 'nome' => 'Já importado']);
        EstruturaProdutoOrigemMl::create(['company_id' => $this->empresa->id, 'produto_id' => $importado->id, 'ml_item_id' => 'MLB1', 'importado_em' => now()]);
        $comRef = $this->produtoCompleto($this->empresa, 'Cadeira da Loja', 'REF-LOJA');
        $ligado = $this->produtoCompleto($this->empresa, 'Mesa Ligada', 'MESA-1');
        EstruturaAnuncio::create(['oferta_id' => $ligado['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
            'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB4', 'titulo' => 'Mesa']);
        MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB1', 'title' => 'Um', 'status' => 'active', 'nota_ecf' => 10, 'coletado_em' => $coleta]);
        MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB2', 'title' => 'Dois', 'status' => 'active', 'nota_ecf' => 20, 'skus' => [' ref-loja'], 'coletado_em' => $coleta]);
        MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB3', 'title' => 'Três', 'status' => 'paused', 'nota_ecf' => 30,
            'listing_type_id' => 'gold_special', 'price' => 99.9, 'thumbnail' => 'http://http2.mlstatic.com/t.jpg', 'performance_level' => 'medium',
            'sold_quantity' => 4, 'available_quantity' => 2, 'visitas_30d' => 40, 'fotos_count' => 5, 'coletado_em' => $coleta]);
        MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB4', 'title' => 'Quatro', 'status' => 'active', 'nota_ecf' => 40, 'coletado_em' => $coleta]);

        $todos = $this->lista(['mostrar_na_lista' => 1])->assertOk();

        $this->assertSame(['conectado', 'atualizado_em', 'total', 'pagina', 'paginas', 'limite', 'itens'], array_keys($todos->json()));
        $this->assertSame([true, 4, 1, 1, 20], [$todos->json('conectado'), $todos->json('total'), $todos->json('pagina'), $todos->json('paginas'), $todos->json('limite')]);
        $this->assertSame($coleta->copy()->setTimezone('America/Sao_Paulo')->toIso8601String(), $todos->json('atualizado_em'));
        $this->assertSame(['MLB1', 'MLB2', 'MLB3', 'MLB4'], array_column($todos->json('itens'), 'mlb'));
        $tres = $todos->json('itens.2');
        $this->assertSame(['mlb', 'titulo', 'thumbnail', 'permalink', 'preco', 'tipo', 'ativo', 'status', 'catalogo', 'estoque', 'vendas_total',
            'visitas_30d', 'fotos', 'saude_nivel', 'feito_pela_ecf', 'na_lista', 'importacao'], array_keys($tres), 'campo novo do acervo não chega sem decisão');
        $this->assertSame(['Três', 'https://http2.mlstatic.com/t.jpg', 99.9, 'Clássico', false, 'Pausado', 2, 4, 40, 5, 'Média', null, null],
            [$tres['titulo'], $tres['thumbnail'], $tres['preco'], $tres['tipo'], $tres['ativo'], $tres['status'], $tres['estoque'], $tres['vendas_total'],
                $tres['visitas_30d'], $tres['fotos'], $tres['saude_nivel'], $tres['na_lista'], $tres['importacao']]);
        $this->assertSame(['produto_id' => $importado->id, 'nome' => 'Já importado', 'como' => 'importado'], $todos->json('itens.0.na_lista'));
        $this->assertSame(['produto_id' => $comRef['produto']->id, 'nome' => 'Cadeira da Loja', 'como' => 'sku'], $todos->json('itens.1.na_lista'));
        $this->assertSame(['produto_id' => $ligado['produto']->id, 'nome' => 'Mesa Ligada', 'como' => 'ligado'], $todos->json('itens.3.na_lista'));
        $this->assertSemPortal($todos);

        // Padrão: importado e ligado escondidos; o do SKU aparece marcado (o acervo não sabe filtrá-lo antes de paginar).
        $this->assertSame(['MLB2', 'MLB3'], array_column($this->lista([])->json('itens'), 'mlb'));
        $this->assertSame(['MLB3'], array_column($this->lista(['situacao' => 'inativos'])->json('itens'), 'mlb'));
        $this->assertSame(['MLB3'], array_column($this->lista(['q' => 'Três'])->json('itens'), 'mlb'));
        $this->lista(['ordem' => 'piores'])->assertStatus(422);

        // A importação em andamento aparece na linha, com as situações do estado.
        Queue::fake();
        $this->importar(['MLB3'])->assertOk();
        $this->assertSame(['situacao' => 'fila'], $this->lista([])->json('itens.1.importacao'));
        $this->importar(['MLB2'])->assertOk();
        $this->assertSame(['situacao' => 'recusado'], $this->lista([])->json('itens.0.importacao'));
    }

    public function test_a_lista_pagina_de_30_em_30_e_a_conta_desconectada_responde_200_sem_link(): void
    {
        foreach (range(1, 31) as $i) {
            MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB'.(1000 + $i), 'title' => "Anúncio {$i}",
                'status' => 'active', 'nota_ecf' => $i, 'coletado_em' => now()]);
        }

        $um = $this->lista([])->assertOk();
        $this->assertSame([31, 2, 1, 30, 20], [$um->json('total'), $um->json('paginas'), $um->json('pagina'), count($um->json('itens')), $um->json('limite')]);
        $this->assertSame(['MLB1031'], array_column($this->lista(['pagina' => 2])->json('itens'), 'mlb'));
        $this->lista(['pagina' => 0])->assertStatus(422);
        $this->lista(['situacao' => 'pausados'])->assertStatus(422);

        MlToken::query()->where('company_id', $this->empresa->id)->delete();
        $desconectada = $this->lista([])->assertOk();
        $this->assertFalse($desconectada->json('conectado'));
        $this->assertStringNotContainsString('http', (string) json_encode(array_diff_key($desconectada->json(), ['itens' => true])), 'sem link');
    }

    public function test_o_estado_sem_lote_vem_vazio_e_o_lote_terminado_fica_para_quem_abre_a_gaveta(): void
    {
        $this->assertSame(['em_andamento' => false, 'totais' => ['novos' => 0, 'na_lista' => 0, 'recusados' => 0, 'erros' => 0], 'itens' => []],
            $this->estado()->json());

        $this->anuncioNoAcervo('MLB111', $this->anuncio('MLB111'));
        $this->importar(['MLB111'])->assertOk();

        $reaberto = $this->estado()->json();
        $this->assertFalse($reaberto['em_andamento']);
        $this->assertSame(['novo'], array_column($reaberto['itens'], 'situacao'));
        $this->assertIsList($reaberto['itens'][0]['avisos']);
    }

    // ═══ D30 e o custo ══════════════════════════════════════════════════════

    /** O detector da D30 não é vazio: uma chamada com a transação aberta É anotada (senão as provas acima não provam). */
    public function test_o_detector_de_http_com_a_transacao_aberta_funciona(): void
    {
        Http::get('https://api.mercadolibre.com/items/MLB0');
        $this->assertSame([], $this->httpNaTransacao, 'fora da transação não anota');

        DB::transaction(fn () => Http::get('https://api.mercadolibre.com/items/MLB0'));

        $this->assertSame(['GET https://api.mercadolibre.com/items/MLB0'], $this->httpNaTransacao);
        $this->httpNaTransacao = [];
    }

    public function test_o_custo_em_consultas_e_o_mesmo_a_cada_anuncio(): void
    {
        foreach (['MLB100', 'MLB101', 'MLB102'] as $mlb) {
            $this->anuncioNoAcervo($mlb, $this->anuncio($mlb));
        }
        // O 1º aquece o que é da categoria (o schema, os termos, a ficha) — custo de uma vez só.
        $this->importar(['MLB100'])->assertOk();

        $consultas = [];
        foreach (['MLB101', 'MLB102'] as $mlb) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSame('novo', $this->importar([$mlb])->assertOk()->json('itens.0.situacao'));
            $consultas[$mlb] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $this->assertSame($consultas['MLB101'], $consultas['MLB102'], 'consultas por anúncio: '.json_encode($consultas));
        $this->assertSame(3, EstruturaProduto::query()->count());
    }

    // ─── apoio ───

    private function importar(array $mlbs): TestResponse
    {
        $r = $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/mercado-livre/importar", ['mlbs' => $mlbs]);
        $this->assertSame([], $this->httpNaTransacao, 'D30: nenhuma chamada HTTP com a transação aberta');

        return $r;
    }

    private function estado(): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/mercado-livre/estado")->assertOk();
    }

    private function lista(array $query): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/mercado-livre/anuncios?".http_build_query($query));
    }

    private function assertSemPortal(TestResponse $r): void
    {
        $this->assertDoesNotMatchRegularExpression('/portal/i', (string) $r->getContent(), 'a trilha não cita nem leva ao Portal');
    }

    private function anuncioNoAcervo(string $mlb, array $item, array $extra = []): void
    {
        $this->itens[$mlb] = $item;
        MlAcervoItem::create(array_merge([
            'company_id' => $this->empresa->id, 'ml_item_id' => $mlb, 'title' => $item['title'], 'category_id' => $item['category_id'],
            'status' => 'active', 'listing_type_id' => 'gold_special', 'price' => 399.9, 'available_quantity' => 7,
            'sold_quantity' => 31, 'catalog_listing' => false, 'nota_ecf' => 40, 'coletado_em' => now(),
        ], $extra));
    }

    private function anuncio(string $mlb, array $mais = []): array
    {
        return array_replace([
            'id' => $mlb, 'seller_id' => (int) self::VENDEDOR, 'title' => self::ORIGINAL, 'category_id' => 'MLB193945',
            'status' => 'active', 'listing_type_id' => 'gold_special', 'price' => 399.9, 'available_quantity' => 7, 'sold_quantity' => 31,
            'attributes' => $this->atributos("CAD-{$mlb}"),
            'pictures' => $this->fotos($mlb, 3),
            'shipping' => ['mode' => 'me2', 'logistic_type' => 'xd_drop_off', 'free_shipping' => true],
            'sale_terms' => [],
        ], $mais);
    }

    private function atributos(?string $sku): array
    {
        return array_values(array_filter([
            ['id' => 'BRAND', 'name' => 'Marca', 'value_name' => 'Ergo'],
            ['id' => 'MODEL', 'name' => 'Modelo', 'value_name' => 'ER-200'],
            $sku !== null ? ['id' => 'SELLER_SKU', 'name' => 'SKU', 'value_name' => $sku] : null,
            ['id' => 'SEAT_DEPTH', 'name' => 'Profundidade do assento', 'value_name' => '48 cm', 'value_struct' => ['number' => 48, 'unit' => 'cm']],
            ['id' => 'WITH_ARMRESTS', 'name' => 'Com braços', 'value_id' => '242085', 'value_name' => 'Sim'],
            ['id' => 'SELLER_PACKAGE_HEIGHT', 'value_struct' => ['number' => 12, 'unit' => 'cm'], 'value_name' => '12 cm'],
            ['id' => 'SELLER_PACKAGE_WIDTH', 'value_struct' => ['number' => 45, 'unit' => 'cm'], 'value_name' => '45 cm'],
            ['id' => 'SELLER_PACKAGE_LENGTH', 'value_struct' => ['number' => 60, 'unit' => 'cm'], 'value_name' => '60 cm'],
            ['id' => 'SELLER_PACKAGE_WEIGHT', 'value_struct' => ['number' => 4500, 'unit' => 'g'], 'value_name' => '4500 g'],
        ]));
    }

    /** @return list<array{id: string, secure_url: string}> */
    private function fotos(string $mlb, int $quantas): array
    {
        return array_map(fn (int $n) => ['id' => "P{$n}", 'secure_url' => "https://http2.mlstatic.com/D_{$mlb}_{$n}-O.jpg"], range(1, $quantas));
    }

    /** O ML de mentira (a API e a CDN das fotos), só leitura — e o detector da D30. */
    private function ml(Request $r): \GuzzleHttp\Promise\PromiseInterface
    {
        if (DB::transactionLevel() > $this->nivelDoTeste) {
            $this->httpNaTransacao[] = $r->method().' '.$r->url();
        }
        $url = $r->url();
        $caminho = (string) parse_url($url, PHP_URL_PATH);

        if (str_contains($url, 'mlstatic.com')) {
            foreach ($this->fotosQueFalham as $f) {
                if (str_contains($url, $f)) {
                    return Http::response('', 404);
                }
            }

            return Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']);
        }
        if (preg_match('#/trends/MLB/MLB\d+$#', $caminho)) {
            return $this->termosFora ? Http::response(['message' => 'fora'], 500)
                : Http::response([['keyword' => 'cadeira home office'], ['keyword' => 'cadeira escritorio giratoria'], ['keyword' => 'mesa gamer']]);
        }
        if (preg_match('#/categories/MLB\d+/(technical_specs/input|sale_terms)$#', $caminho)) {
            return Http::response([], 200);
        }
        if (preg_match('#/categories/(MLB\d+)/attributes$#', $caminho, $m)) {
            return Http::response(match ($m[1]) {
                'MLB193945' => [
                    ['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
                    ['id' => 'MATERIAL', 'name' => 'Material', 'value_type' => 'list', 'tags' => ['required' => true],
                        'values' => [['id' => '10', 'name' => 'Couro sintético'], ['id' => '11', 'name' => 'Tecido']]],
                    ['id' => 'SEAT_DEPTH', 'name' => 'Profundidade do assento', 'value_type' => 'number_unit',
                        'allowed_units' => [['id' => 'cm', 'name' => 'cm']], 'default_unit' => 'cm', 'tags' => []],
                    ['id' => 'WITH_ARMRESTS', 'name' => 'Com braços', 'value_type' => 'boolean', 'tags' => [],
                        'values' => [['id' => '242085', 'name' => 'Sim'], ['id' => '242084', 'name' => 'Não']]],
                    ['id' => 'COLOR', 'name' => 'Cor', 'value_type' => 'list', 'tags' => ['allow_variations' => true],
                        'values' => [['id' => '51', 'name' => 'Preto'], ['id' => '52', 'name' => 'Branco']]],
                ],
                'MLB5'  => [['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]]],
                'MLB7'  => [['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]],
                    ['id' => 'SIZE_GRID_ID', 'name' => 'Tabela de medidas', 'value_type' => 'grid_id', 'tags' => []]],
                default => [],
            }, 200);
        }
        if (preg_match('#/categories/(MLB\d+)$#', $caminho, $m)) {
            $folha = fn (string $id, string $nome, array $extra = []) => Http::response(['id' => $id, 'name' => $nome, 'children_categories' => [],
                'path_from_root' => [['id' => 'MLB1574', 'name' => 'Casa'], ['id' => $id, 'name' => $nome]], 'settings' => ['max_title_length' => 60]] + $extra);

            return match ($m[1]) {
                'MLB193945' => $folha('MLB193945', 'Cadeiras de Escritório'),
                'MLB5'      => Http::response(['id' => 'MLB5', 'name' => 'Fantasias', 'children_categories' => [],
                    'path_from_root' => [['id' => 'MLB5', 'name' => 'Fantasias']], 'settings' => ['listing_allowed' => false]]),
                'MLB7'      => $folha('MLB7', 'Camisetas'),
                default     => Http::response(['message' => 'fora'], 500),
            };
        }
        if (preg_match('#/items/(MLB\d+)/description$#', $caminho)) {
            return Http::response(['plain_text' => 'Cadeira giratória com encosto em tela.'], 200);
        }
        if (preg_match('#/items/(MLB\d+)$#', $caminho, $m) && isset($this->itens[$m[1]])) {
            return Http::response($this->itens[$m[1]], 200);
        }

        return Http::response([], 404);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagejpeg($img);

        return (string) ob_get_clean();
    }

    /** As tabelas que uma importação toca (e o histórico): iguais antes e depois = nada gravado. */
    private function contagens(): array
    {
        $saida = [];
        foreach (array_column(Schema::getTables(), 'name') as $tabela) {
            if (str_starts_with($tabela, 'estrutura_') || in_array($tabela, ['activity_log', 'pub_produtos'], true)) {
                $saida[$tabela] = DB::table($tabela)->count();
            }
        }
        ksort($saida);

        return $saida;
    }

    private function retratoDoBanco(EstruturaProduto $produto): array
    {
        $p = EstruturaProduto::query()->with('variacoes')->findOrFail($produto->id);

        return [$p->nome, $p->titulo, $p->descricao, $p->variacoes->map(fn ($v) => [$v->codigo, $v->custo, $v->estoque])->all()];
    }

    private function eventosDaTrilha(): \Illuminate\Support\Collection
    {
        return Activity::query()->get()->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
    }
}
