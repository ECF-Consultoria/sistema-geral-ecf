<?php

namespace App\Services\Metrics;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Resolvedor único e determinístico de período do núcleo de resultados
 * (Carteira/Desempenho/Dashboard/Metas).
 *
 * ÚNICO ponto de resolução de período do núcleo — contrato consumido pelas
 * Fases 102-104. Nenhum controller/service consumidor deve montar período
 * na mão (`now()`/`startOfMonth()` inline); todos devem chamar
 * `resolve(array $filtros): array` daqui.
 *
 * Service PURO: só `Carbon`. Sem `Model`, sem `DB`, sem `Http`, sem cache.
 * Determinístico e testável via `Carbon::setTestNow()`.
 *
 * Referência canônica: plano-carteira-desempenho-multi-servico.md
 * §73-230 (regra de período) e §529-578 (shape).
 *
 * ### Dois contextos de leitura (regras de baseline distintas)
 *
 * 1. Operacional (`current_month`): baseline = mesmo intervalo de dias no
 *    mês calendário anterior, alinhado por dia, com clamp para o último dia
 *    do mês anterior quando o dia não existe lá.
 * 2. Fechado/oficial/custom (`last_closed_month`, `YYYY-MM`, `custom`):
 *    baseline = N dias imediatamente anteriores à janela atual (janela de
 *    mesmo tamanho).
 *
 * @see resolve() para o shape completo de retorno (14 chaves).
 */
class MetricPeriodResolver
{
    /**
     * Timezone único do núcleo de resultados — nunca varia por consumidor.
     */
    private const TIMEZONE = 'America/Sao_Paulo';

    /**
     * As 14 chaves exatas do contrato de retorno (plano §554-570). Sempre
     * presentes, sempre nesta ordem.
     */
    private const RESULT_KEYS = [
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

    /**
     * Resolve o período (janela atual + janela comparativa) a partir do
     * filtro da tela.
     *
     * `$filtros` aceita:
     * - `period_key`: `current_month` | `last_closed_month` | `YYYY-MM` | `custom`
     * - `custom` exige `start`/`end` (strings `YYYY-MM-DD`, inclusivas)
     * - `data_fresh_until` (opcional, só usado em `current_month`): string
     *   `YYYY-MM-DD`, INPUT (nunca consultado de banco/Adman); default =
     *   hoje; clamp em `[current_start, hoje]`.
     *
     * @param array<string, mixed> $filtros
     * @return array{
     *     mode: string, period_key: string,
     *     current_start: string, current_end: string,
     *     baseline_start: string, baseline_end: string,
     *     days_count: int, comparison_mode: string, timezone: string,
     *     data_fresh_until: string,
     *     bonus_payment_month: ?string, bonus_competence_month: ?string,
     *     is_current_month: bool, is_closed: bool,
     * }
     *
     * @throws InvalidArgumentException quando `period_key` é desconhecido
     *     ou `custom` está sem `start`/`end`.
     */
    public function resolve(array $filtros): array
    {
        $periodKey = $filtros['period_key'] ?? null;

        return match (true) {
            $periodKey === 'current_month'     => $this->resolveCurrentMonth($filtros),
            $periodKey === 'last_closed_month' => $this->resolveLastClosedMonth(),
            $periodKey === 'custom'            => $this->resolveCustom($filtros),
            is_string($periodKey) && preg_match('/^\d{4}-\d{2}$/', $periodKey) === 1
                => $this->resolveSpecificMonth($periodKey),
            default => throw new InvalidArgumentException(
                "period_key inválido ou ausente: '" . var_export($periodKey, true) . "'. "
                . "Esperado: current_month | last_closed_month | YYYY-MM | custom."
            ),
        };
    }

    // ─────────────────────── Modo 1: operacional (mês atual) ───────────────────────

    /**
     * `current_month` → `mode='operational'`,
     * `comparison_mode='same_interval_previous_month'`.
     *
     * Plano §94-129: current_start = dia 1 do mês corrente; current_end =
     * data_fresh_until (default hoje) clampado em [current_start, hoje];
     * baseline = mesmo intervalo no mês anterior alinhado por dia, com
     * clamp para o último dia do mês anterior quando o dia não existe lá.
     */
    private function resolveCurrentMonth(array $filtros): array
    {
        $now          = $this->now();
        $today        = $now->copy()->startOfDay();
        $currentStart = $now->copy()->startOfMonth()->startOfDay();

        $currentEnd = $this->resolveDataFreshUntil($filtros, $currentStart, $today);

        $prevMonthAnchor     = $currentStart->copy()->subMonthNoOverflow(); // dia 1 do mês anterior
        $prevMonthDaysInMes  = $prevMonthAnchor->daysInMonth;
        $diaAlinhado         = min($currentEnd->day, $prevMonthDaysInMes);

        $baselineStart = $prevMonthAnchor->copy();
        $baselineEnd   = $prevMonthAnchor->copy()->addDays($diaAlinhado - 1);

        $daysCount = (int) $currentStart->diffInDays($currentEnd) + 1;

        return $this->buildResult([
            'mode'                   => 'operational',
            'period_key'             => 'current_month',
            'current_start'          => $currentStart,
            'current_end'            => $currentEnd,
            'baseline_start'         => $baselineStart,
            'baseline_end'           => $baselineEnd,
            'days_count'             => $daysCount,
            'comparison_mode'        => 'same_interval_previous_month',
            'data_fresh_until'       => $currentEnd,
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => true,
            'is_closed'              => false,
        ]);
    }

    /**
     * Resolve `data_fresh_until` do filtro (INPUT — nunca consultado de
     * banco/Adman). Default = hoje. Clamp: > hoje → hoje;
     * < current_start → current_start (T-100-02).
     */
    private function resolveDataFreshUntil(array $filtros, Carbon $currentStart, Carbon $today): Carbon
    {
        $input = $filtros['data_fresh_until'] ?? null;

        $freshUntil = $input !== null
            ? Carbon::parse($input, self::TIMEZONE)->startOfDay()
            : $today->copy();

        if ($freshUntil->gt($today)) {
            $freshUntil = $today->copy();
        }
        if ($freshUntil->lt($currentStart)) {
            $freshUntil = $currentStart->copy();
        }

        return $freshUntil;
    }

    // ─────────────────────── Modo 2: oficial/bônus (último mês fechado) ───────────────────────

    /**
     * `last_closed_month` → `mode='official_bonus'`,
     * `comparison_mode='previous_equal_length_window'`.
     *
     * Plano §131-173: competência = mês calendário anterior ao corrente
     * (jan/2026 → competência dez/2025). current = mês completo da
     * competência. baseline = N dias imediatamente anteriores a
     * current_start (janela de mesmo tamanho — `baselineJanelaMesmoTamanho`).
     * bonus_competence_month = competência; bonus_payment_month =
     * competência + 1 mês (= mês corrente).
     */
    private function resolveLastClosedMonth(): array
    {
        $now            = $this->now();
        $competenceStart = $now->copy()->startOfMonth()->subMonthNoOverflow()->startOfDay();

        $currentStart = $competenceStart->copy();
        $currentEnd   = $competenceStart->copy()->endOfMonth()->startOfDay();
        $daysCount    = $currentStart->daysInMonth;

        [$baselineStart, $baselineEnd] = $this->baselineJanelaMesmoTamanho($currentStart, $daysCount);

        return $this->buildResult([
            'mode'                   => 'official_bonus',
            'period_key'             => 'last_closed_month',
            'current_start'          => $currentStart,
            'current_end'            => $currentEnd,
            'baseline_start'         => $baselineStart,
            'baseline_end'           => $baselineEnd,
            'days_count'             => $daysCount,
            'comparison_mode'        => 'previous_equal_length_window',
            'data_fresh_until'       => $currentEnd,
            'bonus_payment_month'    => $competenceStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'bonus_competence_month' => $competenceStart->format('Y-m'),
            'is_current_month'       => false,
            'is_closed'              => true,
        ]);
    }

    // ─────────────────────── Modo 3: mês específico (YYYY-MM) ───────────────────────

    /**
     * `YYYY-MM` → `mode='closed_period'`,
     * `comparison_mode='previous_equal_length_window'`.
     *
     * Plano §199-201: current = mês calendário completo selecionado;
     * baseline = janela-de-mesmo-tamanho imediatamente anterior;
     * `bonus_*=null` (bônus oficial é SÓ `last_closed_month`); `is_closed`
     * = último dia do mês selecionado < hoje; `is_current_month` = mês
     * selecionado == mês corrente.
     */
    private function resolveSpecificMonth(string $periodKey): array
    {
        $monthStart = Carbon::parse($periodKey . '-01', self::TIMEZONE)->startOfMonth()->startOfDay();
        $currentEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        $daysCount  = $monthStart->daysInMonth;

        [$baselineStart, $baselineEnd] = $this->baselineJanelaMesmoTamanho($monthStart, $daysCount);

        $today = $this->now()->startOfDay();

        return $this->buildResult([
            'mode'                   => 'closed_period',
            'period_key'             => $periodKey,
            'current_start'          => $monthStart,
            'current_end'            => $currentEnd,
            'baseline_start'         => $baselineStart,
            'baseline_end'           => $baselineEnd,
            'days_count'             => $daysCount,
            'comparison_mode'        => 'previous_equal_length_window',
            'data_fresh_until'       => $currentEnd,
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => $monthStart->format('Y-m') === $today->format('Y-m'),
            'is_closed'              => $currentEnd->lt($today),
        ]);
    }

    // ─────────────────────── Modo 4: custom (período personalizado fechado) ───────────────────────

    /**
     * `custom` → `mode='closed_period'`,
     * `comparison_mode='previous_equal_length_window'`.
     *
     * Plano §203-206: valida presença de `start`/`end` (T-100-01),
     * `current_start`=`start`, `current_end`=`end` (inclusivas),
     * `days_count`=diffInDays+1, baseline via
     * `baselineJanelaMesmoTamanho()`. `bonus_*=null`, `is_current_month=false`,
     * `is_closed` = (`end` < hoje).
     *
     * @throws InvalidArgumentException quando `start`/`end` ausentes.
     */
    private function resolveCustom(array $filtros): array
    {
        if (!isset($filtros['start'], $filtros['end'])) {
            throw new InvalidArgumentException(
                "period_key 'custom' exige 'start' e 'end' (strings YYYY-MM-DD) no filtro."
            );
        }

        $currentStart = Carbon::parse($filtros['start'], self::TIMEZONE)->startOfDay();
        $currentEnd   = Carbon::parse($filtros['end'], self::TIMEZONE)->startOfDay();
        $daysCount    = (int) $currentStart->diffInDays($currentEnd) + 1;

        [$baselineStart, $baselineEnd] = $this->baselineJanelaMesmoTamanho($currentStart, $daysCount);

        $today = $this->now()->startOfDay();

        return $this->buildResult([
            'mode'                   => 'closed_period',
            'period_key'             => 'custom',
            'current_start'          => $currentStart,
            'current_end'            => $currentEnd,
            'baseline_start'         => $baselineStart,
            'baseline_end'           => $baselineEnd,
            'days_count'             => $daysCount,
            'comparison_mode'        => 'previous_equal_length_window',
            'data_fresh_until'       => $currentEnd,
            'bonus_payment_month'    => null,
            'bonus_competence_month' => null,
            'is_current_month'       => false,
            'is_closed'              => $currentEnd->lt($today),
        ]);
    }

    // ─────────────────────── Helper compartilhado: janela de mesmo tamanho ───────────────────────

    /**
     * N dias imediatamente anteriores a `$currentStart` (inclusivos),
     * N = `$daysCount`. Usado pelos modos `last_closed_month`, `YYYY-MM`
     * e `custom` (janela-de-mesmo-tamanho — plano §158-173/199-206).
     *
     * `baseline_end` = `$currentStart` − 1 dia;
     * `baseline_start` = `baseline_end` − (`$daysCount` − 1) dias.
     *
     * @return array{0: Carbon, 1: Carbon} [baselineStart, baselineEnd]
     */
    private function baselineJanelaMesmoTamanho(Carbon $currentStart, int $daysCount): array
    {
        $baselineEnd   = $currentStart->copy()->subDay();
        $baselineStart = $baselineEnd->copy()->subDays($daysCount - 1);

        return [$baselineStart, $baselineEnd];
    }

    // ─────────────────────── Helpers de tempo/formatação ───────────────────────

    private function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Monta o array final garantindo as 14 chaves na ordem do contrato,
     * `timezone` fixo e datas serializadas `Y-m-d`.
     *
     * @param array{
     *     mode: string, period_key: string,
     *     current_start: Carbon, current_end: Carbon,
     *     baseline_start: Carbon, baseline_end: Carbon,
     *     days_count: int, comparison_mode: string,
     *     data_fresh_until: Carbon,
     *     bonus_payment_month: ?string, bonus_competence_month: ?string,
     *     is_current_month: bool, is_closed: bool,
     * } $dados
     * @return array<string, mixed>
     */
    private function buildResult(array $dados): array
    {
        $resultado = [
            'mode'                   => $dados['mode'],
            'period_key'             => $dados['period_key'],
            'current_start'          => $dados['current_start']->toDateString(),
            'current_end'            => $dados['current_end']->toDateString(),
            'baseline_start'         => $dados['baseline_start']->toDateString(),
            'baseline_end'           => $dados['baseline_end']->toDateString(),
            'days_count'             => $dados['days_count'],
            'comparison_mode'        => $dados['comparison_mode'],
            'timezone'               => self::TIMEZONE,
            'data_fresh_until'       => $dados['data_fresh_until']->toDateString(),
            'bonus_payment_month'    => $dados['bonus_payment_month'],
            'bonus_competence_month' => $dados['bonus_competence_month'],
            'is_current_month'       => $dados['is_current_month'],
            'is_closed'              => $dados['is_closed'],
        ];

        // Defensivo: garante ordem/presença exata das 14 chaves do contrato.
        return array_replace(array_flip(self::RESULT_KEYS), $resultado);
    }
}
