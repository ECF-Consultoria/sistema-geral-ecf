<?php

// Qual métrica alimenta meta, status e totais do painel — e por que ela é CONFIGURÁVEL.
//
// Essa decisão já virou duas vezes e cada giro custou um deploy:
//
//   260707  gross → móveis   Não dar meta batida a quem não vende móvel.
//   260902  móveis → gross   (00c8e4f3) Os limiares M2/M3/M4 vêm da planilha, que sempre
//                            usou gross, e ninguém os recalibrou; fatiar categoria obriga
//                            a trocar gross→net junto (a Adman só dá netBilling por item);
//                            e a planilha de Evolução não filtra categoria.
//   260910  gross → móveis   Pedido do time ("tirar o cross, deixar só móveis"). A medição
//                            de 202608/202609 derrubou o 2º motivo acima: nas empresas
//                            ~100% móveis a razão móveis/gross tem mediana 98,1% (set) e
//                            91,6% (ago), e NENHUMA das 6 (set) / 7 (ago) que deixam de
//                            bater a meta perde por gross→net — todas por categoria mesmo.
//                            Aceito o custo: o total cai ~14–18% e diverge da planilha.
//
// Por isso a métrica virou `Configuracao::polo_metrica_faturamento` ('moveis' | 'gross'):
// o próximo giro é toggle, não deploy. O default é 'moveis'.
//
// `faturamento_moveis` e `faturamento` seguem AMBOS gravados pelo SyncPolosFaturamentoJob
// em qualquer modo — a coluna "% móveis" da exportação depende dos dois.

namespace Tests\Feature\Polos;

use App\Http\Controllers\PolosController;
use App\Models\Configuracao;
use App\Models\PoloFaturamentoSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/** @group polos */
class FaturamentoMetricaTest extends TestCase
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

    // ─── A métrica vigente ──────────────────────────────────────────────────

    public function test_default_e_moveis_e_o_cross_selling_nao_conta(): void
    {
        // O caso JHOLP: R$ 52 mil na conta, R$ 396 de móvel. Sem chave em Configuracao.
        $this->snapshot('1000000001', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000001']], '202608');

        $this->assertSame(396.40, $fat['1000000001'],
            'Sem configuração explícita o painel mede só Casa/Móveis (MLB1574).');
    }

    public function test_configuracao_gross_devolve_a_conta_inteira(): void
    {
        Configuracao::set('polo_metrica_faturamento', 'gross');
        $this->snapshot('1000000002', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000002']], '202608');

        $this->assertSame(52015.00, $fat['1000000002'],
            'Em modo gross o painel volta ao critério da planilha de Evolução.');
    }

    public function test_valor_invalido_cai_no_default_em_vez_de_quebrar(): void
    {
        // Chave digitada errada no banco NÃO pode derrubar o /polos nem virar coluna
        // inexistente no SELECT — cai em 'moveis'.
        Configuracao::set('polo_metrica_faturamento', 'bruto');
        $this->snapshot('1000000003', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000003']], '202608');

        $this->assertSame(396.40, $fat['1000000003']);
    }

    // ─── A empresa que vende fora da categoria ──────────────────────────────

    public function test_quem_vende_so_fora_de_moveis_entra_zerado(): void
    {
        // Katia Elaine de Almeida (3550303554): 100% Painéis Canaletados, raiz MLB1499.
        // Em modo gross ela marcava 96% da meta M2; em móveis, R$ 0 — que é o pedido.
        $this->snapshot('3550303554', 959.40, 0.0);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '3550303554']], '202608');

        $this->assertArrayHasKey('3550303554', $fat, 'R$ 0 medido ≠ ausência de medição.');
        $this->assertSame(0.0, $fat['3550303554']);
    }

    // ─── O mapa auxiliar de móveis não se confunde com a métrica ────────────

    public function test_moveis_continua_disponivel_em_metodo_proprio(): void
    {
        // Alimenta o "% móveis" da exportação (curadoria de roster) em QUALQUER modo.
        Configuracao::set('polo_metrica_faturamento', 'gross');
        $this->snapshot('1000000004', 52015.00, 396.40);

        $fat = $this->invoca('faturamentoMoveisDoMes', [['cust_id' => '1000000004']], '202608');

        $this->assertSame(396.40, $fat['1000000004']);
    }

    // ─── Invariantes de leitura que valem nos dois modos ────────────────────

    public function test_empresa_sem_snapshot_fica_ausente_do_mapa(): void
    {
        // Ausência ≠ zero: o chamador trata como R$0, mas o mapa não inventa a chave.
        // É o caso da Mabile (1076393622), que a Adman recusa com 500.
        $this->assertSame([], $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '9999999999']], '202608'));
    }

    public function test_zerado_e_preservado_como_zero_legitimo(): void
    {
        $this->snapshot('1000000005', 0.0, 0.0);

        $fat = $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000005']], '202608');

        $this->assertArrayHasKey('1000000005', $fat);
        $this->assertSame(0.0, $fat['1000000005']);
    }

    public function test_mes_sem_snapshot_nao_vaza_de_outro_mes(): void
    {
        $this->snapshot('1000000006', 10000.00, 5000.00);

        $this->assertSame([], $this->invoca('faturamentoAdmanDoMes', [['cust_id' => '1000000006']], '202607'));
    }

    public function test_ativos_sem_cust_id_nao_quebram_a_leitura(): void
    {
        $this->snapshot('1000000007', 7777.00, 1111.00);

        $fat = $this->invoca('faturamentoAdmanDoMes', [
            ['cust_id' => ''], ['cust_id' => null], ['cust_id' => '1000000007'],
        ], '202608');

        $this->assertSame(['1000000007' => 1111.00], $fat);
    }
}
