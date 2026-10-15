<?php

namespace Tests\Feature\Phase196;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fase 196 (v28.0) — as fotos definitivas do Publicador gravam no disco `imagens`
 * e as antigas continuam legíveis do `local` (ARM-01..05).
 */
class DiscoDasImagensNoPublicadorTest extends TestCase
{
    use RefreshDatabase;

    private PubRascunho $rascunho;

    private ImagemAssetService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.imagens' => 'r2']);

        $empresa = Company::factory()->create();
        config(['publicador.contas_liberadas' => ['companies' => [$empresa->id], 'mlb_empresas' => []]]);
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        $this->rascunho = (new RascunhoRepository())->criar(PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $oferta->id, 'sku' => 'CAD-01', 'nome' => 'Cadeira', 'origem' => PubProduto::ORIGEM_PORTAL]), [new Alvo('gold_special', 'Cadeira')]);

        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));
        $this->servico = app(ImagemAssetService::class);

        Http::fake(['*/pictures/items/upload' => Http::response(['id' => '123-MLB1_102026', 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg']]])]);
    }

    private static function jpg(int $lado = 1200): string
    {
        return UploadedFile::fake()->image('cadeira.jpg', $lado, $lado)->get();
    }

    /** Foto guardada antes da virada: só existe no disco local. */
    private function fotoLegada(): PubImagem
    {
        $conteudo = self::jpg(900);
        $sha = hash('sha256', $conteudo);
        $caminho = "publicador/{$this->rascunho->id}/{$sha}.jpg";
        Storage::disk('local')->put($caminho, $conteudo);

        return $this->rascunho->imagens()->create(['caminho' => $caminho, 'sha256' => $sha, 'mime' => 'image/jpeg',
            'bytes' => strlen($conteudo), 'largura' => 900, 'altura' => 900, 'upload_status' => PubImagem::PENDENTE]);
    }

    /** ARM-01: a foto nova vai para o disco das imagens, não para o local, e sobe ao ML de lá. */
    public function test_foto_nova_grava_no_disco_das_imagens_e_sobe_ao_ml(): void
    {
        $r = $this->servico->receber($this->rascunho, self::jpg(), 'cadeira.jpg');

        $img = $r['imagem'];
        Storage::disk('r2')->assertExists($img->caminho);
        Storage::disk('local')->assertMissing($img->caminho);
        $this->assertSame(PubImagem::ENVIADA, $img->upload_status);
    }

    /** ARM-02: a foto antiga, só no local, continua sendo lida, servida e enviada ao ML. */
    public function test_foto_legada_continua_legivel_do_local(): void
    {
        $img = $this->fotoLegada();

        $this->assertTrue(ArquivosDeImagem::existe($img->caminho));
        $this->assertSame(Storage::disk('local')->get($img->caminho), ArquivosDeImagem::ler($img->caminho));
        $this->assertSame(200, ArquivosDeImagem::resposta($img->caminho)->getStatusCode());

        $this->assertSame(PubImagem::ENVIADA, $this->servico->enviarAoMl($img)->upload_status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/pictures/items/upload'));
    }

    /** Arquivo em lugar nenhum: sem resposta e sem envio de corpo vazio ao ML. */
    public function test_arquivo_ausente_falha_sem_chamar_o_ml(): void
    {
        $img = $this->fotoLegada();
        Storage::disk('local')->delete($img->caminho);

        $this->assertNull(ArquivosDeImagem::resposta($img->caminho));
        $this->assertSame(PubImagem::FALHOU, $this->servico->enviarAoMl($img)->upload_status);
        Http::assertNothingSent();
    }

    /** Remover a foto apaga dos dois discos (não sobra cópia no local depois de migrada). */
    public function test_remover_apaga_dos_dois_discos(): void
    {
        $img = $this->fotoLegada();
        Storage::disk('r2')->put($img->caminho, Storage::disk('local')->get($img->caminho));

        $this->servico->remover($img);

        Storage::disk('local')->assertMissing($img->caminho);
        Storage::disk('r2')->assertMissing($img->caminho);
        $this->assertNull(PubImagem::find($img->id));
    }

    /** ARM-04: storage fora do ar → nenhuma linha, nenhum arquivo, e um motivo claro na tela. */
    public function test_falha_do_storage_nao_cria_linha(): void
    {
        $quebrado = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $quebrado->shouldReceive('put')->andThrow(new \RuntimeException('Could not connect to R2'));
        Storage::set('r2', $quebrado);

        $r = $this->servico->receber($this->rascunho, self::jpg(), 'cadeira.jpg');

        $this->assertNull($r['imagem']);
        $this->assertContains('V-IMG-12', array_map(fn ($p) => $p->regra, $r['problemas']));
        $this->assertSame(0, $this->rascunho->imagens()->count());
        Http::assertNothingSent();
    }

    /** ARM-03: o insert falhou de vez → o arquivo recém-gravado não fica órfão. */
    public function test_insert_que_falha_nao_deixa_arquivo_orfao(): void
    {
        PubImagem::creating(fn () => throw new QueryException('testing', 'insert into pub_imagens', [], new \Exception('disco do banco cheio')));

        try {
            $this->servico->receber($this->rascunho, self::jpg(), 'cadeira.jpg');
            $this->fail('o QueryException devia ter subido');
        } catch (QueryException) {
            // esperado
        } finally {
            PubImagem::flushEventListeners();
        }

        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    /**
     * ARM-05: a foto do rascunho no R2 vira referência do Gemini no disco LOCAL (efêmera),
     * nunca no R2, e o temporário usado na cópia some.
     */
    public function test_referencia_do_gemini_sai_do_r2_e_fica_so_no_local(): void
    {
        $img = $this->servico->receber($this->rascunho, self::jpg(), 'cadeira.jpg')['imagem'];
        $servico = app(PublicadorCriativoReferenciaService::class);
        $portador = $servico->criarPortador($this->rascunho, 'GENERAL', User::factory()->create());
        $temporariosAntes = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'pubref*') ?: [];

        $refs = $servico->guardar($portador, [$img], []);

        $this->assertCount(1, $refs);
        Storage::disk('local')->assertExists($refs[0]['path']);
        $this->assertSame(Storage::disk('r2')->get($img->caminho), Storage::disk('local')->get($refs[0]['path']));
        $this->assertSame([], Storage::disk('r2')->allFiles('creative-referencias'));
        $this->assertSame($temporariosAntes, glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'pubref*') ?: []);
    }
}
