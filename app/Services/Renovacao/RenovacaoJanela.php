<?php

namespace App\Services\Renovacao;

use Carbon\Carbon;

/**
 * Fase 202 (REN-02) — janela de renovação por meses de CALENDÁRIO.
 *
 * Fim de mês usa subMonthsNoOverflow (30/04 menos 2 meses = 28/02), nunca
 * uma conta em dias. Comparações por data (startOfDay), nunca por hora.
 */
class RenovacaoJanela
{
    public const SEM_TERMINO    = 'sem_termino';
    public const VENCIDO        = 'vencido';
    public const NA_JANELA      = 'na_janela';
    public const FORA_DA_JANELA = 'fora_da_janela';

    public static function janelaAbre(Carbon $termino, ?int $meses = null): Carbon
    {
        return $termino->copy()->startOfDay()->subMonthsNoOverflow($meses ?? RenovacaoConfig::antecedenciaMeses());
    }

    public static function situacao(?Carbon $termino, Carbon $hoje): string
    {
        if ($termino === null) {
            return self::SEM_TERMINO;
        }

        $termino = $termino->copy()->startOfDay();
        $hoje    = $hoje->copy()->startOfDay();

        if ($hoje->gt($termino)) {
            return self::VENCIDO;
        }

        return $hoje->gte(self::janelaAbre($termino)) ? self::NA_JANELA : self::FORA_DA_JANELA;
    }

    /** Negativo quando vencido. */
    public static function diasRestantes(?Carbon $termino, Carbon $hoje): ?int
    {
        if ($termino === null) {
            return null;
        }

        return (int) $hoje->copy()->startOfDay()->diffInDays($termino->copy()->startOfDay(), false);
    }

    public static function venceEmAte30Dias(?Carbon $termino, Carbon $hoje): bool
    {
        $dias = self::diasRestantes($termino, $hoje);

        return $dias !== null && $dias >= 0 && $dias <= 30;
    }
}
