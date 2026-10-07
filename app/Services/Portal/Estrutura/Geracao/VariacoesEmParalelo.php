<?php

namespace App\Services\Portal\Estrutura\Geracao;

use Illuminate\Support\Str;

/**
 * Casamento de variações de dois produtos em PARALELO (Fase 168, D-17).
 *
 * Nunca é produto cartesiano: a medição na planilha mostrou que ela nunca cruza
 * 1 variação com 2; dos pares 2x2, 13 usam 2 das 4 combinações. Cada variação
 * aparece no máximo uma vez no resultado.
 *
 * Regras:
 *  - um lado com 1 variação casa com todas as do outro;
 *  - senão, primeiro por eixo + valor (sem caixa e sem acento) quando os dois lados têm;
 *  - o que sobrou casa por posição (ordem, id), PULANDO o par em que as duas têm
 *    valor, pois valores diferentes nunca casam;
 *  - o que sobrar é ignorado.
 *
 * Classe pura: sem banco, sem config, sem relógio.
 */
final class VariacoesEmParalelo
{
    /**
     * Variação = ['id' => int, 'ordem' => int, 'eixo' => ?string, 'valor' => ?string, ...];
     * as chaves extras passam intactas.
     *
     * @param  list<array<string,mixed>>  $a
     * @param  list<array<string,mixed>>  $b
     * @return list<array{0: array<string,mixed>, 1: array<string,mixed>}>
     */
    public static function casar(array $a, array $b): array
    {
        $a = self::ordenar($a);
        $b = self::ordenar($b);

        if ($a === [] || $b === []) {
            return [];
        }

        if (count($a) === 1) {
            return array_map(fn ($vb) => [$a[0], $vb], $b);
        }
        if (count($b) === 1) {
            return array_map(fn ($va) => [$va, $b[0]], $a);
        }

        $pares = [];
        $usadasB = [];

        // Passo 1: mesmo eixo e mesmo valor normalizado.
        foreach ($a as $ia => $va) {
            $chaveA = self::chave($va);
            if ($chaveA === null) {
                continue;
            }
            foreach ($b as $ib => $vb) {
                if (isset($usadasB[$ib]) || self::chave($vb) !== $chaveA) {
                    continue;
                }
                $usadasB[$ib] = true;
                $pares[$ia] = [$va, $vb];
                break;
            }
        }

        // Passo 2: as que sobraram, por posição; par com valor nos dois lados não casa.
        $sobraA = array_keys(array_diff_key($a, $pares));
        $sobraB = array_keys(array_diff_key($b, $usadasB));
        foreach ($sobraA as $pos => $ia) {
            if (! isset($sobraB[$pos])) {
                break;
            }
            $va = $a[$ia];
            $vb = $b[$sobraB[$pos]];
            if (self::temValor($va) && self::temValor($vb)) {
                continue;
            }
            $pares[$ia] = [$va, $vb];
        }

        ksort($pares);

        return array_values($pares);
    }

    /** @return list<array<string,mixed>> */
    private static function ordenar(array $variacoes): array
    {
        $variacoes = array_values($variacoes);
        usort($variacoes, fn ($x, $y) => [$x['ordem'] ?? 0, $x['id']] <=> [$y['ordem'] ?? 0, $y['id']]);

        return $variacoes;
    }

    private static function temValor(array $v): bool
    {
        return trim((string) ($v['valor'] ?? '')) !== '';
    }

    /** Chave eixo+valor normalizada; null quando falta eixo ou valor. */
    private static function chave(array $v): ?string
    {
        $eixo = trim((string) ($v['eixo'] ?? ''));
        if ($eixo === '' || ! self::temValor($v)) {
            return null;
        }

        return self::normalizar($eixo) . '|' . self::normalizar((string) $v['valor']);
    }

    private static function normalizar(string $texto): string
    {
        return mb_strtolower(Str::ascii(trim((string) preg_replace('/\s+/u', ' ', $texto))));
    }
}
