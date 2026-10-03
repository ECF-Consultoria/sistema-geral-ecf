<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\EstruturaAnuncio;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\RegraViolada;
use App\Support\Publicador\Variacao\Eixo;
use App\Support\Publicador\Variacao\RegeneradorVariantes;
use App\Support\Publicador\Variacao\ValorEixo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Publicar (E13, `09` §4–5; TC-80, 82–85, 88, 89, 91; D6, D9, D11). O ML é
 * simulado — nenhum anúncio de verdade nasce aqui. O POST /items devolve
 * MLB9000000001, MLB9000000002… na ordem dos envios.
 */
class PublicacaoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private int $posts = 0;

    /** @var \Closure(array, int): mixed o `POST /items` agora */
    private \Closure $criar;

    /** @var \Closure(array): mixed o `POST /items/multiwarehouse` agora */
    private \Closure $multideposito;

    private int $statusDescricao = 200;

    private bool $oauthFalha = false;

    /** @var array<string, array> MLB → corpo do `GET /items?ids=` (reconciliação) */
    private array $noMl = [];

    /** @var list<string> status do item no momento de cada POST /items (prova do SENT antes do envio) */
    private array $statusNoPost = [];

    private AtorDoPortal $ator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario();
        config(['publicador.contas_liberadas.companies' => [$this->empresa->id]]);
        $this->ator = AtorDoPortal::daEquipe(User::factory()->create(['name' => 'Dev ECF']));

        $this->criar = fn (array $corpo, int $n) => Http::response(['id' => sprintf('MLB90000000%02d', $n), 'user_product_id' => "MLBU{$n}", 'status' => 'active',
            'family_name' => $corpo['family_name'] ?? null, 'listing_type_id' => $corpo['listing_type_id'] ?? null], 201);
        $this->multideposito = fn (array $corpo) => Http::response(['id' => 'MLB9100000001', 'status' => 'active'], 201);

        $this->fakeMl([
            '*/oauth/token' => fn () => $this->oauthFalha
                ? Http::response(['error' => 'invalid_grant', 'message' => 'invalid_grant'], 400)
                : Http::response(['access_token' => 'novo-access', 'refresh_token' => 'novo-refresh', 'expires_in' => 21600, 'user_id' => 1555596317]),
            '*/stores/search*' => fn () => Http::response(self::fixture('conta-multideposito/stores_stock_location')),
            '*/items/multiwarehouse' => fn (Request $q) => ($this->multideposito)($q->data()),
            '*/items/*/description' => fn () => Http::response(['text' => '', 'plain_text' => 'ok'], $this->statusDescricao),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => isset($this->noMl[$id]) ? ['code' => 200, 'body' => $this->noMl[$id]] : ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [],
                'tags' => ['incomplete_technical_specs', 'good_quality_picture'], 'permalink' => 'https://produto.mercadolivre.com.br/x']),
            '*/items' => function (Request $q) {
                $this->statusNoPost[] = PubPublicacaoItem::query()->latest('enviado_em')->value('status');

                return ($this->criar)($q->data(), ++$this->posts);
            },
        ]);
    }

    private function conferir(): void
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
    }

    private function iniciar(): PubPublicacao
    {
        return app(PublicacaoService::class)->iniciar($this->r->fresh(), $this->ator, cienteDosAvisos: true);
    }

    private function fatia(PubPublicacao $p): bool
    {
        return app(PublicacaoService::class)->executarFatia($p->fresh(), 45);
    }

    private function publicar(): PubPublicacao
    {
        $this->conferir();
        $p = $this->iniciar();
        $this->assertTrue($this->fatia($p));

        return $p->fresh();
    }

    private function postsDeItem(): int
    {
        return count(Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/items')));
    }

    /** O item que a busca por SKU acha: do mesmo tipo, família e categoria, criado agora. */
    private function noMlComo(string $mlb, array $mudar = []): void
    {
        $this->noMl[$mlb] = ['id' => $mlb, 'listing_type_id' => 'gold_special', 'category_id' => self::CADEIRA,
            'family_name' => 'Cadeira Escritório Executiva ECF Giratória', 'date_created' => now()->toIso8601String(), 'user_product_id' => 'MLBU77', ...$mudar];
    }

    // ═══ Caminho feliz ═══════════════════════════════════════════════════════

    public function test_publica_grava_o_mlb_descricao_estado_e_completa_o_planejado_da_regua(): void
    {
        $planejado = EstruturaAnuncio::create(['oferta_id' => $this->r->produto->oferta_id, 'tipo' => 'classico', 'titulo' => 'Planejado', 'status' => 'ativo']);

        $this->conferir();
        $p = $this->iniciar();

        Queue::assertPushedOn('high', PublicarRascunhoJob::class);
        $this->assertSame(PubPublicacao::RUNNING, $p->status);
        $this->assertSame(PubRascunho::PUBLISHING, $this->r->fresh()->status);

        $this->assertTrue($this->fatia($p));

        $item = $p->itens()->sole();
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('MLB9000000001', $item->ml_item_id);
        $this->assertSame('MLBU1', $item->ml_user_product_id);
        $this->assertSame(['SENT'], $this->statusNoPost, 'SENT gravado ANTES do POST (D9)');
        $this->assertSame(201, $item->resposta['status']);
        $this->assertSame('Cadeira Escritório Executiva ECF Giratória', $item->payload['family_name'], 'o payload enviado fica guardado');
        $this->assertSame('OK', $item->descricao_status);
        $this->assertSame(['plain_text' => 'Cadeira executiva giratória.'], collect(Http::recorded(fn (Request $q) => str_ends_with($q->url(), '/description')))->first()[0]->data());
        $this->assertSame(['status' => 'active', 'sub_status' => [], 'tags' => ['incomplete_technical_specs'], 'permalink' => 'https://produto.mercadolivre.com.br/x'], $item->avisos['estado']);

        $this->assertSame(PubPublicacao::PUBLISHED, $p->fresh()->status);
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status);
        $this->assertTrue((bool) $this->r->variantes()->value('publicada'));

        // O planejado da aba Anúncios ganhou o MLB — não nasceu um segundo Clássico.
        $this->assertSame('MLB9000000001', $planejado->fresh()->codigo_mlb);
        $this->assertSame(1, EstruturaAnuncio::where('oferta_id', $this->r->produto->oferta_id)->count());

        $this->assertStringNotContainsString('fake-access-token', json_encode(PubPublicacaoItem::all()->toArray()));
        $this->assertStringNotContainsString('fake-access-token', json_encode($p->fresh()->toArray()));
    }

    // ═══ Portão (RN-90, D6) ══════════════════════════════════════════════════

    public function test_so_publica_com_conferencia_valida_para_a_versao_atual(): void
    {
        $servico = app(PublicacaoService::class);
        $tenta = function (bool $ciente = true) use ($servico) {
            try {
                $servico->iniciar($this->r->fresh(), $this->ator, $ciente);

                return null;
            } catch (RegraViolada $e) {
                return $e->regra.': '.$e->getMessage();
            }
        };

        $this->assertStringStartsWith('RN-90', $tenta(), 'sem conferência');

        $this->conferir();
        $this->assertStringContainsString('Estou ciente', $tenta(false), 'avisos do ML precisam do "Estou ciente"');

        $this->repo->tocar($this->r->fresh());
        $this->assertStringStartsWith('RN-90', $tenta(), 'editou depois de conferir');

        $this->conferir();
        config(['publicador.contas_liberadas.companies' => [999999]]);
        $this->assertStringStartsWith('CONTA-LIB', $tenta(), 'conta não liberada não publica');

        // Liberar a MlbEmpresa de mesmo número NÃO libera a Company (listas separadas por âncora).
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => [$this->empresa->id]]]);
        $this->assertStringStartsWith('CONTA-LIB', $tenta(), 'mlb_empresas não libera a Company de mesmo id');
        $this->assertSame(0, $this->postsDeItem());

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->assertNull($tenta());
        $this->assertStringStartsWith('RN-93', $tenta(), 'uma publicação por vez');
        $this->assertSame(0, $this->postsDeItem());
    }

    public function test_preco_que_mudou_depois_da_conferencia_aborta_sem_postar(): void
    {
        $this->conferir();
        $p = $this->iniciar();
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->efetivos['precos']['gold_special'] = 189.9; // a Precificação mudou no meio

        $this->assertTrue($this->fatia($p));

        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('mudou desde a conferência', $p->fresh()->conta_snapshot['motivo']);
        $this->assertSame(0, $this->postsDeItem());
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status);
    }

    public function test_tc89_conta_que_mudou_de_modelo_aborta(): void
    {
        $this->conferir();
        $p = $this->iniciar();
        $this->usuario = ['id' => 1555596317, 'tags' => ['business', 'normal']]; // sem user_product_seller

        $this->assertTrue($this->fatia($p));

        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('mudou de modelo', $p->fresh()->conta_snapshot['motivo']);
        $this->assertSame(0, $this->postsDeItem());
    }

    public function test_tc88_conta_desconectada_no_meio_falha_com_mensagem(): void
    {
        $this->conferir();
        $p = $this->iniciar();
        MlToken::query()->update(['expires_at' => now()->addMinute()]);
        $this->oauthFalha = true;

        $this->assertTrue($this->fatia($p));

        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('reconectada', $p->fresh()->conta_snapshot['motivo']);
        $this->assertSame(0, $this->postsDeItem());
    }

    // ═══ Erros do ML ═════════════════════════════════════════════════════════

    public function test_tc80_erro_do_ml_falha_o_item_e_aponta_o_campo(): void
    {
        $this->criar = fn () => Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [
            ['department' => 'items', 'cause_id' => 147, 'type' => 'error', 'code' => 'item.attributes.missing_required', 'references' => ['item.attributes'], 'message' => 'The attributes [MODEL] are required for category MLB193945.'],
        ]], 400);

        $p = $this->publicar();

        $item = $p->itens()->sole();
        $this->assertSame(PubPublicacaoItem::FAILED, $item->status);
        $this->assertSame(400, $item->http_status);
        $this->assertSame(147, $item->resposta['corpo']['cause'][0]['cause_id'], 'resposta bruta guardada');
        $this->assertSame(PubPublicacao::FAILED, $p->status);
        $this->assertSame(PubRascunho::FAILED, $this->r->fresh()->status);

        [$problema] = app(PublicacaoService::class)->problemas($p);
        $this->assertSame('MODEL', $problema->alvo['atributo']);
        $this->assertSame('Preencha os atributos obrigatórios: «Modelo».', $problema->mensagem);
        $this->assertSame(0, EstruturaAnuncio::where('oferta_id', $this->r->produto->oferta_id)->count());
    }

    public function test_tc82_aviso_na_criacao_fica_visivel(): void
    {
        $this->criar = fn () => Http::response(['id' => 'MLB9000000001', 'cause' => [
            ['cause_id' => 382, 'type' => 'warning', 'code' => 'item.category_id.migrated', 'message' => 'Category migrated', 'references' => ['item.category_id']],
        ]], 201);

        $item = $this->publicar()->itens()->sole();

        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame(382, $item->avisos['causas'][0]['cause_id']);
    }

    public function test_tc91_descricao_que_falha_nao_recria_o_item_e_pode_ser_reenviada(): void
    {
        $this->statusDescricao = 400;
        $item = $this->publicar()->itens()->sole();

        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('FAILED', $item->descricao_status);

        $this->statusDescricao = 200;
        $this->assertSame('OK', app(PublicacaoService::class)->reenviarDescricao($item)->descricao_status);
        $this->assertSame(1, $this->postsDeItem(), 'o item não é recriado');
    }

    // ═══ Falha parcial e retentativa (RN-94) ═════════════════════════════════

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
            $foto = $this->r->imagens()->create(['caminho' => "publicador/{$id}.jpg", 'sha256' => str_repeat((string) $i, 64), 'mime' => 'image/jpeg', 'bytes' => 800_000,
                'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => "PIC-{$id}"]);
            $atribuicoes[] = ['imagem' => $foto->id, 'grupo' => "COLOR=id:{$id}", 'posicao' => 0];
        }
        $this->repo->gravarAtribuicoes($this->r, $atribuicoes);
    }

    public function test_tc83_falha_parcial_mantem_os_criados_e_retentar_envia_so_o_que_faltou(): void
    {
        $this->tresCores();
        $criar = $this->criar;
        $this->criar = fn (array $corpo, int $n) => $n === 2
            ? Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [['cause_id' => 3701, 'type' => 'error', 'code' => 'item.attribute.product_identifier.invalid', 'message' => 'GTIN', 'references' => ['item.attributes']]]], 400)
            : $criar($corpo, $n);

        $p = $this->publicar();

        $this->assertSame(PubPublicacao::PARTIALLY_PUBLISHED, $p->status);
        $this->assertSame(PubRascunho::PARTIALLY_PUBLISHED, $this->r->fresh()->status);
        $this->assertSame([PubPublicacaoItem::CREATED, PubPublicacaoItem::FAILED, PubPublicacaoItem::CREATED], $p->itens()->pluck('status')->all());
        $this->assertSame(['MLB9000000001', null, 'MLB9000000003'], $p->itens()->pluck('ml_item_id')->all());
        $this->assertSame([true, false, true], $this->r->variantes()->orderBy('posicao')->pluck('publicada')->map(fn ($x) => (bool) $x)->all());
        // A régua recebe o 1º item criado do tipo.
        $this->assertSame(['MLB9000000001'], EstruturaAnuncio::where('oferta_id', $this->r->produto->oferta_id)->pluck('codigo_mlb')->all());

        // Retentar: o ML aceita agora; só o Azul vai.
        $this->criar = $criar;
        $nova = $this->iniciar();
        $this->assertTrue($this->fatia($nova));

        $this->assertSame(['CAD-Azul'], collect($nova->itens()->get())->map(fn ($i) => collect($i->payload['attributes'])->firstWhere('id', 'SELLER_SKU')['value_name'])->all());
        $this->assertSame(4, $this->postsDeItem());
        $this->assertSame(PubPublicacao::PUBLISHED, $nova->fresh()->status);
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status);
        $this->assertSame(['MLB9000000001'], EstruturaAnuncio::where('oferta_id', $this->r->produto->oferta_id)->pluck('codigo_mlb')->all(), 'a régua não ganha um segundo Clássico');
    }

    // ═══ Timeout e reconciliação (RN-93, `09` §5) ════════════════════════════

    public function test_tc84_timeout_reconcilia_pelo_sku_e_adota_sem_reenviar(): void
    {
        $this->criar = fn () => Http::failedConnection();
        $this->conferir();
        $p = $this->iniciar();

        // A busca do ML ainda não enxerga o item: espera, não reenvia.
        $this->assertFalse($this->fatia($p));
        $this->assertSame(PubPublicacaoItem::UNKNOWN, $p->itens()->sole()->status);

        // Um minuto depois ele aparece na busca por SKU.
        $this->travel(1)->minutes();
        $this->skuEm['CAD-01'] = ['MLB5550000001'];
        $this->noMlComo('MLB5550000001', ['date_created' => now()->subSeconds(50)->toIso8601String()]);
        $this->assertTrue($this->fatia($p));

        $item = $p->itens()->sole();
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('MLB5550000001', $item->ml_item_id);
        $this->assertSame(1, $this->postsDeItem(), 'nunca reenviado');
        $this->assertSame('OK', $item->descricao_status);
        $this->assertSame(PubPublicacao::PUBLISHED, $p->fresh()->status);
    }

    public function test_reconciliacao_nao_adota_anuncio_de_outro_tipo_ou_antigo(): void
    {
        $this->criar = fn () => Http::failedConnection();
        $this->skuEm['CAD-01'] = ['MLB1', 'MLB2'];
        $this->noMlComo('MLB1', ['listing_type_id' => 'gold_pro']);                                   // o Premium do par
        $this->noMlComo('MLB2', ['date_created' => now()->subDays(30)->toIso8601String()]);         // um anúncio antigo
        $this->conferir();
        $p = $this->iniciar();

        $this->assertFalse($this->fatia($p));
        $this->assertSame(PubPublicacaoItem::UNKNOWN, $p->itens()->sole()->status);
        $this->assertNull($p->itens()->sole()->ml_item_id);
    }

    public function test_tc85_timeout_sem_item_reenvia_uma_vez_e_so_uma(): void
    {
        $this->criar = fn () => Http::failedConnection();
        $this->conferir();
        $p = $this->iniciar();
        $this->assertFalse($this->fatia($p));

        // 4 min depois, a busca segue vazia: reenvia (2ª tentativa) — e falha de novo.
        $this->travel(4)->minutes();
        $this->assertFalse($this->fatia($p));
        $this->assertSame(2, $this->postsDeItem());
        $this->assertSame(2, $p->itens()->sole()->tentativas);

        // Mais 4 min, nada achado: não há 3º envio — falha pedindo conferência manual.
        $this->travel(4)->minutes();
        $this->assertTrue($this->fatia($p));
        $item = $p->itens()->sole();
        $this->assertSame(PubPublicacaoItem::FAILED, $item->status);
        $this->assertStringContainsString('Confira no Mercado Livre', $item->avisos['mensagem']);
        $this->assertSame(2, $this->postsDeItem());
    }

    public function test_d9_item_sent_deixado_por_execucao_morta_vai_para_a_reconciliacao(): void
    {
        $this->conferir();
        $p = $this->iniciar();
        // A 1ª fatia preparou os itens; o processo morre logo depois de gravar SENT.
        $this->criar = function () {
            throw new \RuntimeException('o worker morreu no meio do POST');
        };
        try {
            $this->fatia($p);
        } catch (\RuntimeException) {
        }
        $this->assertSame(PubPublicacaoItem::SENT, $p->itens()->sole()->status);

        // A reentrega acha o item pelo SKU: adota, sem POST novo.
        $this->criar = fn (array $c, int $n) => Http::response(['id' => 'MLB-DUPLICADO'], 201);
        $this->skuEm['CAD-01'] = ['MLB5550000002'];
        $this->noMlComo('MLB5550000002');
        $this->assertTrue($this->fatia($p));

        $this->assertSame('MLB5550000002', $p->itens()->sole()->ml_item_id);
        $this->assertSame(1, $this->posts, 'só o POST da execução que morreu — a reentrega não posta');
    }

    public function test_publicacao_nova_reconcilia_o_incerto_da_anterior_antes_de_postar(): void
    {
        $this->criar = fn () => Http::failedConnection();
        $this->conferir();
        $p = $this->iniciar();
        $this->assertFalse($this->fatia($p));
        app(PublicacaoService::class)->abandonar($p->fresh(), 'interrompida');

        $nova = $this->iniciar();
        $this->skuEm['CAD-01'] = ['MLB5550000003'];
        $this->noMlComo('MLB5550000003');
        $this->assertTrue($this->fatia($nova));

        $this->assertSame('MLB5550000003', $nova->itens()->sole()->ml_item_id);
        $this->assertSame(1, $this->postsDeItem());
    }

    // ═══ D11: multidepósito ══════════════════════════════════════════════════

    private function contaMultideposito(): void
    {
        $this->usuario = ['id' => 1555596317, 'tags' => ['user_product_seller', 'warehouse_management', 'multiwarehouse']];
        $unica = $this->repo->snapshot($this->r->fresh())->variantes[0];
        $this->repo->gravarVariacao($this->r->fresh(), [], [$unica->comDados([...$unica->dados, 'estoque' => 5, 'estoque_depositos' => ['DEPOSITO_1' => 3, 'DEPOSITO_2' => 2]])]);
    }

    public function test_d11_estoque_por_deposito_vai_pelo_caminho_proprio(): void
    {
        $this->contaMultideposito();

        $item = $this->publicar()->itens()->sole();

        $this->assertSame('items_multiwarehouse', $item->caminho);
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertSame('MLB9100000001', $item->ml_item_id);
        $this->assertSame([['store_id' => 'DEPOSITO_1', 'quantity' => 3], ['store_id' => 'DEPOSITO_2', 'quantity' => 2]], $item->payload['stock_locations']);
        $this->assertSame(5, $item->payload['available_quantity'], 'continua no corpo: sem ele, 369 (N-19)');
        $this->assertSame(0, $this->postsDeItem());
    }

    public function test_d11_plano_b_quando_o_ml_recusa_o_caminho_por_deposito(): void
    {
        $this->contaMultideposito();
        $this->multideposito = fn () => Http::response(['message' => 'body.invalid_fields', 'error' => 'The fields [stock_locations] are invalid for requested call.', 'status' => 400, 'cause' => []], 400);

        $item = $this->publicar()->itens()->sole();

        $this->assertSame('plano_b', $item->caminho);
        $this->assertSame(PubPublicacaoItem::CREATED, $item->status);
        $this->assertArrayNotHasKey('stock_locations', $item->payload);
        $this->assertSame(400, $item->avisos['plano_b']['status'], 'a recusa do ML fica guardada');
        $this->assertSame(1, $this->postsDeItem());
    }

    // ═══ CR-B01: a conta fixada no clique vale em TODA escrita ═══════════════

    /** Uma MlbEmpresa no MESMO produto (D20: a MlbEmpresa vem antes da Company na escolha da âncora). */
    private function mlbEmpresaNoProduto(bool $comToken, bool $liberada): MlbEmpresa
    {
        $e = MlbEmpresa::create(['nome' => 'Dev 02 (Polos)', 'projeto' => 'POLOS', 'company_id' => $this->empresa->id]);
        $this->produto->update(['mlb_empresa_id' => $e->id]);
        if ($comToken) {
            $this->tokenDaMlbEmpresa($e);
        }
        if ($liberada) {
            config(['publicador.contas_liberadas.mlb_empresas' => [$e->id]]);
        }

        return $e;
    }

    private function tokenDaMlbEmpresa(MlbEmpresa $e, string $seller = '1555596317'): void
    {
        MlToken::create(['company_id' => null, 'mlb_empresa_id' => $e->id, 'ml_user_id' => $seller, 'access_token' => 'token-da-mlb-empresa', 'refresh_token' => 'x',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
    }

    private function chamadasComToken(string $token): int
    {
        return count(Http::recorded(fn (Request $q) => ($q->header('Authorization')[0] ?? '') === "Bearer {$token}"));
    }

    private function postsDeDescricao(): int
    {
        return count(Http::recorded(fn (Request $q) => $q->method() === 'POST' && str_ends_with($q->url(), '/description')));
    }

    /** 1º POST some no timeout: o item fica UNKNOWN esperando a reconciliação — a janela em que a âncora muda. */
    private function publicacaoEsperandoReconciliar(): PubPublicacao
    {
        $this->criar = fn () => Http::failedConnection();
        $this->conferir();
        $p = $this->iniciar();
        $this->assertFalse($this->fatia($p));
        $this->assertSame(PubPublicacaoItem::UNKNOWN, $p->itens()->sole()->status);
        $this->assertSame(1, $this->postsDeItem());
        // Daqui em diante, qualquer POST /items "criaria" o anúncio — o teste prova que nenhum sai.
        $this->criar = fn () => Http::response(['id' => 'MLB-NA-CONTA-ERRADA', 'status' => 'active'], 201);
        $this->travel(4)->minutes();

        return $p;
    }

    public function test_cr_b01_iniciar_fixa_a_ancora_e_o_vendedor_conferidos(): void
    {
        $p = $this->publicar();

        $this->assertSame(['chave' => 'company-'.$this->empresa->id, 'seller' => '1555596317'], $p->ator['conta']);
        $this->assertTrue($p->ator['equipe'], 'o ator continua gravado ao lado da conta');
    }

    public function test_cr_b01_iniciar_recusa_conferencia_de_outro_vendedor_ou_sem_vendedor(): void
    {
        $tenta = function () {
            try {
                $this->iniciar();

                return null;
            } catch (RegraViolada $e) {
                return $e->regra.': '.$e->getMessage();
            }
        };
        $this->conferir();

        MlToken::where('company_id', $this->empresa->id)->update(['ml_user_id' => '7770001']); // reconectaram outra conta do ML na empresa
        $this->assertStringStartsWith('RN-90: A conta do Mercado Livre deste produto mudou desde a conferência', (string) $tenta());

        MlToken::where('company_id', $this->empresa->id)->update(['ml_user_id' => '1555596317']);
        $this->r->validacoes()->latest('id')->first()->update(['respostas_ml' => []]); // conferência sem o vendedor lido
        $this->assertStringStartsWith('RN-90: A conta do Mercado Livre deste produto mudou desde a conferência', (string) $tenta());

        $this->assertSame(0, PubPublicacao::count());
        $this->assertSame(0, $this->postsDeItem());
    }

    public function test_cr_b01_oauth_de_mlb_empresa_nao_liberada_concluido_no_meio_nao_recebe_post(): void
    {
        $e = $this->mlbEmpresaNoProduto(comToken: false, liberada: false);
        $p = $this->publicacaoEsperandoReconciliar();
        $this->assertSame('company-'.$this->empresa->id, $p->ator['conta']['chave']);

        // Alguém conclui o OAuth da MlbEmpresa pelo link do Onboarding: a âncora do produto vira ela.
        $this->tokenDaMlbEmpresa($e);
        $this->assertTrue($this->fatia($p));

        $this->assertSame(1, $this->postsDeItem(), 'nenhum POST /items depois da troca de âncora');
        $this->assertSame(0, $this->chamadasComToken('token-da-mlb-empresa'), 'a conta não liberada não recebe nem leitura');
        Http::assertNotSent(fn (Request $q) => $q->method() === 'POST' && str_contains($q->url(), '/description'));
        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('mudou desde o clique em Publicar', $p->fresh()->conta_snapshot['motivo']);
        $this->assertSame(PubPublicacaoItem::UNKNOWN, $p->itens()->sole()->status, 'o incerto continua incerto para a próxima reconciliar');
    }

    public function test_cr_b01_token_revogado_que_derruba_para_outra_ancora_nao_publica_nela(): void
    {
        // As DUAS âncoras liberadas: mesmo assim a troca de conta no meio para tudo.
        $e = $this->mlbEmpresaNoProduto(comToken: true, liberada: true);
        $p = $this->publicacaoEsperandoReconciliar();
        $this->assertSame('empresa-'.$e->id, $p->ator['conta']['chave']);
        $this->assertSame(0, $this->chamadasComToken('fake-access-token'), 'até aqui só o token da MlbEmpresa');

        // O sync diário recebe invalid_grant e grava `revoked`: a âncora cai para a Company.
        MlToken::where('mlb_empresa_id', $e->id)->update(['status' => 'revoked']);
        $this->assertTrue($this->fatia($p));

        $this->assertSame(1, $this->postsDeItem());
        $this->assertSame(0, $this->chamadasComToken('fake-access-token'), 'a Company não recebe nada desta publicação');
        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('mudou desde o clique em Publicar', $p->fresh()->conta_snapshot['motivo']);
    }

    public function test_cr_b01_conta_tirada_da_lista_com_publicacao_rodando_nao_recebe_post(): void
    {
        $p = $this->publicacaoEsperandoReconciliar();

        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]); // .env + config:cache no meio
        $this->assertTrue($this->fatia($p));

        $this->assertSame(1, $this->postsDeItem());
        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('não foi liberada', $p->fresh()->conta_snapshot['motivo']);
    }

    public function test_cr_b01_token_de_outro_vendedor_na_mesma_ancora_nao_recebe_post(): void
    {
        $p = $this->publicacaoEsperandoReconciliar();

        MlToken::where('company_id', $this->empresa->id)->update(['ml_user_id' => '7770001']); // OAuth com outra conta do ML
        $this->assertTrue($this->fatia($p));

        $this->assertSame(1, $this->postsDeItem());
        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertStringContainsString('outro vendedor', $p->fresh()->conta_snapshot['motivo']);
    }

    public function test_cr_b01_conta_que_muda_entre_itens_da_mesma_fatia_para_o_resto_e_marca_o_motivo(): void
    {
        $this->tresCores();
        $criar = $this->criar;
        // O 1º item é criado; logo depois a conta sai da lista (antes da descrição e dos outros dois).
        $this->criar = function (array $corpo, int $n) use ($criar) {
            config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

            return $criar($corpo, $n);
        };

        $p = $this->publicar();

        $this->assertSame(1, $this->postsDeItem(), 'só o item que saiu antes da troca');
        $this->assertSame(0, $this->postsDeDescricao(), 'a descrição também é escrita: não sai');
        $this->assertSame([PubPublicacaoItem::CREATED, PubPublicacaoItem::FAILED, PubPublicacaoItem::FAILED], $p->itens()->pluck('status')->all());
        $this->assertStringContainsString('não foi liberada', $p->itens()->get()[1]->avisos['mensagem']);
        $this->assertSame(PubPublicacao::FAILED, $p->status);
        $this->assertSame(PubRascunho::PARTIALLY_PUBLISHED, $this->r->fresh()->status, 'o item criado continua contando');
    }

    public function test_cr_b01_reenviar_descricao_so_vai_para_a_conta_fixada_e_liberada(): void
    {
        $this->statusDescricao = 400;
        $item = $this->publicar()->itens()->sole();
        $this->assertSame(1, $this->postsDeDescricao());
        $this->statusDescricao = 200;
        $servico = app(PublicacaoService::class);
        $tenta = function () use ($servico, $item) {
            try {
                $servico->reenviarDescricao($item->fresh());

                return null;
            } catch (RegraViolada $e) {
                return $e->regra;
            }
        };

        config(['publicador.contas_liberadas.companies' => []]);
        $this->assertSame('CONTA-LIB', $tenta(), 'conta tirada da lista');

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $e = $this->mlbEmpresaNoProduto(comToken: true, liberada: true);
        $this->assertSame('V-ACC-03', $tenta(), 'a âncora do produto mudou: o item nasceu na Company');
        $this->assertSame(1, $this->postsDeDescricao(), 'nenhum POST de descrição nas recusas');
        $this->assertSame(0, $this->chamadasComToken('token-da-mlb-empresa'));

        MlToken::where('mlb_empresa_id', $e->id)->delete();
        $this->assertNull($tenta());
        $this->assertSame('OK', $item->fresh()->descricao_status);
        $this->assertSame(2, $this->postsDeDescricao());
    }

    // ═══ Job ═════════════════════════════════════════════════════════════════

    public function test_job_com_a_trava_ocupada_volta_para_a_fila_sem_tocar_em_nada(): void
    {
        $this->conferir();
        $p = $this->iniciar();
        $trava = Cache::lock("publicador:publicacao:{$p->id}", 600);
        $trava->get();

        $job = (new PublicarRascunhoJob($p->id))->withFakeQueueInteractions();
        $job->handle(app(PublicacaoService::class));

        $job->assertReleased(20);
        $this->assertSame(0, $p->itens()->count());
        $trava->release();
    }

    public function test_job_que_nao_terminou_volta_para_a_fila_e_o_que_quebrou_encerra(): void
    {
        $this->criar = fn () => Http::failedConnection();
        $this->conferir();
        $p = $this->iniciar();

        $job = (new PublicarRascunhoJob($p->id))->withFakeQueueInteractions();
        $job->handle(app(PublicacaoService::class));
        $job->assertReleased(15);

        (new PublicarRascunhoJob($p->id))->failed(new \RuntimeException('boom'));
        $this->assertSame(PubPublicacao::FAILED, $p->fresh()->status);
        $this->assertSame(PubPublicacaoItem::UNKNOWN, $p->itens()->sole()->status, 'continua incerto: a próxima reconcilia');
        $this->assertSame(PubRascunho::DRAFT, $this->r->fresh()->status);
    }
}
