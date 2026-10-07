<?php

namespace Tests\Unit\PortalEstrutura;

use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticaProdutoTest extends TestCase
{
    private function vol(float $c, float $l, float $a, float $kg): array
    {
        return ['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg];
    }

    public static function gabarito(): array
    {
        return [
            '93x55x6 9,5 me2'        => [[93, 55, 6, 9.5], 'me2', 9.5, 5.12],
            '91x59x20 13,5 me2'      => [[91, 59, 20, 13.5], 'me2', 17.90, 17.90],
            '89x57x52 25 me2'        => [[89, 57, 52, 25], 'me2', 43.97, 43.97],
            '65,5x47x10,5 9 full'    => [[65.5, 47, 10.5, 9], 'me2_full', null, null],
            '74,5x28x6 2,7 full'     => [[74.5, 28, 6, 2.7], 'me2_full', null, null],
            '131x48x17 33 me1'       => [[131, 48, 17, 33], 'me1', null, null],
            '187x44x12 28,5 me1'     => [[187, 44, 12, 28.5], 'me1', null, null],
        ];
    }

    #[DataProvider('gabarito')]
    public function test_gabarito_da_planilha(array $m, string $esperado, ?float $faturado, ?float $cubado): void
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

    public function test_89x57x52_nao_e_full(): void
    {
        $r = LogisticaProduto::daVolumes([$this->vol(89, 57, 52, 25)]);

        $this->assertSame('me2', $r['logistica']);
        $this->assertNotSame('me2_full', $r['logistica']);
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

    public function test_cubado_cobrado_so_acima_do_minimo_e_do_real(): void
    {
        // 91x59x20: cubado 17,9 > 5 e > real 13,5.
        $this->assertTrue(LogisticaProduto::daVolumes([$this->vol(91, 59, 20, 13.5)])['cubado_cobrado']);
        // 93x55x6: cubado 5,12 > 5, mas real 9,5 é maior.
        $this->assertFalse(LogisticaProduto::daVolumes([$this->vol(93, 55, 6, 9.5)])['cubado_cobrado']);
        // cubado pequeno (< mínimo): vale o real.
        $r = LogisticaProduto::daVolumes([$this->vol(20, 20, 20, 0.5)]);
        $this->assertFalse($r['cubado_cobrado']);
        $this->assertEqualsWithDelta(0.5, $r['peso_faturado'], 0.001);
    }

    public function test_regras_vem_do_config_quando_passadas(): void
    {
        $regras = config('estrutura_produtos');
        $regras['me2']['peso'] = 5;

        $r = LogisticaProduto::avaliar(LogisticaProduto::pacote([$this->vol(30, 20, 10, 9)]), $regras);

        $this->assertSame('me1', $r['logistica']);
    }
}
