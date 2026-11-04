<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\PortalUsuario;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Portal\PortalEquipeService;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Concerns\EntraNoPortal;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * R2 do 1º teste do usuário (04/11/2026): a coluna FOTOS da Lista mostrava só a caixa com a contagem (0, 5, 1…), nunca
 * a miniatura.
 *
 * A causa: a coluna nasceu como o marcador do protótipo — um quadrado com degradê e o número `fotos_min` no canto — e
 * NENHUM endereço de foto chegava à Lista: o serviço só contava as fotos e a lista de campos da página
 * (`ApresentacaoDaTrilha::PRODUTO`) só tinha a contagem. Não havia imagem para carregar.
 *
 * O conserto, e o que este teste segura:
 * - o serviço manda a CAPA de cada produto (a 1ª foto, por `ordem`, da 1ª variação com foto) só pelos ids, sem consulta
 *   a mais;
 * - cada porta troca os ids pelo endereço que ELA serve, e o endereço ABRE (200, imagem): a equipe com escrita pela rota
 *   da equipe; o cliente de verdade pela rota da 4f;
 * - onde a rota não serviria (a equipe sem escrita, a loja sem a trilha de trabalho, o modo equipe do Portal) a capa vem
 *   nula — a foto quebrada é pior que a contagem.
 */
class CapaNaListaTest extends TestCase
{
    use EntraNoPortal;
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
    }

    /**
     * A capa é a foto de MENOR `ordem` (0 é a capa; a reordenação grava a ordem), da 1ª variação — na ordem do cadastro —
     * que tem foto. Sem foto nenhuma, nula. A foto de outra loja não entra.
     */
    public function test_o_servico_manda_a_capa_de_cada_produto_pela_ordem_das_fotos(): void
    {
        $cadeira = $this->produtoCompleto($this->empresa, 'Cadeira Jatobá', 'CJ', ['Azul', 'Preto']);
        [$azul, $preto] = $cadeira['variacoes'];
        // A Azul (1ª cor) sem foto: a capa vem da Preto. Nela, a ordem foi trocada — a capa é a de ordem 0, não a 1ª criada.
        EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $azul->id)->delete();
        $fotosPreto = EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $preto->id)->orderBy('id')->get();
        $fotosPreto[0]->update(['ordem' => 2]);
        $fotosPreto[2]->update(['ordem' => 0]);
        $semFoto = $this->produtoCompleto($this->empresa, 'Mesa Sem Foto', 'MS');
        EstruturaProdutoVariacaoImagem::query()->where('produto_id', $semFoto['produto']->id)->delete();
        // Uma loja vizinha com fotos não muda nada aqui.
        $this->produtoCompleto($this->empresaDaTrilha(), 'Vaso Vizinho', 'VV');

        $trilha = app(TrilhaDaEmpresa::class)->montar($this->empresa);

        $this->assertSame(['variacao' => (int) $preto->id, 'imagem' => (int) $fotosPreto[2]->id], $this->produtoNaTrilha($trilha, (int) $cadeira['produto']->id)['capa']);
        $this->assertNull($this->produtoNaTrilha($trilha, (int) $semFoto['produto']->id)['capa']);
        // A contagem (a régua da Lista) segue a mesma: a menor entre as cores.
        $this->assertSame(0, $this->produtoNaTrilha($trilha, (int) $cadeira['produto']->id)['fotos_min']);
    }

    /** A capa não custa consulta: a contagem e a capa saem da MESMA leitura das fotos. */
    public function test_a_capa_nao_acrescenta_consulta(): void
    {
        $this->produtoCompleto($this->empresa, 'Cadeira Jatobá', 'CJ', ['Azul', 'Preto']);
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(TrilhaDaEmpresa::class)->montar($this->empresa);
            $n = count(array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'estrutura_produto_variacao_imagens')));
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame(1, $contar(), 'uma leitura das fotos para a contagem e a capa');
        $this->produtoCompleto($this->empresa, 'Mesa Jatobá', 'MJ', ['Azul', 'Verde', 'Preto']);
        $this->assertSame(1, $contar(), 'mais produtos, a mesma leitura');
    }

    /**
     * A reprodução do R2 pela porta da EQUIPE: a Lista recebe o endereço da miniatura e ele ABRE — a imagem, não um 404
     * nem o redirecionamento ao login.
     */
    public function test_a_equipe_com_escrita_recebe_a_capa_e_o_endereco_abre_a_miniatura(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Cadeira Jatobá', 'CJ');
        $capa = $this->comArquivo($this->capaDa($p['variacoes'][0]));
        $dev = $this->dev();

        $produto = $this->doProduto($this->actingAs($dev)->get("/trilha/{$this->empresa->id}")->assertOk(), (int) $p['produto']->id);

        $this->assertSame("/trilha/{$this->empresa->id}/fotos/{$p['variacoes'][0]->id}/{$capa->id}", $produto['capa']);
        $foto = $this->actingAs($dev)->get($produto['capa'])->assertOk();
        $this->assertSame('image/jpeg', $foto->headers->get('Content-Type'));
        $this->assertNotSame('', (string) $foto->streamedContent());
    }

    /**
     * Onde a rota da equipe NÃO serviria, nada de endereço: quem não escreve no Portal da empresa (a rota responde 403)
     * e a loja na trilha sem a chave de trabalho (404). A Lista volta à contagem, sem foto quebrada.
     */
    public function test_sem_escrita_ou_sem_a_trilha_de_trabalho_a_capa_vem_nula_porque_a_rota_recusaria(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Cadeira Jatobá', 'CJ');
        $capa = $this->comArquivo($this->capaDa($p['variacoes'][0]));

        // O Dev sem a empresa na carteira (não admin): abre a trilha, não escreve — e a rota da foto recusa.
        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $props = $this->props($this->actingAs($semCarteira->refresh())->get("/trilha/{$this->empresa->id}")->assertOk());
        $this->assertFalse($props['pode_editar']);
        $this->assertNull($this->doProdutoNasProps($props, (int) $p['produto']->id)['capa']);
        $this->actingAs($semCarteira)->get("/trilha/{$this->empresa->id}/fotos/{$p['variacoes'][0]->id}/{$capa->id}")->assertForbidden();

        // A loja na trilha, mas sem a chave de trabalho: o Dev lê a trilha; a rota da foto não existe para ela.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $q = $this->produtoCompleto($soLeitura, 'Mesa Jatobá', 'MJ');
        $capaQ = $this->comArquivo($this->capaDa($q['variacoes'][0]));
        $dev = $this->dev();
        $this->assertNull($this->doProduto($this->actingAs($dev)->get("/trilha/{$soLeitura->id}")->assertOk(), (int) $q['produto']->id)['capa']);
        $this->actingAs($dev)->get("/trilha/{$soLeitura->id}/fotos/{$q['variacoes'][0]->id}/{$capaQ->id}")->assertNotFound();
    }

    /**
     * A porta do CLIENTE (ele lê a Lista): o endereço é o da rota de foto DELE e abre com a sessão dele. O da equipe não
     * serviria (leva ao login). No modo equipe do Portal a rota do cliente é 404 (desenho da 4f): a capa vem nula.
     */
    public function test_o_cliente_recebe_a_capa_pela_rota_dele_e_o_modo_equipe_do_portal_nao(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Cadeira Jatobá', 'CJ');
        $v = $p['variacoes'][0];
        $capa = $this->comArquivo($this->capaDa($v));
        $cliente = $this->clienteDoPortal($this->empresa);

        $produto = $this->doProduto($this->comoCliente($cliente)->get("/portal/{$this->empresa->id}/trilha")->assertOk(), (int) $p['produto']->id);

        $this->assertSame("/portal/{$this->empresa->id}/trilha/fotos/{$v->id}/{$capa->id}", $produto['capa']);
        $this->comoCliente($cliente)->get($produto['capa'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // A rota da equipe, com a sessão do cliente, não abre: é por isso que o endereço muda por porta.
        $this->comoCliente($cliente)->get("/trilha/{$this->empresa->id}/fotos/{$v->id}/{$capa->id}")->assertRedirect();

        // O modo equipe do Portal: a página abre, a capa não vem (a rota do cliente é 404 ali).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->entrarComoEquipe($this->empresa);
        $naPassagem = $this->props($this->get("/portal/{$this->empresa->id}/trilha")->assertOk());
        $this->assertTrue($naPassagem['sessao_equipe']);
        $this->assertNull($this->doProdutoNasProps($naPassagem, (int) $p['produto']->id)['capa']);
        $this->get("/portal/{$this->empresa->id}/trilha/fotos/{$v->id}/{$capa->id}")->assertNotFound();
    }

    // ─── apoio ───

    private function dev(): User
    {
        $usuario = User::factory()->create(['role' => 'admin', 'active' => true]);
        $usuario->forceFill(['is_dev' => true])->save();

        return $usuario->refresh();
    }

    private function comoCliente(PortalUsuario $cliente): static
    {
        $this->app['auth']->forgetGuards();

        return $this->entrarNoPortal($this->empresa, $cliente);
    }

    /** A sessão de equipe no Portal: a passagem de 60 s emitida pelo admin, consumida na porta da equipe. */
    private function entrarComoEquipe(Company $empresa): void
    {
        $membro = User::factory()->create(['role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]))->assertRedirect();
    }

    private function capaDa(EstruturaProdutoVariacao $v): EstruturaProdutoVariacaoImagem
    {
        return EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $v->id)->orderBy('ordem')->orderBy('id')->firstOrFail();
    }

    /** Grava no disco uma imagem de verdade no caminho da linha (o GD gera a miniatura dela). */
    private function comArquivo(EstruturaProdutoVariacaoImagem $img): EstruturaProdutoVariacaoImagem
    {
        $conteudo = 'jpeg-falso-'.Str::random(4);
        if (function_exists('imagecreatetruecolor')) {
            $gd = imagecreatetruecolor(40, 30);
            ob_start();
            imagejpeg($gd);
            $conteudo = (string) ob_get_clean();
        }
        Storage::disk(VariacaoImagensService::DISCO)->put($img->caminho, $conteudo);

        return $img;
    }

    /** As props da página Inertia como o navegador as recebe. */
    private function props(TestResponse $resposta): array
    {
        return json_decode(json_encode($resposta->viewData('page')), true)['props'];
    }

    private function doProduto(TestResponse $resposta, int $id): array
    {
        return $this->doProdutoNasProps($this->props($resposta), $id);
    }

    private function doProdutoNasProps(array $props, int $id): array
    {
        foreach ($props['produtos'] as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        $this->fail("o produto {$id} não está nas props");
    }
}
