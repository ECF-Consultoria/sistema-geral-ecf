<?php

namespace App\Services\Portal\Estrutura\Produtos;

use InvalidArgumentException;

/**
 * Leitura estrita de número digitado por brasileiro ou colado de planilha.
 *
 * Existe porque `floatval('27,8')` devolve 27 em silêncio (Armadilha 12 da
 * pesquisa da Fase 167) — medida e custo errados sem aviso. Aqui o que não é
 * número é recusado com mensagem clara.
 */
final class NumeroBr
{
    public const DINHEIRO = 'dinheiro';
    public const MEDIDA   = 'medida';

    public const MENSAGEM = 'Use só números. Exemplo: 27,8';

    /**
     * @throws InvalidArgumentException quando o texto não é um número positivo
     */
    public static function interpretar(mixed $valor, string $tipo = self::MEDIDA): ?float
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            if ($valor < 0) {
                throw new InvalidArgumentException(self::MENSAGEM);
            }

            return (float) $valor;
        }

        if (! is_string($valor)) {
            throw new InvalidArgumentException(self::MENSAGEM);
        }

        $texto = preg_replace('/R\$|kg|cm|\s+/iu', '', trim($valor));

        if ($texto === '' || $texto === null) {
            return null;
        }

        if (! preg_match('/^[\d.,]+$/', $texto)) {
            throw new InvalidArgumentException(self::MENSAGEM);
        }

        $pontos   = substr_count($texto, '.');
        $virgulas = substr_count($texto, ',');

        if ($pontos > 0 && $virgulas > 0) {
            // O separador que vier por ÚLTIMO é o decimal; o outro é milhar.
            $decimal = strrpos($texto, ',') > strrpos($texto, '.') ? ',' : '.';
            $milhar  = $decimal === ',' ? '.' : ',';

            if (substr_count($texto, $decimal) !== 1) {
                throw new InvalidArgumentException(self::MENSAGEM);
            }
            $texto = str_replace($milhar, '', $texto);
            $texto = str_replace($decimal, '.', $texto);
        } elseif ($virgulas > 0) {
            if ($virgulas > 1) {
                throw new InvalidArgumentException(self::MENSAGEM);
            }
            $texto = str_replace(',', '.', $texto);
        } elseif ($pontos > 0) {
            // Número de milhar não começa com 0: "0.500" é R$ 0,50, não R$ 500 (BE-IN-07).
            $ehMilhar = $tipo === self::DINHEIRO && preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $texto);

            if ($ehMilhar) {
                $texto = str_replace('.', '', $texto);
            } elseif ($pontos > 1) {
                throw new InvalidArgumentException(self::MENSAGEM);
            }
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $texto)) {
            throw new InvalidArgumentException(self::MENSAGEM);
        }

        return (float) $texto;
    }
}
