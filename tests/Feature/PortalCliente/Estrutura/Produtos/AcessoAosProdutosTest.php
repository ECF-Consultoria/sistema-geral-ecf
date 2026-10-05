<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaProduto;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Support\Permissions;
use App\Support\Portal\ModulosPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-10: o submódulo Produtos no Portal — quem entra, por onde, e que a
 * empresa vem sempre da sessão (id de outra empresa = 404; `company_id` do
 * corpo é ignorado).
 */
class AcessoAosProdutosTest extends TestCase
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

    private function analistaNaCarteira(\App\Models\Company $empresa): User
    {
        $user = User::create([
            'name' => 'Analista '.uniqid(), 'email' => 'an.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'consultor', 'active' => true,
        ]);
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor '.uniqid(), 'slug' => 'setor-'.uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([Permissions::CORE_ONBOARDING, Permissions::CORE_EMPRESAS] as $key) {
            DB::table('setor_permissoes')->insert(['setor_id' => $setorId, 'permission_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('user_setores')->insert(['user_id' => $user->id, 'setor_id' => $setorId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_users')->insert([
            'company_id' => $empresa->id, 'user_id' => $user->id, 'role' => 'consultor', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function entrarComoEquipe(User $membro, \App\Models\Company $empresa): static
    {
        $ticket = app(PortalEquipeService::class)->emitir($membro, $empresa, '127.0.0.1');
        $this->get(route('portal.equipe.entrar', ['t' => $ticket]));

        return $this;
    }

    private function linha(string $codigo, array $extra = []): array
    {
        return array_merge(['codigo' => $codigo, 'nome' => 'Produto '.$codigo], $extra);
    }

    public function test_o_menu_do_mapeamento_comeca_por_produtos(): void
    {
        $this->assertSame(
            ['produtos', 'lista', 'precificacao', 'anuncios', 'planejamento', 'mapeamento'],
            array_keys((new \ReflectionClass(ModulosPortal::class))->getConstant('SUBMODULOS')[ModulosPortal::ESTRUTURA]),
        );
    }

    public function test_sem_sessao_a_pagina_manda_para_a_entrada_do_portal(): void
    {
        $this->get(route('portal.auth.estrutura.produtos'))->assertRedirect();
    }

    public function test_o_cliente_abre_a_pagina_com_as_props_do_contrato(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.produtos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaProdutos', false)
                ->has('produtos.linhas')
                ->has('filtros')
                ->has('listas.familias')
                ->has('listas.ambientes')
                ->has('vocabulario.eixos')
                ->has('vocabulario.pendencias')
                ->where('ml_conectado', false)
                ->has('frete_tabela')
                ->where('limites.colar', 200)
                ->where('modulos', fn ($m) => collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('ativo', true)['chave'] === 'produtos')
            );
    }

    public function test_a_equipe_abre_a_pagina_como_admin_e_como_analista_da_carteira(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarComoEquipe($this->admin(), $empresa)
            ->get(route('portal.auth.estrutura.produtos'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaProdutos', false));
    }

    public function test_a_equipe_analista_da_carteira_tambem_abre(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->withoutVite()->entrarComoEquipe($this->analistaNaCarteira($empresa), $empresa)
            ->get(route('portal.auth.estrutura.produtos'))
            ->assertOk();
    }

    // A sessão do portal guarda a empresa por processo: cada empresa do teste tem o seu método.
    public function test_entrada_empresa_sem_nada_abre_produtos(): void
    {
        $this->withoutVite()->entrarNoPortal($this->empresaDoGabarito())
            ->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));
    }

    public function test_entrada_empresa_so_com_ofertas_abre_a_lista_skus(): void
    {
        // As ofertas importadas da #131: oferta sem produto (gabarito grava só ofertas).
        $empresa = $this->empresaDoGabarito();
        $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->assertSame(0, EstruturaProduto::where('company_id', $empresa->id)->count());

        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.lista'));
    }

    public function test_entrada_empresa_com_produto_abre_produtos_e_link_antigo_vai_para_o_mapeamento(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        EstruturaProduto::create(['company_id' => $empresa->id, 'nome' => 'Cadeira', 'codigo' => 'CAD']);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));
        $sessao->get(route('portal.auth.estrutura', ['abrir' => 7]))
            ->assertRedirect(route('portal.auth.estrutura.mapeamento', ['abrir' => 7]));
    }

    public function test_toda_rota_nova_tem_throttle_proprio_de_prefixo_estrutura_produtos(): void
    {
        $achadas = 0;
        foreach (Route::getRoutes() as $rota) {
            $nome = (string) $rota->getName();
            if (! str_starts_with($nome, 'portal.auth.estrutura.produtos.')) {
                continue;
            }
            $achadas++;
            $throttle = collect($rota->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotNull($throttle, "{$nome} sem throttle");
            $this->assertStringContainsString(',estrutura.produtos', $throttle, "{$nome} sem prefixo próprio");
            $this->assertContains('portal.auth', $rota->gatherMiddleware(), "{$nome} fora do grupo portal.auth");
        }
        $this->assertSame(14, $achadas);
    }

    public function test_a_allowlist_tem_uma_linha_por_rota_e_nenhum_curinga_generico(): void
    {
        $permitido = (new \ReflectionClass(\App\Http\Middleware\RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
        $this->assertNotContains('portal/estrutura/produtos/*', $permitido);
        $this->assertCount(13, array_filter($permitido, fn ($p) => str_starts_with($p, 'portal/estrutura/produtos')));
    }
}
