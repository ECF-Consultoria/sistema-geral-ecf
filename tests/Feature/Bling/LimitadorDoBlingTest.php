<?php

namespace Tests\Feature\Bling;

use App\Services\Bling\LimitadorDoBling;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

/**
 * Fase 184-06 (BLING-14) — os dois baldes do `LimitadorDoBling` (por conta e
 * global por IP) mais o balde de erros. Nenhum teste aqui depende de tempo
 * real passar: a pausa é sempre substituída por um espião (`esperar()`), e
 * as janelas de 1s/10s dos baldes nunca decaem durante a execução rápida de
 * um único método de teste — por isso cada teste usa um `$contaId` próprio,
 * nunca reaproveitado entre métodos.
 */
class LimitadorDoBlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Defensivo: mesmo com app() fresco por teste (array store também
        // nasce vazio), deixa explícito que os baldes globais partem zerados.
        RateLimiter::clear('bling-ip');
        RateLimiter::clear('bling-erros');
    }

    /**
     * Devolve um `LimitadorDoBling` real, exceto a pausa: `esperar()` vira um
     * espião que registra os milissegundos pedidos em `$pausas` em vez de
     * dormir de verdade.
     *
     * @param  list<int>  $pausas  passada por referência; recebe um elemento por chamada a `esperar()`
     */
    private function comEsperaEspionada(array &$pausas): LimitadorDoBling
    {
        $espiao = Mockery::mock(LimitadorDoBling::class)->makePartial();
        $espiao->shouldAllowMockingProtectedMethods();
        $espiao->shouldReceive('esperar')->andReturnUsing(function (int $ms) use (&$pausas) {
            $pausas[] = $ms;
        });

        return $espiao;
    }

    // ═══ Teste 1 ══════════════════════════════════════════════════════════

    public function test_balde_vazio_devolve_true_e_nao_espera(): void
    {
        $pausas = [];
        $limitador = $this->comEsperaEspionada($pausas);

        $resultado = $limitador->aguardarVez('conta-teste-1');

        $this->assertTrue($resultado);
        $this->assertSame([], $pausas);
    }

    // ═══ Teste 2 ══════════════════════════════════════════════════════════

    public function test_segunda_chamada_na_mesma_conta_dentro_do_mesmo_segundo_espera(): void
    {
        $pausas = [];
        $limitador = $this->comEsperaEspionada($pausas);

        $limitador->aguardarVez('conta-teste-2');
        $limitador->aguardarVez('conta-teste-2');

        $this->assertNotEmpty($pausas, 'A segunda chamada na mesma conta deveria ter pedido uma pausa.');
        $this->assertGreaterThan(0, $pausas[0]);
    }

    // ═══ Teste 3 ══════════════════════════════════════════════════════════

    public function test_contas_diferentes_nao_esperam_uma_pela_outra(): void
    {
        $pausas = [];
        $limitador = $this->comEsperaEspionada($pausas);

        $limitador->aguardarVez('conta-teste-3a');
        $limitador->aguardarVez('conta-teste-3b');

        // Duas contas diferentes, uma chamada cada: nem o balde por conta nem
        // o global (ainda longe do teto) obrigam espera.
        $this->assertSame([], $pausas);
    }

    // ═══ Teste 4 — prova que o balde global NÃO é o balde por conta renomeado ═══

    public function test_ao_atingir_o_teto_global_ate_conta_nunca_usada_espera(): void
    {
        $pausas = [];
        $limitador = $this->comEsperaEspionada($pausas);

        // GLOBAL_POR_SEGUNDO contas DIFERENTES, uma chamada cada: nenhuma
        // delas deveria esperar (o balde por conta de cada uma está vazio, e
        // o balde global só fica cheio depois da última destas).
        for ($i = 1; $i <= LimitadorDoBling::GLOBAL_POR_SEGUNDO; $i++) {
            $limitador->aguardarVez("conta-teste-4-{$i}");
        }
        $this->assertSame([], $pausas, 'Nenhuma das primeiras '.LimitadorDoBling::GLOBAL_POR_SEGUNDO.' contas deveria esperar.');

        // Uma conta NUNCA usada antes (balde por conta vazio) ainda assim
        // espera, porque o balde GLOBAL por IP está cheio. Se o limitador só
        // tivesse o balde por conta, esta chamada passaria direto.
        $limitador->aguardarVez('conta-teste-4-nunca-usada');

        $this->assertNotEmpty($pausas, 'O balde global deveria ter forçado espera numa conta nova.');
    }

    // ═══ Teste 5 — as chaves ═════════════════════════════════════════════

    public function test_chave_por_conta_contem_o_id_e_a_global_e_fixa(): void
    {
        $limitador = new LimitadorDoBling();

        // Conta nova, baldes vazios: esta chamada não precisa esperar de
        // verdade, então usar a classe real (sem espião) é seguro aqui.
        $limitador->aguardarVez('conta-teste-5');

        $this->assertGreaterThan(0, RateLimiter::attempts('bling-conta-conta-teste-5'));
        $this->assertGreaterThan(0, RateLimiter::attempts('bling-ip'));
    }

    // ═══ Teste 6 — balde de erros ════════════════════════════════════════

    public function test_registrar_erro_estoura_o_balde_e_aumenta_a_pausa_da_proxima_chamada(): void
    {
        $pausas = [];
        $limitador = $this->comEsperaEspionada($pausas);

        for ($i = 0; $i < LimitadorDoBling::ERROS_POR_10S; $i++) {
            $limitador->registrarErro();
        }

        // Conta nova, balde por conta e balde global ainda vazios: a ÚNICA
        // razão para esperar aqui é o balde de erros estourado.
        $limitador->aguardarVez('conta-teste-6');

        $this->assertNotEmpty($pausas, 'O balde de erros cheio deveria ter forçado uma pausa extra.');
        $this->assertGreaterThan(0, $pausas[0]);
    }
}
