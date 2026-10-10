<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\FotosEmLoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fotos em lote pelo nome do arquivo (09/10/2026): `Ref_número.jpg` vai para a variação da Ref, na
 * ordem do número, até o teto de 12 — pelo MESMO `VariacaoImagensService::enviar` da ficha.
 *
 * Modos de falha que estes testes impedem: foto na variação de OUTRA empresa (Ref igual em duas
 * empresas); Ref com "_" partida no lugar errado; a ordem do número ignorada; um arquivo ruim
 * derrubando as outras fotos da mesma variação; passar do teto por variação.
 */
class FotosEmLoteTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function variacao(Company $empresa, string $codigo, string $valor = 'Natural'): EstruturaProdutoVariacao
    {
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-'.$codigo, 'nome' => 'Produto '.$codigo]);

        return EstruturaProdutoVariacao::create(['produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => 1, 'codigo' => $codigo, 'eixo' => 'cor', 'valor' => $valor]);
    }

    /** Linhas de imagem já gravadas (só para contar no teto; o arquivo não importa). */
    private function jaTem(EstruturaProdutoVariacao $v, int $quantas): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            EstruturaProdutoVariacaoImagem::create([
                'company_id' => $v->company_id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
                'caminho' => "estrutura/x/{$v->id}/{$i}.jpg", 'nome_original' => "antiga-{$i}.jpg", 'mime' => 'image/jpeg',
                'tamanho' => 10, 'largura' => 1, 'altura' => 1, 'ordem' => $i,
            ]);
        }
    }

    private function jpg(string $nome): UploadedFile
    {
        return UploadedFile::fake()->image($nome, 40, 30);
    }

    /** Arquivo de verdade em disco: o `fake()` deduz o MIME pelo NOME, e aqui o servidor tem de olhar o CONTEÚDO. */
    private function arquivoReal(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'lote');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function assertSemOrigem(string $json, string $onde): void
    {
        $json = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $json);
        $this->assertDoesNotMatchRegularExpression('/mercado|an[uú]ncio|publica|\bmlb|\bml\b/iu', $json, "a origem vazou em {$onde}");
    }

    public function test_o_nome_do_arquivo_da_a_ref_e_a_posicao_com_a_ref_inteira_vencendo(): void
    {
        $this->assertSame([['MESA-01_2', 1], ['MESA-01', 2]], FotosEmLoteService::lerNome('MESA-01_2.jpg')['candidatos']);
        $this->assertSame([['MESA', 1]], FotosEmLoteService::lerNome('C:\\fotos\\MESA.JPG')['candidatos']);
        $this->assertSame('jpg', FotosEmLoteService::lerNome('MESA.JPG')['extensao']);
        $this->assertSame([['CAD_01_3', 1], ['CAD_01', 3]], FotosEmLoteService::lerNome('CAD_01_3.png')['candidatos']);
        $this->assertFalse(FotosEmLoteService::lerNome('MESA_1.gif')['formato_aceito']);
        $this->assertTrue(FotosEmLoteService::lerNome('mesa_1.webp')['formato_aceito']);
    }

    public function test_previa_so_com_os_nomes_diz_onde_cada_foto_entra_e_o_que_fica_de_fora(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mesa = $this->variacao($empresa, 'MESA-01');
        $cheia = $this->variacao($empresa, 'BUF-01', 'Off White');
        $this->jaTem($cheia, 11);
        $comUnder = $this->variacao($empresa, 'CAD_01');
        $refComNumero = $this->variacao($empresa, 'MESA_2');
        $outra = $this->empresaDoGabarito();
        $this->variacao($outra, 'SO-NA-OUTRA');

        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.fotos.previa'), ['nomes' => [
            'MESA-01_2.jpg', 'MESA-01_1.jpg', 'mesa-01_10.png',
            'BUF-01_1.jpg', 'BUF-01_2.jpg',
            'CAD_01_3.png', 'MESA_2.jpg',
            'SO-NA-OUTRA_1.jpg', 'NAO-EXISTE_1.jpg', 'MESA-01_3.gif', 'MESA-01_1.jpg',
        ]])->assertOk();

        $porRef = collect($r->json('variacoes'))->keyBy('ref');
        $this->assertSame(['BUF-01', 'CAD_01', 'MESA-01', 'MESA_2'], $porRef->keys()->all());
        $this->assertSame(['MESA-01_1.jpg', 'MESA-01_2.jpg', 'mesa-01_10.png'], array_column($porRef['MESA-01']['arquivos'], 'nome'), 'na ordem do número, sem caixa na Ref');
        $this->assertSame([1, 2, 10], array_column($porRef['MESA-01']['arquivos'], 'posicao'));
        $this->assertSame([$mesa->id, 12, 0], [$porRef['MESA-01']['variacao_id'], $porRef['MESA-01']['cabem'], $porRef['MESA-01']['imagens']]);
        $this->assertSame([true, false], array_column($porRef['BUF-01']['arquivos'], 'entra'), 'só cabe uma');
        $this->assertSame('Não coube: cada variação aceita até 12 fotos.', $porRef['BUF-01']['arquivos'][1]['motivo']);
        $this->assertSame([['nome' => 'CAD_01_3.png', 'posicao' => 3, 'entra' => true, 'motivo' => null]], $porRef['CAD_01']['arquivos']);
        $this->assertSame($comUnder->id, $porRef['CAD_01']['variacao_id']);
        $this->assertSame($refComNumero->id, $porRef['MESA_2']['variacao_id'], 'a Ref inteira vence o número');
        $this->assertSame(6, $r->json('entram'));

        $fora = collect($r->json('fora'))->pluck('motivo', 'nome');
        $this->assertStringContainsString('Não achamos a Ref SO-NA-OUTRA', $fora['SO-NA-OUTRA_1.jpg'], 'a Ref de outra empresa não existe para esta');
        $this->assertStringContainsString('Não achamos a Ref NAO-EXISTE', $fora['NAO-EXISTE_1.jpg']);
        $this->assertSame('Formato não aceito. Envie JPG, PNG ou WebP.', $fora['MESA-01_3.gif']);
        $this->assertCount(4, $r->json('fora'), 'o nome repetido também fica de fora');
        $this->assertSemOrigem($r->getContent(), 'prévia das fotos');
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('variacao_id', $mesa->id)->count(), 'a prévia não grava nada');
    }

    public function test_enviar_guarda_cada_foto_na_variacao_do_nome_na_ordem_do_numero_e_ate_o_teto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $mesa = $this->variacao($empresa, 'MESA-01');
        $this->jaTem($mesa, 1);
        $cheia = $this->variacao($empresa, 'BUF-01');
        $this->jaTem($cheia, 11);
        $outra = $this->empresaDoGabarito();
        $daOutra = $this->variacao($outra, 'MESA-02');

        Queue::fake();
        $r = $this->entrarNoPortal($empresa)->postJson(route('portal.auth.estrutura.produtos.fotos.enviar'), ['imagens' => [
            $this->jpg('MESA-01_3.jpg'), $this->jpg('MESA-01_1.jpg'),
            $this->jpg('BUF-01_2.jpg'), $this->jpg('BUF-01_1.jpg'),
            $this->jpg('MESA-02_1.jpg'),
            $this->arquivoReal('MESA-01_2.jpg', "MZ\x90\x00\x03\x00\x00\x00 isto não é imagem"),
        ]])->assertOk();

        $this->assertSame(3, $r->json('enviadas'));
        $situacao = collect($r->json('resultados'))->pluck('situacao', 'nome');
        $this->assertSame('enviada', $situacao['MESA-01_1.jpg']);
        $this->assertSame('enviada', $situacao['MESA-01_3.jpg']);
        $this->assertSame('enviada', $situacao['BUF-01_1.jpg']);
        $this->assertSame('fora', $situacao['BUF-01_2.jpg'], 'passou do teto: entra a de número menor');
        $this->assertSame('fora', $situacao['MESA-02_1.jpg'], 'Ref de outra empresa');
        $this->assertSame('fora', $situacao['MESA-01_2.jpg'], 'o conteúdo não é imagem: só ela fica de fora');
        $motivos = collect($r->json('resultados'))->pluck('motivo', 'nome');
        $this->assertSame('Formato de imagem não aceito. Envie JPG, PNG ou WebP.', $motivos['MESA-01_2.jpg']);

        // Depois das que já existiam, na ordem do número.
        $galeria = EstruturaProdutoVariacaoImagem::where('variacao_id', $mesa->id)->orderBy('ordem')->pluck('nome_original')->all();
        $this->assertSame(['antiga-0.jpg', 'MESA-01_1.jpg', 'MESA-01_3.jpg'], $galeria);
        $this->assertSame(12, EstruturaProdutoVariacaoImagem::where('variacao_id', $cheia->id)->count());
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('variacao_id', $daOutra->id)->count());
        foreach (EstruturaProdutoVariacaoImagem::where('variacao_id', $mesa->id)->where('nome_original', 'like', 'MESA%')->get() as $img) {
            Storage::disk('local')->assertExists($img->caminho);
        }

        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $mesa->produto_id);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $cheia->produto_id);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === $daOutra->produto_id);
        $this->assertSemOrigem($r->getContent(), 'resultado do envio');
    }

    public function test_remessa_grande_demais_e_sem_arquivo_respondem_422_com_mensagem_clara(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->variacao($empresa, 'MESA-01');
        $sessao = $this->entrarNoPortal($empresa);

        $muitas = array_map(fn ($i) => $this->jpg("MESA-01_{$i}.jpg"), range(1, FotosEmLoteService::MAX_POR_ENVIO + 1));
        $sessao->postJson(route('portal.auth.estrutura.produtos.fotos.enviar'), ['imagens' => $muitas])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Envie no máximo 20 fotos por vez.');
        $sessao->postJson(route('portal.auth.estrutura.produtos.fotos.enviar'), [])
            ->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma foto.');
        $sessao->postJson(route('portal.auth.estrutura.produtos.fotos.previa'), ['nomes' => []])->assertStatus(422);
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::count());
    }
}
