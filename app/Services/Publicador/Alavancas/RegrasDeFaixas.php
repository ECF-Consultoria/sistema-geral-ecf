<?php

namespace App\Services\Publicador\Alavancas;

use App\Support\Publicador\RegraViolada;

/**
 * Regras locais das faixas de atacado % B2B (`pxq-porcentagem-b2b`): o servidor confere antes de
 * chamar o ML, que valida de novo (e o erro dele fica no histórico).
 */
final class RegrasDeFaixas
{
    /**
     * @param  list<array{id?: ?string, percentual: float|int|string, quantidade_minima: int|string|float}>  $faixas  lista vazia é válida (apaga todas)
     *
     * @throws RegraViolada ALAV-B2B-03 a ALAV-B2B-06
     */
    public static function conferir(array $faixas): void
    {
        if (count($faixas) > 5) {
            throw new RegraViolada('ALAV-B2B-03', 'Cabem no máximo 5 faixas por anúncio.');
        }

        $pares = [];
        foreach ($faixas as $f) {
            $p = $f['percentual'] ?? null;
            if (! is_numeric($p) || (float) $p <= 0 || (float) $p >= 100) {
                throw new RegraViolada('ALAV-B2B-04', 'O percentual de cada faixa precisa ser maior que 0 e menor que 100.');
            }

            $q = $f['quantidade_minima'] ?? null;
            if (! is_numeric($q) || (float) $q != (int) $q || (int) $q < 1 || (int) $q > 100) {
                throw new RegraViolada('ALAV-B2B-05', 'A quantidade mínima é um número inteiro de 1 a 100.');
            }
            $pares[] = [(int) $q, (float) $p];
        }

        usort($pares, fn ($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($pares); $i++) {
            // Quantidade repetida, ou desconto que não cresce junto com a quantidade.
            if ($pares[$i][0] === $pares[$i - 1][0] || $pares[$i][1] <= $pares[$i - 1][1]) {
                throw new RegraViolada('ALAV-B2B-06', 'Os descontos precisam crescer junto com a quantidade.');
            }
        }
    }
}
