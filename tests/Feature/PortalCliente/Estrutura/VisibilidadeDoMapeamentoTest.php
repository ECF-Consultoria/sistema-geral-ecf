<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\PortalEquipeService;
use App\Support\Portal\VisibilidadeDoMapeamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Quem vê quais submódulos do Mapeamento: desde 10/10/2026 TODOS — cliente, equipe e a empresa
 * que importou ofertas (como a #131) — veem Produtos, Planejamento, Precificação e Mapeamento
 * ("Lista SKUs e Anúncios eu não vou usar, pode tirar"); `configuracoes` troca a lista padrão e dá
 * a lista exata de uma empresa (`todos` = os 7), sem deploy. Esconder não remove: a página abre.
 */
class VisibilidadeDoMapeamentoTest extends TestCase
{
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private const TODOS = ['produtos', 'sugestoes', 'lista', 'precificacao', 'anuncios', 'planejamento', 'mapeamento'];

    /** As chaves dos submódulos do Mapeamento no menu de uma página do portal (a de Produtos). */
    private function doMenu(Company $empresa, ?string $rota = 'portal.auth.estrutura.produtos', bool $equipe = false): array
    {
        // O guard guarda o usuário da requisição anterior: trocar de empresa no mesmo teste pede guard novo.
        $this->app['auth']->forgetGuards();
        $sessao = $equipe ? $this->comoEquipe($empresa) : $this->withoutVite()->entrarNoPortal($empresa);
        $modulos = $sessao->get(route($rota))->assertOk()->viewData('page')['props']['modulos'];

        return collect(collect($modulos)->firstWhere('chave', 'estrutura')['submodulos'])->pluck('chave')->all();
    }

    private function comoEquipe(Company $empresa): static
    {
        $admin = User::create(['name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($admin, $empresa, '127.0.0.1');
        $this->withoutVite()->get(route('portal.equipe.entrar', ['t' => $ticket]));

        return $this;
    }

    private function ofertaImportada(Company $empresa): void
    {
        app(EstruturaOfertaService::class)->criar($empresa, ['sku' => 'MLB-IMPORTADA-'.uniqid(), 'fase' => 'simples', 'nome' => 'Importada'], $this->atorCliente($empresa));
    }

    public function test_o_cliente_ve_os_quatro_do_dia_a_dia(): void
    {
        $vazia = $this->empresaDoGabarito();
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $this->doMenu($vazia));

        // Com produtos cadastrados (ofertas simples LIGADAS a produto), nada muda.
        Http::fake();
        $comProdutos = $this->empresaDoGabarito();
        $this->catalogoSintetico($comProdutos, $this->atorCliente($comProdutos));
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $this->doMenu($comProdutos));
    }

    public function test_quem_importou_ofertas_tambem_ve_so_os_quatro(): void
    {
        $importou = $this->empresaDoGabarito();
        $this->ofertaImportada($importou);

        // Até 10/10/2026 ela via também Lista SKUs e Anúncios; o usuário tirou os dois de todo mundo.
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $this->doMenu($importou));
        $this->assertTrue(VisibilidadeDoMapeamento::importouOfertas($importou));
        $this->assertFalse(VisibilidadeDoMapeamento::importouOfertas($this->empresaDoGabarito()));
    }

    public function test_a_equipe_ve_os_mesmos_quatro_e_segue_a_configuracao_da_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();

        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $this->doMenu($empresa, equipe: true));

        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$empresa->id, 'produtos,lista');
        $this->assertSame(['produtos', 'lista'], $this->doMenu($empresa, equipe: true));

        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$empresa->id, 'todos');
        $this->assertSame(self::TODOS, $this->doMenu($empresa, equipe: true));
    }

    public function test_configuracao_troca_a_lista_padrao_e_da_a_lista_exata_da_empresa(): void
    {
        $a = $this->empresaDoGabarito();
        $b = $this->empresaDoGabarito();
        $this->ofertaImportada($b);

        // Padrão novo para todos (lixo e espaço ignorados); desde 10/10/2026 não há mais exceção automática
        // para quem importou ofertas — a $b segue o mesmo padrão.
        Configuracao::set(VisibilidadeDoMapeamento::CHAVE_PADRAO, ' produtos , MAPEAMENTO, inexistente,');
        $this->assertSame(['produtos', 'mapeamento'], $this->doMenu($a));
        $this->assertSame(['produtos', 'mapeamento'], $this->doMenu($b));

        // A lista da empresa é EXATA: vence o padrão.
        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$b->id, 'sugestoes,precificacao');
        $this->assertSame(['sugestoes', 'precificacao'], $this->doMenu($b, 'portal.auth.estrutura.sugestoes'));
        $this->assertSame(['produtos', 'mapeamento'], $this->doMenu($a), 'a configuração de uma empresa não mexe na outra');

        // "todos" libera os 7; uma lista só com lixo vale como ausente.
        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$b->id, 'Todos');
        $this->assertSame(self::TODOS, $this->doMenu($b));
        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$a->id, 'xyz, ,');
        Configuracao::set(VisibilidadeDoMapeamento::CHAVE_PADRAO, '');
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $this->doMenu($a));
    }

    public function test_esconder_nao_remove_a_pagina_abre_e_o_item_aparece_marcado(): void
    {
        $empresa = $this->empresaDoGabarito();

        foreach (['lista' => 'lista', 'anuncios' => 'anuncios', 'agenda' => 'planejamento'] as $rota => $chave) {
            $modulos = $this->withoutVite()->entrarNoPortal($empresa)
                ->get(route("portal.auth.estrutura.{$rota}"))->assertOk()
                ->viewData('page')['props']['modulos'];
            $subs = collect(collect($modulos)->firstWhere('chave', 'estrutura')['submodulos']);

            $ativo = $subs->firstWhere('ativo', true);
            $this->assertSame($chave, $ativo['chave']);
            $this->assertTrue($ativo['oculto'], "{$chave} aparece só porque a pessoa está nele");
            $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], $subs->where('oculto', false)->pluck('chave')->values()->all());
        }
    }

    /** A entrada do módulo só manda para a Lista SKUs quem a vê; o resto começa por Produtos. */
    public function test_a_entrada_so_manda_para_a_lista_quem_a_ve(): void
    {
        $importou = $this->empresaDoGabarito();
        $this->ofertaImportada($importou);

        // Desde 10/10/2026 ninguém vê a Lista SKUs por padrão: a entrada vai para Produtos (a Lista abre por link).
        $this->app['auth']->forgetGuards();
        $sessao = $this->withoutVite()->entrarNoPortal($importou);
        $sessao->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));
        $sessao->get(route('portal.auth.estrutura.lista'))->assertOk();

        // A equipe também.
        $this->app['auth']->forgetGuards();
        $this->comoEquipe($importou)->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));

        // A ECF devolveu a Lista SKUs a esta empresa (`todos`): quem só tem ofertas importadas entra por ela.
        Configuracao::set(VisibilidadeDoMapeamento::PREFIXO_EMPRESA.$importou->id, 'todos');
        $this->app['auth']->forgetGuards();
        $this->entrarNoPortal($importou)->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.lista'));
    }

    public function test_os_outros_modulos_nao_mudam(): void
    {
        $empresa = $this->empresaDoGabarito();
        $modulos = $this->withoutVite()->entrarNoPortal($empresa)->get(route('portal.auth.inicio'))->assertOk()->viewData('page')['props']['modulos'];

        $this->assertContains('estrutura', array_column($modulos, 'chave'));
        foreach ($modulos as $m) {
            if ($m['chave'] !== 'estrutura') {
                $this->assertSame([], $m['submodulos']);
            }
        }
        // Fora da página do módulo, os submódulos visíveis do Mapeamento são os 4 (nenhum ativo).
        $estrutura = collect($modulos)->firstWhere('chave', 'estrutura');
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], array_column($estrutura['submodulos'], 'chave'));
    }
}
