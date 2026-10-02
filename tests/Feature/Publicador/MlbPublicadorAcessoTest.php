<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\ConferirRascunhoJob;
use App\Jobs\Publicador\PublicarRascunhoJob;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Segurança do editor interno (T-160-33..39, T-160-76): só admin; autorização por produto
 * (404 para inexistente, empresa arquivada ou sem dono); foto/item de outro rascunho é 404
 * (IDOR); a trava D21 (CONTA-LIB) vira 422 sem job; em conta não liberada a conferência é só
 * local e a miniatura da foto guardada vem da rota interna (D26); nenhum token na resposta.
 */
class MlbPublicadorAcessoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->montarCenario();
        $this->fakeMl(['*/pictures/items/upload' => Http::response(['id' => 'PIC-1', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg']]])]);
    }

    private function admin(): static
    {
        return $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function rota(string $nome, array $extra = [], ?PubProduto $p = null): string
    {
        return route("mlb.anuncios.publicador.{$nome}", ['produto' => ($p ?? $this->produto)->id, ...$extra]);
    }

    private function naoLiberar(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
    }

    /** Um segundo produto/rascunho da MESMA empresa, para os testes de IDOR. */
    private function outroRascunho(): PubRascunho
    {
        $p = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'OUT-01', 'nome' => 'Outro', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        return $this->repo->criar($p, [new Alvo('gold_special', 'Outro produto')]);
    }

    // ═══ Acesso ═════════════════════════════════════════════════════════════

    public function test_nao_admin_leva_403_em_todas_as_familias_de_rota(): void
    {
        $consultor = $this->withoutVite()->actingAs(User::factory()->create(['role' => 'consultor']));
        $img = $this->r->imagens()->firstOrFail()->id;

        $consultor->getJson($this->rota('abrir'))->assertForbidden();
        $consultor->putJson($this->rota('salvar'), ['condicao' => 'new'])->assertForbidden();
        $consultor->putJson($this->rota('categoria'), ['categoria_id' => 'MLB1'])->assertForbidden();
        $consultor->post($this->rota('fotos'), [], ['Accept' => 'application/json'])->assertForbidden();
        $consultor->getJson($this->rota('fotos.arquivo', ['imagem' => $img]))->assertForbidden();
        $consultor->postJson($this->rota('conferir'))->assertForbidden();
        $consultor->postJson($this->rota('publicar'))->assertForbidden();
        $consultor->getJson($this->rota('simular'))->assertForbidden();
        $consultor->getJson(route('mlb.anuncios.publicador.categorias', ['q' => 'x']))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_produto_inexistente_arquivado_ou_sem_dono_e_404(): void
    {
        $this->admin()->getJson(route('mlb.anuncios.publicador.abrir', 999999))->assertNotFound();

        $e = MlbEmpresa::create(['nome' => 'Polo Arquivado', 'projeto' => 'POLOS'])->fresh();
        $arquivado = PubProduto::create(['mlb_empresa_id' => $e->id, 'sku' => 'ARQ-1', 'nome' => 'Arq', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->admin()->getJson(route('mlb.anuncios.publicador.abrir', $arquivado->id))->assertOk();
        $e->forceFill(['arquivado_em' => now()])->save();
        $this->admin()->getJson(route('mlb.anuncios.publicador.abrir', $arquivado->id))->assertNotFound();
        $this->admin()->putJson(route('mlb.anuncios.publicador.salvar', $arquivado->id), ['condicao' => 'new'])->assertNotFound();

        $semDono = PubProduto::create(['sku' => 'ORF-1', 'nome' => 'Órfão', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $this->admin()->getJson(route('mlb.anuncios.publicador.abrir', $semDono->id))->assertNotFound();
        $this->assertSame(0, PubRascunho::where('produto_id', $semDono->id)->count());
    }

    public function test_foto_e_item_de_outro_rascunho_sao_404(): void
    {
        $outro = $this->outroRascunho();
        $fotoAlheia = $outro->imagens()->create(['caminho' => 'publicador/alheia.jpg', 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg', 'bytes' => 1000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'X']);
        Storage::disk('local')->put($fotoAlheia->caminho, 'conteudo-alheio');
        $pub = $outro->publicacoes()->create(['revisao' => $outro->revisao, 'modelo_publicacao' => 'USER_PRODUCTS', 'status' => 'PARTIALLY_PUBLISHED', 'chave_idempotencia' => (string) Str::uuid()]);
        $itemAlheio = $pub->itens()->create(['indice' => 0, 'listing_type_id' => 'gold_special', 'variante_chave' => '__single__', 'status' => 'CREATED', 'ml_item_id' => 'MLB4000000009']);

        $this->admin()->deleteJson($this->rota('fotos.remover', ['imagem' => $fotoAlheia->id]))->assertNotFound();
        $this->admin()->postJson($this->rota('fotos.reenviar', ['imagem' => $fotoAlheia->id]))->assertNotFound();
        $this->admin()->get($this->rota('fotos.arquivo', ['imagem' => $fotoAlheia->id]))->assertNotFound();
        $this->admin()->postJson($this->rota('descricao', ['item' => $itemAlheio->id]))->assertNotFound();
        $this->assertSame(1, PubImagem::where('id', $fotoAlheia->id)->count(), 'a foto alheia continua lá');
        $this->assertSame(1, PubPublicacaoItem::where('id', $itemAlheio->id)->count());
        // Pelo produto dono, a mesma foto é achada.
        $this->admin()->get($this->rota('fotos.arquivo', ['imagem' => $fotoAlheia->id], $outro->produto))->assertOk();
    }

    public function test_foto_sem_arquivo_guardado_e_404_no_arquivo(): void
    {
        $migrada = $this->r->imagens()->create(['caminho' => null, 'sha256' => str_repeat('c', 64), 'mime' => 'image/jpeg', 'bytes' => 1000,
            'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => 'M', 'ml_url' => 'https://http2.mlstatic.com/M.jpg']);

        $this->admin()->get($this->rota('fotos.arquivo', ['imagem' => $migrada->id]))->assertNotFound();
    }

    // ═══ Trava de contas liberadas (D21) ════════════════════════════════════

    public function test_publicar_em_conta_nao_liberada_e_422_conta_lib_sem_job_e_o_resto_segue(): void
    {
        $this->naoLiberar();
        $this->r->validacoes()->create(['revisao' => $this->r->revisao, 'camada' => 'L3', 'plano_hash' => str_repeat('a', 64), 'resultado' => ConferenciaService::OK, 'issues' => [],
            'respostas_ml' => ['conta' => ['sellerId' => '1555596317']]]);

        $this->admin()->postJson($this->rota('publicar'), ['ciente' => true])->assertUnprocessable()->assertJson(['regra' => 'CONTA-LIB']);
        Queue::assertNotPushed(PublicarRascunhoJob::class);
        $this->assertSame(0, PubPublicacao::count());

        $this->admin()->getJson($this->rota('abrir'))->assertOk()->assertJsonPath('publicacao_liberada', false);
        $this->admin()->putJson($this->rota('salvar'), ['condicao' => 'used'])->assertOk()->assertJsonPath('rascunho.condicao', 'used')->assertJsonPath('publicacao_liberada', false);
    }

    // ═══ D26: conta não liberada confere só local e guarda a foto ═══════════

    public function test_conferir_em_conta_nao_liberada_roda_so_local_sem_validate(): void
    {
        $this->naoLiberar();

        $this->admin()->postJson($this->rota('conferir'))->assertStatus(202)->assertJson(['conferindo' => true, 'publicacao_liberada' => false]);
        Queue::assertPushedOn('high', ConferirRascunhoJob::class);
        $antes = count(Http::recorded());
        // O job roda como na fila (a fila é fake neste teste).
        (new ConferirRascunhoJob($this->r->id))->handle(app(ConferenciaService::class));

        $this->assertSame([], array_slice(Http::recorded()->all(), $antes), 'conferência local: nenhuma chamada ao ML');
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/items/validate'));
        $estado = $this->admin()->getJson($this->rota('abrir'))->assertOk()->json();
        $this->assertTrue($estado['conferencia']['local']);
        $this->assertSame(ConferenciaService::LOCAL, $estado['conferencia']['resultado']);
    }

    public function test_foto_em_conta_nao_liberada_fica_guardada_pending_e_a_miniatura_vem_da_rota_interna(): void
    {
        $this->naoLiberar();
        $this->r->imagens()->delete();
        $this->repo->gravarAtribuicoes($this->r->fresh(), []);

        $r = $this->admin()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->image('capa.jpg', 1200, 1200)], ['Accept' => 'application/json'])->assertOk()->json();

        $this->assertSame(PubImagem::PENDENTE, $r['imagens'][0]['upload_status']);
        $this->assertTrue($r['imagens'][0]['tem_arquivo']);
        $this->assertSame($this->rota('fotos.arquivo', ['imagem' => $r['imagens'][0]['id']]), $r['imagens'][0]['url']);
        $this->assertNull(PubImagem::findOrFail($r['imagens'][0]['id'])->ml_url);
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), '/pictures/items/upload'));

        $arquivo = $this->admin()->get($r['imagens'][0]['url'])->assertOk();
        $this->assertStringStartsWith('image/', (string) $arquivo->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $arquivo->headers->get('Cache-Control'));
    }

    /**
     * WR-B04: produto SEM token ativo é "não liberada" — em conta da lista também. A foto é
     * guardada pendente e entra no grupo (antes: gravada e 422 "reconecte", fora do grupo, e o
     * reenvio do mesmo arquivo passava calado); a conferência é a local; publicar pede reconectar.
     */
    public function test_wr_b04_sem_token_foto_entra_no_grupo_conferencia_e_local_e_publicar_pede_reconectar(): void
    {
        MlToken::query()->delete();
        $this->r->imagens()->delete();
        $this->repo->gravarAtribuicoes($this->r->fresh(), []);
        $imagem = UploadedFile::fake()->image('capa.jpg', 1200, 1200); // o arquivo temporário vive enquanto o objeto viver
        $bytes = file_get_contents($imagem->getPathname());
        $antes = count(Http::recorded());

        $r = $this->admin()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->createWithContent('capa.jpg', $bytes)], ['Accept' => 'application/json'])->assertOk()->json();
        $id = $r['imagens'][0]['id'];
        $this->assertSame(PubImagem::PENDENTE, $r['imagens'][0]['upload_status']);
        $this->assertTrue($r['foto']['nova']);
        $this->assertSame([['imagem' => $id, 'grupo' => 'GENERAL', 'posicao' => 0]], array_map(fn ($a) => ['imagem' => (string) $a['imagem'], 'grupo' => $a['grupo'], 'posicao' => $a['posicao']], $r['atribuicoes']));
        $this->assertFalse($r['publicacao_liberada']);

        // O mesmo arquivo de novo: a mesma foto, uma vez só no grupo.
        $r = $this->admin()->post($this->rota('fotos'), ['imagem' => UploadedFile::fake()->createWithContent('capa.jpg', $bytes)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertFalse($r['foto']['nova']);
        $this->assertCount(1, $r['imagens']);
        $this->assertCount(1, $r['atribuicoes']);

        $this->admin()->postJson($this->rota('fotos.reenviar', ['imagem' => $id]))->assertOk()->assertJsonPath('imagens.0.upload_status', PubImagem::PENDENTE);

        (new ConferirRascunhoJob($this->r->id))->handle(app(ConferenciaService::class));
        $estado = $this->admin()->getJson($this->rota('abrir'))->assertOk()->json();
        $this->assertTrue($estado['conferencia']['local']);
        $this->assertNotContains('V-ACC-01', array_column($estado['conferencia']['issues'], 'regra'));

        $this->admin()->postJson($this->rota('publicar'), ['ciente' => true])->assertUnprocessable()->assertJson(['regra' => 'V-ACC-01']);
        Queue::assertNotPushed(PublicarRascunhoJob::class);
        $this->assertSame([], array_slice(Http::recorded()->all(), $antes), 'sem token: nenhuma chamada ao ML');
    }

    // ═══ Sem token na resposta ══════════════════════════════════════════════

    public function test_nenhuma_resposta_vaza_o_token_da_conta(): void
    {
        // Como a conferência real grava: o vendedor lido no `/users/me` (CR-B01 o exige para publicar).
        $this->r->validacoes()->create(['revisao' => $this->r->revisao, 'camada' => 'L3', 'plano_hash' => str_repeat('a', 64), 'resultado' => ConferenciaService::OK, 'issues' => [],
            'respostas_ml' => ['conta' => ['sellerId' => '1555596317']]]);

        $abrir = $this->admin()->getJson($this->rota('abrir'))->assertOk();
        $publicar = $this->admin()->postJson($this->rota('publicar'), ['ciente' => true])->assertStatus(202);

        foreach ([$abrir, $publicar] as $resposta) {
            $resposta->assertDontSee('fake-access-token');
            $resposta->assertDontSee('fake-refresh-token');
        }
    }
}
