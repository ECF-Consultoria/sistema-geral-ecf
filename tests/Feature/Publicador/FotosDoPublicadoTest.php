<?php

namespace Tests\Feature\Publicador;

use App\Models\PubImagem;
use App\Models\PubPublicacao;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\FotosDoPublicadoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * As fotos de um produto JÁ PUBLICADO (28/10/2026). O usuário, com o print de um produto publicado: "ainda não dá para
 * fazer upload de novas fotos e nem mudar a posição". As fotos mudam no rascunho; o anúncio no ar só muda pelo
 * "Atualizar fotos no Mercado Livre" (`PUT /items/{id}` com `pictures`). O ML é simulado: nada real é tocado.
 */
class FotosDoPublicadoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private int $posts = 0;

    /** Falso = o `PUT /items/{id}` é recusado com 400. */
    private bool $putAceito = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->montarCenario();
        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/pictures/items/upload' => fn () => Http::response(['id' => 'P-SUBIU', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/P-SUBIU.jpg']]], 200),
            '*/items/MLB*' => function (Request $q) {
                if ($q->method() === 'PUT' && ! $this->putAceito) {
                    return Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400,
                        'cause' => [['cause_id' => 1, 'type' => 'error', 'code' => 'item.pictures.invalid', 'message' => 'Invalid pictures', 'references' => []]]], 400);
                }

                return Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'status' => 'active', 'sub_status' => [], 'tags' => [],
                    'permalink' => 'https://produto.mercadolivre.com.br/x', 'shipping' => ['mode' => 'me2', 'free_shipping' => true]]);
            },
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB80000000%02d', ++$this->posts), 'status' => 'active'], 201),
        ]);
        $this->efetivos = ['titulos' => ['gold_special' => null], 'precos' => ['gold_special' => 207.19], 'mlbs' => []];
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function publicar(): PubPublicacao
    {
        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe($this->admin), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status);

        return $p->fresh();
    }

    /** Uma foto já no ML, posta no FIM das gerais (como o "+ Foto" faz). */
    private function fotoNoMl(string $mlId): PubImagem
    {
        $foto = $this->r->imagens()->create(['caminho' => "publicador/{$mlId}.jpg", 'sha256' => hash('sha256', $mlId), 'mime' => 'image/jpeg',
            'bytes' => 800_000, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => $mlId]);
        app(EditorRascunhoService::class)->colocarFotoNoGrupo($this->r->fresh(), $foto, 'GENERAL');

        return $foto;
    }

    private function abrir(): array
    {
        return $this->actingAs($this->admin)->getJson(route('mlb.anuncios.publicador.abrir', ['produto' => $this->produto->id]))->assertOk()->json();
    }

    private function atualizar()
    {
        return $this->actingAs($this->admin)->postJson(route('mlb.anuncios.publicador.fotos.no-ml', ['produto' => $this->produto->id]));
    }

    /** @return list<array<string, mixed>> os `PUT /items/{id}` mandados */
    private function puts(): array
    {
        return array_values(array_map(fn ($par) => ['url' => $par[0]->url(), 'corpo' => $par[0]->data()],
            Http::recorded(fn (Request $q) => $q->method() === 'PUT' && str_contains($q->url(), '/items/MLB'))->all()));
    }

    public function test_nao_publicado_nao_tem_fotos_no_ml(): void
    {
        $this->assertNull($this->abrir()['fotos_no_ml']);
    }

    public function test_publicado_sem_mudanca_diz_que_as_fotos_sao_as_mesmas(): void
    {
        $this->publicar();

        $this->assertSame(['anuncios' => 1, 'diferentes' => []], $this->abrir()['fotos_no_ml']);
    }

    public function test_foto_nova_e_ordem_nova_vao_ao_anuncio_no_ar_so_pelo_botao(): void
    {
        $this->publicar();
        $capa = $this->r->imagens()->firstOrFail();
        $nova = $this->fotoNoMl('P-NOVA');
        // A nova vira a capa (o "tornar capa" da tela).
        $resposta = $this->actingAs($this->admin)->putJson(route('mlb.anuncios.publicador.fotos.atribuir', ['produto' => $this->produto->id]), ['atribuicoes' => [
            ['imagem' => $nova->id, 'grupo' => 'GENERAL', 'posicao' => 0], ['imagem' => $capa->id, 'grupo' => 'GENERAL', 'posicao' => 1],
        ]])->assertOk()->json();

        $this->assertSame([], $this->puts(), 'mexer nas fotos não escreve no anúncio no ar');
        $this->assertCount(1, $resposta['fotos_no_ml']['diferentes']);
        $this->assertSame('Clássico', $resposta['fotos_no_ml']['diferentes'][0]['tipo']);
        $this->assertSame(PubRascunho::PUBLISHED, $this->r->fresh()->status, 'continua publicado');

        $depois = $this->atualizar()->assertOk()->json();

        $puts = $this->puts();
        $this->assertCount(1, $puts);
        $this->assertStringEndsWith('/items/MLB8000000001', $puts[0]['url']);
        $this->assertSame(['pictures' => [['id' => 'P-NOVA'], ['id' => '123-MLB1_102026']]], $puts[0]['corpo'], 'só as fotos, na ordem nova');
        $this->assertCount(1, $depois['fotos_atualizadas']['atualizados']);
        $this->assertSame([], $depois['fotos_atualizadas']['falhas']);
        $this->assertSame([], $depois['fotos_no_ml']['diferentes'], 'depois de atualizar, iguais');

        // Atualizar de novo sem mudar nada não escreve outra vez.
        $this->atualizar()->assertOk();
        $this->assertCount(1, $this->puts());
    }

    public function test_foto_que_ainda_nao_subiu_sobe_antes_de_atualizar(): void
    {
        $this->publicar();
        // O arquivo falso numa variável ANTES de ler (learnings publicador-ml §9: na mesma linha ele já foi liberado).
        $arquivo = UploadedFile::fake()->image('n.jpg', 1200, 1200);
        $res = app(\App\Services\Publicador\ImagemAssetService::class)->receber($this->r->fresh(), file_get_contents($arquivo->getPathname()), 'n.jpg', false);
        app(EditorRascunhoService::class)->colocarFotoNoGrupo($this->r->fresh(), $res['imagem'], 'GENERAL');
        $this->assertSame(PubImagem::PENDENTE, $res['imagem']->fresh()->upload_status);

        $this->atualizar()->assertOk();

        $this->assertSame(['pictures' => [['id' => '123-MLB1_102026'], ['id' => 'P-SUBIU']]], $this->puts()[0]['corpo']);
        $this->assertSame(PubImagem::ENVIADA, $res['imagem']->fresh()->upload_status);
    }

    public function test_recusa_do_ml_aparece_e_a_diferenca_continua(): void
    {
        $this->publicar();
        $this->fotoNoMl('P-NOVA');
        $this->putAceito = false;

        $depois = $this->atualizar()->assertOk()->json();

        $this->assertSame([], $depois['fotos_atualizadas']['atualizados']);
        $this->assertCount(1, $depois['fotos_atualizadas']['falhas']);
        $this->assertStringContainsString('recusou as fotos: Invalid pictures', $depois['fotos_atualizadas']['falhas'][0]['mensagem']);
        $this->assertCount(1, $depois['fotos_no_ml']['diferentes'], 'nada foi gravado como enviado');
    }

    public function test_conta_nao_liberada_nao_manda_nada(): void
    {
        $this->publicar();
        $this->fotoNoMl('P-NOVA');
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);

        $this->atualizar()->assertStatus(422);

        $this->assertSame([], $this->puts());
    }

    public function test_sem_anuncio_no_ar_o_botao_e_recusado(): void
    {
        $this->atualizar()->assertStatus(422)->assertJsonPath('regra', 'V-FOTO-01');
        $this->assertSame([], $this->puts());
    }

    public function test_situacao_nunca_derruba_o_editor(): void
    {
        $this->publicar();
        // Sem categoria o plano não sai: a comparação some em silêncio, o editor abre.
        $this->r->update(['categoria_id' => null]);

        $this->assertNull(app(FotosDoPublicadoService::class)->situacao($this->r->fresh()));
    }
}
