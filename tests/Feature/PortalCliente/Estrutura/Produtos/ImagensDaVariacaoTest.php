<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Imagens por variação do produto: o cliente sobe várias imagens por cor, ordena (a 1ª é a
 * capa) e elas ficam no disco PRIVADO, entregues só por rota autenticada da empresa dona.
 *
 * Modos de falha que estes testes impedem: imagem de uma empresa visível a outra (por id
 * adivinhado, ou por id de imagem de OUTRA variação da mesma empresa); arquivo fora do disco
 * privado; mais de 12 por variação; formato disfarçado (executável com extensão .jpg); envio
 * grande demais morrendo mudo; arquivo órfão depois de excluir a imagem ou a variação.
 */
class ImagensDaVariacaoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    /** @return array{0: EstruturaProduto, 1: EstruturaProdutoVariacao} */
    private function variacao(Company $empresa, string $codigo = 'CAD-1'): array
    {
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => 'P-'.$codigo, 'nome' => 'Cadeira '.$codigo]);
        $variacao = EstruturaProdutoVariacao::create([
            'produto_id' => $produto->id, 'company_id' => $empresa->id, 'ordem' => 1, 'codigo' => $codigo, 'eixo' => 'cor', 'valor' => 'Natural',
        ]);

        return [$produto, $variacao];
    }

    private function urlEnviar(int $variacaoId): string
    {
        return route('portal.auth.estrutura.produtos.imagens.enviar', $variacaoId);
    }

    /** @param array<int, UploadedFile> $arquivos */
    private function enviar(int $variacaoId, array $arquivos, array $extra = [])
    {
        return $this->postJson($this->urlEnviar($variacaoId), ['imagens' => $arquivos] + $extra);
    }

    private function jpg(string $nome = 'foto.jpg', int $l = 800, int $a = 600): UploadedFile
    {
        return UploadedFile::fake()->image($nome, $l, $a);
    }

    /**
     * Arquivo de verdade em disco temporário: o `UploadedFile::fake()` do Laravel deduz o MIME pelo NOME,
     * e o que importa aqui é o servidor olhar o CONTEÚDO (executável chamado de .jpg).
     */
    private function arquivoReal(string $nome, string $conteudo): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    /** Grava direto no banco e no disco (sem passar pelo HTTP): para preparar o cenário de OUTRA empresa. */
    private function imagemDireta(Company $empresa, EstruturaProdutoVariacao $v, int $ordem, string $conteudo = 'conteudo'): EstruturaProdutoVariacaoImagem
    {
        $caminho = "estrutura/{$empresa->id}/produtos/{$v->produto_id}/variacoes/{$v->id}/seed-{$ordem}-".uniqid().'.jpg';
        Storage::disk('local')->put($caminho, $conteudo);

        return EstruturaProdutoVariacaoImagem::create([
            'company_id' => $empresa->id, 'produto_id' => $v->produto_id, 'variacao_id' => $v->id,
            'caminho' => $caminho, 'nome_original' => "seed-{$ordem}.jpg", 'mime' => 'image/jpeg', 'tamanho' => strlen($conteudo), 'ordem' => $ordem,
        ]);
    }

    // ─── Envio ───────────────────────────────────────────────────────────────

    public function test_o_envio_guarda_no_disco_privado_escopado_pela_empresa_da_sessao(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        [$produto, $variacao] = $this->variacao($minha);

        $resposta = $this->entrarNoPortal($minha)
            ->post($this->urlEnviar($variacao->id), [
                'imagens'    => [$this->jpg('frente.jpg', 800, 600), $this->jpg('lado.png', 400, 300)],
                'company_id' => $outra->id,
                'produto_id' => 999,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(2, 'imagens')
            ->assertJsonPath('imagens.0.capa', true)
            ->assertJsonPath('imagens.1.capa', false)
            ->assertJsonPath('imagens.0.nome_original', 'frente.jpg');

        $linhas = EstruturaProdutoVariacaoImagem::orderBy('ordem')->get();
        $this->assertCount(2, $linhas);
        $this->assertSame([0, 1], $linhas->pluck('ordem')->all());
        $this->assertSame([$minha->id, $minha->id], $linhas->pluck('company_id')->all(), 'company_id vem da sessão, nunca do corpo');
        $this->assertSame([$produto->id, $produto->id], $linhas->pluck('produto_id')->all());
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('company_id', $outra->id)->count());

        $this->assertSame('image/jpeg', $linhas[0]->mime);
        $this->assertSame('image/png', $linhas[1]->mime);
        $this->assertSame([800, 600], [$linhas[0]->largura, $linhas[0]->altura]);
        $this->assertSame([400, 300], [$linhas[1]->largura, $linhas[1]->altura]);
        $this->assertGreaterThan(0, $linhas[0]->tamanho);

        foreach ($linhas as $linha) {
            $this->assertMatchesRegularExpression(
                "#^estrutura/{$minha->id}/produtos/{$produto->id}/variacoes/{$variacao->id}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png)$#",
                $linha->caminho,
            );
            Storage::disk('local')->assertExists($linha->caminho);
            Storage::disk('public')->assertMissing($linha->caminho);
        }
        $this->assertSame([], Storage::disk('public')->allFiles(), 'nada no disco público');

        // O caminho do disco não vai para a tela; só a rota autenticada.
        $this->assertStringNotContainsString($linhas[0]->caminho, $resposta->getContent());
        $this->assertSame(
            '/portal/estrutura/produtos/variacao/'.$variacao->id.'/imagem/'.$linhas[0]->id,
            $resposta->json('imagens.0.url'),
        );
    }

    public function test_uma_nova_remessa_vai_para_o_fim_e_o_envio_fica_no_log_como_cliente(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('a.jpg'), $this->jpg('b.jpg')]])->assertOk();
        $r = $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('c.jpg')]])->assertOk();

        $r->assertJsonCount(3, 'imagens')->assertJsonPath('imagens.2.nome_original', 'c.jpg')->assertJsonPath('imagens.2.ordem', 2);
        $log = Activity::query()->where('properties->evento', 'imagens_variacao_enviadas')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('cliente', $log->properties['origem']);
    }

    public function test_o_teto_e_de_doze_por_variacao_e_o_excedente_e_recusado_sem_guardar_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $doze = array_map(fn ($i) => $this->jpg("f{$i}.jpg", 100, 100), range(1, 12));
        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => $doze])->assertOk()->assertJsonCount(12, 'imagens');

        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('treze.jpg')]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('imagens')
            ->assertJsonPath('errors.imagens.0', 'Esta variação já tem as 12 imagens permitidas. Exclua uma para enviar outra.');

        $this->assertSame(12, EstruturaProdutoVariacaoImagem::count());
        $this->assertCount(12, Storage::disk('local')->allFiles());
    }

    public function test_a_remessa_que_nao_cabe_inteira_e_recusada_inteira(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $dez = array_map(fn ($i) => $this->jpg("f{$i}.jpg", 100, 100), range(1, 10));
        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => $dez])->assertOk();

        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('a.jpg'), $this->jpg('b.jpg'), $this->jpg('c.jpg')]])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'Cada variação aceita até 12 imagens. Esta já tem 10; envie no máximo 2.');

        $this->assertSame(10, EstruturaProdutoVariacaoImagem::count(), 'nenhuma das três entrou');
        $this->assertCount(10, Storage::disk('local')->allFiles(), 'e nenhum arquivo ficou no disco');
    }

    public function test_o_teto_conta_por_variacao_e_nao_por_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $cheia] = $this->variacao($empresa, 'COR-A');
        [, $vazia] = $this->variacao($empresa, 'COR-B');
        for ($i = 0; $i < 12; $i++) {
            $this->imagemDireta($empresa, $cheia, $i);
        }

        $this->entrarNoPortal($empresa)
            ->postJson($this->urlEnviar($vazia->id), ['imagens' => [$this->jpg()]])
            ->assertOk()->assertJsonCount(1, 'imagens');
    }

    public function test_formato_invalido_ou_disfarcado_e_recusado_com_422(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $invalidos = [
            'pdf'          => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            'texto'        => $this->arquivoReal('nota.txt', 'oi'),
            'gif'          => $this->arquivoReal('anim.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            'svg'          => $this->arquivoReal('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'script no .jpg' => $this->arquivoReal('foto.jpg', '<?php echo 1; ?>'),
            'html no .png' => $this->arquivoReal('foto.png', '<html><script>alert(1)</script></html>'),
            'executavel no .webp' => $this->arquivoReal('foto.webp', "MZ�       ��"),
            'gif com nome .jpg' => $this->arquivoReal('foto.jpg', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
        ];

        foreach ($invalidos as $rotulo => $arquivo) {
            $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$arquivo]])
                ->assertStatus(422, "{$rotulo} deveria ser recusado")
                ->assertJsonValidationErrors('imagens.0');
        }

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_uma_imagem_boa_com_outra_ruim_na_mesma_remessa_nao_grava_nenhuma_e_a_mensagem_aponta_qual(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        $this->entrarNoPortal($empresa)
            ->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg(), UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')]])
            ->assertStatus(422)
            ->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), 'A imagem 2 não está num formato aceito'));

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::count());
    }

    public function test_aceita_jpg_jpeg_png_e_webp(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        $r = $this->entrarNoPortal($empresa)->postJson($this->urlEnviar($variacao->id), ['imagens' => [
            $this->jpg('a.jpg'), $this->jpg('b.jpeg'), $this->jpg('c.png'), $this->jpg('d.webp'),
        ]])->assertOk()->assertJsonCount(4, 'imagens');

        $this->assertSame(['image/jpeg', 'image/jpeg', 'image/png', 'image/webp'], array_column($r->json('imagens'), 'mime'));
        $this->assertSame(['jpg', 'jpg', 'png', 'webp'], EstruturaProdutoVariacaoImagem::orderBy('ordem')->get()
            ->map(fn ($i) => pathinfo($i->caminho, PATHINFO_EXTENSION))->all());
    }

    public function test_imagem_acima_do_teto_por_arquivo_e_recusada_dizendo_o_limite(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        $this->entrarNoPortal($empresa)
            ->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('enorme.jpg')->size(10241)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['imagens.0' => 'A imagem 1 passa de 10 MB; tente uma menor.']);
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::count());
    }

    public function test_o_teto_por_arquivo_vem_da_config(): void
    {
        config(['estrutura_produtos.imagens.max_kb' => 100]);
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg()->size(101)]])->assertStatus(422);
        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg()->size(100)]])->assertOk();
    }

    public function test_sem_nenhuma_imagem_a_mensagem_pede_para_escolher(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->postJson($this->urlEnviar($variacao->id), [])->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma imagem.');
        $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => []])->assertStatus(422)->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma imagem.');
    }

    // ─── Envio grande demais (post_max_size / upload_max_filesize) ───────────

    public function test_corpo_que_estourou_o_post_max_size_chega_vazio_e_vira_mensagem_clara(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        // O PHP descartou o corpo (sem $_FILES) mas o cabeçalho ainda diz o tamanho original; o
        // `ValidatePostSize` do Laravel o barra com 413 mudo e o `withExceptions` do bootstrap o traduz.
        $this->entrarNoPortal($empresa)
            ->call('POST', $this->urlEnviar($variacao->id), [], [], [], [
                'CONTENT_LENGTH' => 50 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'A imagem é grande demais para enviar; tente uma menor.');

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::count());
    }

    public function test_corpo_grande_sem_arquivo_abaixo_do_post_max_size_tambem_vira_a_mensagem(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        $this->entrarNoPortal($empresa)
            ->call('POST', $this->urlEnviar($variacao->id), [], [], [], [
                'CONTENT_LENGTH' => 3 * 1024 * 1024, 'HTTP_ACCEPT' => 'application/json',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'A imagem é grande demais para enviar; tente uma menor.');
    }

    public function test_arquivo_que_estourou_o_upload_max_filesize_vira_a_mesma_mensagem(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        $grande = new UploadedFile($caminho, 'grande.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->entrarNoPortal($empresa)
            ->post($this->urlEnviar($variacao->id), ['imagens' => [$grande]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'A imagem é grande demais para enviar; tente uma menor.');

        @unlink($caminho);
    }

    public function test_upload_interrompido_nao_e_tratado_como_tamanho(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $caminho = tempnam(sys_get_temp_dir(), 'img');
        $parcial = new UploadedFile($caminho, 'parcial.jpg', 'image/jpeg', UPLOAD_ERR_PARTIAL, true);

        $this->entrarNoPortal($empresa)
            ->post($this->urlEnviar($variacao->id), ['imagens' => [$parcial]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'Não foi possível receber a imagem. Tente de novo.');

        @unlink($caminho);
    }

    public function test_pedido_pequeno_sem_arquivo_continua_sendo_so_escolha_uma_imagem(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);

        $this->entrarNoPortal($empresa)
            ->call('POST', $this->urlEnviar($variacao->id), [], [], [], ['CONTENT_LENGTH' => 200, 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.imagens.0', 'Escolha ao menos uma imagem.');
    }

    // ─── Servir ──────────────────────────────────────────────────────────────

    public function test_a_empresa_dona_recebe_o_arquivo_com_tipo_certo_e_cache_privado(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $url = $sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('frente.jpg')]])->json('imagens.0.url');
        $linha = EstruturaProdutoVariacaoImagem::firstOrFail();
        $esperado = Storage::disk('local')->get($linha->caminho);

        $r = $sessao->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($esperado, $r->streamedContent());
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', (string) $r->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringNotContainsString('frente.jpg', (string) $r->headers->get('Content-Disposition'), 'o nome em disco é o uuid, não o do cliente');
    }

    public function test_outra_empresa_recebe_404_e_nunca_o_arquivo(): void
    {
        $minha = $this->empresaDoGabarito();
        $alheia = $this->empresaDoGabarito();
        [, $minhaVariacao] = $this->variacao($minha, 'MINHA');
        [, $variacaoAlheia] = $this->variacao($alheia, 'ALHEIA');
        $imagemAlheia = $this->imagemDireta($alheia, $variacaoAlheia, 0, 'segredo-da-outra');
        $minhaImagem = $this->imagemDireta($minha, $minhaVariacao, 0, 'minha');

        $sessao = $this->entrarNoPortal($minha);

        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$variacaoAlheia->id, $imagemAlheia->id]))->assertNotFound();
        // A variação é minha mas a imagem é de outra empresa: também 404.
        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$minhaVariacao->id, $imagemAlheia->id]))->assertNotFound();
        // A imagem é minha mas a variação é de outra empresa.
        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$variacaoAlheia->id, $minhaImagem->id]))->assertNotFound();
        // Id que não existe: o mesmo 404 (não confirma nada).
        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [999999, 999999]))->assertNotFound();

        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$minhaVariacao->id, $minhaImagem->id]))->assertOk();
    }

    public function test_imagem_de_outra_variacao_da_mesma_empresa_por_este_caminho_e_404(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $natural] = $this->variacao($empresa, 'COR-NAT');
        [, $preto] = $this->variacao($empresa, 'COR-PRE');
        $daNatural = $this->imagemDireta($empresa, $natural, 0);
        $sessao = $this->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$preto->id, $daNatural->id]))->assertNotFound();
        $sessao->get(route('portal.auth.estrutura.produtos.imagens.ver', [$natural->id, $daNatural->id]))->assertOk();
    }

    public function test_linha_sem_arquivo_no_disco_responde_404_e_nao_500(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $imagem = $this->imagemDireta($empresa, $variacao, 0);
        Storage::disk('local')->delete($imagem->caminho);

        $this->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos.imagens.ver', [$variacao->id, $imagem->id]))
            ->assertNotFound();
    }

    public function test_sem_sessao_do_portal_nao_serve_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $imagem = $this->imagemDireta($empresa, $variacao, 0, 'privado');

        $r = $this->get(route('portal.auth.estrutura.produtos.imagens.ver', [$variacao->id, $imagem->id]));
        $r->assertRedirect();
        $this->assertStringNotContainsString('privado', (string) $r->getContent());
    }

    public function test_a_equipe_tambem_ve_e_envia_na_empresa_aberta_e_o_log_diz_interno(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $admin = \App\Models\User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
        $ticket = app(\App\Services\Portal\PortalEquipeService::class)->emitir($admin, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]));

        $url = $this->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg()]])->assertOk()->json('imagens.0.url');
        $this->get($url)->assertOk();

        $this->assertSame('interno', Activity::query()->where('properties->evento', 'imagens_variacao_enviadas')->latest('id')->first()->properties['origem']);
    }

    // ─── Escopo no envio, na exclusão e na ordem ─────────────────────────────

    public function test_variacao_de_outra_empresa_responde_404_antes_de_validar_em_todas_as_rotas(): void
    {
        $minha = $this->empresaDoGabarito();
        $alheia = $this->empresaDoGabarito();
        [, $variacaoAlheia] = $this->variacao($alheia);
        $imagemAlheia = $this->imagemDireta($alheia, $variacaoAlheia, 0);
        $sessao = $this->entrarNoPortal($minha);

        // Corpo inválido de propósito: o 404 vem ANTES do 422 ("não é seu" nunca vira "campo faltando").
        $sessao->postJson($this->urlEnviar($variacaoAlheia->id), [])->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.produtos.imagens.ordem', $variacaoAlheia->id), [])->assertNotFound();
        $sessao->deleteJson(route('portal.auth.estrutura.produtos.imagens.excluir', [$variacaoAlheia->id, $imagemAlheia->id]))->assertNotFound();

        $this->assertSame(1, EstruturaProdutoVariacaoImagem::count());
        Storage::disk('local')->assertExists($imagemAlheia->caminho);
    }

    public function test_excluir_remove_a_linha_e_o_arquivo_e_a_proxima_vira_capa(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $sessao = $this->entrarNoPortal($empresa);

        $ids = collect($sessao->postJson($this->urlEnviar($variacao->id), ['imagens' => [$this->jpg('a.jpg'), $this->jpg('b.jpg'), $this->jpg('c.jpg')]])->json('imagens'))->pluck('id')->all();
        $capa = EstruturaProdutoVariacaoImagem::find($ids[0]);

        $r = $sessao->deleteJson(route('portal.auth.estrutura.produtos.imagens.excluir', [$variacao->id, $ids[0]]))->assertOk();

        Storage::disk('local')->assertMissing($capa->caminho);
        $this->assertNull(EstruturaProdutoVariacaoImagem::find($ids[0]));
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $r->assertJsonCount(2, 'imagens')
            ->assertJsonPath('imagens.0.id', $ids[1])->assertJsonPath('imagens.0.ordem', 0)->assertJsonPath('imagens.0.capa', true)
            ->assertJsonPath('imagens.1.id', $ids[2])->assertJsonPath('imagens.1.ordem', 1);
        $this->assertSame([0, 1], EstruturaProdutoVariacaoImagem::orderBy('ordem')->pluck('ordem')->all());
    }

    public function test_excluir_imagem_de_outra_variacao_da_mesma_empresa_e_404_e_nao_apaga_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $natural] = $this->variacao($empresa, 'COR-NAT');
        [, $preto] = $this->variacao($empresa, 'COR-PRE');
        $daNatural = $this->imagemDireta($empresa, $natural, 0);

        $this->entrarNoPortal($empresa)
            ->deleteJson(route('portal.auth.estrutura.produtos.imagens.excluir', [$preto->id, $daNatural->id]))->assertNotFound();

        $this->assertNotNull(EstruturaProdutoVariacaoImagem::find($daNatural->id));
        Storage::disk('local')->assertExists($daNatural->caminho);
    }

    public function test_reordenar_aplica_a_ordem_so_nos_ids_da_variacao_e_ignora_os_de_fora(): void
    {
        $empresa = $this->empresaDoGabarito();
        $alheia = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa, 'COR-A');
        [, $outraMinha] = $this->variacao($empresa, 'COR-B');
        [, $variacaoAlheia] = $this->variacao($alheia, 'ALHEIA');

        $a = $this->imagemDireta($empresa, $variacao, 0);
        $b = $this->imagemDireta($empresa, $variacao, 1);
        $c = $this->imagemDireta($empresa, $variacao, 2);
        $deOutraVariacao = $this->imagemDireta($empresa, $outraMinha, 0);
        $deOutraEmpresa = $this->imagemDireta($alheia, $variacaoAlheia, 5);

        $r = $this->entrarNoPortal($empresa)
            ->putJson(route('portal.auth.estrutura.produtos.imagens.ordem', $variacao->id), [
                'ordem' => [$c->id, $deOutraEmpresa->id, $a->id, $deOutraVariacao->id, 999999, $c->id],
            ])->assertOk();

        // c e a pedidos, nesta ordem; b não citada vai para o fim. Repetido conta uma vez.
        $r->assertJsonPath('imagens.0.id', $c->id)->assertJsonPath('imagens.0.capa', true)
            ->assertJsonPath('imagens.1.id', $a->id)
            ->assertJsonPath('imagens.2.id', $b->id);
        $this->assertSame(0, $c->fresh()->ordem);
        $this->assertSame(1, $a->fresh()->ordem);
        $this->assertSame(2, $b->fresh()->ordem);

        // O que é de fora ficou exatamente como estava.
        $this->assertSame(0, $deOutraVariacao->fresh()->ordem);
        $this->assertSame(5, $deOutraEmpresa->fresh()->ordem);
        $this->assertSame($alheia->id, $deOutraEmpresa->fresh()->company_id);
    }

    public function test_reordenar_com_ordem_malformada_e_422(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $a = $this->imagemDireta($empresa, $variacao, 0);
        $sessao = $this->entrarNoPortal($empresa);
        $url = route('portal.auth.estrutura.produtos.imagens.ordem', $variacao->id);

        $sessao->putJson($url, [])->assertStatus(422)->assertJsonPath('errors.ordem.0', 'Informe a ordem das imagens.');
        $sessao->putJson($url, ['ordem' => 'abc'])->assertStatus(422);
        $sessao->putJson($url, ['ordem' => [$a->id, 'x']])->assertStatus(422)->assertJsonValidationErrors('ordem.1');
        $this->assertSame(0, $a->fresh()->ordem);
    }

    // ─── A prop da ficha ─────────────────────────────────────────────────────

    public function test_a_ficha_traz_as_imagens_de_cada_variacao_com_a_url_autenticada(): void
    {
        $empresa = $this->empresaDoGabarito();
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['chave' => 'a', 'grupo' => 'CAD', 'codigo' => 'CAD-1', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Natural'],
            ['chave' => 'b', 'grupo' => 'CAD', 'codigo' => 'CAD-2', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Preto'],
        ], $this->atorCliente($empresa));
        $produto = EstruturaProduto::where('company_id', $empresa->id)->firstOrFail();
        $natural = EstruturaProdutoVariacao::where('codigo', 'CAD-1')->firstOrFail();
        $preto = EstruturaProdutoVariacao::where('codigo', 'CAD-2')->firstOrFail();

        $sessao = $this->withoutVite()->entrarNoPortal($empresa);
        $sessao->postJson($this->urlEnviar($natural->id), ['imagens' => [$this->jpg('capa.jpg', 640, 480), $this->jpg('detalhe.png', 320, 240)]])->assertOk();
        $idCapa = EstruturaProdutoVariacaoImagem::where('variacao_id', $natural->id)->where('ordem', 0)->value('id');

        $pagina = $sessao->get(route('portal.auth.estrutura.produtos.ficha', $produto->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaProdutoFicha')
                ->has('linhas', 2)
                ->where('linhas.0.id', $natural->id)
                ->has('linhas.0.imagens', 2)
                ->where('linhas.0.imagens.0.id', $idCapa)
                ->where('linhas.0.imagens.0.url', "/portal/estrutura/produtos/variacao/{$natural->id}/imagem/{$idCapa}")
                ->where('linhas.0.imagens.0.nome_original', 'capa.jpg')
                ->where('linhas.0.imagens.0.ordem', 0)
                ->where('linhas.0.imagens.0.capa', true)
                ->where('linhas.0.imagens.1.nome_original', 'detalhe.png')
                ->where('linhas.0.imagens.1.capa', false)
                ->where('linhas.0.imagens.0.largura', 640)
                ->where('linhas.1.id', $preto->id)
                ->where('linhas.1.imagens', [])
            );

        // A url da prop serve a imagem para quem está logado; e o caminho do disco não vai na prop.
        $sessao->get("/portal/estrutura/produtos/variacao/{$natural->id}/imagem/{$idCapa}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $props = json_encode($pagina->viewData('page')['props']);
        $this->assertStringNotContainsString('"caminho"', $props);
        $this->assertStringNotContainsString("estrutura/{$empresa->id}/produtos/", $props);
    }

    public function test_a_ficha_de_produto_novo_continua_sem_linhas_e_a_lista_de_produtos_nao_ganha_imagens(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->variacao($empresa);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura.produtos.novo'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('linhas', []));
        $sessao->get(route('portal.auth.estrutura.produtos'))->assertOk()
            ->assertInertia(fn ($page) => $page->missing('produtos.linhas.0.imagens'));
    }

    // ─── Limpeza do disco ────────────────────────────────────────────────────

    public function test_excluir_a_variacao_apaga_as_imagens_do_disco(): void
    {
        $empresa = $this->empresaDoGabarito();
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['chave' => 'a', 'grupo' => 'CAD', 'codigo' => 'CAD-1', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Natural'],
            ['chave' => 'b', 'grupo' => 'CAD', 'codigo' => 'CAD-2', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Preto'],
        ], $this->atorCliente($empresa));
        $natural = EstruturaProdutoVariacao::where('codigo', 'CAD-1')->firstOrFail();
        $preto = EstruturaProdutoVariacao::where('codigo', 'CAD-2')->firstOrFail();
        $sessao = $this->entrarNoPortal($empresa);
        $sessao->postJson($this->urlEnviar($natural->id), ['imagens' => [$this->jpg(), $this->jpg('b.jpg')]])->assertOk();
        $sessao->postJson($this->urlEnviar($preto->id), ['imagens' => [$this->jpg('c.jpg')]])->assertOk();
        $this->assertCount(3, Storage::disk('local')->allFiles());

        $sessao->deleteJson(route('portal.auth.estrutura.produtos.variacoes.excluir', $natural->id))->assertOk();

        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('variacao_id', $natural->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles("estrutura/{$empresa->id}/produtos/{$natural->produto_id}/variacoes/{$natural->id}"));
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'só a imagem da outra variação sobrou');
    }

    // ─── Schema ──────────────────────────────────────────────────────────────

    public function test_a_tabela_tem_as_colunas_do_desenho_e_o_indice_composto(): void
    {
        $this->assertTrue(Schema::hasColumns('estrutura_produto_variacao_imagens', [
            'id', 'company_id', 'produto_id', 'variacao_id', 'caminho', 'nome_original', 'mime', 'tamanho', 'largura', 'altura', 'ordem', 'created_at', 'updated_at',
        ]));

        $indices = collect(Schema::getIndexes('estrutura_produto_variacao_imagens'))->keyBy('name');
        $this->assertSame(['company_id', 'variacao_id', 'ordem'], $indices['epvi_company_var_ordem_idx']['columns']);
        $this->assertSame(['variacao_id'], $indices['epvi_variacao_idx']['columns']);
        $this->assertSame(['produto_id'], $indices['epvi_produto_idx']['columns']);
        $this->assertFalse($indices['epvi_company_var_ordem_idx']['unique'], 'ordem repetida momentânea na reordenação não pode ser barrada');
    }

    public function test_largura_e_altura_aceitam_nulo_e_o_resto_e_obrigatorio(): void
    {
        $empresa = $this->empresaDoGabarito();
        [$produto, $variacao] = $this->variacao($empresa);

        $semMedidas = EstruturaProdutoVariacaoImagem::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'variacao_id' => $variacao->id,
            'caminho' => 'x/y.jpg', 'nome_original' => 'y.jpg', 'mime' => 'image/jpeg', 'tamanho' => 10, 'ordem' => 0,
        ]);
        $this->assertNull($semMedidas->fresh()->largura);
        $this->assertNull($semMedidas->fresh()->altura);

        $this->expectException(QueryException::class);
        EstruturaProdutoVariacaoImagem::create([
            'company_id' => $empresa->id, 'produto_id' => $produto->id, 'variacao_id' => $variacao->id,
            'nome_original' => 'z.jpg', 'mime' => 'image/jpeg', 'tamanho' => 10, 'ordem' => 1,
        ]);
    }

    public function test_apagar_variacao_produto_ou_empresa_leva_as_linhas_das_imagens_e_nao_toca_as_da_outra(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        [$pa1, $va1] = $this->variacao($a, 'A1');
        [$pa2, $va2] = $this->variacao($a, 'A2');
        [, $vb] = $this->variacao($b, 'B1');
        $this->imagemDireta($a, $va1, 0);
        $this->imagemDireta($a, $va2, 0);
        $this->imagemDireta($b, $vb, 0);
        $this->assertSame(3, EstruturaProdutoVariacaoImagem::count());

        // Variação: cascata.
        EstruturaProdutoVariacao::query()->whereKey($va1->id)->delete();
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('variacao_id', $va1->id)->count());
        $this->assertSame(2, EstruturaProdutoVariacaoImagem::count());

        // Produto: cascata (variação e imagens).
        $pa2->delete();
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('company_id', $a->id)->count());
        $this->assertSame(1, EstruturaProdutoVariacaoImagem::count());

        // Empresa: cascata.
        $this->imagemDireta($a, $this->variacao($a, 'A3')[1], 0);
        Company::find($a->id)->delete();
        $this->assertSame(0, EstruturaProdutoVariacaoImagem::where('company_id', $a->id)->count());
        $this->assertSame(1, EstruturaProdutoVariacaoImagem::where('company_id', $b->id)->count());
    }

    public function test_a_migration_repetida_nao_muda_nada_e_repoe_o_indice_que_faltar(): void
    {
        $empresa = $this->empresaDoGabarito();
        [, $variacao] = $this->variacao($empresa);
        $this->imagemDireta($empresa, $variacao, 0);

        $migration = require database_path('migrations/2026_10_07_100300_create_estrutura_produto_variacao_imagens_table.php');

        $migration->up();
        $migration->up();
        $this->assertSame(1, EstruturaProdutoVariacaoImagem::count(), 'rodar de novo não apaga nem duplica');

        Schema::table('estrutura_produto_variacao_imagens', fn ($t) => $t->dropIndex('epvi_produto_idx'));
        $this->assertNotContains('epvi_produto_idx', array_column(Schema::getIndexes('estrutura_produto_variacao_imagens'), 'name'));

        $migration->up();
        $this->assertContains('epvi_produto_idx', array_column(Schema::getIndexes('estrutura_produto_variacao_imagens'), 'name'));
        $this->assertSame(1, EstruturaProdutoVariacaoImagem::count());

        $migration->down();
        $this->assertFalse(Schema::hasTable('estrutura_produto_variacao_imagens'));
        $migration->down();
        $migration->up();
        $this->assertTrue(Schema::hasTable('estrutura_produto_variacao_imagens'));
    }

    public function test_os_nomes_de_indice_e_fk_da_migration_cabem_nos_64_do_mariadb(): void
    {
        $codigo = file_get_contents(database_path('migrations/2026_10_07_100300_create_estrutura_produto_variacao_imagens_table.php'));
        preg_match_all("/'(epvi_[a-z_]+)'/", $codigo, $m);

        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $nome) {
            $this->assertLessThan(64, strlen($nome), $nome);
        }
        $this->assertSame(0, preg_match("/->(enum|json)\(/", $codigo), 'sem enum nem json (armadilhas do MariaDB)');
    }

    // ─── Allowlist do domínio do cliente ─────────────────────────────────────

    public function test_a_allowlist_libera_so_digitos_nos_dois_ids(): void
    {
        $base = 'portal/estrutura/produtos/variacao';

        $this->assertTrue(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3"));
        $this->assertTrue(RestringeDominioDoPortal::liberado("{$base}/12/imagens"));
        $this->assertTrue(RestringeDominioDoPortal::liberado("{$base}/12/imagens/ordem"));

        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/x"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/x/imagem/3"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3x"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3/outra"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3%2F.."));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12%2Fimagem/3x"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3/../../x"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagens/ordem/x"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagens/outra"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagens/3"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/"));
        $this->assertFalse(RestringeDominioDoPortal::liberado("{$base}/12/imagem/3\n"));
    }

    public function test_no_dominio_do_cliente_a_rota_da_imagem_so_existe_com_id_numerico(): void
    {
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $base = 'http://cliente.teste/portal/estrutura/produtos/variacao';

        // Existe (sem sessão manda para a entrada); com id não numérico ou caminho extra, 404.
        $this->get("{$base}/12/imagem/3")->assertRedirect();
        $this->get("{$base}/12/imagem/x")->assertNotFound();
        $this->get("{$base}/12/imagem/3%2Fx")->assertNotFound();
        $this->get("{$base}/12/imagem/3/outra")->assertNotFound();
        $this->deleteJson("{$base}/12/imagem/x")->assertNotFound();
    }

    public function test_no_dominio_do_cliente_o_upload_e_a_ordem_passam_pela_allowlist(): void
    {
        config(['portal.dominio_cliente' => 'cliente.teste']);
        $base = 'http://cliente.teste/portal/estrutura/produtos/variacao';

        $this->assertNotSame(404, $this->postJson("{$base}/12/imagens", [])->status());
        $this->assertNotSame(404, $this->putJson("{$base}/12/imagens/ordem", [])->status());
        $this->assertSame(404, $this->postJson("{$base}/12/imagens/x", [])->status());
    }

    public function test_toda_rota_de_imagem_tem_where_numerico_nos_dois_parametros_e_throttle_proprio(): void
    {
        $achadas = 0;
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $rota) {
            if (! str_starts_with((string) $rota->getName(), 'portal.auth.estrutura.produtos.imagens.')) {
                continue;
            }
            $achadas++;
            foreach ($rota->parameterNames() as $parametro) {
                $this->assertSame('[0-9]+', $rota->wheres[$parametro] ?? null, "{$rota->getName()}: {$parametro} sem whereNumber");
            }
            $throttle = collect($rota->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertStringContainsString(',estrutura.produtos.imagens.', (string) $throttle);
        }
        $this->assertSame(4, $achadas);
    }
}
