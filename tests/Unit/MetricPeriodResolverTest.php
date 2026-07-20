<?php

namespace Tests\Unit;

use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Suite unitária do resolvedor único de período (Fase 100 Plan 01).
 *
 * Cobre os 4 casos obrigatórios do plano canônico
 * (plano-carteira-desempenho-multi-servico.md §1200-1203) + edge cases
 * de clamp, bissexto e virada de ano. Datas sempre fixadas via
 * Carbon::setTestNow() — nunca depende do relógio real.
 */
class MetricPeriodResolverTest extends TestCase
{
    /**
     * As 14 chaves exatas do contrato (plano §554-570).
     */
    private const EXPECTED_KEYS = [
        'mode',
        'period_key',
        'current_start',
        'current_end',
        'baseline_start',
        'baseline_end',
        'days_count',
        'comparison_mode',
        'timezone',
        'data_fresh_until',
        'bonus_payment_month',
        'bonus_competence_month',
        'is_current_month',
        'is_closed',
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolver(): MetricPeriodResolver
    {
        return new MetricPeriodResolver();
    }

    // ─────────────────────── Contrato de shape (PER-01) ───────────────────────

    public function test_contrato_de_shape_retorna_exatamente_14_chaves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame(self::EXPECTED_KEYS, array_keys($resultado));
        $this->assertSame('America/Sao_Paulo', $resultado['timezone']);
    }

    // ─────────────────────── CASO OBRIGATÓRIO §1200 ───────────────────────

    public function test_caso_obrigatorio_mes_atual_20_07_2026_sem_data_fresh_until(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame('2026-07-01', $resultado['current_start']);
        $this->assertSame('2026-07-20', $resultado['current_end']);
        $this->assertSame('2026-06-01', $resultado['baseline_start']);
        $this->assertSame('2026-06-20', $resultado['baseline_end']);
        $this->assertSame('operational', $resultado['mode']);
        $this->assertSame('same_interval_previous_month', $resultado['comparison_mode']);
        $this->assertTrue($resultado['is_current_month']);
        $this->assertFalse($resultado['is_closed']);
        $this->assertNull($resultado['bonus_payment_month']);
        $this->assertNull($resultado['bonus_competence_month']);
    }

    public function test_mes_atual_com_data_fresh_until_informado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve([
            'period_key'       => 'current_month',
            'data_fresh_until' => '2026-07-19',
        ]);

        $this->assertSame('2026-07-19', $resultado['current_end']);
        $this->assertSame('2026-06-19', $resultado['baseline_end']);
    }

    public function test_clamp_data_fresh_until_futuro_nunca_le_dia_nao_consolidado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve([
            'period_key'       => 'current_month',
            'data_fresh_until' => '2026-07-25',
        ]);

        $this->assertSame('2026-07-20', $resultado['current_end']);
        $this->assertSame('2026-06-20', $resultado['baseline_end']);
    }

    public function test_clamp_data_fresh_until_anterior_ao_inicio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve([
            'period_key'       => 'current_month',
            'data_fresh_until' => '2026-06-15',
        ]);

        $this->assertSame('2026-07-01', $resultado['current_end']);
        $this->assertSame('2026-06-01', $resultado['baseline_end']);
    }

    public function test_clamp_de_dia_inexistente_31_marco_vs_fevereiro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-31', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame('2026-03-31', $resultado['current_end']);
        $this->assertSame('2026-02-01', $resultado['baseline_start']);
        $this->assertSame('2026-02-28', $resultado['baseline_end']);
    }

    public function test_fevereiro_bissexto_clamp_respeita_29_dias(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-03-30', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame('2024-02-29', $resultado['baseline_end']);
    }

    public function test_virada_de_ano_mes_atual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame('2026-01-01', $resultado['current_start']);
        $this->assertSame('2026-01-15', $resultado['current_end']);
        $this->assertSame('2025-12-01', $resultado['baseline_start']);
        $this->assertSame('2025-12-15', $resultado['baseline_end']);
    }

    public function test_days_count_do_mes_atual_e_inclusivo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'current_month']);

        $this->assertSame(20, $resultado['days_count']);
    }
}
