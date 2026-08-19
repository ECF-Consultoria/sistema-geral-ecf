<?php

namespace App\Support;

/**
 * Cnpj — helper estático puro para validação de dígito verificador de CNPJ.
 *
 * Quick 260819-guy (2026-08-19) — o projeto não tinha, até aqui, nenhuma
 * checagem de dígito verificador de CNPJ: `ContratoDadosMinimosService`
 * (regra 2) e `ContratoAdminController::atualizarCadastro()` só validavam
 * presença e formato (14 dígitos após remover pontuação) — deliberadamente,
 * por decisão registrada no docblock daquele service. O usuário reverteu
 * essa decisão em 2026-08-19: dígito verificador agora É checado, nos dois
 * lugares.
 *
 * Algoritmo padrão (módulo 11, dois dígitos verificadores, pesos 5-4-3-2-9-
 * 8-7-6-5-4-3-2 para o 1º dígito e 6-5-4-3-2-9-8-7-6-5-4-3-2 para o 2º —
 * mesmo cálculo usado pela Receita Federal e por toda validação de CNPJ em
 * PHP). Aceita CNPJ com ou sem pontuação; rejeita comprimento diferente de
 * 14 dígitos e sequências de dígito repetido (`00000000000000`,
 * `11111111111111`, etc. — matematicamente "válidas" pelo módulo 11, mas
 * nunca emitidas pela Receita).
 */
class Cnpj
{
    /**
     * Valida um CNPJ (com ou sem pontuação) pelo dígito verificador.
     *
     * `26.754.383/0001-87` → true (exemplo usado no plano deste quick).
     * `00000000000000`      → false (todos os dígitos iguais).
     * `123`                  → false (tamanho errado).
     */
    public static function valido(?string $cnpj): bool
    {
        if ($cnpj === null) {
            return false;
        }

        $numeros = preg_replace('/\D/', '', $cnpj) ?? '';

        if (strlen($numeros) !== 14) {
            return false;
        }

        // Sequência de dígito repetido — matematicamente passa no módulo 11,
        // mas nunca é um CNPJ real emitido pela Receita Federal.
        if (preg_match('/^(\d)\1{13}$/', $numeros) === 1) {
            return false;
        }

        $primeiroDigito  = self::calcularDigito(substr($numeros, 0, 12));
        $segundoDigito   = self::calcularDigito(substr($numeros, 0, 12) . $primeiroDigito);

        return $numeros[12] === (string) $primeiroDigito && $numeros[13] === (string) $segundoDigito;
    }

    /**
     * Calcula um dígito verificador pelo módulo 11. `$base` tem 12 dígitos
     * (para o 1º verificador) ou 13 dígitos (para o 2º, já incluindo o
     * primeiro dígito calculado) — o tamanho de `$base` decide qual tabela
     * de pesos usar.
     */
    private static function calcularDigito(string $base): int
    {
        $pesos = strlen($base) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $soma = 0;
        foreach (str_split($base) as $i => $digito) {
            $soma += ((int) $digito) * $pesos[$i];
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
