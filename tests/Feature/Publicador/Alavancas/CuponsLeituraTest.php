<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\CuponsLeitura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-05 (D-08): cupons do vendedor com saldo, usados e orçamento. */
class CuponsLeituraTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function leitura(): CuponsLeitura
    {
        return app(CuponsLeitura::class);
    }

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', self::fixtureAlavanca('doc/promocoes/users_promotions'));
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));
    }

    private function chamadasDeDetalhe(): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => str_starts_with($c['caminho'], '/seller-promotions/promotions/')));
    }

    public function test_lista_so_cupom_do_vendedor_com_saldo_usados_e_orcamento(): void
    {
        $this->cenario();

        $r = $this->leitura()->cupons($this->contaAlavanca());

        $this->assertFalse($r['truncado']);
        $this->assertCount(1, $r['itens']);
        $c = $r['itens'][0];
        $this->assertSame('C-MLB1234', $c['id']);
        $this->assertSame('test_coupon', $c['nome']);
        $this->assertSame('started', $c['status']);
        $this->assertSame('FIXED_PERCENTAGE', $c['sub_type']);
        $this->assertEquals(10, $c['percentual']);
        $this->assertNull($c['valor']);
        $this->assertEquals(1000, $c['compra_minima']);
        $this->assertEquals(200, $c['teto']);
        $this->assertEquals(10000, $c['orcamento']);
        $this->assertEquals(8200, $c['saldo']);
        $this->assertEquals(9, $c['usados']);
        $this->assertSame('NICKNMY_CODE', $c['codigo']);

        $d = $this->chamadasDeDetalhe()[0];
        $this->assertSame('SELLER_COUPON_CAMPAIGN', $d['query']['promotion_type']);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_detalha_no_maximo_o_limite_e_marca_truncado(): void
    {
        $this->cenario();
        config(['publicador.alavancas.limites.cupons_detalhados' => 2]);
        $convites = self::fixtureAlavanca('doc/promocoes/users_promotions');
        $convites['results'] = [];
        for ($i = 1; $i <= 3; $i++) {
            $convites['results'][] = ['id' => "P-MLB90{$i}", 'type' => 'SELLER_COUPON_CAMPAIGN', 'status' => 'started', 'name' => "Cupom {$i}"];
        }
        $convites['paging'] = ['total' => 3];
        $this->responder('GET', '#^/seller-promotions/users/\d+$#', $convites);
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB90\d$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));

        $r = $this->leitura()->cupons($this->contaAlavanca());

        $this->assertCount(2, $r['itens']);
        $this->assertTrue($r['truncado']);
        $this->assertCount(2, $this->chamadasDeDetalhe());
    }

    public function test_falha_nao_fica_em_cache(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', ['message' => 'erro'], 500);

        try {
            $this->leitura()->cupons($this->contaAlavanca());
            $this->fail('deveria lançar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->responder('GET', '#^/seller-promotions/promotions/P-MLB5005$#', self::fixtureAlavanca('doc/cupons/promotion_coupon'));
        $this->assertCount(1, $this->leitura()->cupons($this->contaAlavanca())['itens']);
    }

    public function test_atualizar_refaz_a_leitura_do_detalhe(): void
    {
        $this->cenario();
        $this->leitura()->cupons($this->contaAlavanca());
        $antes = count($this->chamadasDeDetalhe());

        $this->leitura()->cupons($this->contaAlavanca());
        $this->assertCount($antes, $this->chamadasDeDetalhe());

        $this->leitura()->cupons($this->contaAlavanca(), true);
        $this->assertCount($antes + 1, $this->chamadasDeDetalhe());
    }
}
