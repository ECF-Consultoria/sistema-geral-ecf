<?php

namespace App\Services\Portal\Estrutura\Geracao;

use Illuminate\Validation\ValidationException;

/**
 * Leitura das quantidades de Combo/Combit por tipo e por produto (Fase 168,
 * PR168-04/05).
 *
 * Armadilha 5: o middleware ConvertEmptyStringsToNull transforma '' em null, por
 * isso "nenhuma quantidade" é gravada como '0' e "vazio" significa "herda o
 * padrão do tipo".
 *
 * Classe pura: usa só ValidationException do framework, sem banco.
 */
final class Quantidades
{
    public const MENSAGEM = 'Use números inteiros de 2 a 999, separados por vírgula.';

    /**
     * null ou só espaços => null (herda); '0' => [] (nenhuma);
     * "2, 4, 6" => [2, 4, 6] (únicos e ordenados). Inválido lança.
     *
     * @return array<int,int>|null
     */
    public static function ler(?string $texto, string $campo = 'quantidades', int $maxItens = 8): ?array
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        $tokens = preg_split('/[,;\s]+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === ['0']) {
            return [];
        }

        $numeros = [];
        foreach ($tokens as $token) {
            if (! ctype_digit($token) || strlen($token) > 3) {
                self::recusar($campo);
            }
            $n = (int) $token;
            if ($n < 2 || $n > 999) {
                self::recusar($campo);
            }
            $numeros[$n] = $n;
        }

        if (count($numeros) > $maxItens) {
            self::recusar($campo);
        }

        sort($numeros);

        return array_values($numeros);
    }

    /** @param array<int,int>|null $lista */
    public static function paraTexto(?array $lista): ?string
    {
        if ($lista === null) {
            return null;
        }

        return $lista === [] ? '0' : implode(', ', $lista);
    }

    /**
     * Nível do TIPO: null, '' ou '0' => nenhuma; senão a lista lida.
     *
     * @return array<int,int>
     */
    public static function doTipo(?string $texto): array
    {
        if ($texto === null || trim($texto) === '' || trim($texto) === '0') {
            return [];
        }

        return self::ler($texto) ?? [];
    }

    /**
     * O valor do produto, quando existe, vence (inclusive [] = nenhuma).
     *
     * @param  array<int,int>|null  $doProduto
     * @param  array<int,int>  $doTipo
     * @return array<int,int>
     */
    public static function efetivas(?array $doProduto, array $doTipo): array
    {
        return $doProduto ?? $doTipo;
    }

    private static function recusar(string $campo): never
    {
        throw ValidationException::withMessages([$campo => self::MENSAGEM]);
    }
}
