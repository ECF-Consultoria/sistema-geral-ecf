<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PortalParaRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Schema\AtributoClassificado;
use App\Support\Publicador\Schema\ClassificadorAtributos;
use App\Support\Publicador\Schema\ContextoClassificacao;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Fase 172-08 — o produto agrupado do Portal chega ao rascunho do Publicador: categoria, ficha,
 * pacote, uma variante por cor com SKU e estoque. Só preenche o vazio, é idempotente e nunca escreve no ML.
 * Os schemas vêm das respostas reais guardadas (zero HTTP).
 */
class PortalParaRascunhoTest extends TestCase
{
    use CarregaSchemas;
    use RefreshDatabase;

    private Company $empresa;

    private RascunhoRepository $repo;

    private EditorRascunhoService $editor;

    private PortalParaRascunhoService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        // Qualquer chamada de rede vira exceção: o serviço só pode ler schema JÁ guardado.
        Http::preventStrayRequests();
        Http::fake();

        foreach ([self::CADEIRA, self::PASTILHA] as $categoria) {
            $schema = self::schema($categoria);
            MlCategoriaSchema::create(['category_id' => $categoria, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
                'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
                'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
        }

        $this->empresa = Company::factory()->create();
        $this->repo = new RascunhoRepository();
        $this->editor = app(EditorRascunhoService::class);
        $this->servico = app(PortalParaRascunhoService::class);
    }

    // ═══ Ajudantes ═══════════════════════════════════════════════════════════

    /**
     * @param  list<array{0: string, 1: ?int}>  $cores  [nome da cor, estoque]
     * @return array{0: EstruturaProduto, 1: PubProduto, 2: list<EstruturaProdutoVariacao>}
     */
    private function produto(array $cores = [['Azul', 10], ['Preto', 0], ['Branco', null]], string $categoria = self::CADEIRA, string $eixo = 'cor'): array
    {
        $p = EstruturaProduto::create(['company_id' => $this->empresa->id, 'codigo' => 'MESA', 'nome' => 'Mesa',
            'categoria_ml_id' => $categoria, 'categoria_ml_nome' => 'Categoria']);
        $vars = [];
        foreach ($cores as $i => [$nome, $estoque]) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => $i,
                'codigo' => 'MESA-'.($i + 1), 'eixo' => $eixo, 'valor' => $nome, 'custo' => 10, 'estoque' => $estoque]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 50, 'largura' => 40, 'altura' => 30, 'peso' => 2.5]);
            EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'MESA-'.($i + 1), 'fase' => 'simples', 'nome' => "Mesa {$nome}"]);
            $vars[] = $v;
        }
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'estrutura_produto_id' => $p->id, 'sku' => 'MESA', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        return [$p, $pub, $vars];
    }

    private function ficha(EstruturaProduto $p, string $id, string $valor, ?string $valorId = null): void
    {
        EstruturaProdutoAtributo::create(['company_id' => $this->empresa->id, 'produto_id' => $p->id, 'atributo_id' => $id,
            'atributo_nome' => $id, 'valor' => $valor, 'valor_id' => $valorId]);
    }

    private function snap(PubProduto $pub): RascunhoSnapshot
    {
        return $this->repo->snapshot(PubRascunho::where('produto_id', $pub->id)->firstOrFail());
    }

    private function rascunho(PubProduto $pub): PubRascunho
    {
        return PubRascunho::where('produto_id', $pub->id)->firstOrFail();
    }

    /** @return array<string, array> nome da cor → dados da variante */
    private function porCor(RascunhoSnapshot $s): array
    {
        $saida = [];
        foreach ($s->variantes as $v) {
            $saida[collect($v->valores)->first()?->valueName ?? ChaveCanonica::UNICA] = $v->dados;
        }

        return $saida;
    }

    private function contagens(): array
    {
        return [
            'variantes' => DB::table('pub_variantes')->count(),
            'eixo_valores' => DB::table('pub_eixo_valores')->count(),
            'atributos' => DB::table('pub_rascunho_atributos')->count(),
        ];
    }

    // ═══ Testes ══════════════════════════════════════════════════════════════

    public function test_produto_agrupado_chega_com_categoria_ficha_pacote_e_uma_variante_por_cor(): void
    {
        [$p, $pub] = $this->produto();
        $this->ficha($p, 'BRAND', 'ECF');
        $this->ficha($p, 'MODEL', 'Executiva');

        $resumo = $this->servico->preencher($pub);

        $r = $this->rascunho($pub);
        $s = $this->snap($pub);
        $this->assertSame(self::CADEIRA, $r->categoria_id);
        $this->assertNotNull($r->schema_hash);
        $this->assertSame('ECF', $s->atributos['BRAND']['value_name']);
        $this->assertSame('portal', $s->atributos['BRAND']['origem']);
        $this->assertSame('Executiva', $s->atributos['MODEL']['value_name']);
        $this->assertSame('50 cm', $s->atributos['SELLER_PACKAGE_LENGTH']['value_name']);
        $this->assertSame('40 cm', $s->atributos['SELLER_PACKAGE_WIDTH']['value_name']);
        $this->assertSame('30 cm', $s->atributos['SELLER_PACKAGE_HEIGHT']['value_name']);
        $this->assertSame('2500 g', $s->atributos['SELLER_PACKAGE_WEIGHT']['value_name']);

        $this->assertCount(1, $s->eixos);
        $this->assertCount(3, $s->eixos[0]->valores);
        $cores = $this->porCor($s);
        $this->assertSame(['Azul', 'Branco', 'Preto'], collect(array_keys($cores))->sort()->values()->all());
        $this->assertSame('MESA-1', $cores['Azul']['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame('MESA-2', $cores['Preto']['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame('MESA-3', $cores['Branco']['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame(10, $cores['Azul']['estoque']);
        $this->assertSame(0, $cores['Preto']['estoque']);
        $this->assertArrayNotHasKey('estoque', $cores['Branco'], 'estoque desconhecido no Portal fica nulo');

        $this->assertSame($r->id, $resumo['rascunho_id']);
        $this->assertFalse($resumo['intocavel']);
        $this->assertSame(3, $resumo['variantes']);
        $this->assertArrayNotHasKey('MAIN_COLOR', $s->atributos);
        Http::assertNothingSent();
    }

    public function test_so_preenche_o_vazio_e_conta_o_que_manteve(): void
    {
        [$p, $pub] = $this->produto([['Azul', 10], ['Preto', 7]]);
        $this->ficha($p, 'BRAND', 'ECF');
        $this->ficha($p, 'MODEL', 'Executiva');

        // A equipe já mexeu: categoria, uma marca, o comprimento do pacote e o estoque da variante única.
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $this->editor->trocarCategoria($r, self::CADEIRA);
        $this->repo->mesclarAtributos($r, ['BRAND' => ['value_name' => 'Marca da equipe', 'origem' => 'user'], 'SELLER_PACKAGE_LENGTH' => ['value_name' => '99 cm', 'origem' => 'user']]);
        $this->editor->salvarVariantes($r, [ChaveCanonica::UNICA => ['estoque' => 99]]);

        $resumo = $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame('Marca da equipe', $s->atributos['BRAND']['value_name']);
        $this->assertSame('user', $s->atributos['BRAND']['origem']);
        $this->assertSame('99 cm', $s->atributos['SELLER_PACKAGE_LENGTH']['value_name']);
        $this->assertSame('Executiva', $s->atributos['MODEL']['value_name'], 'o vazio é preenchido');
        $this->assertGreaterThanOrEqual(2, $resumo['campos_mantidos']);

        $estoques = array_column($this->porCor($s), 'estoque');
        $this->assertContains(99, $estoques, 'o estoque digitado pela equipe fica na âncora');
        $this->assertCount(2, $s->variantes);
    }

    public function test_categoria_diferente_no_rascunho_fica_e_avisa(): void
    {
        [$p, $pub] = $this->produto();
        $this->ficha($p, 'BRAND', 'ECF');
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $this->editor->trocarCategoria($r, self::PASTILHA);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(self::PASTILHA, $this->rascunho($pub)->categoria_id);
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, 'categoria')));
    }

    public function test_categoria_a_confirmar_nao_e_preenchida(): void
    {
        [$p, $pub] = $this->produto();
        $p->update(['categoria_ml_id' => null]);

        $resumo = $this->servico->preencher($pub);

        $this->assertNull($this->rascunho($pub)->categoria_id);
        $this->assertNotEmpty($resumo['avisos']);
    }

    public function test_atributo_de_varias_opcoes_grava_values_multi_e_pede_revisao(): void
    {
        [$p, $pub] = $this->produto();
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $this->editor->trocarCategoria($r, self::CADEIRA);

        $classificado = (new ClassificadorAtributos())->classificar(self::schema(self::CADEIRA), new ContextoClassificacao('new', []));
        $multi = collect($classificado->atributos)->first(fn ($a) => $a->multivalor && count($a->valores) >= 2
            && $a->papel === AtributoClassificado::PRODUCT && $a->secao !== AtributoClassificado::SECAO_EMBALAGEM);
        $this->assertNotNull($multi, 'a cadeira guardada tem ao menos um atributo de várias opções com lista');
        $this->ficha($p, $multi->id, $multi->valores[0]['name'].' | '.$multi->valores[1]['name']);

        $this->servico->preencher($pub);

        $linha = $this->rascunho($pub)->atributos()->where('attribute_id', $multi->id)->first();
        $this->assertNotNull($linha);
        $this->assertCount(2, $linha->values_multi);
        $this->assertTrue($linha->revisar);

        // WR-03: o snapshot leva as opções à tela, e uma gravação da tela sem a chave não as apaga.
        $this->assertSame($linha->values_multi, $this->snap($pub)->atributos[$multi->id]['values_multi']);
        $daTela = array_map(fn ($a) => array_diff_key($a, ['values_multi' => true]), $this->snap($pub)->atributos);
        $this->editor->salvar($this->rascunho($pub), ['atributos' => $daTela]);
        $this->assertCount(2, $linha->fresh()->values_multi, 'salvar no editor mantém as opções guardadas');

        // Trocou a 1ª opção: a lista velha não vale mais.
        $daTela[$multi->id] = ['value_id' => (string) $multi->valores[1]['id'], 'origem' => 'user'];
        $this->editor->salvar($this->rascunho($pub), ['atributos' => $daTela]);
        $this->assertNull($linha->fresh()->values_multi);
    }

    public function test_segunda_execucao_nao_muda_nada(): void
    {
        [$p, $pub] = $this->produto();
        $this->ficha($p, 'BRAND', 'ECF');

        $this->servico->preencher($pub);
        $antes = $this->contagens();
        $revisao = $this->rascunho($pub)->revisao;
        $estado = $this->porCor($this->snap($pub));

        $this->servico->preencher($pub);

        $this->assertSame($antes, $this->contagens());
        $this->assertSame($revisao, $this->rascunho($pub)->revisao);
        $this->assertSame($estado, $this->porCor($this->snap($pub)));
    }

    public function test_cor_nova_no_portal_entra_sem_tirar_nenhuma(): void
    {
        [$p, $pub] = $this->produto([['Azul', 10], ['Preto', 3]]);
        $this->servico->preencher($pub);
        $antes = $this->porCor($this->snap($pub));

        EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $this->empresa->id, 'ordem' => 5,
            'codigo' => 'MESA-9', 'eixo' => 'cor', 'valor' => 'Verde', 'custo' => 10, 'estoque' => 4]);
        $this->servico->preencher($pub);

        $depois = $this->porCor($this->snap($pub));
        $this->assertCount(3, $depois);
        $this->assertSame($antes['Azul'], $depois['Azul']);
        $this->assertSame($antes['Preto'], $depois['Preto']);
        $this->assertSame('MESA-9', $depois['Verde']['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame(4, $depois['Verde']['estoque']);
    }

    public function test_tres_cores_viram_tres_skus_distintos_mesmo_com_sku_na_variante_unica(): void
    {
        [, $pub] = $this->produto();
        // Rascunho "adotado": a variante única já tem o SKU do grupo.
        $r = $this->editor->rascunhoDoProduto($pub, true);
        $this->assertSame('MESA', $this->snap($pub)->variantes[0]->dados['atributos']['SELLER_SKU']['value_name']);

        $this->servico->preencher($pub);

        $skus = collect($this->snap($pub)->variantes)->map(fn ($v) => $v->dados['atributos']['SELLER_SKU']['value_name'] ?? null);
        $this->assertCount(3, $skus->unique());
        $this->assertEqualsCanonicalizing(['MESA-1', 'MESA-2', 'MESA-3'], $skus->all());
        $this->assertSame($r->id, $this->rascunho($pub)->id);
    }

    public function test_sku_digitado_pela_equipe_numa_variante_fica(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['Preto', 2]]);
        $this->servico->preencher($pub);
        $r = $this->rascunho($pub);
        $azul = collect($this->snap($pub)->variantes)->first(fn ($v) => collect($v->valores)->first()->valueName === 'Azul');
        $this->editor->salvarVariantes($r, [$azul->chave => ['atributos' => ['SELLER_SKU' => ['value_name' => 'DIGITADO']]]]);

        $this->servico->preencher($pub);

        $this->assertSame('DIGITADO', $this->porCor($this->snap($pub))['Azul']['atributos']['SELLER_SKU']['value_name']);
    }

    public function test_valor_do_eixo_trocado_para_id_da_lista_nao_duplica(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['Preto', 2]]);
        $this->servico->preencher($pub);
        $r = $this->rascunho($pub);
        $s = $this->snap($pub);
        $eixo = $s->eixos[0];

        // A equipe trocou o texto livre "Azul" pela opção da lista (mesmo nome, agora com id).
        $this->editor->salvarEixos($r, [[
            'chave' => $eixo->chave, 'nome' => $eixo->nome, 'defines_picture' => $eixo->definesPicture,
            'valores' => [['id' => '52005', 'nome' => 'Azul'], ['id' => null, 'nome' => 'Preto']],
        ]]);
        $this->servico->preencher($pub);

        $this->assertCount(2, $this->snap($pub)->eixos[0]->valores);
        $this->assertCount(2, array_filter($this->snap($pub)->variantes, fn ($v) => ! $v->orfa));
    }

    public function test_categoria_sem_color_como_eixo_usa_eixo_customizado(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['Preto', 2]], self::PASTILHA);

        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame(ChaveCanonica::EIXO_CUSTOM, $s->eixos[0]->chave);
        $this->assertSame('Cor', $s->eixos[0]->nome);
        $this->assertCount(2, $this->porCor($s));
    }

    public function test_eixo_de_outro_atributo_criado_pela_equipe_nao_recebe_cores(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['Preto', 2]]);
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $this->editor->trocarCategoria($r, self::CADEIRA);
        $this->editor->salvarEixos($r, [['chave' => ChaveCanonica::EIXO_CUSTOM, 'nome' => 'Acabamento', 'defines_picture' => false,
            'valores' => [['id' => null, 'nome' => 'Fosco'], ['id' => null, 'nome' => 'Brilho']]]]);

        $resumo = $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertCount(1, $s->eixos);
        $this->assertSame('Acabamento', $s->eixos[0]->nome);
        $this->assertCount(2, $s->eixos[0]->valores);
        $this->assertNotEmpty($resumo['avisos']);
    }

    public function test_produto_de_uma_variacao_sem_valor_nao_cria_eixo_e_a_variante_unica_recebe_sku_e_estoque(): void
    {
        [, $pub] = $this->produto([['', 6]]);

        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame([], $s->eixos);
        $this->assertCount(1, $s->variantes);
        $this->assertSame('MESA-1', $s->variantes[0]->dados['atributos']['SELLER_SKU']['value_name']);
        $this->assertSame(6, $s->variantes[0]->dados['estoque']);
    }

    public function test_variacao_sem_valor_em_produto_de_varias_e_pulada_com_aviso(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['', 2], ['Preto', 3]]);

        $resumo = $this->servico->preencher($pub);

        $this->assertCount(2, $this->snap($pub)->eixos[0]->valores);
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, 'sem valor') && str_contains($a, 'produto separado')));
    }

    public function test_rascunho_publicado_nunca_e_tocado(): void
    {
        [$p, $pub] = $this->produto();
        $this->ficha($p, 'BRAND', 'ECF');
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $r->update(['status' => PubRascunho::PUBLISHED]);
        $revisao = $r->fresh()->revisao;

        $resumo = $this->servico->preencher($pub);

        $this->assertTrue($resumo['intocavel']);
        $this->assertNull($r->fresh()->categoria_id);
        $this->assertSame($revisao, $r->fresh()->revisao);
        $this->assertSame(0, DB::table('pub_rascunho_atributos')->count());
    }

    public function test_empresa_fora_do_piloto_tambem_e_preenchida_sem_escrever_no_ml(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        [$p, $pub] = $this->produto();
        $this->ficha($p, 'BRAND', 'ECF');

        $this->servico->preencher($pub);

        $this->assertSame(self::CADEIRA, $this->rascunho($pub)->categoria_id);
        $this->assertCount(3, $this->snap($pub)->variantes);
        Http::assertNothingSent();
    }

    public function test_produto_sem_grupo_devolve_resumo_vazio_sem_criar_rascunho(): void
    {
        $pub = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $resumo = $this->servico->preencher($pub);

        $this->assertNull($resumo['rascunho_id']);
        $this->assertSame(0, PubRascunho::count());
    }

    public function test_grupo_de_outra_empresa_nao_e_lido(): void
    {
        [$p, $pubDona] = $this->produto();
        $pubDona->delete();
        $outra = Company::factory()->create();
        $cruzado = PubProduto::create(['company_id' => $outra->id, 'estrutura_produto_id' => $p->id, 'sku' => 'Y', 'nome' => 'Y', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $resumo = $this->servico->preencher($cruzado);

        $this->assertNull($resumo['rascunho_id']);
        $this->assertSame(0, PubRascunho::count());
    }

    public function test_cor_ja_publicada_em_outro_produto_fica_fora_do_grupo_com_aviso_verdadeiro(): void
    {
        [, $pub, $vars] = $this->produto();
        // "Preto" já é um produto avulso publicado (pela oferta); "Branco" tem o SKU de outro produto publicado.
        $ofertaPreto = EstruturaOferta::where('variacao_id', $vars[1]->id)->firstOrFail();
        $avulso = PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $ofertaPreto->id, 'sku' => 'MESA-2', 'nome' => 'Mesa Preto', 'origem' => PubProduto::ORIGEM_PORTAL]);
        PubRascunho::create(['produto_id' => $avulso->id, 'status' => PubRascunho::PUBLISHED]);
        $mesmoSku = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'mesa-3', 'nome' => 'Cadastrado à mão', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        PubRascunho::create(['produto_id' => $mesmoSku->id, 'status' => PubRascunho::PUBLISHED]);

        $resumo = $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertSame([], $s->eixos, 'sobrou uma cor só: nenhuma variação com as cores publicadas');
        $skus = collect($s->variantes)->map(fn ($v) => $v->dados['atributos']['SELLER_SKU']['value_name'] ?? null)->filter()->values()->all();
        $this->assertSame(['MESA-1'], $skus, 'nenhum SKU já publicado entra no grupo');
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, '"Preto"') && str_contains($a, "#{$avulso->id}") && str_contains($a, 'segue separada')));
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, '"Branco"') && str_contains($a, "#{$mesmoSku->id}")));
    }

    public function test_cor_publicada_que_ja_estava_no_rascunho_nao_e_removida_e_o_aviso_pede_desativar(): void
    {
        [, $pub, $vars] = $this->produto();
        $this->servico->preencher($pub);
        $this->assertCount(3, $this->snap($pub)->eixos[0]->valores);
        $ofertaPreto = EstruturaOferta::where('variacao_id', $vars[1]->id)->firstOrFail();
        $avulso = PubProduto::create(['company_id' => $this->empresa->id, 'oferta_id' => $ofertaPreto->id, 'sku' => 'MESA-2', 'nome' => 'Mesa Preto', 'origem' => PubProduto::ORIGEM_PORTAL]);
        PubRascunho::create(['produto_id' => $avulso->id, 'status' => PubRascunho::PUBLISHED]);

        $resumo = $this->servico->preencher($pub);

        $this->assertCount(3, $this->snap($pub)->eixos[0]->valores, 'D-05: o Sincronizar nunca remove');
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, '"Preto"') && str_contains($a, 'desative-a')));
    }

    public function test_sem_categoria_nao_cria_eixo_customizado_e_a_proxima_execucao_varia_pela_cor_da_categoria(): void
    {
        [$p, $pub] = $this->produto([['Azul', 1], ['Preto', 2]]);
        $p->update(['categoria_ml_id' => null]);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame([], $this->snap($pub)->eixos, 'sem schema nenhum eixo nasce (nem customizado)');
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, 'próximo Sincronizar')));

        $p->update(['categoria_ml_id' => self::CADEIRA]);
        $this->servico->preencher($pub);

        $s = $this->snap($pub);
        $this->assertCount(1, $s->eixos);
        $this->assertNotSame(ChaveCanonica::EIXO_CUSTOM, $s->eixos[0]->chave, 'com a categoria, o eixo é o atributo de cor dela');
        $this->assertCount(2, $this->porCor($s));
    }

    public function test_rascunho_antigo_com_eixo_customizado_avisa_em_vez_de_seguir_em_silencio(): void
    {
        [, $pub] = $this->produto([['Azul', 1], ['Preto', 2]]);
        $r = $this->editor->rascunhoDoProduto($pub, false);
        $this->editor->trocarCategoria($r, self::CADEIRA);
        $this->editor->salvarEixos($r, [['chave' => ChaveCanonica::EIXO_CUSTOM, 'nome' => 'Cor', 'defines_picture' => false,
            'valores' => [['id' => null, 'nome' => 'Azul'], ['id' => null, 'nome' => 'Preto']]]]);

        $resumo = $this->servico->preencher($pub);

        $this->assertSame(ChaveCanonica::EIXO_CUSTOM, $this->snap($pub)->eixos[0]->chave, 'o Portal não troca o eixo da equipe');
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, 'eixo próprio')));
    }

    public function test_cor_removida_pela_equipe_nao_volta_no_proximo_sincronizar(): void
    {
        [, $pub] = $this->produto();
        $this->servico->preencher($pub);
        $r = $this->rascunho($pub);
        $eixo = $this->snap($pub)->eixos[0];
        $semBranco = array_values(array_filter($eixo->valores, fn ($v) => $v->valueName !== 'Branco'));
        $this->editor->salvarEixos($r, [['chave' => $eixo->chave, 'nome' => $eixo->nome, 'defines_picture' => $eixo->definesPicture,
            'valores' => array_map(fn ($v) => ['id' => $v->valueId, 'nome' => $v->valueName], $semBranco)]]);
        $this->assertCount(2, $this->snap($pub)->eixos[0]->valores);

        $resumo = $this->servico->preencher($pub);

        $nomes = array_map(fn ($v) => $v->valueName, $this->snap($pub)->eixos[0]->valores);
        $this->assertNotContains('Branco', $nomes, 'D-05: a edição da equipe não é desfeita');
        $this->assertCount(2, array_filter($this->snap($pub)->variantes, fn ($v) => ! $v->orfa && $v->ativa));
        $this->assertTrue(collect($resumo['avisos'])->contains(fn ($a) => str_contains($a, '"Branco"') && str_contains($a, 'removida')));

        // Mesmo depois de a equipe descartar a órfã, a cor continua fora (memória do Sincronizar).
        $r->variantes()->where('orfa', true)->get()->each(fn ($v) => $v->delete());
        $this->servico->preencher($pub);
        $this->assertNotContains('Branco', array_map(fn ($v) => $v->valueName, $this->snap($pub)->eixos[0]->valores));
    }
}
