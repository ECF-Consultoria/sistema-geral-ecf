<?php

namespace Tests\Unit\Publicador;

use App\Models\MlbEmpresa;
use App\Models\MlCategoriaSchema;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\PreviaDaFaseService;
use App\Support\Publicador\Variacao\ChaveCanonica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-05 (§4 da ETAPA-3) — a PRÉVIA do painel "Criar Fase 2".
 *
 * Duas baterias, de propósito:
 *
 * 1. **Os cálculos PUROS** (SKU, SELLER_SKU, título, descrição, estoque). É o
 *    que a §8 pede ("funções puras com teste unitário") e eles não encostam no
 *    banco: dividir estoque é aritmética, e aritmética se prova sem fixture.
 * 2. **O payload montado** (`previa()`), com fixture. ⚠️ Lição da tela preta
 *    (07/10, produção): campo que a tela exibe e chega como objeto derruba a
 *    página. Por isso aqui se prova o **formato** de cada campo que o painel
 *    (175-07) vai exibir, não só o valor — e se prova o resultado MONTADO,
 *    não só a peça.
 *
 * A prévia NÃO fala com o Mercado Livre: `Http::fake()` sem handler intercepta
 * tudo e `Http::assertNothingSent()` cobra o contrário (teste de serviço, logo
 * o estrito vale — numa requisição de página o `HandleInertiaRequests` buscaria
 * os sinais do ECF Drive e o estrito não serviria).
 *
 * @group phase175
 */
class PreviaDaFaseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function servico(): PreviaDaFaseService
    {
        return app(PreviaDaFaseService::class);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Bateria 1 — os cálculos puros
    // ═══════════════════════════════════════════════════════════════════════

    public function test_sku_sugerido_e_o_do_base_com_o_sufixo_do_kit(): void
    {
        $this->assertSame('CAD-KIT2', PreviaDaFaseService::skuSugerido('CAD', 2));
        $this->assertSame('CAD-01-KIT12', PreviaDaFaseService::skuSugerido('CAD-01', 12));
        $this->assertSame('CAD-KIT3', PreviaDaFaseService::skuSugerido('  CAD  ', 3), 'espaço sobrando no SKU do base não entra no do kit');
    }

    /** O teto é 120 (a coluna) e o sufixo é a parte que IDENTIFICA o kit: corta o começo, nunca o `-KIT{N}`. */
    public function test_sku_longo_e_cortado_preservando_o_sufixo_do_kit(): void
    {
        $longo = str_repeat('A', 125);

        $sku = PreviaDaFaseService::skuSugerido($longo, 2);

        $this->assertSame(120, mb_strlen($sku));
        $this->assertStringEndsWith('-KIT2', $sku);
        $this->assertStringStartsWith('AAAA', $sku);
    }

    public function test_seller_sku_sugerido_vem_do_seller_sku_da_variante(): void
    {
        $this->assertSame('CAD-AZUL-KIT3', PreviaDaFaseService::sellerSkuSugerido('CAD-AZUL', 'CAD-KIT3', 3));
        $this->assertSame(
            'CAD-KIT3',
            PreviaDaFaseService::sellerSkuSugerido(null, 'CAD-KIT3', 3),
            'variante do base sem SELLER_SKU (produto simples recém-criado): o kit usa o SKU dele, nunca fica vazio',
        );
        $this->assertSame('CAD-KIT3', PreviaDaFaseService::sellerSkuSugerido('   ', 'CAD-KIT3', 3), 'SELLER_SKU em branco é o mesmo que não ter');
        $this->assertSame(120, mb_strlen(PreviaDaFaseService::sellerSkuSugerido(str_repeat('B', 130), 'CAD-KIT2', 2)));
        $this->assertStringEndsWith('-KIT2', PreviaDaFaseService::sellerSkuSugerido(str_repeat('B', 130), 'CAD-KIT2', 2));
    }

    public function test_titulo_sugerido_prefixa_kit_n_no_titulo_da_fase_1(): void
    {
        $r = PreviaDaFaseService::tituloSugerido('Cadeira Escritório', 2, 60);

        $this->assertSame('Kit 2 Cadeira Escritório', $r['titulo']);
        $this->assertFalse($r['cortado']);
        $this->assertIsString($r['titulo'], 'o painel exibe este campo: tem de ser string');
        $this->assertIsBool($r['cortado']);
    }

    /** O título do base já foi conferido por uma pessoa: sem estourar o limite, ele não é mexido. */
    public function test_titulo_dentro_do_limite_nao_e_higienizado(): void
    {
        $r = PreviaDaFaseService::tituloSugerido('Cadeira 1,5m - Preta', 2, 60);

        $this->assertSame('Kit 2 Cadeira 1,5m - Preta', $r['titulo']);
        $this->assertFalse($r['cortado']);
    }

    public function test_titulo_acima_do_maximo_e_cortado_por_palavra_inteira_com_aviso(): void
    {
        $base = 'Cadeira Gamer Reclinável Com Apoio Para Os Pés Preta Luxo';
        $this->assertSame(63, mb_strlen("Kit 2 {$base}"), 'a fixture precisa estourar o limite de 60');

        $r = PreviaDaFaseService::tituloSugerido($base, 2, 60);

        $this->assertTrue($r['cortado']);
        $this->assertSame('Kit 2 Cadeira Gamer Reclinável Com Apoio Para Os Pés Preta', $r['titulo']);
        $this->assertLessThanOrEqual(60, mb_strlen($r['titulo']));
        $this->assertStringEndsWith('Preta', $r['titulo'], 'nunca no meio de uma palavra: "Luxo" saiu inteiro');
    }

    public function test_descricao_sugerida_abre_com_a_frase_do_kit_e_mantem_a_do_base(): void
    {
        $d = PreviaDaFaseService::descricaoSugerida('Cadeira Escritório', 2, 'texto do base');

        $this->assertSame("Este kit contém 2 unidades de Cadeira Escritório.\n\ntexto do base", $d);
    }

    public function test_base_sem_descricao_gera_so_a_frase_sem_linhas_sobrando(): void
    {
        $this->assertSame('Este kit contém 3 unidades de Cadeira.', PreviaDaFaseService::descricaoSugerida('Cadeira', 3, null));
        $this->assertSame('Este kit contém 3 unidades de Cadeira.', PreviaDaFaseService::descricaoSugerida('Cadeira', 3, '   '));
    }

    public function test_estoque_do_kit_e_o_floor_da_divisao(): void
    {
        $r = PreviaDaFaseService::estoqueDoKit(['estoque' => 7], 2);

        $this->assertSame(3, $r['estoque']);
        $this->assertNull($r['depositos']);
        $this->assertSame([], $r['zerou']);
    }

    public function test_estoque_que_zera_e_zero_de_verdade_e_o_servico_avisa(): void
    {
        $r = PreviaDaFaseService::estoqueDoKit(['estoque' => 1], 2);

        $this->assertSame(0, $r['estoque'], 'zero é zero: o aviso é de quem monta a prévia');
        $this->assertNull($r['depositos']);
    }

    /**
     * ⚠️ Multidepósito: o estoque é a SOMA DOS DIVIDIDOS, não o floor da soma.
     * Com A=7 e B=2 em N=2, floor(9÷2) daria 4 por coincidência — a fixture
     * abaixo escolhe números em que as duas contas dão resultado DIFERENTE.
     */
    public function test_multideposito_divide_por_deposito_e_soma_depois(): void
    {
        $r = PreviaDaFaseService::estoqueDoKit(['estoque_depositos' => ['A' => 7, 'B' => 2]], 2);

        $this->assertSame(['A' => 3, 'B' => 1], $r['depositos']);
        $this->assertSame(4, $r['estoque'], 'soma dos divididos (3+1), NÃO floor(9÷2)=4 por sorte');

        $outro = PreviaDaFaseService::estoqueDoKit(['estoque_depositos' => ['A' => 5, 'B' => 5]], 3);
        $this->assertSame(['A' => 1, 'B' => 1], $outro['depositos']);
        $this->assertSame(2, $outro['estoque'], 'soma dos divididos = 2; floor(10÷3) daria 3 — contas diferentes');
    }

    public function test_deposito_que_zera_sai_nomeado_no_zerou(): void
    {
        $r = PreviaDaFaseService::estoqueDoKit(['estoque_depositos' => ['A' => 1, 'B' => 9]], 2);

        $this->assertSame(['A' => 0, 'B' => 4], $r['depositos']);
        $this->assertSame(4, $r['estoque']);
        $this->assertSame(['A'], $r['zerou'], 'o nome do depósito que zerou volta para o aviso');
    }

    public function test_estoque_nunca_digitado_fica_nulo_e_nunca_zero(): void
    {
        $r = PreviaDaFaseService::estoqueDoKit([], 2);

        $this->assertNull($r['estoque'], 'nulo é "não informado"; zero diria "sem unidades", que é outra coisa');
        $this->assertNull($r['depositos']);
        $this->assertSame([], $r['zerou']);

        $comNulo = PreviaDaFaseService::estoqueDoKit(['estoque' => null, 'estoque_depositos' => []], 2);
        $this->assertNull($comNulo['estoque']);
        $this->assertNull($comNulo['depositos'], 'mapa de depósitos vazio é o mesmo que não ter depósito');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Bateria 2 — o payload montado
    // ═══════════════════════════════════════════════════════════════════════

    private function empresa(): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => 'Polo da Prévia', 'projeto' => 'POLOS']);
    }

    private function base(MlbEmpresa $e, string $sku = 'CAD', string $nome = 'Cadeira Escritório'): PubProduto
    {
        return PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => $sku,
            'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);
    }

    private function schema(string $categoria = 'MLB193945', int $maximo = 60): void
    {
        MlCategoriaSchema::create([
            'category_id' => $categoria,
            'categoria' => [
                'id' => $categoria,
                'name' => 'Cadeiras de Escritório',
                'settings' => ['max_title_length' => $maximo],
                'path_from_root' => [['id' => $categoria, 'name' => 'Cadeiras de Escritório']],
            ],
            'atributos' => [], 'technical_specs' => [], 'sale_terms' => [],
            'schema_hash' => str_repeat('a', 64), 'fetched_at' => now(),
        ]);
    }

    /**
     * Um base pronto: rascunho publicado, dois tipos de anúncio (um desligado),
     * descrição e a variante única com SELLER_SKU e estoque.
     */
    private function rascunhoSimples(PubProduto $base, ?string $categoria = 'MLB193945'): PubRascunho
    {
        $r = PubRascunho::create([
            'produto_id' => $base->id,
            'status' => PubRascunho::PUBLISHED,
            'revisao' => 1,
            'categoria_id' => $categoria,
            'descricao' => 'Cadeira executiva com apoio lombar.',
        ]);
        $r->alvos()->create(['listing_type_id' => 'gold_special', 'titulo' => 'Cadeira Escritório Executiva', 'ativo' => true, 'posicao' => 0]);
        $r->alvos()->create(['listing_type_id' => 'gold_pro', 'titulo' => 'Cadeira Escritório Premium', 'ativo' => true, 'posicao' => 1]);
        $r->alvos()->create(['listing_type_id' => 'free', 'titulo' => 'Cadeira Grátis', 'ativo' => false, 'posicao' => 2]);

        $v = $r->variantes()->create([
            'combinacao_chave' => ChaveCanonica::UNICA,
            'combinacao_hash' => ChaveCanonica::hash(ChaveCanonica::UNICA),
            'ativa' => true,
            'estoque' => 7,
        ]);
        $v->atributos()->create(['attribute_id' => 'SELLER_SKU', 'value_name' => 'CAD-UN']);

        return $r;
    }

    public function test_previa_monta_sku_titulos_descricao_e_variantes(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $this->rascunhoSimples($base);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(2, $p['quantidade']);
        $this->assertSame('CAD-KIT2', $p['sku']);
        $this->assertSame(60, $p['max_title_length']);
        $this->assertSame(['gold_special', 'gold_pro'], $p['tipos'], 'os tipos são os MESMOS alvos ATIVOS da Fase 1');
        $this->assertSame([
            'gold_special' => 'Kit 2 Cadeira Escritório Executiva',
            'gold_pro' => 'Kit 2 Cadeira Escritório Premium',
        ], $p['titulo_por_tipo']);
        $this->assertSame("Este kit contém 2 unidades de Cadeira Escritório.\n\nCadeira executiva com apoio lombar.", $p['descricao']);
        $this->assertNull($p['erro_campo']);

        $this->assertArrayHasKey(ChaveCanonica::UNICA, $p['variantes']);
        $this->assertSame([
            'seller_sku' => 'CAD-UN-KIT2',
            'estoque' => 3,
            'depositos' => null,
            'ativa' => true,
        ], $p['variantes'][ChaveCanonica::UNICA]);

        Http::assertNothingSent();
    }

    /**
     * ⚠️ Formato, não só valor (lição da tela preta): todo campo que o painel
     * exibe é string/int/bool/null, e `avisos` é uma LISTA de
     * `{chave: string, mensagem: string}` — nunca um mapa nem um objeto aninhado.
     */
    public function test_formato_do_payload_e_seguro_para_a_tela(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $this->rascunhoSimples($base);

        $p = $this->servico()->previa($base, 2);

        $this->assertIsString($p['sku']);
        $this->assertIsString($p['descricao']);
        $this->assertIsInt($p['quantidade']);
        $this->assertIsInt($p['max_title_length']);
        $this->assertIsList($p['tipos']);
        $this->assertIsList($p['avisos']);
        $this->assertNull($p['erro_campo']);

        foreach ($p['titulo_por_tipo'] as $tipo => $titulo) {
            $this->assertIsString($tipo);
            $this->assertIsString($titulo);
        }
        foreach ($p['avisos'] as $aviso) {
            $this->assertSame(['chave', 'mensagem'], array_keys($aviso), 'o painel só lê estas duas chaves');
            $this->assertIsString($aviso['chave']);
            $this->assertIsString($aviso['mensagem']);
            $this->assertNotSame('', $aviso['mensagem']);
        }
        foreach ($p['variantes'] as $chave => $v) {
            $this->assertIsString($chave);
            $this->assertSame(['seller_sku', 'estoque', 'depositos', 'ativa'], array_keys($v));
            $this->assertIsString($v['seller_sku']);
            $this->assertTrue($v['estoque'] === null || is_int($v['estoque']));
            $this->assertTrue($v['depositos'] === null || is_array($v['depositos']));
            $this->assertIsBool($v['ativa']);
        }
    }

    /**
     * A decisão de 2026-10-08 (`175-DECISOES.md` item 2): o kit nasce com preço
     * vazio **e o painel avisa antes de confirmar**. O aviso é DADO do servidor
     * — a frase não pode viver só no front.
     */
    public function test_aviso_do_preco_vazio_vem_sempre_e_nao_bloqueia(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $this->rascunhoSimples($base);

        $p = $this->servico()->previa($base, 2);

        $chaves = array_column($p['avisos'], 'chave');
        $this->assertContains('preco_vazio', $chaves);
        $this->assertNull($p['erro_campo'], 'o aviso do preço NUNCA bloqueia o Confirmar');
        $this->assertArrayNotHasKey('preco', $p, 'a §4 manda campo vazio, sem sugestão: preço não entra no retorno');
        $this->assertArrayNotHasKey('preco_sugerido', $p);

        $mensagem = collect($p['avisos'])->firstWhere('chave', 'preco_vazio')['mensagem'];
        $this->assertStringContainsString('preço', $mensagem);
    }

    public function test_quantidade_repetida_na_familia_e_erro_de_campo(): void
    {
        $e = $this->empresa();
        $base = $this->base($e);
        $this->schema();
        $this->rascunhoSimples($base);
        PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'CAD-KIT2', 'nome' => 'Kit 2 Cadeira', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
            'produto_base_id' => $base->id, 'quantidade_kit' => 2, 'fase' => 2,
        ]);

        $p = $this->servico()->previa($base->fresh(), 2);

        $this->assertSame('Já existe Kit 2 deste produto.', $p['erro_campo']);
        $this->assertSame(
            null,
            $this->servico()->previa($base->fresh(), 3)['erro_campo'],
            'quantidade livre não é erro',
        );
    }

    public function test_sku_repetido_na_empresa_e_aviso_nao_bloqueio(): void
    {
        $e = $this->empresa();
        $base = $this->base($e);
        $this->schema();
        $this->rascunhoSimples($base);
        // Outro produto, fora da família, com o SKU que o kit vai querer.
        PubProduto::create([
            'mlb_empresa_id' => $e->id,
            'sku' => 'cad-kit2', 'nome' => 'Outro', 'origem' => PubProduto::ORIGEM_PUBLICADOR,
        ]);

        $p = $this->servico()->previa($base->fresh(), 2);

        $this->assertContains('sku_repetido', array_column($p['avisos'], 'chave'), 'comparação sem caixa, como no criarProduto');
        $this->assertNull($p['erro_campo'], 'SKU repetido é aviso, não bloqueio');
    }

    public function test_estoque_zero_avisa_e_nao_bloqueia(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $r = $this->rascunhoSimples($base);
        $r->variantes()->first()->update(['estoque' => 1]);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(0, $p['variantes'][ChaveCanonica::UNICA]['estoque']);
        $this->assertContains('estoque_zero', array_column($p['avisos'], 'chave'));
        $this->assertNull($p['erro_campo']);
    }

    public function test_deposito_que_zera_aparece_nomeado_na_mensagem(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $r = $this->rascunhoSimples($base);
        $r->variantes()->first()->update(['estoque' => null, 'estoque_depositos' => ['DEP-SP' => 1, 'DEP-RJ' => 9]]);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(['DEP-SP' => 0, 'DEP-RJ' => 4], $p['variantes'][ChaveCanonica::UNICA]['depositos']);
        $this->assertSame(4, $p['variantes'][ChaveCanonica::UNICA]['estoque']);

        $mensagem = collect($p['avisos'])->firstWhere('chave', 'estoque_zero')['mensagem'];
        $this->assertStringContainsString('DEP-SP', $mensagem, 'o nome do depósito que zerou entra no aviso');
        $this->assertStringNotContainsString('DEP-RJ', $mensagem, 'o depósito que não zerou fica fora');
    }

    public function test_estoque_nunca_informado_no_base_avisa_desconhecido(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $r = $this->rascunhoSimples($base);
        $r->variantes()->first()->update(['estoque' => null]);

        $p = $this->servico()->previa($base, 2);

        $this->assertNull($p['variantes'][ChaveCanonica::UNICA]['estoque']);
        $chaves = array_column($p['avisos'], 'chave');
        $this->assertContains('estoque_desconhecido', $chaves);
        $this->assertNotContains('estoque_zero', $chaves, 'não informado não é zero');
    }

    /** Variante desligada no base não gera aviso de estoque — mas vai no mapa, porque o clone a copia. */
    public function test_variante_desligada_entra_no_mapa_sem_gerar_aviso(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $r = $this->rascunhoSimples($base);
        $r->variantes()->create([
            'combinacao_chave' => 'COLOR=id:52049',
            'combinacao_hash' => ChaveCanonica::hash('COLOR=id:52049'),
            'ativa' => false,
            'estoque' => 1,
        ]);

        $p = $this->servico()->previa($base, 2);

        $this->assertArrayHasKey('COLOR=id:52049', $p['variantes']);
        $this->assertSame(0, $p['variantes']['COLOR=id:52049']['estoque']);
        $this->assertFalse($p['variantes']['COLOR=id:52049']['ativa']);
        $this->assertNotContains('estoque_zero', array_column($p['avisos'], 'chave'), 'desligada no base não vira aviso');
    }

    public function test_titulo_cortado_gera_aviso(): void
    {
        $base = $this->base($this->empresa());
        $this->schema('MLB193945', 40);
        $r = $this->rascunhoSimples($base);
        $r->alvos()->where('listing_type_id', 'gold_special')->update(['titulo' => 'Cadeira Gamer Reclinável Com Apoio Para Os Pés']);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(40, $p['max_title_length']);
        $this->assertLessThanOrEqual(40, mb_strlen($p['titulo_por_tipo']['gold_special']));
        $this->assertContains('titulo_cortado', array_column($p['avisos'], 'chave'));
    }

    public function test_base_sem_categoria_cai_no_limite_padrao_com_aviso(): void
    {
        $base = $this->base($this->empresa());
        $this->rascunhoSimples($base, null);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(60, $p['max_title_length']);
        $this->assertContains('sem_categoria', array_column($p['avisos'], 'chave'));
        Http::assertNothingSent();
    }

    /** Categoria que o ML não devolve (`RegraViolada` V-CAT-03): a prévia não explode. */
    public function test_categoria_ilegivel_nao_derruba_a_previa(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $base = $this->base($this->empresa());
        $this->rascunhoSimples($base, 'MLB999999');

        $p = $this->servico()->previa($base, 2);

        $this->assertSame(60, $p['max_title_length']);
        $this->assertContains('sem_categoria', array_column($p['avisos'], 'chave'));
    }

    /** Alvo sem título (o base herdaria o planejado na publicação): o kit parte do nome do produto. */
    public function test_tipo_sem_titulo_no_base_usa_o_nome_do_produto(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $r = $this->rascunhoSimples($base);
        $r->alvos()->where('listing_type_id', 'gold_special')->update(['titulo' => null]);

        $p = $this->servico()->previa($base, 2);

        $this->assertSame('Kit 2 Cadeira Escritório', $p['titulo_por_tipo']['gold_special']);
    }

    public function test_base_sem_rascunho_avisa_e_nao_explode(): void
    {
        $base = $this->base($this->empresa());

        $p = $this->servico()->previa($base, 2);

        $this->assertSame('CAD-KIT2', $p['sku']);
        $this->assertSame([], $p['tipos']);
        $this->assertSame([], $p['titulo_por_tipo']);
        $this->assertSame([], $p['variantes']);
        $this->assertContains('sem_rascunho', array_column($p['avisos'], 'chave'));
    }

    /** A prévia NÃO grava: é o que o nome promete. */
    public function test_previa_nao_grava_nada(): void
    {
        $base = $this->base($this->empresa());
        $this->schema();
        $this->rascunhoSimples($base);
        $antes = [PubProduto::count(), PubRascunho::count()];

        $this->servico()->previa($base, 2);

        $this->assertSame($antes, [PubProduto::count(), PubRascunho::count()]);
    }
}
