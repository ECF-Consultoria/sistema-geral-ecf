<?php

namespace App\Services\Contratos;

use Carbon\Carbon;

/**
 * Fase 202 — regra única de vigência (meses) do contrato.
 *
 * Extraída de `ContratoPdfService::vigenciaEmMeses` (quick 260915-fc7): fases
 * do MESMO serviço somam parcelas (escalonado 3 + 9 = 12); entre serviços
 * vale o maior total. Qualquer fase sem parcelas válidas derruba tudo no
 * padrão de 12 meses — nunca soma parcial.
 */
class VigenciaContrato
{
    public const MESES_PADRAO = 12;

    /**
     * @param  iterable<array{servico?: mixed, parcelas?: ?int}>  $fases
     */
    public static function mesesDoGrupo(iterable $fases): int
    {
        $mesesPorServico = [];

        foreach ($fases as $fase) {
            $parcelas = $fase['parcelas'] ?? null;

            if (! is_numeric($parcelas) || (int) $parcelas <= 0) {
                $mesesPorServico = [];

                break;
            }

            $nome = $fase['servico'] ?? '';
            $mesesPorServico[$nome] = ($mesesPorServico[$nome] ?? 0) + (int) $parcelas;
        }

        return $mesesPorServico === [] ? self::MESES_PADRAO : max($mesesPorServico);
    }

    /**
     * Término = início + meses. Decisão de discrição: sem subtrair 1 dia — a
     * data aparece como "calculada" na tela justamente para conferência.
     */
    public static function terminoPorMeses(Carbon $inicio, int $meses): Carbon
    {
        return $inicio->copy()->addMonthsNoOverflow($meses);
    }
}
