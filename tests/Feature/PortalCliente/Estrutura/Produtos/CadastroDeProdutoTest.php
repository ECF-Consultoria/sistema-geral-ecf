<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\EstruturaProdutoVolume;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-06: gravação do catálogo por linha, igual para grade e planilha.
 *
 * Modos de falha que estes testes impedem: 70 linhas coladas e só algumas
 * gravadas (erro de uma linha derrubando as outras, ou sumindo calado); código
 * repetido virando 500 no MariaDB (o unique ignora caixa e acento, o SQLite
 * não); categoria aceita sozinha (texto virando id) ou não-folha gravada;
 * produto de outra empresa sendo alterado por id forjado.
 */
class CadastroDeProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);

        Http::fake(function (Request $r) {
            if (preg_match('#/categories/(MLB\d+)$#', $r->url(), $m)) {
                return match ($m[1]) {
                    'MLB1' => Http::response([
                        'id' => 'MLB1', 'name' => 'Cristaleiras', 'children_categories' => [],
                        'path_from_root' => [['id' => 'MLB0', 'name' => 'Casa'], ['id' => 'MLB9', 'name' => 'Moveis'], ['id' => 'MLB1', 'name' => 'Cristaleiras']],
                    ]),
                    'MLB2' => Http::response([
                        'id' => 'MLB2', 'name' => 'Moveis', 'children_categories' => [['id' => 'MLB1', 'name' => 'Cristaleiras']],
                        'path_from_root' => [['id' => 'MLB2', 'name' => 'Moveis']],
                    ]),
                    default => Http::response(['message' => 'erro'], 500),
                };
            }

            return Http::response([], 404);
        });
    }

    private function svc(): ProdutoCadastroService
    {
        return app(ProdutoCadastroService::class);
    }

    private function vol(): array
    {
        return [['c' => 93, 'l' => 55, 'a' => 6, 'kg' => 9.5]];
    }

    private function requisicoesDeCategoria(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/categories/'))->count();
    }

    public function test_mesmo_grupo_cria_um_produto_e_duas_variacoes_com_volumes_e_ecoa_a_chave(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->svc()->gravarLinhas($empresa, [
            ['chave' => 'k1', 'grupo' => '1014', 'codigo' => '1014-1', 'nome' => 'Cristaleira', 'variacao' => '1', 'volumes' => $this->vol(), 'custo' => '100,50'],
            ['chave' => 'k2', 'grupo' => '1014', 'codigo' => '1014-2', 'nome' => 'Cristaleira', 'variacao' => '2', 'volumes_texto' => "10\u{00D7}10\u{00D7}10 \u{00B7} 2.5"],
        ], $this->atorCliente($empresa));

        $this->assertSame(1, EstruturaProduto::where('company_id', $empresa->id)->count());
        $this->assertSame('1014', EstruturaProduto::first()->codigo);
        $this->assertSame(2, EstruturaProdutoVariacao::count());
        $this->assertSame(2, EstruturaProdutoVolume::count());
        $this->assertSame(['criadas' => 2, 'atualizadas' => 0, 'sem_mudanca' => 0, 'com_erro' => 0], $r['totais']);
        $this->assertSame(['k1', 'k2'], array_column($r['linhas'], 'chave'));
        $this->assertSame([1, 2], array_column($r['linhas'], 'ordem'));
        $this->assertSame(100.5, $r['linhas'][0]['custo']);
    }

    public function test_linha_sem_grupo_cria_produto_proprio_com_o_codigo_da_variacao(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->svc()->gravarLinhas($empresa, [['codigo' => 'SOLO-1', 'nome' => 'Solo']], $this->atorCliente($empresa));

        $this->assertSame('SOLO-1', EstruturaProduto::first()->codigo);
        $this->assertSame(1, EstruturaProdutoVariacao::count());
    }

    public function test_grade_recusa_codigo_repetido_por_caixa_e_acento_sem_derrubar_as_outras_linhas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        $this->svc()->gravarLinhas($empresa, [
            ['codigo' => 'ABC-1', 'nome' => 'A'],
            ['codigo' => 'AÇO-1', 'nome' => 'B'],
        ], $ator);

        $r = $this->svc()->gravarLinhas($empresa, [
            ['chave' => 'x1', 'codigo' => 'abc-1', 'nome' => 'A2'],
            ['chave' => 'x2', 'codigo' => 'aco-1', 'nome' => 'B2'],
            ['chave' => 'x3', 'codigo' => 'NOVO-1', 'nome' => 'C'],
            ['chave' => 'x4', 'codigo' => 'novo-1', 'nome' => 'C2'],
        ], $ator);

        $this->assertSame(['abc-1', 'aco-1', 'novo-1'], array_column($r['erros'], 'codigo'));
        $this->assertSame('O código abc-1 já existe em outro produto. Use outro código.', $r['erros'][0]['mensagem']);
        $this->assertSame('x1', $r['erros'][0]['chave']);
        $this->assertSame(1, $r['totais']['criadas']);
        $this->assertSame(3, $r['totais']['com_erro']);
        $this->assertSame(3, EstruturaProdutoVariacao::where('company_id', $empresa->id)->count());

        // Outra empresa pode usar o mesmo código.
        $r2 = $this->svc()->gravarLinhas($outra, [['codigo' => 'ABC-1', 'nome' => 'A']], $this->atorCliente($outra));
        $this->assertSame([], $r2['erros']);
        $this->assertSame(1, $r2['totais']['criadas']);
    }

    public function test_importacao_atualiza_pelo_codigo_e_classifica_sem_mudanca(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $linha = ['grupo' => 'G1', 'codigo' => 'G1-1', 'nome' => 'Mesa', 'custo' => 50, 'volumes' => $this->vol(), 'familia' => 'Farmhouse'];

        $this->svc()->gravarLinhas($empresa, [$linha], $ator, ProdutoCadastroService::MODO_IMPORTACAO);
        $variacao = EstruturaProdutoVariacao::first();
        $variacao->forceFill(['updated_at' => '2020-01-01 00:00:00'])->saveQuietly();

        $r = $this->svc()->gravarLinhas($empresa, [$linha], $ator, ProdutoCadastroService::MODO_IMPORTACAO);
        $this->assertSame(['criadas' => 0, 'atualizadas' => 0, 'sem_mudanca' => 1, 'com_erro' => 0], $r['totais']);
        $this->assertSame('2020-01-01 00:00:00', $variacao->fresh()->updated_at->format('Y-m-d H:i:s'));

        // Custo novo muda só aquela variação; o que a linha não trouxe (volumes) fica.
        $r = $this->svc()->gravarLinhas($empresa, [['codigo' => 'g1-1', 'nome' => 'Mesa', 'custo' => '75,25']], $ator, ProdutoCadastroService::MODO_IMPORTACAO);
        $this->assertSame(1, $r['totais']['atualizadas']);
        $this->assertSame(1, EstruturaProdutoVariacao::count());
        $this->assertSame(75.25, $variacao->fresh()->custo);
        $this->assertSame(1, EstruturaProdutoVolume::count());
    }

    public function test_variacao_nova_de_produto_existente_copia_da_primeira_e_o_enviado_vale(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        $this->svc()->gravarLinhas($empresa, [
            ['grupo' => 'G', 'codigo' => 'G-1', 'nome' => 'Mesa', 'eixo' => 'cor', 'valor' => 'Natural', 'custo' => 80, 'volumes' => $this->vol()],
        ], $ator);
        $produto = EstruturaProduto::first();

        $this->svc()->gravarLinhas($empresa, [
            ['produto_id' => $produto->id, 'codigo' => 'G-2', 'nome' => 'Mesa', 'valor' => 'Preto'],
        ], $ator);
        $copiada = EstruturaProdutoVariacao::where('codigo', 'G-2')->first();
        $this->assertSame('cor', $copiada->eixo);
        $this->assertSame(80.0, $copiada->custo);
        $this->assertSame('Preto', $copiada->valor);
        $this->assertSame(2, $copiada->ordem);
        $this->assertSame([9.5], $copiada->volumes->pluck('peso')->all());

        $this->svc()->gravarLinhas($empresa, [
            ['produto_id' => $produto->id, 'codigo' => 'G-3', 'nome' => 'Mesa', 'custo' => 10, 'volumes' => [['c' => 1, 'l' => 2, 'a' => 3, 'kg' => 4]]],
        ], $ator);
        $propria = EstruturaProdutoVariacao::where('codigo', 'G-3')->first();
        $this->assertSame(10.0, $propria->custo);
        $this->assertSame([4.0], $propria->volumes->pluck('peso')->all());
    }

    public function test_renomear_o_produto_devolve_todas_as_variacoes_dele(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);

        $this->svc()->gravarLinhas($empresa, [
            ['grupo' => 'G', 'codigo' => 'G-1', 'nome' => 'Mesa'],
            ['grupo' => 'G', 'codigo' => 'G-2', 'nome' => 'Mesa'],
        ], $ator);
        $v = EstruturaProdutoVariacao::where('codigo', 'G-1')->first();

        $r = $this->svc()->gravarLinhas($empresa, [['id' => $v->id, 'codigo' => 'G-1', 'nome' => 'Mesa Nova']], $ator);

        $this->assertSame('Mesa Nova', EstruturaProduto::first()->nome);
        $this->assertSame(1, $r['totais']['atualizadas']);
        $this->assertCount(2, $r['linhas']);
        $this->assertSame(['Mesa Nova', 'Mesa Nova'], array_column($r['linhas'], 'nome'));
    }

    public function test_linhas_do_mesmo_produto_divergentes_avisam_e_vale_a_primeira(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->svc()->gravarLinhas($empresa, [
            ['grupo' => 'G', 'codigo' => 'G-1', 'nome' => 'Mesa'],
            ['grupo' => 'G', 'codigo' => 'G-2', 'nome' => 'Outro nome'],
        ], $this->atorCliente($empresa));

        $this->assertSame('Mesa', EstruturaProduto::first()->nome);
        $this->assertSame(['Produto G: usamos o nome da primeira linha.'], $r['avisos']);
    }

    public function test_familia_e_ambientes_casam_sem_caixa_e_acento_e_os_novos_entram_na_lista(): void
    {
        $empresa = $this->empresaDoGabarito();
        EstruturaFamilia::create(['company_id' => $empresa->id, 'nome' => 'Farmhouse']);
        EstruturaAmbiente::create(['company_id' => $empresa->id, 'nome' => 'Sala Estar']);

        $r = $this->svc()->gravarLinhas($empresa, [
            ['codigo' => 'A-1', 'nome' => 'A', 'familia' => 'farmhouse', 'ambientes' => 'sala estar / Hall'],
            ['codigo' => 'B-1', 'nome' => 'B', 'familia' => 'Palhinha Slim'],
        ], $this->atorCliente($empresa));

        $this->assertSame(2, EstruturaFamilia::where('company_id', $empresa->id)->count());
        $this->assertSame(['Palhinha Slim'], $r['criadas_nas_listas']['familias']);
        $this->assertSame(['Hall'], $r['criadas_nas_listas']['ambientes']);
        $this->assertSame('Farmhouse', $r['linhas'][0]['familia']);
        $this->assertSame(['Hall', 'Sala Estar'], $r['linhas'][0]['ambientes']);
    }

    public function test_categoria_folha_grava_id_nome_e_caminho_e_a_mesma_categoria_gera_uma_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $linhas = [];
        for ($i = 1; $i <= 10; $i++) {
            $linhas[] = ['codigo' => "C-{$i}", 'nome' => "Produto {$i}", 'categoria_ml_id' => 'mlb1'];
        }

        $r = $this->svc()->gravarLinhas($empresa, $linhas, $this->atorCliente($empresa));

        $p = EstruturaProduto::first();
        $this->assertSame('MLB1', $p->categoria_ml_id);
        $this->assertSame('Cristaleiras', $p->categoria_ml_nome);
        $this->assertSame('Casa > Moveis > Cristaleiras', $p->categoria_ml_caminho);
        $this->assertSame('confirmada', $r['linhas'][0]['categoria_estado']);
        $this->assertSame(1, $this->requisicoesDeCategoria());
    }

    public function test_categoria_que_nao_e_folha_e_recusada_e_a_linha_nao_grava(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->svc()->gravarLinhas($empresa, [
            ['codigo' => 'A-1', 'nome' => 'A', 'categoria_ml_id' => 'MLB2'],
            ['codigo' => 'B-1', 'nome' => 'B', 'categoria_ml_id' => 'MLB1'],
        ], $this->atorCliente($empresa));

        $this->assertSame('Escolha uma categoria mais específica (a última do caminho).', $r['erros'][0]['mensagem']);
        $this->assertSame(1, $r['totais']['criadas']);
        $this->assertSame(0, EstruturaProduto::where('nome', 'A')->count());
    }

    public function test_ml_fora_do_ar_grava_so_o_id_e_texto_colado_fica_a_confirmar(): void
    {
        $empresa = $this->empresaDoGabarito();

        $r = $this->svc()->gravarLinhas($empresa, [
            ['codigo' => 'A-1', 'nome' => 'A', 'categoria_ml_id' => 'MLB999'],
            ['codigo' => 'B-1', 'nome' => 'B', 'categoria_texto' => 'Cristaleiras'],
        ], $this->atorCliente($empresa));

        $this->assertSame([], $r['erros']);
        $this->assertSame('nao_validada', $r['linhas'][0]['categoria_estado']);
        $this->assertSame('MLB999', $r['linhas'][0]['categoria_ml_id']);
        $this->assertSame('a_confirmar', $r['linhas'][1]['categoria_estado']);
        $this->assertNull($r['linhas'][1]['categoria_ml_id']);
        $this->assertStringContainsString('MLB999', $r['avisos'][0]);
    }

    public function test_id_de_outra_empresa_e_recusado_igual_a_inexistente_e_nada_muda(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $this->svc()->gravarLinhas($b, [['codigo' => 'B-1', 'nome' => 'Da B']], $this->atorCliente($b));
        $produtoB = EstruturaProduto::where('company_id', $b->id)->first();
        $variacaoB = EstruturaProdutoVariacao::where('company_id', $b->id)->first();

        $r = $this->svc()->gravarLinhas($a, [
            ['produto_id' => $produtoB->id, 'codigo' => 'X-1', 'nome' => 'Invasor'],
            ['id' => $variacaoB->id, 'codigo' => 'B-1', 'nome' => 'Invasor'],
            ['id' => 999999, 'codigo' => 'Z-1', 'nome' => 'Inexistente'],
        ], $this->atorCliente($a));

        $this->assertSame(['Produto não encontrado.', 'Produto não encontrado.', 'Produto não encontrado.'], array_column($r['erros'], 'mensagem'));
        $this->assertSame('Da B', $produtoB->fresh()->nome);
        $this->assertSame(0, EstruturaProdutoVariacao::where('company_id', $a->id)->count());
        $this->assertSame(1, EstruturaProdutoVariacao::where('company_id', $b->id)->count());
    }

    public function test_company_id_dentro_da_linha_e_ignorado(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();

        $this->svc()->gravarLinhas($a, [['company_id' => $b->id, 'codigo' => 'A-1', 'nome' => 'A']], $this->atorCliente($a));

        $this->assertSame($a->id, EstruturaProduto::first()->company_id);
        $this->assertSame($a->id, EstruturaProdutoVariacao::first()->company_id);
    }

    public function test_mais_de_200_linhas_na_grade_e_recusado_inteiro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $linhas = [];
        for ($i = 1; $i <= 201; $i++) {
            $linhas[] = ['codigo' => "L-{$i}", 'nome' => "P{$i}"];
        }

        try {
            $this->svc()->gravarLinhas($empresa, $linhas, $this->atorCliente($empresa));
            $this->fail('Esperava ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('linhas', $e->errors());
        }

        $this->assertSame(0, EstruturaProduto::count());
    }

    public function test_o_lote_gera_um_registro_com_origem_cliente_ou_interno(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->svc()->gravarLinhas($empresa, [
            ['codigo' => 'A-1', 'nome' => 'A'], ['codigo' => 'B-1', 'nome' => 'B'],
        ], $this->atorCliente($empresa));
        $this->svc()->gravarLinhas($empresa, [['codigo' => 'C-1', 'nome' => 'C']], AtorDoPortal::daEquipe(User::factory()->create()));
        // Nada gravado = nada registrado.
        $this->svc()->gravarLinhas($empresa, [['codigo' => '', 'nome' => 'sem codigo']], $this->atorCliente($empresa));

        $logs = Activity::where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn ($l) => $l->getExtraProperty('evento') === 'produtos_gravados')->values();

        $this->assertCount(2, $logs);
        $this->assertSame(['cliente', 'interno'], $logs->map(fn ($l) => $l->getExtraProperty('origem'))->all());
        $this->assertSame(2, $logs[0]->getExtraProperty('totais')['criadas']);
    }
}
