<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\Configuracao;
use App\Models\User;
use App\Services\Portal\PortalEquipeService;
use App\Support\Portal\AtorDoPortal;
use App\Support\Portal\ModulosPortal;
use App\Support\Portal\VisibilidadeDosModulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * Quais MÓDULOS aparecem no menu do Portal. Desde 13/10/2026 ("ocultar tudo e deixar apenas
 * mapeamento estrutural") só o Mapeamento Estrutural, para cliente e equipe; `configuracoes`
 * devolve os outros sem deploy (lista padrão, lista exata de uma empresa, ou `todos`).
 * Esconder não remove: a página abre por link direto — menos o Início, que leva ao primeiro
 * módulo do menu, porque é para lá que o login manda.
 */
class VisibilidadeDosModulosTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    private const TODOS = ['inicio', 'onboarding', 'calculadora', 'ppa', 'estrutura'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Queue::fake();
    }

    private function empresa(): Company
    {
        return Company::create([
            'name'   => 'Cliente Menu '.uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true, 'status' => 'ativo', 'empresa_nova' => false,
        ]);
    }

    private function comoCliente(Company $empresa): static
    {
        // O guard guarda o usuário da requisição anterior: trocar de empresa no mesmo teste pede guard novo.
        $this->app['auth']->forgetGuards();

        return $this->withoutVite()->entrarNoPortal($empresa);
    }

    private function comoEquipe(Company $empresa): static
    {
        $this->app['auth']->forgetGuards();
        $admin = User::create(['name' => 'Admin '.uniqid(), 'email' => 'admin.'.uniqid().'@ecf.test',
            'password' => bcrypt('senha'), 'role' => 'admin', 'active' => true]);
        $ticket = app(PortalEquipeService::class)->emitir($admin, $empresa, '127.0.0.1');
        $this->withoutVite()->get(route('portal.equipe.entrar', ['t' => $ticket]));

        return $this;
    }

    /** As chaves dos módulos no menu de uma página do portal. */
    private function doMenu($sessao, string $rota = 'portal.auth.estrutura.produtos'): array
    {
        return array_column($sessao->get(route($rota))->assertOk()->viewData('page')['props']['modulos'], 'chave');
    }

    public function test_sem_configuracao_o_cliente_ve_so_o_mapeamento_estrutural(): void
    {
        $empresa = $this->empresa();
        $modulos = $this->comoCliente($empresa)->get(route('portal.auth.estrutura.produtos'))->assertOk()
            ->viewData('page')['props']['modulos'];

        $this->assertSame(['estrutura'], array_column($modulos, 'chave'));
        $this->assertTrue($modulos[0]['ativo']);
        $this->assertFalse($modulos[0]['oculto']);
        // Os submódulos do dia a dia seguem os mesmos: esconder os módulos não mexe neles.
        $this->assertSame(['produtos', 'sugestoes', 'precificacao', 'mapeamento'], array_column($modulos[0]['submodulos'], 'chave'));
    }

    public function test_o_inicio_escondido_leva_ao_mapeamento_estrutural(): void
    {
        $empresa = $this->empresa();
        $sessao = $this->comoCliente($empresa);

        // O login e a porta da equipe mandam para o Início: ele não pode virar uma tela fora do menu.
        $sessao->get(route('portal.auth.inicio'))->assertRedirect(route('portal.auth.estrutura'));
        $sessao->get(route('portal.auth.estrutura'))->assertRedirect(route('portal.auth.estrutura.produtos'));
    }

    public function test_a_equipe_segue_a_mesma_regua(): void
    {
        $empresa = $this->empresa();
        $sessao = $this->comoEquipe($empresa);

        $sessao->get(route('portal.auth.inicio'))->assertRedirect(route('portal.auth.estrutura'));
        $this->assertSame(['estrutura'], $this->doMenu($sessao));

        Configuracao::set(VisibilidadeDosModulos::PREFIXO_EMPRESA.$empresa->id, 'onboarding,estrutura');
        $this->assertSame(['onboarding', 'estrutura'], $this->doMenu($this->comoEquipe($empresa)));
    }

    public function test_esconder_nao_remove_a_pagina_abre_e_o_item_aparece_marcado(): void
    {
        $empresa = $this->empresa();

        foreach (['portal.auth.ppa' => 'ppa', 'portal.auth.calculadora' => 'calculadora', 'portal.auth.onboarding' => 'onboarding'] as $rota => $chave) {
            $modulos = collect($this->comoCliente($empresa)->get(route($rota))->assertOk()->viewData('page')['props']['modulos']);

            $ativo = $modulos->firstWhere('ativo', true);
            $this->assertSame($chave, $ativo['chave']);
            $this->assertTrue($ativo['oculto'], "{$chave} aparece só porque a pessoa está nele");
            $this->assertSame(['estrutura'], $modulos->where('oculto', false)->pluck('chave')->values()->all());
        }
    }

    public function test_a_configuracao_troca_a_lista_padrao(): void
    {
        $empresa = $this->empresa();

        // Lixo, espaço e caixa são ignorados; a ordem do menu é a do catálogo, não a da configuração.
        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, ' estrutura , PPA, inexistente,');
        $sessao = $this->comoCliente($empresa);
        $this->assertSame(['ppa', 'estrutura'], $this->doMenu($sessao));
        // O Início continua escondido: leva ao primeiro do menu, que agora é o PPA.
        $sessao->get(route('portal.auth.inicio'))->assertRedirect(route('portal.auth.ppa'));

        // "todos" devolve os cinco e o Início volta a abrir.
        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, 'Todos');
        $sessao = $this->comoCliente($empresa);
        $this->assertSame(self::TODOS, $this->doMenu($sessao, 'portal.auth.inicio'));

        // Uma lista só com lixo vale como ausente: o portal nunca fica sem menu.
        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, 'xyz, ,');
        $this->assertSame(['estrutura'], $this->doMenu($this->comoCliente($empresa)));
    }

    public function test_a_lista_da_empresa_e_exata_e_nao_mexe_nas_outras(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();

        Configuracao::set(VisibilidadeDosModulos::PREFIXO_EMPRESA.$b->id, 'inicio,onboarding,estrutura');
        $this->assertSame(['inicio', 'onboarding', 'estrutura'], $this->doMenu($this->comoCliente($b), 'portal.auth.inicio'));
        $this->assertSame(['estrutura'], $this->doMenu($this->comoCliente($a)));

        // A da empresa vence a padrão, nos dois sentidos.
        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, 'todos');
        $this->assertSame(['inicio', 'onboarding', 'estrutura'], $this->doMenu($this->comoCliente($b)));
        $this->assertSame(self::TODOS, $this->doMenu($this->comoCliente($a)));

        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, '');
        Configuracao::set(VisibilidadeDosModulos::PREFIXO_EMPRESA.$b->id, 'todos');
        $this->assertSame(self::TODOS, $this->doMenu($this->comoCliente($b)));
        $this->assertSame(['estrutura'], $this->doMenu($this->comoCliente($a)));
    }

    public function test_a_entrada_e_o_primeiro_modulo_do_menu(): void
    {
        $empresa = $this->empresa();
        $ator = AtorDoPortal::cliente($this->clienteDoPortal($empresa));

        $this->assertSame('portal.auth.estrutura', ModulosPortal::entrada($empresa, $ator));
        $this->assertFalse(ModulosPortal::moduloVisivel($empresa, $ator, ModulosPortal::INICIO));
        $this->assertTrue(ModulosPortal::moduloVisivel($empresa, $ator, ModulosPortal::ESTRUTURA));
        $this->assertFalse(ModulosPortal::moduloVisivel($empresa, $ator, 'nao-existe'));

        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, 'calculadora,onboarding');
        $this->assertSame('portal.auth.onboarding', ModulosPortal::entrada($empresa, $ator));

        Configuracao::set(VisibilidadeDosModulos::CHAVE_PADRAO, 'todos');
        $this->assertSame('portal.auth.inicio', ModulosPortal::entrada($empresa, $ator));
    }
}
