<?php

namespace Tests\Feature\Trilha;

use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlToken;
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
use App\Services\Trilha\PrecoNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O preço ajustável da revisão (4ª entrega, fatia 4c, desenho §19.11, D45/D46/D47): `PUT /trilha/{empresa}/ofertas/{item}/preco`
 * grava o preço do Clássico e/ou do Premium SÓ no rascunho do anúncio (`pub_variante_precos`, pelo
 * `EditorRascunhoService::salvarVariantes`), em todas as cores ou numa só, e responde a prévia nova.
 *
 * O cenário é o do preparo de verdade (`CenarioDoPreparo`, como o `PreviaNaTrilhaTest`): a cadeira de DUAS cores (Azul e
 * Preto, um pub_produto com as duas como variantes) preparada pelo agente, na revisão; as chaves da trilha ligam só depois
 * do preparo. A Precificação é um dublê fixo por cor (a de verdade tem a suíte dela) — fora no teste que prova que ela
 * NÃO é escrita, que roda com a de verdade. O que se prova:
 *
 * - grava em todas as cores (`cor: null`) e numa só; `null` volta a herdar da Precificação; pedido igual não grava;
 * - Premium igual ao Clássico → 422 `errors["precos.premium"]`, inclusive quando a igualdade nasce do pedido COMBINADO
 *   com o preço já gravado ou herdado do outro tipo; só nas cores que o pedido toca;
 * - tipo desligado, valor inválido e cor fora do anúncio → 422; nada grava;
 * - revisão velha → 409 SEM `travado` com `revisao_atual` (antes do 422); na fila, publicando, publicado e fora da
 *   revisão → 409 SEM `travado`;
 * - a Precificação não é escrita (linhas inteiras iguais antes e depois, não só a contagem);
 * - o `activity_log` com o evento, o tipo, a cor, o de → para e o ator da equipe;
 * - abaixo do mínimo é aviso e grava;
 * - nenhuma chamada HTTP, nenhum Job, e o `EditorEmUso` não é marcado;
 * - o Kit N (`c-`) grava no rascunho DELE.
 */
class PrecoNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    /** As chaves da prévia (§19.5, com as da 4b) e, só na resposta do PUT, `avisos`. */
    private const CHAVES = ['item', 'pronta', 'motivo', 'revisao', 'categoria', 'tipos', 'variantes', 'anuncios', 'descricao',
        'preco', 'preparo', 'publicacao', 'ajuste', 'ajustes_restantes_hoje', 'aprovacao', 'bloqueios', 'eixos', 'vendedor', 'avisos'];

    /** As chaves canônicas das duas cores da cadeira no Publicador (valores do eixo COLOR do schema MLB193945). */
    private const AZUL = 'COLOR=id:52028';

    private const PRETO = 'COLOR=id:52049';

    private EstruturaProduto $d;

    private PubProduto $pub;

    private User $dev;

    /** O dublê da Precificação ({@see precificacao()}). */
    private ?DadosEfetivosService $dubleDaPrecificacao = null;

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

        // A cadeira do cenário com uma 2ª cor (Preto): o Sincronizar junta as duas num pub_produto só.
        $this->d = $this->produtoDoPortal('CDC');
        $v = EstruturaProdutoVariacao::create(['produto_id' => $this->d->id, 'company_id' => $this->empresa->id, 'ordem' => 1,
            'codigo' => 'CDC-PR', 'eixo' => 'cor', 'valor' => 'Preto', 'custo' => 100, 'estoque' => 5]);
        EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 60, 'largura' => 60, 'altura' => 40, 'peso' => 12]);
        EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => 'CDC-PR', 'fase' => 'simples', 'nome' => 'Cadeira Executiva — Preto']);
        $this->salvarERodar($this->d->fresh());
        $this->pub = $this->pubDo($this->d);

        $this->ligarATrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Gravar ═══════════════════════════════════════════════════════════════

    public function test_grava_o_preco_de_todas_as_cores_so_no_rascunho_e_responde_a_previa_nova(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [207.19, 230.5]]);
        $r = $this->rascunho();
        $antes = (int) $r->revisao;
        $chave = $this->item();
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['etapa'], 'controle: na revisão');

        $j = $this->preco($chave, ['revisao' => $antes, 'precos' => ['classico' => 199.9], 'cor' => null])->assertOk()->json();

        $this->assertSame(self::CHAVES, array_keys($j), 'a prévia da §19.5 e os avisos');
        $this->assertSame([self::AZUL => ['gold_special' => 199.9, 'gold_pro' => null], self::PRETO => ['gold_special' => 199.9, 'gold_pro' => null]],
            $this->gravados(), 'o Clássico das duas cores no rascunho; o Premium segue herdando (nulo)');
        $this->assertSame($antes + 1, (int) $r->fresh()->revisao, 'a revisão sobe (a conferência anterior perde a validade)');
        $this->assertSame($antes + 1, $j['revisao']);
        $this->assertTrue($j['pronta']);
        $this->assertSame('revisao', $j['item']['etapa'], 'segue na revisão');
        $this->assertSame([
            'classico'      => ['valor' => 199.9, 'da_precificacao' => 207.19, 'minimo' => null, 'ajustado' => true],
            'premium'       => ['valor' => 230.5, 'da_precificacao' => 230.5, 'minimo' => null, 'ajustado' => false],
            'por_cor'       => false,
            'premium_igual' => false,
        ], $j['preco']);
        $this->assertSame([[self::AZUL, 'classico', 199.9], [self::AZUL, 'premium', 230.5], [self::PRETO, 'classico', 199.9], [self::PRETO, 'premium', 230.5]],
            $this->precosDosAnuncios($j));
        $this->assertSame([], $j['avisos']);
    }

    public function test_grava_o_preco_de_uma_cor_so_e_as_outras_ficam(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]]);
        $chave = $this->item();

        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 259.9], 'cor' => self::PRETO])->assertOk()->json();

        $this->assertSame([self::AZUL => ['gold_special' => null, 'gold_pro' => null], self::PRETO => ['gold_special' => null, 'gold_pro' => 259.9]], $this->gravados());
        $this->assertSame([[self::AZUL, 'classico', 207.19], [self::AZUL, 'premium', 230.5], [self::PRETO, 'classico', 217.19], [self::PRETO, 'premium', 259.9]],
            $this->precosDosAnuncios($j), 'o preço de cada cor segue em anuncios[].preco');
        // `valor` e `da_precificacao` são da 1ª cor ativa; `ajustado` olha todas.
        $this->assertSame(['valor' => 230.5, 'da_precificacao' => 230.5, 'minimo' => null, 'ajustado' => true], $j['preco']['premium']);
        $this->assertTrue($j['preco']['por_cor']);

        // Depois o Azul, só ele: o Preto não muda.
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199, 'premium' => 249.9], 'cor' => self::AZUL])->assertOk();
        $this->assertSame([self::AZUL => ['gold_special' => 199.0, 'gold_pro' => 249.9], self::PRETO => ['gold_special' => null, 'gold_pro' => 259.9]], $this->gravados());
    }

    public function test_null_volta_a_herdar_da_precificacao_e_o_pedido_que_nao_muda_nada_nao_grava(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]]);
        $chave = $this->item();
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199.9, 'premium' => 249.9], 'cor' => null])->assertOk();

        // "Voltar ao da Precificação" do Clássico: a linha fica nula, o Premium gravado fica.
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null], 'cor' => null])->assertOk()->json();
        $this->assertSame([self::AZUL => ['gold_special' => null, 'gold_pro' => 249.9], self::PRETO => ['gold_special' => null, 'gold_pro' => 249.9]], $this->gravados());
        $this->assertSame(['valor' => 207.19, 'da_precificacao' => 207.19, 'minimo' => null, 'ajustado' => false], $j['preco']['classico']);
        $this->assertSame([207.19, 217.19], array_values(array_map(fn ($a) => $a['preco'], array_filter($j['anuncios'], fn ($a) => $a['tipo'] === 'classico'))),
            'cada cor volta ao preço da Precificação DELA');
        $this->assertTrue($j['preco']['por_cor']);

        // O mesmo pedido de novo (e o mesmo valor já gravado): nada grava — nem a revisão sobe, nem o histórico cresce.
        $revisao = $this->revisao();
        $historico = $this->historico()->count();
        $this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => null], 'cor' => null])->assertOk();
        $this->preco($chave, ['revisao' => $revisao, 'precos' => ['premium' => 249.90], 'cor' => self::AZUL])->assertOk();
        $this->assertSame($revisao, $this->revisao());
        $this->assertSame($historico, $this->historico()->count());
    }

    // ═══ D46 — Premium igual ao Clássico ══════════════════════════════════════

    public function test_premium_igual_ao_classico_e_422_tambem_combinando_com_o_gravado_ou_o_herdado_do_outro_tipo(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]]);
        $chave = $this->item();
        $erro = fn (TestResponse $r) => $this->assertErro($r, 'precos.premium', PrecoNaTrilha::MSG_PREMIUM_IGUAL);
        $base = $this->gravados();

        // Os dois no mesmo pedido.
        $erro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 300, 'premium' => 300], 'cor' => null]));
        // O Clássico pedido × o Premium HERDADO da Precificação (o Azul: 230,50).
        $erro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 230.5], 'cor' => self::AZUL]));
        // O número como texto (o que um formulário mandaria) empata do mesmo jeito.
        $erro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => '207.19'], 'cor' => self::AZUL]));
        $this->assertSame($base, $this->gravados(), 'nada gravou');

        // O Premium GRAVADO no Preto (250) e depois o Clássico 250 em todas: empata no Preto → 422.
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 250], 'cor' => self::PRETO])->assertOk();
        $gravado = $this->gravados();
        $revisao = $this->revisao();
        $erro($this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => 250], 'cor' => null]));
        $this->assertSame($gravado, $this->gravados());
        $this->assertSame($revisao, $this->revisao(), 'o 422 não sobe a revisão');

        // Só as cores que o pedido toca: o Clássico 250 SÓ no Azul (o Premium dele é 230,50) passa, mesmo com o Preto…
        $this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => 250], 'cor' => self::AZUL])->assertOk();
        $this->assertSame(250.0, $this->gravados()[self::AZUL]['gold_special']);
        // …e o Premium do Azul igual ao Clássico gravado dele (250) não passa.
        $erro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 250], 'cor' => self::AZUL]));

        // "Voltar ao da Precificação" que empata com o GRAVADO do outro tipo: o Premium do Azul gravado em 207,19 (o Clássico
        // da Precificação dele) passa com o Clássico gravado em 199; o Clássico de volta à Precificação, não.
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 207.19, 'classico' => 199], 'cor' => self::AZUL])->assertOk();
        $erro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null], 'cor' => self::AZUL]));
        $this->assertSame(199.0, $this->gravados()[self::AZUL]['gold_special']);
    }

    /**
     * O empate que JÁ existia numa cor (aqui, vindo da própria Precificação do Preto) não impede de mexer na outra — senão
     * a equipe não conseguiria consertar cor por cor; a prévia avisa (`premium_igual`) até alguém consertar.
     */
    public function test_o_empate_que_ja_existia_numa_cor_nao_impede_de_mexer_na_outra_e_a_previa_avisa(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [240.5, 240.5]]);
        $chave = $this->item();
        $this->assertTrue($this->previa($chave)->assertOk()->json('preco.premium_igual'), 'controle: o Preto já empata');

        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199], 'cor' => self::AZUL])->assertOk()->json();
        $this->assertTrue($j['preco']['premium_igual'], 'o Preto segue empatado — a prévia avisa');
        // Em todas as cores, o Preto entra no pedido: o Clássico 199 desempata ele também.
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199], 'cor' => null])->assertOk()->json();
        $this->assertFalse($j['preco']['premium_igual']);
        // E voltar o Clássico do Preto à Precificação empata de novo: 422.
        $this->assertErro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null], 'cor' => self::PRETO]),
            'precos.premium', PrecoNaTrilha::MSG_PREMIUM_IGUAL);
    }

    public function test_com_o_premium_desligado_nao_ha_empate_e_o_premium_no_pedido_e_422(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [207.19, 230.5]]);
        DB::table('pub_rascunho_alvos')->where('rascunho_id', $this->rascunho()->id)->where('listing_type_id', 'gold_pro')->update(['ativo' => false]);
        $chave = $this->item();

        $this->assertErro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 260], 'cor' => null]), 'precos.premium', PrecoNaTrilha::MSG_TIPO_DESLIGADO);
        // `null` num tipo desligado também é 422 (o contrato: tipo desligado no pedido).
        $this->assertErro($this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => null], 'cor' => null]), 'precos.premium', PrecoNaTrilha::MSG_TIPO_DESLIGADO);
        $this->assertSame([self::AZUL => ['gold_special' => null, 'gold_pro' => null], self::PRETO => ['gold_special' => null, 'gold_pro' => null]], $this->gravados());

        // Sem o Premium no ar, o Clássico pode ser qualquer valor (o herdado do Premium não conta).
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 230.5], 'cor' => null])->assertOk()->json();
        $this->assertNull($j['preco']['premium']);
        $this->assertFalse($j['preco']['premium_igual']);
    }

    // ═══ 422 ══════════════════════════════════════════════════════════════════

    public function test_valor_invalido_cor_fora_do_anuncio_e_corpo_sem_tipo_sao_422_e_nada_grava(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $antes = $this->gravados();

        foreach ([0, -5, 'abc', 10.123, 99999999, true, ['x']] as $ruim) {
            $this->assertErro($this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => $ruim], 'cor' => null]), 'precos.classico', PrecoNaTrilha::MSG_VALOR);
        }
        $this->assertErro($this->preco($chave, ['revisao' => $revisao, 'precos' => ['premium' => '1.999'], 'cor' => null]), 'precos.premium', PrecoNaTrilha::MSG_VALOR);
        foreach ([[], ['outro' => 10], 'x', null] as $semTipo) {
            $this->assertErro($this->preco($chave, ['revisao' => $revisao, 'precos' => $semTipo, 'cor' => null]), 'precos', PrecoNaTrilha::MSG_SEM_TIPO);
        }
        foreach (['COLOR=id:1', '__single__', 12] as $cor) {
            $this->assertErro($this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => 199.9], 'cor' => $cor]), 'cor', PrecoNaTrilha::MSG_COR);
        }
        // A cor desativada no anúncio não é cor dele.
        DB::table('pub_variantes')->where('rascunho_id', $this->rascunho()->id)->where('combinacao_chave', self::PRETO)->update(['ativa' => false]);
        $this->assertErro($this->preco($chave, ['revisao' => $revisao, 'precos' => ['classico' => 199.9], 'cor' => self::PRETO]), 'cor', PrecoNaTrilha::MSG_COR);
        // Sem a revisão (ou fora da forma): não há como saber se a aba é velha.
        foreach ([null, 'x', 1.5] as $semRevisao) {
            $this->assertErro($this->preco($chave, ['revisao' => $semRevisao, 'precos' => ['classico' => 199.9], 'cor' => null]), 'revisao', PrecoNaTrilha::MSG_RECARREGUE);
        }

        $this->assertSame($antes, $this->gravados());
        $this->assertSame($revisao, $this->revisao());
        $this->assertSame(0, $this->historico()->count());
    }

    // ═══ 409 ══════════════════════════════════════════════════════════════════

    public function test_revisao_velha_e_409_sem_travado_com_a_revisao_atual_antes_do_422(): void
    {
        $chave = $this->item();
        $atual = $this->revisao();
        $antes = $this->gravados();

        foreach ([['classico' => 199.9], ['classico' => 'abc'], ['classico' => 300, 'premium' => 300]] as $precos) {
            $resposta = $this->preco($chave, ['revisao' => $atual - 1, 'precos' => $precos, 'cor' => null])->assertStatus(409);
            $this->assertSame(['revisao_atual' => $atual, 'message' => PrecoNaTrilha::MSG_REVISAO], $resposta->json(), 'sem `travado`: a caixa mantém o digitado');
        }
        // A revisão como texto (o que um formulário mandaria) também é conferida.
        $this->preco($chave, ['revisao' => (string) ($atual + 3), 'precos' => ['classico' => 199.9], 'cor' => null])->assertStatus(409)->assertJsonMissingPath('travado');

        $this->assertSame($antes, $this->gravados());
        $this->assertSame($atual, $this->revisao());
    }

    public function test_na_fila_publicando_publicado_ou_fora_da_revisao_e_409_sem_travado(): void
    {
        $chave = $this->item();
        $r = $this->rascunho();
        $revisao = $this->revisao();
        $antes = $this->gravados();
        $pedir = fn (?string $item = null) => $this->preco($item ?? $chave, ['revisao' => $revisao, 'precos' => ['classico' => 199.9], 'cor' => null]);

        // Na fila de publicação (agendado): o estado vem antes da revisão — com a revisão velha, a mensagem é a da fila.
        $this->agendarNaFila($this->pub);
        $this->assertSame(['message' => PrecoNaTrilha::MSG_NA_FILA], $pedir()->assertStatus(409)->json());
        $this->assertSame(['message' => PrecoNaTrilha::MSG_NA_FILA],
            $this->preco($chave, ['revisao' => $revisao - 1, 'precos' => ['classico' => 'abc']])->assertStatus(409)->json());
        DB::table('pub_fila_publicacao_itens')->delete();

        // Publicando agora (a linha ainda diz revisão), publicado e publicado em parte.
        foreach ([PubRascunho::PUBLISHING => PrecoNaTrilha::MSG_PUBLICANDO, PubRascunho::PUBLISHED => PrecoNaTrilha::MSG_PUBLICADO,
            PubRascunho::PARTIALLY_PUBLISHED => PrecoNaTrilha::MSG_PUBLICADO] as $status => $mensagem) {
            DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => $status]);
            $this->assertSame(['message' => $mensagem], $pedir()->assertStatus(409)->json(), $status);
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => PubRascunho::DRAFT]);

        // Fora da revisão: o produto sem anúncio no Publicador ainda, e o que não andou e espera a onda (a etapa da TELA).
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $naoAndou = $this->produtoCompleto($this->empresa, 'Vaso Parado', 'VP');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $naoAndou), PubRascunho::DRAFT, ['resumo' => [
            'revisao' => 1, 'validado' => true, 'bloqueios' => 0, 'versao' => EditorRascunhoService::VERSAO_DO_RESUMO,
        ]]);
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda 20'), "s-{$naoAndou['produto']->id}");
        foreach (["s-{$semPub['produto']->id}", "s-{$naoAndou['produto']->id}"] as $fora) {
            $this->assertSame(['message' => PrecoNaTrilha::MSG_FORA_DA_REVISAO],
                $this->preco($fora, ['revisao' => 1, 'precos' => ['classico' => 199.9], 'cor' => null])->assertStatus(409)->json(), $fora);
        }

        $this->assertSame($antes, $this->gravados());
        $this->assertSame($revisao, $this->revisao());
        $this->assertSame(0, $this->historico()->count());
        // E, saindo da fila, o mesmo pedido passa.
        $pedir()->assertOk();
    }

    // ═══ O que não muda ═══════════════════════════════════════════════════════

    /**
     * Com a Precificação DE VERDADE (sem dublê): gravar um preço, numa cor e em todas, e voltar à Precificação não escreve
     * em nenhuma tabela dela nem nas do cadastro — as linhas inteiras iguais antes e depois (contagem não pega UPDATE).
     */
    public function test_a_precificacao_e_o_cadastro_nao_sao_escritos(): void
    {
        foreach (EstruturaOferta::query()->where('company_id', $this->empresa->id)->get() as $oferta) {
            EstruturaPrecificacao::create(['oferta_id' => $oferta->id, 'custo' => 100, 'frete_classico' => 30, 'frete_premium' => 30]);
        }
        $chave = $this->item();
        $antes = $this->retratoDaPrecificacao();
        $daPrecificacao = $this->previa($chave)->assertOk()->json('preco.classico.da_precificacao');
        $this->assertNotNull($daPrecificacao, 'controle: a Precificação de verdade deu preço');

        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199.9], 'cor' => self::PRETO])->assertOk();
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 189.9, 'premium' => 499.9], 'cor' => null])->assertOk();
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null, 'premium' => null], 'cor' => null])->assertOk()->json();

        $this->assertSame($antes, $this->retratoDaPrecificacao());
        $this->assertSame($daPrecificacao, $j['preco']['classico']['valor'], 'de volta ao preço da Precificação');
        $this->assertSame([self::AZUL => ['gold_special' => null, 'gold_pro' => null], self::PRETO => ['gold_special' => null, 'gold_pro' => null]], $this->gravados());
    }

    public function test_o_historico_registra_o_evento_com_tipo_cor_de_para_e_o_ator_da_equipe(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]]);
        $chave = $this->item();

        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199.9, 'premium' => 249.9], 'cor' => null])->assertOk();
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => null], 'cor' => self::PRETO])->assertOk();

        $registros = $this->historico()->orderBy('id')->get();
        $this->assertCount(3, $registros, 'um por tipo que mudou');
        $r = $this->rascunho();
        foreach ($registros as $a) {
            $this->assertSame([$this->dev->id, User::class], [(int) $a->causer_id, $a->causer_type], 'o ator da equipe');
            $this->assertSame([PubRascunho::class, (int) $r->id], [$a->subject_type, (int) $a->subject_id]);
            $this->assertSame(['interno', (int) $this->empresa->id, $chave, (int) $this->pub->id], [$a->getExtraProperty('origem'),
                (int) $a->getExtraProperty('company_id'), $a->getExtraProperty('item'), (int) $a->getExtraProperty('pub_produto_id')]);
        }
        [$classico, $premium, $volta] = $registros;
        $this->assertSame(['classico', null, [self::AZUL => 207.19, self::PRETO => 217.19], 199.9],
            [$classico->getExtraProperty('tipo'), $classico->getExtraProperty('cor'), $classico->getExtraProperty('de'), $classico->getExtraProperty('para')]);
        $this->assertSame(['premium', null, [self::AZUL => 230.5, self::PRETO => 240.5], 249.9],
            [$premium->getExtraProperty('tipo'), $premium->getExtraProperty('cor'), $premium->getExtraProperty('de'), $premium->getExtraProperty('para')]);
        $this->assertSame(['premium', self::PRETO, [self::PRETO => 249.9], null],
            [$volta->getExtraProperty('tipo'), $volta->getExtraProperty('cor'), $volta->getExtraProperty('de'), $volta->getExtraProperty('para')]);
        $this->assertStringContainsString('Clássico', $classico->description);
        $this->assertStringContainsString('R$ 199,90', $classico->description);
        $this->assertStringContainsString('da Precificação', $volta->description);
        $this->assertSame((int) $r->revisao, (int) $volta->getExtraProperty('revisao'), 'a revisão que ficou');
    }

    public function test_abaixo_do_minimo_da_precificacao_e_aviso_e_grava(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]], ['az' => [180, 200], 'pr' => [185.5, 210]]);
        $chave = $this->item();

        // Todas as cores: o maior mínimo que o valor não alcança.
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 170, 'premium' => 205], 'cor' => null])->assertOk()->json();
        $this->assertSame(['Abaixo do mínimo da Precificação no Clássico (R$ 185,50).', 'Abaixo do mínimo da Precificação no Premium (R$ 210,00).'], $j['avisos']);
        $this->assertSame([self::AZUL => ['gold_special' => 170.0, 'gold_pro' => 205.0], self::PRETO => ['gold_special' => 170.0, 'gold_pro' => 205.0]], $this->gravados(), 'o aviso não bloqueia');
        // (O JSON tira o `.0`: 170 e 180 voltam inteiros.)
        $this->assertSame(['valor' => 170, 'da_precificacao' => 207.19, 'minimo' => 180, 'ajustado' => true], $j['preco']['classico']);

        // Uma cor: o mínimo DELA. O Premium 205 no Azul (mínimo 200) não avisa.
        $j = $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 182, 'premium' => 205], 'cor' => self::AZUL])->assertOk()->json();
        $this->assertSame([], $j['avisos']);
        // No mínimo exato não é "abaixo"; e `null` (de volta à Precificação) nunca avisa.
        $this->assertSame([], $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 185.5], 'cor' => self::PRETO])->assertOk()->json('avisos'));
        $this->assertSame([], $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null], 'cor' => null])->assertOk()->json('avisos'));
    }

    /**
     * Zero IA e zero ML (o cliente HTTP trocado por um que anota qualquer pedido, com a conta liberada e a modalidade dela
     * no cache, como na prévia), nenhum Job na fila e o editor do Publicador NÃO marcado como em uso.
     */
    public function test_nenhuma_chamada_http_nenhum_job_e_o_editor_nao_fica_em_uso(): void
    {
        $this->precificacao(['az' => [207.19, 230.5], 'pr' => [217.19, 240.5]]);
        MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'APP_USR-x', 'refresh_token' => 'TG-x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);
        ModalidadeDeEnvio::guardar((int) $this->empresa->id, 'drop_off', me1: false);
        $chave = $this->item();
        $pedidos = [];
        Http::swap(new FabricaHttp(app('events')));
        Http::preventStrayRequests();
        Http::fake(function (PedidoHttp $pedido) use (&$pedidos) {
            $pedidos[] = $pedido->method().' '.$pedido->url();

            return Http::response([], 500);
        });
        $jobs = collect(Queue::pushedJobs())->flatten(1)->count();

        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199.9], 'cor' => null])->assertOk();
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['premium' => 259.9], 'cor' => self::PRETO])->assertOk();
        $this->preco($chave, ['revisao' => $this->revisao(), 'precos' => ['classico' => null], 'cor' => null])->assertOk();

        $this->assertSame([], $pedidos, 'o preço chamou o ML');
        $this->assertSame($jobs, collect(Queue::pushedJobs())->flatten(1)->count(), 'o preço pôs Job na fila');
        $this->assertFalse(EditorEmUso::emUso((int) $this->pub->id));
        $this->assertFalse(EditorEmUso::editando((int) $this->pub->id));
    }

    // ═══ O item e o endereço ══════════════════════════════════════════════════

    /** O Kit N (`c-{p}-2`, cópia do rascunho da Simples) grava no rascunho DELE; o da Simples fica. */
    public function test_o_kit_n_grava_no_rascunho_dele(): void
    {
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => []]);
        $p = $this->produtoDoPortal('CAD');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'CAD-AZ-CB2', 'Combo 2 Cadeira', [[EstruturaOferta::query()->where('sku', 'CAD-AZ')->firstOrFail(), 2]]);
        $this->salvarERodar($p);
        $kitN = app(CriarFaseService::class)->criar($this->pubDo($p)->fresh(), ['quantidade' => 2]);
        $this->ligarATrilha();
        $daSimples = $this->gravados($this->rascunhoDo($p));
        $doKit = PubRascunho::query()->where('produto_id', $kitN->id)->firstOrFail();
        $chave = "c-{$p->id}-2";
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['etapa'], 'controle: o Combo 2 na revisão');

        $this->preco($chave, ['revisao' => (int) $doKit->revisao, 'precos' => ['classico' => 399.9], 'cor' => null])->assertOk();

        $this->assertSame([399.9], array_values(array_column($this->gravados($doKit), 'gold_special')));
        $this->assertSame($daSimples, $this->gravados($this->rascunhoDo($p)), 'a Simples não muda');
        $this->assertSame((int) $doKit->revisao + 1, (int) $doKit->fresh()->revisao);
    }

    public function test_o_endereco_da_escrita_leva_ao_preco_e_o_de_outra_loja_ou_que_nao_existe_e_404_antes_de_validar(): void
    {
        $this->withoutVite();
        $props = json_decode(json_encode($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk()->viewData('page')), true)['props'];
        $this->assertSame("/trilha/{$this->empresa->id}/ofertas/__ITEM__/preco", $props['escrita']['revisao']['preco']);
        $url = str_replace('__ITEM__', $this->item(), $props['escrita']['revisao']['preco']);
        $this->actingAs($this->dev)->putJson($url, ['revisao' => $this->revisao(), 'precos' => ['classico' => 199.9], 'cor' => null])->assertOk();

        // De outra loja (pelo endereço desta) e o que não existe: 404, com o corpo inválido de propósito.
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora));
        foreach (["s-{$deFora['produto']->id}", 's-999999', "c-{$this->d->id}-3", 'o-999999'] as $chave) {
            $this->preco($chave, ['precos' => 'x'])->assertNotFound();
        }
        $this->actingAs($this->dev)->putJson("/trilha/{$outra->id}/ofertas/{$this->item()}/preco", ['precos' => 'x'])->assertNotFound();
        // O GET não existe nesse endereço (só o PUT).
        $this->actingAs($this->dev)->getJson($url)->assertStatus(405);
    }

    // ─── apoio ───

    /** 422 com a mensagem no campo (`assertJsonPath` não alcança a chave com ponto, `precos.premium` — learnings §31). */
    private function assertErro(TestResponse $resposta, string $campo, string $mensagem): void
    {
        $resposta->assertUnprocessable();
        $this->assertSame($mensagem, $resposta->json('errors')[$campo][0] ?? null, (string) $resposta->getContent());
    }

    private function ligarATrilha(): void
    {
        // A marca de "agendado" do save (80 s) vence; e só agora a trilha liga (com ela o agente esperaria a onda).
        Carbon::setTestNow(now()->addMinutes(5));
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    private function item(): string
    {
        return "s-{$this->d->id}";
    }

    private function rascunho(): PubRascunho
    {
        return $this->rascunhoDo($this->d);
    }

    private function revisao(): int
    {
        return (int) $this->rascunho()->revisao;
    }

    private function preco(string $chave, array $corpo): TestResponse
    {
        return $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", $corpo);
    }

    private function previa(string $chave): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/previa");
    }

    /**
     * O que está GRAVADO em `pub_variante_precos` do rascunho: variante → listing_type_id → preço (nulo = herda).
     *
     * @return array<string, array<string, ?float>>
     */
    private function gravados(?PubRascunho $r = null): array
    {
        $r ??= $this->rascunho();
        $saida = [];
        foreach (DB::table('pub_variante_precos as p')->join('pub_variantes as v', 'v.id', '=', 'p.variante_id')->join('pub_rascunho_alvos as a', 'a.id', '=', 'p.alvo_id')
            ->where('v.rascunho_id', $r->id)->orderBy('v.posicao')->orderBy('a.posicao')->get(['v.combinacao_chave', 'a.listing_type_id', 'p.preco']) as $l) {
            $saida[$l->combinacao_chave][$l->listing_type_id] = $l->preco !== null ? (float) $l->preco : null;
        }

        return $saida;
    }

    /** @return list<array{0: string, 1: string, 2: ?float}> [variante, tipo, preço] de cada anúncio da prévia, por cor e tipo */
    private function precosDosAnuncios(array $j): array
    {
        $saida = array_map(fn (array $a) => [$a['variante'], $a['tipo'], $a['preco']], $j['anuncios']);
        usort($saida, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $saida;
    }

    private function historico()
    {
        return Activity::query()->where('properties->evento', 'trilha_preco_ajustado');
    }

    /** As tabelas da Precificação e do cadastro, linha a linha. */
    private function retratoDaPrecificacao(): array
    {
        $saida = [];
        foreach (['estrutura_precificacoes', 'estrutura_precificacao_parametros', 'estrutura_ofertas', 'estrutura_produtos', 'estrutura_produto_variacoes'] as $tabela) {
            $saida[$tabela] = DB::table($tabela)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();
        }

        return $saida;
    }

    /**
     * A Precificação fixa, por cor da cadeira (`az`/`pr` → [Clássico, Premium]), com o mínimo por cor. UM objeto, mudado no
     * lugar: o controller (e o que ele recebeu no construtor) é resolvido uma vez por rota no teste.
     *
     * @param  array{az: array{0: ?float, 1: ?float}, pr: array{0: ?float, 1: ?float}}  $precos
     * @param  array{az?: array{0: ?float, 1: ?float}, pr?: array{0: ?float, 1: ?float}}  $minimos
     */
    private function precificacao(array $precos, array $minimos = []): void
    {
        $porTipo = fn (?array $par) => ['gold_special' => $par[0] ?? null, 'gold_pro' => $par[1] ?? null];
        $fixos = [
            'titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => $porTipo($precos['az']),
            'promocoes' => $porTipo($minimos['az'] ?? null), 'sem_frete' => ['gold_special' => false, 'gold_pro' => false], 'mlbs' => [],
            'precos_por_variante'    => ['cdc-az' => $porTipo($precos['az']), 'cdc-pr' => $porTipo($precos['pr'])],
            'promocoes_por_variante' => ['cdc-az' => $porTipo($minimos['az'] ?? null), 'cdc-pr' => $porTipo($minimos['pr'] ?? null)],
            'sem_frete_por_variante' => ['cdc-az' => ['gold_special' => false, 'gold_pro' => false], 'cdc-pr' => ['gold_special' => false, 'gold_pro' => false]],
        ];
        if ($this->dubleDaPrecificacao !== null) {
            $this->dubleDaPrecificacao->fixos = $fixos;

            return;
        }
        $this->dubleDaPrecificacao = new class(app(EstruturaPrecificacaoService::class), $fixos) extends DadosEfetivosService
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
        $this->app->instance(DadosEfetivosService::class, $this->dubleDaPrecificacao);
    }
}
