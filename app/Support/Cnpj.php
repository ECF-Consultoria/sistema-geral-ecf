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
    // ─── Comparação contrato × empresa (quick 260915-mtj) ───────────────────
    // Fonte ÚNICA da regra. `resources/js/Pages/Admin/TabelasContrato.jsx` espelha a mesma lógica
    // (a tela precisa do resultado antes do clique) e `ConfirmacaoConfereCnpjTest` trava que os
    // cinco estados batem nos dois lados. O servidor decide de verdade em
    // `TabelasContratoController::confirmar()`.

    /** Os 14 dígitos são iguais — o contrato é desta empresa. */
    public const COMPARACAO_IGUAL = 'igual';

    /** Mesmos 8 primeiros dígitos, final diferente — matriz e filial da mesma empresa. */
    public const COMPARACAO_MESMA_EMPRESA_OUTRA_UNIDADE = 'mesma_empresa_outra_unidade';

    /** Os 8 primeiros dígitos diferem — o CNPJ é de outra empresa. */
    public const COMPARACAO_DIFERENTE = 'diferente';

    /** A empresa não tem CNPJ cadastrado; o contrato tem. */
    public const COMPARACAO_EMPRESA_SEM_CNPJ = 'empresa_sem_cnpj';

    /** O CNPJ do contrato não foi lido (ou não tem 14 dígitos) — independe da empresa. */
    public const COMPARACAO_CONTRATO_SEM_CNPJ = 'contrato_sem_cnpj';

    /** @var array<int, string> */
    public const COMPARACOES = [
        self::COMPARACAO_IGUAL,
        self::COMPARACAO_MESMA_EMPRESA_OUTRA_UNIDADE,
        self::COMPARACAO_DIFERENTE,
        self::COMPARACAO_EMPRESA_SEM_CNPJ,
        self::COMPARACAO_CONTRATO_SEM_CNPJ,
    ];

    /**
     * Só os dígitos do CNPJ (`'12.345.678/0001-99'` → `'12345678000199'`). `null` vira `''`.
     */
    public static function digitos(?string $cnpj): string
    {
        return preg_replace('/\D/', '', (string) $cnpj) ?? '';
    }

    /**
     * Os 8 primeiros dígitos (identificam a empresa; o resto identifica a unidade). `null` quando o
     * CNPJ não tem exatamente 14 dígitos — sem CNPJ inteiro não dá para afirmar de quem é.
     */
    public static function raiz(?string $cnpj): ?string
    {
        $digitos = self::digitos($cnpj);

        return strlen($digitos) === 14 ? substr($digitos, 0, 8) : null;
    }

    /**
     * Compara o CNPJ lido do contrato com o CNPJ cadastrado na empresa. Devolve um de
     * `self::COMPARACOES`. Ordem das perguntas importa:
     *
     *  1. contrato sem CNPJ utilizável (vazio ou sem 14 dígitos) → `contrato_sem_cnpj`
     *     (não dá para comparar nada, tenha a empresa CNPJ ou não);
     *  2. empresa vazia → `empresa_sem_cnpj`;
     *  3. mesmos dígitos → `igual` (máscara não importa);
     *  4. mesmos 8 primeiros dígitos → `mesma_empresa_outra_unidade`;
     *  5. o resto → `diferente` (inclusive CNPJ da empresa gravado malformado).
     */
    public static function comparar(?string $contrato, ?string $empresa): string
    {
        if (self::raiz($contrato) === null) {
            return self::COMPARACAO_CONTRATO_SEM_CNPJ;
        }

        $digitosEmpresa = self::digitos($empresa);

        if ($digitosEmpresa === '') {
            return self::COMPARACAO_EMPRESA_SEM_CNPJ;
        }

        if ($digitosEmpresa === self::digitos($contrato)) {
            return self::COMPARACAO_IGUAL;
        }

        if (self::raiz($empresa) === self::raiz($contrato)) {
            return self::COMPARACAO_MESMA_EMPRESA_OUTRA_UNIDADE;
        }

        return self::COMPARACAO_DIFERENTE;
    }

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
