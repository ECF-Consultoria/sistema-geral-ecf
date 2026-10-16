<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 16/10/2026 — a miniatura de exibição da imagem da variação (`?w=320`). Medido na #459: as 9 capas
 * somavam 4,3 MB (PNG de ~700 px, 280–690 KB) para quadros de 56–132 px na aba Criadas do Planejamento.
 * A miniatura é uma CÓPIA em JPEG guardada ao lado da original; a original não muda, e quem não pede
 * `?w=320` continua recebendo ela.
 */
class MiniaturaDaImagemTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array{0: EstruturaProdutoVariacao, 1: EstruturaProdutoVariacaoImagem} */
    private function imagem(Company $empresa, UploadedFile $arquivo): array
    {
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-1', 'nome' => 'Poltrona']);
        $variacao = EstruturaProdutoVariacao::create([
            'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => 1, 'codigo' => 'POLT-1', 'eixo' => 'cor', 'valor' => 'Cinza',
        ]);
        $galeria = app(VariacaoImagensService::class)->enviar($empresa, $variacao, [$arquivo], $this->atorCliente($empresa));

        return [$variacao, EstruturaProdutoVariacaoImagem::findOrFail($galeria[0]['id'])];
    }

    public function test_a_miniatura_sai_em_jpeg_de_ate_320px_fica_guardada_e_a_original_continua_igual(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$variacao, $img] = $this->imagem($empresa, UploadedFile::fake()->image('foto.png', 900, 600));
        $sessao = $this->entrarNoPortal($empresa);

        $mini = $sessao->get(VariacaoImagensService::urlMiniatura($variacao->id, $img->id));
        $mini->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('max-age=604800', $mini->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $mini->headers->get('Cache-Control'), 'imagem de empresa nunca em cache compartilhado');
        $this->assertSame([320, 213], array_slice(getimagesizefromstring($mini->streamedContent()), 0, 2), 'o lado maior vira 320, a proporção fica');
        Storage::disk('local')->assertExists(VariacaoImagensService::caminhoDaMiniatura($img));

        // A original segue intacta e é o que vai sem `?w=320` (ou com outro tamanho qualquer).
        foreach ([VariacaoImagensService::url($variacao->id, $img->id), route('portal.auth.estrutura.produtos.imagens.ver', ['variacao' => $variacao->id, 'imagem' => $img->id, 'w' => 999])] as $url) {
            $original = $sessao->get($url);
            $original->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->assertSame(900, getimagesizefromstring($original->streamedContent())[0]);
        }
    }

    public function test_imagem_menor_que_a_miniatura_nao_e_ampliada_e_a_segunda_vez_vem_do_disco(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$variacao, $img] = $this->imagem($empresa, UploadedFile::fake()->image('mini.jpg', 120, 90));
        $sessao = $this->entrarNoPortal($empresa);
        $url = VariacaoImagensService::urlMiniatura($variacao->id, $img->id);

        $this->assertSame([120, 90], array_slice(getimagesizefromstring($sessao->get($url)->streamedContent()), 0, 2));

        // Gravada uma vez: trocar o arquivo da miniatura no disco prova que a segunda resposta vem dele.
        Storage::disk('local')->put(VariacaoImagensService::caminhoDaMiniatura($img), 'GUARDADA');
        $this->assertSame('GUARDADA', $sessao->get($url)->streamedContent());
    }

    public function test_arquivo_que_o_gd_nao_le_entrega_a_original_sem_erro(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$variacao, $img] = $this->imagem($empresa, UploadedFile::fake()->image('foto.jpg', 400, 400));
        Storage::disk('local')->put($img->caminho, 'nao e imagem');

        $r = $this->entrarNoPortal($empresa)->get(VariacaoImagensService::urlMiniatura($variacao->id, $img->id));

        $r->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('nao e imagem', $r->streamedContent(), 'sem miniatura possível, vai a original');
        Storage::disk('local')->assertMissing(VariacaoImagensService::caminhoDaMiniatura($img));
    }

    public function test_outra_empresa_nao_ve_a_miniatura(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$variacao, $img] = $this->imagem($empresa, UploadedFile::fake()->image('foto.png', 600, 600));

        $this->entrarNoPortal($this->empresaDoGabarito())->get(VariacaoImagensService::urlMiniatura($variacao->id, $img->id))->assertNotFound();
        Storage::disk('local')->assertMissing(VariacaoImagensService::caminhoDaMiniatura($img), 'nem chega a gerar');
    }

    public function test_excluir_a_imagem_leva_a_miniatura_junto(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$variacao, $img] = $this->imagem($empresa, UploadedFile::fake()->image('foto.png', 600, 600));
        $url = VariacaoImagensService::urlMiniatura($variacao->id, $img->id);

        $this->entrarNoPortal($empresa)->get($url)->assertOk();
        $miniatura = VariacaoImagensService::caminhoDaMiniatura($img);
        Storage::disk('local')->assertExists($miniatura);

        app(VariacaoImagensService::class)->excluir($empresa, $variacao, $img, $this->atorCliente($empresa));

        Storage::disk('local')->assertMissing($img->caminho);
        Storage::disk('local')->assertMissing($miniatura);
    }
}
