<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Produtos\ModeloProdutosXlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-10: as escritas do submódulo Produtos pelo HTTP — gravação por
 * linha, exclusão de variação, famílias e ambientes. Os serviços já têm os
 * seus testes (06/07); aqui se prova a casca: JSON, status, mensagem e que a
 * empresa vem da sessão.
 */
class GravarLinhasTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
    }

    private function vol(): array
    {
        return [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]];
    }

    private function gravar($sessao, array $linhas)
    {
        return $sessao->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => $linhas]);
    }

    public function test_a_gravacao_devolve_as_linhas_calculadas_no_servidor_e_as_listas(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->gravar($this->entrarNoPortal($empresa), [
            ['chave' => 'k1', 'codigo' => 'MSA-1', 'nome' => 'Mesa', 'volumes' => $this->vol(), 'custo' => '100,50', 'familia' => 'Farmhouse', 'ambientes' => ['Sala']],
        ])->assertOk();

        $r->assertJsonStructure(['linhas', 'erros', 'avisos', 'criadas_nas_listas', 'totais', 'listas' => ['familias', 'ambientes']]);
        $linha = $r->json('linhas.0');
        $this->assertSame('k1', $linha['chave']);
        $this->assertArrayHasKey('logistica', $linha);
        $this->assertArrayHasKey('peso_cubado', $linha);
        $this->assertArrayHasKey('pendencias', $linha);
        $this->assertArrayHasKey('origem', $linha['frete']);
        $this->assertSame(1, $r->json('totais.criadas'));
        $this->assertSame(['Farmhouse'], array_column($r->json('listas.familias'), 'nome'));
        $this->assertSame(['Sala'], array_column($r->json('listas.ambientes'), 'nome'));
    }

    public function test_erro_de_uma_linha_nao_impede_as_outras(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->gravar($this->entrarNoPortal($empresa), [
            ['chave' => 'ok1', 'codigo' => 'A-1', 'nome' => 'A'],
            ['chave' => 'ruim', 'codigo' => '', 'nome' => 'Sem código'],
            ['chave' => 'ok2', 'codigo' => 'A-2', 'nome' => 'B'],
        ])->assertOk();

        $this->assertSame(2, $r->json('totais.criadas'));
        $this->assertSame(1, $r->json('totais.com_erro'));
        $this->assertSame(['ruim'], array_column($r->json('erros'), 'chave'));
        $this->assertSame(2, EstruturaProdutoVariacao::where('company_id', $empresa->id)->count());
    }

    /**
     * BE-CR-01: o `codigo` do produto fica "órfão" quando a Ref da 1ª variação muda. Um "Novo
     * produto" da ficha com aquela Ref (e grupo = Ref, como a ficha manda) caía dentro do produto
     * antigo: renomeava, apagava a categoria e pendurava a variação nele.
     */
    public function test_produto_novo_com_grupo_igual_ao_codigo_orfao_de_outro_produto_nao_mexe_nele(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        // 1. O produto antigo nasce pela ficha com a Ref A1 (o código dele vira A1).
        $this->gravar($sessao, [['chave' => 'a', 'grupo' => 'A1', 'codigo' => 'A1', 'nome' => 'Mesa Antiga', 'familia' => 'Farmhouse', 'volumes' => $this->vol()]])->assertOk();
        $antigo = EstruturaProduto::where('company_id', $empresa->id)->firstOrFail();
        $antigo->update(['categoria_ml_id' => 'MLB1', 'categoria_ml_nome' => 'Cristaleiras', 'categoria_ml_caminho' => 'Casa > Cristaleiras']);
        $variacao = EstruturaProdutoVariacao::where('produto_id', $antigo->id)->firstOrFail();

        // 2. A Ref muda para B1: o código do produto continua A1.
        $this->gravar($sessao, [['id' => $variacao->id, 'codigo' => 'B1', 'nome' => 'Mesa Antiga']])->assertOk();
        $this->assertSame('A1', $antigo->fresh()->codigo);

        // 3. "Novo produto" com a Ref A1: a ficha manda grupo = Ref e categoria_texto vazio.
        $r = $this->gravar($sessao, [
            ['chave' => 'n1', 'grupo' => 'A1', 'codigo' => 'A1', 'nome' => 'Mesa Nova', 'categoria_texto' => '', 'valor' => 'Preto'],
        ])->assertOk();

        $this->assertSame(0, $r->json('totais.criadas'));
        $this->assertSame(1, $r->json('totais.com_erro'));
        $this->assertSame('n1', $r->json('erros.0.chave'));
        $this->assertSame('Já existe um produto com o código A1. Abra a ficha dele para adicionar a variação.', $r->json('erros.0.mensagem'));

        // O produto antigo está intacto: nome, categoria, família e variações.
        $antigo->refresh();
        $this->assertSame('Mesa Antiga', $antigo->nome);
        $this->assertSame('MLB1', $antigo->categoria_ml_id);
        $this->assertSame('Cristaleiras', $antigo->categoria_ml_nome);
        $this->assertSame('Casa > Cristaleiras', $antigo->categoria_ml_caminho);
        $this->assertSame('Farmhouse', $antigo->familia?->nome);
        $this->assertSame(['B1'], EstruturaProdutoVariacao::where('produto_id', $antigo->id)->pluck('codigo')->all());
        $this->assertSame(1, EstruturaProduto::where('company_id', $empresa->id)->count());
        $this->assertSame(['Mesa Antiga'], EstruturaOferta::where('company_id', $empresa->id)->whereNotNull('variacao_id')->pluck('nome')->all());

        // As linhas do MESMO lote continuam se juntando pelo grupo (produto novo, código livre).
        $r = $this->gravar($sessao, [
            ['chave' => 'p1', 'grupo' => 'P1', 'codigo' => 'P1', 'nome' => 'Poltrona', 'valor' => 'Azul'],
            ['chave' => 'p2', 'grupo' => 'P1', 'codigo' => 'P2', 'nome' => 'Poltrona', 'valor' => 'Verde'],
        ])->assertOk();
        $this->assertSame(2, $r->json('totais.criadas'));
        $this->assertSame(2, EstruturaProduto::where('company_id', $empresa->id)->count());
        $this->assertSame(1, count(array_unique(array_column($r->json('linhas'), 'produto_id'))));
    }

    /**
     * Contrato da ficha (FE-CR-03, BE-IN-05): `null` explícito em eixo, valor, família e custo
     * LIMPA o campo; texto vazio e chave ausente não mexem — também pelo HTTP, onde o middleware
     * global transformaria `''` em `null`.
     */
    public function test_nulo_explicito_limpa_eixo_valor_familia_e_custo_e_texto_vazio_nao_mexe(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $this->gravar($sessao, [[
            'chave' => 'k', 'codigo' => 'LMP-1', 'nome' => 'Mesa', 'eixo' => 'cor', 'valor' => 'Natural',
            'familia' => 'Farmhouse', 'custo' => '100,00', 'volumes' => $this->vol(),
        ]])->assertOk();
        $v = EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();
        $oferta = fn () => EstruturaOferta::where('variacao_id', $v->id)->firstOrFail();
        $this->assertSame('Mesa — Natural', $oferta()->nome);

        // Texto vazio = não mexeu (nada muda, nem pelo ConvertEmptyStringsToNull).
        $r = $this->gravar($sessao, [['id' => $v->id, 'codigo' => 'LMP-1', 'nome' => 'Mesa', 'eixo' => '', 'valor' => '', 'familia' => '', 'custo' => '']])->assertOk();
        $this->assertSame(1, $r->json('totais.sem_mudanca'));
        $v->refresh();
        $this->assertSame('cor', $v->eixo);
        $this->assertSame('Natural', $v->valor);
        $this->assertSame(100.0, $v->custo);
        $this->assertSame('Farmhouse', $v->produto->familia?->nome);

        // null explícito = limpar.
        $r = $this->gravar($sessao, [['id' => $v->id, 'codigo' => 'LMP-1', 'nome' => 'Mesa', 'eixo' => null, 'valor' => null, 'familia' => null, 'custo' => null]])->assertOk();
        $this->assertSame([], $r->json('erros'));
        $this->assertSame(1, $r->json('totais.atualizadas'));
        $v->refresh();
        $this->assertNull($v->eixo);
        $this->assertNull($v->valor);
        $this->assertNull($v->custo);
        $this->assertNull($v->produto->fresh()->familia_id);
        $this->assertSame(1, EstruturaFamilia::where('company_id', $empresa->id)->count(), 'a família continua na lista da empresa');
        $this->assertSame('Mesa', $oferta()->nome, 'sem valor, a oferta se chama só pelo produto');
        $this->assertNull($r->json('linhas.0.valor'));
        $this->assertNull($r->json('linhas.0.eixo'));
        $this->assertNull($r->json('linhas.0.familia'));
    }

    /** Fase 172-02: estoque por variação — 0 e vazio são coisas diferentes e variação nova não herda. */
    public function test_estoque_por_variacao_zero_nulo_e_sem_heranca(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $r = $this->gravar($sessao, [[
            'chave' => 'k', 'codigo' => 'EST-1', 'nome' => 'Mesa', 'eixo' => 'cor', 'valor' => 'Natural',
            'volumes' => $this->vol(), 'estoque' => '12',
        ]])->assertOk();
        $this->assertSame(12, $r->json('linhas.0.estoque'));
        $v1 = EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();
        $this->assertSame(12, $v1->estoque);

        // Variação nova do mesmo produto, sem estoque informado: NÃO herda os 12.
        $r = $this->gravar($sessao, [[
            'chave' => 'k2', 'produto_id' => $v1->produto_id, 'codigo' => 'EST-2', 'nome' => 'Mesa', 'eixo' => 'cor', 'valor' => 'Preto',
        ]])->assertOk();
        $this->assertSame([], $r->json('erros'));
        $v2 = EstruturaProdutoVariacao::where('company_id', $empresa->id)->where('codigo', 'EST-2')->firstOrFail();
        $this->assertNull($v2->estoque);

        // Atualização sem a chave mantém; 0 grava 0; vazio não mexe; null limpa.
        $this->gravar($sessao, [['id' => $v1->id, 'codigo' => 'EST-1', 'nome' => 'Mesa']])->assertOk();
        $this->assertSame(12, $v1->fresh()->estoque);

        $r = $this->gravar($sessao, [['id' => $v1->id, 'codigo' => 'EST-1', 'nome' => 'Mesa', 'estoque' => 0]])->assertOk();
        $this->assertSame(0, $v1->fresh()->estoque);
        $this->assertSame(0, $r->json('linhas.0.estoque'));

        $this->gravar($sessao, [['id' => $v1->id, 'codigo' => 'EST-1', 'nome' => 'Mesa', 'estoque' => '']])->assertOk();
        $this->assertSame(0, $v1->fresh()->estoque);

        $this->gravar($sessao, [['id' => $v1->id, 'codigo' => 'EST-1', 'nome' => 'Mesa', 'estoque' => null]])->assertOk();
        $this->assertNull($v1->fresh()->estoque);

        // Inválido vira erro da linha, sem gravar.
        $r = $this->gravar($sessao, [['chave' => 'x', 'id' => $v1->id, 'codigo' => 'EST-1', 'nome' => 'Mesa', 'estoque' => '-3']])->assertOk();
        $this->assertSame(1, $r->json('totais.com_erro'));
    }

    public function test_201_linhas_ou_nenhuma_dao_422(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());

        $muitas = array_map(fn ($i) => ['codigo' => "X-{$i}", 'nome' => 'X'], range(1, 201));
        $this->gravar($sessao, $muitas)->assertStatus(422)->assertJsonValidationErrors('linhas');
        $this->gravar($sessao, [])->assertStatus(422);
        $this->assertSame(0, EstruturaProduto::count());
    }

    public function test_excluir_variacao_sem_anuncios_responde_a_mensagem_simples(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $this->gravar($sessao, [['codigo' => 'EXC-1', 'nome' => 'Excluir']])->assertOk();
        $variacao = EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();

        $r = $sessao->deleteJson(route('portal.auth.estrutura.produtos.variacoes.excluir', $variacao->id))->assertOk();

        $this->assertSame('Variação EXC-1 excluída.', $r->json('mensagem'));
        $this->assertTrue($r->json('produto_excluido'));
        $this->assertSame(0, EstruturaProdutoVariacao::count());
    }

    public function test_excluir_variacao_com_anuncios_avisa_que_voltaram_para_a_espera(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $this->gravar($sessao, [['codigo' => 'ANU-1', 'nome' => 'Com anúncio']])->assertOk();
        $variacao = EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();
        $oferta = EstruturaOferta::where('variacao_id', $variacao->id)->firstOrFail();
        app(EstruturaAnuncioService::class)->cadastrar($oferta, [
            'tipo' => 'classico', 'codigo_mlb' => 'MLB0000000099', 'titulo' => 'Anúncio', 'catalogo' => false, 'status' => 'ativo',
        ], $this->atorCliente($empresa));

        $r = $sessao->deleteJson(route('portal.auth.estrutura.produtos.variacoes.excluir', $variacao->id))->assertOk();

        $this->assertSame(1, $r->json('anuncios_para_espera'));
        $this->assertStringContainsString('1 anúncio(s) voltaram para a área de espera.', $r->json('mensagem'));
    }

    public function test_excluir_variacao_componente_de_combo_da_422_com_o_combo_na_mensagem(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $this->gravar($sessao, [['codigo' => 'BASE-1', 'nome' => 'Base']])->assertOk();
        $variacao = EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();
        $base = EstruturaOferta::where('variacao_id', $variacao->id)->firstOrFail();
        app(EstruturaOfertaService::class)->criar($empresa, [
            'sku' => 'BASE-1-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Base', 'logistica' => 'mercado_envios',
            'componentes' => [['id' => $base->id, 'quantidade' => 2]],
        ], $this->atorCliente($empresa));

        $sessao->deleteJson(route('portal.auth.estrutura.produtos.variacoes.excluir', $variacao->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('oferta')
            ->assertJsonPath('errors.oferta.0', fn ($m) => str_contains($m, 'BASE-1-CB2'));

        $this->assertSame(1, EstruturaProdutoVariacao::where('company_id', $empresa->id)->count());
    }

    public function test_familia_criada_com_outra_grafia_devolve_o_item_existente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);

        $a = $sessao->postJson(route('portal.auth.estrutura.produtos.familias.criar'), ['nome' => 'Farmhouse'])->assertOk();
        $this->assertTrue($a->json('criado'));

        $b = $sessao->postJson(route('portal.auth.estrutura.produtos.familias.criar'), ['nome' => '  farmhouse '])->assertOk();
        $this->assertFalse($b->json('criado'));
        $this->assertSame($a->json('item.id'), $b->json('item.id'));
        $this->assertSame(1, EstruturaFamilia::where('company_id', $empresa->id)->count());
    }

    public function test_nome_de_lista_com_barra_da_422(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());

        $sessao->postJson(route('portal.auth.estrutura.produtos.familias.criar'), ['nome' => 'Sala/Quarto'])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
        $sessao->postJson(route('portal.auth.estrutura.produtos.ambientes.criar'), ['nome' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('nome');
    }

    public function test_renomear_e_excluir_ambiente_da_propria_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $id = $sessao->postJson(route('portal.auth.estrutura.produtos.ambientes.criar'), ['nome' => 'Sala'])->json('item.id');

        $r = $sessao->putJson(route('portal.auth.estrutura.produtos.ambientes.renomear', $id), ['nome' => 'Sala de estar'])->assertOk();
        $this->assertSame('Sala de estar', $r->json('item.nome'));

        $sessao->deleteJson(route('portal.auth.estrutura.produtos.ambientes.excluir', $id))->assertOk()->assertJsonPath('listas.ambientes', []);
        $this->assertSame(0, EstruturaAmbiente::where('company_id', $empresa->id)->count());
    }

    // ═══ Modelo e importação (D-13) ═════════════════════════════════════════

    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            @unlink($arquivo);
        }
        parent::tearDown();
    }

    /** Planilha sintética gravada em disco com a extensão pedida. */
    private function planilha(array $linhas, string $extensao = 'xlsx'): UploadedFile
    {
        $planilha = new Spreadsheet();
        $planilha->getActiveSheet()->setTitle('Produtos');
        foreach ($linhas as $r => $celulas) {
            foreach (array_values($celulas) as $c => $valor) {
                if ($valor !== null) {
                    $planilha->getActiveSheet()->setCellValueExplicit(
                        \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1).($r + 1),
                        (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                    );
                }
            }
        }
        $caminho = tempnam(sys_get_temp_dir(), 'prod167').'.xlsx';
        IOFactory::createWriter($planilha, 'Xlsx')->save($caminho);
        $this->temporarios[] = $caminho;

        // Nome do cliente com a extensão pedida; o conteúdo é o mesmo zip.
        return new UploadedFile($caminho, "produtos.{$extensao}", null, null, true);
    }

    public function test_modelo_baixa_o_xlsx_com_os_11_cabecalhos(): void
    {
        $r = $this->entrarNoPortal($this->empresaDoGabarito())
            ->get(route('portal.auth.estrutura.produtos.modelo'))
            ->assertOk()
            ->assertDownload('modelo-produtos.xlsx');

        $caminho = tempnam(sys_get_temp_dir(), 'mod167').'.xlsx';
        $this->temporarios[] = $caminho;
        file_put_contents($caminho, $r->streamedContent());

        $folha = IOFactory::load($caminho)->getSheet(0);
        $cabecalhos = [];
        for ($c = 1; $c <= 11; $c++) {
            $cabecalhos[] = (string) $folha->getCell([$c, 1])->getValue();
        }
        $this->assertSame(ModeloProdutosXlsx::CABECALHOS, $cabecalhos);
    }

    public function test_previa_da_importacao_nao_grava_nada(): void
    {
        $empresa = $this->empresaDoGabarito();
        $arquivo = $this->planilha([ModeloProdutosXlsx::CABECALHOS, ['N1', null, null, 'Novo Um'], ['N2', null, null, 'Novo Dois']]);

        $r = $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao.previa'), ['arquivo' => $arquivo], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertNull($r->json('erro_geral'));
        $this->assertSame(2, $r->json('totais.novos'));
        $this->assertSame(0, EstruturaProduto::count());
    }

    public function test_previa_recusa_xlsm_csv_e_arquivo_grande(): void
    {
        $sessao = $this->entrarNoPortal($this->empresaDoGabarito());
        $url = route('portal.auth.estrutura.produtos.importacao.previa');
        $json = ['Accept' => 'application/json'];

        $sessao->post($url, ['arquivo' => $this->planilha([['Ref', 'Produto'], ['A', 'B']], 'xlsm')], $json)
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $sessao->post($url, ['arquivo' => UploadedFile::fake()->createWithContent('produtos.csv', "Ref;Produto\nA;B\n")], $json)
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $sessao->post($url, ['arquivo' => UploadedFile::fake()->create('produtos.xlsx', 2049, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')], $json)
            ->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_aplicar_a_importacao_grava_e_avisa_pelo_flash_success(): void
    {
        $empresa = $this->empresaDoGabarito();
        $arquivo = $this->planilha([ModeloProdutosXlsx::CABECALHOS, ['N1', null, null, 'Novo Um'], ['N2', null, null, 'Novo Dois']]);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao'), ['arquivo' => $arquivo])
            ->assertRedirect()
            ->assertSessionHas('success', 'Importação concluída: 2 novos, 0 atualizados.');

        $this->assertSame(2, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    /** BE-WR-04: o flash diz quantas e quais linhas ficaram de fora. */
    public function test_aplicar_conta_no_flash_as_linhas_que_nao_entraram(): void
    {
        $empresa = $this->empresaDoGabarito();
        $arquivo = $this->planilha([
            ModeloProdutosXlsx::CABECALHOS,
            ['N1', null, null, 'Novo Um'],
            ['X9', null, null, 'Custo ruim', null, null, null, null, null, null, 'abc'],
            ['n1', null, null, 'Repetido'],
        ]);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao'), ['arquivo' => $arquivo])
            ->assertRedirect()
            ->assertSessionHas('success', 'Importação concluída: 1 novos, 0 atualizados. 2 linhas não entraram: '
                .'linha 3 (X9): Use só números. Exemplo: 27,8; linha 4 (n1): A Ref n1 já está na linha 2 do arquivo. Deixe uma linha só para cada Ref.');

        $this->assertSame(1, EstruturaProduto::where('company_id', $empresa->id)->count());
    }

    public function test_aplicar_com_erro_geral_volta_com_o_erro_em_arquivo(): void
    {
        $empresa = $this->empresaDoGabarito();
        // Sem a coluna Ref: o leitor devolve a mensagem fixa.
        $arquivo = $this->planilha([['Coisa', 'Outra'], ['a', 'b']]);

        $this->entrarNoPortal($empresa)
            ->post(route('portal.auth.estrutura.produtos.importacao'), ['arquivo' => $arquivo])
            ->assertRedirect()
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, EstruturaProduto::count());
    }

    // ═══ Fretes (D-16) ══════════════════════════════════════════════════════

    private function umaVariacaoComVolumes($sessao, $empresa): EstruturaProdutoVariacao
    {
        $this->gravar($sessao, [['codigo' => 'FRT-1', 'nome' => 'Frete', 'volumes' => $this->vol(), 'custo' => '100,00']])->assertOk();

        return EstruturaProdutoVariacao::where('company_id', $empresa->id)->firstOrFail();
    }

    public function test_fretes_sem_conta_conectada_nao_faz_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $v = $this->umaVariacaoComVolumes($sessao, $empresa);
        Http::fake();

        $r = $sessao->postJson(route('portal.auth.estrutura.produtos.fretes'), ['variacao_ids' => [$v->id]])->assertOk();

        $this->assertFalse($r->json('conectado'));
        Http::assertNothingSent();
    }

    public function test_fretes_com_conta_cotam_pela_api_e_nao_gravam_precificacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $sessao = $this->entrarNoPortal($empresa);
        $v = $this->umaVariacaoComVolumes($sessao, $empresa);
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
        Http::fake(fn (Request $r) => Http::response(['coverage' => ['all_country' => ['list_cost' => 23.45]]]));
        $antes = EstruturaPrecificacao::count();

        $r = $sessao->postJson(route('portal.auth.estrutura.produtos.fretes'), ['variacao_ids' => [$v->id]])->assertOk();

        $this->assertTrue($r->json('conectado'));
        $this->assertSame('api', $r->json("fretes.{$v->id}.origem"));
        $this->assertEquals(23.45, $r->json("fretes.{$v->id}.valor"));
        $this->assertSame(0, $r->json('pendentes'));
        $this->assertSame($antes, EstruturaPrecificacao::count());
        $this->assertStringNotContainsString('fake-access-token', $r->getContent());
    }
}
