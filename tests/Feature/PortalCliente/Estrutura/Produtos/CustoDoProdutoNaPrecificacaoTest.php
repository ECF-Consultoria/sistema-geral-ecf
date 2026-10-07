<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Portal\Estrutura\Produtos\ProdutoCadastroService;
use App\Services\Publicador\DadosEfetivosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-08 (D-10): o custo da oferta ligada vem da variação do produto.
 *
 * Modos de falha que estes testes impedem: custo antigo digitado vencendo o do
 * produto (duas verdades); variação sem custo caindo no custo antigo; combo que
 * não soma o custo do produto; oferta sem produto mudando de preço; Precificação
 * sobrescrevendo o custo de oferta ligada; Publicador com preço divergente.
 */
class CustoDoProdutoNaPrecificacaoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function linha(string $codigo, ?string $custo): array
    {
        $l = ['grupo' => 'G'.$codigo, 'codigo' => $codigo, 'nome' => 'Produto '.$codigo, 'valor' => null, 'eixo' => 'cor', 'volumes' => [['c' => 50, 'l' => 40, 'a' => 10, 'kg' => 5]]];
        if ($custo !== null) {
            $l['custo'] = $custo;
        }

        return $l;
    }

    private function ligada(Company $empresa, string $codigo, ?string $custo): EstruturaOferta
    {
        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [$this->linha($codigo, $custo)], $this->atorCliente($empresa));

        return EstruturaOferta::where('company_id', $empresa->id)->where('sku', $codigo)->whereNotNull('variacao_id')->firstOrFail();
    }

    private function pagina(Company $empresa, array $ids): array
    {
        return app(EstruturaPrecificacaoService::class)->pagina($empresa, $ids);
    }

    public function test_o_custo_do_produto_vence_o_digitado_antigo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-1', '100');
        EstruturaPrecificacao::create(['oferta_id' => $o->id, 'custo' => 80, 'frete_classico' => 20, 'frete_premium' => 20]);

        $r = $this->pagina($empresa, [$o->id])['por_oferta'][$o->id];

        $this->assertSame(100.0, $r['custo']['valor']);
        $this->assertSame('produto', $r['custo']['origem']);
        $this->assertTrue($r['do_produto']);
        // 120 / 0,695 = 172,66 → ×1,2 = 207,19 (a conta do gabarito, com custo 100)
        $this->assertSame(207.19, $r['classico']['anunciado']);
    }

    public function test_variacao_sem_custo_deixa_a_oferta_sem_custo_e_nao_cai_no_antigo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $o = $this->ligada($empresa, 'LIG-2', null);
        EstruturaPrecificacao::create(['oferta_id' => $o->id, 'custo' => 80, 'frete_classico' => 20]);

        $p = $this->pagina($empresa, [$o->id]);
        $r = $p['por_oferta'][$o->id];

        $this->assertNull($r['custo']['valor']);
        $this->assertSame('sem_custo', $r['pendencia']);
        $this->assertNull($r['classico']['anunciado']);
        $this->assertSame(1, $p['resumo']['sem_custo']);
    }

    public function test_combo_e_kit_somam_o_custo_do_produto_e_o_digitado_das_ofertas_sem_produto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $base = $this->ligada($empresa, 'LIG-3', '100');
        $svc = app(EstruturaOfertaService::class);
        [$combo] = $svc->criar($empresa, ['sku' => 'LIG-3-CB3', 'fase' => 'combo', 'nome' => 'Combo 3', 'componentes' => [['id' => $base->id, 'quantidade' => 3]]], $ator);
        [$avulsa] = $svc->criar($empresa, ['sku' => 'AVULSA', 'fase' => 'simples', 'nome' => 'Avulsa'], $ator);
        EstruturaPrecificacao::create(['oferta_id' => $avulsa->id, 'custo' => 50]);
        [$kit] = $svc->criar($empresa, ['sku' => 'KIT-1', 'fase' => 'kit', 'nome' => 'Kit', 'componentes' => [['id' => $base->id, 'quantidade' => 1], ['id' => $avulsa->id, 'quantidade' => 1]]], $ator);

        $r = $this->pagina($empresa, [$combo->id, $kit->id, $avulsa->id])['por_oferta'];

        $this->assertSame(['valor' => 300.0, 'origem' => 'componentes', 'calculado' => 300.0], $r[$combo->id]['custo']);
        $this->assertSame(150.0, $r[$kit->id]['custo']['valor']);
        $this->assertFalse($r[$combo->id]['do_produto']);
        // Oferta sem produto: custo digitado, como antes.
        $this->assertSame('digitado', $r[$avulsa->id]['custo']['origem']);
        $this->assertSame(50.0, $r[$avulsa->id]['custo']['valor']);
        $this->assertFalse($r[$avulsa->id]['do_produto']);
    }

    public function test_custo_em_oferta_ligada_e_recusado_mas_frete_grava_sem_mexer_no_custo(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $o = $this->ligada($empresa, 'LIG-4', '100');
        EstruturaPrecificacao::create(['oferta_id' => $o->id, 'custo' => 80]);
        $svc = app(EstruturaPrecificacaoService::class);

        try {
            $svc->salvarOferta($o, ['custo' => 90], $ator);
            $this->fail('Devia recusar o custo.');
        } catch (ValidationException $e) {
            $this->assertSame('O custo desta oferta vem do Produtos. Altere lá.', $e->errors()['custo'][0]);
        }

        $svc->salvarOferta($o, ['frete_classico' => 30], $ator);
        $linha = EstruturaPrecificacao::where('oferta_id', $o->id)->first();
        $this->assertSame(30.0, (float) $linha->frete_classico);
        $this->assertSame(80.0, (float) $linha->custo);
    }

    public function test_publicador_herda_o_custo_da_variacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $ator = $this->atorCliente($empresa);
        $o = $this->ligada($empresa, 'LIG-5', '100');
        app(EstruturaPrecificacaoService::class)->salvarOferta($o, ['frete_classico' => 20, 'frete_premium' => 25], $ator);

        $antes = app(DadosEfetivosService::class)->daOferta($o->fresh());
        $this->assertSame(207.19, $antes['precos']['gold_special']);

        app(ProdutoCadastroService::class)->gravarLinhas($empresa, [$this->linha('LIG-5', '200')], $ator, ProdutoCadastroService::MODO_IMPORTACAO);

        $depois = app(DadosEfetivosService::class)->daOferta($o->fresh());
        $this->assertGreaterThan($antes['precos']['gold_special'], $depois['precos']['gold_special']);
        $this->assertNull(EstruturaPrecificacao::where('oferta_id', $o->id)->value('custo'));
    }
}
