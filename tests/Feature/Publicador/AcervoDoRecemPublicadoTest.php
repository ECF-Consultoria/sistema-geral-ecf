<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\SincronizarAcervoDoPublicadoJob;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Quick 261010-nke — publicou pelo caminho AVULSO (clique em Publicar), o
 * acervo da aba Publicações recebe o MLB criado.
 *
 * O bug: a publicação grava em `pub_publicacao_itens` e a tela
 * `/mlb/anuncios/meus/{company}` lê EXCLUSIVAMENTE `ml_acervo_itens` (D-05).
 * Nada ligava as duas coisas — medido em produção em 10/10/2026 com
 * `MLB5366398961` e `MLB5366495199` (Poltrona Beny), que existem no Mercado
 * Livre e não existiam no acervo.
 *
 * A decisão do usuário foi FONTE ÚNICA: a publicação DISPARA o sync daquele
 * item; não escreve na tela por um segundo caminho. Logo o que estes testes
 * travam é o DISPARO — o conteúdo do sync é do
 * `tests/Unit/Phase134/ColetaDoPublicadoTest.php`.
 *
 * O ML é simulado (nenhum anúncio de verdade nasce aqui) e o `Queue::fake()`
 * é quem registra o dispatch.
 */
class AcervoDoRecemPublicadoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private int $posts = 0;

    /** @var \Closure(array, int): mixed o `POST /items` agora */
    private \Closure $criar;

    private AtorDoPortal $ator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * Monta o cenário da cadeira com o ML simulado. Fica fora do `setUp()`
     * porque o Teste 4 precisa da âncora `mlb_empresa` (loja sem Company) e o
     * `montarCenario()` só pode rodar uma vez por teste.
     */
    private function montar(string $ancora = 'company'): void
    {
        $this->montarCenario($ancora);
        $this->ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));

        $this->criar = fn (array $corpo, int $n) => Http::response([
            'id' => sprintf('MLB90000000%02d', $n), 'user_product_id' => "MLBU{$n}", 'status' => 'active',
            'family_name' => $corpo['family_name'] ?? null, 'listing_type_id' => $corpo['listing_type_id'] ?? null,
        ], 201);

        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['text' => '', 'plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(
                fn ($id) => ['code' => 404, 'body' => []],
                explode(',', $q->data()['ids'])
            )),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)),
                'status' => 'active', 'sub_status' => [], 'tags' => [], 'permalink' => 'https://produto.mercadolivre.com.br/x']),
            '*/items' => fn (Request $q) => ($this->criar)($q->data(), ++$this->posts),
        ]);
    }

    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));

        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), $this->ator, cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));

        return $p->fresh();
    }

    /** @return list<SincronizarAcervoDoPublicadoJob> */
    private function syncsDespachados(): array
    {
        return Queue::pushed(SincronizarAcervoDoPublicadoJob::class)->all();
    }

    // ─── Teste 1 — o disparo acontece, com a Company e os MLB certos ───────

    public function test_publicacao_concluida_despacha_o_sync_do_acervo_uma_vez(): void
    {
        $this->montar();

        // Publicação de OUTRO rascunho, com item criado — não pode entrar na lista.
        $this->publicacaoAlheiaCom('MLB7777777777');

        $p = $this->publicar();
        $this->assertSame(PubPublicacao::PUBLISHED, $p->status);

        $syncs = $this->syncsDespachados();

        $this->assertCount(1, $syncs, 'um disparo por publicação concluída — nem zero, nem duplicado');
        $this->assertSame($this->produto->company_id, $syncs[0]->companyId);
        $this->assertSame(['MLB9000000001'], $syncs[0]->mlItemIds);
    }

    // ─── Teste 2 — publicação PARCIAL dispara só com o que foi criado ──────

    public function test_publicacao_parcial_despacha_so_com_o_mlb_criado(): void
    {
        $this->montar();
        $this->tresCores();

        $criar = $this->criar;
        $this->criar = fn (array $corpo, int $n) => $n === 2
            ? Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400,
                'cause' => [['cause_id' => 3701, 'type' => 'error', 'code' => 'item.attribute.product_identifier.invalid',
                    'message' => 'GTIN', 'references' => ['item.attributes']]]], 400)
            : $criar($corpo, $n);

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PARTIALLY_PUBLISHED, $p->status);
        $this->assertSame([PubPublicacaoItem::CREATED, PubPublicacaoItem::FAILED, PubPublicacaoItem::CREATED],
            $p->itens()->pluck('status')->all());

        $syncs = $this->syncsDespachados();

        $this->assertCount(1, $syncs);
        $this->assertSame(
            ['MLB9000000001', 'MLB9000000003'],
            $syncs[0]->mlItemIds,
            'só os CREATED com ml_item_id — o FAILED não tem MLB para sincronizar'
        );
    }

    // ─── Teste 3 — nada criado, nada a sincronizar ─────────────────────────

    public function test_publicacao_sem_nenhum_item_criado_nao_despacha(): void
    {
        $this->montar();
        $this->criar = fn (array $corpo, int $n) => Http::response(['message' => 'Validation error',
            'error' => 'validation_error', 'status' => 400, 'cause' => []], 400);

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::FAILED, $p->status);
        $this->assertSame([], $p->itens()->where('status', PubPublicacaoItem::CREATED)->pluck('ml_item_id')->all());
        Queue::assertNotPushed(SincronizarAcervoDoPublicadoJob::class);
    }

    // ─── Teste 4 — MlbEmpresa sem Company: publica e NÃO dispara ───────────

    /**
     * 535 de 539 `MlbEmpresa` não têm Company, e o acervo é indexado por
     * `companies.id` (a própria rota é `/meus/{company}`). Para essa conta não
     * existe aba Publicações — então o sync é pulado, nunca lançado.
     */
    public function test_conta_ancorada_em_mlb_empresa_sem_company_publica_e_nao_despacha(): void
    {
        $this->montar('mlb_empresa');

        $this->assertNull($this->produto->company_id, 'o cenário é justamente a conta sem Company');

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PUBLISHED, $p->status, 'a publicação não pode ser afetada');
        $this->assertSame('MLB9000000001', $p->itens()->sole()->ml_item_id);
        Queue::assertNotPushed(SincronizarAcervoDoPublicadoJob::class);
    }

    // ─── Teste 5 — falhar o disparo NUNCA desfaz a publicação ──────────────

    /**
     * O anúncio já está no Mercado Livre: nada aqui pode desfazer nem repetir
     * a publicação. O despachante é trocado por um que lança SÓ para este job
     * (os outros dispatches de `concluir()` seguem reais).
     */
    public function test_falha_no_disparo_nao_desfaz_a_publicacao(): void
    {
        $this->montar();
        $this->trocarDespachanteQueLanca();

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PUBLISHED, $p->status);
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status);
        $this->assertSame(PubPublicacaoItem::CREATED, $p->itens()->sole()->status);
        $this->assertSame('MLB9000000001', $p->itens()->sole()->ml_item_id);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    /**
     * Despachante que lança ao receber o job do sync e delega todo o resto ao
     * real — assim o teste prova o `try/catch` fail-open sem quebrar a tarefa
     * pós-publicação nem a promoção automática.
     */
    private function trocarDespachanteQueLanca(): void
    {
        // O `Illuminate\Bus\Dispatcher` CONCRETO é o singleton; os dois
        // contratos são aliases dele (BusServiceProvider). Trocar o concreto é
        // o que de fato alcança o `PendingDispatch::__destruct()`.
        $real = $this->app->make(\Illuminate\Bus\Dispatcher::class);

        $this->app->instance(\Illuminate\Bus\Dispatcher::class, new class($real) implements QueueingDispatcher
        {
            public function __construct(private QueueingDispatcher $real) {}

            public function dispatch($command)
            {
                if ($command instanceof SincronizarAcervoDoPublicadoJob) {
                    throw new \RuntimeException('fila indisponível');
                }

                return $this->real->dispatch($command);
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->real->dispatchSync($command, $handler);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->real->dispatchNow($command, $handler);
            }

            public function dispatchToQueue($command)
            {
                return $this->real->dispatchToQueue($command);
            }

            public function findBatch(string $batchId)
            {
                return $this->real->findBatch($batchId);
            }

            public function batch($jobs)
            {
                return $this->real->batch($jobs);
            }

            public function hasCommandHandler($command)
            {
                return $this->real->hasCommandHandler($command);
            }

            public function getCommandHandler($command)
            {
                return $this->real->getCommandHandler($command);
            }

            public function pipeThrough(array $pipes)
            {
                $this->real->pipeThrough($pipes);

                return $this;
            }

            public function map(array $map)
            {
                $this->real->map($map);

                return $this;
            }
        });

        $this->app->alias(QueueingDispatcher::class, \Illuminate\Contracts\Bus\Dispatcher::class);
    }

    /** Uma publicação de OUTRO rascunho, com item criado — prova o escopo do pluck. */
    private function publicacaoAlheiaCom(string $mlItemId): void
    {
        $outro = PubProduto::create(['company_id' => $this->produto->company_id, 'sku' => 'OUTRA-99',
            'nome' => 'Outra cadeira qualquer', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $rascunho = $this->repo->criar($outro, [new Alvo('gold_special', 'Cadeira Escritório Outra Qualquer ECF')]);

        $pub = PubPublicacao::create(['rascunho_id' => $rascunho->id, 'revisao' => 1, 'modelo_publicacao' => 'UP',
            'status' => PubPublicacao::PUBLISHED, 'chave_idempotencia' => (string) Str::uuid(), 'concluida_em' => now()]);

        PubPublicacaoItem::create(['publicacao_id' => $pub->id, 'indice' => 0, 'listing_type_id' => 'gold_special',
            'variante_chave' => 'alheia', 'status' => PubPublicacaoItem::CREATED, 'ml_item_id' => $mlItemId]);
    }

    /** Três variantes de cor, para a publicação parcial (molde do `PublicacaoTest`). */
    private function tresCores(): void
    {
        $nomes = ['52049' => 'Preto', '52028' => 'Azul', '52055' => 'Branco'];
        $cor = new Eixo('COLOR', 'Cor', 0, true, array_map(fn ($id, $n) => new ValorEixo((string) $id, $n), array_keys($nomes), $nomes));
        $variantes = array_map(fn ($v) => $v->comDados(['estoque' => 2, 'precos' => ['gold_special' => 150.0],
            'atributos' => ['SELLER_SKU' => ['value_name' => 'CAD-'.$v->valores['COLOR']->valueName], 'GTIN' => ['value_name' => '7896553367645']]]),
            RegeneradorVariantes::regenerar($this->repo->snapshot($this->r->fresh())->variantes, [$cor])->variantes);
        $this->repo->gravarVariacao($this->r->fresh(), [$cor], $variantes);

        $atribuicoes = [['imagem' => $this->r->imagens()->value('id'), 'grupo' => R::GERAL, 'posicao' => 0]];
        foreach (array_keys($nomes) as $i => $id) {
            $foto = $this->r->imagens()->create(['caminho' => "publicador/{$id}.jpg", 'sha256' => str_repeat((string) $i, 64),
                'mime' => 'image/jpeg', 'bytes' => 800_000, 'largura' => 1200, 'altura' => 1200,
                'upload_status' => \App\Models\PubImagem::ENVIADA, 'ml_picture_id' => "PIC-{$id}"]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => "COLOR=id:{$id}", 'posicao' => 0];
        }
        $this->repo->gravarAtribuicoes($this->r, $atribuicoes);
    }
}
