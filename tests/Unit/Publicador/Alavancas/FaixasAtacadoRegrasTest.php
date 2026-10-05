<?php

namespace Tests\Unit\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\RegrasDeFaixas;
use App\Support\Publicador\RegraViolada;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 166-09: regras locais das faixas de atacado % B2B. */
class FaixasAtacadoRegrasTest extends TestCase
{
    private function f(float $p, int|float $q, ?string $id = null): array
    {
        return ['id' => $id, 'percentual' => $p, 'quantidade_minima' => $q];
    }

    public static function invalidas(): array
    {
        $f = fn ($p, $q) => ['percentual' => $p, 'quantidade_minima' => $q];

        return [
            'seis faixas' => [[$f(1, 1), $f(2, 2), $f(3, 3), $f(4, 4), $f(5, 5), $f(6, 6)], 'ALAV-B2B-03'],
            'percentual zero' => [[$f(0, 2)], 'ALAV-B2B-04'],
            'percentual cem' => [[$f(100, 2)], 'ALAV-B2B-04'],
            'quantidade zero' => [[$f(5, 0)], 'ALAV-B2B-05'],
            'quantidade 101' => [[$f(5, 101)], 'ALAV-B2B-05'],
            'quantidade fracionada' => [[$f(5, 2.5)], 'ALAV-B2B-05'],
            'quantidades repetidas' => [[$f(5, 2), $f(8, 2)], 'ALAV-B2B-06'],
            'desconto que nao cresce' => [[$f(10, 2), $f(8, 5)], 'ALAV-B2B-06'],
            'desconto igual' => [[$f(10, 2), $f(10, 5)], 'ALAV-B2B-06'],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_recusa(array $faixas, string $codigo): void
    {
        try {
            RegrasDeFaixas::conferir($faixas);
            $this->fail("devia lançar {$codigo}");
        } catch (RegraViolada $e) {
            $this->assertSame($codigo, $e->regra);
        }
    }

    public function test_lista_vazia_e_valida_e_apaga_tudo(): void
    {
        RegrasDeFaixas::conferir([]);
        $this->assertTrue(true);
    }

    public function test_cinco_faixas_crescentes_em_qualquer_ordem_passam(): void
    {
        RegrasDeFaixas::conferir([$this->f(9, 10), $this->f(3, 2, '12'), $this->f(5, 4), $this->f(7, 6), $this->f(8, 8)]);
        $this->assertTrue(true);
    }
}
