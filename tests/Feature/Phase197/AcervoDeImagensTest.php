<?php

namespace Tests\Feature\Phase197;

use App\Models\AcervoImagem;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\MercadoLivreService;
use App\Services\MlColetaService;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ExcluirProdutoService;
use App\Services\Publicador\ImagemAssetService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 197 (v28.0) — a tabela de ativos por conta do ML e o backfill só de banco (ATV-01..05).
 */
class AcervoDeImagensTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private ImagemAssetService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.imagens' => 'r2']);

        $this->empresa = $this->empresaComConta('1555596317');
        $this->app->instance(ClienteMlPublicador::class, new ClienteMlPublicador(app(MercadoLivreService::class), app(MlColetaService::class), fn () => null));
        $this->servico = app(ImagemAssetService::class);

        Http::fake(['*/pictures/items/upload' => fn () => Http::response(['id' => 'MLB-'.Str::random(6), 'variations' => [['secure_url' => 'https://http2.mlstatic.com/D_1-O.jpg']]])]);
    }

    private function empresaComConta(string $mlUserId): Company
    {
        $empresa = Company::factory()->create();
        MlToken::create(['company_id' => $empresa->id, 'ml_user_id' => $mlUserId, 'access_token' => 'fake', 'refresh_token' => 'fake',
            'token_type' => 'bearer', 'expires_at' => now()->addHours(5), 'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        $liberadas = config('publicador.contas_liberadas.companies', []);
        config(['publicador.contas_liberadas' => ['companies' => [...$liberadas, $empresa->id], 'mlb_empresas' => []]]);

        return $empresa;
    }

    private function rascunho(Company $empresa, string $sku): PubRascunho
    {
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => $sku, 'fase' => 'simples', 'nome' => "Produto {$sku}"]);

        return (new RascunhoRepository())->criar(PubProduto::create(['company_id' => $empresa->id, 'oferta_id' => $oferta->id, 'sku' => $sku,
            'nome' => "Produto {$sku}", 'origem' => PubProduto::ORIGEM_PORTAL]), [new Alvo('gold_special', "Produto {$sku}")]);
    }

    private static function jpg(int $lado = 1200): string
    {
        return UploadedFile::fake()->image('foto.jpg', $lado, $lado)->get();
    }

    /** ATV-01/ATV-03: o upload vira ativo da conta com os metadados, no caminho por conteúdo. */
    public function test_upload_vira_ativo_da_conta_com_metadados(): void
    {
        $r = $this->rascunho($this->empresa, 'CAD-01');
        $conteudo = self::jpg();

        $img = $this->servico->receber($r, $conteudo, 'cadeira-frente.jpg')['imagem'];
        $ativo = $img->acervoImagem;

        $sha = hash('sha256', $conteudo);
        $this->assertSame("imagens/c{$this->empresa->id}/{$sha}.jpg", $ativo->caminho);
        $this->assertSame($ativo->caminho, $img->caminho);
        $this->assertSame([AcervoImagem::ORIGEM_UPLOAD, 'cadeira-frente.jpg', 'CAD-01', 'Produto CAD-01', '1555596317', 'r2', 1200, 1200],
            [$ativo->origem, $ativo->nome_original, $ativo->sku, $ativo->produto_nome, $ativo->ml_user_id, $ativo->disco, $ativo->largura, $ativo->altura]);
        Storage::disk('r2')->assertExists($ativo->caminho);
    }

    /** Critério 1: a mesma foto em dois produtos da mesma conta é UM ativo, UM objeto e UM envio ao ML. */
    public function test_mesma_foto_em_dois_produtos_da_conta_e_um_ativo_e_um_envio(): void
    {
        $conteudo = self::jpg();
        $a = $this->servico->receber($this->rascunho($this->empresa, 'CAD-01'), $conteudo, 'a.jpg')['imagem'];
        $b = $this->servico->receber($this->rascunho($this->empresa, 'CAD-02'), $conteudo, 'b.jpg')['imagem'];

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($a->acervo_imagem_id, $b->acervo_imagem_id);
        $this->assertSame(1, AcervoImagem::count());
        $this->assertCount(1, Storage::disk('r2')->allFiles());
        $this->assertSame($a->ml_picture_id, $b->ml_picture_id, 'a foto já estava na conta: reaproveita o id');
        $this->assertCount(1, Http::recorded(fn (Request $q) => str_contains($q->url(), '/pictures/items/upload')));
    }

    /** Outra conta, mesma foto: ativos separados (isolamento entre contas). */
    public function test_outra_conta_tem_ativo_proprio(): void
    {
        $outra = $this->empresaComConta('999');
        $conteudo = self::jpg();

        $this->servico->receber($this->rascunho($this->empresa, 'CAD-01'), $conteudo, 'a.jpg');
        $this->servico->receber($this->rascunho($outra, 'CAD-01'), $conteudo, 'a.jpg');

        $this->assertSame(['c'.$this->empresa->id, 'c'.$outra->id], AcervoImagem::orderBy('id')->pluck('dono_chave')->all());
    }

    /** Tirar a foto do rascunho desfaz o uso; o objeto e o ativo ficam no acervo. */
    public function test_remover_do_rascunho_nao_apaga_o_objeto_do_acervo(): void
    {
        $img = $this->servico->receber($this->rascunho($this->empresa, 'CAD-01'), self::jpg(), 'a.jpg')['imagem'];

        $this->servico->remover($img);

        $this->assertNull(PubImagem::find($img->id));
        $this->assertSame(1, AcervoImagem::count());
        Storage::disk('r2')->assertExists(AcervoImagem::first()->caminho);
    }

    /** Excluir o produto também não leva o objeto do acervo junto. */
    public function test_excluir_produto_mantem_o_objeto_do_acervo(): void
    {
        $r = $this->rascunho($this->empresa, 'AVULSO-01');
        $r->produto->update(['oferta_id' => null]);
        $this->servico->receber($r, self::jpg(), 'a.jpg');

        $resposta = app(ExcluirProdutoService::class)->excluir(
            ['mlb_empresa' => null, 'company' => $this->empresa, 'chave' => 'c'.$this->empresa->id],
            [$r->produto_id],
            \App\Models\User::factory()->create(['role' => 'admin']),
        );

        $this->assertSame([$r->produto_id], $resposta['excluidos'], json_encode($resposta['recusados']));
        $ativo = AcervoImagem::first();
        Storage::disk('r2')->assertExists($ativo->caminho);
        $this->assertNull($ativo->fresh()->produto_id, 'nullOnDelete: o registro do arquivo sobrevive ao produto');
    }

    /** Imagem de IA aprovada entra como origem `ia`, com modelo e criativo. */
    public function test_origem_ia_e_registrada(): void
    {
        $img = $this->servico->receber($this->rascunho($this->empresa, 'CAD-01'), self::jpg(), 'criativo-x.jpg',
            origem: ['origem' => AcervoImagem::ORIGEM_IA, 'modelo_ia' => 'gemini-3.1-flash-image', 'criativo_id' => 42])['imagem'];

        $this->assertSame([AcervoImagem::ORIGEM_IA, 'gemini-3.1-flash-image', 42],
            [$img->acervoImagem->origem, $img->acervoImagem->modelo_ia, $img->acervoImagem->criativo_id]);
    }

    /** ATV-04: dry-run não grava; aplicar liga fotos antigas, deduplica por conta e é idempotente. */
    public function test_backfill_e_so_de_banco_e_idempotente(): void
    {
        $conteudo = self::jpg(900);
        $sha = hash('sha256', $conteudo);
        $antigas = [];
        foreach (['CAD-01', 'CAD-02'] as $sku) {
            $r = $this->rascunho($this->empresa, $sku);
            $caminho = "publicador/{$r->id}/{$sha}.jpg";
            Storage::disk('local')->put($caminho, $conteudo);
            $antigas[] = $r->imagens()->create(['caminho' => $caminho, 'sha256' => $sha, 'mime' => 'image/jpeg', 'bytes' => strlen($conteudo),
                'largura' => 900, 'altura' => 900, 'upload_status' => PubImagem::PENDENTE]);
        }
        $sumida = $this->rascunho($this->empresa, 'CAD-03')->imagens()->create(['caminho' => 'publicador/x/sumiu.jpg', 'sha256' => str_repeat('a', 64),
            'mime' => 'image/jpeg', 'bytes' => 1, 'upload_status' => PubImagem::PENDENTE]);
        $arquivosAntes = Storage::disk('local')->allFiles();

        $this->artisan('acervo:registrar-existentes', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, AcervoImagem::count());

        $this->artisan('acervo:registrar-existentes')->assertSuccessful();
        $this->artisan('acervo:registrar-existentes')->assertSuccessful();

        $this->assertSame(1, AcervoImagem::count());
        $ativo = AcervoImagem::first();
        $this->assertSame(['local', $antigas[0]->caminho], [$ativo->disco, $ativo->caminho], 'aponta para o arquivo onde ele já está');
        $this->assertSame([$ativo->id, $ativo->id], [$antigas[0]->fresh()->acervo_imagem_id, $antigas[1]->fresh()->acervo_imagem_id]);
        $this->assertNull($sumida->fresh()->acervo_imagem_id);
        $this->assertSame($arquivosAntes, Storage::disk('local')->allFiles(), 'nenhum arquivo movido ou apagado');
        $this->assertSame([], Storage::disk('r2')->allFiles());

        // A cópia física redundante (2ª foto) é só dela: sai com ela; o objeto do ativo fica.
        $this->servico->remover($antigas[1]->fresh());
        Storage::disk('local')->assertMissing($antigas[1]->caminho);
        Storage::disk('local')->assertExists($ativo->caminho);
    }
}
