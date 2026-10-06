<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\User;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Portal\PortalEquipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-19 / D-27: a ficha do produto em página inteira — quem entra, as
 * props e o isolamento entre empresas.
 */
class FichaDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true,
        ]);
    }

    private function entrarComoEquipe(User $membro, Company $empresa): static
    {
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]));

        return $this;
    }

    /** Grava pelo serviço (uma sessão HTTP só vale para uma empresa por teste). */
    private function gravarProdutoDuasVariacoes(Company $empresa): int
    {
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [
            ['chave' => 'a', 'grupo' => 'CAD', 'codigo' => 'CAD-1', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Natural'],
            ['chave' => 'b', 'grupo' => 'CAD', 'codigo' => 'CAD-2', 'nome' => 'Cadeira Teste', 'variacao' => 'Cor: Preto'],
        ], $this->atorCliente($empresa));

        return (int) EstruturaProduto::where('company_id', $empresa->id)->firstOrFail()->id;
    }

    public function test_sem_sessao_as_duas_rotas_mandam_para_a_entrada_do_portal(): void
    {
        $this->get('/portal/estrutura/produtos/novo')->assertRedirect();
        $this->get('/portal/estrutura/produtos/12')->assertRedirect();
    }

    public function test_o_cliente_abre_a_ficha_de_produto_novo(): void
    {
        $this->withoutVite()->entrarNoPortal($this->empresaDoGabarito())
            ->get(route('portal.auth.estrutura.produtos.novo'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaProdutoFicha', false)
                ->where('produto', null)
                ->where('linhas', [])
                ->has('listas.familias')
                ->has('listas.ambientes')
                ->has('vocabulario.eixos')
                ->has('vocabulario.logisticas')
                ->has('vocabulario.pendencias')
                ->where('ml_conectado', false)
                ->has('frete_tabela')
                ->where('limites.colar', 200)
                ->where('modulos', fn ($m) => collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('ativo', true)['chave'] === 'produtos')
            );
    }

    public function test_o_cliente_abre_a_ficha_do_proprio_produto_com_as_variacoes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $id = $this->gravarProdutoDuasVariacoes($empresa);

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos.ficha', $id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaProdutoFicha', false)
                ->where('produto.id', $id)
                ->where('produto.nome', 'Cadeira Teste')
                ->has('linhas', 2)
                ->where('linhas.0.produto_id', $id)
                ->where('linhas.1.produto_id', $id)
                ->where('linhas.0.primeira', true)
                ->has('linhas.0.logistica')
                ->has('linhas.0.frete')
                ->has('linhas.0.pendencias')
                ->has('linhas.0.oferta')
                ->has('linhas.0.volumes')
                ->has('linhas.0.n_volumes')
                ->has('linhas.0.peso_total')
            );
    }

    public function test_produto_de_outra_empresa_responde_404(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $idAlheio = $this->gravarProdutoDuasVariacoes($outra);

        $this->withoutVite()->entrarNoPortal($minha)
            ->get('/portal/estrutura/produtos/'.$idAlheio)
            ->assertNotFound();
    }

    public function test_id_inexistente_e_caminho_nao_numerico_respondem_404(): void
    {
        $sessao = $this->withoutVite()->entrarNoPortal($this->empresaDoGabarito());

        $sessao->get('/portal/estrutura/produtos/999999')->assertNotFound();
        $sessao->get('/portal/estrutura/produtos/abc')->assertNotFound();
    }

    public function test_a_equipe_abre_a_ficha(): void
    {
        $empresa = $this->empresaDoGabarito();
        $id = $this->gravarProdutoDuasVariacoes($empresa);

        $this->withoutVite()->entrarComoEquipe($this->admin(), $empresa)
            ->get(route('portal.auth.estrutura.produtos.ficha', $id))
            ->assertOk();
    }

    public function test_o_router_nao_deixa_a_ficha_engolir_as_irmas(): void
    {
        $rotas = app('router')->getRoutes();
        $acao = fn (string $caminho) => $rotas->match(Request::create($caminho, 'GET'))->getActionMethod();

        $this->assertSame('modelo', $acao('/portal/estrutura/produtos/modelo'));
        $this->assertSame('buscarCategorias', $acao('/portal/estrutura/produtos/categorias'));
        $this->assertSame('novo', $acao('/portal/estrutura/produtos/novo'));
        $this->assertSame('ficha', $acao('/portal/estrutura/produtos/12'));
    }

    public function test_a_allowlist_libera_so_o_id_numerico_e_o_novo(): void
    {
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/12'));
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/novo'));
        $this->assertTrue(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/modelo'));

        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/12/x'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/abc'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/12x'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/produtos/'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/usuarios'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('portal/estrutura/qualquer-outra'));
    }

    public function test_no_dominio_do_cliente_o_id_passa_e_o_subcaminho_e_barrado(): void
    {
        config(['portal.dominio_cliente' => 'cliente.teste']);

        $this->get('http://cliente.teste/portal/estrutura/produtos/12')->assertRedirect();
        $this->get('http://cliente.teste/portal/estrutura/produtos/12/x')->assertNotFound();
        $this->get('http://cliente.teste/portal/estrutura/produtos/abc')->assertNotFound();
    }
}
