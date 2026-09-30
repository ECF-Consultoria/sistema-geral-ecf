<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaPrecificacaoParametros;
use App\Services\Portal\Estrutura\PrecificacaoEstrutura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Precificação do Mapeamento Estrutural (ADR PORTAL-02): a conta da Calculadora
 * de Custo, no PHP, sobre o gabarito da planilha.
 *
 *     preço mínimo = (custo + frete) / (1 − comissão − imposto − MC − LL)
 *     anunciado    = preço mínimo × (1 + acréscimo)
 */
class PrecificacaoEstruturaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    /** A conta pura, com os padrões da Calculadora (11,5 / 16,5 / 19 / 0 / 0 / 20). */
    public function test_a_conta_e_a_da_calculadora(): void
    {
        // Clássico: 120 / (1 − 0,115 − 0,19) = 172,66 → ×1,2 = 207,19
        $c = PrecificacaoEstrutura::preco(100, 20, 11.5, 19, 0, 0, 20);
        $this->assertSame(['minimo' => 172.66, 'anunciado' => 207.19, 'impossivel' => false, 'sem_frete' => false], $c);

        // Sem frete: o preço sai, mas avisado.
        $this->assertTrue(PrecificacaoEstrutura::preco(100, null, 11.5, 19, 0, 0, 20)['sem_frete']);

        // Percentuais somando 100% ou mais: não há preço.
        $this->assertSame(['minimo' => null, 'anunciado' => null, 'impossivel' => true, 'sem_frete' => false],
            PrecificacaoEstrutura::preco(100, 20, 50, 30, 10, 10, 20));

        // Sem custo: sem preço, sem pendência de frete.
        $this->assertNull(PrecificacaoEstrutura::preco(null, 20, 11.5, 19, 0, 0, 20)['minimo']);
    }

    /**
     * Caso real de 30/09 (PUFF-AZ, empresa 447): frete do Clássico 32 e o do
     * Premium em branco. O branco entrava como ZERO e o Premium saía mais
     * barato que o Clássico. Agora o tipo em branco usa o frete do outro.
     */
    public function test_frete_em_branco_usa_o_do_outro_tipo_e_premium_fica_acima(): void
    {
        $this->assertSame(
            ['classico' => ['valor' => 32.0, 'origem' => 'digitado'], 'premium' => ['valor' => 32.0, 'origem' => 'outro_tipo']],
            PrecificacaoEstrutura::fretes(32.0, null),
        );
        $this->assertSame(['valor' => 25.0, 'origem' => 'outro_tipo'], PrecificacaoEstrutura::fretes(null, 25.0)['classico']);
        // Zero digitado é escolha do cliente: não herda.
        $this->assertSame(['valor' => 0.0, 'origem' => 'digitado'], PrecificacaoEstrutura::fretes(32.0, 0.0)['premium']);
        $this->assertSame(['valor' => null, 'origem' => null], PrecificacaoEstrutura::fretes(null, null)['premium']);

        $empresa = $this->empresaDoGabarito();
        $o = $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01']->id), ['custo' => '44', 'frete_classico' => '32'])
            ->assertSessionHasNoErrors();
        $cad = $sessao->get(route('portal.auth.estrutura.precificacao'))->viewData('page')['props']['precificacao']['por_oferta'][$o['CAD-01']->id];

        // Clássico: 76 / 0,695 = 109,35 → ×1,2 = 131,22. Premium: 76 / 0,645 = 117,83 → 141,40.
        $this->assertSame(131.22, $cad['classico']['anunciado']);
        $this->assertSame(141.4, $cad['premium']['anunciado']);
        $this->assertGreaterThan($cad['classico']['anunciado'], $cad['premium']['anunciado']);
        $this->assertSame('outro_tipo', $cad['premium']['frete_origem']);
        // O que o cliente digitou continua sendo o que o campo mostra: o Premium segue vazio.
        $this->assertNull($cad['frete_premium']);
        $this->assertNull($cad['pendencia']);
    }

    /**
     * O custo de combo/kit/combit vem dos componentes; basta um componente sem
     * custo para não haver custo. O digitado vence.
     */
    public function test_custo_das_variacoes_vem_dos_componentes(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $o = $this->listaDoGabarito($empresa, $ator);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01']->id), ['custo' => '100', 'frete_classico' => '20', 'frete_premium' => '25'])
            ->assertSessionHasNoErrors();

        $pagina = fn () => $sessao->get(route('portal.auth.estrutura.precificacao'))->viewData('page')['props']['precificacao'];

        $p = $pagina();
        $cad = $p['por_oferta'][$o['CAD-01']->id];
        $this->assertSame(['valor' => 100.0, 'origem' => 'digitado', 'calculado' => null], $cad['custo']);
        $this->assertSame(172.66, $cad['classico']['minimo']);
        $this->assertSame(207.19, $cad['classico']['anunciado']);
        // Premium: 125 / (1 − 0,165 − 0,19) = 193,80 → ×1,2 = 232,56
        $this->assertSame(193.8, $cad['premium']['minimo']);
        $this->assertSame(232.56, $cad['premium']['anunciado']);
        $this->assertNull($cad['pendencia']);

        // CB4 = 4 × 100, sem digitar; sem frete ainda.
        $cb4 = $p['por_oferta'][$o['CAD-01-CB4']->id];
        $this->assertSame(['valor' => 400.0, 'origem' => 'componentes', 'calculado' => 400.0], $cb4['custo']);
        $this->assertSame('sem_frete', $cb4['pendencia']);

        // O kit leva a Mesa, que ainda não tem custo: nada de somar só a cadeira.
        $kit = $p['por_oferta'][$o['MSA-MR+CAD-01-KIT']->id];
        $this->assertNull($kit['custo']['valor']);
        $this->assertSame('sem_custo', $kit['pendencia']);

        // Com a Mesa, o kit e o combit fecham: 300 + 100 e 300 + 4 × 100.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['MSA-MR']->id), ['custo' => '300.00'])->assertSessionHasNoErrors();
        $p = $pagina();
        $this->assertSame(400.0, $p['por_oferta'][$o['MSA-MR+CAD-01-KIT']->id]['custo']['valor']);
        $this->assertSame(700.0, $p['por_oferta'][$o['MSA-MR+CAD-01-CBT4']->id]['custo']['valor']);

        // Corrigir à mão o custo do combo: o digitado vence, e a soma continua visível.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01-CB4']->id), ['custo' => '380'])->assertSessionHasNoErrors();
        $this->assertSame(['valor' => 380.0, 'origem' => 'digitado', 'calculado' => 400.0], $pagina()['por_oferta'][$o['CAD-01-CB4']->id]['custo']);

        // O resumo é da empresa inteira: 9 ofertas; só CAD-01 com preço completo.
        $this->assertSame(['total' => 9, 'precificadas' => 1, 'sem_custo' => 0, 'sem_frete' => 8, 'impossivel' => 0], $pagina()['resumo']);
    }

    /** Parâmetros da empresa e exceção por produto — em ponto percentual. */
    public function test_parametros_da_empresa_e_excecao_do_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $o = $this->listaDoGabarito($empresa, $ator);
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // Sem linha, valem os padrões da Calculadora.
        $this->assertSame(EstruturaPrecificacaoParametros::PADROES, EstruturaPrecificacaoParametros::daEmpresa($empresa->id));

        $parametros = ['comissao_classico' => '12', 'comissao_premium' => '17', 'imposto' => '10', 'margem_contribuicao' => '5', 'lucro_liquido' => '5', 'acrescimo' => '0'];
        $sessao->put(route('portal.auth.estrutura.precificacao.parametros'), $parametros)->assertSessionHasNoErrors();

        // 100% ou mais é recusado; vazio também.
        $sessao->put(route('portal.auth.estrutura.precificacao.parametros'), [...$parametros, 'imposto' => '100'])->assertSessionHasErrors('imposto');
        $sessao->put(route('portal.auth.estrutura.precificacao.parametros'), [...$parametros, 'acrescimo' => ''])->assertSessionHasErrors('acrescimo');
        $this->assertSame(10.0, EstruturaPrecificacaoParametros::daEmpresa($empresa->id)['imposto']);

        // Clássico: 110 / (1 − 0,12 − 0,10 − 0,05 − 0,05) = 110 / 0,68 = 161,76; acréscimo 0.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01']->id), ['custo' => '100', 'frete_classico' => '10', 'frete_premium' => '10']);
        $props = fn () => $sessao->get(route('portal.auth.estrutura.precificacao'))->viewData('page')['props']['precificacao']['por_oferta'][$o['CAD-01']->id];
        $this->assertSame(161.76, $props()['classico']['minimo']);
        $this->assertSame(161.76, $props()['classico']['anunciado']);

        // Exceção só deste produto: comissão do Clássico 20% → 110 / 0,60 = 183,33.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01']->id),
            ['custo' => '100', 'frete_classico' => '10', 'frete_premium' => '10', 'comissao_classico' => '20'])->assertSessionHasNoErrors();
        $cad = $props();
        $this->assertSame(183.33, $cad['classico']['minimo']);
        $this->assertSame(20.0, $cad['classico']['comissao']);
        $this->assertSame(20.0, $cad['excecoes']['comissao_classico']);
        $this->assertNull($cad['excecoes']['imposto']);

        // Valor negativo é recusado.
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $o['CAD-01']->id), ['custo' => '-5'])->assertSessionHasErrors('custo');
        $this->assertSame(100.0, EstruturaPrecificacao::where('oferta_id', $o['CAD-01']->id)->value('custo'));
    }

    public function test_a_pagina_abre_e_marca_o_submodulo_e_oferta_alheia_e_404(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->listaDoGabarito($empresa, $this->atorCliente($empresa));
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        $sessao->get(route('portal.auth.estrutura.precificacao'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portal/EstruturaPrecificacao')
                ->where('precificacao.resumo.total', 9)
                ->count('precificacao.por_oferta', 9)
                ->where('modulos', fn ($m) => collect(collect($m)->firstWhere('chave', 'estrutura')['submodulos'])->firstWhere('ativo', true)['chave'] === 'precificacao')
            );

        $outra = $this->empresaDoGabarito();
        $alheia = $this->listaDoGabarito($outra, $this->atorCliente($outra))['CAD-01'];
        $sessao->put(route('portal.auth.estrutura.precificacao.oferta', $alheia->id), ['custo' => '1'])->assertNotFound();
        $this->assertSame(0, EstruturaPrecificacao::count());
    }
}
