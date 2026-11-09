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
     * Fase 202-08: fases do mesmo serviço com períodos TODOS IGUAIS (ex.: Acmax,
     * 3 itens `P12M`) são itens em PARALELO, não parcelas em sequência — vale o
     * período (12), nunca a soma (36, que dava término em 2029). Períodos
     * diferentes (P1M+P11M, P3M+P9M) seguem somando (escalonado).
     *
     * @param  iterable<array{servico?: mixed, parcelas?: ?int}>  $fases
     */
    public static function mesesDoGrupo(iterable $fases): int
    {
        $periodosPorServico = [];

        foreach ($fases as $fase) {
            $parcelas = $fase['parcelas'] ?? null;

            if (! is_numeric($parcelas) || (int) $parcelas <= 0) {
                return self::MESES_PADRAO;
            }

            $nome = $fase['servico'] ?? '';
            $periodosPorServico[$nome][] = (int) $parcelas;
        }

        if ($periodosPorServico === []) {
            return self::MESES_PADRAO;
        }

        $mesesPorServico = array_map(
            fn (array $periodos) => count(array_unique($periodos)) === 1 ? $periodos[0] : array_sum($periodos),
            $periodosPorServico
        );

        return max($mesesPorServico);
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
