<?php

namespace Tests\Feature\Phase199;

use App\Jobs\Publicador\ExcluirAtivoDoAcervoJob;
use App\Models\AcervoImagem;
use App\Models\Company;
use App\Models\MlAnuncioCriativo;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\User;
use App\Services\Publicador\AcervoExclusaoService;
use App\Services\Publicador\ImagemAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 199 (v28.0) — exclusão segura do acervo (EXC-01..05).
 *
 * Regra do usuário (15/10/2026): bloqueia se a imagem está em anúncio NÃO publicado; libera se só
 * está em anúncio publicado (o ML tem a cópia). O objeto sai por job idempotente.
 */
class ExclusaoSeguraDoAcervoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('r2');
        config(['filesystems.imagens' => 'r2']);
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->empresa = Company::factory()->create();
    }

    private function rascunho(string $sku, string $status = PubRascunho::DRAFT): PubRascunho
    {
        $p = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => $sku, 'nome' => "Produto {$sku}", 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        return PubRascunho::create(['produto_id' => $p->id, 'status' => $status]);
    }

    private static function jpeg(int $cor = 200): string
    {
        $img = imagecreatetruecolor(800, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, $cor, $cor, $cor));
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** Foto num rascunho; depois o rascunho vai para `$status` (publicar exige ter a foto antes). */
    private function ativoEmUso(string $sku, string $status, int $cor = 200): AcervoImagem
    {
        $r = $this->rascunho($sku);
        $img = app(ImagemAssetService::class)->receber($r, self::jpeg($cor), 'foto.jpg')['imagem'];
        $r->update(['status' => $status]);

        return $img->acervoImagem;
    }

    private function rota(string $nome, AcervoImagem $ativo, ?PubRascunho $r = null): string
    {
        $produtoId = $r?->produto_id ?? $ativo->usos()->first()->rascunho->produto_id;

        return route("mlb.anuncios.publicador.acervo.ativos.{$nome}", ['produto' => $produtoId, 'ativo' => $ativo->id]);
    }

    /** EXC-01: em rascunho → bloqueia, diz onde, e nada sai do storage. */
    public function test_em_anuncio_nao_publicado_bloqueia(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::DRAFT);

        $this->actingAs($this->admin)->getJson($this->rota('exclusao', $ativo))->assertOk()
            ->assertJsonPath('pode', false)->assertJsonPath('bloqueios.0.sku', 'CAD-01');
        $this->actingAs($this->admin)->deleteJson($this->rota('excluir', $ativo))->assertStatus(422)->assertJsonPath('bloqueios.0.sku', 'CAD-01');

        $this->assertSame(AcervoImagem::DISPONIVEL, $ativo->fresh()->status);
        Storage::disk('r2')->assertExists($ativo->caminho);
    }

    /** Publicado em parte ainda vai subir foto: também bloqueia. */
    public function test_publicado_em_parte_bloqueia(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PARTIALLY_PUBLISHED);

        $this->assertFalse(app(AcervoExclusaoService::class)->analisar($ativo)['pode']);
    }

    /** EXC-02: só em anúncio publicado → libera; job apaga objeto e miniatura; o uso (e o histórico) fica. */
    public function test_so_em_publicado_libera_e_o_job_apaga_o_objeto(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED);
        Storage::disk('r2')->put("miniaturas/{$ativo->dono_chave}/{$ativo->sha256}.webp", 'x');
        $ativo->update(['miniatura_caminho' => "miniaturas/{$ativo->dono_chave}/{$ativo->sha256}.webp"]);
        $uso = $ativo->usos()->first();

        $this->actingAs($this->admin)->getJson($this->rota('exclusao', $ativo))->assertOk()
            ->assertJsonPath('pode', true)->assertJsonPath('publicados', 1);
        $this->actingAs($this->admin)->deleteJson($this->rota('excluir', $ativo))->assertOk();

        $ativo->refresh();
        $this->assertSame(AcervoImagem::EXCLUIDA, $ativo->status);
        $this->assertSame([], Storage::disk('r2')->allFiles());
        $this->assertNotNull(PubImagem::find($uso->id), 'a linha do anúncio publicado fica — o histórico da v27 aponta para ela');
    }

    /** Sem uso nenhum: sai direto. */
    public function test_sem_uso_exclui(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::DRAFT);
        $r = $ativo->usos()->first()->rascunho;
        app(ImagemAssetService::class)->remover($ativo->usos()->first());

        $this->actingAs($this->admin)->deleteJson($this->rota('excluir', $ativo, $r))->assertOk();

        $this->assertSame(AcervoImagem::EXCLUIDA, $ativo->fresh()->status);
        Storage::disk('r2')->assertMissing($ativo->caminho);
    }

    /** EXC-02: o job é idempotente e desiste se um rascunho passou a usar a imagem enquanto esperava. */
    public function test_job_idempotente_e_desiste_se_a_imagem_voltou_a_ser_usada(): void
    {
        Queue::fake();
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED);
        $servico = app(AcervoExclusaoService::class);

        $this->assertTrue($servico->pedir($ativo)['ok']);
        Queue::assertPushed(ExcluirAtivoDoAcervoJob::class);
        $this->assertSame(AcervoImagem::EXCLUINDO, $ativo->fresh()->status);

        // Enquanto esperava na fila, um rascunho novo passou a usar a mesma imagem.
        $novo = $this->rascunho('CAD-02');
        $novo->imagens()->create(['caminho' => $ativo->caminho, 'acervo_imagem_id' => $ativo->id, 'sha256' => $ativo->sha256,
            'mime' => 'image/jpeg', 'bytes' => $ativo->bytes, 'upload_status' => PubImagem::PENDENTE]);

        $servico->executar($ativo->id);
        $this->assertSame(AcervoImagem::DISPONIVEL, $ativo->fresh()->status);
        Storage::disk('r2')->assertExists($ativo->caminho);

        // Rodar de novo com o ativo disponível não faz nada.
        $servico->executar($ativo->id);
        Storage::disk('r2')->assertExists($ativo->caminho);
    }

    /** Falha do storage: registra o erro e repete; esgotou → `falhou`. */
    public function test_falha_do_storage_registra_e_esgotado_vira_falhou(): void
    {
        Queue::fake();
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED);
        app(AcervoExclusaoService::class)->pedir($ativo);

        $quebrado = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $quebrado->shouldReceive('delete')->andThrow(new \RuntimeException('R2 fora do ar'));
        Storage::set('r2', $quebrado);

        $job = new ExcluirAtivoDoAcervoJob($ativo->id);
        try {
            $job->handle(app(AcervoExclusaoService::class));
            $this->fail('a falha do storage devia subir para a fila repetir');
        } catch (\RuntimeException) {
            // esperado
        }

        $ativo->refresh();
        $this->assertSame(AcervoImagem::EXCLUINDO, $ativo->status);
        $this->assertStringContainsString('R2 fora do ar', (string) $ativo->exclusao_erro);

        $job->failed(new \RuntimeException('R2 fora do ar'));
        $this->assertSame(AcervoImagem::FALHOU, $ativo->fresh()->status);
    }

    /** A mesma foto enviada de novo depois de excluída volta ao acervo com o arquivo regravado. */
    public function test_reenviar_foto_excluida_revive_o_ativo(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED, 77);
        app(AcervoExclusaoService::class)->pedir($ativo);
        $this->assertSame(AcervoImagem::EXCLUIDA, $ativo->fresh()->status);

        app(ImagemAssetService::class)->receber($this->rascunho('CAD-02'), self::jpeg(77), 'de-novo.jpg');

        $this->assertSame(AcervoImagem::DISPONIVEL, $ativo->fresh()->status);
        Storage::disk('r2')->assertExists($ativo->caminho);
        $this->assertSame(1, AcervoImagem::count());
    }

    /** EXC-05: lote aplica a mesma regra a cada uma e diz o que ficou. */
    public function test_exclusao_em_lote(): void
    {
        $livre = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED, 10);
        $presa = $this->ativoEmUso('CAD-02', PubRascunho::DRAFT, 20);
        $produto = $livre->usos()->first()->rascunho->produto_id;

        $res = $this->actingAs($this->admin)->postJson(route('mlb.anuncios.publicador.acervo.ativos.excluir-lote', ['produto' => $produto]),
            ['ids' => [$livre->id, $presa->id, 999999]])->assertOk()->json();

        $this->assertSame([$livre->id], $res['excluidas']);
        $this->assertSame([$presa->id, 999999], array_column($res['recusadas'], 'id'));
        $this->assertSame([AcervoImagem::EXCLUIDA, AcervoImagem::DISPONIVEL], [$livre->fresh()->status, $presa->fresh()->status]);
    }

    /** Outra conta não exclui: 404. */
    public function test_outra_conta_nao_exclui(): void
    {
        $ativo = $this->ativoEmUso('CAD-01', PubRascunho::PUBLISHED);
        $outra = Company::factory()->create();
        $p = PubProduto::create(['company_id' => $outra->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $r = PubRascunho::create(['produto_id' => $p->id]);

        $this->actingAs($this->admin)->deleteJson($this->rota('excluir', $ativo, $r))->assertNotFound();
        $this->assertSame(AcervoImagem::DISPONIVEL, $ativo->fresh()->status);
    }

    /** EXC-04: gerações não aprovadas velhas saem só com --aplicar; aprovada, recente e protegida ficam. */
    public function test_limpar_geracoes_nao_aprovadas(): void
    {
        $criar = function (string $status, int $diasAtras) {
            $caminho = 'creative-geradas/'.Str::random(12).'/1.jpg';
            Storage::disk('local')->put($caminho, 'jpg');
            $c = MlAnuncioCriativo::create(['token' => Str::random(32), 'company_id' => $this->empresa->id, 'slot' => 'hero',
                'status' => $status, 'imagem_path' => $caminho]);
            $c->forceFill(['created_at' => now()->subDays($diasAtras)])->save();

            return $c;
        };
        $velhaRejeitada = $criar(MlAnuncioCriativo::STATUS_PRONTO, 40);
        $velhaAprovada = $criar(MlAnuncioCriativo::STATUS_APROVADO, 40);
        $recente = $criar(MlAnuncioCriativo::STATUS_PRONTO, 5);
        $protegida = $criar(MlAnuncioCriativo::STATUS_ERRO, 40);
        AcervoImagem::create(['dono_chave' => 'c'.$this->empresa->id, 'company_id' => $this->empresa->id, 'disco' => 'local',
            'caminho' => $protegida->imagem_path, 'sha256' => str_repeat('c', 64), 'mime' => 'image/jpeg', 'bytes' => 3, 'origem' => 'ia']);

        $this->artisan('acervo:limpar-geracoes')->assertSuccessful();
        Storage::disk('local')->assertExists($velhaRejeitada->imagem_path);

        $this->artisan('acervo:limpar-geracoes', ['--aplicar' => true])->assertSuccessful();
        Storage::disk('local')->assertMissing($velhaRejeitada->imagem_path);
        $this->assertNull($velhaRejeitada->fresh()->imagem_path);
        foreach ([$velhaAprovada, $recente, $protegida] as $fica) {
            Storage::disk('local')->assertExists($fica->imagem_path);
            $this->assertNotNull($fica->fresh()->imagem_path);
        }
    }
}
