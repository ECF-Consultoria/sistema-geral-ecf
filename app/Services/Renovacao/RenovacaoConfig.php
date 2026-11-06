<?php

namespace App\Services\Renovacao;

use App\Models\Configuracao;

/**
 * Fase 202 (D-06/D-08) — chaves de configuração da renovação.
 *
 * `ativa()` controla o AGENDADOR (nasce DESLIGADO). `simulacao()` controla
 * qualquer escrita no HubSpot (agendador e botão manual): ao ligar, a primeira
 * coisa que acontece é simulação.
 */
class RenovacaoConfig
{
    public const CHAVE_ATIVA        = 'renovacao_automatica_ativa';
    public const CHAVE_SIMULACAO    = 'renovacao_modo_simulacao';
    public const CHAVE_ANTECEDENCIA = 'renovacao_antecedencia_meses';

    public const ANTECEDENCIA_PADRAO = 2;

    public static function ativa(): bool
    {
        return Configuracao::get(self::CHAVE_ATIVA, '0') === '1';
    }

    public static function simulacao(): bool
    {
        return Configuracao::get(self::CHAVE_SIMULACAO, '1') !== '0';
    }

    public static function antecedenciaMeses(): int
    {
        $valor = Configuracao::get(self::CHAVE_ANTECEDENCIA, self::ANTECEDENCIA_PADRAO);

        if (! is_numeric($valor)) {
            return self::ANTECEDENCIA_PADRAO;
        }

        $n = (int) $valor;

        return ($n >= 1 && $n <= 12) ? $n : self::ANTECEDENCIA_PADRAO;
    }
}
