<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Jobs\Trilha\AndarFilaDePublicacaoJob;
use App\Jobs\Trilha\PublicarPelaTrilhaJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubFilaPublicacaoItem;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Trilha\PrecoNaTrilha;
use App\Services\Trilha\PublicacaoNaTrilha;
use App\Services\Trilha\SituacaoDasOfertas;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\EditorEmUso;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as FabricaHttp;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Aprovar e publicar pela trilha (4ª entrega, fatia 4d, desenho §19.13, D46–D50 e D59): `POST …/ofertas/{item}/publicar`
 * confere o anúncio (o `ConferenciaService` de verdade, com o Mercado Livre FALSO) e o põe na FILA de publicação da loja;
 * `GET …/publicacao` acompanha. Quem publica é a fila — nada daqui manda `POST /items`.
 *
 * O cenário é o do preparo de verdade (`CenarioDoPreparo`, como o da 4c): a cadeira de DUAS cores (Azul e Preto, um
 * pub_produto só) preparada pelo agente, na revisão, com uma foto já enviada e o preço gravado nas duas cores; a conta da
 * loja conectada e liberada; as chaves da trilha ligam só depois do preparo. A loja é a 459 (`PublicacaoNaTrilha::LOJA`).
 * O Mercado Livre é um `Http::fake` só com o que a conferência pergunta (conta, condicionais, tipos, SKU e o
 * `/items/validate`) e `preventStrayRequests`: qualquer outro pedido — um `POST /items` — estoura. O que se prova:
 *
 * - **a 460 com TUDO pronto → 403 e nada na fila** (`Queue::assertNothingPushed`, nenhum HTTP); e cada uma das três
 *   conferências da guarda D48 sozinha recusa (a empresa do endereço, o `pub_produtos.company_id`, a âncora da conta);
 * - a âncora de outra empresa é recusada também DENTRO do Job (antes de conferir e de novo antes de agendar);
 * - validate OK → item na fila (`PubFilaPublicacaoItem`) e a situação segue a fila de verdade: `na_fila` → `publicando`
 *   → `publicado` com os MLBs; a prévia traz a MESMA `publicacao`;
 * - avisos sem `ciente` → `avisos` (nada na fila); com `ciente` → na fila; erro da conferência → `recusado` em texto
 *   neutro, nada na fila; conta não liberada → `recusado`;
 * - revisão velha → 409 SEM `travado`; na fila / publicando / publicado / fora da revisão / prévia não pronta → 409;
 *   Premium = Clássico em QUALQUER cor ativa → 422 (a 4c deixa mexer cor por cor; o publicar, não);
 * - nenhum `POST /items` sai da trilha; dois cliques, uma conferência; o Job que quebra vira `erro`;
 * - D59: o anúncio recém-publicado em revisão no ML é "Em análise", sem alerta, nas primeiras 24 h; depois, a regra antiga.
 */
class PublicarNaTrilhaTest extends TestCase
{
    use CenarioDoPreparo;
    use MontaTrilha;
    use RefreshDatabase;

    /** As chaves canônicas das duas cores da cadeira no Publicador (valores do eixo COLOR do schema MLB193945). */
    private const AZUL = 'COLOR=id:52028';

    private const PRETO = 'COLOR=id:52049';

    /** As chaves de `publicacao` (§19.13), na ordem. */
    private const CHAVES = ['situacao', 'mensagem', 'avisos', 'mlbs'];

    /** O que não pode aparecer em texto nenhum da publicação (D59 e a régua de 4a) — e o antigo "robô" (R9: é "agente"). */
    private const PROIBIDOS = ['Mercado Livre', 'Publicador', 'Portal', 'robô', 'Robô', '/portal', 'mlb/anuncios'];

    private EstruturaProduto $d;

    private PubProduto $pub;

    private User $dev;

    /** @var \Closure(array): mixed o `/items/validate` agora */
    private \Closure $validate;

    private \SplObjectStorage $jobsRodados;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trilha.empresas' => [], 'trilha.trabalho.empresas' => [],
            'publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false,
            'publicador.contas_liberadas.todas' => false, 'publicador.contas_liberadas.companies' => [],
            'publicador.exigir_ficha_inteira' => false,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarPreparo();
        // A loja da trilha É a 459: a única em que a 4d publica (a empresa do `montarPreparo` fica sem uso).
        $this->empresa = Company::factory()->create(['id' => PublicacaoNaTrilha::LOJA]);
        // A página da equipe (o teste da `escrita`) monta o menu interno, cujo badge pergunta ao ECF Drive.
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));
        // O cliente do ML sem dormir entre tentativas (o teste não espera).
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), function (int $s) {}));
        $this->jobsRodados = new \SplObjectStorage();

        $this->d = $this->cadeira($this->empresa, 'CDC');
        $this->pub = $this->pubDo($this->d);
        $this->ligarATrilha([$this->empresa->id]);
        $this->contaDaLoja($this->empresa);
        $this->prontoParaPublicar($this->d, ['az' => [199.9, 229.9], 'pr' => [209.9, 239.9]]);

        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();

        // O validate da conta devolve 400 só com avisos que são ruído (N-16): a conferência sai OK.
        $this->validate = fn (array $corpo) => Http::response(self::ml('conta/categorias/MLB193945/validate_base_up'), 400);
        $this->fakeMl();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ D48 — só na 459, guarda no código ════════════════════════════════════

    /**
     * A 460 com TUDO pronto (a cadeira preparada pelo agente, na revisão, prévia pronta, foto enviada, preço, conta
     * conectada E liberada, as duas chaves da trilha): 403 com a mensagem neutra e NADA — nenhum Job, nenhum HTTP,
     * nenhuma fila, nenhum estado. Sem a guarda, este pedido iria para a fila de publicação de uma loja de cliente.
     */
    public function test_a_460_com_tudo_pronto_e_403_e_nada_vai_para_a_fila(): void
    {
        $outra = Company::factory()->create(['id' => 460]);
        $e = $this->cadeira($outra, 'CDX');
        $this->ligarATrilha([$this->empresa->id, $outra->id]);
        $this->contaDaLoja($outra);
        $this->prontoParaPublicar($e, ['az' => [199.9, 229.9], 'pr' => [209.9, 239.9]]);
        $chave = "s-{$e->id}";
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($outra, comoNaTela: true)[$chave]['etapa'], 'controle: na revisão');
        $this->assertTrue($this->actingAs($this->dev)->getJson("/trilha/{$outra->id}/ofertas/{$chave}/previa")->assertOk()->json('pronta'), 'controle: a prévia está pronta');
        $r = $this->rascunhoDo($e);
        Queue::fake();
        $this->fakeMl();

        $resposta = $this->publicar($chave, ['revisao' => (int) $r->revisao, 'ciente' => true], $outra);

        $resposta->assertForbidden();
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_LOJA], $resposta->json());
        Queue::assertNothingPushed();
        $this->assertSame([], Http::recorded()->all(), 'falou com o ML');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());
        $this->assertNull(Cache::get(PublicacaoNaTrilha::chave((int) $this->pubDo($e)->id)));
        $this->assertSame(0, Activity::query()->where('properties->evento', 'trilha_publicacao_pedida')->count());
        // O acompanhamento também é da 459 só.
        $this->actingAs($this->dev)->getJson("/trilha/{$outra->id}/ofertas/{$chave}/publicacao")->assertForbidden()
            ->assertExactJson(['message' => PublicacaoNaTrilha::MSG_LOJA]);
    }

    /**
     * Cada conferência da D48 recusa SOZINHA (as outras duas passando), para nenhuma depender das outras:
     * 1. a empresa do ENDEREÇO não é a 459 (o anúncio e a âncora são da 459 — dado cruzado de propósito);
     * 2. o `pub_produtos.company_id` não é a 459 (a âncora com token é uma MlbEmpresa DA 459);
     * 3. a âncora da conta não é da 459 (a MlbEmpresa do anúncio, com token, é de outra empresa).
     */
    public function test_cada_conferencia_da_guarda_da_459_recusa_sozinha(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        $pedir = fn (?Company $empresa = null, ?string $item = null) => $this->publicar($item ?? $chave, ['revisao' => $revisao], $empresa);

        // 1. O endereço da 460, com um item dela cujo anúncio diz company 459 e publica pela conta da 459.
        $outra = Company::factory()->create(['id' => 460]);
        $e = $this->cadeira($outra, 'CDX');
        $this->ligarATrilha([$this->empresa->id, $outra->id]);
        $this->prontoParaPublicar($e, ['az' => [199.9, 229.9], 'pr' => [209.9, 239.9]]);
        // Daqui em diante, nada pode ir para a fila nem para o ML (o preparo da 460 acima pôs os Jobs dele).
        Queue::fake();
        $this->fakeMl();
        $cruzado = $this->pubDo($e);
        DB::table('pub_produtos')->where('id', $cruzado->id)->update(['company_id' => PublicacaoNaTrilha::LOJA]);
        $this->assertSame(PublicacaoNaTrilha::LOJA, (int) $cruzado->fresh()->contaOuNula()?->id, 'controle: a âncora é a 459');
        $this->assertSame((int) $cruzado->id, (int) TrilhaDaEmpresa::itens($outra, comoNaTela: true)["s-{$e->id}"]['pub_produto_id'], 'controle: o item da 460 é esse anúncio');
        $pedir($outra, "s-{$e->id}")->assertForbidden()->assertJsonPath('message', PublicacaoNaTrilha::MSG_LOJA);

        // 2. O anúncio da 459 com `company_id` de outra empresa (sem token); a âncora é uma MlbEmpresa da 459.
        $daLoja = $this->mlbEmpresa(PublicacaoNaTrilha::LOJA);
        DB::table('pub_produtos')->where('id', $this->pub->id)->update(['company_id' => $outra->id, 'mlb_empresa_id' => $daLoja->id]);
        $this->assertSame((int) $daLoja->id, (int) $this->pub->fresh()->contaOuNula()?->id, 'controle: a âncora é a MlbEmpresa da 459');
        $this->assertSame((int) $this->pub->id, (int) TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['pub_produto_id'], 'controle: segue o item da 459');
        $pedir()->assertForbidden()->assertJsonPath('message', PublicacaoNaTrilha::MSG_LOJA);

        // 3. O anúncio da 459 (company 459) com a MlbEmpresa de OUTRA empresa, conectada: ela publicaria.
        $deFora = $this->mlbEmpresa($outra->id);
        DB::table('pub_produtos')->where('id', $this->pub->id)->update(['company_id' => PublicacaoNaTrilha::LOJA, 'mlb_empresa_id' => $deFora->id]);
        $this->assertSame((int) $deFora->id, (int) $this->pub->fresh()->contaOuNula()?->id, 'controle: a âncora é a MlbEmpresa de fora');
        $pedir()->assertForbidden()->assertJsonPath('message', PublicacaoNaTrilha::MSG_LOJA);

        Queue::assertNothingPushed();
        $this->assertSame([], Http::recorded()->all());
        // Controle: de volta à 459 por inteiro, o mesmo pedido passa.
        DB::table('pub_produtos')->where('id', $this->pub->id)->update(['mlb_empresa_id' => null]);
        $pedir()->assertStatus(202);
    }

    /**
     * O Job não confia no controller: com a âncora trocada para a MlbEmpresa de outra empresa DEPOIS do pedido, ele
     * recusa antes de conferir (nenhum HTTP, nada na fila); e, trocada DURANTE a conferência (o validate troca), recusa
     * antes de agendar.
     */
    public function test_a_ancora_de_outra_empresa_e_recusada_tambem_dentro_do_job(): void
    {
        $chave = $this->item();
        $this->publicar($chave, ['revisao' => $this->revisao()])->assertStatus(202);
        $deFora = $this->mlbEmpresa(Company::factory()->create(['id' => 460])->id);
        DB::table('pub_produtos')->where('id', $this->pub->id)->update(['mlb_empresa_id' => $deFora->id]);

        // O Job de verdade (`handle`), como o worker o roda.
        $job = Queue::pushed(PublicarPelaTrilhaJob::class)->first();
        $job->handle(app(PublicacaoNaTrilha::class));
        $this->jobsRodados->attach($job);

        $this->assertSame([], Http::recorded()->all(), 'conferiu um anúncio de outra conta');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());
        $this->assertSame(['recusado', PublicacaoNaTrilha::MSG_LOJA], $this->doCalculo(['situacao', 'mensagem']));
        // O acompanhamento, com a âncora de fora, também é 403 (a guarda vale nas duas rotas).
        $this->acompanhar($chave)->assertForbidden();

        // Durante a conferência: o validate troca a âncora (o pedido passou pela guarda e o Job conferiu a 459).
        DB::table('pub_produtos')->where('id', $this->pub->id)->update(['mlb_empresa_id' => null]);
        $this->publicar($chave, ['revisao' => $this->revisao()])->assertStatus(202);
        $this->validate = function (array $corpo) use ($deFora) {
            DB::table('pub_produtos')->where('id', $this->pub->id)->update(['mlb_empresa_id' => $deFora->id]);

            return Http::response(self::ml('conta/categorias/MLB193945/validate_base_up'), 400);
        };

        $this->assertSame(['loja'], $this->rodarOsJobs());
        $this->assertNotSame([], $this->pedidosAoMl(), 'controle: conferiu');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count(), 'agendou com a âncora de fora');
        $this->assertSame(['recusado', PublicacaoNaTrilha::MSG_LOJA], $this->doCalculo(['situacao', 'mensagem']));
    }

    // ═══ O caminho feliz: conferir → fila → publicado ═════════════════════════

    public function test_aprovar_confere_poe_na_fila_e_a_situacao_segue_a_fila_ate_o_publicado(): void
    {
        $chave = $this->item();
        $revisao = $this->revisao();
        // Sem pedido: o GET é EXATAMENTE `{publicacao: null}`, e a prévia traz o mesmo `null`.
        $this->assertSame(['publicacao' => null], $this->acompanhar($chave)->assertOk()->json());
        $this->assertContrato($chave, null);

        $resposta = $this->publicar($chave, ['revisao' => $revisao, 'ciente' => false]);

        $this->assertAceito($resposta);
        $this->assertSame(['publicacao' => ['situacao' => 'conferindo', 'mensagem' => PublicacaoNaTrilha::MSG_CONFERINDO, 'avisos' => [], 'mlbs' => []]], $resposta->json());
        Queue::assertPushedOn('high', PublicarPelaTrilhaJob::class, fn (PublicarPelaTrilhaJob $j) => [$j->companyId, $j->item, $j->pubProdutoId, $j->userId, $j->revisao, $j->ciente]
            === [PublicacaoNaTrilha::LOJA, $chave, (int) $this->pub->id, (int) $this->dev->id, $revisao, false]);
        $this->assertSame($resposta->json('publicacao'), $this->assertContrato($chave, 'conferindo'), 'o 202, o GET e a prévia dizem o mesmo');
        $pedido = Activity::query()->where('properties->evento', 'trilha_publicacao_pedida')->sole();
        $this->assertSame([(int) $this->dev->id, $chave, $revisao, false], [(int) $pedido->causer_id, $pedido->getExtraProperty('item'),
            (int) $pedido->getExtraProperty('revisao'), $pedido->getExtraProperty('ciente')], 'quem aprovou fica no histórico');

        $this->assertSame(['na_fila'], $this->rodarOsJobs());

        $item = PubFilaPublicacaoItem::query()->sole();
        $this->assertSame([(int) $this->pub->id, PubFilaPublicacaoItem::AGENDADO, $revisao, false, (int) $this->rascunho()->id],
            [(int) $item->produto_id, $item->status, (int) $item->revisao, (bool) $item->ciente, (int) $item->rascunho_id]);
        $this->assertSame('company-'.PublicacaoNaTrilha::LOJA, $item->fila->conta_chave, 'a fila da conta da 459');
        $this->assertSame((int) $this->dev->id, (int) $item->fila->criada_por, 'quem aprovou é o ator das publicações');
        $this->assertNotNull($item->validacao_id, 'a fila guarda a conferência que a trilha fez');
        $this->assertNenhumPostDeItem();
        $this->assertSame(['situacao' => 'na_fila', 'mensagem' => PublicacaoNaTrilha::MSG_NA_FILA, 'avisos' => [], 'mlbs' => []], $this->assertContrato($chave, 'na_fila'));
        // Na fila: aprovar de novo é 409 (a fila já vai publicar).
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_NA_FILA_409], $this->publicar($chave, ['revisao' => $revisao])->assertStatus(409)->json());

        // A fila anda (o agendador, simulado no banco: nada aqui fala com o ML): publicando…
        $publicacao = PubPublicacao::create(['rascunho_id' => $item->rascunho_id, 'revisao' => $revisao, 'modelo_publicacao' => 'user_products',
            'status' => PubPublicacao::RUNNING, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now()]);
        $item->update(['status' => PubFilaPublicacaoItem::PUBLICANDO, 'publicacao_id' => $publicacao->id, 'iniciado_em' => now()]);
        DB::table('pub_rascunhos')->where('id', $item->rascunho_id)->update(['status' => PubRascunho::PUBLISHING]);
        $this->assertSame(PublicacaoNaTrilha::MSG_PUBLICANDO, $this->assertContrato($chave, 'publicando')['mensagem']);

        // …publicado (a publicação terminou; o agendador ainda não fechou o item): os MLBs, Clássico antes do Premium.
        foreach ([['gold_pro', 'MLB9000002', 1], ['gold_special', 'MLB9000001', 0], ['gold_pro', null, 2]] as [$lt, $mlb, $indice]) {
            PubPublicacaoItem::create(['publicacao_id' => $publicacao->id, 'indice' => $indice, 'listing_type_id' => $lt, 'variante_chave' => '*',
                'status' => $mlb !== null ? PubPublicacaoItem::CREATED : PubPublicacaoItem::FAILED, 'ml_item_id' => $mlb, 'payload' => [], 'criado_em' => now()]);
        }
        $publicacao->update(['status' => PubPublicacao::PUBLISHED, 'concluida_em' => now()]);
        // `mlbs`: lista simples de strings (a tela só conta), Clássico antes do Premium, sem o item que falhou.
        $publicado = ['situacao' => 'publicado', 'mensagem' => PublicacaoNaTrilha::MSG_PUBLICADO, 'avisos' => [], 'mlbs' => ['MLB9000001', 'MLB9000002']];
        $this->assertSame($publicado, $this->assertContrato($chave, 'publicado'));

        // O agendador fecha o item: segue publicado (os MLBs da publicação de verdade).
        $item->update(['status' => PubFilaPublicacaoItem::PUBLICADO, 'produto_ativo' => null, 'concluido_em' => now()]);
        DB::table('pub_rascunhos')->where('id', $item->rascunho_id)->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame($publicado, $this->assertContrato($chave, 'publicado'), 'o mesmo cálculo no GET e na prévia');
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_PUBLICADO_409], $this->publicar($chave, ['revisao' => $revisao])->assertStatus(409)->json());
        $this->assertNenhumPostDeItem();
    }

    /**
     * R5 (retorno do 1º teste, 04/11/2026): depois de agendar, a fila anda NA HORA — o `AndarFilaDePublicacaoJob` (fila
     * `high`) roda a MESMA passada do `publicador:fila-publicacao`, e o item começa (`publicando`, com o
     * `PublicarRascunhoJob` na fila) sem esperar o minuto. Nenhuma trava sai: com o editor do Publicador em uso o item
     * ESPERA (a checagem do agendador); com a passada do minuto rodando (o mutex do agendamento), o Job volta para a fila
     * sem andar nada. E nunca um `POST /items` aqui (quem fala com o ML é o Job da publicação, de mentira).
     */
    public function test_depois_de_agendar_a_fila_anda_na_hora_sem_pular_as_travas(): void
    {
        $chave = $this->item();
        $this->publicar($chave, ['revisao' => $this->revisao(), 'ciente' => false])->assertStatus(202);
        Queue::assertNotPushed(AndarFilaDePublicacaoJob::class);
        $this->assertSame(['na_fila'], $this->rodarOsJobs());
        Queue::assertPushedOn('high', AndarFilaDePublicacaoJob::class);
        $item = PubFilaPublicacaoItem::query()->sole();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->status);

        // Trava do agendador: o editor do Publicador aberto — o item espera a próxima passada.
        EditorEmUso::marcar((int) $this->pub->id);
        $this->rodarAPassadaNaHora();
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->fresh()->status, 'editor em uso: espera');
        Queue::assertNotPushed(PublicarRascunhoJob::class);
        Carbon::setTestNow(now()->addMinutes(5));

        // Trava do agendamento: a passada do minuto está rodando — o Job volta para a fila e não anda nada.
        $evento = AndarFilaDePublicacaoJob::eventoDoMinuto();
        $this->assertNotNull($evento, 'o agendamento `publicador-fila-publicacao` existe (routes/console.php)');
        $this->assertTrue($evento->mutex->create($evento));
        $job = $this->rodarAPassadaNaHora();
        $job->assertReleased(AndarFilaDePublicacaoJob::ESPERA_S);
        $this->assertSame(PubFilaPublicacaoItem::AGENDADO, $item->fresh()->status, 'a passada do minuto rodando: nada anda por fora');
        $evento->mutex->forget($evento);

        // Livre: a passada na hora começa o item — sem o comando de minuto.
        $this->rodarAPassadaNaHora()->assertNotReleased();
        $this->assertSame(PubFilaPublicacaoItem::PUBLICANDO, $item->fresh()->status);
        Queue::assertPushedOn('high', PublicarRascunhoJob::class);
        $this->assertTrue($evento->mutex->create($evento), 'a passada na hora solta o mutex no fim');
        $evento->mutex->forget($evento);
        $this->assertNenhumPostDeItem();
    }

    /** Avisos sem `ciente`: a lista volta e nada vai para a fila. Com `ciente` (o [Publicar assim mesmo]): na fila. */
    public function test_avisos_sem_ciente_voltam_em_lista_e_com_ciente_vao_para_a_fila(): void
    {
        $corpo = self::ml('conta/categorias/MLB193945/validate_base_up');
        $corpo['cause'][] = ['department' => 'items', 'cause_id' => 469, 'type' => 'warning', 'code' => 'item.available_quantity.ignored',
            'references' => [], 'message' => 'Available quantity is managed by stock locations'];
        $this->validate = fn (array $dados) => Http::response($corpo, 400);
        $chave = $this->item();
        $revisao = $this->revisao();

        $this->assertAceito($this->publicar($chave, ['revisao' => $revisao]));
        $this->assertSame(['avisos'], $this->rodarOsJobs());

        $j = $this->assertContrato($chave, 'avisos');
        $this->assertSame(PublicacaoNaTrilha::MSG_AVISOS, $j['mensagem']);
        $this->assertNotEmpty($j['avisos'], 'a lista dos avisos');
        $this->assertNeutro($j);
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count(), 'aviso sem ciente não agenda');

        // [Publicar assim mesmo]: a mesma revisão (a conferência não a sobe), com `ciente`.
        $this->assertSame($revisao, $this->revisao());
        $this->assertSame('conferindo', $this->assertAceito($this->publicar($chave, ['revisao' => $revisao, 'ciente' => true]))['situacao']);
        $this->assertSame(['na_fila'], $this->rodarOsJobs());
        $this->assertTrue((bool) PubFilaPublicacaoItem::query()->sole()->ciente, 'a fila sabe que a equipe está ciente');
        $this->assertContrato($chave, 'na_fila');
        $this->assertNenhumPostDeItem();
    }

    /** Erro da conferência: `recusado`, com os erros em texto neutro ("canal de venda"); nada na fila. Conta não liberada: idem. */
    public function test_erro_da_conferencia_e_conta_nao_liberada_sao_recusados_e_nada_vai_para_a_fila(): void
    {
        // A foto que o ML não acha (204): a tradução do dicionário cita o Mercado Livre — a trilha não.
        $this->validate = fn (array $dados) => Http::response(['cause' => [['department' => 'items', 'cause_id' => 204, 'type' => 'error',
            'code' => 'item.pictures.not_found', 'references' => ['item.pictures'], 'message' => 'Picture not found']],
            'message' => 'Validation error', 'error' => 'validation_error', 'status' => 400], 400);
        $chave = $this->item();

        $this->assertAceito($this->publicar($chave, ['revisao' => $this->revisao()]));
        $this->assertSame(['recusado:BLOQUEADO'], $this->rodarOsJobs());

        $j = $this->assertContrato($chave, 'recusado');
        $this->assertSame(PublicacaoNaTrilha::MSG_RECUSADO, $j['mensagem']);
        $this->assertContains('Uma foto não foi encontrada no canal de venda. Envie as fotos de novo.', $j['avisos']);
        $this->assertNeutro($j);
        $this->assertNeutro($this->previa($chave)->json());
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());

        // Recusado não trava: aprovar de novo (a conta deixou de estar liberada) → só local, recusado, nada no ML.
        config(['publicador.contas_liberadas.companies' => []]);
        $this->fakeMl();
        $this->publicar($chave, ['revisao' => $this->revisao()])->assertStatus(202);
        $this->assertSame(['recusado:LOCAL'], $this->rodarOsJobs());
        $this->assertSame(PublicacaoNaTrilha::MSG_CONTA, $this->assertContrato($chave, 'recusado')['mensagem']);
        $this->assertSame([], $this->pedidosAoMl(), 'conta não liberada não recebe nada');
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());
    }

    /** O Job que quebra (ou estoura o tempo) não deixa a tela em "conferindo" para sempre; nem o que morreu calado. */
    public function test_o_job_que_quebra_ou_some_vira_erro_e_dois_cliques_conferem_uma_vez(): void
    {
        $chave = $this->item();
        $corpo = ['revisao' => $this->revisao()];

        $primeiro = $this->assertAceito($this->publicar($chave, $corpo));
        // O 2º clique enquanto confere: o MESMO objeto completo (nunca nulo), sem Job novo.
        $this->assertSame($primeiro, $this->assertAceito($this->publicar($chave, $corpo)));
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);

        Queue::pushed(PublicarPelaTrilhaJob::class)->first()->failed(new \RuntimeException('caiu'));
        $this->assertSame(PublicacaoNaTrilha::MSG_NAO_TERMINOU, $this->assertContrato($chave, 'erro')['mensagem']);

        // De novo: um Job novo. E o "conferindo" que ninguém fechou (o worker morreu) vence em 30 min.
        $this->assertSame('conferindo', $this->assertAceito($this->publicar($chave, $corpo))['situacao']);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 2);
        Carbon::setTestNow(now()->addMinutes(PublicacaoNaTrilha::CONFERINDO_VALE_MIN + 1));
        $this->assertSame(PublicacaoNaTrilha::MSG_NAO_TERMINOU, $this->assertContrato($chave, 'erro')['mensagem']);
        $this->assertAceito($this->publicar($chave, ['revisao' => $this->revisao()]));
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 3);

        // O estado gravado sem `mensagem` (cache de outra versão) ainda sai com a da situação — nunca vazia.
        Cache::put(PublicacaoNaTrilha::chave((int) $this->pub->id), ['situacao' => 'recusado', 'em' => now()->toIso8601String()], 60);
        $this->assertSame(PublicacaoNaTrilha::MSG_RECUSADO, $this->assertContrato($chave, 'recusado')['mensagem']);
    }

    /**
     * A pessoa aprovou a revisão N; se o anúncio muda antes do Job rodar (o preço ajustado noutra aba), o Job não confere
     * nem agenda a N+1 que ela não viu: `recusado` com "mudou", nada no ML, nada na fila.
     */
    public function test_o_anuncio_que_mudou_depois_de_aprovado_nao_e_conferido_nem_agendado(): void
    {
        $chave = $this->item();
        $this->publicar($chave, ['revisao' => $this->revisao()])->assertStatus(202);
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", ['revisao' => $this->revisao(),
            'precos' => ['classico' => 189.9], 'cor' => null])->assertOk();

        $this->assertSame(['mudou'], $this->rodarOsJobs());
        $this->assertSame(PublicacaoNaTrilha::MSG_MUDOU, $this->assertContrato($chave, 'recusado')['mensagem']);
        $this->assertSame([], $this->pedidosAoMl());
        $this->assertSame(0, PubFilaPublicacaoItem::query()->count());
    }

    // ═══ 409 e 422 ════════════════════════════════════════════════════════════

    public function test_revisao_velha_e_409_sem_travado_e_corpo_mal_formado_e_422(): void
    {
        $chave = $this->item();
        $atual = $this->revisao();

        $resposta = $this->publicar($chave, ['revisao' => $atual - 1, 'ciente' => true])->assertStatus(409);
        $this->assertSame(['revisao_atual' => $atual, 'message' => PrecoNaTrilha::MSG_REVISAO], $resposta->json(), 'sem `travado`');
        $this->publicar($chave, ['revisao' => (string) ($atual + 2)])->assertStatus(409)->assertJsonMissingPath('travado');
        foreach ([null, 'x', 1.5] as $ruim) {
            $this->assertErro($this->publicar($chave, ['revisao' => $ruim]), 'revisao', PrecoNaTrilha::MSG_RECARREGUE);
        }
        foreach (['talvez', 7, ['x']] as $ruim) {
            $this->assertErro($this->publicar($chave, ['revisao' => $atual, 'ciente' => $ruim]), 'ciente', PublicacaoNaTrilha::MSG_CIENTE);
        }

        Queue::assertNothingPushed();
        $this->assertNull(Cache::get(PublicacaoNaTrilha::chave((int) $this->pub->id)));
    }

    public function test_na_fila_publicando_publicado_fora_da_revisao_e_previa_nao_pronta_sao_409(): void
    {
        $chave = $this->item();
        $r = $this->rascunho();
        $revisao = $this->revisao();
        $pedir = fn (?string $item = null, ?int $rev = null) => $this->publicar($item ?? $chave, ['revisao' => $rev ?? $revisao]);

        // Na fila (agendado pelo Publicador): o estado antes da revisão — com a revisão velha, a mensagem é a da fila.
        $this->agendarNaFila($this->pub);
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_NA_FILA_409], $pedir()->assertStatus(409)->json());
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_NA_FILA_409], $pedir(null, $revisao - 1)->assertStatus(409)->json());
        // A situação mostra a fila, mesmo sem a trilha ter pedido (a tela não oferece [Aprovar e publicar] à toa).
        $this->assertContrato($chave, 'na_fila');
        DB::table('pub_fila_publicacao_itens')->delete();
        $this->assertContrato($chave, null);

        foreach ([PubRascunho::PUBLISHING => PublicacaoNaTrilha::MSG_PUBLICANDO_409, PubRascunho::PUBLISHED => PublicacaoNaTrilha::MSG_PUBLICADO_409,
            PubRascunho::PARTIALLY_PUBLISHED => PublicacaoNaTrilha::MSG_PUBLICADO_409] as $status => $mensagem) {
            DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => $status]);
            $this->assertSame(['message' => $mensagem], $pedir()->assertStatus(409)->json(), $status);
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => PubRascunho::DRAFT]);

        // Fora da revisão: o produto sem anúncio no Publicador ainda.
        $semPub = $this->produtoCompleto($this->empresa, 'Vaso Novo', 'VN');
        $this->assertSame(['message' => PublicacaoNaTrilha::MSG_FORA_DA_REVISAO], $pedir("s-{$semPub['produto']->id}", 1)->assertStatus(409)->json());

        // A prévia não pronta (sem categoria no anúncio): 409 com o motivo neutro dela.
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['categoria_id' => null]);
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['etapa'], 'controle: segue na revisão');
        $this->assertSame(['message' => 'O anúncio ainda está sem categoria.'], $pedir()->assertStatus(409)->json());

        Queue::assertNothingPushed();
        $this->assertSame([], Http::recorded()->all());
    }

    /**
     * D46 no publicar: o Premium igual ao Clássico em QUALQUER cor ativa é 422 — inclusive na cor que ninguém tocou. A 4c
     * deixa consertar cor por cor (só confere as cores do pedido); o publicar confere todas.
     */
    public function test_premium_igual_ao_classico_em_qualquer_cor_ativa_e_422(): void
    {
        $chave = $this->item();
        $this->precos($this->d, ['az' => [199.9, 229.9], 'pr' => [239.9, 239.9]]);
        // Controle: a 4c aceita mexer só no Azul (o Preto empatado não é dela)…
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/preco", ['revisao' => $this->revisao(),
            'precos' => ['classico' => 189.9], 'cor' => self::AZUL])->assertOk()->assertJsonPath('preco.premium_igual', true);

        // …o publicar não.
        $resposta = $this->publicar($chave, ['revisao' => $this->revisao(), 'ciente' => true]);
        $this->assertErro($resposta, 'precos.premium', PrecoNaTrilha::MSG_PREMIUM_IGUAL);
        $this->assertSame(PrecoNaTrilha::MSG_PREMIUM_IGUAL, $resposta->json('message'));
        Queue::assertNothingPushed();

        // Consertado o Preto, passa.
        $this->precos($this->d, ['pr' => [209.9, 239.9]]);
        $this->publicar($chave, ['revisao' => $this->revisao()])->assertStatus(202);
    }

    // ═══ D59 — "Em análise" ═══════════════════════════════════════════════════

    /**
     * O anúncio recém-publicado ainda `under_review` no ML: "Em análise" (anúncio e linha), sem alerta, nas primeiras 24 h
     * depois de CRIADO; depois, a regra de sempre ("Em revisão", "Fora do ar" com alerta). Um à venda e o outro em análise:
     * "No ar", sem alerta. O parado de verdade ao lado de um em análise segue alertando.
     */
    public function test_recem_publicado_em_revisao_e_em_analise_sem_alerta_so_nas_primeiras_24_horas(): void
    {
        $chave = $this->item();
        $r = $this->rascunho();
        $publicacao = PubPublicacao::create(['rascunho_id' => $r->id, 'revisao' => (int) $r->revisao, 'modelo_publicacao' => 'user_products',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'iniciada_em' => now(), 'concluida_em' => now()]);
        $itens = [];
        foreach (['gold_special' => 'MLB9100001', 'gold_pro' => 'MLB9100002'] as $lt => $mlb) {
            $itens[$lt] = PubPublicacaoItem::create(['publicacao_id' => $publicacao->id, 'indice' => count($itens), 'listing_type_id' => $lt,
                'variante_chave' => '*', 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => $mlb, 'payload' => [], 'criado_em' => now(),
                'avisos' => ['estado' => ['status' => 'under_review']]]);
        }
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['status' => PubRascunho::PUBLISHED]);
        $linha = function () use ($chave): array {
            $o = $this->itemDaTrilha(app(TrilhaDaEmpresa::class)->montar($this->empresa), $chave);

            return [$o['etapa'], $o['status'], $o['alerta'], array_column($o['anuncios'], 'situacao')];
        };

        $this->assertSame(['lanc', 'Em análise · Clássico e Premium', false, ['Em análise', 'Em análise']], $linha());
        // Na porta do cliente também (texto neutro).
        $this->assertSame(SituacaoDasOfertas::EM_ANALISE.' · Clássico e Premium', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)[$chave]['status']);

        // Um à venda, o outro em análise: "No ar", sem alerta.
        $itens['gold_special']->update(['avisos' => ['estado' => ['status' => 'active']]]);
        $this->assertSame(['lanc', 'No ar · falta o Premium', false, [null, 'Em análise']], $linha());
        // Parado de verdade (em revisão há 30 h) ao lado de um em análise: "Fora do ar" do parado, com alerta.
        $itens['gold_special']->update(['avisos' => ['estado' => ['status' => 'under_review']], 'criado_em' => now()->subHours(30)]);
        $this->assertSame(['lanc', 'Fora do ar · Clássico', true, ['Em revisão', 'Em análise']], $linha());

        // 24 h depois de criados: a regra de sempre.
        $itens['gold_special']->update(['criado_em' => now()]);
        Carbon::setTestNow(now()->addHours(SituacaoDasOfertas::EM_ANALISE_HORAS)->addMinute());
        $this->assertSame(['lanc', 'Fora do ar · Clássico e Premium', true, ['Em revisão', 'Em revisão']], $linha());
    }

    // ═══ O endereço ═══════════════════════════════════════════════════════════

    public function test_os_enderecos_da_escrita_levam_ao_publicar_e_ao_acompanhar_e_o_item_de_outra_loja_e_404(): void
    {
        $this->withoutVite();
        $props = json_decode(json_encode($this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk()->viewData('page')), true)['props'];
        $this->assertSame("/trilha/{$this->empresa->id}/ofertas/__ITEM__/publicar", $props['escrita']['revisao']['publicar']);
        $this->assertSame("/trilha/{$this->empresa->id}/ofertas/__ITEM__/publicacao", $props['escrita']['revisao']['publicacao']);
        $publicar = str_replace('__ITEM__', $this->item(), $props['escrita']['revisao']['publicar']);
        $acompanhar = str_replace('__ITEM__', $this->item(), $props['escrita']['revisao']['publicacao']);
        $this->actingAs($this->dev)->postJson($publicar, ['revisao' => $this->revisao()])->assertStatus(202);
        $this->actingAs($this->dev)->getJson($acompanhar)->assertOk()->assertJsonPath('publicacao.situacao', 'conferindo');

        // De outra loja (pelo endereço desta) e o que não existe: 404 — antes da loja (403) e da validação (422).
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VF');
        $this->rascunhoDoPub($this->grupoNoPublicador($outra, $deFora));
        foreach (["s-{$deFora['produto']->id}", 's-999999', "c-{$this->d->id}-3", 'o-999999'] as $chave) {
            $this->publicar($chave, ['revisao' => 'x', 'ciente' => 'x'])->assertNotFound();
            $this->acompanhar($chave)->assertNotFound();
        }
        // Os métodos trocados não existem.
        $this->actingAs($this->dev)->getJson($publicar)->assertStatus(405);
        $this->actingAs($this->dev)->postJson($acompanhar)->assertStatus(405);
        Queue::assertPushed(PublicarPelaTrilhaJob::class, 1);
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

    /** A conta da empresa conectada e liberada, com a modalidade de envio dela no cache (como a prévia da 4a). */
    private function contaDaLoja(Company $empresa): void
    {
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'APP_USR-x', 'refresh_token' => 'TG-x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas.companies' => [...(array) config('publicador.contas_liberadas.companies', []), $empresa->id]]);
        ModalidadeDeEnvio::guardar((int) $empresa->id, 'drop_off', me1: false);
    }

    /** Uma MlbEmpresa conectada da empresa `$companyId` (a âncora que a guarda D48 confere). */
    private function mlbEmpresa(int $companyId): MlbEmpresa
    {
        $e = MlbEmpresa::create(['nome' => 'Loja Polo '.uniqid(), 'projeto' => 'Incubadora', 'company_id' => $companyId]);
        MlToken::create(['company_id' => null, 'mlb_empresa_id' => $e->id, 'ml_user_id' => '1555596317', 'access_token' => 'APP_USR-m',
            'refresh_token' => 'TG-m', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas.mlb_empresas' => [...(array) config('publicador.contas_liberadas.mlb_empresas', []), $e->id]]);

        return $e;
    }

    /**
     * O anúncio pronto para publicar: uma foto por cor JÁ enviada ao ML (a conferência não sobe nada), a garantia e o preço
     * gravado — o que a equipe completaria no Publicador antes de aprovar.
     */
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
            // O frete contra a Precificação (o passo 9): sem cotação de volta, nada a comparar — não vira aviso.
            '*/shipping_options/free*' => fn () => Http::response([]),
        ]);
    }

    private static function ml(string $relativo): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/{$relativo}.json")), true)['resposta'];
    }

    /** @return list<string> "MÉTODO url" de cada pedido ao ML */
    private function pedidosAoMl(): array
    {
        return Http::recorded()->map(fn (array $par) => $par[0]->method().' '.$par[0]->url())->values()->all();
    }

    /** Nenhum `POST /items` (criar anúncio) saiu — os POSTs são só o validate e os condicionais. */
    private function assertNenhumPostDeItem(): void
    {
        $posts = array_values(array_filter($this->pedidosAoMl(), fn (string $p) => str_starts_with($p, 'POST ')));
        $this->assertNotSame([], $posts, 'controle: a conferência falou com o ML');
        foreach ($posts as $p) {
            $this->assertMatchesRegularExpression('#/(items/validate|attributes/conditional)(\?|$)#', $p, 'a trilha mandou criar anúncio');
        }
    }

    /** Roda os Jobs da publicação ainda não rodados (o `executar` do Job; um teste roda o `handle`). @return list<string> */
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

    /** R5: a passada da fila na hora — o `handle` do Job, com a fila de mentira anotando o `release`. */
    private function rodarAPassadaNaHora(): AndarFilaDePublicacaoJob
    {
        $job = (new AndarFilaDePublicacaoJob())->withFakeQueueInteractions();
        $job->handle(app(\App\Services\Publicador\Fila\AgendadorDaFila::class));

        return $job;
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

    private function publicar(string $chave, array $corpo, ?Company $empresa = null): TestResponse
    {
        return $this->actingAs($this->dev)->postJson('/trilha/'.($empresa ?? $this->empresa)->id."/ofertas/{$chave}/publicar", $corpo);
    }

    private function acompanhar(string $chave): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/publicacao");
    }

    private function previa(string $chave): TestResponse
    {
        return $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/{$chave}/previa")->assertOk();
    }

    /**
     * O contrato que a tela da 4d supõe, conferido a cada passo: o `GET …/publicacao` é EXATAMENTE `{publicacao: …}`; a
     * `publicacao` da prévia é a MESMA (mesmas chaves, mesmo cálculo); e, quando existe, tem as 4 chaves, `situacao` do
     * contrato, `mensagem` NÃO vazia (em toda situação), `avisos` e `mlbs` listas simples de strings.
     */
    private function assertContrato(string $chave, ?string $situacao): ?array
    {
        $resposta = $this->acompanhar($chave)->assertOk()->json();
        $this->assertSame(['publicacao'], array_keys($resposta), 'o GET é só {publicacao}');
        $p = $resposta['publicacao'];
        $this->assertSame($p, $this->previa($chave)->json('publicacao'), 'a prévia traz a MESMA publicacao do GET');
        if ($situacao === null) {
            $this->assertNull($p);

            return null;
        }
        $this->assertPublicacao($p);
        $this->assertSame($situacao, $p['situacao']);

        return $p;
    }

    /** A forma de UMA `publicacao` (§19.13), não nula. */
    private function assertPublicacao(mixed $p): void
    {
        $this->assertIsArray($p, 'publicacao nunca nula aqui');
        $this->assertSame(self::CHAVES, array_keys($p));
        $this->assertContains($p['situacao'], ['conferindo', 'avisos', 'na_fila', 'publicando', 'publicado', 'recusado', 'erro']);
        $this->assertIsString($p['mensagem']);
        $this->assertNotSame('', trim($p['mensagem']), "mensagem vazia em {$p['situacao']}");
        foreach (['avisos', 'mlbs'] as $lista) {
            $this->assertIsArray($p[$lista]);
            $this->assertTrue(array_is_list($p[$lista]), "{$lista} é lista");
            $this->assertSame([], array_filter($p[$lista], fn ($x) => ! is_string($x)), "{$lista} só de strings");
        }
    }

    /** O 202 do POST: SEMPRE `{publicacao: {…}}` completo (nunca nulo). */
    private function assertAceito(TestResponse $resposta): array
    {
        $resposta->assertStatus(202);
        $this->assertSame(['publicacao'], array_keys($resposta->json()));
        $this->assertPublicacao($resposta->json('publicacao'));

        return $resposta->json('publicacao');
    }

    /** A situação pelo cálculo (sem a rota), a da cadeira da 459. @param  list<string>  $campos */
    private function doCalculo(array $campos): array
    {
        $j = PublicacaoNaTrilha::situacao($this->rascunho()) ?? [];

        return array_map(fn (string $c) => $j[$c] ?? null, $campos);
    }

    /** Nenhum texto proibido em lugar nenhum do JSON (D59). */
    private function assertNeutro(array $j): void
    {
        $texto = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (self::PROIBIDOS as $proibido) {
            $this->assertStringNotContainsString($proibido, $texto);
        }
    }

    /** 422 com a mensagem no campo (`assertJsonPath` não alcança a chave com ponto — learnings §31). */
    private function assertErro(TestResponse $resposta, string $campo, string $mensagem): void
    {
        $resposta->assertUnprocessable();
        $this->assertSame($mensagem, $resposta->json('errors')[$campo][0] ?? null, (string) $resposta->getContent());
    }
}
