<?php

namespace Tests\Feature\Phase165;

use App\Models\MlAnuncioCriativo;
use App\Models\MlbEmpresa;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Services\Creative\ReferenciaEfemeraService;
use App\Services\Publicador\Criativos\PublicadorCriativoReferenciaService;
use App\Services\Publicador\RascunhoRepository;
use App\Support\Publicador\Imagem\ResolvedorGruposImagem as R;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Phase165\Concerns\CenarioCriativoDoPublicador;
use Tests\TestCase;

/**
 * Fase 165, Plano 03, Task 3 — `PublicadorCriativoReferenciaService`: as
 * fotos do próprio rascunho (e uploads novos) viram a referência efêmera do
 * Creative Engine, sem alterar o `ReferenciaEfemeraService` do outro dev.
 */
class ReferenciaDoPublicadorTest extends TestCase
{
    use CenarioCriativoDoPublicador;
    use RefreshDatabase;

    private function servico(): PublicadorCriativoReferenciaService
    {
        return app(PublicadorCriativoReferenciaService::class);
    }

    public function test_grupos_validos_do_rascunho_simples_e_so_a_galeria_geral(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');

        $this->assertSame([R::GERAL], $this->servico()->gruposValidos($this->r->fresh()));
    }

    public function test_grupos_validos_com_variacao_de_cor_inclui_os_dois_grupos(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $this->comVariacaoDeCor();

        $grupos = $this->servico()->gruposValidos($this->r->fresh());

        $this->assertContains(R::GERAL, $grupos);
        $this->assertContains('COLOR=id:52028', $grupos);
        $this->assertContains('COLOR=id:52049', $grupos);
    }

    public function test_selecionar_fotos_do_proprio_rascunho_na_ordem_dos_ids(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $a = $this->fotoComArquivo(R::GERAL);
        $b = $this->fotoComArquivo(R::GERAL);

        $fotos = $this->servico()->selecionarFotos($this->r->fresh(), [$b->id, $a->id]);

        $this->assertSame([$b->id, $a->id], array_map(fn (PubImagem $f) => $f->id, $fotos));
    }

    public function test_foto_de_outro_rascunho_e_recusada(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');

        $outraEmpresa = MlbEmpresa::create(['nome' => 'Outra Loja', 'projeto' => 'Incubadora']);
        $outroProduto = PubProduto::create(['mlb_empresa_id' => $outraEmpresa->id, 'sku' => 'OUTRO-01', 'nome' => 'Outro produto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $outroRascunho = app(RascunhoRepository::class)->criar($outroProduto, [new Alvo('gold_special', null)]);
        $bytes = self::jpeg(1500);
        $caminho = "publicador/{$outroRascunho->id}/".hash('sha256', $bytes).'.jpg';
        Storage::disk('local')->put($caminho, $bytes);
        $fotoDeOutro = $outroRascunho->imagens()->create([
            'caminho' => $caminho, 'sha256' => hash('sha256', $bytes), 'mime' => 'image/jpeg', 'bytes' => strlen($bytes),
            'largura' => 1500, 'altura' => 1500, 'upload_status' => PubImagem::PENDENTE,
        ]);

        try {
            $this->servico()->selecionarFotos($this->r->fresh(), [$fotoDeOutro->id]);
            $this->fail('deveria lançar ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['Uma das fotos escolhidas não é deste anúncio.'], $e->errors()['imagens']);
        }
    }

    public function test_foto_sem_caminho_ou_sem_arquivo_e_ignorada_sem_erro(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $comArquivo = $this->fotoComArquivo(R::GERAL);
        // Veio do Anunciar antigo só com o id do ML — sem arquivo (H-22).
        $semCaminho = $this->r->imagens()->create(['caminho' => null, 'sha256' => null, 'mime' => null, 'bytes' => 0, 'largura' => 0, 'altura' => 0, 'upload_status' => PubImagem::ENVIADA, 'ml_picture_id' => '123-MLB1']);
        $arquivoSumido = $this->r->imagens()->create(['caminho' => "publicador/{$this->r->id}/sumida.jpg", 'sha256' => str_repeat('b', 64), 'mime' => 'image/jpeg', 'bytes' => 1, 'largura' => 1200, 'altura' => 1200, 'upload_status' => PubImagem::PENDENTE]);

        $fotos = $this->servico()->selecionarFotos($this->r->fresh(), [$comArquivo->id, $semCaminho->id, $arquivoSumido->id]);

        $this->assertSame([$comArquivo->id], array_map(fn (PubImagem $f) => $f->id, $fotos));
    }

    public function test_criar_portador_com_os_campos_do_d02(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $grupo = R::GERAL;
        $u = $this->admin();

        $portador = $this->servico()->criarPortador($this->r->fresh(), $grupo, $u);

        $this->assertSame(32, strlen($portador->token));
        $this->assertNull($portador->rascunho_id);
        $this->assertSame($this->r->id, $portador->pub_rascunho_id);
        $this->assertSame($grupo, $portador->pub_grupo);
        $this->assertNull($portador->company_id, 'MlbEmpresa sem Company — T-165-05b');
        $this->assertSame($this->produto->mlb_empresa_id, $portador->mlb_empresa_id);
        $this->assertSame($u->id, $portador->user_id);
        $this->assertSame('hero', $portador->slot);
        $this->assertSame(MlAnuncioCriativo::STATUS_PENDENTE, $portador->status);
    }

    public function test_guardar_fotos_do_rascunho_e_upload_novo_grava_a_referencia_efemera(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $a = $this->fotoComArquivo(R::GERAL);
        $b = $this->fotoComArquivo(R::GERAL);
        $bytesA = Storage::disk('local')->get($a->caminho);
        $bytesB = Storage::disk('local')->get($b->caminho);
        $upload = UploadedFile::fake()->image('capa-nova.jpg', 1400, 1400);
        $bytesUpload = file_get_contents($upload->getPathname());
        $portador = $this->servico()->criarPortador($this->r->fresh(), R::GERAL, $this->admin());

        $refs = $this->servico()->guardar($portador, [$a, $b], [$upload]);

        $this->assertSame([0, 1, 2], array_column($refs, 'indice'));
        $this->assertSame($refs, $portador->fresh()->referencias);

        $disco = Storage::disk('local');
        foreach ($refs as $ref) {
            $disco->assertExists($ref['path']);
            $this->assertStringStartsWith("creative-referencias/{$portador->token}/", $ref['path']);
        }

        // Nome das fotos do rascunho segue `foto-{id}.{ext}` — nunca o nome do cliente (T-160-03).
        $this->assertSame("foto-{$a->id}.jpg", $refs[0]['nome']);
        $this->assertSame("foto-{$b->id}.jpg", $refs[1]['nome']);
        $this->assertSame('capa-nova.jpg', $refs[2]['nome']);

        $bytesVivos = app(ReferenciaEfemeraService::class)->bytesDe($portador->fresh());
        $this->assertCount(3, $bytesVivos);
        $this->assertSame([$bytesA, $bytesB, $bytesUpload], array_column($bytesVivos, 'bytes'));

        // As fotos de origem continuam intactas — nada foi movido nem apagado.
        $disco->assertExists($a->caminho);
        $disco->assertExists($b->caminho);
        $this->assertSame($bytesA, $disco->get($a->fresh()->caminho));
        $this->assertNotNull($a->fresh()->caminho);
    }

    public function test_guardar_sem_nenhum_arquivo_lanca_excecao(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $portador = $this->servico()->criarPortador($this->r->fresh(), R::GERAL, $this->admin());

        $this->expectException(\InvalidArgumentException::class);

        $this->servico()->guardar($portador, [], []);
    }

    public function test_apagar_a_referencia_nao_toca_na_pasta_do_rascunho(): void
    {
        $this->montarCenarioCriativo('mlb_empresa');
        $a = $this->fotoComArquivo(R::GERAL);
        $portador = $this->servico()->criarPortador($this->r->fresh(), R::GERAL, $this->admin());
        $this->servico()->guardar($portador, [$a], []);
        $disco = Storage::disk('local');
        $diretorioReferencia = "creative-referencias/{$portador->token}";
        $diretorioRascunho = "publicador/{$this->r->id}";
        $disco->assertExists($diretorioReferencia.'/0.jpg');

        app(ReferenciaEfemeraService::class)->apagar($portador->fresh());

        $this->assertFalse($disco->exists($diretorioReferencia));
        $this->assertTrue($disco->exists($diretorioRascunho), 'a pasta de fotos do rascunho fica intacta');
        $disco->assertExists($a->caminho);
    }
}
