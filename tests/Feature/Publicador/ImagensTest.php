<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubRascunho;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** `06` §7–8 — conferir, guardar, deduplicar e subir as fotos (TC-56 a 59). */
class ImagensTest extends TestCase
{
    use RefreshDatabase;

    private PubRascunho $rascunho;

    private ImagemAssetService $servico;

    /** Como o ML responde ao upload AGORA (o fake é registrado uma vez — `Http::fake` acumula). */
    private int $statusDoUpload = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $empresa = Company::factory()->create();
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        $this->rascunho = (new RascunhoRepository())->criar($oferta, [new Alvo('gold_special', 'Cadeira')]);

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));
        $this->servico = app(ImagemAssetService::class);

        $contador = 0;
        Http::fake(['*/pictures/items/upload' => function () use (&$contador) {
            $contador++;

            return $this->statusDoUpload === 200
                ? Http::response(['id' => "123-MLB{$contador}_102026", 'variations' => [['size' => '1200x1200', 'secure_url' => "https://http2.mlstatic.com/D_{$contador}-O.jpg"]]])
                : Http::response(['message' => 'bad_request', 'error' => 'bad_request', 'status' => 400, 'cause' => []], $this->statusDoUpload);
        }]);
    }

    private static function jpg(int $largura = 1200, int $altura = 1200, string $nome = 'cadeira.jpg'): string
    {
        return UploadedFile::fake()->image($nome, $largura, $altura)->get();
    }

    public function test_foto_boa_e_guardada_no_disco_privado_e_sobe_para_o_ml(): void
    {
        $r = $this->servico->receber($this->rascunho, self::jpg(), 'cadeira.jpg');

        $img = $r['imagem'];
        $this->assertTrue($r['nova']);
        $this->assertSame(PubImagem::ENVIADA, $img->upload_status);
        $this->assertSame('123-MLB1_102026', $img->ml_picture_id);
        $this->assertSame('https://http2.mlstatic.com/D_1-O.jpg', $img->ml_url);
        $this->assertSame([1200, 1200, 'image/jpeg'], [$img->largura, $img->altura, $img->mime]);
        Storage::disk('local')->assertExists($img->caminho);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a foto do cliente não fica pública');
    }

    public function test_tc56_foto_pequena_nao_e_guardada_nem_enviada(): void
    {
        $r = $this->servico->receber($this->rascunho, self::jpg(400, 600), 'pequena.jpg');

        $this->assertNull($r['imagem']);
        $this->assertContains('V-IMG-03', array_map(fn ($p) => $p->regra, $r['problemas']));
        $this->assertSame(0, PubImagem::count());
        Http::assertNothingSent();
    }

    public function test_tc57_formato_que_o_ml_nao_aceita(): void
    {
        $gif = UploadedFile::fake()->image('animada.gif', 800, 800)->get();

        $r = $this->servico->receber($this->rascunho, $gif, 'animada.gif');

        $this->assertNull($r['imagem']);
        $this->assertContains('V-IMG-01', array_map(fn ($p) => $p->regra, $r['problemas']));
    }

    public function test_foto_entre_500_e_1200_entra_com_aviso(): void
    {
        $r = $this->servico->receber($this->rascunho, self::jpg(800, 800), 'media.jpg');

        $this->assertNotNull($r['imagem']);
        $this->assertSame(['V-IMG-10'], array_map(fn ($p) => $p->regra, $r['problemas']));
    }

    public function test_tc58_mesmo_arquivo_duas_vezes_e_a_mesma_foto(): void
    {
        $bytes = self::jpg();

        $a = $this->servico->receber($this->rascunho, $bytes, 'a.jpg')['imagem'];
        $b = $this->servico->receber($this->rascunho, $bytes, 'b.jpg');

        $this->assertFalse($b['nova']);
        $this->assertSame($a->id, $b['imagem']->id);
        $this->assertSame(1, PubImagem::count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'pictures')));

        // E pode estar em mais de um grupo (ex.: tabela de medidas na geral e num grupo).
        $repo = new RascunhoRepository();
        $repo->gravarAtribuicoes($this->rascunho, [
            ['imagem' => $a->id, 'grupo' => R::GERAL, 'posicao' => 0],
            ['imagem' => $a->id, 'grupo' => 'COLOR=id:52049', 'posicao' => 0],
        ]);
        $this->assertCount(2, $repo->snapshot($this->rascunho->fresh())->imagens);
    }

    public function test_tc59_falha_no_upload_fica_marcada_e_reenvia_depois(): void
    {
        $this->statusDoUpload = 400;
        $img = $this->servico->receber($this->rascunho, self::jpg(), 'a.jpg')['imagem'];

        $this->assertSame(PubImagem::FALHOU, $img->upload_status);
        $this->assertSame(400, $img->upload_erro['status']);
        $this->assertStringContainsString('por minuto', $img->upload_erro['mensagem']);
        Storage::disk('local')->assertExists($img->caminho); // o arquivo fica: dá para reenviar

        $repo = new RascunhoRepository();
        $this->assertSame('failed', $repo->metadadosDasImagens($this->rascunho)[(string) $img->id]['upload_status']);
        $this->assertSame([], $repo->fotosNoMl($this->rascunho), 'o montador não usa foto que não subiu');

        $this->statusDoUpload = 200;
        $this->assertSame([], $this->servico->enviarPendentes($this->rascunho->fresh()));
        $this->assertSame(PubImagem::ENVIADA, $img->fresh()->upload_status);
        $this->assertNotEmpty($repo->fotosNoMl($this->rascunho));
    }

    public function test_foto_legada_sem_arquivo_nao_tem_como_reenviar(): void
    {
        $legada = $this->rascunho->imagens()->create(['ml_picture_id' => 'PIC-ANTIGA', 'upload_status' => PubImagem::FALHOU]);

        $falhas = $this->servico->enviarPendentes($this->rascunho);

        $this->assertSame([$legada->id], array_map(fn ($i) => $i->id, $falhas));
        $this->assertStringContainsString('sem o arquivo', $legada->fresh()->upload_erro['mensagem']);
        Http::assertNothingSent();
    }

    public function test_remover_apaga_o_arquivo(): void
    {
        $img = $this->servico->receber($this->rascunho, self::jpg(), 'a.jpg')['imagem'];

        $this->servico->remover($img);

        Storage::disk('local')->assertMissing($img->caminho);
        $this->assertSame(0, PubImagem::count());
    }
}
