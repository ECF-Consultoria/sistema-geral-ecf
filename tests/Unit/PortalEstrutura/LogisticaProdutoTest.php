<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Logística provável pelas regras do ML ("seguir o ML em tudo", 09/10/2026): cubado sem o
 * mínimo de 5 kg da planilha antiga e limites do ME2 pela modalidade de envio da conta
 * ("Dimensões permitidas" do ML). Sem modalidade, os limites dos Correios.
 */
class LogisticaProdutoTest extends TestCase
{
    private function vol(float $c, float $l, float $a, float $kg): array
    {
        return ['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg];
    }

    /**
     * O gabarito da planilha relido pelas regras do ML. Os pesos (faturado, cubado) não
     * mudam: em todos estes o cubado passa de 5 kg ou fica abaixo do real. A logística muda
     * onde o Full do ML (até 25 kg, soma 260, lado 120) é mais largo que o da planilha
     * (20 kg, lado 80): 93×55×6, 91×59×20 e 89×57×52 agora cabem no Full.
     */
    public static function gabarito(): array
    {
        return [
            '93x55x6 9,5 full'       => [[93, 55, 6, 9.5], 'me2_full', 9.5, 5.12],
            '91x59x20 13,5 full'     => [[91, 59, 20, 13.5], 'me2_full', 17.90, 17.90],
            '89x57x52 25 full'       => [[89, 57, 52, 25], 'me2_full', 43.97, 43.97],
            '65,5x47x10,5 9 full'    => [[65.5, 47, 10.5, 9], 'me2_full', null, null],
            '74,5x28x6 2,7 full'     => [[74.5, 28, 6, 2.7], 'me2_full', null, null],
            '131x48x17 33 me1'       => [[131, 48, 17, 33], 'me1', null, null],
            '187x44x12 28,5 me1'     => [[187, 44, 12, 28.5], 'me1', null, null],
            '50x40x30 28 me2'        => [[50, 40, 30, 28], 'me2', 28.0, 10.0],
        ];
    }

    #[DataProvider('gabarito')]
    public function test_gabarito_da_planilha_pelas_regras_do_ml(array $m, string $esperado, ?float $faturado, ?float $cubado): void
    {
        $r = LogisticaProduto::daVolumes([$this->vol(...$m)]);

        $this->assertSame($esperado, $r['logistica']);
        if ($faturado !== null) {
            $this->assertEqualsWithDelta($faturado, $r['peso_faturado'], 0.01);
        }
        if ($cubado !== null) {
            $this->assertEqualsWithDelta($cubado, $r['peso_cubado'], 0.01);
        }
    }

    /** Full do ML: "até 25 kg", soma ≤ 260, nenhum lado acima de 120. Um grama a mais e é só ME2. */
    public function test_full_vai_ate_25_kg_soma_260_e_lado_120(): void
    {
        $this->assertSame('me2_full', LogisticaProduto::daVolumes([$this->vol(89, 57, 52, 25)])['logistica']);
        $this->assertSame('me2', LogisticaProduto::daVolumes([$this->vol(89, 57, 52, 25.001)])['logistica']);

        // Na Coleta o ME2 vai a 200 cm de lado: 121 cm é ME2, mas não Full; 120 cm é Full.
        $this->assertSame('me2', LogisticaProduto::daVolumes([$this->vol(121, 30, 20, 5)], 'cross_docking')['logistica']);
        $this->assertSame('me2_full', LogisticaProduto::daVolumes([$this->vol(120, 30, 20, 5)], 'cross_docking')['logistica']);
        // Soma 261 passa do Full mesmo com todos os lados abaixo de 120.
        $this->assertSame('me2', LogisticaProduto::daVolumes([$this->vol(110, 100, 51, 5)], 'cross_docking')['logistica']);
    }

    public function test_pacote_empilhado_de_dois_volumes(): void
    {
        $volumes = [$this->vol(186, 43, 12, 27.8), $this->vol(97, 42, 12, 12.1)];

        $p = LogisticaProduto::pacote($volumes);
        $this->assertSame(186.0, $p['c']);
        $this->assertSame(43.0, $p['l']);
        $this->assertSame(24.0, $p['a']);
        $this->assertEqualsWithDelta(39.9, $p['peso_real'], 0.0001);

        $this->assertSame('me1', LogisticaProduto::daVolumes($volumes)['logistica']);
    }

    public function test_sem_volume_ou_medida_invalida_e_pendente(): void
    {
        $this->assertSame('pendente', LogisticaProduto::avaliar(null)['logistica']);
        $this->assertSame('pendente', LogisticaProduto::daVolumes([])['logistica']);
        $this->assertSame('pendente', LogisticaProduto::daVolumes([$this->vol(10, 0, 5, 1)])['logistica']);
        $this->assertSame('pendente', LogisticaProduto::daVolumes([$this->vol(10, 10, 5, 0)])['logistica']);
    }

    /**
     * Peso faturado = o MAIOR entre real e cubado, sem o mínimo de 5 kg. Prova do ML: a conta
     * #459 cobrou `billable_weight: 750` por 15×15×20 com 500 g (sondagem de 01/10).
     */
    public function test_cubado_sem_corte_de_5_kg_vale_sempre_que_passa_do_real(): void
    {
        $r = LogisticaProduto::daVolumes([$this->vol(15, 15, 20, 0.5)]);
        $this->assertEqualsWithDelta(0.75, $r['peso_cubado'], 0.001);
        $this->assertEqualsWithDelta(0.75, $r['peso_faturado'], 0.001);
        $this->assertTrue($r['cubado_cobrado']);

        // Pequeno também: 20×20×20 com 500 g fatura 1,33 kg (a planilha antiga dava 0,5).
        $r = LogisticaProduto::daVolumes([$this->vol(20, 20, 20, 0.5)]);
        $this->assertEqualsWithDelta(1.33, $r['peso_faturado'], 0.001);
        $this->assertTrue($r['cubado_cobrado']);

        // 91x59x20: cubado 17,9 > real 13,5.
        $this->assertTrue(LogisticaProduto::daVolumes([$this->vol(91, 59, 20, 13.5)])['cubado_cobrado']);
        // 93x55x6: cubado 5,12, mas real 9,5 é maior — vale o real.
        $r = LogisticaProduto::daVolumes([$this->vol(93, 55, 6, 9.5)]);
        $this->assertFalse($r['cubado_cobrado']);
        $this->assertEqualsWithDelta(9.5, $r['peso_faturado'], 0.001);
    }

    /**
     * Limites do ME2 pela modalidade da conta ("Dimensões permitidas", 09/10/2026):
     * Correios 30 kg / soma 200 / lado 100; Agências e Coleta 50 / 300 / 200; Full 25 / 260 / 120.
     */
    public function test_limites_do_me2_pela_modalidade_de_envio(): void
    {
        $grande = [$this->vol(131, 48, 17, 33)];   // lado 131, soma 196, 33 kg
        $this->assertSame('me1', LogisticaProduto::daVolumes($grande)['logistica'], 'sem modalidade = Correios');
        $this->assertSame('me1', LogisticaProduto::daVolumes($grande, 'drop_off')['logistica']);
        $this->assertSame('me2', LogisticaProduto::daVolumes($grande, 'xd_drop_off')['logistica']);
        $this->assertSame('me2', LogisticaProduto::daVolumes($grande, 'cross_docking')['logistica']);
        $this->assertSame('me1', LogisticaProduto::daVolumes($grande, 'fulfillment')['logistica'], '33 kg passa dos 25 do Full');

        // Peso: 45 kg só cabe em Agências/Coleta.
        $pesado = [$this->vol(40, 40, 40, 45)];
        $this->assertSame('me1', LogisticaProduto::daVolumes($pesado, 'drop_off')['logistica']);
        $this->assertSame('me2', LogisticaProduto::daVolumes($pesado, 'cross_docking')['logistica']);

        // Lado 201 e soma 301 passam da Coleta também.
        $this->assertSame('me1', LogisticaProduto::daVolumes([$this->vol(201, 10, 10, 5)], 'cross_docking')['logistica']);
        $this->assertSame('me1', LogisticaProduto::daVolumes([$this->vol(150, 80, 71, 10)], 'xd_drop_off')['logistica']);

        // Nos limites exatos ainda cabe ("até 30 kg", "não pode passar de 200 cm").
        $this->assertSame('me2', LogisticaProduto::daVolumes([$this->vol(100, 60, 40, 30)], 'drop_off')['logistica']);

        // Modalidade sem limites no config (Flex, por exemplo) vale como Correios.
        $this->assertSame('me1', LogisticaProduto::daVolumes($grande, 'self_service')['logistica']);
        $this->assertSame(['peso' => 50, 'soma' => 300, 'maior' => 200], LogisticaProduto::limites('cross_docking'));
        $this->assertSame(LogisticaProduto::limites('drop_off'), LogisticaProduto::limites(null));
    }

    public function test_regras_vem_do_config_quando_passadas(): void
    {
        $regras = config('estrutura_produtos');
        $regras['modalidades']['drop_off']['peso'] = 5;

        $r = LogisticaProduto::avaliar(LogisticaProduto::pacote([$this->vol(30, 20, 10, 9)]), $regras);

        $this->assertSame('me1', $r['logistica']);
    }
}
