<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\EstruturaProduto;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Support\Permissions;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\VisibilidadeDoMapeamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
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
        // 09/10/2026: o Planejamento (chave `sugestoes`) vem logo depois de Produtos.
        $this->assertSame(
            ['produtos', 'sugestoes', 'lista', 'precificacao', 'anuncios', 'planejamento', 'mapeamento'],
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
                ->component('Portal/EstruturaProdutos')
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
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaProdutos'));
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

    public function test_entrada_empresa_so_com_ofertas_abre_produtos_e_so_vai_a_lista_se_configurada(): void
    {
        // As ofertas importadas da #131: oferta sem produto (gabarito grava só ofertas).
        $empresa = $this->empresaDoGabarito();
        $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $this->assertSame(0, EstruturaProduto::where('company_id', $empresa->id)->count());

        // 10/10/2026: ninguém vê a Lista SKUs por padrão — a entrada vai para Produtos.
        $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));

        // A ECF devolveu a Lista a esta empresa: aí ela entra por lá.
        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$empresa->id, 'todos');
        $this->app['auth']->forgetGuards();
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
        $this->assertSame(30, $achadas); // 172-05: + descricao; 09/10: + exportar, categorias.sugerir_nomes, fotos.previa, fotos.enviar e fichas.modelo/previa/importacao
    }

    public function test_a_allowlist_tem_uma_linha_por_rota_e_nenhum_curinga_generico(): void
    {
        $permitido = (new \ReflectionClass(\App\Http\Middleware\RestringeDominioDoPortal::class))->getConstant('PERMITIDO');
        $this->assertNotContains('portal/estrutura/produtos/*', $permitido);
        $this->assertCount(22, array_filter($permitido, fn ($p) => str_starts_with($p, 'portal/estrutura/produtos')));
        foreach (['modelo', 'previa', 'importacao'] as $f) {
            $this->assertContains("portal/estrutura/produtos/fichas/{$f}", $permitido);
        }
        $this->assertContains('portal/estrutura/produtos/fotos', $permitido);
        $this->assertContains('portal/estrutura/produtos/fotos/previa', $permitido);
        $this->assertContains('portal/estrutura/produtos/exportar', $permitido);
        $this->assertContains('portal/estrutura/produtos/categorias/sugerir-nomes', $permitido);
        // 167-19: o id numérico da ficha entra por uma lista própria, nunca por curinga.
        $this->assertSame(
            [
                'portal/estrutura/produtos/{id}',
                'portal/estrutura/produtos/{id}/ficha-tecnica',
                'portal/estrutura/produtos/{id}/descricao',
                'portal/estrutura/sugestoes/produtos/{id}/geracao',
                // Imagens por variação: os dois ids (variação e imagem) só dígitos.
                'portal/estrutura/produtos/variacao/{id}/imagens',
                'portal/estrutura/produtos/variacao/{id}/imagens/ordem',
                'portal/estrutura/produtos/variacao/{id}/imagem/{id}',
            ],
            (new \ReflectionClass(\App\Http\Middleware\RestringeDominioDoPortal::class))->getConstant('PERMITIDO_COM_ID'),
        );
    }

    // ─── Empresa sempre da sessão (T-167-41) e origem no log (T-167-46) ─────

    public function test_company_id_do_corpo_e_ignorado_e_o_produto_nasce_na_empresa_da_sessao(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();

        $this->entrarNoPortal($minha)
            ->postJson(route('portal.auth.estrutura.produtos.linhas'), [
                'company_id' => $outra->id,
                'linhas'     => [$this->linha('P-1', ['company_id' => $outra->id])],
            ])->assertOk();

        $this->assertSame(1, EstruturaProduto::where('company_id', $minha->id)->count());
        $this->assertSame(0, EstruturaProduto::where('company_id', $outra->id)->count());
    }

    public function test_ids_de_outra_empresa_respondem_404(): void
    {
        $minha = $this->empresaDoGabarito();
        $outra = $this->empresaDoGabarito();
        $this->gravarNaOutra($outra);
        $variacaoAlheia = \App\Models\EstruturaProdutoVariacao::where('company_id', $outra->id)->firstOrFail();
        $familiaAlheia = \App\Models\EstruturaFamilia::create(['company_id' => $outra->id, 'nome' => 'Alheia']);
        $ambienteAlheio = \App\Models\EstruturaAmbiente::create(['company_id' => $outra->id, 'nome' => 'Alheio']);

        $sessao = $this->entrarNoPortal($minha);

        $sessao->deleteJson(route('portal.auth.estrutura.produtos.variacoes.excluir', $variacaoAlheia->id))->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.produtos.familias.renomear', $familiaAlheia->id), ['nome' => 'X'])->assertNotFound();
        $sessao->deleteJson(route('portal.auth.estrutura.produtos.familias.excluir', $familiaAlheia->id))->assertNotFound();
        $sessao->putJson(route('portal.auth.estrutura.produtos.ambientes.renomear', $ambienteAlheio->id), ['nome' => 'X'])->assertNotFound();
        $sessao->deleteJson(route('portal.auth.estrutura.produtos.ambientes.excluir', $ambienteAlheio->id))->assertNotFound();

        $this->assertSame(1, \App\Models\EstruturaProdutoVariacao::where('company_id', $outra->id)->count());
        $this->assertSame('Alheia', $familiaAlheia->fresh()->nome);
    }

    /** Grava direto pelo serviço: uma sessão HTTP só vale para uma empresa por teste. */
    private function gravarNaOutra(Company $outra): void
    {
        app(\App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService::class)
            ->gravarLinhas($outra, [$this->linha('ALHEIO-1')], $this->atorCliente($outra));
    }

    public function test_a_origem_no_log_e_cliente_quando_grava_o_cliente(): void
    {
        $this->entrarNoPortal($this->empresaDoGabarito())
            ->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [$this->linha('C-1')]])
            ->assertOk();

        $this->assertSame('cliente', $this->origemDoUltimoLote());
    }

    public function test_a_origem_no_log_e_interno_quando_grava_a_equipe(): void
    {
        $this->entrarComoEquipe($this->admin(), $this->empresaDoGabarito())
            ->postJson(route('portal.auth.estrutura.produtos.linhas'), ['linhas' => [$this->linha('E-1')]])
            ->assertOk();

        $this->assertSame('interno', $this->origemDoUltimoLote());
    }

    private function origemDoUltimoLote(): ?string
    {
        $log = Activity::query()->where('properties->evento', 'produtos_gravados')->latest('id')->first();
        $this->assertNotNull($log, 'nenhum activity produtos_gravados');

        return $log->properties['origem'] ?? null;
    }
}
