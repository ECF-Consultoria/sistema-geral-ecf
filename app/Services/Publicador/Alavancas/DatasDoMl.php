<?php

namespace App\Services\Publicador\Alavancas;

use Carbon\CarbonImmutable;

/**
 * Datas do ML no fuso de São Paulo. A API devolve ora `Z`, ora `-03:00`, ora sem fuso
 * (Armadilhas 4 e 12 do RESEARCH): aqui tudo vira o dia de calendário de São Paulo.
 */
final class DatasDoMl
{
    public const FUSO = 'America/Sao_Paulo';

    /** Início do dia de hoje em São Paulo. */
    public static function hoje(): CarbonImmutable
    {
        return CarbonImmutable::now(self::FUSO)->startOfDay();
    }

    /** Com `Z`/offset converte para SP; sem fuso interpreta em SP; inválido = null. */
    public static function ler(?string $iso): ?CarbonImmutable
    {
        $iso = trim((string) $iso);
        if ($iso === '') {
            return null;
        }

        try {
            $temFuso = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $iso);

            return $temFuso
                ? CarbonImmutable::parse($iso)->setTimezone(self::FUSO)
                : CarbonImmutable::parse($iso, self::FUSO);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dias de calendário em SP até a data; passado é negativo. */
    public static function diasAte(?string $iso, ?CarbonImmutable $hoje = null): ?int
    {
        $data = self::ler($iso);
        if ($data === null) {
            return null;
        }
        $base = ($hoje ?? self::hoje())->setTimezone(self::FUSO)->startOfDay();

        return (int) $base->diffInDays($data->startOfDay(), false);
    }

    public static function inicioDoDia(string $ymd): string
    {
        return "{$ymd}T00:00:00";
    }

    public static function fimDoDia(string $ymd): string
    {
        return "{$ymd}T23:59:59";
    }

    /** Dias contados nas duas pontas (07 a 07 = 1). */
    public static function diasInclusivos(string $de, string $ate): int
    {
        $a = CarbonImmutable::parse($de, self::FUSO)->startOfDay();
        $b = CarbonImmutable::parse($ate, self::FUSO)->startOfDay();

        return (int) $a->diffInDays($b, false) + 1;
    }
}
