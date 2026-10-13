<?php

namespace Tests\Feature\PortalCliente\Estrutura\Sugestoes;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\Geracao\OfertasCriadas;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * 13/10/2026 — a aba "Criadas" do Planejamento. O usuário: "em Planejamento deveriam ficar os produtos que
 * planejamos… aceitou, ele fica ali". A sugestão aceita sumia da tela e não havia onde rever, dentro do
 * Planejamento, os combos, kits e combits que já existem.
 *
 * A lista é a das ofertas compostas da empresa, por tipo, com do que cada uma é feita, e com o preço e o
 * envio saídos da MESMA conta da Precificação. Nenhuma chamada ao Mercado Livre.
 */
class AbaCriadasTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(fn () => Http::response(['message' => 'não deveria chamar'], 500));
        // Criar oferta agenda o preparo no editor interno (outra tela, outro teste): aqui não roda.
        Queue::fake();
    }

    private function simples(Company $empresa, string $codigo, array $volume, ?string $custo): EstruturaOferta
    {
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [[
            'grupo' => 'G'.$codigo, 'codigo' => $codigo, 'nome' => 'Produto '.$codigo, 'valor' => null, 'eixo' => 'cor',
            'volumes' => [$volume], ...($custo !== null ? ['custo' => $custo] : []),
        ]], $this->atorCliente($empresa));

        return EstruturaOferta::where('company_id', $empresa->id)->where('sku', $codigo)->whereNotNull('variacao_id')->firstOrFail();
    }

    /** Uma poltrona que cabe no envio padrão, uma mesa, e três compostas: Combo 2, Kit e Combit. */
    private function cenario(): array
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $nina = $this->simples($empresa, 'NINA', ['c' => 53, 'l' => 60, 'a' => 80, 'kg' => 8], '100');
        $mesa = $this->simples($empresa, 'MESA', ['c' => 20, 'l' => 20, 'a' => 10, 'kg' => 2], null);

        $ofertas = app(EstruturaOfertaService::class);
        [$combo] = $ofertas->criar($empresa, ['sku' => 'NINA-CB2', 'fase' => 'combo', 'nome' => 'Combo 2 Nina', 'componentes' => [['id' => $nina->id, 'quantidade' => 2]]], $ator);
        [$kit] = $ofertas->criar($empresa, ['sku' => 'NINA+MESA', 'fase' => 'kit', 'nome' => 'Kit Nina e Mesa',
            'componentes' => [['id' => $nina->id, 'quantidade' => 1], ['id' => $mesa->id, 'quantidade' => 1]]], $ator);
        [$combit] = $ofertas->criar($empresa, ['sku' => 'NINA+MESA-CBT4', 'fase' => 'combit', 'nome' => 'Combit 4 Nina e Mesa',
            'componentes' => [['id' => $nina->id, 'quantidade' => 4], ['id' => $mesa->id, 'quantidade' => 1]]], $ator);

        return [$empresa, $nina, $mesa, $combo, $kit, $combit];
    }

    public function test_lista_as_compostas_por_tipo_com_a_composicao_o_envio_e_o_preco_da_precificacao(): void
    {
        [$empresa, $nina, $mesa, $combo, $kit] = $this->cenario();

        $r = app(OfertasCriadas::class)->listar($empresa);

        $this->assertSame(3, $r['total']);
        $this->assertSame(3, $r['mostrando']);
        $this->assertSame(['combo', 'kit', 'combit'], array_column($r['grupos'], 'fase'), 'a ordem dos grupos; as ofertas simples não entram');
        $this->assertSame(['Combos', 'Kits', 'Combits'], array_column($r['grupos'], 'rotulo'));

        $doCombo = $r['grupos'][0]['itens'][0];
        $this->assertSame([$combo->id, 'NINA-CB2', 'Combo 2 Nina', 'combo'], [$doCombo['id'], $doCombo['sku'], $doCombo['nome'], $doCombo['fase']]);
        $this->assertSame([['id' => $nina->id, 'sku' => 'NINA', 'nome' => 'Produto NINA', 'quantidade' => 2]], $doCombo['composicao']);
        // O envio é o da Precificação: as duas caixas somadas passam do limite e viram ME1.
        $this->assertSame(LogisticaProduto::ME1, $doCombo['envio']['chave']);
        $this->assertSame(['c' => 53.0, 'l' => 60.0, 'a' => 160.0, 'peso' => 16.0], $doCombo['envio']['pacote']);
        $this->assertSame('sem_frete', $doCombo['pendencia'], 'fora do envio padrão não há frete a sugerir');

        $doKit = $r['grupos'][1]['itens'][0];
        $this->assertSame($kit->id, $doKit['id']);
        $this->assertSame(['NINA', 'MESA'], array_column($doKit['composicao'], 'sku'));
        $this->assertSame('sem_custo', $doKit['pendencia'], 'a mesa não tem custo: o do kit não fecha');
        $this->assertFalse($doKit['sem_calculo']);
    }

    public function test_a_pagina_traz_a_contagem_em_toda_aba_e_a_lista_so_na_aba_criadas(): void
    {
        [$empresa] = $this->cenario();
        $sessao = $this->withoutVite()->entrarNoPortal($empresa);

        // Nas outras abas: a contagem aparece, a lista não é montada.
        $sessao->get(route('portal.auth.estrutura.sugestoes'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaSugestoes', false)
                ->where('sugestoes.aba', 'sugestoes')->where('sugestoes.contagens.criadas', 3)->where('criadas', null));

        $sessao->get(route('portal.auth.estrutura.sugestoes', ['aba' => 'criadas']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaSugestoes', false)
                ->where('sugestoes.aba', 'criadas')->where('filtros.aba', 'criadas')
                ->where('sugestoes.contagens.criadas', 3)
                ->has('sugestoes.contagens.sugestoes')
                ->where('criadas.total', 3)
                ->where('criadas.grupos.0.fase', 'combo')
                ->where('criadas.grupos.0.itens.0.sku', 'NINA-CB2')
                ->where('criadas.grupos.2.itens.0.composicao.0.quantidade', 4));

        // A página não consulta o Mercado Livre (o que sai é só o aviso interno do layout, de outro serviço).
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'mercadolibre') || str_contains($req->url(), 'mercadolivre'));
    }

    public function test_empresa_sem_nada_criado_e_escopo_por_empresa(): void
    {
        [$empresa] = $this->cenario();
        $outra = $this->empresaDoGabarito();
        $this->simples($outra, 'SO', ['c' => 10, 'l' => 10, 'a' => 10, 'kg' => 1], '5');

        $this->assertSame(['total' => 0, 'mostrando' => 0, 'grupos' => []], app(OfertasCriadas::class)->listar($outra), 'só oferta simples: nada criado');
        $this->assertSame(0, app(OfertasCriadas::class)->contar($outra));
        $this->assertSame(3, app(OfertasCriadas::class)->contar($empresa));
    }
}
