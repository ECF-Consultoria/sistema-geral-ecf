<?php

// O painel mede GROSS da conta, não a fatia de Casa/Móveis.
//
// Entre 260707 e 260902 servia `faturamento_moveis` (raiz MLB1574, netBilling por item).
// Revertido porque:
//   - os limiares M2=1.000/M3=4.000/M4=8.000 vêm da planilha, que sempre usou gross, e
//     ninguém os recalibrou quando o insumo mudou — a meta ficou ~13% mais difícil;
//   - fatiar por categoria obrigou a trocar gross→net junto (a Adman só dá netBilling por
//     item): dos ~13% de queda, ~11 pontos eram a métrica e só ~3 a categoria;
//   - a planilha de Evolução NÃO filtra categoria — traz a JHOLP com R$ 50.818 (conta
//     inteira), não com os R$ 396 de móveis dela.
//
// `faturamento_moveis` segue calculado e vira a coluna "% móveis" da exportação: a
// pergunta "essa empresa vende móvel mesmo?" é de ROSTER, não de métrica.

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\PoloFaturamentoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** @group polos */
class FaturamentoGrossTest extends TestCase
{
    use RefreshDatabase;

    private function invoca(string $metodo, array $ativos, string $mes): array
    {
        $m = new ReflectionMethod(PolosController::class, $metodo);
        $m->setAccessible(true);

        return $m->invoke(app(PolosController::class), $ativos, $mes);
    }

    private function snapshot(string $cust, float $gross, float $moveis): void
    {
        PoloFaturamentoSnapshot::create([
            'mes' => '202608', 'cust_id' => $cust,
            'faturamento' => $gross, 'faturamento_moveis' => $moveis,
            'ads' => 0, 'synced_at' => now(),
        ]);
    }

    public function test_painel_le_o_gross_e_nao_a_fatia_de_moveis(): void
    {
        // O caso JHOLP: R$ 52 mil na conta, R$ 396 de móvel.
        $this->snapshot('1000000001', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000001']], '202608');

        $this->assertSame(52015.00, $fat['1000000001'],
            'O painel mede a conta inteira — é o mesmo critério da planilha de Evolução.');
    }

    public function test_moveis_continua_disponivel_em_metodo_proprio(): void
    {
        // Não foi apagado: alimenta o "% móveis" da exportação (curadoria de roster).
        $this->snapshot('1000000002', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoMoveisDoMes', [['cust_id' => '1000000002']], '202608');

        $this->assertSame(396.40, $fat['1000000002']);
    }

    public function test_empresa_sem_snapshot_fica_ausente_do_mapa(): void
    {
        // Ausência ≠ zero: o chamador trata como R$0, mas o mapa não inventa a chave.
        $this->assertSame([], $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '9999999999']], '202608'));
    }

    public function test_gross_zerado_e_preservado_como_zero_legitimo(): void
    {
        // R$ 0 medido é diferente de nunca medido — só o primeiro conta como "Não vendeu".
        $this->snapshot('1000000003', 0.0, 0.0);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000003']], '202608');

        $this->assertArrayHasKey('1000000003', $fat);
        $this->assertSame(0.0, $fat['1000000003']);
    }

    public function test_mes_sem_snapshot_nao_vaza_de_outro_mes(): void
    {
        $this->snapshot('1000000004', 10000.00, 5000.00);

        $this->assertSame([], $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000004']], '202607'));
    }

    public function test_ativos_sem_cust_id_nao_quebram_a_leitura(): void
    {
        $this->snapshot('1000000005', 7777.00, 1111.00);

        $fat = $this->invoca('faturamentoAdmanDoMes', [
            ['cust_id' => ''], ['cust_id' => null], ['cust_id' => '1000000005'],
        ], '202608');

        $this->assertSame(['1000000005' => 7777.00], $fat);
    }
}
