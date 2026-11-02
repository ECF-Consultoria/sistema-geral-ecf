<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A foto da cor no painel "Mapear" da porta da equipe (02/11/2026, achado no teste em produção: as fotos apareciam
 * quebradas). A rota de imagem do Portal, gerada fora de uma visita ao Portal, saía `/portal/0/…` e, no domínio do
 * admin, levava ao domínio do cliente (sem sessão da equipe). Agora o painel usa a rota da trilha, com a mesma régua do
 * Mapeamento: as duas chaves (404), a escrita no Portal da empresa (403) e a imagem da variação e da empresa do
 * endereço (404).
 */
class FotosNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_o_mapear_entrega_a_foto_pela_rota_da_trilha_e_ela_abre_para_a_equipe(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $capa = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));

        $opcoes = $this->actingAs($this->dev)
            ->getJson("/trilha/{$this->empresa->id}/produtos/{$a['produto']->id}/mapeamento")
            ->assertOk()->json();

        $esperada = "/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][0]->id}/{$capa->id}";
        $this->assertSame($esperada, $opcoes['produto']['foto']);
        $this->assertSame($esperada, $opcoes['produto']['cores'][0]['foto']);
        foreach ($opcoes['produto']['cores'] as $cor) {
            $this->assertStringNotContainsString('/portal', (string) $cor['foto'], 'nunca a rota do Portal na porta da equipe');
        }

        $resposta = $this->actingAs($this->dev)->get($esperada)->assertOk();
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('image/', (string) $resposta->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_a_foto_de_outra_variacao_ou_de_outra_loja_pelo_endereco_desta_e_404(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $capaAzul = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));
        $outra = $this->empresaDaTrilha();
        $b = $this->produtoCompleto($outra, 'Vaso Beta', 'VB');
        $deFora = $this->comArquivo($this->capaDa($b['variacoes'][0]->id));

        // A imagem da cor Azul pelo número da cor Verde.
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][1]->id}/{$capaAzul->id}")->assertNotFound();
        // A imagem de outra loja pelo endereço desta (com a variação certa dela).
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$b['variacoes'][0]->id}/{$deFora->id}")->assertNotFound();
        // Linha sem arquivo: 404, nunca 500.
        $semArquivo = $this->capaDa($a['variacoes'][1]->id);
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][1]->id}/{$semArquivo->id}")->assertNotFound();
        // Id de 19 dígitos: 404, nunca 500.
        $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}/fotos/1234567890123456789/1")->assertNotFound();
    }

    public function test_sem_a_chave_sem_acesso_ao_portal_ou_sem_login_a_foto_nao_sai(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $capa = $this->comArquivo($this->capaDa($a['variacoes'][0]->id));
        $url = "/trilha/{$this->empresa->id}/fotos/{$a['variacoes'][0]->id}/{$capa->id}";

        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $this->actingAs($semCarteira->refresh())->get($url)->assertForbidden();

        // Na trilha, mas sem a chave de trabalho: o Mapear não existe, a foto também não.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $capaLeitura = $this->comArquivo($this->capaDa($p['variacoes'][0]->id));
        $this->actingAs($this->dev)->get("/trilha/{$soLeitura->id}/fotos/{$p['variacoes'][0]->id}/{$capaLeitura->id}")->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect();
    }

    // ─── apoio ───

    private function capaDa(int $variacaoId): EstruturaProdutoVariacaoImagem
    {
        return EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $variacaoId)->orderBy('ordem')->orderBy('id')->firstOrFail();
    }

    /** Grava no disco uma imagem de verdade no caminho da linha (o GD gera a miniatura dela). */
    private function comArquivo(EstruturaProdutoVariacaoImagem $img): EstruturaProdutoVariacaoImagem
    {
        $conteudo = 'jpeg-falso';
        if (function_exists('imagecreatetruecolor')) {
            $gd = imagecreatetruecolor(40, 30);
            ob_start();
            imagejpeg($gd);
            $conteudo = (string) ob_get_clean();
        }
        Storage::disk(VariacaoImagensService::DISCO)->put($img->caminho, $conteudo);

        return $img;
    }
}
