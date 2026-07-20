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

    // ─────────────────────── CASO OBRIGATÓRIO §1201 (last_closed_month) ───────────────────────

    public function test_caso_obrigatorio_bonus_20_07_2026_ultimo_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'last_closed_month']);

        $this->assertSame('official_bonus', $resultado['mode']);
        $this->assertSame('previous_equal_length_window', $resultado['comparison_mode']);
        $this->assertSame('2026-06-01', $resultado['current_start']);
        $this->assertSame('2026-06-30', $resultado['current_end']);
        $this->assertSame(30, $resultado['days_count']);
        $this->assertSame('2026-05-31', $resultado['baseline_end']);
        $this->assertSame('2026-05-02', $resultado['baseline_start']);
        $this->assertSame('2026-06', $resultado['bonus_competence_month']);
        $this->assertSame('2026-07', $resultado['bonus_payment_month']);
        $this->assertFalse($resultado['is_current_month']);
        $this->assertTrue($resultado['is_closed']);
    }

    public function test_bonus_com_virada_de_ano(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-10', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => 'last_closed_month']);

        $this->assertSame('2025-12', $resultado['bonus_competence_month']);
        $this->assertSame('2026-01', $resultado['bonus_payment_month']);
        $this->assertSame('2025-12-01', $resultado['current_start']);
        $this->assertSame('2025-12-31', $resultado['current_end']);
        $this->assertSame(31, $resultado['days_count']);
        $this->assertSame('2025-10-31', $resultado['baseline_start']);
        $this->assertSame('2025-11-30', $resultado['baseline_end']);
    }

    // ─────────────────────── CASO OBRIGATÓRIO §1202 (mês específico) ───────────────────────

    public function test_caso_obrigatorio_filtro_junho_2026(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => '2026-06']);

        $this->assertSame('closed_period', $resultado['mode']);
        $this->assertSame('previous_equal_length_window', $resultado['comparison_mode']);
        $this->assertSame('2026-06-01', $resultado['current_start']);
        $this->assertSame('2026-06-30', $resultado['current_end']);
        $this->assertSame(30, $resultado['days_count']);
        $this->assertSame('2026-05-02', $resultado['baseline_start']);
        $this->assertSame('2026-05-31', $resultado['baseline_end']);
        $this->assertNull($resultado['bonus_payment_month']);
        $this->assertNull($resultado['bonus_competence_month']);
        $this->assertTrue($resultado['is_closed']);
    }

    public function test_mes_especifico_maio_2026(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => '2026-05']);

        $this->assertSame('2026-05-01', $resultado['current_start']);
        $this->assertSame('2026-05-31', $resultado['current_end']);
        $this->assertSame(31, $resultado['days_count']);
        $this->assertSame('2026-03-31', $resultado['baseline_start']);
        $this->assertSame('2026-04-30', $resultado['baseline_end']);
    }

    public function test_mes_especifico_igual_ao_mes_corrente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve(['period_key' => '2026-07']);

        $this->assertTrue($resultado['is_current_month']);
        $this->assertFalse($resultado['is_closed']);
    }

    // ─────────────────────── CASO OBRIGATÓRIO §1203 (custom) ───────────────────────

    public function test_caso_obrigatorio_custom_01_06_a_15_06(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve([
            'period_key' => 'custom',
            'start'      => '2026-06-01',
            'end'        => '2026-06-15',
        ]);

        $this->assertSame('closed_period', $resultado['mode']);
        $this->assertSame('previous_equal_length_window', $resultado['comparison_mode']);
        $this->assertSame('2026-06-01', $resultado['current_start']);
        $this->assertSame('2026-06-15', $resultado['current_end']);
        $this->assertSame(15, $resultado['days_count']);
        $this->assertSame('2026-05-17', $resultado['baseline_start']);
        $this->assertSame('2026-05-31', $resultado['baseline_end']);
        $this->assertNull($resultado['bonus_payment_month']);
        $this->assertNull($resultado['bonus_competence_month']);
    }

    public function test_custom_cruzando_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $resultado = $this->resolver()->resolve([
            'period_key' => 'custom',
            'start'      => '2026-02-20',
            'end'        => '2026-03-05',
        ]);

        $this->assertSame(14, $resultado['days_count']);
        $this->assertSame('2026-02-19', $resultado['baseline_end']);
        $this->assertSame('2026-02-06', $resultado['baseline_start']);
    }

    // ─────────────────────── Bloco âncora: os 4 casos obrigatórios (PER-05) ───────────────────────

    public function test_casos_obrigatorios_ancora_de_regressao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        // §1200 — mês atual
        $atual = $this->resolver()->resolve(['period_key' => 'current_month']);
        $this->assertSame('2026-07-01', $atual['current_start']);
        $this->assertSame('2026-07-20', $atual['current_end']);
        $this->assertSame('2026-06-01', $atual['baseline_start']);
        $this->assertSame('2026-06-20', $atual['baseline_end']);

        // §1201 — bônus (último mês fechado)
        $bonus = $this->resolver()->resolve(['period_key' => 'last_closed_month']);
        $this->assertSame('2026-06', $bonus['bonus_competence_month']);
        $this->assertSame('2026-07', $bonus['bonus_payment_month']);
        $this->assertSame('2026-06-01', $bonus['current_start']);
        $this->assertSame('2026-06-30', $bonus['current_end']);
        $this->assertSame('2026-05-02', $bonus['baseline_start']);
        $this->assertSame('2026-05-31', $bonus['baseline_end']);

        // §1202 — filtro Junho/2026
        $junho = $this->resolver()->resolve(['period_key' => '2026-06']);
        $this->assertSame('2026-06-01', $junho['current_start']);
        $this->assertSame('2026-06-30', $junho['current_end']);
        $this->assertSame('2026-05-02', $junho['baseline_start']);
        $this->assertSame('2026-05-31', $junho['baseline_end']);

        // §1203 — custom 01/06..15/06
        $custom = $this->resolver()->resolve([
            'period_key' => 'custom',
            'start'      => '2026-06-01',
            'end'        => '2026-06-15',
        ]);
        $this->assertSame('2026-06-01', $custom['current_start']);
        $this->assertSame('2026-06-15', $custom['current_end']);
        $this->assertSame('2026-05-17', $custom['baseline_start']);
        $this->assertSame('2026-05-31', $custom['baseline_end']);
    }

    // ─────────────────────── Gate de contrato (PER-06) ───────────────────────

    public function test_gate_de_contrato_para_todos_os_modos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $casos = [
            ['period_key' => 'current_month'],
            ['period_key' => 'last_closed_month'],
            ['period_key' => '2026-06'],
            ['period_key' => 'custom', 'start' => '2026-06-01', 'end' => '2026-06-15'],
        ];

        foreach ($casos as $filtros) {
            $resultado = $this->resolver()->resolve($filtros);

            $this->assertSame(self::EXPECTED_KEYS, array_keys($resultado), 'period_key=' . $filtros['period_key']);
            $this->assertSame('America/Sao_Paulo', $resultado['timezone']);

            foreach (['current_start', 'current_end', 'baseline_start', 'baseline_end', 'data_fresh_until'] as $campo) {
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $resultado[$campo],
                    "campo {$campo} malformado para period_key={$filtros['period_key']}"
                );
            }

            $this->assertLessThanOrEqual($resultado['current_end'], $resultado['current_start']);
            $this->assertLessThanOrEqual($resultado['baseline_end'], $resultado['baseline_start']);
        }
    }

    // ─────────────────────── Guardas de input inválido ───────────────────────

    public function test_custom_sem_start_e_end_lanca_excecao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolve(['period_key' => 'custom']);
    }

    public function test_period_key_desconhecido_lanca_excecao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20', 'America/Sao_Paulo'));

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver()->resolve(['period_key' => 'nao-existe']);
    }
}
