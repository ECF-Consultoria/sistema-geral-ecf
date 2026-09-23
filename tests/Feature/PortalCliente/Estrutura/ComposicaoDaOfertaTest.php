<?php

namespace Tests\Feature\PortalCliente\Estrutura;

use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * A regra de composição, ao pé da letra da aula ("As 4 fases da oferta"):
 * combo = mesmo produto, mais unidades; kit = produtos diferentes juntos;
 * combit = kit com mais unidades de um item.
 */
class ComposicaoDaOfertaTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function recusa(callable $fn, string $campo = 'componentes'): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            return $e->errors()[$campo][0];
        }

        $this->fail('Era para recusar.');
    }

    public function test_cada_fase_recusa_a_composicao_da_outra(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);
        [$cad] = $svc->criar($empresa, ['sku' => 'CAD-01', 'fase' => 'simples'], $ator);
        [$msa] = $svc->criar($empresa, ['sku' => 'MSA-MR', 'fase' => 'simples'], $ator);

        $criar = fn (string $fase, array $comp) => fn () => $svc->criar($empresa, ['sku' => 'X-'.uniqid(), 'fase' => $fase, 'componentes' => $comp], $ator);

        // Simples não tem composição.
        $this->recusa($criar('simples', [['id' => $cad->id, 'quantidade' => 1]]));
        // Combo de 1 unidade não é combo.
        $this->recusa($criar('combo', [['id' => $cad->id, 'quantidade' => 1]]));
        // Combo de dois produtos é kit/combit.
        $this->recusa($criar('combo', [['id' => $cad->id, 'quantidade' => 2], ['id' => $msa->id, 'quantidade' => 2]]));
        // Kit com um produto só não é kit.
        $this->recusa($criar('kit', [['id' => $cad->id, 'quantidade' => 1]]));
        // Kit com mais unidades de um item é combit.
        $this->recusa($criar('kit', [['id' => $msa->id, 'quantidade' => 1], ['id' => $cad->id, 'quantidade' => 4]]));
        // Combit sem nenhum item repetido é kit.
        $this->recusa($criar('combit', [['id' => $msa->id, 'quantidade' => 1], ['id' => $cad->id, 'quantidade' => 1]]));

        $this->assertSame(2, EstruturaOferta::count());
    }

    public function test_componente_tem_de_ser_simples_da_mesma_empresa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);
        $ofertas = $this->listaDoGabarito($empresa, $ator);

        // Um combo não entra como componente.
        $this->recusa(fn () => $svc->criar($empresa, ['sku' => 'X', 'fase' => 'combo',
            'componentes' => [['id' => $ofertas['CAD-01-CB2']->id, 'quantidade' => 2]]], $ator));

        // Produto de OUTRA empresa: mesma mensagem de id inexistente — dizer
        // "é de outro cliente" confirmaria que ele existe.
        $outra = $this->empresaDoGabarito();
        [$alheio] = $svc->criar($outra, ['sku' => 'ALHEIO', 'fase' => 'simples'], $this->atorCliente($outra));

        $msg = $this->recusa(fn () => $svc->criar($empresa, ['sku' => 'X', 'fase' => 'combo',
            'componentes' => [['id' => $alheio->id, 'quantidade' => 2]]], $ator));
        $this->assertStringNotContainsString('empresa', $msg);
    }

    /** Não há o teto de 3 componentes do Planejamento — era limite de coluna. */
    public function test_kit_com_quatro_produtos_e_valido_e_soma_as_unidades(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);

        $ids = [];
        foreach (['A', 'B', 'C', 'D'] as $sku) {
            [$o] = $svc->criar($empresa, ['sku' => $sku, 'fase' => 'simples'], $ator);
            $ids[] = ['id' => $o->id, 'quantidade' => 1];
        }
        $ids[3]['quantidade'] = 3;

        [$combit] = $svc->criar($empresa, ['sku' => 'ABCD', 'fase' => 'combit', 'componentes' => $ids], $ator);

        $linha = \App\Services\Portal\Estrutura\EstruturaConjunto::daEmpresa($empresa)->oferta($combit->id);
        $this->assertSame(6, $linha['unidades']); // 1 + 1 + 1 + 3
    }

    public function test_produto_que_entra_numa_variacao_nao_pode_ser_excluido_nem_deixar_de_ser_simples(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);
        $ofertas = $this->listaDoGabarito($empresa, $ator);

        $msg = $this->recusa(fn () => $svc->excluir($ofertas['MSA-MR'], $ator), 'oferta');
        $this->assertStringContainsString('MSA-MR+CAD-01-KIT', $msg);

        $this->recusa(fn () => $svc->atualizar($ofertas['CAD-01'], ['sku' => 'CAD-01', 'fase' => 'combo',
            'componentes' => [['id' => $ofertas['MSA-MR']->id, 'quantidade' => 2]]], $ator), 'fase');

        $this->assertSame(9, EstruturaOferta::count());
    }

    public function test_sku_e_obrigatorio_mas_pode_repetir(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $svc = app(EstruturaOfertaService::class);

        $this->recusa(fn () => $svc->criar($empresa, ['sku' => '   ', 'fase' => 'simples'], $ator), 'sku');

        // SKU repetido conta como duas ofertas, como na planilha — a tela avisa.
        $svc->criar($empresa, ['sku' => 'Não tenho', 'fase' => 'simples'], $ator);
        $svc->criar($empresa, ['sku' => 'não tenho ', 'fase' => 'simples'], $ator);

        $conjunto = \App\Services\Portal\Estrutura\EstruturaConjunto::daEmpresa($empresa);
        $this->assertSame(2, $conjunto->painel()['ofertas']);
        $this->assertSame(['não tenho' => 2], $conjunto->skusRepetidos());
    }
}
