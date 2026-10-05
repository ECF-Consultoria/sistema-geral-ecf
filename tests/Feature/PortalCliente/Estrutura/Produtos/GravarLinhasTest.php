<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\EstruturaAmbiente;
use App\Models\EstruturaFamilia;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Services\Portal\Estrutura\EstruturaAnuncioService;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
        // Nenhuma chamada real ao ML: categoria nunca é pedida nestes testes.
        Cache::put('ml_app_token_coleta', 'app-token-teste', 3600);
        Http::fake(['*' => Http::response([], 404)]);
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
}
