<?php

namespace App\Support\Publicador;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dia útil do Publicador (prazo das tarefas pós-publicação, 09/10/2026): pula sábado, domingo, os
 * feriados nacionais de data fixa e as datas de `publicador.feriados` (os móveis — Carnaval, Sexta-feira
 * Santa, Corpus Christi — e o que a ECF quiser acrescentar). Sempre no fuso de São Paulo: a publicação
 * das 23h de sexta é de sexta, não de sábado.
 */
final class DiasUteis
{
    private const FUSO = 'America/Sao_Paulo';

    /**
     * Feriados nacionais de data fixa (mm-dd): Leis 662/1949, 6.802/1980 (Aparecida) e 14.759/2023
     * (Consciência Negra, nacional desde 2024).
     */
    public const FIXOS = [
        '01-01' => 'Confraternização Universal',
        '04-21' => 'Tiradentes',
        '05-01' => 'Dia do Trabalho',
        '09-07' => 'Independência',
        '10-12' => 'Nossa Senhora Aparecida',
        '11-02' => 'Finados',
        '11-15' => 'Proclamação da República',
        '11-20' => 'Consciência Negra',
        '12-25' => 'Natal',
    ];

    public static function ehUtil(CarbonInterface $dia): bool
    {
        $d = CarbonImmutable::instance($dia)->setTimezone(self::FUSO);
        if ($d->isWeekend()) {
            return false;
        }
        if (isset(self::FIXOS[$d->format('m-d')])) {
            return false;
        }

        return ! in_array($d->format('Y-m-d'), self::moveis(), true);
    }

    /**
     * O N-ésimo dia útil DEPOIS do dia de `$desde` (D+N útil): publicado na sexta 09/10/2026, D+1 é a
     * terça 13/10 (sábado, domingo e Aparecida no meio). Devolve a data à meia-noite de São Paulo.
     */
    public static function somar(CarbonInterface $desde, int $dias = 1): CarbonImmutable
    {
        $d = CarbonImmutable::instance($desde)->setTimezone(self::FUSO)->startOfDay();
        $faltam = max(0, $dias);
        while ($faltam > 0) {
            $d = $d->addDay();
            if (self::ehUtil($d)) {
                $faltam--;
            }
        }

        return $d;
    }

    /** @return list<string> as datas `Y-m-d` de `publicador.feriados` que são datas válidas */
    private static function moveis(): array
    {
        return array_values(array_filter(
            array_map(fn ($v) => trim((string) $v), (array) config('publicador.feriados', [])),
            fn (string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1,
        ));
    }
}
