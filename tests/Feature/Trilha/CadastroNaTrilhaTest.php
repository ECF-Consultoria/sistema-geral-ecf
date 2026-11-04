<?php

namespace Tests\Feature\Trilha;

use App\Models\BlingConexao;
use App\Models\BlingVinculo;
use App\Models\Company;
use App\Models\EstruturaAmbiente;
use App\Models\EstruturaAnuncio;
use App\Models\EstruturaAnuncioEspera;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoLinhas;
use App\Services\Portal\Estrutura\Produtos\VariacaoImagensService;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Services\Trilha\VersaoDoCadastro;
use App\Support\Publicador\MemoriaDoPreparoIa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O cadastro do produto DENTRO da trilha, fatia F1 (3ª entrega, 02/11/2026; desenho §6 e §9): criar com nome e SKU,
 * abrir a gaveta (o retrato) e gravar o básico — pelos serviços do Portal, sem o Portal.
 *
 * - "criar primeiro": nasce o produto, a 1ª variação (sem eixo) e a oferta simples (SKU = Ref), e ele aparece na Lista
 *   da trilha e na tela de Produtos do Portal; SKU repetido (sem caixa nem acento) é 422 no campo;
 * - o JSON CRU das linhas: `''` não mexe, `null` limpa (sem isso o `ConvertEmptyStringsToNull` apagaria o dado);
 * - uma requisição, um produto, e só as chaves da lista permitida (a categoria, conferida, desde a F2 —
 *   {@see FichaNaTrilhaTest}; cores, tamanhos e variação nova desde a F4 — {@see CoresNaTrilhaTest});
 * - o retrato é a LISTA DE CAMPOS do contrato (§6.3), com as fotos pela rota da equipe e nenhum `/portal/`;
 * - a trava (pergunta 1): o produto que algum item da trilha já levou adiante fica só para leitura (409);
 * - o rastro: um `trilha_cadastro_salvo` por escrita, com `via: trilha`, o passo e o ator da equipe.
 *
 * O custo (quando o produto vai ao Publicador) está em {@see CustoDoCadastroTest}; a guarda, em
 * {@see CadastroNaTrilhaAcessoTest}.
 */
class CadastroNaTrilhaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    /** As chaves do retrato da gaveta (§6.3), nem uma a mais. */
    private const CHAVES_DO_RETRATO = ['produto', 'variacoes', 'ficha', 'listas', 'eixos', 'limites', 'situacao', 'edicao', 'versao'];

    private const CHAVES_DO_PRODUTO = ['id', 'nome', 'titulo', 'familia', 'ambientes', 'categoria', 'descricao', 'eixo', 'eixo_2', 'origem_ml', 'titulo_do_anuncio'];

    private const CHAVES_DA_VARIACAO = ['id', 'codigo', 'valor', 'valor_2', 'ordem', 'custo', 'estoque', 'volumes', 'bling', 'estoque_do_bling', 'dona_da_cor', 'logistica', 'fotos'];

    private const CHAVES_DA_SITUACAO = ['etapa', 'faltas', 'avisos', 'no_publicador', 'categoria_travada', 'mapeado_em'];

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

    // ═══ Criar (rota 2) ════════════════════════════════════════════════════

    public function test_criar_nasce_o_produto_a_variacao_e_a_oferta_simples_e_ele_entra_na_lista(): void
    {
        $r = $this->criar(['nome' => 'Poltrona Ripada Bege', 'codigo' => 'POLT-RIP-BG',
            // O resto do corpo é ignorado: só nome e SKU nascem.
            'descricao' => 'não grava', 'custo' => '10', 'grupo' => 'OUTRO', 'familia' => 'Poltronas'])
            ->assertCreated()->json();

        $produto = EstruturaProduto::query()->where('company_id', $this->empresa->id)->sole();
        $this->assertSame(['Poltrona Ripada Bege', 'POLT-RIP-BG', null, null, null], [$produto->nome, $produto->codigo, $produto->descricao, $produto->familia_id, $produto->titulo]);
        $variacao = EstruturaProdutoVariacao::query()->where('produto_id', $produto->id)->sole();
        $this->assertSame(['POLT-RIP-BG', null, null, null], [$variacao->codigo, $variacao->eixo, $variacao->valor, $variacao->custo]);
        $oferta = EstruturaOferta::query()->where('company_id', $this->empresa->id)->sole();
        $this->assertSame([(int) $variacao->id, 'POLT-RIP-BG', EstruturaOferta::FASE_SIMPLES], [(int) $oferta->variacao_id, $oferta->sku, $oferta->fase]);

        // A resposta é o retrato do produto novo (a gaveta troca para `e:{id}` sem outro GET) + o resultado da criação.
        $this->assertContratoDoRetrato($r);
        $this->assertSame((int) $produto->id, $r['produto']['id']);
        $this->assertSame([0, 'Produto criado.'], [$r['absorvidos_da_espera'], $r['mensagem']]);
        $this->assertSame('lista', $r['situacao']['etapa']);
        $this->assertContains('categoria', $r['situacao']['faltas']);
        $this->assertContains('fotos (0 de 3)', $r['situacao']['faltas']);
        $this->assertSame(['livre' => true, 'motivo' => null, 'onda_comecou' => null], $r['edicao']);

        // Na Lista da trilha, com as mesmas faltas, destravado; e na tela de Produtos do Portal, com a oferta simples.
        $naTrilha = $this->produtoNaTrilha($this->trilha(), (int) $produto->id);
        $this->assertSame(['lista', $r['situacao']['faltas'], false], [$naTrilha['etapa'], $naTrilha['faltas'], $naTrilha['cadastro_travado']]);
        $noPortal = app(ProdutoLinhas::class)->pagina($this->empresa, '', 1)['linhas'];
        $this->assertSame(['POLT-RIP-BG', 'POLT-RIP-BG'], [$noPortal[0]['codigo'], $noPortal[0]['oferta']['sku']]);
    }

    public function test_sku_repetido_sem_caixa_nem_acento_volta_422_no_campo_e_o_duplo_envio_nao_cria_dois(): void
    {
        $this->criar(['nome' => 'Poltrona', 'codigo' => 'póltrona-1'])->assertCreated();

        $this->criar(['nome' => 'Outra poltrona', 'codigo' => 'POLTRONA-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['codigo'])
            ->assertJsonPath('errors.codigo.0', 'O código POLTRONA-1 já existe em outro produto. Use outro código.');
        // O mesmo pedido duas vezes (o duplo clique): o 2º cai no "repetido".
        $this->criar(['nome' => 'Poltrona', 'codigo' => 'póltrona-1'])->assertUnprocessable()->assertJsonValidationErrors(['codigo']);

        $this->assertSame(1, EstruturaProduto::query()->count());
        $this->assertSame(1, EstruturaProdutoVariacao::query()->count());
        $this->assertSame(1, EstruturaOferta::query()->count());
    }

    public function test_criar_sem_nome_ou_sem_sku_e_422_no_campo(): void
    {
        $this->criar(['codigo' => 'X-1'])->assertUnprocessable()->assertJsonValidationErrors(['nome']);
        $this->criar(['nome' => 'Poltrona'])->assertUnprocessable()->assertJsonValidationErrors(['codigo']);
        $this->criar(['nome' => '   ', 'codigo' => 'X-1'])->assertUnprocessable()->assertJsonValidationErrors(['nome']);
        $this->criar(['nome' => 'Poltrona', 'codigo' => str_repeat('A', 121)])->assertUnprocessable()->assertJsonValidationErrors(['codigo']);
        $this->criar(['nome' => str_repeat('P', 256), 'codigo' => 'X-1'])->assertUnprocessable()->assertJsonValidationErrors(['nome']);

        $this->assertSame(0, EstruturaProduto::query()->count());
    }

    /** O SKU que já tinha anúncio na espera: o produto nasce ligado a ele — e, como a oferta "andou", só para leitura. */
    public function test_o_sku_que_tinha_anuncio_na_espera_nasce_ligado_avisa_e_abre_so_para_leitura(): void
    {
        EstruturaAnuncioEspera::create(['company_id' => $this->empresa->id, 'sku_colado' => 'POLT-9', 'motivo' => 'sem_oferta',
            'tipo' => 'classico', 'status' => 'ativo', 'codigo_mlb' => 'MLB5550001112', 'titulo' => 'Poltrona antiga']);

        $r = $this->criar(['nome' => 'Poltrona', 'codigo' => 'POLT-9'])->assertCreated()->json();

        $this->assertSame(1, $r['absorvidos_da_espera']);
        $this->assertStringContainsString('Este SKU já tinha anúncio', $r['mensagem']);
        $this->assertSame(1, EstruturaAnuncio::query()->where('codigo_mlb', 'MLB5550001112')->count());
        $this->assertFalse($r['edicao']['livre']);
        $this->assertStringContainsString('já saiu do Planejamento', (string) $r['edicao']['motivo']);
    }

    // ═══ Abrir (rota 1): o retrato ═════════════════════════════════════════

    public function test_o_retrato_e_a_lista_de_campos_com_as_fotos_pela_rota_da_equipe(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', [null], ['categoria_ml_caminho' => 'Casa › Decoração']);

        $r = $this->abrir($p['produto'])->assertOk()->json();

        $this->assertContratoDoRetrato($r);
        $v = $p['variacoes'][0];
        $this->assertSame([
            'id' => (int) $p['produto']->id, 'nome' => 'Vaso Alfa', 'titulo' => 'Título De Venda Vaso Alfa', 'familia' => 'Decoração',
            'ambientes' => ['Sala de estar'],
            'categoria' => ['id' => 'MLB100200', 'nome' => 'Objetos de decoração', 'caminho_texto' => 'Casa › Decoração', 'estado' => 'confirmada'],
            'descricao' => 'Uma descrição do produto.', 'eixo' => null, 'eixo_2' => null, 'origem_ml' => false,
            // F8 (D41): o título do anúncio original — nulo no produto que não veio do ML.
            'titulo_do_anuncio' => null,
        ], $r['produto']);
        $variacao = $r['variacoes'][0];
        $this->assertSame([(int) $v->id, 'VA', 1, 100, null, [['c' => 50, 'l' => 40, 'a' => 30, 'kg' => 5]], false, false, true],
            [$variacao['id'], $variacao['codigo'], $variacao['ordem'], $variacao['custo'], $variacao['estoque'], $variacao['volumes'], $variacao['bling'], $variacao['estoque_do_bling'], $variacao['dona_da_cor']]);
        $this->assertCount(3, $variacao['fotos']);
        $foto = $variacao['fotos'][0];
        $this->assertSame(['id', 'url', 'url_original', 'nome_original'], array_keys($foto));
        $this->assertSame("/trilha/{$this->empresa->id}/fotos/{$v->id}/{$foto['id']}", $foto['url']);
        $this->assertSame("/trilha/{$this->empresa->id}/fotos/{$v->id}/{$foto['id']}?original=1", $foto['url_original']);
        // A miniatura abre pela rota da equipe (a mesma do Mapear).
        $this->actingAs($this->dev)->get($foto['url'])->assertNotFound(); // a linha não tem arquivo no disco: 404, nunca 500

        $this->assertSame(['familias' => [['id' => EstruturaFamilia::query()->value('id'), 'nome' => 'Decoração']],
            'ambientes' => [['id' => EstruturaAmbiente::query()->value('id'), 'nome' => 'Sala de estar']]], $r['listas']);
        $this->assertSame('Cor', $r['eixos']['cor']);
        $this->assertSame(['fotos_por_variacao' => 12, 'foto_mb' => 10, 'descricao' => 5000, 'linhas' => 200], $r['limites']);
        // Completo e não mapeado: "falta mapear".
        $this->assertSame(['etapa' => 'mapa', 'faltas' => [], 'avisos' => [], 'no_publicador' => false, 'categoria_travada' => false, 'mapeado_em' => null], $r['situacao']);
        // F6: a versão do cadastro (sha1), a mesma que o serviço calcula agora.
        $this->assertSame(VersaoDoCadastro::de($this->empresa, $p['produto']), $r['versao']);
    }

    public function test_o_retrato_diz_mapeado_e_no_publicador_e_a_falta_e_a_mesma_da_lista(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB', [null], ['titulo' => null, 'descricao' => null]);
        TrilhaMapeamento::create(['company_id' => $this->empresa->id, 'produto_id' => $a['produto']->id, 'concluido_em' => now()]);
        $this->grupoNoPublicador($this->empresa, $a);

        $ra = $this->abrir($a['produto'])->assertOk()->json();
        $rb = $this->abrir($b['produto'])->assertOk()->json();

        $this->assertNull($ra['situacao']['etapa'], 'mapeado: segue pelas ofertas');
        $this->assertSame('2026-11-05T10:00:00-03:00', $ra['situacao']['mapeado_em']);
        $this->assertSame([true, true], [$ra['situacao']['no_publicador'], $ra['situacao']['categoria_travada']]);
        $this->assertSame([true, true], [$ra['variacoes'][0]['dona_da_cor'], $ra['variacoes'][1]['dona_da_cor']], 'um eixo só: cada cor é dona das fotos');
        $this->assertSame(['Azul', 'Verde'], array_column($ra['variacoes'], 'valor'));
        $this->assertSame('cor', $ra['produto']['eixo']);

        $this->assertSame('lista', $rb['situacao']['etapa']);
        $this->assertSame(['título', 'descrição'], $rb['situacao']['faltas']);
        // A régua de um produto é a da Lista inteira (a mesma que a tela da trilha lê).
        $todas = TrilhaDaEmpresa::faltasDosProdutos($this->empresa);
        foreach ([$a, $b] as $p) {
            $this->assertSame($todas[(int) $p['produto']->id], TrilhaDaEmpresa::faltasDoProduto($this->empresa, (int) $p['produto']->id));
        }
    }

    public function test_com_cor_e_tamanho_a_dona_das_fotos_e_a_variacao_da_cor_que_tem_foto(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Camiseta', 'CM', ['Azul', 'Azul', 'Verde']);
        // Cor E tamanho: Azul P (com as fotos), Azul M (sem) e Verde P (com).
        foreach ($p['variacoes'] as $i => $v) {
            $v->update(['eixo_2' => 'tamanho', 'valor_2' => $i === 1 ? 'M' : 'P']);
        }
        \App\Models\EstruturaProdutoVariacaoImagem::query()->where('variacao_id', $p['variacoes'][1]->id)->delete();

        $r = $this->abrir($p['produto'])->assertOk()->json();

        $this->assertSame([true, false, true], array_column($r['variacoes'], 'dona_da_cor'));
        $this->assertSame([[$p['variacoes'][0]->id], []], [array_values(array_unique(array_map(fn ($f) => (int) explode('/', $f['url'])[4], $r['variacoes'][0]['fotos']))), $r['variacoes'][1]['fotos']]);
        $this->assertSame('tamanho', $r['produto']['eixo_2']);
        $this->assertNotContains('fotos (0 de 3)', $r['situacao']['faltas'], 'F0: o tamanho sem foto própria leva as da cor');
    }

    /**
     * A variação ligada ao Bling (desenho §5.6): o retrato diz as DUAS travas que o serviço aplica ao gravar — o SKU
     * (`bling`, com o vínculo ativo) e o estoque (`estoque_do_bling`, só com a sincronização de estoque ligada). Achado
     * da integração da F1: com uma chave só, a tela não sabia se o estoque vinha de lá.
     */
    public function test_a_variacao_do_bling_diz_no_retrato_o_sku_e_o_estoque_que_vem_de_la(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $v = $p['variacoes'][0];
        $conexao = BlingConexao::create(['company_id' => $this->empresa->id, 'access_token' => 'a', 'refresh_token' => 'r',
            'status' => BlingConexao::STATUS_ATIVA, 'estoque_ativo' => false]);
        BlingVinculo::create(['company_id' => $this->empresa->id, 'bling_produto_id' => '987', 'variacao_id' => $v->id,
            'codigo_bling' => 'VA', 'situacao' => BlingVinculo::SITUACAO_VINCULADO]);

        $this->assertSame([true, false], [$this->abrir($p['produto'])->json('variacoes.0.bling'), $this->abrir($p['produto'])->json('variacoes.0.estoque_do_bling')],
            'sem a sincronização de estoque, só o SKU vem do Bling');

        $conexao->update(['estoque_ativo' => true]);
        $r = $this->abrir($p['produto'])->assertOk()->json();
        $this->assertSame([true, true], [$r['variacoes'][0]['bling'], $r['variacoes'][0]['estoque_do_bling']]);

        // O mesmo que a gravação faz: o serviço ignora os dois e avisa — por isso a tela os mostra só para leitura.
        $salvo = $this->linhas($p['produto'], [['id' => $v->id, 'codigo' => 'VA-NOVO', 'nome' => 'Vaso Alfa', 'estoque' => '9', 'custo' => '7,00']])
            ->assertOk()->json();
        $this->assertSame(['VA', null, 7.0], [$v->fresh()->codigo, $v->fresh()->estoque, $v->fresh()->custo]);
        $this->assertStringContainsString('vêm do Bling', implode(' ', $salvo['avisos']));
    }

    // ═══ Gravar as linhas (rota 3) ═════════════════════════════════════════

    public function test_texto_vazio_nao_mexe_e_null_limpa_custo_familia_e_titulo(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $v = $p['variacoes'][0];

        $r = $this->linhas($p['produto'], [['id' => $v->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '', 'familia' => '', 'titulo' => '', 'descricao' => '']])
            ->assertOk()->json();

        $this->assertSame(['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 1], array_intersect_key($r['totais'], array_flip(['criadas', 'atualizadas', 'sem_mudanca'])));
        $this->assertSame('Nada mudou.', $r['mensagem']);
        $produto = $p['produto']->fresh();
        $this->assertSame([100.0, 'Título De Venda Vaso Alfa', 'Uma descrição do produto.'], [$v->fresh()->custo, $produto->titulo, $produto->descricao]);
        $this->assertNotNull($produto->familia_id);

        $r = $this->linhas($p['produto'], [['id' => $v->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => null, 'familia' => null, 'titulo' => null]])
            ->assertOk()->json();

        $produto = $p['produto']->fresh();
        $this->assertSame([null, null, null], [$v->fresh()->custo, $produto->titulo, $produto->familia_id]);
        $this->assertSame(['custo', 'título', 'família'], $r['situacao']['faltas']);
        $this->assertSame('Salvo. Ainda faltam: custo, título, família.', $r['mensagem']);
        $this->assertSame('lista', $r['situacao']['etapa']);
    }

    public function test_grava_o_basico_e_cria_familia_e_ambientes_pelo_nome(): void
    {
        $produto = EstruturaProduto::query()->findOrFail($this->criar(['nome' => 'Poltrona', 'codigo' => 'POLT-1'])->json('produto.id'));
        $v = $produto->variacoes()->sole();

        $r = $this->linhas($produto, [[
            'id' => $v->id, 'chave' => 'k1', 'codigo' => 'POLT-1-BG', 'nome' => 'Poltrona Ripada', 'titulo' => 'poltrona ripada bege para sala',
            'familia' => 'Poltronas', 'ambientes' => ['Sala de estar', 'Varanda'], 'custo' => '1.890,50', 'estoque' => '4',
            // Caixa dentro dos limites dos Correios: o "frete ME1" (aviso na trilha de trabalho, F3) fica fora deste teste.
            'volumes' => [['c' => 60, 'l' => 40, 'a' => 30, 'kg' => 8.5]], 'descricao' => "Linha 1\nLinha 2",
        ]])->assertOk()->json();

        $this->assertSame([], $r['erros']);
        $this->assertSame(['familias' => ['Poltronas'], 'ambientes' => ['Sala de estar', 'Varanda']], $r['criadas_nas_listas']);
        $produto->refresh();
        $v->refresh();
        // O título sai capitalizado pela régua do sistema (`TituloDoProduto`), como no Portal.
        $this->assertSame(['Poltrona Ripada', 'Poltrona Ripada Bege Para Sala', "Linha 1\nLinha 2", 'Poltronas'],
            [$produto->nome, $produto->titulo, $produto->descricao, $produto->familia?->nome]);
        $this->assertSame(['POLT-1-BG', 1890.5, 4], [$v->codigo, $v->custo, $v->estoque]);
        // A oferta simples acompanha o SKU e o nome.
        $oferta = EstruturaOferta::query()->where('variacao_id', $v->id)->sole();
        $this->assertSame(['POLT-1-BG', 'Poltrona Ripada'], [$oferta->sku, $oferta->nome]);

        // O retrato que volta já é o novo, com as listas que nasceram.
        $this->assertContratoDoRetrato($r);
        $this->assertSame(['Sala de estar', 'Varanda'], $r['produto']['ambientes']);
        $this->assertSame(['Poltronas'], array_column($r['listas']['familias'], 'nome'));
        $this->assertSame([['c' => 60, 'l' => 40, 'a' => 30, 'kg' => 8.5]], $r['variacoes'][0]['volumes']);
        $this->assertSame(['categoria', 'fotos (0 de 3)'], $r['situacao']['faltas']);
    }

    public function test_a_descricao_acima_do_teto_e_erro_da_linha_e_nada_dela_grava(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');

        $r = $this->linhas($p['produto'], [['id' => $p['variacoes'][0]->id, 'chave' => 'k1', 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '7,00',
            'descricao' => str_repeat('a', 5001)]])->assertOk()->json();

        $this->assertCount(1, $r['erros']);
        $this->assertSame('k1', $r['erros'][0]['chave']);
        $this->assertArrayHasKey('descricao', $r['erros'][0]['campos']);
        $this->assertSame('Confira os campos marcados: o que está neles não foi salvo.', $r['mensagem']);
        $this->assertSame([100.0, 'Uma descrição do produto.'], [$p['variacoes'][0]->fresh()->custo, $p['produto']->fresh()->descricao]);
    }

    /**
     * As chaves fora da lista permitida não chegam ao serviço; o `produto_id` é sempre o do caminho. Desde a F4 o eixo,
     * o valor, o 2º eixo e a ordem passam (com a conferência das cores — {@see CoresNaTrilhaTest}).
     */
    public function test_grupo_categoria_texto_e_o_resto_fora_da_lista_sao_descartados(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');

        $this->linhas($a['produto'], [[
            'id' => $a['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa Novo',
            'produto_id' => $b['produto']->id, 'company_id' => 999, 'grupo' => 'VB',
            // O texto de categoria ("a confirmar" no Portal) nunca entra pela trilha; a categoria do ML (F2) passa,
            // conferida — ver FichaNaTrilhaTest. A coluna "Variação" da planilha e as caixas em texto também não.
            'categoria_texto' => 'Cadeiras', 'variacao' => 'Cor: Verde', 'volumes_texto' => '10x10x10 1kg',
            'eixo' => 'cor', 'valor' => 'Azul', 'eixo_2' => 'tamanho', 'valor_2' => 'P', 'ordem' => 9,
        ]])->assertOk()->assertJsonPath('erros', []);

        $produto = $a['produto']->fresh();
        $v = $a['variacoes'][0]->fresh();
        $this->assertSame(['Vaso Alfa Novo', 'VA', 'MLB100200', 'Objetos de decoração'], [$produto->nome, $produto->codigo, $produto->categoria_ml_id, $produto->categoria_ml_nome]);
        $this->assertSame(['cor', 'Azul', 'tamanho', 'P', 9], [$v->eixo, $v->valor, $v->eixo_2, $v->valor_2, $v->ordem]);
        $this->assertSame([[50.0, 40.0, 30.0, 5.0]], $v->volumes()->get()->map(fn ($x) => [(float) $x->comprimento, (float) $x->largura, (float) $x->altura, (float) $x->peso])->all());
        $this->assertSame('Vaso Beta', $b['produto']->fresh()->nome);
        $this->assertSame((int) $this->empresa->id, (int) $produto->company_id);
    }

    /**
     * F4: a linha sem `id` é variação nova deste produto. Sem a cor da que já existe no mesmo pedido, o Publicador
     * separaria as duas: 422 por linha e nada grava (o resto da régua está no {@see CoresNaTrilhaTest}).
     */
    public function test_a_linha_sem_id_e_variacao_nova_e_sem_nomear_a_que_existe_nada_grava(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');

        $this->linhas($p['produto'], [
            ['id' => $p['variacoes'][0]->id, 'chave' => 'v1', 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '7,00'],
            ['chave' => 'n1', 'codigo' => 'VA-2', 'nome' => 'Vaso Alfa'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['linhas.0.valor', 'linhas.1.valor']);

        $this->assertSame(1, EstruturaProdutoVariacao::query()->count());
        $this->assertSame(100.0, $p['variacoes'][0]->fresh()->custo);
    }

    public function test_corpo_sem_linhas_ou_com_linhas_demais_e_422(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $linha = ['id' => $p['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa'];

        $this->linhas($p['produto'], [])->assertUnprocessable()->assertJsonValidationErrors(['linhas']);
        $this->actingAs($this->dev)->postJson($this->url($p['produto'], 'linhas'), ['linhas' => 'x'])->assertUnprocessable();
        $this->linhas($p['produto'], array_fill(0, 201, $linha))->assertUnprocessable()->assertJsonValidationErrors(['linhas']);
        $this->linhas($p['produto'], ['x'])->assertUnprocessable()->assertJsonValidationErrors(['linhas.0']);
    }

    // ═══ O rastro ══════════════════════════════════════════════════════════

    public function test_cada_escrita_deixa_um_evento_da_trilha_com_o_passo_e_o_ator_da_equipe(): void
    {
        $id = $this->criar(['nome' => 'Poltrona', 'codigo' => 'POLT-1'])->json('produto.id');
        $produto = EstruturaProduto::query()->findOrFail($id);
        $v = $produto->variacoes()->sole();
        $this->linhas($produto, [['id' => $v->id, 'codigo' => 'POLT-1', 'nome' => 'Poltrona', 'custo' => '9,90']])->assertOk();
        // Nada mudou: nenhum evento.
        $this->linhas($produto, [['id' => $v->id, 'codigo' => 'POLT-1', 'nome' => 'Poltrona', 'custo' => '9,90']])->assertOk();

        $eventos = Activity::query()->where('log_name', 'portal')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_cadastro_salvo')->values();
        $this->assertCount(2, $eventos);
        $this->assertSame(['criar', 'linhas'], $eventos->map(fn (Activity $a) => $a->getExtraProperty('passo'))->all());
        foreach ($eventos as $e) {
            $this->assertSame(['trilha', 'interno', (int) $id, (int) $this->empresa->id], [$e->getExtraProperty('via'), $e->getExtraProperty('origem'),
                (int) $e->getExtraProperty('produto_id'), (int) $e->getExtraProperty('company_id')]);
            $this->assertSame([User::class, (int) $this->dev->id], [$e->causer_type, (int) $e->causer_id]);
            $this->assertSame([EstruturaProduto::class, (int) $id], [$e->subject_type, (int) $e->subject_id]);
        }
        // Os eventos de sempre dos serviços continuam (a trilha só acrescenta o dela).
        $this->assertTrue(Activity::query()->get()->contains(fn (Activity $a) => $a->getExtraProperty('evento') === 'produtos_gravados'));
    }

    // ═══ A trava (pergunta 1) ══════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function oQueAndou(): array
    {
        return [
            'rascunho validado'          => ['validado'],
            'com anúncio'                => ['anuncio'],
            'com etapa do agente'          => ['robo'],
            'Kit N do Publicador'        => ['kit_n'],
            'kit da trilha que o leva'   => ['kit'],
        ];
    }

    #[DataProvider('oQueAndou')]
    public function test_o_produto_que_algum_item_levou_adiante_fica_so_para_leitura(string $caso): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $outro = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $nomeDoItem = $this->andou($caso, $p, $outro);
        $antes = [$p['variacoes'][0]->fresh()->custo, $p['produto']->fresh()->nome];

        $this->linhas($p['produto'], [['id' => $p['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa Mudado', 'custo' => '1,00']])
            ->assertStatus(409)
            ->assertJsonPath('travado', true)
            ->assertJsonPath('motivo', fn (string $m) => str_contains($m, "“{$nomeDoItem}”") && str_contains($m, 'já saiu do Planejamento'));

        $this->assertSame($antes, [$p['variacoes'][0]->fresh()->custo, $p['produto']->fresh()->nome], 'nada gravou');
        Queue::assertNothingPushed();

        // A gaveta abre para leitura, com o motivo; a Lista da trilha diz o mesmo.
        $r = $this->abrir($p['produto'])->assertOk()->json();
        $this->assertFalse($r['edicao']['livre']);
        $this->assertStringContainsString("“{$nomeDoItem}”", (string) $r['edicao']['motivo']);
        $this->assertNull($r['edicao']['onda_comecou']);
        $this->assertTrue($this->produtoNaTrilha($this->trilha(), (int) $p['produto']->id)['cadastro_travado']);
        // O vizinho, que nenhum item levou (salvo o kit que leva os dois), segue livre.
        $this->assertSame($caso !== 'kit', $this->abrir($outro['produto'])->json('edicao.livre'));
    }

    public function test_onda_comecada_sem_o_robo_fica_editavel_com_o_aviso_e_a_futura_sem_ele(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $q = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB');
        $hoje = $this->onda($this->empresa, '2026-11-05', 'Onda Novembro');
        $this->itemNaOnda($hoje, "s-{$p['produto']->id}");
        $this->itemNaOnda($this->onda($this->empresa, '2026-11-20', 'Onda Futura'), "s-{$q['produto']->id}");
        // O rascunho existe, ainda DRAFT e sem nada do agente: não andou.
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p));

        $r = $this->abrir($p['produto'])->assertOk()->json();
        $this->assertSame(['livre' => true, 'motivo' => null, 'onda_comecou' => ['id' => (int) $hoje->id, 'nome' => 'Onda Novembro', 'data' => '2026-11-05']], $r['edicao']);
        $this->assertNull($this->abrir($q['produto'])->json('edicao.onda_comecou'));

        $this->linhas($p['produto'], [['id' => $p['variacoes'][0]->id, 'codigo' => 'VA', 'nome' => 'Vaso Alfa', 'custo' => '120,00']])->assertOk();
        $this->assertSame(120.0, $p['variacoes'][0]->fresh()->custo);
    }

    // ═══ apoio ═══

    /** Faz um item da trilha com o produto `$p` andar; devolve o nome do item que a trava cita. */
    private function andou(string $caso, array $p, array $outro): string
    {
        switch ($caso) {
            case 'validado':
                $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p), PubRascunho::VALIDATED);

                return (string) $p['simples'][0]->nome;
            case 'anuncio':
                EstruturaAnuncio::create(['oferta_id' => $p['simples'][0]->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
                    'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567890', 'titulo' => 'Vaso']);

                return (string) $p['simples'][0]->nome;
            case 'robo':
                $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $p), PubRascunho::DRAFT,
                    [MemoriaDoPreparoIa::PREPARO => ['etapas' => ['titulo' => ['status' => 'ok']]]]);

                return (string) $p['simples'][0]->nome;
            case 'kit_n':
                $grupo = $this->grupoNoPublicador($this->empresa, $p);
                $this->rascunhoDoPub($grupo);
                $combo = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-CB2', 'Combo 2 Vaso Alfa', [[$p['simples'][0], 2]]);
                PubProduto::create(['company_id' => $this->empresa->id, 'produto_base_id' => $grupo->id, 'quantidade_kit' => 2,
                    'sku' => 'VA-CB2', 'nome' => 'Kit 2', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

                return (string) $combo->nome;
            case 'kit':
                $kit = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_KIT, 'KT-VA-VB', 'Kit Alfa e Beta', [[$p['simples'][0], 1], [$outro['simples'][0], 1]]);
                EstruturaAnuncio::create(['oferta_id' => $kit->id, 'tipo' => EstruturaAnuncio::TIPO_CLASSICO,
                    'status' => EstruturaAnuncio::STATUS_ATIVO, 'codigo_mlb' => 'MLB1234567891', 'titulo' => 'Kit']);

                return 'Kit Alfa e Beta';
        }
        $this->fail("caso desconhecido: {$caso}");
    }

    private function url(EstruturaProduto $produto, string $fim): string
    {
        return "/trilha/{$this->empresa->id}/produtos/{$produto->id}/{$fim}";
    }

    private function criar(array $corpo): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson("/trilha/{$this->empresa->id}/produtos", $corpo));
    }

    private function abrir(EstruturaProduto $produto): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->getJson($this->url($produto, 'cadastro')));
    }

    private function linhas(EstruturaProduto $produto, array $linhas): TestResponse
    {
        return $this->semPortal($this->actingAs($this->dev)->postJson($this->url($produto, 'linhas'), ['linhas' => $linhas]));
    }

    /** Nenhuma resposta das rotas da trilha leva endereço do Portal (§6.3). */
    private function semPortal(TestResponse $resposta): TestResponse
    {
        $this->assertStringNotContainsString('/portal/', (string) $resposta->getContent());
        $this->assertStringNotContainsString('\/portal\/', (string) $resposta->getContent());

        return $resposta;
    }

    private function trilha(): array
    {
        return app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh());
    }

    private function assertContratoDoRetrato(array $r): void
    {
        foreach (self::CHAVES_DO_RETRATO as $chave) {
            $this->assertArrayHasKey($chave, $r);
        }
        $this->assertSame(self::CHAVES_DO_PRODUTO, array_keys($r['produto']));
        foreach ($r['variacoes'] as $v) {
            $this->assertSame(self::CHAVES_DA_VARIACAO, array_keys($v));
            $this->assertArrayNotHasKey('capa', $v);
            $this->assertArrayNotHasKey('oferta', $v);
            $this->assertArrayNotHasKey('frete', $v);
        }
        $this->assertSame(['salvos'], array_keys($r['ficha']));
        $this->assertSame(['familias', 'ambientes'], array_keys($r['listas']));
        $this->assertSame(self::CHAVES_DA_SITUACAO, array_keys($r['situacao']));
        // O "frete ME1" como aviso (F3) está no FotosNaTrilhaTest e no TrilhaDaEmpresaTest; aqui as caixas cabem.
        $this->assertSame([], $r['situacao']['avisos']);
        $this->assertSame(['livre', 'motivo', 'onda_comecou'], array_keys($r['edicao']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $r['versao'], 'F6: a versão do cadastro (sha1)');
    }
}
