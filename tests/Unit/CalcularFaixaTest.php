<?php

namespace Tests\Unit;

use Tests\TestCase;

class CalcularFaixaTest extends TestCase
{
    private function calcularFaixaPublic(float $faturamento): array
    {
        $controller = new \App\Http\Controllers\AdminController();
        $method     = new \ReflectionMethod($controller, 'calcularFaixa');
        $method->setAccessible(true);
        return $method->invoke($controller, $faturamento);
    }

    public function test_faturamento_zero_retorna_ate_499k(): void
    {
        $this->assertSame(['faixa' => 'ate_499k', 'valor' => 3000.0], $this->calcularFaixaPublic(0.0));
    }

    public function test_limite_exato_499k_retorna_ate_499k(): void
    {
        $this->assertSame(['faixa' => 'ate_499k', 'valor' => 3000.0], $this->calcularFaixaPublic(499999.99));
    }

    public function test_500k_exato_retorna_500k_999k(): void
    {
        $this->assertSame(['faixa' => '500k_999k', 'valor' => 4500.0], $this->calcularFaixaPublic(500000.0));
    }

    public function test_1m_exato_retorna_1m_1999k(): void
    {
        $this->assertSame(['faixa' => '1m_1999k', 'valor' => 6000.0], $this->calcularFaixaPublic(1000000.0));
    }

    public function test_2m_exato_retorna_2m_2999k(): void
    {
        $this->assertSame(['faixa' => '2m_2999k', 'valor' => 7500.0], $this->calcularFaixaPublic(2000000.0));
    }

    public function test_3m_exato_retorna_3m_3999k(): void
    {
        $this->assertSame(['faixa' => '3m_3999k', 'valor' => 9000.0], $this->calcularFaixaPublic(3000000.0));
    }

    public function test_4m_exato_retorna_4m_4999k(): void
    {
        $this->assertSame(['faixa' => '4m_4999k', 'valor' => 10500.0], $this->calcularFaixaPublic(4000000.0));
    }

    public function test_5m_exato_retorna_maxima(): void
    {
        $this->assertSame(['faixa' => 'maxima', 'valor' => 12000.0], $this->calcularFaixaPublic(5000000.0));
    }

    public function test_acima_5m_retorna_maxima(): void
    {
        $this->assertSame(['faixa' => 'maxima', 'valor' => 12000.0], $this->calcularFaixaPublic(6000000.0));
    }
}
