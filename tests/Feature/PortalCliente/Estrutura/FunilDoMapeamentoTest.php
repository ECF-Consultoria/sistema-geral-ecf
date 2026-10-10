<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoAtributo;
use App\Models\MlCategoriaSchema;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\FunilDoMapeamento;
use App\Services\Portal\Estrutura\Geracao\ListaDeSugestoes;
use App\Services\Portal\Estrutura\Produtos\FichaTecnicaDaCategoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CatalogoSinteticoDeSugestoes;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;
use Tests\Unit\Publicador\Concerns\CarregaSchemas;

/**
 * O funil do Mapeamento (09/10/2026): números que as telas JÁ calculam — produtos com cadastro a
 * completar (`PendenciasDoProduto` + ficha técnica), combinações para revisar e produtos sem tipo
 * (os mesmos da tela de Planejamento), ofertas sem preço fechado (o resumo da Precificação) e o
 * que está à venda (a régua do painel). Nenhuma requisição externa.
 */
class FunilDoMapeamentoTest extends TestCase
{
    use CarregaSchemas;
    use CatalogoSinteticoDeSugestoes;
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function funil($empresa): array
    {
        return app(FunilDoMapeamento::class)->daEmpresa($empresa);
    }

    public function test_empresa_vazia_da_zeros(): void
    {
        $f = $this->funil($this->empresaDoGabarito());

        $this->assertSame(['total' => 0, 'pendentes' => 0, 'com_pendencia' => 0, 'ficha_incompleta' => 0], $f['produtos']);
        $this->assertSame(['sugestoes' => 0, 'sem_tipo' => 0], $f['planejamento']);
        $this->assertSame(0, $f['precificacao']['total']);
        $this->assertSame(['ofertas' => 0, 'a_venda' => 0, 'completas' => 0, 'sem_nada' => 0], $f['venda']);
    }

    public function test_planejamento_e_precificacao_sao_os_numeros_das_proprias_telas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->catalogoSintetico($empresa, $this->atorCliente($empresa));

        $f = $this->funil($empresa);
        $tela = app(ListaDeSugestoes::class)->listar($empresa, ['aba' => 'sugestoes'], 1)['contagens'];
        $this->assertSame(['sugestoes' => $tela['sugestoes'], 'sem_tipo' => $tela['sem_tipo']], $f['planejamento']);
        $this->assertGreaterThan(0, $f['planejamento']['sugestoes']);

        $resumo = app(EstruturaPrecificacaoService::class)->pagina($empresa, [])['resumo'];
        $this->assertSame($resumo['sem_custo'] + $resumo['sem_frete'] + $resumo['impossivel'], $f['precificacao']['pendentes']);
        foreach (['total', 'precificadas', 'sem_custo', 'sem_frete', 'impossivel'] as $k) {
            $this->assertSame($resumo[$k], $f['precificacao'][$k], $k);
        }
    }

    public function test_produto_com_dado_faltando_ou_ficha_tecnica_incompleta_e_pendente(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->catalogoSintetico($empresa, $this->atorCliente($empresa));
        $schema = self::schema(self::CADEIRA);
        MlCategoriaSchema::create(['category_id' => self::CADEIRA, 'domain_id' => $schema->dominio(), 'categoria' => $schema->categoria,
            'atributos' => $schema->atributos, 'technical_specs' => $schema->technicalSpecs, 'sale_terms' => $schema->saleTerms,
            'schema_hash' => $schema->hash(), 'fetched_at' => now()]);

        // A mesma régua da tela de Produtos: os produtos com alguma linha com pendência.
        $daTelaDeProdutos = function () use ($empresa): int {
            $ids = EstruturaProduto::where('company_id', $empresa->id)->pluck('id')->all();

            return collect(app(\App\Services\Portal\Estrutura\Produtos\ProdutoLinhas::class)->paraProdutos($empresa, $ids))
                ->filter(fn ($l) => $l['pendencias'] !== [])->pluck('produto_id')->unique()->count();
        };

        // Todos com categoria (a do catálogo sintético é só texto): sobram o banco sem medidas, a cadeira sem
        // família e o que vai por transportadora sem frete — os mesmos da tela de Produtos.
        EstruturaProduto::where('company_id', $empresa->id)->update(['categoria_ml_id' => 'MLB0000']);
        $f = $this->funil($empresa)['produtos'];
        $this->assertSame(10, $f['total']);
        $semFicha = $daTelaDeProdutos();
        $this->assertGreaterThan(0, $semFicha);
        $this->assertSame(['com_pendencia' => $semFicha, 'ficha_incompleta' => 0, 'pendentes' => $semFicha],
            ['com_pendencia' => $f['com_pendencia'], 'ficha_incompleta' => $f['ficha_incompleta'], 'pendentes' => $f['pendentes']],
            'categoria sem definição guardada não é conferida (nenhuma requisição externa)');

        // A cadeira Solo (sem pendência de cadastro) na categoria guardada, sem nenhum obrigatório: ficha incompleta.
        $cadeira = EstruturaProduto::where('company_id', $empresa->id)->where('nome', 'Cadeira Solo')->firstOrFail();
        $cadeira->update(['categoria_ml_id' => self::CADEIRA]);
        $this->assertSame($semFicha, $daTelaDeProdutos(), 'a cadeira não tem pendência de cadastro');
        $f = $this->funil($empresa)['produtos'];
        $this->assertSame([$semFicha, 1, $semFicha + 1], [$f['com_pendencia'], $f['ficha_incompleta'], $f['pendentes']]);

        // Preenchidos todos os obrigatórios (menos o Modelo, que é gerado depois), ela sai.
        // A Cadeira Solo não tem eixo: a ficha dela é a da categoria sem eixo nenhum fora.
        $grupos = FichaTecnicaDaCategoria::doProduto(FichaTecnicaDaCategoria::daAtributos($schema->atributos), []);
        foreach (FichaTecnicaDaCategoria::camposPorId($grupos) as $id => $c) {
            if (! $c['obrigatorio'] || $id === 'MODEL') {
                continue;
            }
            EstruturaProdutoAtributo::create(['company_id' => $empresa->id, 'produto_id' => $cadeira->id, 'atributo_id' => $id, 'atributo_nome' => $id,
                'valor' => $c['valores'] !== [] ? $c['valores'][0]['nome'] : '3', 'valor_id' => $c['valores'] !== [] ? (string) $c['valores'][0]['id'] : null]);
        }
        $f = $this->funil($empresa)['produtos'];
        $this->assertSame([$semFicha, 0, $semFicha], [$f['com_pendencia'], $f['ficha_incompleta'], $f['pendentes']]);
    }

    /** O Mapeamento abre sem esperar o funil: a prop é ADIADA e vem num pedido parcial logo depois. */
    public function test_o_mapeamento_entrega_o_funil_adiado(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        $pagina = $this->withoutVite()->entrarNoPortal($empresa)
            ->get(route('portal.auth.estrutura.mapeamento'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Portal/EstruturaMapeamento')->missing('funil'))
            ->viewData('page');
        $this->assertContains('funil', $pagina['deferredProps']['default'] ?? []);

        $parcial = $this->withoutVite()->entrarNoPortal($empresa)
            ->withHeaders([
                'X-Inertia'                   => 'true',
                'X-Inertia-Version'           => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Data'      => 'funil',
                'X-Inertia-Partial-Component' => 'Portal/EstruturaMapeamento',
            ])
            ->get(route('portal.auth.estrutura.mapeamento'))->assertOk();

        $this->assertSame($this->funil($empresa), $parcial->json('props.funil'));
        $this->assertArrayNotHasKey('estrutura', $parcial->json('props'), 'o pedido do funil não recalcula a página');
    }

    public function test_a_venda_pela_regua_do_painel(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $this->anunciosDoGabarito($this->listaDoGabarito($empresa, $ator), $ator);

        // 9 ofertas; CAD-01 (os dois lados), CAD-01-CB2 e MSA-MR têm algo no ar; só a CAD-01 está completa.
        $this->assertSame(['ofertas' => 9, 'a_venda' => 3, 'completas' => 1, 'sem_nada' => 6], $this->funil($empresa)['venda']);
    }
}
