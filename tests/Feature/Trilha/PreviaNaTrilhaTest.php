<?php

namespace Tests\Feature\Trilha;

use App\Models\AcervoImagem;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Publicador\CriarFaseService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Trilha\PreviaNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A prévia do anúncio dentro da trilha (4ª entrega, fatia 4a, desenho §19): o painel `v:{item}` da equipe lê o anúncio
 * que o robô preparou — o MESMO plano do Publicador (`PreviaDoAnuncioService::montar`) — sem passar pela rota de lá.
 *
 * O cenário é o do preparo de verdade (`CenarioDoPreparo`: a cadeira MLB193945 com o schema guardado, a IA como dublê,
 * a fila rodada à mão): o produto P com a Simples preparada e três fotos (do cliente, gerada pelo acervo, gerada por
 * criativo), o Kit 2 dela (o Combo 2 do Portal, `c-P-2`) e o Kit com o produto Q (`o-K`). As chaves da trilha só ligam
 * DEPOIS do preparo (com elas o robô esperaria a onda). O que se prova:
 *
 * - os três tipos de item (s-, c-, o-) abrem a prévia do rascunho DELES, com as fotos pela rota da trilha e `gerada`
 *   pela régua das imagens automáticas (acervo `ia` e criativo);
 * - item de outra loja, ou que não existe, é 404; fora da revisão é 200 com `pronta: false` e o motivo neutro, com a
 *   etapa e o status COMO NA TELA (a régua da onda);
 * - nenhum `/portal`, `mlb/anuncios`, "Mercado Livre" nem "etapa Produto/Condições/Detalhes" no JSON;
 * - o preço da Precificação × o gravado no rascunho;
 * - nada é gravado e o `EditorEmUso` NÃO é marcado (a rota do Publicador marca; a da trilha, não);
 * - a rota da foto só serve `pub_imagens` do rascunho do item;
 * - custo: com o cache quente, nenhuma chamada ao ML e consultas que não crescem com o catálogo.
 */
class PreviaNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    /** As chaves do contrato (§19.5), na ordem. */
    private const CHAVES = ['item', 'pronta', 'motivo', 'revisao', 'categoria', 'tipos', 'variantes', 'anuncios', 'descricao',
        'preco', 'preparo', 'publicacao', 'aprovacao', 'bloqueios'];

    private EstruturaProduto $p;

    private EstruturaProduto $q;

    private PubProduto $grupo;

    private PubProduto $kitN;

    private PubProduto $pubDoKit;

    private EstruturaOferta $kit;

    /** @var array{cliente: PubImagem, acervo: PubImagem, criativo: PubImagem} as fotos do rascunho da Simples de P */
    private array $fotos;

    private User $dev;

    /** O dublê da Precificação ({@see efetivosDoPortal()}). */
    private ?DadosEfetivosService $precificacao = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
            'publicador.contas_liberadas.todas' => false, 'publicador.contas_liberadas.companies' => [],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarPreparo();
        // A página da equipe (o teste da `escrita`) monta o menu interno, cujo badge pergunta ao ECF Drive.
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));

        $this->p = $this->produtoDoPortal('CAD');
        $this->q = $this->produtoDoPortal('CDB');
        $simplesP = EstruturaOferta::query()->where('sku', 'CAD-AZ')->firstOrFail();
        $simplesQ = EstruturaOferta::query()->where('sku', 'CDB-AZ')->firstOrFail();
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'CAD-AZ-CB2', 'Combo 2 Cadeira', [[$simplesP, 2]]);
        $this->kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-CAD-CDB', 'Kit Cadeiras', [[$simplesP, 1], [$simplesQ, 1]]);
        $this->salvarERodar($this->p);
        $this->salvarERodar($this->q);

        $this->grupo = $this->pubDo($this->p);
        $this->pubDoKit = PubProduto::query()->where('oferta_id', $this->kit->id)->firstOrFail();
        $this->fotos = $this->fotosNaSimples($this->rascunhoDo($this->p));
        // O Kit 2 da Simples (o Combo 2 do Portal): CÓPIA do rascunho dela, fotos inclusive (outras linhas de `pub_imagens`).
        $this->kitN = app(CriarFaseService::class)->criar($this->grupo->fresh(), ['quantidade' => 2]);

        // A marca de "agendado" do save (80 s) vence; e só agora a trilha liga (com ela o robô esperaria a onda).
        Carbon::setTestNow(now()->addMinutes(5));
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Os três tipos de item ════════════════════════════════════════════════

    public function test_a_previa_da_simples_sai_do_plano_com_as_fotos_pela_trilha_e_as_geradas_marcadas(): void
    {
        $r = $this->rascunhoDo($this->p);
        $chave = "s-{$this->p->id}";

        $j = $this->previa($chave)->assertOk()->json();

        $this->assertSame(self::CHAVES, array_keys($j), 'o contrato da §19.5, nem mais nem menos');
        $this->assertSame(['id' => $chave, 'nome' => 'Cadeira Executiva', 'tipo' => 'Simples', 'etapa' => 'revisao',
            'status' => 'Pré-pronto · a conferir'], $j['item']);
        $this->assertTrue($j['pronta']);
        $this->assertNull($j['motivo']);
        $this->assertSame((int) $r->fresh()->revisao, $j['revisao']);
        $this->assertSame(['caminho' => ['Casa, Móveis e Decoração', 'Móveis para Casa', 'Cadeiras, Sofás e Banquetas', 'Cadeiras para Escritório']], $j['categoria']);
        $this->assertSame([['chave' => 'classico', 'nome' => 'Clássico'], ['chave' => 'premium', 'nome' => 'Premium']], $j['tipos']);
        $this->assertSame([['chave' => '__single__', 'rotulo' => 'Único']], $j['variantes']);
        $this->assertSame('Cadeira confortável para o dia todo.', $j['descricao'], 'o texto que vai ao anúncio, sem HTML');
        $this->assertSame(['titulo' => 'ok', 'descricao' => 'ok', 'imagens' => null], $j['preparo']);
        $this->assertNull($j['publicacao']);
        $this->assertNull($j['aprovacao']);
        $this->assertSame([], $j['bloqueios']);

        $this->assertCount(2, $j['anuncios']);
        [$classico, $premium] = $j['anuncios'];
        $this->assertSame(['tipo', 'variante', 'titulo', 'preco', 'promocao', 'parcelas', 'fotos', 'caracteristicas', 'envio'], array_keys($classico));
        $this->assertSame(['classico', '__single__', 'Cadeira Escritório Giratória Ergonômica'], [$classico['tipo'], $classico['variante'], $classico['titulo']]);
        $this->assertSame(['premium', 'Cadeira Giratória Escritório Ergonômica'], [$premium['tipo'], $premium['titulo']]);
        $this->assertNull($classico['parcelas'], 'só o Premium parcela');
        $this->assertSame(['sem_promocao', 'com_promocao'], array_keys((array) $premium['parcelas']));
        $this->assertSame(['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false, 'aviso' => null], $classico['envio']);
        $itens = collect($classico['caracteristicas'])->flatMap(fn ($g) => $g['itens']);
        $this->assertContains(['nome' => 'Marca', 'valor' => 'ECF'], $itens->all());

        // As fotos: as três do rascunho, pela rota da trilha (relativa), e a geração pela régua das imagens automáticas.
        $porId = collect($classico['fotos'])->keyBy('id');
        $this->assertEqualsCanonicalizing(array_map(fn (PubImagem $i) => (string) $i->id, $this->fotos), $porId->keys()->all());
        foreach ($this->fotos as $tipo => $img) {
            $foto = $porId[(string) $img->id];
            $this->assertSame(['id', 'url', 'gerada'], array_keys($foto));
            $this->assertSame("/trilha/{$this->empresa->id}/ofertas/{$chave}/fotos/{$img->id}", $foto['url']);
            $this->assertSame($tipo !== 'cliente', $foto['gerada'], "a foto {$tipo}");
        }
        // E a URL abre a foto.
        $this->actingAs($this->dev)->get($classico['fotos'][0]['url'])->assertOk();
    }

    public function test_o_combo_e_o_kit_abrem_a_previa_do_rascunho_deles(): void
    {
        $doCombo = $this->previa("c-{$this->p->id}-2")->assertOk()->json();
        $doKit = $this->previa("o-{$this->kit->id}")->assertOk()->json();

        foreach ([[$doCombo, $this->kitN, "c-{$this->p->id}-2", 'Combo'], [$doKit, $this->pubDoKit, "o-{$this->kit->id}", 'Kit']] as [$j, $pub, $chave, $tipo]) {
            $r = PubRascunho::query()->where('produto_id', $pub->id)->firstOrFail();
            $this->assertSame(self::CHAVES, array_keys($j));
            $this->assertSame([$chave, $tipo, 'revisao'], [$j['item']['id'], $j['item']['tipo'], $j['item']['etapa']], $chave);
            $this->assertTrue($j['pronta'], $chave);
            $this->assertSame((int) $r->revisao, $j['revisao'], "{$chave}: a revisão do rascunho DELE");
            $this->assertNotEmpty($j['anuncios'], $chave);
            // As fotos do rascunho dele, pela rota dele.
            $ids = $r->imagens()->pluck('id')->map(fn ($id) => (string) $id)->all();
            foreach ($j['anuncios'] as $a) {
                foreach ($a['fotos'] as $f) {
                    $this->assertContains($f['id'], $ids, "{$chave}: foto de outro rascunho");
                    $this->assertSame("/trilha/{$this->empresa->id}/ofertas/{$chave}/fotos/{$f['id']}", $f['url']);
                }
            }
        }
        // O Kit 2 é cópia da Simples, fotos inclusive (outras linhas, o mesmo arquivo). A geração pelo acervo vai junto (a
        // cópia leva o ativo); a do criativo, não — o criativo aponta para a linha da Simples. É a régua copiada das
        // imagens automáticas (para elas a cópia seria foto do cliente); hoje a aprovação de um criativo já grava o ativo
        // com origem `ia`, e o caso só existe para foto anterior ao acervo.
        $doKitN = PubImagem::query()->where('rascunho_id', PubRascunho::query()->where('produto_id', $this->kitN->id)->value('id'))->get()->keyBy('sha256');
        $this->assertCount(3, $doCombo['anuncios'][0]['fotos']);
        $geradas = collect($doCombo['anuncios'][0]['fotos'])->where('gerada', true)->pluck('id')->all();
        $this->assertSame([(string) $doKitN[$this->fotos['acervo']->sha256]->id], $geradas);
        // O texto do Kit N não é do robô ("kit_da_fase"): nada a dizer do título nem da descrição.
        $this->assertSame(['titulo' => null, 'descricao' => null, 'imagens' => null], $doCombo['preparo']);
        $this->assertSame(['titulo' => 'ok', 'descricao' => 'ok', 'imagens' => null], $doKit['preparo']);
    }

    // ═══ Quem não tem prévia ══════════════════════════════════════════════════

    public function test_item_que_nao_existe_ou_de_outra_loja_e_404(): void
    {
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $kitDeFora = $this->ofertaComposta($outra, EstruturaOferta::FASE_KIT, 'KT-VF', 'Kit de Fora', [[$deFora['simples'][0], 1]]);
        $pubDeFora = $this->grupoNoPublicador($outra, $deFora);
        $this->rascunhoDoPub($pubDeFora);

        foreach (["s-{$deFora['produto']->id}", "o-{$kitDeFora->id}", "c-{$this->p->id}-3", 's-999999', 'o-999999'] as $chave) {
            $this->previa($chave)->assertNotFound();
        }
        // Pela loja de fora (dentro das chaves), o item é dela: a régua de cada loja só conhece os seus.
        $this->actingAs($this->dev)->getJson("/trilha/{$outra->id}/ofertas/s-{$this->p->id}/previa")->assertNotFound();
        // Chave fora da forma: a rota nem casa.
        foreach (['x-1', 's-', 'c-1', "s-{$this->p->id}a", 's-1234567890123456789'] as $chave) {
            $this->previa($chave)->assertNotFound();
        }
    }

    /**
     * Fora da revisão o painel diz que o robô prepara (200 + `pronta: false`, a escolha da 4a — o contrato da §19.5 já
     * descreve o "não pronta"). A etapa e o status são os da LINHA da tela: com a trilha de trabalho, o item que não andou
     * segue a régua da onda — o rascunho com o resumo validado sem o robô ter mexido diria "esperando a revisão" na
     * régua sem as ondas, mas a tela o mostra no Planejamento, e a prévia não pode abrir.
     */
    public function test_fora_da_revisao_a_previa_diz_que_o_robo_prepara_com_a_etapa_da_tela(): void
    {
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $naoAndou = $this->produtoCompleto($this->empresa, 'Vaso Parado', 'VP');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $naoAndou), PubRascunho::DRAFT, ['resumo' => [
            'revisao' => 1, 'validado' => true, 'bloqueios' => 0, 'versao' => EditorRascunhoService::VERSAO_DO_RESUMO,
        ]]);
        $chave = "s-{$naoAndou['produto']->id}";
        // Numa onda que ainda não começou: a linha aparece na tela, "na fila".
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda 20'), $chave);
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa)[$chave]['etapa'], 'sem as ondas, a régua diria revisão');
        $tela = $this->itemDaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa), $chave);

        $j = $this->previa($chave)->assertOk()->json();

        $this->assertSame(['id' => $chave, 'nome' => 'Vaso Parado', 'tipo' => 'Simples', 'etapa' => 'robo',
            'status' => 'Na fila · começa em 20/11'], $j['item']);
        $this->assertSame([$tela['etapa'], $tela['status']], [$j['item']['etapa'], $j['item']['status']], 'a etapa e o status da linha');
        $this->assertFalse($j['pronta']);
        $this->assertSame(PreviaNaTrilha::MOTIVO_PREPARANDO, $j['motivo']);
        $this->assertSame([[], [], null, null], [$j['anuncios'], $j['tipos'], $j['preco'], $j['categoria']]);
        $this->assertSame(1, $j['revisao']);
        $this->assertSame(self::CHAVES, array_keys($j));

        // Sem rascunho (nem pub_produto ainda): o mesmo motivo, e nada de revisão nem preparo.
        $sem = $this->previa("s-{$semPub['produto']->id}")->assertOk()->json();
        $this->assertSame([false, PreviaNaTrilha::MOTIVO_PREPARANDO, null, null, []], [$sem['pronta'], $sem['motivo'], $sem['revisao'], $sem['preparo'], $sem['anuncios']]);
        $this->assertSame('plano', $sem['item']['etapa']);
    }

    public function test_na_revisao_sem_categoria_ou_sem_tipo_ligado_o_motivo_e_neutro(): void
    {
        $r = $this->rascunhoDo($this->p);
        $chave = "s-{$this->p->id}";

        // Sem categoria: o Publicador diria "Escolha a categoria na etapa Produto…".
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['categoria_id' => null]);
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame(['revisao', false, PreviaNaTrilha::MOTIVO_SEM_CATEGORIA, []], [$j['item']['etapa'], $j['pronta'], $j['motivo'], $j['anuncios']]);
        $this->assertNull($j['preco']);

        // Categoria que não se lê agora (sem o schema guardado e o ML fora — o `montar` LANÇA): não é "sem categoria", e não
        // é 500 (a rota do Publicador daria 500 aqui).
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['categoria_id' => 'MLB999999']);
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame([false, PreviaNaTrilha::MOTIVO_INDISPONIVEL, []], [$j['pronta'], $j['motivo'], $j['anuncios']]);
        $this->assertSame('revisao', $j['item']['etapa']);

        // Nenhum tipo ligado: o Publicador diria "…em Condições de venda".
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['categoria_id' => self::CADEIRA]);
        DB::table('pub_rascunho_alvos')->where('rascunho_id', $r->id)->update(['ativo' => false]);
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame([false, PreviaNaTrilha::MOTIVO_SEM_TIPO, []], [$j['pronta'], $j['motivo'], $j['tipos']]);
    }

    /**
     * Nada do Portal, do editor do Publicador nem o nome da plataforma no JSON — em todas as formas da resposta. Sem a
     * troca da trilha, a foto sairia pela rota do Publicador (`mlb/anuncios/…`) e o motivo citaria a "etapa Produto".
     */
    public function test_nenhum_portal_editor_do_publicador_nem_mercado_livre_no_json(): void
    {
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $respostas = [
            $this->previa("s-{$this->p->id}"), $this->previa("c-{$this->p->id}-2"), $this->previa("o-{$this->kit->id}"),
            $this->previa("s-{$semPub['produto']->id}"),
        ];
        DB::table('pub_rascunhos')->where('id', $this->rascunhoDo($this->p)->id)->update(['categoria_id' => null]);
        $respostas[] = $this->previa("s-{$this->p->id}");
        DB::table('pub_rascunhos')->where('id', $this->rascunhoDo($this->p)->id)->update(['categoria_id' => self::CADEIRA]);
        DB::table('pub_rascunho_alvos')->where('rascunho_id', $this->rascunhoDo($this->p)->id)->update(['ativo' => false]);
        $respostas[] = $this->previa("s-{$this->p->id}");

        foreach ($respostas as $i => $resposta) {
            $corpo = (string) $resposta->assertOk()->getContent();
            $texto = json_encode(json_decode($corpo, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['/portal', 'portal/', 'mlb/anuncios', 'Mercado Livre', 'mercadolivre', 'mlstatic', 'etapa Produto',
                'Condições', 'etapa Detalhes', 'Publicador', 'http://', 'https://'] as $proibido) {
                $this->assertStringNotContainsStringIgnoringCase($proibido, $texto, "resposta {$i}: {$proibido}");
            }
        }
    }

    // ═══ O preço ══════════════════════════════════════════════════════════════

    public function test_o_preco_de_cada_tipo_e_o_da_precificacao_ou_o_ajustado_no_rascunho(): void
    {
        // A Precificação fixa (a de verdade tem a suíte dela): Clássico 207,19 e Premium 230,50.
        $this->efetivosDoPortal(['gold_special' => 207.19, 'gold_pro' => 230.5]);
        $r = $this->rascunhoDo($this->p);
        $chave = "s-{$this->p->id}";

        // 4c ampliou o `preco` (aditivo): o `minimo` da Precificação por tipo, `por_cor` e `premium_igual`.
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame([
            'classico'      => ['valor' => 207.19, 'da_precificacao' => 207.19, 'minimo' => null, 'ajustado' => false],
            'premium'       => ['valor' => 230.5, 'da_precificacao' => 230.5, 'minimo' => null, 'ajustado' => false],
            'por_cor'       => false,
            'premium_igual' => false,
        ], $j['preco']);
        $this->assertSame([207.19, 230.5], array_column($j['anuncios'], 'preco'));

        // A equipe grava o Clássico no rascunho (a 4c fará pela trilha): o valor muda, o da Precificação fica.
        app(EditorRascunhoService::class)->salvarVariantes($r->fresh(), ['__single__' => ['precos' => ['gold_special' => 199.9]]]);
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame([
            'classico'      => ['valor' => 199.9, 'da_precificacao' => 207.19, 'minimo' => null, 'ajustado' => true],
            'premium'       => ['valor' => 230.5, 'da_precificacao' => 230.5, 'minimo' => null, 'ajustado' => false],
            'por_cor'       => false,
            'premium_igual' => false,
        ], $j['preco']);
        $this->assertSame(199.9, $j['anuncios'][0]['preco'], 'o preço do anúncio é o do plano');

        // Gravado IGUAL ao da Precificação (diferença abaixo de meio centavo): não é ajuste.
        app(EditorRascunhoService::class)->salvarVariantes($r->fresh(), ['__single__' => ['precos' => ['gold_special' => 207.191]]]);
        $this->assertFalse($this->previa($chave)->assertOk()->json('preco.classico.ajustado'));

        // Sem preço na Precificação, o gravado é ajuste; e o tipo desligado sai nulo.
        $this->efetivosDoPortal(['gold_special' => null, 'gold_pro' => 230.5]);
        app(EditorRascunhoService::class)->salvarVariantes($r->fresh(), ['__single__' => ['precos' => ['gold_special' => 199.9]]]);
        DB::table('pub_rascunho_alvos')->where('rascunho_id', $r->id)->where('listing_type_id', 'gold_pro')->update(['ativo' => false]);
        $j = $this->previa($chave)->assertOk()->json();
        $this->assertSame(['classico' => ['valor' => 199.9, 'da_precificacao' => null, 'minimo' => null, 'ajustado' => true], 'premium' => null,
            'por_cor' => false, 'premium_igual' => false], $j['preco']);
        $this->assertSame([['chave' => 'classico', 'nome' => 'Clássico']], $j['tipos']);
    }

    // ═══ Só leitura ═══════════════════════════════════════════════════════════

    /**
     * A prévia e a foto não gravam nada e NÃO marcam o editor do Publicador como em uso — a rota de lá marca (a fila e o
     * robô esperam quem está "no editor"); a da trilha, que só lê, não pode segurar ninguém.
     */
    public function test_a_previa_e_a_foto_nao_gravam_nada_nem_marcam_o_editor_em_uso(): void
    {
        $pubs = [(int) $this->grupo->id, (int) $this->kitN->id, (int) $this->pubDoKit->id];
        $antes = $this->retratoDoBanco();
        $jobs = $this->jobsNaFila();

        $this->previa("s-{$this->p->id}")->assertOk();
        $this->previa("c-{$this->p->id}-2")->assertOk();
        $this->previa("o-{$this->kit->id}")->assertOk();
        $this->actingAs($this->dev)->get($this->urlDaFoto("s-{$this->p->id}", $this->fotos['cliente']))->assertOk();

        foreach ($pubs as $pub) {
            $this->assertFalse(EditorEmUso::emUso($pub), "pub {$pub} marcado em uso");
            $this->assertFalse(EditorEmUso::editando($pub), "pub {$pub} marcado editando");
        }
        $this->assertSame($antes, $this->retratoDoBanco(), 'a prévia gravou');
        $this->assertSame($jobs, $this->jobsNaFila(), 'a prévia pôs Job na fila');

        // O contraponto: a rota do Publicador marca (é por isso que a trilha não passa por ela).
        $this->actingAs($this->dev)->getJson(route('mlb.anuncios.publicador.previa', ['produto' => $this->grupo->id]))->assertOk();
        $this->assertTrue(EditorEmUso::emUso((int) $this->grupo->id));
    }

    // ═══ A foto ═══════════════════════════════════════════════════════════════

    public function test_a_foto_so_serve_pub_imagens_do_rascunho_do_item(): void
    {
        $s = "s-{$this->p->id}";
        $foto = $this->actingAs($this->dev)->get($this->urlDaFoto($s, $this->fotos['cliente']))->assertOk();
        $this->assertStringContainsString('private', (string) $foto->headers->get('Cache-Control'));
        $this->assertSame('image/jpeg', $foto->headers->get('Content-Type'));
        $this->assertSame('nosniff', $foto->headers->get('X-Content-Type-Options'));

        // A foto do Kit 2 (outro rascunho do MESMO produto) pela Simples, e a da Simples pelo Kit: 404.
        $doKitN = PubImagem::query()->where('rascunho_id', PubRascunho::query()->where('produto_id', $this->kitN->id)->value('id'))->firstOrFail();
        $this->actingAs($this->dev)->get($this->urlDaFoto($s, $doKitN))->assertNotFound();
        $this->actingAs($this->dev)->get($this->urlDaFoto("o-{$this->kit->id}", $this->fotos['cliente']))->assertNotFound();
        $this->actingAs($this->dev)->get($this->urlDaFoto("c-{$this->p->id}-2", $doKitN))->assertOk();

        // A de outra loja (pelo endereço desta) e a que não existe: 404.
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $rDeFora = $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora));
        $alheia = $rDeFora->imagens()->create(['caminho' => 'publicador/fora.jpg', 'sha256' => str_repeat('f', 64), 'mime' => 'image/jpeg', 'bytes' => 10, 'upload_status' => PubImagem::PENDENTE]);
        ArquivosDeImagem::gravar('publicador/fora.jpg', 'x');
        $this->actingAs($this->dev)->get($this->urlDaFoto($s, $alheia))->assertNotFound();
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/ofertas/{$s}/fotos/999999")->assertNotFound();
        // …e pela loja de fora, a foto desta.
        $this->actingAs($this->dev)->get("/trilha/{$outra->id}/ofertas/s-{$deFora['produto']->id}/fotos/{$this->fotos['cliente']->id}")->assertNotFound();
        $this->actingAs($this->dev)->get("/trilha/{$outra->id}/ofertas/s-{$deFora['produto']->id}/fotos/{$alheia->id}")->assertOk();

        // A foto antiga, só com o endereço público (veio do Anunciar antigo): redireciona; sem nada: 404.
        $r = $this->rascunhoDo($this->p);
        $antiga = $r->imagens()->create(['caminho' => null, 'sha256' => str_repeat('a', 64), 'mime' => 'image/jpeg', 'bytes' => 10,
            'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'PIC-1', 'ml_url' => 'https://http2.mlstatic.com/D_1-O.jpg']);
        $this->actingAs($this->dev)->get($this->urlDaFoto($s, $antiga))->assertRedirect('https://http2.mlstatic.com/D_1-O.jpg');
        // Guardada em `http://`: o redirecionamento sai em https (a página é https; o navegador bloquearia conteúdo misto).
        $emHttp = $r->imagens()->create(['caminho' => null, 'sha256' => str_repeat('d', 64), 'mime' => 'image/jpeg', 'bytes' => 10,
            'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'PIC-2', 'ml_url' => 'http://http2.mlstatic.com/D_2-O.jpg']);
        $this->assertSame('https://http2.mlstatic.com/D_2-O.jpg', $this->actingAs($this->dev)->get($this->urlDaFoto($s, $emHttp))->assertRedirect()->headers->get('Location'));
        // Outro esquema não sai como endereço.
        $estranha = $r->imagens()->create(['caminho' => null, 'sha256' => str_repeat('e', 64), 'mime' => 'image/jpeg', 'bytes' => 10,
            'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'PIC-3', 'ml_url' => 'javascript:alert(1)']);
        $this->actingAs($this->dev)->get($this->urlDaFoto($s, $estranha))->assertNotFound();
        $perdida = $r->imagens()->create(['caminho' => 'publicador/sumiu.jpg', 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg', 'bytes' => 10, 'upload_status' => PubImagem::PENDENTE]);
        $this->actingAs($this->dev)->get($this->urlDaFoto($s, $perdida))->assertNotFound();
    }

    // ═══ O endereço na página ═════════════════════════════════════════════════

    public function test_a_escrita_da_equipe_traz_a_previa_com_o_marcador_do_item(): void
    {
        $this->withoutVite();
        $props = json_decode(json_encode($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk()->viewData('page')), true)['props'];

        // 4c acrescentou o `preco` (o PUT do preço ajustável), com o mesmo marcador.
        $this->assertSame(['previa' => "/trilha/{$this->empresa->id}/ofertas/__ITEM__/previa", 'preco' => "/trilha/{$this->empresa->id}/ofertas/__ITEM__/preco"],
            $props['escrita']['revisao']);
        $url = str_replace('__ITEM__', "s-{$this->p->id}", $props['escrita']['revisao']['previa']);
        $this->assertTrue($this->actingAs($this->dev)->getJson($url)->assertOk()->json('pronta'));
    }

    // ═══ Custo ════════════════════════════════════════════════════════════════

    /**
     * Zero IA e zero ML com o cache quente: a conta liberada (o envio pelas medidas lê a modalidade da conta — no cache)
     * e o schema guardado; o cliente HTTP trocado por um que anota qualquer pedido. E as consultas não crescem com o
     * catálogo: mais dois produtos preparados, outra loja e mais fotos no rascunho — o mesmo número na prévia e na foto.
     */
    public function test_com_o_cache_quente_nenhuma_chamada_ao_ml_e_as_consultas_nao_crescem(): void
    {
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'APP_USR-x', 'refresh_token' => 'TG-x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);
        ModalidadeDeEnvio::guardar((int) $this->empresa->id, 'drop_off', me1: false);
        $s = "s-{$this->p->id}";

        $pedidos = [];
        Http::swap(new FabricaHttp(app('events')));
        Http::preventStrayRequests();
        Http::fake(function (PedidoHttp $pedido) use (&$pedidos) {
            $pedidos[] = $pedido->method().' '.$pedido->url();

            return Http::response([], 500);
        });

        // A base já tem de tudo um pouco (produto com família, cores, kit e rascunho): a leitura em lote de uma relação vazia
        // o Eloquent pula, e a 1ª linha dela pareceria custo por produto.
        $base = $this->produtoCompleto($this->empresa, 'Vaso Base', 'VBASE', ['Azul', 'Verde']);
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $base), PubRascunho::DRAFT, ['ia_preparo' => ['etapas' => ['titulo' => ['status' => 'ok']]]]);
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-VBASE', 'Kit Base', [[$base['simples'][0], 1], [$base['simples'][1], 1]]);

        $antes = $this->previa($s)->assertOk()->json();
        [$naPrevia, $naFoto] = [$this->consultas(fn () => $this->previa($s)->assertOk()), $this->consultas(fn () => $this->actingAs($this->dev)->get($this->urlDaFoto($s, $this->fotos['cliente']))->assertOk())];

        // O catálogo cresce: outra loja, mais produtos com rascunho e mais fotos no rascunho do item.
        $outra = $this->empresaDaTrilha();
        $this->rascunhoDoPub($this->grupoNoPublicador($outra, $this->produtoCompleto($outra, 'Vaso de Fora', 'VF')));
        foreach (['VA', 'VB', 'VC'] as $codigo) {
            $mais = $this->produtoCompleto($this->empresa, "Vaso {$codigo}", $codigo, ['Azul', 'Verde']);
            $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $mais), PubRascunho::DRAFT, ['ia_preparo' => ['etapas' => ['titulo' => ['status' => 'ok']]]]);
            $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, "KT-{$codigo}", "Kit {$codigo}", [[$mais['simples'][0], 1], [$mais['simples'][1], 1]]);
        }
        $this->maisFotos($this->rascunhoDo($this->p), 2);

        $this->assertSame($naPrevia, $this->consultas(fn () => $this->previa($s)->assertOk()), 'consultas da prévia cresceram com o catálogo');
        $this->assertSame($naFoto, $this->consultas(fn () => $this->actingAs($this->dev)->get($this->urlDaFoto($s, $this->fotos['cliente']))->assertOk()), 'consultas da foto cresceram');
        $this->assertCount(5, $this->previa($s)->assertOk()->json('anuncios.0.fotos'));
        $this->assertSame([], $pedidos, 'a prévia chamou o ML com o cache quente');
        $this->assertTrue($antes['pronta']);
    }

    // ─── apoio ───

    private function previa(string $chave): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/previa");
    }

    private function urlDaFoto(string $chave, PubImagem $img): string
    {
        return "/trilha/{$this->empresa->id}/ofertas/{$chave}/fotos/{$img->id}";
    }

    /**
     * Três fotos na galeria geral do rascunho: a do cliente, uma gerada que entrou pelo acervo (ativo com origem `ia`) e
     * uma que veio de um criativo aprovado (`ml_anuncio_criativos.pub_imagem_id`) — as duas réguas das imagens automáticas.
     *
     * @return array{cliente: PubImagem, acervo: PubImagem, criativo: PubImagem}
     */
    private function fotosNaSimples(PubRascunho $r): array
    {
        $saida = [];
        foreach (['cliente' => 1200, 'acervo' => 1100, 'criativo' => 1000] as $tipo => $lado) {
            $saida[$tipo] = $this->foto($r, "{$tipo}.jpg", $lado);
        }
        $ativo = AcervoImagem::query()->create(['company_id' => $this->empresa->id, 'dono_chave' => 'company-'.$this->empresa->id, 'disco' => 'local',
            'caminho' => $saida['acervo']->caminho, 'sha256' => $saida['acervo']->sha256, 'mime' => 'image/jpeg', 'bytes' => 100, 'origem' => AcervoImagem::ORIGEM_IA]);
        $saida['acervo']->update(['acervo_imagem_id' => $ativo->id]);
        DB::table('ml_anuncio_criativos')->insert(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'slot' => 'lifestyle',
            'status' => 'aprovado', 'pub_imagem_id' => $saida['criativo']->id, 'created_at' => now(), 'updated_at' => now()]);

        return array_map(fn (PubImagem $i) => $i->fresh(), $saida);
    }

    private function maisFotos(PubRascunho $r, int $quantas): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            $this->foto($r, "mais{$i}.jpg", 900 - 10 * $i);
        }
    }

    /** Uma foto guardada (sem subir ao ML) e posta na galeria geral — o caminho do "enviar foto" do editor. */
    private function foto(PubRascunho $r, string $nome, int $lado): PubImagem
    {
        $res = app(ImagemAssetService::class)->receber($r, UploadedFile::fake()->image($nome, $lado, $lado)->get(), $nome, false);
        app(EditorRascunhoService::class)->colocarFotoNoGrupo($r, $res['imagem'], ResolvedorGruposImagem::GERAL);

        return $res['imagem'];
    }

    /**
     * Os efetivos do Portal fixos — o preço da Precificação por tipo (título planejado nulo: vale o do robô). O dublê é
     * UM objeto, mudado no lugar: o controller (e o que ele recebeu no construtor) é resolvido uma vez por rota no teste.
     */
    private function efetivosDoPortal(array $precos): void
    {
        $fixos = ['titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => $precos,
            'promocoes' => ['gold_special' => null, 'gold_pro' => null], 'sem_frete' => ['gold_special' => false, 'gold_pro' => false], 'mlbs' => []];
        if ($this->precificacao !== null) {
            $this->precificacao->fixos = $fixos;

            return;
        }
        $this->precificacao = new class(app(EstruturaPrecificacaoService::class), $fixos) extends DadosEfetivosService
        {
            public function __construct(EstruturaPrecificacaoService $p, public array $fixos)
            {
                parent::__construct($p);
            }

            public function daProduto(PubProduto $produto): array
            {
                return $this->fixos;
            }
        };
        $this->app->instance(DadosEfetivosService::class, $this->precificacao);
    }

    /** Quantas consultas ao banco o pedido faz. */
    private function consultas(callable $pedido): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $pedido();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }

    /** O que a prévia poderia mudar no banco: cada linha das tabelas do Publicador e da trilha, e o histórico. */
    private function retratoDoBanco(): array
    {
        $saida = [];
        foreach (['pub_produtos', 'pub_rascunhos', 'pub_imagens', 'pub_imagem_atribuicoes', 'pub_rascunho_alvos', 'pub_rascunho_atributos', 'pub_variantes', 'pub_variante_precos',
            'pub_validacoes', 'acervo_imagens', 'ml_anuncio_criativos', 'trilha_ondas', 'trilha_onda_itens', 'activity_log', 'jobs'] as $tabela) {
            $saida[$tabela] = DB::table($tabela)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();
        }

        return $saida;
    }

    private function jobsNaFila(): int
    {
        return collect(Queue::pushedJobs())->flatten(1)->count();
    }
}
