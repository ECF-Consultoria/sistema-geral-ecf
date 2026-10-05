<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\RegrasDeDesconto;
use App\Support\Publicador\RegraViolada;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** 166-07 (AL166-10): fronteiras das regras do desconto individual (doc `desconto-individua`). */
class PriceDiscountRegrasTest extends TestCase
{
    private function hoje(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-10 15:30:00', 'America/Sao_Paulo')->startOfDay();
    }

    private function conferir(float $preco, float $deal, ?float $top = null, string $ini = '2026-10-10', string $fim = '2026-10-12', ?float $min = null, ?float $max = null): void
    {
        RegrasDeDesconto::conferir($preco, $deal, $top, $ini, $fim, $this->hoje(), $min, $max);
    }

    private function recusa(string $regra, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Esperava {$regra}.");
        } catch (RegraViolada $e) {
            $this->assertSame($regra, $e->regra, $e->getMessage());
        }
    }

    public function test_desconto_minimo_de_5_por_cento(): void
    {
        $this->conferir(100, 95);
        $this->recusa('ALAV-DESC-01', fn () => $this->conferir(100, 95.01));
    }

    public function test_desconto_precisa_ser_menor_que_80_por_cento(): void
    {
        $this->conferir(100, 20.01);
        $this->recusa('ALAV-DESC-02', fn () => $this->conferir(100, 20));
        $this->recusa('ALAV-DESC-02', fn () => $this->conferir(100, 10));
    }

    public function test_mercado_pontos_5_pontos_ate_35_e_10_pontos_acima(): void
    {
        $this->conferir(100, 80, 75);                 // 20% e 25%
        $this->recusa('ALAV-DESC-03', fn () => $this->conferir(100, 80, 75.01)); // 24,99%
        $this->conferir(100, 60, 50);                 // 40% e 50%
        $this->recusa('ALAV-DESC-03', fn () => $this->conferir(100, 60, 50.01)); // 49,99%
        $this->conferir(100, 65, 59.5);               // 35% e 40,5% (até 35% vale 5 p.p.)
    }

    public function test_top_deal_nao_pode_ser_maior_ou_igual_ao_preco_geral(): void
    {
        $this->recusa('ALAV-DESC-03', fn () => $this->conferir(100, 80, 80));
        $this->recusa('ALAV-DESC-03', fn () => $this->conferir(100, 80, 85));
    }

    public function test_desconto_do_mercado_pontos_tambem_tem_teto_de_80(): void
    {
        $this->recusa('ALAV-DESC-02', fn () => $this->conferir(100, 30, 20));
    }

    public function test_inicio_no_passado_e_recusado_e_hoje_vale(): void
    {
        $this->conferir(100, 90, null, '2026-10-10', '2026-10-10');
        $this->recusa('ALAV-DESC-04', fn () => $this->conferir(100, 90, null, '2026-10-09', '2026-10-12'));
    }

    public function test_prazo_de_14_dias_inclusivos(): void
    {
        $this->conferir(100, 90, null, '2026-10-10', '2026-10-23'); // hoje + 13 = 14 dias
        $this->recusa('ALAV-DESC-05', fn () => $this->conferir(100, 90, null, '2026-10-10', '2026-10-24')); // 15 dias
    }

    public function test_fim_antes_do_inicio_e_recusado(): void
    {
        $this->recusa('ALAV-DESC-05', fn () => $this->conferir(100, 90, null, '2026-10-12', '2026-10-11'));
    }

    public function test_faixa_do_candidato_quando_informada(): void
    {
        $this->conferir(100, 90, null, '2026-10-10', '2026-10-12', 80, 92);
        $this->recusa('ALAV-DESC-06', fn () => $this->conferir(100, 78, null, '2026-10-10', '2026-10-12', 80, 92));
        $this->recusa('ALAV-DESC-06', fn () => $this->conferir(100, 93, null, '2026-10-10', '2026-10-12', 80, 92));
        $this->conferir(100, 78); // sem faixa informada não confere
    }

    public function test_mensagem_traz_os_numeros(): void
    {
        try {
            $this->conferir(100, 95.01);
        } catch (RegraViolada $e) {
            $this->assertStringContainsString('4,99%', $e->getMessage());
        }
    }
}
