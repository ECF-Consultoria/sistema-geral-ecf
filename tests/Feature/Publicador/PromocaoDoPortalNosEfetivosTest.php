<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaProduto;
use App\Models\EstruturaProdutoVariacao;
use App\Models\PubProduto;
use App\Services\Publicador\DadosEfetivosService;
use App\Support\Publicador\Payload\Alvo;
use App\Support\Publicador\RascunhoSnapshot;
use App\Support\Publicador\Variacao\Variante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 10/10/2026 — a Precificação do Portal de verdade (sem mock) chega ao Publicador com o `minimo` (o preço
 * da Central de Promoções) e a marca `sem_frete`, por tipo e por cor, ao lado do `anunciado` de sempre.
 * O exemplo do usuário: custo 100 + frete 20 com os padrões → Clássico anuncia 207,19, promoção 172,66.
 */
class PromocaoDoPortalNosEfetivosTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_oferta_entrega_o_minimo_e_a_marca_sem_frete_por_tipo(): void
    {
        $c = Company::factory()->create();
        $com = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'CAD-01', 'fase' => 'simples', 'nome' => 'Cadeira']);
        // Frete digitado nos dois tipos: a conta não depende de frete sugerido nem do "outro tipo".
        EstruturaPrecificacao::create(['oferta_id' => $com->id, 'custo' => 100, 'frete_classico' => 20, 'frete_premium' => 20]);
        $sem = EstruturaOferta::create(['company_id' => $c->id, 'sku' => 'CAD-02', 'fase' => 'simples', 'nome' => 'Cadeira 2']);
        EstruturaPrecificacao::create(['oferta_id' => $sem->id, 'custo' => 100, 'frete_classico' => null, 'frete_premium' => null]);
        $svc = app(DadosEfetivosService::class);

        $e = $svc->daOferta($com->fresh());
        $this->assertSame(['gold_special' => 207.19, 'gold_pro' => 223.26], $e['precos']);
        $this->assertSame(['gold_special' => 172.66, 'gold_pro' => 186.05], $e['promocoes']);
        $this->assertSame(['gold_special' => false, 'gold_pro' => false], $e['sem_frete']);

        $s = $svc->daOferta($sem->fresh());
        $this->assertSame(['gold_special' => true, 'gold_pro' => true], $s['sem_frete'], 'a conta saiu com frete zero, marcada');
        $this->assertSame(143.88, $s['promocoes']['gold_special']);

        // O produto ligado à oferta entrega o mesmo, e o produto solto (sem oferta) nada.
        $this->assertSame($e, $svc->daProduto(PubProduto::daOferta($com)));
        $solto = $svc->daProduto(PubProduto::create(['company_id' => $c->id, 'sku' => 'SOLTO', 'nome' => 'Solto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]));
        $this->assertSame(['gold_special' => null, 'gold_pro' => null], $solto['promocoes']);
        $this->assertSame(['gold_special' => false, 'gold_pro' => false], $solto['sem_frete']);
    }

    public function test_produto_agrupado_traz_minimo_e_sem_frete_de_cada_cor(): void
    {
        $c = Company::factory()->create();
        $p = EstruturaProduto::create(['company_id' => $c->id, 'codigo' => 'MESA', 'nome' => 'Mesa']);
        $ofertas = [];
        foreach ([1 => 20, 2 => null] as $i => $frete) {
            $v = EstruturaProdutoVariacao::create(['produto_id' => $p->id, 'company_id' => $c->id, 'ordem' => $i, 'codigo' => "MESA-{$i}", 'eixo' => 'cor', 'valor' => "Cor {$i}", 'custo' => 100]);
            $ofertas[$i] = EstruturaOferta::create(['company_id' => $c->id, 'variacao_id' => $v->id, 'sku' => "MESA-{$i}", 'fase' => 'simples', 'nome' => "Mesa {$i}"]);
            EstruturaPrecificacao::create(['oferta_id' => $ofertas[$i]->id, 'frete_classico' => $frete, 'frete_premium' => $frete]);
        }
        $produto = PubProduto::create(['company_id' => $c->id, 'oferta_id' => $ofertas[1]->id, 'estrutura_produto_id' => $p->id, 'sku' => 'MESA-1', 'nome' => 'Mesa', 'origem' => PubProduto::ORIGEM_PORTAL]);

        $e = app(DadosEfetivosService::class)->daProduto($produto);

        $this->assertSame(['gold_special' => 207.19, 'gold_pro' => 223.26], $e['precos_por_variante']['mesa-1']);
        $this->assertSame(['gold_special' => 172.66, 'gold_pro' => 186.05], $e['promocoes_por_variante']['mesa-1']);
        $this->assertSame(['gold_special' => false, 'gold_pro' => false], $e['sem_frete_por_variante']['mesa-1']);
        $this->assertSame(['gold_special' => true, 'gold_pro' => true], $e['sem_frete_por_variante']['mesa-2']);

        // No rascunho, cada cor recebe o Portal da SUA oferta; a cor sem casamento, o da âncora.
        $snap = (new RascunhoSnapshot('MLB1', variantes: [
            new Variante('COLOR=Cor 1', [], dados: ['atributos' => ['SELLER_SKU' => ['value_name' => 'MESA-1']]]),
            new Variante('COLOR=Cor 2', [], dados: ['atributos' => ['SELLER_SKU' => ['value_name' => 'MESA-2']]]),
            new Variante('COLOR=Cor 3', [], dados: ['atributos' => ['SELLER_SKU' => ['value_name' => 'OUTRA']], 'precos' => ['gold_special' => 250.0]]),
        ], alvos: [new Alvo('gold_special', 'Mesa')]))->comEfetivosDe($e);

        $this->assertSame(['anunciado' => 207.19, 'minimo' => 172.66, 'sem_frete' => false], $snap->variantes[0]->dados['portal']['gold_special']);
        $this->assertSame(['gold_special' => true], $snap->variantes[0]->dados['preco_do_portal']);
        $this->assertTrue($snap->variantes[1]->dados['portal']['gold_special']['sem_frete'], 'a cor 2 não tem frete na Precificação');
        $this->assertSame(['anunciado' => 207.19, 'minimo' => 172.66, 'sem_frete' => false], $snap->variantes[2]->dados['portal']['gold_special'], 'sem casamento: o da âncora');
        $this->assertArrayNotHasKey('preco_do_portal', $snap->variantes[2]->dados, 'preço digitado não veio do Portal');
        $this->assertSame(250.0, $snap->variantes[2]->dados['precos']['gold_special']);
    }
}
