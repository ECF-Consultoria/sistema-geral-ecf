<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Http\Middleware\RestringeDominioDoPortal;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaTipoPar;
use App\Models\EstruturaTipoProduto;
use App\Models\User;
use App\Services\Portal\Estrutura\Geracao\CatalogoDaEcfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 168-09: admin da ECF para tipos e pares (D-06, D-13, D-14, D-15).
 *
 * Modos de falha que estes testes impedem: direção do Combit apontando para o lado errado
 * depois de ordenar o par, par duplicado em ordem invertida, slug que muda ao editar,
 * quantidade inválida gravada, tipo excluído deixando par/produto órfão, escrita sem rastro
 * em activity_log e rota interna acessível a não-admin ou liberada no domínio do cliente.
 */
class AdminTiposEParesTest extends TestCase
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

    private function consultor(): User
    {
        return User::create([
            'name' => 'Consultor '.uniqid(), 'email' => 'cons.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);
    }

    /** Tipos próprios do teste, sem depender da semente. */
    private function tipo(string $nome): EstruturaTipoProduto
    {
        return app(CatalogoDaEcfService::class)->criarTipo([
            'nome' => $nome, 'plural' => $nome.'s', 'palavras' => strtolower($nome),
            'qtd_combo' => null, 'qtd_combit' => null,
        ]);
    }

    private function servico(): CatalogoDaEcfService
    {
        return app(CatalogoDaEcfService::class);
    }

    private function dadosTipo(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Bandeja', 'plural' => 'Bandejas', 'palavras' => 'bandeja, bandejas',
            'qtd_combo' => '2, 4', 'qtd_combit' => '',
        ], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Parte de uma lista vazia: a semente da migration não interfere nos slugs.
        EstruturaTipoPar::query()->delete();
        EstruturaTipoProduto::query()->delete();
    }

    // ── Serviço ──

    public function test_ordenar_par_remapeia_a_direcao_do_combit(): void
    {
        $this->assertSame(['tipo_a_id' => 4, 'tipo_b_id' => 9, 'combit_repete' => 'b'], CatalogoDaEcfService::ordenarPar(9, 4, 'primeiro'));
        $this->assertSame('a', CatalogoDaEcfService::ordenarPar(4, 9, 'primeiro')['combit_repete']);
        $this->assertSame('a', CatalogoDaEcfService::ordenarPar(9, 4, 'segundo')['combit_repete']);
        $this->assertNull(CatalogoDaEcfService::ordenarPar(9, 4, 'nao')['combit_repete']);
        $this->assertSame('ambos', CatalogoDaEcfService::ordenarPar(9, 4, 'ambos')['combit_repete']);
        $this->assertSame('ambos', CatalogoDaEcfService::ordenarPar(5, 5, 'segundo')['combit_repete']);
    }

    public function test_criar_tipo_normaliza_palavras_e_quantidades_e_gera_slug(): void
    {
        $tipo = $this->servico()->criarTipo($this->dadosTipo(['palavras' => 'Bandeja, bandejas ,']));

        $this->assertSame('bandeja', $tipo->slug);
        $this->assertSame('bandeja, bandejas', $tipo->palavras);
        $this->assertSame('2, 4', $tipo->qtd_combo);
        $this->assertNull($tipo->qtd_combit);
    }

    public function test_slug_repetido_ganha_sufixo_e_a_edicao_nao_muda_o_slug(): void
    {
        $primeiro = $this->servico()->criarTipo($this->dadosTipo());
        $segundo = $this->servico()->criarTipo($this->dadosTipo());

        $this->assertSame('bandeja', $primeiro->slug);
        $this->assertSame('bandeja-2', $segundo->slug);

        $editado = $this->servico()->atualizarTipo($primeiro, $this->dadosTipo(['nome' => 'Outro nome']));
        $this->assertSame('bandeja', $editado->slug);
        $this->assertSame('Outro nome', $editado->nome);
    }

    public function test_quantidade_invalida_recusa_com_a_mensagem_do_formato(): void
    {
        try {
            $this->servico()->criarTipo($this->dadosTipo(['qtd_combo' => '1']));
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertSame('Use números inteiros de 2 a 999, separados por vírgula.', $e->errors()['qtd_combo'][0]);
        }
    }

    public function test_palavras_so_com_virgulas_sao_recusadas(): void
    {
        try {
            $this->servico()->criarTipo($this->dadosTipo(['palavras' => ' , ,']));
            $this->fail('Deveria recusar.');
        } catch (ValidationException $e) {
            $this->assertSame('Informe ao menos uma palavra-chave.', $e->errors()['palavras'][0]);
        }
    }

    public function test_par_repetido_em_qualquer_ordem_e_recusado(): void
    {
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $this->servico()->criarPar($a->id, $b->id, 'nao');

        foreach ([[$a->id, $b->id], [$b->id, $a->id]] as [$x, $y]) {
            try {
                $this->servico()->criarPar($x, $y, 'ambos');
                $this->fail('Deveria recusar.');
            } catch (ValidationException $e) {
                $this->assertSame('Este par já existe. Edite o que está na lista.', $e->errors()['tipo_b_id'][0]);
            }
        }
        $this->assertSame(1, EstruturaTipoPar::count());
    }

    public function test_atualizar_par_nao_conflita_com_ele_mesmo(): void
    {
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $par = $this->servico()->criarPar($a->id, $b->id, 'nao');

        $par = $this->servico()->atualizarPar($par, $b->id, $a->id, 'primeiro');

        $this->assertSame($a->id, $par->tipo_a_id);
        $this->assertSame('b', $par->combit_repete);
    }

    public function test_excluir_tipo_leva_os_pares_e_devolve_o_produto_a_inferencia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $produto = EstruturaProduto::create(['company_id' => $empresa->id, 'codigo' => null, 'nome' => 'P '.uniqid()]);
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $this->servico()->criarPar($a->id, $b->id, 'nao');
        EstruturaProdutoGeracao::create(['produto_id' => $produto->id, 'company_id' => $empresa->id, 'tipo_id' => $a->id]);

        $this->servico()->excluirTipo($a);

        $this->assertSame(0, EstruturaTipoPar::count());
        $this->assertNull(EstruturaProdutoGeracao::findOrFail($produto->id)->tipo_id);
        $this->assertNull(EstruturaTipoProduto::find($a->id));
    }

    public function test_toda_escrita_deixa_rastro_no_activity_log(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $par = $this->servico()->criarPar($a->id, $b->id, 'nao');
        $this->servico()->excluirPar($par);
        $this->servico()->excluirTipo($b);

        $linhas = DB::table('activity_log')->where('log_name', 'estrutura_geracao')->get();
        $this->assertCount(5, $linhas);
        $this->assertTrue($linhas->every(fn ($l) => (int) $l->causer_id === $admin->id));
    }

    // ── HTTP ──

    public function test_admin_ve_tipos_e_pares_na_pagina(): void
    {
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $this->servico()->criarPar($b->id, $a->id, 'primeiro');

        // A página React só nasce no 168-12: sem manifest do Vite para ela.
        $this->withoutVite()
            ->actingAs($this->admin())
            ->get(route('dev.estrutura_geracao.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Dev/EstruturaGeracao', false)
                ->has('tipos', 2)
                ->where('tipos.0.palavras', ['mesa'])
                ->where('tipos.0.pares', 1)
                ->has('pares', 1)
                ->where('pares.0.primeiro.nome', 'Mesa')
                ->where('pares.0.segundo.nome', 'Cadeira')
                ->where('pares.0.combit', 'segundo'));
    }

    public function test_nao_admin_recebe_403_em_todos_os_verbos(): void
    {
        $tipo = $this->tipo('Mesa');
        $outro = $this->tipo('Cadeira');
        $par = $this->servico()->criarPar($tipo->id, $outro->id, 'nao');
        $this->actingAs($this->consultor());

        $this->get(route('dev.estrutura_geracao.index'))->assertForbidden();
        $this->post(route('dev.estrutura_geracao.tipos.criar'), $this->dadosTipo())->assertForbidden();
        $this->put(route('dev.estrutura_geracao.tipos.atualizar', $tipo->id), $this->dadosTipo())->assertForbidden();
        $this->delete(route('dev.estrutura_geracao.tipos.excluir', $tipo->id))->assertForbidden();
        $this->post(route('dev.estrutura_geracao.pares.criar'), ['primeiro' => 1, 'segundo' => 2, 'combit' => 'nao'])->assertForbidden();
        $this->put(route('dev.estrutura_geracao.pares.atualizar', $par->id), ['primeiro' => 1, 'segundo' => 2, 'combit' => 'nao'])->assertForbidden();
        $this->delete(route('dev.estrutura_geracao.pares.excluir', $par->id))->assertForbidden();

        $this->assertSame(2, EstruturaTipoProduto::count());
    }

    public function test_sem_login_vai_para_o_login(): void
    {
        $this->get(route('dev.estrutura_geracao.index'))->assertRedirect(route('login'));
    }

    public function test_admin_cria_edita_e_exclui_tipo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('dev.estrutura_geracao.tipos.criar'), $this->dadosTipo())
            ->assertRedirect()->assertSessionHas('success', 'Tipo salvo.');
        $tipo = EstruturaTipoProduto::where('slug', 'bandeja')->firstOrFail();

        $this->put(route('dev.estrutura_geracao.tipos.atualizar', $tipo->id), $this->dadosTipo(['plural' => 'Bandejões', 'slug' => 'invasor']))
            ->assertSessionHas('success', 'Tipo salvo.');
        $tipo->refresh();
        $this->assertSame('Bandejões', $tipo->plural);
        $this->assertSame('bandeja', $tipo->slug);

        $this->delete(route('dev.estrutura_geracao.tipos.excluir', $tipo->id))
            ->assertSessionHas('success', 'Tipo excluído.');
        $this->assertNull(EstruturaTipoProduto::find($tipo->id));
    }

    public function test_quantidade_invalida_volta_com_erro_no_campo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('dev.estrutura_geracao.tipos.criar'), $this->dadosTipo(['qtd_combo' => '1']))
            ->assertSessionHasErrors(['qtd_combo' => 'Use números inteiros de 2 a 999, separados por vírgula.']);
        $this->assertSame(0, EstruturaTipoProduto::count());
    }

    public function test_admin_cria_edita_e_exclui_par(): void
    {
        $a = $this->tipo('Mesa');
        $b = $this->tipo('Cadeira');
        $this->actingAs($this->admin());

        $this->post(route('dev.estrutura_geracao.pares.criar'), ['primeiro' => $b->id, 'segundo' => $a->id, 'combit' => 'segundo'])
            ->assertSessionHas('success', 'Par salvo.');
        $par = EstruturaTipoPar::firstOrFail();
        $this->assertSame('a', $par->combit_repete);

        $this->post(route('dev.estrutura_geracao.pares.criar'), ['primeiro' => $a->id, 'segundo' => $b->id, 'combit' => 'nao'])
            ->assertSessionHasErrors(['tipo_b_id' => 'Este par já existe. Edite o que está na lista.']);

        $this->put(route('dev.estrutura_geracao.pares.atualizar', $par->id), ['primeiro' => $a->id, 'segundo' => $b->id, 'combit' => 'ambos'])
            ->assertSessionHas('success', 'Par salvo.');
        $this->assertSame('ambos', $par->refresh()->combit_repete);

        $this->delete(route('dev.estrutura_geracao.pares.excluir', $par->id))
            ->assertSessionHas('success', 'Par excluído.');
        $this->assertSame(0, EstruturaTipoPar::count());
    }

    public function test_par_com_tipo_inexistente_e_recusado(): void
    {
        $a = $this->tipo('Mesa');
        $this->actingAs($this->admin());

        $this->post(route('dev.estrutura_geracao.pares.criar'), ['primeiro' => $a->id, 'segundo' => 999999, 'combit' => 'nao'])
            ->assertSessionHasErrors('segundo');
    }

    public function test_id_inexistente_ou_nao_numerico_da_404(): void
    {
        $this->actingAs($this->admin());

        $this->put('/dev/estrutura-geracao/tipos/999999', $this->dadosTipo())->assertNotFound();
        $this->delete('/dev/estrutura-geracao/pares/999999')->assertNotFound();
        $this->delete('/dev/estrutura-geracao/tipos/abc')->assertNotFound();
    }

    public function test_a_rota_nao_e_liberada_no_dominio_do_cliente(): void
    {
        $this->assertFalse(RestringeDominioDoPortal::liberado('dev/estrutura-geracao'));
        $this->assertFalse(RestringeDominioDoPortal::liberado('dev/estrutura-geracao/tipos/1'));
    }
}
