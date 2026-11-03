<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVariacaoImagem;
use App\Models\PubProduto;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\EcfDriveService;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A fatia F1 do cadastro na trilha (3ª entrega) DE PONTA A PONTA, no nível HTTP, com o que a TELA manda de verdade —
 * a conferência da integração entre o servidor e a tela, feitos em paralelo:
 *
 * 1. a página da equipe entrega `escrita.produtos`, e a tela monta os endereços a partir dele (`lib/trilhaEscrita.js`:
 *    `{produtos}` cria, `{produtos}/{id}/cadastro` abre, `{produtos}/{id}/linhas` grava) — aqui os mesmos;
 * 2. criar (rota 2) → o 201 já é o retrato da rota 1 (a gaveta troca para `e:{id}` sem outro GET);
 * 3. o "Salvar" com o corpo que `linhasParaSalvar` (`lib/trilhaCadastro.js`) monta sobre ESSE retrato — o mesmo
 *    literal está em `tests/js/trilha-cadastro.test.js` ("o corpo do Salvar é o que o servidor lê"): custo e medidas
 *    em texto brasileiro, a caixa em branco fora, ambientes em lista, a descrição com `\n`, `codigo` e `nome` sempre;
 * 4. `''` (não mexi) não apaga; o `null` que a tela manda ao esvaziar um campo limpa;
 * 5. depois de cada passo, o produto está na Lista da trilha (`TrilhaDaEmpresa::montar`) com as MESMAS faltas do
 *    retrato, na tela de Produtos do Portal (`ProdutoLinhas`) com a oferta simples, e NADA vai ao Publicador enquanto
 *    ele estiver incompleto (pergunta 2); o save que o completa o leva, uma vez.
 */
class CadastroDePontaAPontaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    /** As faltas do produto que acabou de nascer só com nome e SKU (a régua da Lista, na ordem dela). */
    private const FALTAS_AO_NASCER = ['medidas', 'custo', 'categoria', 'título', 'família', 'ambiente', 'descrição', 'fotos (0 de 3)'];

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // O badge de alertas do menu (props compartilhadas da página) pergunta ao ECF Drive: dublê.
        $this->mock(EcfDriveService::class, fn (MockInterface $m) => $m->shouldReceive('listSignals')->andReturn(['total' => 0]));
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake(VariacaoImagensService::DISCO);
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        // O preparo ligado aqui (o `.env` do worktree o desliga: "nada foi ao Publicador" passaria por engano).
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

    public function test_criar_abrir_e_salvar_com_o_corpo_da_tela_sem_ir_ao_publicador(): void
    {
        // ─── 1. Os endereços: os da página, como a tela os monta ───
        $produtos = $this->escritaDaPagina()['produtos'];
        $this->assertSame(url("/trilha/{$this->empresa->id}/produtos"), $produtos);

        // ─── 2. Criar ───
        $criado = $this->semPortal($this->actingAs($this->dev)->postJson($produtos, ['nome' => 'Poltrona Ripada', 'codigo' => 'POLT-RIP']))
            ->assertCreated()->json();
        $id = (int) $criado['produto']['id'];
        $aberto = $this->semPortal($this->actingAs($this->dev)->getJson("{$produtos}/{$id}/cadastro"))->assertOk()->json();

        $this->assertSame($aberto, array_diff_key($criado, array_flip(['absorvidos_da_espera', 'avisos', 'mensagem'])),
            'o 201 do criar É o retrato da rota 1: a gaveta troca para `e:{id}` sem outro GET');
        $this->assertSame(['Produto criado.', 0, []], [$criado['mensagem'], $criado['absorvidos_da_espera'], $criado['avisos']]);
        $this->assertSame(['lista', self::FALTAS_AO_NASCER], [$aberto['situacao']['etapa'], $aberto['situacao']['faltas']]);
        $this->assertSame(['livre' => true, 'motivo' => null, 'onda_comecou' => null], $aberto['edicao']);
        $variacaoId = (int) $aberto['variacoes'][0]['id'];
        $this->assertSame(['POLT-RIP', null, null, [], false, false], [$aberto['variacoes'][0]['codigo'], $aberto['variacoes'][0]['custo'],
            $aberto['variacoes'][0]['estoque'], $aberto['variacoes'][0]['volumes'], $aberto['variacoes'][0]['bling'], $aberto['variacoes'][0]['estoque_do_bling']]);
        $this->assertNaListaENoPortal($id, $aberto, 'Poltrona Ripada');
        $this->assertNadaNoPublicador();

        // ─── 3. O "Salvar" com o corpo que a tela monta ───
        $salvo = $this->linhas($produtos, $id, [[
            'chave' => "v{$variacaoId}", 'codigo' => 'POLT-RIP', 'nome' => 'Poltrona Ripada Bege', 'id' => $variacaoId,
            'titulo' => 'poltrona ripada bege para sala', 'familia' => 'Poltronas',
            'volumes' => [['c' => '60', 'l' => '40', 'a' => '30', 'kg' => '8,5']],
            'custo' => '1.890,50', 'estoque' => '4', 'ambientes' => ['Sala de estar', 'Varanda'], 'descricao' => "Linha 1\nLinha 2",
        ]])->assertOk()->json();

        $this->assertSame([], $salvo['erros']);
        $this->assertSame(['criadas' => 0, 'atualizadas' => 1, 'sem_mudanca' => 0, 'com_erro' => 0], array_intersect_key($salvo['totais'], array_flip(['criadas', 'atualizadas', 'sem_mudanca', 'com_erro'])));
        $this->assertSame(['familias' => ['Poltronas'], 'ambientes' => ['Sala de estar', 'Varanda']], $salvo['criadas_nas_listas']);
        // O retrato novo vem junto (a tela não faz o GET) e é o que a rota 1 diz agora.
        $this->assertSame($this->semPortal($this->actingAs($this->dev)->getJson("{$produtos}/{$id}/cadastro"))->json(),
            array_diff_key($salvo, array_flip(['erros', 'avisos', 'totais', 'criadas_nas_listas', 'mensagem'])));
        // O título volta capitalizado pela régua do sistema: a tela compara o "mexido" com ESTE retrato, não com o digitado.
        $this->assertSame(['Poltrona Ripada Bege', 'Poltrona Ripada Bege Para Sala', 'Poltronas', ['Sala de estar', 'Varanda'], "Linha 1\nLinha 2"],
            [$salvo['produto']['nome'], $salvo['produto']['titulo'], $salvo['produto']['familia'], $salvo['produto']['ambientes'], $salvo['produto']['descricao']]);
        // O custo e as medidas voltam como NÚMERO (o cast do model), não como o texto que a tela mandou.
        $this->assertSame([1890.5, 4, [['c' => 60, 'l' => 40, 'a' => 30, 'kg' => 8.5]]],
            [$salvo['variacoes'][0]['custo'], $salvo['variacoes'][0]['estoque'], $salvo['variacoes'][0]['volumes']]);
        $this->assertSame(['lista', ['categoria', 'fotos (0 de 3)']], [$salvo['situacao']['etapa'], $salvo['situacao']['faltas']]);
        $this->assertSame('Salvo. Ainda faltam: categoria, fotos (0 de 3).', $salvo['mensagem']);
        $this->assertNaListaENoPortal($id, $salvo, 'Poltrona Ripada Bege');
        $this->assertNadaNoPublicador();

        // ─── 4a. `''` = não mexi (o JSON é lido cru: o `ConvertEmptyStringsToNull` não apaga nada) ───
        $igual = $this->linhas($produtos, $id, [[
            'id' => $variacaoId, 'codigo' => 'POLT-RIP', 'nome' => 'Poltrona Ripada Bege',
            'custo' => '', 'familia' => '', 'titulo' => '', 'estoque' => '', 'descricao' => '',
        ]])->assertOk()->json();
        $this->assertSame([0, 1, 'Nada mudou.'], [$igual['totais']['atualizadas'], $igual['totais']['sem_mudanca'], $igual['mensagem']]);
        $this->assertSame($salvo['produto'], $igual['produto']);
        $this->assertSame($salvo['variacoes'], $igual['variacoes']);

        // ─── 4b. `null` = limpar: o corpo que a tela manda ao esvaziar custo, família e título ───
        $limpo = $this->linhas($produtos, $id, [[
            'chave' => "v{$variacaoId}", 'codigo' => 'POLT-RIP', 'nome' => 'Poltrona Ripada Bege', 'id' => $variacaoId,
            'titulo' => null, 'familia' => null, 'custo' => null,
        ]])->assertOk()->json();
        $this->assertSame([null, null, null], [$limpo['produto']['titulo'], $limpo['produto']['familia'], $limpo['variacoes'][0]['custo']]);
        $this->assertSame([4, "Linha 1\nLinha 2"], [$limpo['variacoes'][0]['estoque'], $limpo['produto']['descricao']], 'o que não veio fica');
        $this->assertSame(['custo', 'categoria', 'título', 'família', 'fotos (0 de 3)'], $limpo['situacao']['faltas']);
        $this->assertNaListaENoPortal($id, $limpo, 'Poltrona Ripada Bege');
        $this->assertNadaNoPublicador();

        // No banco, por reconsulta — não pelo que a resposta disse.
        $produto = EstruturaProduto::query()->findOrFail($id);
        $variacao = EstruturaProdutoVariacao::query()->findOrFail($variacaoId);
        $this->assertSame(['Poltrona Ripada Bege', null, null, "Linha 1\nLinha 2"], [$produto->nome, $produto->titulo, $produto->familia_id, $produto->descricao]);
        $this->assertSame(['POLT-RIP', null, 4], [$variacao->codigo, $variacao->custo, $variacao->estoque]);
        $this->assertSame(1, EstruturaOferta::query()->where('variacao_id', $variacaoId)->count());
    }

    /** Completo, vai — uma vez, no save que o tira da Lista; antes dele, nem a categoria e as fotos gravadas por fora levam. */
    public function test_o_save_que_completa_o_produto_criado_na_trilha_o_leva_ao_publicador_uma_vez(): void
    {
        $produtos = $this->escritaDaPagina()['produtos'];
        $id = (int) $this->actingAs($this->dev)->postJson($produtos, ['nome' => 'Poltrona Ripada', 'codigo' => 'POLT-RIP'])->assertCreated()->json('produto.id');
        $variacaoId = (int) EstruturaProdutoVariacao::query()->where('produto_id', $id)->value('id');
        $this->linhas($produtos, $id, [[
            'chave' => "v{$variacaoId}", 'codigo' => 'POLT-RIP', 'nome' => 'Poltrona Ripada', 'id' => $variacaoId, 'familia' => 'Poltronas',
            'volumes' => [['c' => '60', 'l' => '40', 'a' => '30', 'kg' => '8,5']], 'custo' => '890,00', 'ambientes' => ['Sala de estar'],
            'descricao' => 'Poltrona de madeira ripada.',
        ]])->assertOk()->assertJsonPath('situacao.faltas', ['categoria', 'título', 'fotos (0 de 3)']);

        // A categoria (F2) e as fotos (F3) ainda não têm rota na trilha: gravadas direto, como o Portal grava.
        EstruturaProduto::query()->whereKey($id)->update(['categoria_ml_id' => 'MLB100200', 'categoria_ml_nome' => 'Poltronas']);
        for ($f = 0; $f < 3; $f++) {
            EstruturaProdutoVariacaoImagem::create(['company_id' => $this->empresa->id, 'produto_id' => $id, 'variacao_id' => $variacaoId,
                'caminho' => 'estrutura/'.Str::random(8).'.jpg', 'nome_original' => "foto{$f}.jpg", 'mime' => 'image/jpeg', 'tamanho' => 1000, 'ordem' => $f]);
        }
        $this->assertSame(['título'], $this->actingAs($this->dev)->getJson("{$produtos}/{$id}/cadastro")->json('situacao.faltas'));
        $this->assertNadaNoPublicador();

        $completo = $this->linhas($produtos, $id, [[
            'chave' => "v{$variacaoId}", 'codigo' => 'POLT-RIP', 'nome' => 'Poltrona Ripada', 'id' => $variacaoId,
            'titulo' => 'Poltrona Ripada De Madeira Para Sala',
        ]])->assertOk()->json();

        $this->assertSame(['mapa', [], 'Lista completa: o produto seguiu para o mapeamento.'],
            [$completo['situacao']['etapa'], $completo['situacao']['faltas'], $completo['mensagem']]);
        $this->assertSame('mapa', $this->produtoNaTrilha($this->trilha(), $id)['etapa']);
        $this->assertSame(1, $this->jobs(SincronizarProdutoDoPortalJob::class, $id));
        $this->assertSame(1, $this->jobs(PrepararProdutoNoPublicadorJob::class, $id));
    }

    // ═══ apoio ═══

    /** `escrita` das props da página da equipe — os endereços que a tela recebe. */
    private function escritaDaPagina(): array
    {
        $pagina = $this->actingAs($this->dev)->get("/trilha/{$this->empresa->id}")->assertOk();
        $props = json_decode(json_encode($pagina->viewData('page')), true)['props'];
        $this->assertTrue($props['pode_editar']);

        return $props['escrita'];
    }

    private function linhas(string $produtos, int $id, array $linhas): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson("{$produtos}/{$id}/linhas", ['linhas' => $linhas]));
    }

    /**
     * O produto está na Lista da trilha com as MESMAS faltas do retrato (a régua é uma) e destravado, e aparece na
     * tela de Produtos do Portal com a oferta simples (SKU = Ref).
     */
    private function assertNaListaENoPortal(int $id, array $retrato, string $nome): void
    {
        $naTrilha = $this->produtoNaTrilha($this->trilha(), $id);
        $this->assertNotNull($naTrilha);
        $this->assertSame([$retrato['situacao']['etapa'], $retrato['situacao']['faltas'], false],
            [$naTrilha['etapa'], $naTrilha['faltas'], $naTrilha['cadastro_travado']]);

        $noPortal = collect(app(ProdutoLinhas::class)->pagina($this->empresa, '', 1)['linhas'])->firstWhere('produto_id', $id);
        $this->assertNotNull($noPortal, 'na tela de Produtos do Portal');
        $this->assertSame([$nome, 'POLT-RIP', 'POLT-RIP'], [$noPortal['nome'], $noPortal['codigo'], $noPortal['oferta']['sku'] ?? null]);
    }

    /** Pergunta 2: incompleto, nem o Sincronizar nem o preparo — nem pelo caminho da oferta simples que nasceu. */
    private function assertNadaNoPublicador(): void
    {
        Queue::assertNotPushed(SincronizarProdutoDoPortalJob::class);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNothingPushed();
        $this->assertSame(0, PubProduto::query()->where('company_id', $this->empresa->id)->count());
    }

    private function jobs(string $classe, int $produtoId): int
    {
        return Queue::pushed($classe, fn ($j) => (int) $j->estruturaProdutoId === $produtoId)->count();
    }

    private function trilha(): array
    {
        return app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh());
    }

    /** Nenhuma resposta das rotas da trilha leva endereço do Portal (§6.3). */
    private function semPortal(TestResponse $resposta): TestResponse
    {
        $this->assertStringNotContainsString('/portal/', str_replace('\/', '/', (string) $resposta->getContent()));

        return $resposta;
    }
}
