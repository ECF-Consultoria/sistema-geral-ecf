<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Jobs\Trilha\PublicarPelaTrilhaJob;
use App\Models\MlAcervoItem;
use App\Models\MlCategoriaSchema;
use App\Models\MlToken;
use App\Models\PubImagem;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaAprovacao;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Ia\AnaliseAnuncioService;
use App\Services\Publicador\RascunhoRepository;
use App\Services\Trilha\PublicacaoNaTrilha;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\Variacao\ChaveCanonica;
use App\Support\Publicador\Variacao\Variante;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Support\Publicador\Imagem\ArquivosDeImagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * Quem passa pelas rotas do cadastro na trilha (3ª entrega, 02/11/2026, desenho §6.1) — a matriz de TODAS as rotas da
 * fatia (F1: abrir, criar e gravar as linhas; F2: a ficha técnica e o "Preencher com IA" dela; F3: enviar e excluir
 * foto e o "Conferir frete pela conta"; F4: excluir a cor e o produto, em dois passos; F7: baixar a planilha por
 * categoria, a prévia e o gravar dela; F8: a lista dos anúncios do ML, o importar, o estado e o limpar; 4a: a prévia do
 * anúncio e a foto dela; 4c: o preço ajustável; 4d: o "Aprovar e publicar" e o acompanhamento; 4b: o "Pedir ajuste ao
 * agente" e o estado dele; 4e: o "Mandar ao cliente" — as rotas DO CLIENTE da 4e têm a matriz delas no
 * `AprovacaoDoClienteNaTrilhaTest`, pelo `portal.auth`; cresce a cada fatia). A
 * empresa dos testes é a 459 (`PublicacaoNaTrilha::LOJA`): a 4d só passa nela — o 403 da outra loja está no
 * `PublicarNaTrilhaTest`. A ordem da guarda é o que se prova aqui:
 *
 * 1. as duas chaves (`trilha.empresas` e `trilha.trabalho.empresas`) → 404, antes de ler a empresa;
 * 2. o módulo oculto (só o Dev) → 404; sem login → login;
 * 3. quem não escreve no Portal da empresa → 403;
 * 4. o recurso do ENDEREÇO de outra loja → 404 ANTES de validar o corpo (o corpo inválido vai junto de propósito: se a
 *    validação viesse antes, a resposta seria 422 e diria "o produto existe, só o campo está errado"); a variação do
 *    endereço é DO PRODUTO do caminho, e a imagem, DA VARIAÇÃO (F3); o item da prévia (4a) é da régua DESTA loja;
 * 5. a variação de OUTRO produto da MESMA loja no corpo → 404 (com `id`, o serviço do Portal acha o produto pela
 *    variação e ignoraria o do caminho);
 * 6. id de 19 dígitos → 404, nunca 500.
 *
 * A regra do que cada rota grava está em {@see CadastroNaTrilhaTest}; o custo (o Publicador), em {@see CustoDoCadastroTest}.
 */
class CadastroNaTrilhaAcessoTest extends TestCase
{
    use CarregaSchemas;
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    /** @var list<string> as planilhas mínimas gravadas no disco (F7) */
    private array $temporarios = [];

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
            // F7: a planilha lê a categoria (folha) para baixar e para conferir a volta.
            '*/categories/MLB100200' => Http::response(['id' => 'MLB100200', 'name' => 'Objetos de decoração', 'children_categories' => [],
                'path_from_root' => [['id' => 'MLB100200', 'name' => 'Objetos de decoração']]]),
            '*' => Http::response([], 404),
        ]);
        $this->app->instance(AnaliseAnuncioService::class, new class extends AnaliseAnuncioService
        {
            public function ficha(string $produto, string $specs, string $titulo, string $caminhoCategoria, string $catalogo, bool $comNaoSeAplica = false): array
            {
                return ['dados' => ['atributos' => ['BRAND' => 'Marca da IA']], 'meta' => ['modelo' => 'teste']];
            }
        });
        // A 459: a única loja em que a 4d publica (as outras rotas não olham o id).
        $this->empresa = $this->empresaDaTrilha(id: PublicacaoNaTrilha::LOJA);
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
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
            // R6 (04/11/2026): a ordem das fotos da cor.
            'ordem das fotos (R6)'   => ['PUT', '/produtos/{p}/variacoes/{v}/fotos/ordem', false],
            'frete (rota 13)' => ['POST', '/produtos/{p}/frete', false],
            'excluir cor (rota 14)'      => ['DELETE', '/produtos/{p}/variacoes/{v}', false],
            'prévia da exclusão (rota 15)' => ['POST', '/produtos/{p}/exclusao/previa', false],
            'excluir produto (rota 15)'  => ['DELETE', '/produtos/{p}', false],
            // F7: a planilha por categoria (sem recurso no endereço: o produto vem do arquivo).
            'planilha: baixar (F7)'  => ['GET', '/planilha/modelo?categorias[]=MLB100200', false],
            'planilha: prévia (F7)'  => ['POST', '/planilha/previa', false],
            'planilha: gravar (F7)'  => ['POST', '/planilha', false],
            // F8: importar do Mercado Livre (sem recurso no endereço: o anúncio vem no corpo e o resto do acervo).
            'ML: anúncios (F8)'      => ['GET', '/mercado-livre/anuncios', false],
            'ML: importar (F8)'      => ['POST', '/mercado-livre/importar', false],
            'ML: estado (F8)'        => ['GET', '/mercado-livre/estado', false],
            'ML: limpar (F8)'        => ['POST', '/mercado-livre/limpar', false],
            // 4a: a prévia do anúncio do item (a Simples do produto, `s-{p}`) e a foto do rascunho dela (`{pi}`).
            'prévia (4a)'            => ['GET', '/ofertas/s-{p}/previa', false],
            'foto da prévia (4a)'    => ['GET', '/ofertas/s-{p}/fotos/{pi}', false],
            // 4c: o preço ajustável da prévia (grava só no rascunho do anúncio).
            'preço (4c)'             => ['PUT', '/ofertas/s-{p}/preco', false],
            // 4d: aprovar e publicar (põe na fila de publicação — o Job fica na fila de mentira) e o acompanhamento.
            'publicar (4d)'          => ['POST', '/ofertas/s-{p}/publicar', false],
            'acompanhar (4d)'        => ['GET', '/ofertas/s-{p}/publicacao', false],
            // 4b: pedir ajuste ao agente (o Job fica na fila de mentira — nenhuma IA) e o estado dele, no mesmo endereço.
            'ajuste (4b)'            => ['POST', '/ofertas/s-{p}/ajuste', false],
            'estado do ajuste (4b)'  => ['GET', '/ofertas/s-{p}/ajuste', false],
            // 4e: mandar a prévia ao cliente (com a chave da aprovação ligada no teste que passa; sem ela, 409).
            'mandar ao cliente (4e)' => ['POST', '/ofertas/s-{p}/cliente', false],
            // R4 (04/11/2026): o [Tentar de novo] da preparação (o Job fica na fila de mentira — nenhuma IA).
            'tentar de novo (R4)'    => ['POST', '/ofertas/s-{p}/preparo', false],
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
        $this->pedir($this->dev, $metodo, '/trilha/999999'.str_replace(['{p}', '{v}', '{i}', '{pi}'], '1', $caminho), [])->assertNotFound();

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
            // As rotas de criar e da planilha não têm recurso no endereço: só a empresa (coberta acima).
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
        // F4: excluir a cor (o produto e a variação do endereço) e o produto, nos dois passos.
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$grande}/variacoes/{$v}")->assertNotFound();
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/variacoes/{$grande}")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$grande}/exclusao/previa")->assertNotFound();
        $this->actingAs($this->dev)->deleteJson("/trilha/{$this->empresa->id}/produtos/{$grande}", ['montadas' => []])->assertNotFound();
        // O id de 19 dígitos DENTRO do corpo: o serviço não o leria como número — 404, nunca variação nova.
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos/{$p['produto']->id}/linhas",
            ['linhas' => [['id' => $grande, 'codigo' => 'VA', 'nome' => 'Vaso Alfa']]])->assertNotFound();
        // 4a: o item e a foto da prévia.
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/s-{$grande}/previa")->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/c-{$p['produto']->id}-1234/previa")->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/s-{$p['produto']->id}/fotos/{$grande}")->assertNotFound();
        // 4c: o item do preço.
        $this->actingAs($this->dev)->putJson("/trilha/{$this->empresa->id}/ofertas/s-{$grande}/preco", ['revisao' => 1, 'precos' => ['classico' => 1]])->assertNotFound();
        // 4d: o item do publicar e do acompanhamento, e a empresa.
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/s-{$grande}/publicar", ['revisao' => 1])->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/o-{$grande}/publicacao")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$grande}/ofertas/s-{$p['produto']->id}/publicar", ['revisao' => 1])->assertNotFound();
        // 4b: o item do ajuste (POST e GET) e a empresa.
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/s-{$grande}/ajuste", ['revisao' => 1, 'refazer' => ['titulo']])->assertNotFound();
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/c-{$p['produto']->id}-1234/ajuste")->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$grande}/ofertas/s-{$p['produto']->id}/ajuste", ['revisao' => 1, 'refazer' => ['titulo']])->assertNotFound();
        // 4e: o item e a empresa do "Mandar ao cliente".
        $this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/ofertas/s-{$grande}/cliente", ['revisao' => 1])->assertNotFound();
        $this->actingAs($this->dev)->postJson("/trilha/{$grande}/ofertas/s-{$p['produto']->id}/cliente", ['revisao' => 1])->assertNotFound();
        Queue::assertNothingPushed();

        $this->assertSame(1, EstruturaProduto::query()->count());
        $this->assertSame(1, EstruturaProdutoVariacao::query()->count());
        $this->assertSame(3, EstruturaProdutoVariacaoImagem::query()->count());
    }

    /** O que o Dev com a empresa ainda recebe pela mesma matriz: a rota existe e responde. */
    #[DataProvider('rotas')]
    public function test_o_dev_que_escreve_no_portal_da_empresa_passa(string $metodo, string $caminho, bool $comVariacao): void
    {
        // Excluir a cor pede um produto de duas (a última variação só sai pelo "Excluir produto").
        $cores = $caminho === '/produtos/{p}/variacoes/{v}' ? ['Azul', 'Verde'] : [null];
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', $cores);
        if (str_starts_with($caminho, '/ofertas/')) {
            // 4a: a Simples no Publicador, com rascunho e uma foto guardada (a prévia diz "não pronta": o agente não mexeu).
            $this->fotoNoRascunho($p);
        }
        $ajustePedido = $metodo === 'POST' && str_ends_with($caminho, '/ajuste');
        $mandar = str_ends_with($caminho, '/cliente');
        if (str_ends_with($caminho, '/preco') || str_ends_with($caminho, '/publicar') || $ajustePedido || $mandar) {
            // 4c: o preço só muda na revisão — o agente terminou o texto e o rascunho tem os dois tipos e a variante.
            $this->naRevisao($p);
        }
        if (str_ends_with($caminho, '/publicar') || $ajustePedido || $mandar) {
            // 4d: e só se publica com a prévia pronta — a categoria com o schema guardado (nenhuma leitura do ML).
            $this->comCategoria($p);
        }
        if ($mandar) {
            // 4e: a loja aprova com o cliente.
            config(['trilha.aprovacao_cliente.empresas' => [$this->empresa->id]]);
        }
        if ($ajustePedido) {
            // 4b: o texto é do agente (o preparo dele registrado, com o hash dos fatos).
            $r = PubRascunho::query()->whereIn('produto_id', PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->firstOrFail();
            $r->update(['step_state' => array_replace_recursive((array) $r->step_state, ['ia_preparo' => ['hash' => 'fatos-do-preparo']])]);
        }
        $tentarDeNovo = str_ends_with($caminho, '/preparo');
        if ($tentarDeNovo) {
            // R4: o título do agente parou (sem crédito) — o item fica no agente com a `parada`.
            $r = PubRascunho::query()->whereIn('produto_id', PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->firstOrFail();
            $r->update(['step_state' => array_replace_recursive((array) $r->step_state, ['ia_preparo' => ['hash' => 'fatos-do-preparo',
                'etapas' => ['titulo' => ['status' => 'erro', 'motivo' => 'sem_credito', 'em' => now()->toIso8601String()]]]])]);
        }
        // Depois da montagem: o corpo do preço leva a revisão do rascunho.
        $corpo = $caminho === '/produtos' ? ['nome' => 'Vaso Novo', 'codigo' => 'VN-1'] : $this->corpo($caminho, $p);
        if (str_starts_with($caminho, '/mercado-livre/')) {
            // F8: a conta conectada e o anúncio no acervo da loja (o Job fica na fila de mentira: nada chama o ML).
            MlToken::create(['company_id' => $this->empresa->id, 'ml_user_id' => '999', 'access_token' => 'APP_USR-x',
                'refresh_token' => 'TG-x', 'expires_at' => now()->addHours(5), 'status' => 'active']);
            MlAcervoItem::create(['company_id' => $this->empresa->id, 'ml_item_id' => 'MLB900', 'title' => 'Vaso no ar', 'status' => 'active', 'coletado_em' => now()]);
        }

        $resposta = $this->pedir($this->dev, $metodo, $this->url($this->empresa, $caminho, $p), $corpo);

        // `getStatusCode()`: o download do modelo (F7) é um StreamedResponse, sem o `status()` do Illuminate.
        $this->assertContains($resposta->getStatusCode(), [200, 201, 202], (string) $resposta->getContent());
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        if ($caminho === '/mercado-livre/importar') {
            Queue::assertPushedOn(\App\Services\Publicador\PreparoIaAgenda::fila(), \App\Jobs\Trilha\ImportarAnuncioNaTrilhaJob::class);
        }
        if (str_ends_with($caminho, '/publicar')) {
            // 4d: 202 e o Job na fila `high` — nada mais (quem publica é a fila do Publicador, depois da conferência).
            $this->assertSame(202, $resposta->getStatusCode());
            $this->assertSame('conferindo', $resposta->json('publicacao.situacao'));
            Queue::assertPushedOn('high', PublicarPelaTrilhaJob::class);
        }
        if (str_ends_with($caminho, '/publicacao')) {
            $this->assertSame(['publicacao' => null], $resposta->json(), 'nunca pediu');
        }
        if ($ajustePedido) {
            // 4b: 202 e o Job na fila da IA (nada roda: a fila é de mentira).
            $this->assertSame([202, 'fila'], [$resposta->getStatusCode(), $resposta->json('ajuste.situacao')]);
            Queue::assertPushedOn(\App\Services\Publicador\PreparoIaAgenda::fila(), \App\Jobs\Trilha\AjustarPeloRoboNaTrilhaJob::class);
        } elseif (str_ends_with($caminho, '/ajuste')) {
            $this->assertSame(['ajuste' => null], $resposta->json(), 'nunca pediu');
        }
        if ($tentarDeNovo) {
            // R4: 202 e o Job na fila da IA (nada roda: a fila é de mentira).
            $this->assertSame([202, 'fila'], [$resposta->getStatusCode(), $resposta->json('preparo.situacao')]);
            Queue::assertPushedOn(\App\Services\Publicador\PreparoIaAgenda::fila(), \App\Jobs\Trilha\TentarPreparoDeNovoJob::class);
        }
        if ($mandar) {
            // 4e: 200 com a prévia nova, a linha `com_cliente` e nada na fila (quem publica é a aprovação do cliente).
            $this->assertSame([200, 'com_cliente'], [$resposta->getStatusCode(), $resposta->json('aprovacao.estado')]);
            $this->assertSame(1, TrilhaAprovacao::query()->count());
            Queue::assertNothingPushed();
        }
        if (str_ends_with($caminho, '/preco')) {
            $this->assertSame(['gold_special' => '199.90', 'gold_pro' => null], DB::table('pub_variante_precos as pr')
                ->join('pub_rascunho_alvos as a', 'a.id', '=', 'pr.alvo_id')->orderBy('a.posicao')->pluck('pr.preco', 'a.listing_type_id')
                ->map(fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', ''))->all());
        }
    }

    // ─── apoio ───

    /** @param  array{produto: EstruturaProduto, variacoes: list<EstruturaProdutoVariacao>}  $p */
    private function url(Company $empresa, string $caminho, array $p): string
    {
        return "/trilha/{$empresa->id}".str_replace(
            ['{p}', '{v}', '{i}', '{pi}'],
            [(string) $p['produto']->id, (string) $p['variacoes'][0]->id, (string) $this->fotoDa($p)->id, (string) ($this->fotoDaPrevia($p)?->id ?? 1)],
            $caminho,
        );
    }

    /** 4a: a foto do rascunho da Simples do produto no Publicador (nula enquanto ela não está lá). */
    private function fotoDaPrevia(array $p): ?PubImagem
    {
        $pub = PubProduto::query()->where('estrutura_produto_id', $p['produto']->id)->value('id');

        return $pub === null ? null : PubImagem::query()->whereIn('rascunho_id', PubRascunho::query()->select('id')->where('produto_id', $pub))->orderBy('id')->first();
    }

    /** 4a: a Simples do produto no Publicador, com o rascunho e uma foto guardada no disco das imagens. */
    private function fotoNoRascunho(array $p): PubImagem
    {
        $r = $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p));
        ArquivosDeImagem::gravar("publicador/{$r->id}/capa.jpg", UploadedFile::fake()->image('capa.jpg', 600, 600)->get());

        return $r->imagens()->create(['caminho' => "publicador/{$r->id}/capa.jpg", 'sha256' => str_repeat('c', 64), 'mime' => 'image/jpeg',
            'bytes' => 100, 'upload_status' => PubImagem::PENDENTE]);
    }

    /**
     * 4c: a Simples do produto na revisão (o rascunho da 4a, com o texto do agente pronto, os dois tipos ligados e a variante
     * única) — o preço só muda aqui.
     */
    private function naRevisao(array $p): void
    {
        $pub = PubProduto::query()->where('estrutura_produto_id', $p['produto']->id)->firstOrFail();
        $r = PubRascunho::query()->where('produto_id', $pub->id)->firstOrFail();
        $ok = ['status' => 'ok'];
        $r->update(['step_state' => ['ia_preparo' => ['etapas' => ['titulo' => $ok, 'modelo' => $ok, 'descricao' => $ok]]]]);
        $repo = app(RascunhoRepository::class);
        $repo->gravarAlvos($r, [new Alvo('gold_special', null, true), new Alvo('gold_pro', null, true)]);
        $repo->gravarVariacao($r, [], [new Variante(ChaveCanonica::UNICA, [])]);
        $this->assertSame('revisao', TrilhaDaEmpresa::itens($this->empresa, comoNaTela: true)["s-{$p['produto']->id}"]['etapa'] ?? null, 'controle: na revisão');
    }

    /** 4d: o rascunho da Simples com a categoria (a cadeira, com o schema guardado — nenhuma leitura do ML): a prévia fica pronta. */
    private function comCategoria(array $p): void
    {
        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);
        $pub = PubProduto::query()->where('estrutura_produto_id', $p['produto']->id)->firstOrFail();
        app(RascunhoRepository::class)->gravarCategoria(PubRascunho::query()->where('produto_id', $pub->id)->firstOrFail(), $schema);
        $this->assertTrue($this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/ofertas/s-{$p['produto']->id}/previa")->assertOk()->json('pronta'),
            'controle: a prévia está pronta');
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
            // R6: todas as fotos da 1ª variação, de trás para a frente.
            str_ends_with($caminho, '/fotos/ordem')   => ['ordem' => EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][0]->id)
                ->orderByDesc('ordem')->orderByDesc('id')->pluck('id')->map(fn ($id) => (int) $id)->all()],
            // F7: a planilha da trilha mínima (a marca, a aba da categoria e uma linha), em multipart.
            str_starts_with($caminho, '/planilha/previa'), $caminho === '/planilha' => ['arquivo' => $this->planilhaMinima()],
            // F8: o anúncio do acervo (montado no teste que passa).
            $caminho === '/mercado-livre/importar'    => ['mlbs' => ['MLB900']],
            // 4c: a revisão do rascunho da Simples (1 enquanto ele não existe) e o Clássico.
            str_ends_with($caminho, '/preco')         => ['revisao' => (int) (PubRascunho::query()->whereIn('produto_id',
                PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->value('revisao') ?? 1),
                'precos' => ['classico' => 199.9], 'cor' => null],
            // 4d: a revisão do rascunho da Simples (1 enquanto ele não existe).
            str_ends_with($caminho, '/publicar')      => ['revisao' => (int) (PubRascunho::query()->whereIn('produto_id',
                PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->value('revisao') ?? 1), 'ciente' => false],
            // 4b: a revisão do rascunho da Simples (1 enquanto ele não existe) e o título.
            str_ends_with($caminho, '/ajuste')        => ['revisao' => (int) (PubRascunho::query()->whereIn('produto_id',
                PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->value('revisao') ?? 1), 'refazer' => ['titulo']],
            // 4e: a revisão do rascunho da Simples (1 enquanto ele não existe).
            str_ends_with($caminho, '/cliente')       => ['revisao' => (int) (PubRascunho::query()->whereIn('produto_id',
                PubProduto::query()->select('id')->where('estrutura_produto_id', $p['produto']->id))->value('revisao') ?? 1)],
            default                                   => [],
        };
    }

    /** O arquivo da planilha da trilha com uma linha: a aba da categoria, o cabeçalho na linha 2 e a marca na aba oculta. */
    private function planilhaMinima(): UploadedFile
    {
        $planilha = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $aba = $planilha->getActiveSheet()->setTitle('Objetos de decoração');
        foreach (['Produto (grupo)', 'Ref', 'Nome do produto'] as $i => $titulo) {
            $aba->setCellValue([$i + 1, 2], $titulo);
        }
        $aba->fromArray([['PL-'.uniqid(), 'PL-'.uniqid(), 'Vaso da Planilha']], null, 'A4');
        $oculta = $planilha->createSheet()->setTitle('Categorias');
        $oculta->fromArray([['trilha-planilha-v1'], ['Objetos de decoração', '100200', 'Objetos de decoração']], null, 'A1');
        $caminho = tempnam(sys_get_temp_dir(), 'pl').'.xlsx';
        $this->temporarios[] = $caminho;
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);

        return new UploadedFile($caminho, 'planilha.xlsx', null, null, true);
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
        // F4: nenhuma cor nem produto excluído (e nenhuma escrita da trilha registrada).
        $this->assertFalse(Activity::query()->get()->contains(fn (Activity $a) => in_array($a->getExtraProperty('evento'),
            ['variacao_excluida', 'produtos_excluidos', 'produto_excluido', 'trilha_cadastro_salvo', 'trilha_preco_ajustado', 'trilha_publicacao_pedida',
                'trilha_ajuste_pedido', 'trilha_aprovacao_enviada'], true)), 'excluiu ou gravou');
        // 4e: nenhuma prévia mandada ao cliente.
        $this->assertSame(0, TrilhaAprovacao::query()->count(), 'mandou ao cliente');
        // 4c: nenhum preço gravado no rascunho.
        $this->assertSame(0, DB::table('pub_variante_precos')->whereNotNull('preco')->count(), 'gravou preço');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
