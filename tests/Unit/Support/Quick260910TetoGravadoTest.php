<?php

namespace Tests\Unit\Support;

use App\Support\FaixaFaturamento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Quick 260910-l7k — `FaixaFaturamento::tetoGravado()`, o espelho PHP da conversão de borda
 * (gêmea de `tetoGravado()` em `resources/js/lib/faixasFaturamento.js`).
 *
 * Um centavo errado aqui move empresa de faixa — por isso a função é pura e testada isolada,
 * sem banco e sem framework.
 */
class Quick260910TetoGravadoTest extends TestCase
{
    #[Test]
    public function teto_redondo_do_contrato_vira_teto_gravado_com_99(): void
    {
        $this->assertSame(499999.99, FaixaFaturamento::tetoGravado(500000.00));
        $this->assertSame(99999.99, FaixaFaturamento::tetoGravado(100000.00));
        $this->assertSame(2999999.99, FaixaFaturamento::tetoGravado(3000000.00));
    }

    #[Test]
    public function teto_ja_na_convencao_nao_e_subtraido_de_novo(): void
    {
        // Idempotência: reprocessar uma proposta já normalizada não pode virar 499.999,98.
        $this->assertSame(499999.99, FaixaFaturamento::tetoGravado(499999.99));
        $this->assertSame(
            499999.99,
            FaixaFaturamento::tetoGravado(FaixaFaturamento::tetoGravado(500000.00))
        );
    }

    #[Test]
    public function faixa_sem_teto_continua_sem_teto(): void
    {
        $this->assertNull(FaixaFaturamento::tetoGravado(null));
    }

    #[Test]
    public function valor_quebrado_que_nao_termina_em_99_perde_um_centavo(): void
    {
        // Regra única: quem não está na convenção entra nela.
        $this->assertSame(499999.49, FaixaFaturamento::tetoGravado(499999.50));
        $this->assertSame(1000.00, FaixaFaturamento::tetoGravado(1000.01));
    }

    #[Test]
    public function teto_zero_nao_vira_negativo(): void
    {
        $this->assertSame(0.0, FaixaFaturamento::tetoGravado(0.0));
    }
}
