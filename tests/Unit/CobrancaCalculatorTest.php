<?php

namespace Tests\Unit;

use App\Models\Servico;
use App\Support\CobrancaCalculator;
use Tests\TestCase;

/**
 * Testes unitários puros do helper CobrancaCalculator.
 *
 * Não usa RefreshDatabase nem touca DB — os contratos passados em `novo()`
 * são objetos anônimos construídos em memória. Per CONTEXT.md D-10 e
 * RESEARCH §3 Pitfall 4 (helper estático precisa rodar sem container).
 */
class CobrancaCalculatorTest extends TestCase
{
    /**
     * Helper interno: cria objeto anônimo simulando um ContratoServico
     * com a relação `servico` já carregada (eager-loaded shape).
     */
    private function contratoMock(float $valor, string $tipo, bool $ativo): object
    {
        return (object) [
            'ativo'            => $ativo,
            'valor_contratado' => $valor,
            'servico'          => (object) ['tipo_cobranca' => $tipo],
        ];
    }

    // ─── legacy() — 4 cenários ──────────────────────────────────────────────

    public function test_legacy_sem_faixa_e_sem_adicional_retorna_zero(): void
    {
        $this->assertEqualsWithDelta(
            0.0,
            CobrancaCalculator::legacy(null, null),
            0.001,
            'legacy(null, null) deve retornar 0.0',
        );
    }

    public function test_legacy_com_faixa_e_sem_adicional_retorna_apenas_faixa(): void
    {
        $this->assertEqualsWithDelta(
            3000.0,
            CobrancaCalculator::legacy(['valor' => 3000.00], null),
            0.001,
            'legacy com faixa 3000 e adicional null deve retornar 3000.0',
        );
    }

    public function test_legacy_sem_faixa_e_com_adicional_retorna_apenas_adicional(): void
    {
        $this->assertEqualsWithDelta(
            200.5,
            CobrancaCalculator::legacy(null, 200.50),
            0.001,
            'legacy(null, 200.50) deve retornar 200.5',
        );
    }

    public function test_legacy_com_faixa_e_adicional_soma_ambos(): void
    {
        $this->assertEqualsWithDelta(
            3200.5,
            CobrancaCalculator::legacy(['valor' => 3000.00], 200.50),
            0.001,
            'legacy(faixa=3000, adicional=200.50) deve retornar 3200.5',
        );
    }

    // ─── novo() — 4 cenários ────────────────────────────────────────────────

    public function test_novo_sem_faixa_e_contratos_vazios_retorna_zero(): void
    {
        $this->assertEqualsWithDelta(
            0.0,
            CobrancaCalculator::novo(null, []),
            0.001,
            'novo(null, []) deve retornar 0.0',
        );
    }

    public function test_novo_com_faixa_e_contratos_vazios_retorna_apenas_faixa(): void
    {
        $this->assertEqualsWithDelta(
            3000.0,
            CobrancaCalculator::novo(['valor' => 3000.00], []),
            0.001,
            'novo com faixa 3000 e contratos vazios deve retornar 3000.0',
        );
    }

    public function test_novo_filtra_tipo_cobranca_unica_e_soma_apenas_mensal(): void
    {
        // Cenário: contrato mensal de R$ 150 + contrato única de R$ 50.
        // Helper deve filtrar único (não-mensal) e devolver apenas a soma mensal.
        $contratos = [
            $this->contratoMock(150.00, Servico::TIPO_MENSAL, true),
            $this->contratoMock(50.00,  Servico::TIPO_UNICA,  true),
        ];

        $this->assertEqualsWithDelta(
            150.0,
            CobrancaCalculator::novo(null, $contratos),
            0.001,
            'novo deve filtrar tipo_cobranca=unica e somar apenas mensais',
        );
    }

    public function test_novo_ignora_contratos_inativos_e_soma_apenas_ativos_mais_faixa(): void
    {
        // Cenário: faixa 3000 + 1 contrato mensal INATIVO (150) + 1 ATIVO (200).
        // Resultado esperado: 3000 + 200 = 3200 (ignora o inativo).
        $contratos = [
            $this->contratoMock(150.00, Servico::TIPO_MENSAL, false),
            $this->contratoMock(200.00, Servico::TIPO_MENSAL, true),
        ];

        $this->assertEqualsWithDelta(
            3200.0,
            CobrancaCalculator::novo(['valor' => 3000.00], $contratos),
            0.001,
            'novo deve ignorar contratos inativos e somar apenas mensais ativos + faixa',
        );
    }
}
