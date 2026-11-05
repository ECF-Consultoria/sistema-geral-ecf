<?php

namespace Tests\Feature\Trilha;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob;
use App\Jobs\Trilha\PublicarPelaTrilhaJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Models\PortalUsuario;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\TrilhaAprovacao;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\PortalEquipeService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Trilha\AjusteDoRoboNaTrilha;
use App\Services\Trilha\AprovacaoDoClienteNaTrilha as Aprovacao;
use App\Services\Trilha\PrecoNaTrilha;
use App\Services\Trilha\PublicacaoNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\PortalContexto;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use App\Support\Trilha\AprovacaoDoCliente;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\EntraNoPortal;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A aprovação do CLIENTE por loja (4ª entrega, fatia 4e, 04/11/2026 — desenho §19.16, D52–D57): a equipe manda a prévia
 * ao cliente (`POST /trilha/{e}/ofertas/{item}/cliente`); ele a vê (`GET portal/{pe}/trilha/ofertas/{item}/previa`) e
 * aprova (`…/aprovar` — o anúncio segue para a fila pelo caminho da 4d, com quem mandou) ou pede ajuste (`…/ajuste` —
 * texto para a equipe, nunca para a IA).
 *
 * O cenário é o da 4d (`PublicarNaTrilhaTest`): a cadeira de DUAS cores preparada pelo agente de verdade, na revisão, na
 * loja 459, com foto enviada, preço gravado e a conta conectada; o Mercado Livre é um `Http::fake` só com o que a
 * conferência pergunta (um `POST /items` estoura) e a fila é de mentira. A chave da aprovação liga a 459 no `setUp`. O
 * cliente é um `PortalUsuario` da 459 na sessão do Portal. O que se prova:
 *
 * - **chave desligada → a 4d intacta** (nem endereço, nem `aprovacao`, nem rota do cliente);
 * - chave ligada: [Mandar ao cliente] cria a linha `com_cliente` e o `POST publicar` da EQUIPE dá 409;
 * - o cliente aprova → o Job de publicar entra com o `User` `enviado_por` (não o cliente, não o modo equipe); a guarda da
 *   #459 vale (a 460 com a chave ligada aprova, mas nada vai para a fila nem para o ML);
 * - D55: a revisão mudou depois do envio → 409 ao cliente e a linha volta para a equipe;
 * - o ajuste exige texto (422) e o texto NÃO chega a prompt nenhum (nem ao "Pedir ajuste ao agente" que vem depois);
 * - o cliente só vê a prévia do item que a equipe mandou, sem `preparo`, `bloqueios`, `ajuste`, `minimo`,
 *   `da_precificacao`; outra empresa → 404;
 * - modo equipe do Portal grava `decidido_por_equipe`; `enviado_por` inativo → "falta publicar" e o [Aprovar e publicar]
 *   volta para a equipe (D56);
 * - a Lista (status), o histórico (`activity_log` com o ator), a matriz de acesso do cliente (allowlist, CSRF,
 *   `portal.auth`, chaves) e a migration que sobe e desce.
 */
class AprovacaoDoClienteNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use EntraNoPortal;
    use MontaTrilha;
    use RefreshDatabase;

    private const AZUL = 'COLOR=id:52028';

    private const PRETO = 'COLOR=id:52049';

    /** A marca do texto do ajuste do cliente, que não pode chegar a prompt nenhum. */
    private const MARCA = 'QWERTY-PEDIDO-DO-CLIENTE-4E';

    /** As chaves da prévia na porta do CLIENTE (§19.16), na ordem. */
    private const PREVIA_DO_CLIENTE = ['item', 'pronta', 'motivo', 'revisao', 'categoria', 'tipos', 'variantes', 'anuncios', 'descricao', 'preco', 'publicacao', 'aprovacao', 'eixos', 'vendedor'];

    /** A `aprovacao` da EQUIPE: as do cliente + as que só a equipe lê. */
    private const APROVACAO_DA_EQUIPE = ['estado', 'rodada', 'enviado_em', 'pedido', 'motivo', 'enviado_por', 'decidido_em', 'decidido_por', 'mudou_depois', 'falta_publicar'];

    /** As palavras que o cliente nunca lê (D59) — e o antigo "robô", que virou "agente" (R9, 04/11/2026). */
    private const PROIBIDAS = ['mercado livre', 'publicador', 'portal', 'robô'];

    private EstruturaProduto $d;

    private PubProduto $pub;

    private User $dev;

    private PortalUsuario $cliente;

    /** @var \Closure(array): mixed o `/items/validate` agora */
    private \Closure $validate;

    private \SplObjectStorage $jobsRodados;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'trilha.aprovacao_cliente.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
            'publicador.contas_liberadas.todas' => false, 'publicador.contas_liberadas.companies' => [],
            'publicador.exigir_ficha_inteira' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarPreparo();
        // A loja da trilha É a 459: a única em que a trilha publica (a empresa do `montarPreparo` fica sem uso).
        $this->empresa = Company::factory()->create(['id' => PublicacaoNaTrilha::LOJA]);
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) {}));
        $this->jobsRodados = new \SplObjectStorage();

        $this->d = $this->cadeira($this->empresa, 'CDC');
        $this->pub = $this->pubDo($this->d);
        $this->ligarATrilha([$this->empresa->id]);
        $this->contaDaLoja($this->empresa);
        $this->prontoParaPublicar($this->d, ['az' => [199.9, 229.9], 'pr' => [209.9, 239.9]]);

        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
        $this->cliente = $this->clienteDoPortal($this->empresa, ['nome' => 'Joana da Loja']);

        $this->validate = fn (array $corpo) => Http::response(self::ml('conta/categorias/MLB193945/validate_base_up'), 400);
        $this->fakeMl();
        Queue::fake();
        // A chave da aprovação: só a 459.
        config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Chave desligada: a 4d intacta ════════════════════════════════════════

    public function test_chave_desligada_a_4d_fica_intacta_e_nada_da_aprovacao_aparece(): void
    {
        config(['trilha.aprovacao_cliente.empresas' => []]);
        $chave = $this->item();

        // Equipe: a `escrita.revisao` da 4b, sem `cliente`; a prévia com `aprovacao` nula; o "Mandar" é 409.
        $equipe = $this->props($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk());
        $this->assertSame(['previa', 'preco', 'publicar', 'publicacao', 'ajuste'], array_keys($equipe['escrita']['revisao']));
        $this->assertNull($this->previa()->json('aprovacao'));
        $this->assertSame(['message' => Aprovacao::MSG_DESLIGADA], $this->mandar(['revisao' => $this->revisao()])->assertStatus(409)->json());
        // Cliente: `cliente_escrita` como na 4f (só as fotos) e as rotas da aprovação não existem.
        $cliente = $this->props($this->comoCliente()->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $this->assertSame(['fotos' => "/portal/{$this->empresa->id}/trilha/produtos/__PRODUTO__/fotos"], $cliente['cliente_escrita']);
        $this->comoCliente()->getJson($this->urlCliente('previa'))->assertNotFound();
        $this->comoCliente()->get($this->urlCliente('fotos/'.$this->fotoComArquivo()->id))->assertNotFound();
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertNotFound();
        $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $this->revisao(), 'texto' => 'x'])->assertNotFound();
        $this->assertSame(0, TrilhaAprovacao::query()->count());

        // A 4d como era: a equipe publica direto.
        $this->publicar(['revisao' => $this->revisao()])->assertStatus(202);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);
        $this->assertStringStartsWith('Pré-pronto', $this->linha()['status'], 'a Lista da 4d (nenhum status da aprovação)');

        // Desligar DEPOIS de mandar: a linha fica no banco sem efeito — a prévia da equipe sem `aprovacao`, a Lista sem o
        // status, as rotas do cliente 404 (mesmo com a linha `com_cliente`).
        Queue::fake();
        Cache::forget(PublicacaoNaTrilha::chave((int) $this->pub->id));
        config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id]]);
        $this->mandar(['revisao' => $this->revisao()])->assertOk()->assertJsonPath('aprovacao.estado', 'com_cliente');
        $this->assertSame(Aprovacao::STATUS_COM_CLIENTE, $this->linha()['status']);
        config(['trilha.aprovacao_cliente.empresas' => []]);
        $this->assertNull($this->previa()->json('aprovacao'));
        $this->assertStringStartsWith('Pré-pronto', $this->linha()['status']);
        $this->comoCliente()->getJson($this->urlCliente('previa'))->assertNotFound();
        $this->comoCliente()->get($this->urlCliente('fotos/'.$this->fotoComArquivo()->id))->assertNotFound();
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertNotFound();
        $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $this->revisao(), 'texto' => 'x'])->assertNotFound();
        $this->assertSame('com_cliente', TrilhaAprovacao::query()->sole()->estado);
        Queue::assertNothingPushed();

        // A chave sozinha não liga: precisa da trilha de trabalho (e esta, da trilha).
        config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id, 460], 'trilha.trabalho.empresas' => []]);
        $this->assertSame([], AprovacaoDoCliente::empresas());
        config(['trilha.trabalho.empresas' => [$this->empresa->id, 460]]);
        $this->assertSame([PublicacaoNaTrilha::LOJA], AprovacaoDoCliente::empresas(), 'a 460 fora de `trilha.empresas`');
        config(['trilha.aprovacao_cliente.empresas' => ['459', ' 459 ', 'abc', 0, -1, null]]);
        $this->assertTrue(AprovacaoDoCliente::ligada($this->empresa));
        $this->assertFalse(AprovacaoDoCliente::ligada(null));
    }

    // ═══ Mandar ao cliente ════════════════════════════════════════════════════

    public function test_mandar_ao_cliente_cria_a_linha_com_cliente_e_o_publicar_da_equipe_da_409(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $equipe = $this->props($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk());
        $this->assertSame("/trilha/{$this->empresa->id}/ofertas/__ITEM__/cliente", $equipe['escrita']['revisao']['cliente']);
        $this->assertNull($this->previa()->json('aprovacao'), 'nunca mandou');

        $j = $this->mandar(['revisao' => $revisao])->assertOk()->json();

        // A resposta é a prévia nova, com a `aprovacao` da equipe.
        $this->assertTrue($j['pronta']);
        $this->assertSame(self::APROVACAO_DA_EQUIPE, array_keys($j['aprovacao']));
        $this->assertSame(['com_cliente', 1, null, null, 'Rafael', null, null, false, false], [$j['aprovacao']['estado'], $j['aprovacao']['rodada'],
            $j['aprovacao']['pedido'], $j['aprovacao']['motivo'], $j['aprovacao']['enviado_por'], $j['aprovacao']['decidido_em'],
            $j['aprovacao']['decidido_por'], $j['aprovacao']['mudou_depois'], $j['aprovacao']['falta_publicar']]);
        $this->assertSame(now()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(), $j['aprovacao']['enviado_em']);
        $this->assertSame(Aprovacao::STATUS_COM_CLIENTE, $j['item']['status']);
        $this->assertSame($j['aprovacao'], $this->previa()->json('aprovacao'), 'o GET da prévia diz o mesmo');
        // A resposta é a prévia INTEIRA (a forma §19.5 da equipe, com os bastidores dela) — as mesmas chaves do GET.
        $this->assertSame(array_keys($this->previa()->json()), array_keys($j));
        foreach (['item', 'pronta', 'revisao', 'anuncios', 'preco', 'preparo', 'publicacao', 'ajuste', 'ajustes_restantes_hoje', 'aprovacao', 'bloqueios'] as $chaveDaPrevia) {
            $this->assertArrayHasKey($chaveDaPrevia, $j);
        }
        $this->assertNotSame([], $j['anuncios']);

        $linha = TrilhaAprovacao::query()->sole();
        $this->assertSame([PublicacaoNaTrilha::LOJA, (int) $this->pub->id, $chave, 'com_cliente', 1, $revisao, (int) $this->dev->id, null, null, null, null],
            [$linha->company_id, $linha->pub_produto_id, $linha->item_chave, $linha->estado, $linha->rodada, $linha->revisao_enviada,
                $linha->enviado_por, $linha->decidido_em, $linha->decidido_por_cliente, $linha->decidido_por_equipe, $linha->pedido]);
        $evento = $this->eventos('trilha_aprovacao_enviada')->sole();
        $this->assertSame([User::class, (int) $this->dev->id, 'interno', $chave, 1, $revisao], [$evento->causer_type, (int) $evento->causer_id,
            $evento->getExtraProperty('origem'), $evento->getExtraProperty('item'), $evento->getExtraProperty('rodada'), $evento->getExtraProperty('revisao')]);
        $this->assertSame([], Http::recorded()->all(), 'mandar não fala com o ML');

        // A Lista (as duas portas): na revisão, "Com o cliente para aprovar", sem alerta.
        $this->assertSame(['revisao', Aprovacao::STATUS_COM_CLIENTE, false], [$this->linha()['etapa'], $this->linha()['status'], $this->linha()['alerta']]);
        $doCliente = collect($this->props($this->comoCliente()->get("/portal/{$this->empresa->id}/trilha")->assertOk())['ofertas'])->firstWhere('id', $chave);
        $this->assertSame([Aprovacao::STATUS_COM_CLIENTE, false], [$doCliente['status'], $doCliente['alerta']]);

        // Com a chave, a equipe NÃO publica direto (a aprovação não valeria nada): 409, e nada na fila.
        $this->assertSame(['message' => Aprovacao::MSG_APROVA_COM_CLIENTE], $this->publicar(['revisao' => $revisao, 'ciente' => true])->assertStatus(409)->json());
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        $this->assertSame(0, $this->eventos('trilha_publicacao_pedida')->count());

        // Mandar de novo a MESMA revisão: 409 (já está com ele).
        $this->assertSame(['message' => Aprovacao::MSG_JA_COM_CLIENTE], $this->mandar(['revisao' => $revisao])->assertStatus(409)->json());
        $this->assertSame(1, TrilhaAprovacao::query()->sole()->rodada);
    }

    /** A guarda do "Mandar", na ordem do contrato: 409 do estado, agente, revisão velha (sem `travado`), 422 da forma e do D46. */
    public function test_mandar_recusa_o_que_nao_esta_pronto_para_o_cliente(): void
    {
        // São mais pedidos que o teto de 10/min da rota (o teto é conferido em {@see test_cada_rota_tem_o_seu_teto()}).
        $this->withoutMiddleware(ThrottleRequests::class);
        $chave = $this->item();
        $r = $this->rascunho();
        $revisao = $this->revisao();

        // Revisão velha: 409 SEM `travado`, com a atual; ausente ou fora da forma: 422.
        $this->assertSame(['revisao_atual' => $revisao, 'message' => PrecoNaTrilha::MSG_REVISAO], $this->mandar(['revisao' => $revisao - 1])->assertStatus(409)->json());
        foreach ([null, 'x', 1.5] as $ruim) {
            $this->assertErro($this->mandar(['revisao' => $ruim]), 'revisao', PrecoNaTrilha::MSG_RECARREGUE);
        }
        // Premium = Clássico em QUALQUER cor ativa (o cliente não aprova o que não publica): 422.
        $this->precos($this->d, ['pr' => [239.9, 239.9]]);
        $this->assertErro($this->mandar(['revisao' => $this->revisao()]), 'precos.premium', PrecoNaTrilha::MSG_PREMIUM_IGUAL);
        $this->precos($this->d, ['pr' => [209.9, 239.9]]);
        // O agente refazendo o texto (4b): 409.
        Cache::put(AjusteDoRoboNaTrilha::chave((int) $this->pub->id), ['pedido' => 'p-1', 'situacao' => 'fila',
            'partes' => ['titulo' => ['situacao' => 'fila']], 'em' => now()->toIso8601String()], 600);
        $this->assertSame(['message' => Aprovacao::MSG_AJUSTE_DO_ROBO], $this->mandar(['revisao' => $this->revisao()])->assertStatus(409)->json());
        Cache::forget(AjusteDoRoboNaTrilha::chave((int) $this->pub->id));
        // Na fila, publicando, publicado: 409 (o estado vem antes da revisão).
        $this->agendarNaFila($this->pub);
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_NA_FILA_409], $this->mandar(['revisao' => 0])->assertStatus(409)->json());
        DB::table('pub_fila_publicacao_itens')->delete();
        foreach ([PubRascunho::PUBLISHING => PublicacaoNaTrilha::MSG_PUBLICANDO_409, PubRascunho::PUBLISHED => PublicacaoNaTrilha::MSG_PUBLICADO_409] as $status => $msg) {
            DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => $status]);
            $this->assertSame(['message' => $msg], $this->mandar(['revisao' => $this->revisao()])->assertStatus(409)->json(), $status);
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => PubRascunho::DRAFT]);
        // Fora da revisão (o produto sem anúncio ainda) e a prévia não pronta (sem categoria): 409.
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $this->assertSame(['message' => Aprovacao::MSG_FORA_DA_REVISAO], $this->mandar(['revisao' => 1], "s-{$semPub['produto']->id}")->assertStatus(409)->json());
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['categoria_id' => null]);
        $this->assertSame(['message' => 'O anúncio ainda está sem categoria.'], $this->mandar(['revisao' => $this->revisao()])->assertStatus(409)->json());

        $this->assertSame(0, TrilhaAprovacao::query()->count());
        $this->assertSame(0, $this->eventos('trilha_aprovacao_enviada')->count());
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['etapa'], 'controle: segue na revisão');
    }

    // ═══ O cliente aprova ═════════════════════════════════════════════════════

    public function test_o_cliente_aprova_e_o_job_de_publicar_entra_com_quem_mandou(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();

        $j = $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertOk()->json();

        $this->assertSame(['aprovacao', 'publicacao'], array_keys($j));
        $this->assertSame(['estado' => 'aprovado', 'rodada' => 1, 'enviado_em' => $j['aprovacao']['enviado_em'], 'pedido' => null], $j['aprovacao']);
        $this->assertSame(['situacao' => 'conferindo', 'mensagem' => Aprovacao::MSG_CLIENTE_APROVADO], $j['publicacao']);
        // O MESMO Job da 4d, na `high`, com o `User` que MANDOU (não o cliente) e `ciente: false` (avisos voltam à equipe).
        Queue::assertPushedOn('high', PublicarPelaTrilhaJob::class, fn (PublicarPelaTrilhaJob $job) => [$job->companyId, $job->item, $job->pubProdutoId, $job->userId, $job->revisao, $job->ciente]
            === [PublicacaoNaTrilha::LOJA, $chave, (int) $this->pub->id, (int) $this->dev->id, $revisao, false]);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);

        $linha = TrilhaAprovacao::query()->sole();
        $this->assertSame(['aprovado', (int) $this->cliente->id, null], [$linha->estado, $linha->decidido_por_cliente, $linha->decidido_por_equipe]);
        $this->assertNotNull($linha->decidido_em);
        // O histórico: quem aprovou foi o CLIENTE; quem publica (o ator da fila) é quem mandou.
        $aprovou = $this->eventos('trilha_aprovacao_aprovada')->sole();
        $this->assertSame([PortalUsuario::class, (int) $this->cliente->id, 'cliente', $chave], [$aprovou->causer_type, (int) $aprovou->causer_id,
            $aprovou->getExtraProperty('origem'), $aprovou->getExtraProperty('item')]);
        $pediu = $this->eventos('trilha_publicacao_pedida')->sole();
        $this->assertSame([User::class, (int) $this->dev->id, 'aprovacao_do_cliente'], [$pediu->causer_type, (int) $pediu->causer_id, $pediu->getExtraProperty('via')]);

        // Conferindo: a Lista e a prévia de cada porta.
        $this->assertSame([Aprovacao::STATUS_APROVADO.' · conferindo', false], [$this->linha()['status'], $this->linha()['alerta']]);
        $this->assertSame('conferindo', $this->previa()->json('publicacao.situacao'));

        // O Job roda (a conferência com o ML falso): na fila, com quem mandou como o ator das publicações.
        $this->assertSame(['na_fila'], $this->rodarOsJobs());
        $item = PubFilaPublicacaoItem::query()->sole();
        $this->assertSame((int) $this->dev->id, (int) $item->fila->criada_por);
        $this->assertSame([Aprovacao::STATUS_APROVADO.' · na fila', false], [$this->linha()['status'], $this->linha()['alerta']]);
        $this->assertSame(['situacao' => 'na_fila', 'mensagem' => Aprovacao::MSG_CLIENTE_NA_FILA], $this->previaDoCliente()->json('publicacao'));
        $this->assertNenhumPostDeItem();
        // Publicando: a régua de sempre ("Publicando agora" — o combo lê esse texto para saber se a Simples está pronta).
        $item->update(['status' => PubFilaPublicacaoItem::PUBLICANDO]);
        DB::table('pub_rascunhos')->where('id', $this->rascunho()->id)->update(['status' => PubRascunho::PUBLISHING]);
        $this->assertSame(['revisao', 'Publicando agora'], [$this->linha()['etapa'], $this->linha()['status']]);
        $this->assertSame(['situacao' => 'publicando', 'mensagem' => PublicacaoNaTrilha::MSG_PUBLICANDO], $this->previaDoCliente()->json('publicacao'));
        $item->update(['status' => PubFilaPublicacaoItem::AGENDADO]);
        DB::table('pub_rascunhos')->where('id', $this->rascunho()->id)->update(['status' => PubRascunho::DRAFT]);

        // Aprovar de novo: não está mais com ele (409, com a aprovação de agora); nada novo na fila.
        $this->assertSame(['message' => Aprovacao::MSG_NAO_ESTA_COM_VOCE, 'aprovacao' => $j['aprovacao']],
            $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertStatus(409)->json());
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);
    }

    /**
     * D48 vale na aprovação do cliente: a 460 com a chave da aprovação ligada e TUDO pronto — a equipe manda, o cliente
     * aprova (a decisão dele fica gravada), mas nada vai para a fila nem para o ML; a equipe vê "falta publicar" e o
     * "Aprovar e publicar" dela continua 403.
     */
    public function test_a_guarda_da_459_vale_na_aprovacao_do_cliente(): void
    {
        $outra = Company::factory()->create(['id' => 460]);
        $e = $this->cadeira($outra, 'CDX');
        $this->ligarATrilha([$this->empresa->id, $outra->id]);
        config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id, $outra->id]]);
        $this->contaDaLoja($outra);
        $this->prontoParaPublicar($e, ['az' => [199.9, 229.9], 'pr' => [209.9, 239.9]]);
        $chave = "s-{$e->id}";
        $rev = (int) $this->rascunhoDo($e)->revisao;
        Queue::fake();
        $this->fakeMl();
        $this->actingAs($this->dev)->postJson("/trilha/{$outra->id}/ofertas/{$chave}/cliente", ['revisao' => $rev])->assertOk()
            ->assertJsonPath('aprovacao.estado', 'com_cliente');
        $doOutro = $this->clienteDoPortal($outra);
        // Daqui em diante, nada pode ir para o ML (a prévia do "Mandar" pode ter lido a conta).
        $this->fakeMl();

        $j = $this->outroCliente($outra, $doOutro)->postJson("/portal/{$outra->id}/trilha/ofertas/{$chave}/aprovar", ['revisao' => $rev])->assertOk()->json();

        $this->assertSame(['aprovado', null], [$j['aprovacao']['estado'], $j['publicacao']]);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        $this->assertSame([], Http::recorded()->all(), 'falou com o ML');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());
        $this->assertNull(Cache::get(PublicacaoNaTrilha::chave((int) $this->pubDo($e)->id)));
        $this->assertSame(0, $this->eventos('trilha_publicacao_pedida')->count());
        // A equipe: "falta publicar", com alerta — e o publicar dela é 403 (a D48 vem antes da regra da aprovação).
        $linha = $this->itemDaTrilha(app(TrilhaDaEmpresa::class)->montar($outra), $chave);
        $this->assertSame([Aprovacao::STATUS_APROVADO.' · falta publicar', true], [$linha['status'], $linha['alerta']]);
        $this->assertTrue($this->actingAs($this->dev)->getJson("/trilha/{$outra->id}/ofertas/{$chave}/previa")->json('aprovacao.falta_publicar'));
        $this->actingAs($this->dev)->postJson("/trilha/{$outra->id}/ofertas/{$chave}/publicar", ['revisao' => $rev])->assertForbidden()
            ->assertExactJson(['message' => PublicacaoNaTrilha::MSG_LOJA]);
        Queue::assertNothingPushed();

        // Controle: a 459, no mesmo teste, aprova E publica.
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertOk()->assertJsonPath('publicacao.situacao', 'conferindo');
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);
    }

    // ═══ D55 ══════════════════════════════════════════════════════════════════

    /**
     * A equipe mexe no anúncio DEPOIS de enviar (o preço da 4c): o cliente que aprovar recebe 409 — mesmo mandando a
     * revisão de agora (o que vale é a ENVIADA) — e a linha volta para a equipe (`ajuste`, `pedido` nulo). Nada vai para a
     * fila. A equipe manda de novo (rodada 2) e aí ele aprova.
     */
    public function test_revisao_mudada_depois_do_envio_e_409_para_o_cliente_e_a_linha_volta_para_a_equipe(): void
    {
        $chave = $this->item();
        $enviada = $this->revisao();
        $this->mandar(['revisao' => $enviada])->assertOk();
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", ['revisao' => $enviada,
            'precos' => ['classico' => 189.9], 'cor' => null])->assertOk();
        $agora = $this->revisao();
        $this->assertGreaterThan($enviada, $agora);
        // A equipe vê que mudou depois de enviar; a Lista segue "com o cliente" até ele agir.
        $this->assertSame(['com_cliente', true], [$this->previa()->json('aprovacao.estado'), $this->previa()->json('aprovacao.mudou_depois')]);
        // O cliente vê o anúncio de AGORA (é o que ele aprovaria) e manda a revisão dele.
        $this->assertSame($agora, $this->previaDoCliente()->json('revisao'));

        $j = $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $agora])->assertStatus(409)->json();

        $this->assertSame(Aprovacao::MSG_MUDOU_DEPOIS_DE_ENVIAR, $j['message']);
        $this->assertSame(['estado' => 'ajuste', 'pedido' => null], ['estado' => $j['aprovacao']['estado'], 'pedido' => $j['aprovacao']['pedido']]);
        $this->assertArrayNotHasKey('travado', $j);
        $linha = TrilhaAprovacao::query()->sole();
        $this->assertSame(['ajuste', null, null, null, null], [$linha->estado, $linha->pedido, $linha->decidido_em, $linha->decidido_por_cliente, $linha->decidido_por_equipe]);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        $voltou = $this->eventos('trilha_aprovacao_voltou')->sole();
        $this->assertSame([$enviada, $agora, PortalUsuario::class], [(int) $voltou->getExtraProperty('revisao_enviada'), (int) $voltou->getExtraProperty('revisao_atual'), $voltou->causer_type]);
        // A equipe: "Em ajuste pela equipe" (o cliente não pediu nada), com alerta, e o motivo na caixa.
        $this->assertSame([Aprovacao::STATUS_EM_AJUSTE, true], [$this->linha()['status'], $this->linha()['alerta']]);
        $this->assertSame(['ajuste', Aprovacao::MOTIVO_EQUIPE_MUDOU, false], [$this->previa()->json('aprovacao.estado'),
            $this->previa()->json('aprovacao.motivo'), $this->previa()->json('aprovacao.mudou_depois')]);
        // De novo: não está mais com ele.
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $enviada])->assertStatus(409)->assertJsonPath('message', Aprovacao::MSG_NAO_ESTA_COM_VOCE);

        // A equipe manda de novo: rodada 2, a revisão de agora; o cliente aprova.
        $this->mandar(['revisao' => $agora])->assertOk()->assertJsonPath('aprovacao.rodada', 2);
        $this->assertSame([2, $agora, 'com_cliente', null], [TrilhaAprovacao::query()->sole()->rodada, TrilhaAprovacao::query()->sole()->revisao_enviada,
            TrilhaAprovacao::query()->sole()->estado, TrilhaAprovacao::query()->sole()->decidido_em]);
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $agora])->assertOk()->assertJsonPath('aprovacao.estado', 'aprovado');
        Queue::assertPushed(PublicarPelaTrilhaJob::class, fn (PublicarPelaTrilhaJob $job) => $job->revisao === $agora);
    }

    /**
     * Com a linha `com_cliente` e o anúncio mudado depois do envio, a equipe NÃO precisa esperar o cliente: o "Mandar de
     * novo" vale (rodada + 1). E a aba velha do cliente (a revisão da rodada 1) leva 409 com a revisão certa, sem mudar nada.
     */
    public function test_a_equipe_manda_de_novo_sem_esperar_e_a_aba_velha_do_cliente_e_409(): void
    {
        $chave = $this->item();
        $enviada = $this->revisao();
        $this->mandar(['revisao' => $enviada])->assertOk();
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", ['revisao' => $enviada,
            'precos' => ['classico' => 189.9], 'cor' => null])->assertOk();

        $this->assertTrue($this->previa()->json('aprovacao.mudou_depois'), 'controle: mudou depois de mandar');
        $this->mandar(['revisao' => $this->revisao()])->assertOk()->assertJsonPath('aprovacao.rodada', 2)->assertJsonPath('aprovacao.mudou_depois', false)
            ->assertJsonPath('aprovacao.estado', 'com_cliente');
        $this->assertSame([2, $this->revisao(), 'com_cliente'], [TrilhaAprovacao::query()->sole()->rodada, TrilhaAprovacao::query()->sole()->revisao_enviada,
            TrilhaAprovacao::query()->sole()->estado], 'a nova rodada leva a revisão de agora');
        // E sem mudança de novo: "já está com o cliente".
        $this->assertSame(['message' => Aprovacao::MSG_JA_COM_CLIENTE], $this->mandar(['revisao' => $this->revisao()])->assertStatus(409)->json());

        $velha = $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $enviada])->assertStatus(409)->json();
        $this->assertSame(['revisao_atual', 'message', 'aprovacao'], array_keys($velha));
        $this->assertSame([$this->revisao(), PrecoNaTrilha::MSG_REVISAO, 'com_cliente', 2], [$velha['revisao_atual'], $velha['message'],
            $velha['aprovacao']['estado'], $velha['aprovacao']['rodada']]);
        $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $enviada, 'texto' => 'x'])->assertStatus(409)
            ->assertJsonPath('aprovacao.estado', 'com_cliente');
        $this->assertErro($this->comoCliente()->postJson($this->urlCliente('aprovar'), []), 'revisao', PrecoNaTrilha::MSG_RECARREGUE);
        $this->assertSame('com_cliente', TrilhaAprovacao::query()->sole()->estado);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
    }

    /**
     * O contrato com a tela: ela lê o estado da linha da Lista pelo PREFIXO do status — "Com o cliente", "O cliente pediu
     * ajuste", "Em ajuste pela equipe" (D55) e "Aprovado pelo cliente". E os 409 do cliente trazem a `aprovacao` dele (a
     * caixa mostra o estado de agora): a equipe mexendo agora e o anúncio que já seguiu para a publicação.
     */
    public function test_os_prefixos_da_lista_e_os_409_do_cliente_com_a_aprovacao(): void
    {
        $this->assertStringStartsWith('Com o cliente', Aprovacao::STATUS_COM_CLIENTE);
        $this->assertStringStartsWith('O cliente pediu ajuste', Aprovacao::STATUS_PEDIU_AJUSTE);
        $this->assertStringStartsWith('Em ajuste pela equipe', Aprovacao::STATUS_EM_AJUSTE);
        $this->assertStringStartsWith('Aprovado pelo cliente', Aprovacao::STATUS_APROVADO);
        $this->assertSame('Aprovado pelo cliente · falta publicar', Aprovacao::STATUS_APROVADO.' · '.Aprovacao::SUFIXO_FALTA_PUBLICAR);

        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();
        $this->assertStringStartsWith('Com o cliente', $this->linha()['status']);
        // A equipe pediu ajuste ao agente depois de mandar e ele ainda não gravou: 409, nada muda.
        Cache::put(AjusteDoRoboNaTrilha::chave((int) $this->pub->id), ['pedido' => 'p-1', 'situacao' => 'fazendo',
            'partes' => ['titulo' => ['situacao' => 'fazendo']], 'em' => now()->toIso8601String()], 600);
        $this->assertSame(['message' => Aprovacao::MSG_EQUIPE_MEXENDO, 'aprovacao' => ['estado' => 'com_cliente', 'rodada' => 1,
            'enviado_em' => now()->setTimezone(TrilhaOnda::FUSO)->toIso8601String(), 'pedido' => null]],
            $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertStatus(409)->json());
        Cache::forget(AjusteDoRoboNaTrilha::chave((int) $this->pub->id));
        // Agendado na fila por fora (o Publicador): já seguiu — 409 com a aprovação; nada muda.
        $this->agendarNaFila($this->pub);
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertStatus(409)
            ->assertJsonPath('message', Aprovacao::MSG_JA_SEGUIU)->assertJsonPath('aprovacao.estado', 'com_cliente');
        $this->assertSame(['com_cliente', null], [TrilhaAprovacao::query()->sole()->estado, TrilhaAprovacao::query()->sole()->decidido_em]);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
    }

    // ═══ O ajuste do cliente ══════════════════════════════════════════════════

    /**
     * O "Pedir ajuste" exige texto (1 a 1000) e o grava para a equipe — e para mais lugar nenhum: depois dele a equipe pede
     * ajuste ao agente (4b, IA falsa que anota), e a marca do texto não aparece em NENHUM argumento de NENHUMA chamada à IA,
     * em Job nenhum nem no estado do ajuste.
     */
    public function test_o_ajuste_do_cliente_exige_texto_e_nao_chega_a_prompt_nenhum(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $chave = $this->item();
        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();

        foreach ([[], ['texto' => ''], ['texto' => '   '], ['texto' => ['x']], ['texto' => 12]] as $ruim) {
            $this->assertErro($this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $revisao, ...$ruim]), 'texto', Aprovacao::MSG_TEXTO);
        }
        $this->assertErro($this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $revisao, 'texto' => str_repeat('á', 1001)]), 'texto', Aprovacao::MSG_TEXTO_LONGO);
        $this->assertErro($this->comoCliente()->postJson($this->urlCliente('ajuste'), ['texto' => 'Troque a foto']), 'revisao', PrecoNaTrilha::MSG_RECARREGUE);
        // Sem `Accept` de JSON: o 422 continua em JSON, com a `message` = o 1º erro.
        $this->comoCliente()->post($this->urlCliente('ajuste'), ['revisao' => $revisao])->assertStatus(422)->assertJsonPath('message', Aprovacao::MSG_TEXTO);
        $this->assertSame('com_cliente', TrilhaAprovacao::query()->sole()->estado, 'nada mudou');

        $texto = 'Mudem a cor do título para azul. '.self::MARCA.' '.str_repeat('é', 900);
        $j = $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $revisao, 'texto' => "  {$texto}  "])->assertOk()->json();

        $this->assertSame(['aprovacao'], array_keys($j));
        $this->assertSame(['estado' => 'ajuste', 'rodada' => 1, 'enviado_em' => $j['aprovacao']['enviado_em'], 'pedido' => $texto], $j['aprovacao']);
        $this->assertSame(['ajuste', $texto, (int) $this->cliente->id], [TrilhaAprovacao::query()->sole()->estado, TrilhaAprovacao::query()->sole()->pedido,
            TrilhaAprovacao::query()->sole()->decidido_por_cliente]);
        $evento = $this->eventos('trilha_aprovacao_ajuste_pedido')->sole();
        $this->assertSame([PortalUsuario::class, 'cliente', $texto], [$evento->causer_type, $evento->getExtraProperty('origem'), $evento->getExtraProperty('pedido')]);
        $this->assertSame([Aprovacao::STATUS_PEDIU_AJUSTE, true], [$this->linha()['status'], $this->linha()['alerta']]);
        $this->assertSame([$texto, null], [$this->previa()->json('aprovacao.pedido'), $this->previa()->json('aprovacao.motivo')]);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        // Pedido de novo: não está mais com ele.
        $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $revisao, 'texto' => 'mais'])->assertStatus(409);

        // A equipe pede ajuste ao agente (título e descrição) — a IA falsa anota tudo o que recebe.
        $chamadas = [];
        $this->mock(AnaliseAnuncioService::class, function (MockInterface $m) use (&$chamadas) {
            $m->shouldReceive('comPrazo')->andReturnSelf();
            foreach (['titulosPorTermos' => ['dados' => ['classico' => 'Cadeira Nova Clássico Ergonômica', 'premium' => 'Cadeira Nova Premium Ergonômica'], 'meta' => []],
                'modeloPorTermos' => ['dados' => 'cadeira nova', 'meta' => []], 'analise' => ['dados' => ['jtbd' => 'x', 'puv' => 'y'], 'meta' => []],
                'descricao' => ['dados' => '<p>Cadeira nova.</p>', 'meta' => []]] as $metodo => $volta) {
                $m->shouldReceive($metodo)->andReturnUsing(function (...$a) use (&$chamadas, $metodo, $volta) {
                    $chamadas[] = [$metodo, $a];

                    return $volta;
                });
            }
        });
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/ajuste", ['revisao' => $this->revisao(),
            'refazer' => ['titulo', 'descricao']])->assertStatus(202);
        foreach (Queue::pushed(AjustarPeloRoboNaTrilhaJob::class) as $job) {
            $job->handle(app(AjusteDoRoboNaTrilha::class));
        }

        $this->assertNotSame([], $chamadas, 'controle: a IA foi chamada');
        $this->assertStringNotContainsString(self::MARCA, serialize($chamadas), 'o texto do cliente chegou à IA');
        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $empurrado) {
                $this->assertStringNotContainsString(self::MARCA, serialize($empurrado['job']));
            }
        }
        $this->assertStringNotContainsString(self::MARCA, (string) json_encode(Cache::get(AjusteDoRoboNaTrilha::chave((int) $this->pub->id))));
        $this->assertStringNotContainsString(self::MARCA, (string) json_encode($this->rascunho()->fresh()->only(['titulos', 'descricao', 'step_state'])));
        // Mandar de novo: rodada 2, sem o pedido antigo na linha (ele fica no histórico).
        $this->mandar(['revisao' => $this->revisao()])->assertOk()->assertJsonPath('aprovacao.pedido', null)->assertJsonPath('aprovacao.rodada', 2);
        $this->assertSame($texto, $this->eventos('trilha_aprovacao_ajuste_pedido')->sole()->getExtraProperty('pedido'));
    }

    // ═══ O que o cliente vê ═══════════════════════════════════════════════════

    /**
     * Só o item que a equipe mandou (antes: 404), na forma da §19.16 — sem `preparo`, `bloqueios`, `ajuste`,
     * `ajustes_restantes_hoje`, `minimo` nem `da_precificacao` em nível nenhum; o MESMO anúncio que a equipe vê (títulos,
     * preços, descrição); as fotos pela porta dele; nenhum texto que cite o Mercado Livre, o Publicador, o Portal ou o agente.
     */
    public function test_o_cliente_ve_so_a_previa_do_item_com_ele_sem_os_bastidores(): void
    {
        $foto = $this->fotoComArquivo();
        $this->comoCliente()->getJson($this->urlCliente('previa'))->assertNotFound();
        $this->comoCliente()->get($this->urlCliente("fotos/{$foto->id}"))->assertNotFound();
        // O apelido da loja que a última leitura da conta guardou (o "Vendido por" da página do anúncio, 05/11/2026).
        $r = $this->rascunho();
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode([...(array) $r->step_state, 'conta' => ['nickname' => 'LOJADOCLIENTE']])]);
        $this->mandar(['revisao' => $this->revisao()])->assertOk();

        $c = $this->previaDoCliente()->json();
        $e = $this->previa()->json();

        $this->assertSame(self::PREVIA_DO_CLIENTE, array_keys($c));
        foreach (['preparo', 'bloqueios', 'ajuste', 'ajustes_restantes_hoje', 'minimo', 'da_precificacao'] as $proibida) {
            $this->assertNotContains($proibida, self::chaves($c), "a porta do cliente recebeu `{$proibida}`");
            $this->assertContains($proibida, self::chaves($e), "controle: a equipe tem `{$proibida}`");
        }
        $this->assertSame(['valor', 'ajustado'], array_keys($c['preco']['classico']));
        $this->assertSame(['classico', 'premium', 'por_cor', 'premium_igual'], array_keys($c['preco']));
        $this->assertSame(['estado', 'rodada', 'enviado_em', 'pedido'], array_keys($c['aprovacao']));
        $this->assertSame(['com_cliente', 1, null], [$c['aprovacao']['estado'], $c['aprovacao']['rodada'], $c['aprovacao']['pedido']]);
        $this->assertNull($c['publicacao'], 'antes de aprovar, nenhuma publicação');
        $this->assertTrue($c['pronta']);
        // O MESMO anúncio que a equipe vê (o que ele aprova é o que vai ao ar).
        $this->assertSame([$e['revisao'], $e['descricao'], $e['categoria'], $e['tipos'], $e['variantes']], [$c['revisao'], $c['descricao'], $c['categoria'], $c['tipos'], $c['variantes']]);
        $this->assertSame(array_map(fn ($a) => [$a['tipo'], $a['variante'], $a['titulo'], $a['preco']], $e['anuncios']),
            array_map(fn ($a) => [$a['tipo'], $a['variante'], $a['titulo'], $a['preco']], $c['anuncios']));
        // A página do anúncio (05/11/2026): o cliente vê como o comprador vê — o mesmo título completo, a condição, o
        // estoque, a garantia, os nomes da variação e o "Vendido por" que a equipe vê (decisão do usuário).
        $paginaDe = fn (array $j) => [$j['eixos'], $j['vendedor'], array_map(fn ($a) => [$a['titulo_completo'], $a['condicao'], $a['estoque'], $a['garantia']], $j['anuncios'])];
        $this->assertSame($paginaDe($e), $paginaDe($c));
        $this->assertSame('LOJADOCLIENTE', $c['vendedor'], 'o "Vendido por" chega ao cliente');
        $this->assertSame([$e['preco']['classico']['valor'], $e['preco']['premium']['valor']], [$c['preco']['classico']['valor'], $c['preco']['premium']['valor']]);
        // As fotos pela porta DELE (a da equipe é `/trilha/…`), e abrem.
        $base = "/portal/{$this->empresa->id}/trilha/ofertas/{$this->item()}/fotos/";
        $fotos = array_merge(...array_map(fn ($a) => $a['fotos'], $c['anuncios']));
        $this->assertNotSame([], $fotos);
        foreach ($fotos as $f) {
            $this->assertSame($base.$f['id'], $f['url']);
            $this->assertSame(['id', 'url', 'gerada'], array_keys($f));
        }
        $resposta = $this->comoCliente()->get($base.$foto->id)->assertOk();
        $this->assertSame(['image/jpeg', 'nosniff'], [$resposta->headers->get('Content-Type'), $resposta->headers->get('X-Content-Type-Options')]);
        $this->assertStringContainsString('private', (string) $resposta->headers->get('Cache-Control'));
        $this->assertTextoNeutro($c);
        $this->assertStringNotContainsString('/trilha/'.$this->empresa->id.'/', (string) json_encode($c, JSON_UNESCAPED_SLASHES), 'nenhum endereço da porta da equipe');

        // Aprovado e publicado em parte: a mensagem dele é neutra (o "em parte" é da equipe).
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $c['revisao']])->assertOk();
        foreach (['recusado' => Aprovacao::MSG_CLIENTE_COM_A_EQUIPE, 'avisos' => Aprovacao::MSG_CLIENTE_COM_A_EQUIPE, 'erro' => Aprovacao::MSG_CLIENTE_COM_A_EQUIPE] as $situacao => $msg) {
            Cache::put(PublicacaoNaTrilha::chave((int) $this->pub->id), ['situacao' => $situacao, 'mensagem' => 'A conferência no Mercado Livre falhou.',
                'avisos' => ['Mercado Livre recusou'], 'em' => now()->toIso8601String()], 60);
            $this->assertSame(['situacao' => $situacao, 'mensagem' => $msg], $this->previaDoCliente()->json('publicacao'), $situacao);
            $this->assertTrue($this->previa()->json('aprovacao.falta_publicar'), "{$situacao}: a equipe vê que falta publicar");
        }
        $this->assertTextoNeutro($this->previaDoCliente()->json());
    }

    /**
     * 04/11/2026 (o MLB vira link para o anúncio, só na equipe): publicado o que o cliente aprovou, a prévia da EQUIPE traz
     * `publicacao.anuncios` com o link de cada anúncio; a do CLIENTE continua só `{situacao, mensagem}` — nem o MLB, nem o
     * link, nem o domínio do canal de venda em lugar nenhum da resposta dele.
     */
    public function test_publicado_a_equipe_recebe_os_links_e_o_cliente_nenhum_mlb(): void
    {
        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertOk();
        $this->assertSame(['na_fila'], $this->rodarOsJobs());

        // A publicação terminou (o agendador ainda não fechou o item): o Clássico no acervo da loja, o Premium montado.
        $item = PubFilaPublicacaoItem::query()->sole();
        $publicacao = PubPublicacao::create(['rascunho_id' => $item->rascunho_id, 'revisao' => $revisao, 'modelo_publicacao' => 'user_products',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now()]);
        foreach ([['gold_special', 'MLB9300001', 0], ['gold_pro', 'MLB9300002', 1]] as [$lt, $mlb, $indice]) {
            PubPublicacaoItem::create(['publicacao_id' => $publicacao->id, 'indice' => $indice, 'listing_type_id' => $lt, 'variante_chave' => '*',
                'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => $mlb, 'payload' => [], 'criado_em' => now()]);
        }
        $item->update(['status' => PubFilaPublicacaoItem::PUBLICANDO, 'publicacao_id' => $publicacao->id, 'iniciado_em' => now()]);
        DB::table('pub_rascunhos')->where('id', $item->rascunho_id)->update(['status' => PubRascunho::PUBLISHING]);
        MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB9300001', 'status' => 'active',
            'permalink' => 'https://produto.mercadolivre.com.br/MLB-9300001-cadeira-_JM']);

        $this->assertSame([
            ['tipo' => 'Clássico', 'mlb' => 'MLB9300001', 'link' => 'https://produto.mercadolivre.com.br/MLB-9300001-cadeira-_JM'],
            ['tipo' => 'Premium', 'mlb' => 'MLB9300002', 'link' => 'https://produto.mercadolivre.com.br/MLB-9300002-_JM'],
        ], $this->previa()->json('publicacao.anuncios'), 'a equipe recebe os links');

        $doCliente = $this->previaDoCliente();
        $this->assertSame(['situacao' => 'publicado', 'mensagem' => PublicacaoNaTrilha::MSG_PUBLICADO], $doCliente->json('publicacao'));
        $json = (string) json_encode($doCliente->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['MLB9300001', 'MLB9300002', '9300001', '9300002', 'mercadolivre', '_JM'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $json, "a prévia do cliente leva `{$segredo}`");
        }
        $this->assertTextoNeutro($doCliente->json());
    }

    /** O item de outra loja, o que não existe e a foto de outro rascunho: 404 (antes do corpo); e o cliente de outra loja não entra. */
    public function test_outra_empresa_item_nao_mandado_e_foto_de_outro_rascunho_sao_404(): void
    {
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $outra = $this->empresaDaTrilha();
        config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id, $outra->id]]);
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $rFora = $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora));
        $fotoFora = $rFora->imagens()->create(['caminho' => "publicador/{$rFora->id}/x.jpg", 'sha256' => str_repeat('f', 64), 'mime' => 'image/jpeg', 'bytes' => 1, 'upload_status' => PubImagem::PENDENTE]);
        // Uma linha "mandada" da outra loja, para provar que o endereço desta não a alcança.
        TrilhaAprovacao::create(['company_id' => $outra->id, 'pub_produto_id' => $rFora->produto_id, 'item_chave' => "s-{$deFora['produto']->id}",
            'estado' => 'com_cliente', 'revisao_enviada' => (int) $rFora->revisao, 'enviado_em' => now(), 'enviado_por' => $this->dev->id]);
        $outroItemDaLoja = $this->produtoCompleto($this->empresa, 'Mesa Daqui', 'MD');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $outroItemDaLoja));
        $antes = TrilhaAprovacao::query()->orderBy('id')->get()->toArray();

        foreach (["s-{$deFora['produto']->id}", 's-999999', "c-{$this->d->id}-3", 'o-999999', "s-{$outroItemDaLoja['produto']->id}"] as $item) {
            $this->comoCliente()->getJson($this->urlCliente('previa', $item))->assertNotFound();
            $this->comoCliente()->get($this->urlCliente("fotos/{$fotoFora->id}", $item))->assertNotFound();
            // Corpo inválido de propósito: o 404 vem antes do 422.
            $this->comoCliente()->postJson($this->urlCliente('aprovar', $item), ['revisao' => 'x'])->assertNotFound();
            $this->comoCliente()->postJson($this->urlCliente('ajuste', $item), ['revisao' => 'x'])->assertNotFound();
        }
        // A foto de outro rascunho pelo item desta loja que foi mandado; e ids de 19 dígitos.
        $this->comoCliente()->get($this->urlCliente("fotos/{$fotoFora->id}"))->assertNotFound();
        $this->comoCliente()->get($this->urlCliente('fotos/1234567890123456789'))->assertNotFound();
        $this->comoCliente()->getJson($this->urlCliente('previa', 's-1234567890123456789'))->assertNotFound();
        // O cliente da OUTRA loja pelo endereço desta: o `portal.auth` recusa (a escrita é 403; a leitura volta ao Início dele).
        $doOutro = $this->clienteDoPortal($outra);
        $this->outroCliente($outra, $doOutro)->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertForbidden();
        $this->outroCliente($outra, $doOutro)->get($this->urlCliente('previa'))->assertRedirect();

        $this->assertSame($antes, TrilhaAprovacao::query()->orderBy('id')->get()->toArray());
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
    }

    // ═══ Modo equipe e D56 ════════════════════════════════════════════════════

    /**
     * No modo equipe do Portal a ECF aprova PELO cliente (§19.1): a página recebe os endereços da aprovação (as fotos da 4f,
     * não), a aprovação grava `decidido_por_equipe` (nunca `decidido_por_cliente`) e o Job publica com quem MANDOU. Uma
     * sessão com as duas marcas (o cliente "por baixo") grava como equipe.
     */
    public function test_o_modo_equipe_aprova_pelo_cliente_e_grava_decidido_por_equipe(): void
    {
        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();
        $membro = $this->entrarComoEquipe($this->empresa);

        $props = $this->props($this->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $this->assertTrue($props['sessao_equipe']);
        $this->assertSame(['fotos' => null, 'aprovacao' => $this->enderecosDoCliente()], $props['cliente_escrita']);
        $this->getJson($this->urlCliente('previa'))->assertOk()->assertJsonPath('aprovacao.estado', 'com_cliente');

        $this->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertOk()->assertJsonPath('aprovacao.estado', 'aprovado');

        $linha = TrilhaAprovacao::query()->sole();
        $this->assertSame([null, (int) $membro->id], [$linha->decidido_por_cliente, $linha->decidido_por_equipe]);
        $evento = $this->eventos('trilha_aprovacao_aprovada')->sole();
        $this->assertSame([User::class, (int) $membro->id, 'interno'], [$evento->causer_type, (int) $evento->causer_id, $evento->getExtraProperty('origem')]);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, fn (PublicarPelaTrilhaJob $j) => $j->userId === (int) $this->dev->id);
        $this->assertSame("{$membro->name} (equipe ECF)", $this->previa()->json('aprovacao.decidido_por'));

        // A sessão com as duas marcas: quem está ali é a equipe — o pedido de ajuste grava como equipe. (A conferência da
        // 4d não terminou — a fila é de mentira —; recusada, o "Mandar de novo" vale.)
        Cache::put(PublicacaoNaTrilha::chave((int) $this->pub->id), ['situacao' => 'recusado', 'mensagem' => 'x', 'em' => now()->toIso8601String()], 600);
        $this->mandar(['revisao' => $revisao])->assertOk();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->withSession([
            PortalContexto::SESSAO_EQUIPE                  => $membro->id,
            PortalContexto::SESSAO_EMPRESAS_EQUIPE         => [$this->empresa->id => 'passagem'],
            $this->app['auth']->guard('portal')->getName() => $this->cliente->id,
            'portal_empresa_id'                            => $this->empresa->id,
        ]);
        $this->postJson($this->urlCliente('ajuste'), ['revisao' => $revisao, 'texto' => 'Pela equipe'])->assertOk();
        $linha = TrilhaAprovacao::query()->sole();
        $this->assertSame(['ajuste', null, (int) $membro->id], [$linha->estado, $linha->decidido_por_cliente, $linha->decidido_por_equipe]);
        $this->assertSame(User::class, $this->eventos('trilha_aprovacao_ajuste_pedido')->sole()->causer_type);
    }

    /**
     * D56: quem mandou está inativo — a aprovação fica gravada, nada vai para a fila, a Lista diz "falta publicar" e o
     * [Aprovar e publicar] da equipe VOLTA (só nesta revisão: mudou depois da aprovação, 409 de novo).
     */
    public function test_enviado_por_inativo_aprova_sem_publicar_e_o_publicar_volta_para_a_equipe(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $quemMandou = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Ana']);
        $quemMandou->forceFill(['is_dev' => true])->save();
        $this->actingAs($quemMandou)->postJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/cliente", ['revisao' => $revisao])->assertOk();
        $quemMandou->forceFill(['active' => false])->save();

        $j = $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertOk()->json();

        $this->assertSame(['aprovado', null], [$j['aprovacao']['estado'], $j['publicacao']]);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        $this->assertSame([Aprovacao::STATUS_APROVADO.' · falta publicar', true], [$this->linha()['status'], $this->linha()['alerta']]);
        $this->assertSame([true, 'Ana', 'Joana da Loja'], [$this->previa()->json('aprovacao.falta_publicar'), $this->previa()->json('aprovacao.enviado_por'),
            $this->previa()->json('aprovacao.decidido_por')]);

        // O [Aprovar e publicar] volta para a equipe: 202 e o Job com quem CLICOU agora.
        $this->publicar(['revisao' => $revisao])->assertStatus(202);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, fn (PublicarPelaTrilhaJob $job) => $job->userId === (int) $this->dev->id);
        $this->assertSame(['na_fila'], $this->rodarOsJobs());
        $this->assertSame(Aprovacao::STATUS_APROVADO.' · na fila', $this->linha()['status']);
        $this->assertFalse($this->previa()->json('aprovacao.falta_publicar'));
        $this->assertNenhumPostDeItem();
    }

    /** Aprovado pelo cliente e a equipe mexeu depois: o publicar direto volta a ser 409 (mande de novo ao cliente). */
    public function test_aprovado_e_mudado_depois_o_publicar_da_equipe_e_409(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $this->mandar(['revisao' => $revisao])->assertOk();
        User::query()->whereKey($this->dev->id)->update(['active' => false]);
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $revisao])->assertOk();
        User::query()->whereKey($this->dev->id)->update(['active' => true]);
        $this->dev->refresh();
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", ['revisao' => $revisao,
            'precos' => ['classico' => 189.9], 'cor' => null])->assertOk();

        $this->assertSame(['message' => Aprovacao::MSG_MUDOU_DEPOIS_DA_APROVACAO], $this->publicar(['revisao' => $this->revisao()])->assertStatus(409)->json());
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
        $this->assertTrue($this->previa()->json('aprovacao.mudou_depois'));
        // Mandar de novo vale (aprovado não é "com o cliente").
        $this->mandar(['revisao' => $this->revisao()])->assertOk()->assertJsonPath('aprovacao.estado', 'com_cliente')->assertJsonPath('aprovacao.rodada', 2);
    }

    // ═══ A página e a matriz de acesso do cliente ═════════════════════════════

    /** `cliente_escrita.aprovacao` chega à porta do cliente (com as fotos da 4f); a porta da equipe nunca a recebe. */
    public function test_cliente_escrita_aprovacao_so_na_porta_do_cliente_e_abre_a_previa(): void
    {
        $cliente = $this->props($this->comoCliente()->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $this->assertSame(['fotos' => "/portal/{$this->empresa->id}/trilha/produtos/__PRODUTO__/fotos", 'aprovacao' => $this->enderecosDoCliente()],
            $cliente['cliente_escrita']);
        $this->assertNull($cliente['escrita']);
        $equipe = $this->props($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk());
        $this->assertNull($equipe['cliente_escrita']);

        // Com o item no marcador, os três endereços respondem (depois de mandar).
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $url = fn (string $k) => str_replace('__ITEM__', $this->item(), $cliente['cliente_escrita']['aprovacao'][$k]);
        $this->comoCliente()->getJson($url('previa'))->assertOk()->assertJsonPath('aprovacao.estado', 'com_cliente');
        $this->comoCliente()->postJson($url('ajuste'), ['revisao' => $this->revisao(), 'texto' => 'Troque a capa'])->assertOk();
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $this->comoCliente()->postJson($url('aprovar'), ['revisao' => $this->revisao()])->assertOk();
    }

    /** No domínio do cliente as quatro rotas passam (allowlist) — e a do "Mandar" da equipe não existe lá; o CSRF vale. */
    public function test_o_dominio_do_cliente_deixa_as_quatro_rotas_passarem_e_o_csrf_vale(): void
    {
        $foto = $this->fotoComArquivo();
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $base = "http://cliente.teste/portal/{$this->empresa->id}/trilha/ofertas/{$this->item()}";

        $this->comoCliente()->getJson("{$base}/previa")->assertOk();
        $this->comoCliente()->get("{$base}/fotos/{$foto->id}")->assertOk();
        // As escritas chegam ao controller: o 422 é dele (não o 404 do domínio).
        $this->comoCliente()->postJson("{$base}/ajuste", ['revisao' => $this->revisao()])->assertStatus(422);
        $this->comoCliente()->postJson("{$base}/aprovar", [])->assertStatus(422);
        // O "Mandar" da equipe não existe no domínio do cliente (nem para o Dev).
        $this->actingAs($this->dev)->postJson("http://cliente.teste/trilha/{$this->empresa->id}/ofertas/{$this->item()}/cliente", ['revisao' => $this->revisao()])->assertNotFound();
        config(['portal.dominio_cliente' => null]);

        // O CSRF como em produção: sem o token, 419 e nada muda; com ele, passa.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertStatus(419);
        $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $this->revisao(), 'texto' => 'x'])->assertStatus(419);
        $this->assertSame('com_cliente', TrilhaAprovacao::query()->sole()->estado);
        $this->comoCliente()->withSession(['_token' => 'token-da-aba'])
            ->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()], ['X-CSRF-TOKEN' => 'token-da-aba'])->assertOk();
    }

    /** Sem as duas chaves da trilha (ou sem login), as quatro rotas do cliente não existem para ele. */
    public function test_sem_as_chaves_da_trilha_ou_sem_login_as_rotas_do_cliente_nao_abrem(): void
    {
        $foto = $this->fotoComArquivo();
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $rotas = fn () => [
            $this->comoCliente()->getJson($this->urlCliente('previa')),
            $this->comoCliente()->get($this->urlCliente("fotos/{$foto->id}")),
            $this->comoCliente()->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()]),
            $this->comoCliente()->postJson($this->urlCliente('ajuste'), ['revisao' => $this->revisao(), 'texto' => 'x']),
        ];

        config(['trilha.trabalho.empresas' => []]);
        foreach ($rotas() as $i => $r) {
            $r->assertNotFound();
        }
        config(['trilha.trabalho.empresas' => [$this->empresa->id], 'trilha.empresas' => []]);
        foreach ($rotas() as $r) {
            $r->assertNotFound();
        }
        config(['trilha.empresas' => [$this->empresa->id]]);
        // Sem login no Portal: a escrita é recusada (nada passa do `portal.auth`).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->urlCliente('aprovar'), ['revisao' => $this->revisao()])->assertStatus(401);
        $this->get($this->urlCliente('previa'))->assertRedirect();
        $this->assertSame('com_cliente', TrilhaAprovacao::query()->sole()->estado);
        Queue::assertNotPushed(PublicarPelaTrilhaJob::class);
    }

    /** Cada rota com o seu teto e o seu contador (um não come o limite do outro). */
    public function test_cada_rota_tem_o_seu_teto(): void
    {
        foreach (['trilha.cliente' => 'throttle:10,1,trilha.revisao.cliente', 'portal.trilha.aprovacao.previa' => 'throttle:120,1,trilha.cliente.aprovacao.previa',
            'portal.trilha.aprovacao.foto' => 'throttle:600,1,trilha.cliente.aprovacao.foto', 'portal.trilha.aprovacao.aprovar' => 'throttle:10,1,trilha.cliente.aprovacao.aprovar',
            'portal.trilha.aprovacao.ajuste' => 'throttle:10,1,trilha.cliente.aprovacao.ajuste'] as $nome => $teto) {
            $this->assertContains($teto, Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
        // O 11º "Mandar" no minuto: 429, e nada gravado por ele.
        for ($i = 0; $i < 10; $i++) {
            $this->mandar(['revisao' => -1]);
        }
        $this->mandar(['revisao' => $this->revisao()])->assertStatus(429);
        $this->assertSame(0, TrilhaAprovacao::query()->count());
    }

    // ═══ A migration ══════════════════════════════════════════════════════════

    /**
     * A tabela NOVA sobe e desce (idempotente nos dois sentidos), com os nomes curtos `tra_*`, as colunas `nullOnDelete`
     * nulas, o único por anúncio e as FKs certas. O `down` dropa as FKs ANTES da tabela (no MariaDB; o SQLite as leva junto
     * — a ordem é conferida no fonte).
     */
    public function test_a_migration_sobe_e_desce(): void
    {
        $arquivo = database_path('migrations/2026_11_04_120000_create_trilha_aprovacoes_table.php');
        $migration = require $arquivo;

        $migration->down();
        $this->assertFalse(Schema::hasTable('trilha_aprovacoes'));
        $migration->down();
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasTable('trilha_aprovacoes'));

        $colunas = collect(Schema::getColumns('trilha_aprovacoes'))->keyBy('name');
        $this->assertSame(['id', 'company_id', 'pub_produto_id', 'item_chave', 'estado', 'rodada', 'revisao_enviada', 'enviado_em', 'enviado_por',
            'decidido_em', 'decidido_por_cliente', 'decidido_por_equipe', 'pedido', 'created_at', 'updated_at'], $colunas->keys()->all());
        foreach (['enviado_por', 'decidido_em', 'decidido_por_cliente', 'decidido_por_equipe', 'pedido'] as $nula) {
            $this->assertTrue($colunas[$nula]['nullable'], "{$nula} precisa ser nula");
        }
        foreach (['company_id', 'pub_produto_id', 'item_chave', 'estado', 'revisao_enviada', 'enviado_em'] as $cheia) {
            $this->assertFalse($colunas[$cheia]['nullable'], $cheia);
        }
        $indices = collect(Schema::getIndexes('trilha_aprovacoes'))->keyBy('name');
        foreach (['tra_company_ix' => ['company_id'], 'tra_pub_uq' => ['pub_produto_id'], 'tra_enviado_ix' => ['enviado_por'],
            'tra_cliente_ix' => ['decidido_por_cliente'], 'tra_equipe_ix' => ['decidido_por_equipe']] as $nome => $cols) {
            $this->assertTrue($indices->has($nome), "índice {$nome}");
            $this->assertSame($cols, $indices[$nome]['columns'], $nome);
        }
        $this->assertTrue($indices['tra_pub_uq']['unique'], 'uma linha viva por anúncio');
        $fks = collect(Schema::getForeignKeys('trilha_aprovacoes'))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => [$fk['foreign_table'], strtolower($fk['on_delete'])]])->all();
        ksort($fks);
        $this->assertSame([
            'company_id'           => ['companies', 'cascade'],
            'decidido_por_cliente' => ['portal_usuarios', 'set null'],
            'decidido_por_equipe'  => ['users', 'set null'],
            'enviado_por'          => ['users', 'set null'],
            'pub_produto_id'       => ['pub_produtos', 'cascade'],
        ], $fks);

        // O fonte: os cinco nomes curtos (≤ 64 no MariaDB, erro 1059) e o `dropForeign` ANTES do `drop` da tabela.
        $fonte = (string) file_get_contents($arquivo);
        foreach (['tra_company_fk', 'tra_pub_fk', 'tra_enviado_fk', 'tra_cliente_fk', 'tra_equipe_fk'] as $fk) {
            $this->assertStringContainsString("'{$fk}'", substr($fonte, strpos($fonte, 'private const FKS'), 200), "{$fk} no `down`");
        }
        $this->assertLessThan(strpos($fonte, "Schema::drop('trilha_aprovacoes')"), strpos($fonte, '$table->dropForeign($fk)'));
        $this->assertDoesNotMatchRegularExpression('/->enum\(|->json\(|->timestamp\(\'/', $fonte);

        // E a tabela de novo em uso: a cascata do anúncio leva a linha junto.
        $this->mandar(['revisao' => $this->revisao()])->assertOk();
        $this->assertSame(1, TrilhaAprovacao::query()->count());
        DB::table('pub_rascunhos')->where('produto_id', $this->pub->id)->delete();
        DB::table('pub_produtos')->where('id', $this->pub->id)->delete();
        $this->assertSame(0, TrilhaAprovacao::query()->count());
    }

    // ─── apoio ───

    /** A cadeira de duas cores (Azul e Preto, um pub_produto só) da empresa, preparada pelo agente de verdade. */
    private function cadeira(Company $empresa, string $codigo): EstruturaProduto
    {
        $antes = $this->empresa;
        $this->empresa = $empresa;
        try {
            $d = $this->produtoDoPortal($codigo);
            $v = EstruturaProdutoVariacao::create(['produto_id' => $d->id, 'company_id' => $empresa->id, 'ordem' => 1,
                'codigo' => "{$codigo}-PR", 'eixo' => 'cor', 'valor' => 'Preto', 'custo' => 100, 'estoque' => 5]);
            EstruturaProdutoVolume::create(['variacao_id' => $v->id, 'ordem' => 0, 'comprimento' => 60, 'largura' => 60, 'altura' => 40, 'peso' => 12]);
            EstruturaOferta::create(['company_id' => $empresa->id, 'variacao_id' => $v->id, 'sku' => "{$codigo}-PR", 'fase' => 'simples', 'nome' => 'Cadeira Executiva — Preto']);
            $this->salvarERodar($d->fresh());
        } finally {
            $this->empresa = $antes;
        }

        return $d;
    }

    /** As chaves da trilha ligam DEPOIS do preparo (com elas o agente esperaria a onda); a marca de "agendado" (80 s) vence. */
    private function ligarATrilha(array $empresas): void
    {
        Carbon::setTestNow(now()->addMinutes(5));
        config(['trilha.empresas' => $empresas, 'trilha.trabalho.empresas' => $empresas]);
    }

    private function contaDaLoja(Company $empresa): void
    {
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'APP_USR-x', 'refresh_token' => 'TG-x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas.companies' => [...(array) config('publicador.contas_liberadas.companies', []), $empresa->id]]);
        ModalidadeDeEnvio::guardar((int) $empresa->id, 'drop_off', me1: false);
    }

    /** O anúncio pronto para publicar: uma foto por cor já enviada ao ML, a garantia e o preço gravado (o cenário da 4d). */
    private function prontoParaPublicar(EstruturaProduto $d, array $precos): void
    {
        $r = $this->rascunhoDo($d);
        $atribuicoes = [];
        foreach ([self::AZUL, self::PRETO] as $i => $cor) {
            $foto = $r->imagens()->create(['caminho' => "publicador/{$r->id}/cor{$i}.jpg", 'sha256' => hash('sha256', "cor{$i}-{$r->id}"), 'mime' => 'image/jpeg',
                'bytes' => 800_000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => "12{$i}-MLB{$r->id}_102026"]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => $cor, 'posicao' => 0];
        }
        app(RascunhoRepository::class)->gravarAtribuicoes($r, $atribuicoes);
        $r->update(['garantia' => ['tipo' => '2230280', 'tempo' => 30, 'unidade' => 'dias']]);
        $this->precos($d, $precos);
    }

    /** O preço gravado no rascunho, por cor (`az`/`pr` → [Clássico, Premium]) — pelo caminho do editor, como a 4c. */
    private function precos(EstruturaProduto $d, array $precos): void
    {
        $cor = ['az' => self::AZUL, 'pr' => self::PRETO];
        $variantes = [];
        foreach ($precos as $k => [$classico, $premium]) {
            $variantes[$cor[$k]] = ['precos' => ['gold_special' => $classico, 'gold_pro' => $premium]];
        }
        app(EditorRascunhoService::class)->salvarVariantes($this->rascunhoDo($d)->fresh(), $variantes);
    }

    /** O arquivo da 1ª foto do rascunho gravado no disco das imagens (o cenário só cria as linhas). */
    private function fotoComArquivo(): PubImagem
    {
        $foto = PubImagem::query()->where('rascunho_id', $this->rascunho()->id)->orderBy('id')->firstOrFail();
        ArquivosDeImagem::gravar($foto->caminho, UploadedFile::fake()->image('capa.jpg', 600, 600)->get());

        return $foto;
    }

    /** O Mercado Livre falso: SÓ o que a conferência pergunta. Qualquer outro pedido (um `POST /items`) estoura. */
    private function fakeMl(): void
    {
        Http::swap(new FabricaHttp(app('events')));
        Http::preventStrayRequests();
        Http::fake([
            '*/users/me' => fn () => Http::response(self::ml('conta/usuario')),
            '*/shipping_preferences*' => fn () => Http::response(self::ml('conta/shipping_preferences')),
            '*/attributes/conditional*' => fn () => Http::response(['required_attributes' => [], 'callbacks' => [], 'status' => 200]),
            '*/available_listing_types*' => fn () => Http::response(self::ml('conta/categorias/MLB193945/available_listing_types')),
            '*/items/search*' => fn () => Http::response(['seller_id' => '1555596317', 'results' => [], 'paging' => ['total' => 0]]),
            '*/items/validate' => fn (PedidoHttp $req) => ($this->validate)($req->data()),
            '*/shipping_options/free*' => fn () => Http::response([]),
        ]);
    }

    private static function ml(string $relativo): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta'];
    }

    /** Nenhum `POST /items` (criar anúncio) saiu — os POSTs são só o validate e os condicionais. */
    private function assertNenhumPostDeItem(): void
    {
        $posts = Http::recorded()->map(fn (array $par) => $par[0]->method().' '.$par[0]->url())->filter(fn (string $p) => str_starts_with($p, 'POST '))->values()->all();
        $this->assertNotSame([], $posts, 'controle: a conferência falou com o ML');
        foreach ($posts as $p) {
            $this->assertMatchesRegularExpression('#/(items/validate|attributes/conditional)(\?|$)#', $p, 'a trilha mandou criar anúncio');
        }
    }

    /** Roda os Jobs da publicação ainda não rodados (o `executar` do Job). @return list<string> */
    private function rodarOsJobs(): array
    {
        $saida = [];
        foreach (Queue::pushed(PublicarPelaTrilhaJob::class) as $job) {
            if ($this->jobsRodados->contains($job)) {
                continue;
            }
            $this->jobsRodados->attach($job);
            $saida[] = app(PublicacaoNaTrilha::class)->executar($job->companyId, $job->item, $job->pubProdutoId, $job->userId, $job->revisao, $job->ciente);
        }

        return $saida;
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

    private function mandar(array $corpo, ?string $chave = null): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/".($chave ?? $this->item()).'/cliente', $corpo);
    }

    private function publicar(array $corpo): TestResponse
    {
        return $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/{$this->item()}/publicar", $corpo);
    }

    private function previa(): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/{$this->item()}/previa")->assertOk();
    }

    private function previaDoCliente(): TestResponse
    {
        return $this->comoCliente()->getJson($this->urlCliente('previa'))->assertOk();
    }

    /** A linha do item na Lista (o `montar` da tela). */
    private function linha(): array
    {
        return $this->itemDaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa), $this->item());
    }

    private function urlCliente(string $resto, ?string $item = null): string
    {
        return "/portal/{$this->empresa->id}/trilha/ofertas/".($item ?? $this->item())."/{$resto}";
    }

    /** `cliente_escrita.aprovacao` esperado (com o marcador do item). */
    private function enderecosDoCliente(): array
    {
        $base = "/portal/{$this->empresa->id}/trilha/ofertas/__ITEM__";

        return ['previa' => "{$base}/previa", 'aprovar' => "{$base}/aprovar", 'ajuste' => "{$base}/ajuste"];
    }

    /** O cliente da loja na sessão do Portal (os guards ficam vivos entre os pedidos do mesmo teste: esquecê-los). */
    private function comoCliente(): static
    {
        return $this->outroCliente($this->empresa, $this->cliente);
    }

    private function outroCliente(Company $empresa, ?PortalUsuario $usuario = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->entrarNoPortal($empresa, $usuario);
    }

    /** A sessão de equipe no Portal: a passagem de 60 s emitida pelo admin, consumida na porta da equipe. */
    private function entrarComoEquipe(Company $empresa): User
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $membro = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Bruna']);
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]))->assertRedirect();

        return $membro;
    }

    private function props(TestResponse $resposta): array
    {
        return json_decode(json_encode($resposta->viewData('page')), true)['props'];
    }

    private function eventos(string $evento)
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === $evento)->values();
    }

    /** 422 com a mensagem no campo (`assertJsonPath` não alcança a chave com ponto — learnings §31). */
    private function assertErro(TestResponse $resposta, string $campo, string $mensagem): void
    {
        $resposta->assertUnprocessable();
        $this->assertSame($mensagem, $resposta->json('errors')[$campo][0] ?? null, (string) $resposta->getContent());
    }

    /** Todo TEXTO que o cliente lê (as URLs não) sem "Mercado Livre", "Publicador", "Portal" nem "robô". */
    private function assertTextoNeutro(array $dados, string $onde = ''): void
    {
        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $this->assertTextoNeutro($valor, "{$onde}.{$chave}");
            } elseif (is_string($valor) && $chave !== 'url') {
                foreach (self::PROIBIDAS as $palavra) {
                    $this->assertStringNotContainsStringIgnoringCase($palavra, $valor, "{$onde}.{$chave}");
                }
            }
        }
    }

    /** Todas as chaves, em qualquer nível. */
    private static function chaves(array $dados): array
    {
        $todas = [];
        foreach ($dados as $chave => $valor) {
            $todas[] = (string) $chave;
            if (is_array($valor)) {
                array_push($todas, ...self::chaves($valor));
            }
        }

        return $todas;
    }
}
