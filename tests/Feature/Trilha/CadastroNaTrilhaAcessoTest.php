<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * Quem passa pelas rotas do cadastro na trilha (3ª entrega, 02/11/2026, desenho §6.1) — a matriz de TODAS as rotas da
 * fatia (F1: abrir, criar e gravar as linhas; F2: a ficha técnica e o "Preencher com IA" dela; F3: enviar e excluir
 * foto e o "Conferir frete pela conta"; cresce a cada fatia). A ordem da guarda é o que se prova aqui:
 *
 * 1. as duas chaves (`trilha.empresas` e `trilha.trabalho.empresas`) → 404, antes de ler a empresa;
 * 2. o módulo oculto (só o Dev) → 404; sem login → login;
 * 3. quem não escreve no Portal da empresa → 403;
 * 4. o recurso do ENDEREÇO de outra loja → 404 ANTES de validar o corpo (o corpo inválido vai junto de propósito: se a
 *    validação viesse antes, a resposta seria 422 e diria "o produto existe, só o campo está errado"); a variação do
 *    endereço é DO PRODUTO do caminho, e a imagem, DA VARIAÇÃO (F3);
 * 5. a variação de OUTRO produto da MESMA loja no corpo → 404 (com `id`, o serviço do Portal acha o produto pela
 *    variação e ignoraria o do caminho);
 * 6. id de 19 dígitos → 404, nunca 500.
 *
 * A regra do que cada rota grava está em {@see CadastroNaTrilhaTest}; o custo (o Publicador), em {@see CustoDoCadastroTest}.
 */
class CadastroNaTrilhaAcessoTest extends TestCase
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
        // A ficha (rotas 4 e 9) lê os campos da categoria do produto: o catálogo de mentira, com o app token em cache
        // (depois do relógio, senão o token nasce vencido); e a IA é um dublê — nenhuma chamada real.
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::fake([
            '*/categories/MLB100200/attributes' => Http::response([['id' => 'BRAND', 'name' => 'Marca', 'value_type' => 'string', 'tags' => ['required' => true]]]),
            '*' => Http::response([], 404),
        ]);
        $this->app->instance(AnaliseAnuncioService::class, new class extends AnaliseAnuncioService
        {
            public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo, bool $comNaoSeAplica = false): array
            {
                return ['dados' => ['atributos' => ['BRAND' => 'Marca da IA']], 'meta' => ['modelo' => 'teste']];
            }
        });
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * As rotas da fatia: [método, caminho depois de `/trilha/{empresa}` (com `{p}` = o produto, `{v}` = a 1ª variação
     * dele e `{i}` = a 1ª foto dela), corpo VÁLIDO]. O corpo válido é montado no teste ({@see corpo()}).
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function rotas(): array
    {
        return [
            'abrir (rota 1)'  => ['GET', '/produtos/{p}/cadastro', false],
            'criar (rota 2)'  => ['POST', '/produtos', false],
            'linhas (rota 3)' => ['POST', '/produtos/{p}/linhas', true],
            'ficha (rota 4)'  => ['PUT', '/produtos/{p}/ficha-tecnica', false],
            'IA (rota 9)'     => ['POST', '/produtos/{p}/ficha-tecnica/ia', false],
            'fotos (rota 10)' => ['POST', '/produtos/{p}/variacoes/{v}/fotos', false],
            'excluir foto (rota 11)' => ['DELETE', '/produtos/{p}/variacoes/{v}/fotos/{i}', false],
            'frete (rota 13)' => ['POST', '/produtos/{p}/frete', false],
        ];
    }

    #[DataProvider('rotas')]
    public function test_fora_das_duas_chaves_a_rota_nao_existe(string $metodo, string $caminho, bool $comVariacao): void
    {
        // Na trilha de leitura (1ª entrega), sem a chave de trabalho: o cadastro não existe.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $this->pedir($this->dev, $metodo, $this->url($soLeitura, $caminho, $p), $this->corpo($caminho, $p))->assertNotFound();

        // Fora de `trilha.empresas` (mesmo que alguém a pusesse só na de trabalho): 404 antes de ler a empresa.
        $fora = $this->empresaDaTrilha();
        $q = $this->produtoCompleto($fora, 'Vaso Fora', 'VF');
        config(['trilha.empresas' => array_values(array_diff((array) config('trilha.empresas'), [$fora->id]))]);
        $this->pedir($this->dev, $metodo, $this->url($fora, $caminho, $q), $this->corpo($caminho, $q))->assertNotFound();

        // Empresa que não existe, dentro das chaves: 404 também (o `findOrFail` da guarda).
        config(['trilha.empresas' => [...(array) config('trilha.empresas'), 999999], 'trilha.trabalho.empresas' => [...(array) config('trilha.trabalho.empresas'), 999999]]);
        $this->pedir($this->dev, $metodo, '/trilha/999999'.str_replace(['{p}', '{v}', '{i}'], '1', $caminho), [])->assertNotFound();

        $this->assertNadaGravado();
    }

    #[DataProvider('rotas')]
    public function test_sem_login_vai_para_o_login_e_quem_nao_e_dev_recebe_404(string $metodo, string $caminho, bool $comVariacao): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $url = $this->url($this->empresa, $caminho, $p);
        $corpo = $this->corpo($caminho, $p);

        // Módulo oculto: o admin que não é Dev recebe 404, como se a rota não existisse.
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->pedir($admin, $metodo, $url, $corpo)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->call($metodo, $url, $corpo)->assertRedirect(route('login'));
        $this->json($metodo, $url, $corpo)->assertUnauthorized();

        $this->assertNadaGravado();
    }

    #[DataProvider('rotas')]
    public function test_quem_nao_escreve_no_portal_da_empresa_recebe_403_antes_de_validar(string $metodo, string $caminho, bool $comVariacao): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();

        // Com corpo inválido: o 403 vem antes do 422.
        $this->pedir($semCarteira->refresh(), $metodo, $this->url($this->empresa, $caminho, $p), ['linhas' => 'x', 'nome' => ''])
            ->assertForbidden()
            ->assertJsonPath('message', 'Você não tem acesso ao portal desta empresa.');

        $this->assertNadaGravado();
    }

    #[DataProvider('rotas')]
    public function test_o_produto_de_outra_loja_pelo_endereco_desta_e_404_mesmo_com_corpo_invalido(string $metodo, string $caminho, bool $comVariacao): void
    {
        if (! str_contains($caminho, '{p}')) {
            // A rota de criar não tem recurso no endereço: só a empresa (coberta acima).
            $this->assertTrue(true);

            return;
        }
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VX');
        $antes = $this->retratoDoBanco($deFora['produto']);

        // Corpo inválido de propósito: o 404 vem antes do 422.
        $this->pedir($this->dev, $metodo, $this->url($this->empresa, $caminho, $deFora), ['linhas' => 'x'])->assertNotFound();
        // E com o corpo válido (a variação dele, pelo endereço desta loja): 404 igual, e nada muda lá.
        $this->pedir($this->dev, $metodo, $this->url($this->empresa, $caminho, $deFora), $this->corpo($caminho, $deFora, 'Trocado por fora'))->assertNotFound();

        $this->assertSame($antes, $this->retratoDoBanco($deFora['produto']));
    }

    public function test_a_variacao_de_outro_produto_da_mesma_loja_no_corpo_e_404_e_o_outro_fica_igual(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $antesA = $this->retratoDoBanco($a['produto']);
        $antesB = $this->retratoDoBanco($b['produto']);
        $url = $this->url($this->empresa, '/produtos/{p}/linhas', $a);

        // Só a variação de B, pelo caminho de A.
        $this->pedir($this->dev, 'POST', $url, ['linhas' => [
            ['id' => $b['variacoes'][0]->id, 'codigo' => 'VB', 'nome' => 'Renomeado pelo caminho de A', 'custo' => '1,00'],
        ]])->assertNotFound();
        // Misturada com uma de A, numa requisição só: 404 igual (nada da de A grava).
        $this->pedir($this->dev, 'POST', $url, ['linhas' => [
            ['id' => $a['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa mudado', 'custo' => '2,00'],
            ['id' => (string) $b['variacoes'][0]->id, 'codigo' => 'VB', 'nome' => 'Vaso Beta', 'custo' => '3,00'],
        ]])->assertNotFound();
        // A variação de OUTRA loja, pelo produto desta: 404 igual.
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VX');
        $this->pedir($this->dev, 'POST', $url, ['linhas' => [
            ['id' => $deFora['variacoes'][0]->id, 'codigo' => 'VX', 'nome' => 'Trocado por fora', 'custo' => '4,00'],
        ]])->assertNotFound();
        $this->assertSame('Vaso de Fora', $deFora['produto']->fresh()->nome);
        // O id que o serviço não leria como número (e viraria "variação nova"): 404 também.
        $this->pedir($this->dev, 'POST', $url, ['linhas' => [['id' => '12abc', 'codigo' => 'VA-N', 'nome' => 'Vaso Alfa']]])->assertNotFound();
        $this->pedir($this->dev, 'POST', $url, ['linhas' => [['id' => 1.5, 'codigo' => 'VA-N', 'nome' => 'Vaso Alfa']]])->assertNotFound();

        $this->assertSame($antesA, $this->retratoDoBanco($a['produto']));
        $this->assertSame($antesB, $this->retratoDoBanco($b['produto']));
        $this->assertSame(2, EstruturaProdutoVariacao::query()->where('company_id', $this->empresa->id)->count());
    }

    /**
     * F3: a variação do ENDEREÇO tem de ser do produto do caminho, e a imagem, da variação — senão 404, antes de validar
     * o corpo (vai inválido de propósito) e sem mexer em nada. Com o produto certo e a variação de outro produto da
     * MESMA loja, o serviço gravaria a foto no produto errado.
     */
    public function test_a_variacao_ou_a_imagem_de_outro_produto_ou_loja_no_endereco_e_404_antes_de_validar(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $outra = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outra, 'Vaso de Fora', 'VX');
        $base = "/trilha/{$this->empresa->id}/produtos/{$a['produto']->id}/variacoes";
        $antes = [$this->retratoDoBanco($a['produto']), $this->retratoDoBanco($b['produto']), $this->retratoDoBanco($deFora['produto'])];

        foreach ([$b, $deFora] as $alheio) {
            $alheia = $alheio['variacoes'][0];
            // Enviar: corpo inválido (sem `imagens`) e o válido — 404 nos dois.
            $this->pedir($this->dev, 'POST', "{$base}/{$alheia->id}/fotos", ['imagens' => 'x'])->assertNotFound();
            $this->pedir($this->dev, 'POST', "{$base}/{$alheia->id}/fotos", ['imagens' => [UploadedFile::fake()->image('f.jpg', 600, 600)]])->assertNotFound();
            // Excluir a foto dela pelo produto A.
            $this->pedir($this->dev, 'DELETE', "{$base}/{$alheia->id}/fotos/".$this->fotoDa($alheio)->id, [])->assertNotFound();
        }
        // A foto da Azul pela Verde (o mesmo produto) e a de outra loja pela Azul.
        [$azul, $verde] = $a['variacoes'];
        $this->pedir($this->dev, 'DELETE', "{$base}/{$verde->id}/fotos/".$this->fotoDa($a)->id, [])->assertNotFound();
        $this->pedir($this->dev, 'DELETE', "{$base}/{$azul->id}/fotos/".$this->fotoDa($deFora)->id, [])->assertNotFound();

        $this->assertSame($antes, [$this->retratoDoBanco($a['produto']), $this->retratoDoBanco($b['produto']), $this->retratoDoBanco($deFora['produto'])]);
        $this->assertNadaGravado();
    }

    public function test_id_de_19_digitos_e_404_nunca_500(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $grande = '1234567890123456789';

        $this->getJson("/trilha/{$grande}/produtos/{$p['produto']->id}/cadastro")->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$grande}/produtos/{$p['produto']->id}/cadastro")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$grande}/produtos", ['nome' => 'X', 'codigo' => 'X'])->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$grande}/cadastro")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$grande}/linhas", ['linhas' => [['id' => 1, 'codigo' => 'X', 'nome' => 'X']]])->assertNotFound();
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/produtos/{$grande}/ficha-tecnica", ['atributos' => []])->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$grande}/ficha-tecnica/ia", ['atributos' => []])->assertNotFound();
        // F3: o produto, a variação e a imagem do endereço.
        $v = $p['variacoes'][0]->id;
        $i = $this->fotoDa($p)->id;
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$grande}/variacoes/{$v}/fotos", [])->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/variacoes/{$grande}/fotos", [])->assertNotFound();
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/variacoes/{$grande}/fotos/{$i}")->assertNotFound();
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/variacoes/{$v}/fotos/{$grande}")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$grande}/frete")->assertNotFound();
        // O id de 19 dígitos DENTRO do corpo: o serviço não o leria como número — 404, nunca variação nova.
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/linhas",
            ['linhas' => [['id' => $grande, 'codigo' => 'VA', 'nome' => 'Vaso Alfa']]])->assertNotFound();

        $this->assertSame(1, EstruturaProduto::query()->count());
        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->count());
    }

    /** O que o Dev com a empresa ainda recebe pela mesma matriz: a rota existe e responde. */
    #[DataProvider('rotas')]
    public function test_o_dev_que_escreve_no_portal_da_empresa_passa(string $metodo, string $caminho, bool $comVariacao): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $corpo = $metodo === 'POST' && ! str_contains($caminho, '{p}') ? ['nome' => 'Vaso Novo', 'codigo' => 'VN-1'] : $this->corpo($caminho, $p);

        $resposta = $this->pedir($this->dev, $metodo, $this->url($this->empresa, $caminho, $p), $corpo);

        $this->assertContains($resposta->status(), [200, 201], (string) $resposta->getContent());
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
    }

    // ─── apoio ───

    /** @param  array{produto: EstruturaProduto, variacoes: list<EstruturaProdutoVariacao>}  $p */
    private function url(Company $empresa, string $caminho, array $p): string
    {
        return "/trilha/{$empresa->id}".str_replace(
            ['{p}', '{v}', '{i}'],
            [(string) $p['produto']->id, (string) $p['variacoes'][0]->id, (string) $this->fotoDa($p)->id],
            $caminho,
        );
    }

    /** A 1ª foto da 1ª variação do produto (o `produtoCompleto` grava 3 linhas, sem arquivo). */
    private function fotoDa(array $p): EstruturaProdutoVariacaoImagem
    {
        return EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][0]->id)->orderBy('ordem')->orderBy('id')->firstOrFail();
    }

    /** O corpo VÁLIDO de cada rota (a 3 leva a variação do produto). */
    private function corpo(string $caminho, array $p, string $nome = 'Vaso Alfa'): array
    {
        return match (true) {
            str_ends_with($caminho, '/linhas')        => ['linhas' => [['id' => $p['variacoes'][0]->id, 'codigo' => $p['variacoes'][0]->codigo, 'nome' => $nome, 'custo' => '9,99']]],
            $caminho === '/produtos'                  => ['nome' => 'Vaso Novo', 'codigo' => 'VN-'.uniqid()],
            str_ends_with($caminho, '/ficha-tecnica') => ['atributos' => [['id' => 'BRAND', 'valor' => $nome]]],
            str_ends_with($caminho, '/ia')            => ['categoria' => 'MLB100200', 'titulo' => $nome, 'atributos' => []],
            // Multipart: o `json()` do teste tira os arquivos do corpo e os manda como upload.
            str_ends_with($caminho, '/fotos')         => ['imagens' => [UploadedFile::fake()->image('foto.jpg', 600, 600)]],
            default                                   => [],
        };
    }

    private function pedir(User $quem, string $metodo, string $url, array $corpo): TestResponse
    {
        return $this->actingAs($quem)->json($metodo, $url, $corpo);
    }

    /** O produto como está no banco: os campos que a trilha poderia mudar. */
    private function retratoDoBanco(EstruturaProduto $produto): array
    {
        $p = EstruturaProduto::query()->with('variacoes')->findOrFail($produto->id);

        return [
            'nome'      => $p->nome,
            'titulo'    => $p->titulo,
            'descricao' => $p->descricao,
            'variacoes' => $p->variacoes->map(fn ($v) => [$v->id, $v->codigo, $v->custo, $v->estoque])->all(),
            'ficha'     => EstruturaProdutoAtributo::query()->where('produto_id', $p->id)->orderBy('atributo_id')->pluck('valor', 'atributo_id')->all(),
            'fotos'     => EstruturaProdutoVariacaoImagem::query()->where('produto_id', $p->id)->orderBy('id')->get(['id', 'variacao_id', 'ordem'])->toArray(),
        ];
    }

    /** Nenhum produto novo, nenhuma linha mudada, nenhuma foto enviada nem excluída, nada no ML, nenhum Job. */
    private function assertNadaGravado(): void
    {
        $this->assertSame(0, EstruturaProduto::query()->where('nome', 'Vaso Novo')->count(), 'criou produto');
        $this->assertSame(0, EstruturaProdutoVariacao::query()->where('custo', 9.99)->count(), 'gravou a linha');
        $this->assertSame(0, EstruturaProdutoAtributo::query()->count(), 'gravou a ficha');
        // O `produtoCompleto` grava 3 fotos por variação: nenhuma a mais (enviada), nenhuma a menos (excluída).
        $this->assertSame(3 * EstruturaProdutoVariacao::query()->count(), EstruturaProdutoVariacaoImagem::query()->count(), 'mexeu nas fotos');
        $this->assertSame([], Storage::disk(VariacaoImagensService::DISCO)->allFiles(), 'gravou foto no disco');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
