<?php

namespace App\Services\Portal\Estrutura\Geracao;

use App\Services\Portal\Estrutura\Produtos\ListasDaEmpresaService;

/**
 * Tipo do produto (mesa, cadeira, cama...) inferido por palavra-chave.
 *
 * ÚNICA implementação da inferência: a tela só exibe o resultado (D-12). A ficha
 * do produto da 167 não muda. Classe pura: sem banco, sem config e sem relógio;
 * quem chama passa o vocabulário em `$tipos`.
 *
 * Regra: a palavra casa por token inteiro (ou o plural regular dele), nunca por
 * prefixo solto ("mesada" não é mesa). O casamento mais longo vence: os trechos
 * contidos em outro maior são descartados. Categoria primeiro, nome depois;
 * ambíguo na categoria não cai para o nome.
 */
final class TipoDoProduto
{
    /** Sem caixa, sem acento, só [a-z0-9] separado por espaço simples. */
    public static function normalizar(?string $texto): string
    {
        $chave = ListasDaEmpresaService::chave((string) $texto);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $chave));
    }

    /**
     * @param  string|array<int, string>  $palavras  texto separado por vírgula ou lista
     * @return list<string>
     */
    public static function palavras(string|array $palavras): array
    {
        $lista = is_array($palavras) ? $palavras : explode(',', $palavras);
        $saida = [];

        foreach ($lista as $palavra) {
            $norm = self::normalizar((string) $palavra);
            if ($norm !== '' && ! in_array($norm, $saida, true)) {
                $saida[] = $norm;
            }
        }

        return $saida;
    }

    /**
     * @param  array<string, array{palavras: list<string>, ordem: int}>  $tipos
     * @return array{slug: ?string, candidatos: list<string>, fonte: ?string}
     */
    public static function inferir(?string $categoria, ?string $nome, array $tipos): array
    {
        foreach (['categoria' => $categoria, 'nome' => $nome] as $fonte => $texto) {
            $candidatos = self::candidatos(self::normalizar($texto), $tipos);

            if ($candidatos === []) {
                continue;
            }

            return [
                'slug'       => count($candidatos) === 1 ? $candidatos[0] : null,
                'candidatos' => $candidatos,
                'fonte'      => $fonte,
            ];
        }

        return ['slug' => null, 'candidatos' => [], 'fonte' => null];
    }

    /**
     * @param  array{slug: ?string, candidatos: list<string>, fonte?: ?string}  $inferido
     * @param  array<string, mixed>  $tipos
     * @return array{slug: ?string, origem: ?string, candidatos: list<string>}
     */
    public static function efetivo(?string $escolhido, array $inferido, array $tipos): array
    {
        if ($escolhido !== null && $escolhido !== '' && array_key_exists($escolhido, $tipos)) {
            return ['slug' => $escolhido, 'origem' => 'escolhido', 'candidatos' => $inferido['candidatos'] ?? []];
        }

        $slug = $inferido['slug'] ?? null;

        return [
            'slug'       => $slug,
            'origem'     => $slug !== null ? 'inferido' : null,
            'candidatos' => $inferido['candidatos'] ?? [],
        ];
    }

    /**
     * Slugs dos tipos que sobram depois de descartar trechos contidos em outro maior.
     *
     * @param  array<string, array{palavras: list<string>, ordem: int}>  $tipos
     * @return list<string>
     */
    private static function candidatos(string $texto, array $tipos): array
    {
        if ($texto === '') {
            return [];
        }

        $tokens = explode(' ', $texto);
        $achados = []; // cada um: [slug, inicio, fim]

        foreach ($tipos as $slug => $tipo) {
            foreach (self::palavras($tipo['palavras'] ?? []) as $palavra) {
                foreach (self::posicoes($tokens, explode(' ', $palavra)) as [$ini, $fim]) {
                    $achados[] = [(string) $slug, $ini, $fim];
                }
            }
        }

        $sobram = [];
        foreach ($achados as [$slug, $ini, $fim]) {
            $contido = false;
            foreach ($achados as [, $i2, $f2]) {
                if ($i2 <= $ini && $f2 >= $fim && ($f2 - $i2) > ($fim - $ini)) {
                    $contido = true;
                    break;
                }
            }
            if (! $contido) {
                $sobram[$slug] = true;
            }
        }

        $slugs = array_keys($sobram);
        usort($slugs, fn ($a, $b) => [$tipos[$a]['ordem'] ?? 0, $a] <=> [$tipos[$b]['ordem'] ?? 0, $b]);

        return array_map('strval', $slugs);
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $alvo
     * @return list<array{0:int,1:int}>
     */
    private static function posicoes(array $tokens, array $alvo): array
    {
        $achados = [];
        $n = count($alvo);

        for ($i = 0; $i + $n <= count($tokens); $i++) {
            $ok = true;
            for ($j = 0; $j < $n; $j++) {
                if (! self::tokenCasa($tokens[$i + $j], $alvo[$j])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $achados[] = [$i, $i + $n - 1];
            }
        }

        return $achados;
    }

    /** Igual ao token da palavra ou ao plural regular dele (+s, +es, 'l' → 'is'). */
    private static function tokenCasa(string $token, string $palavra): bool
    {
        if ($token === $palavra || $token === $palavra.'s' || $token === $palavra.'es') {
            return true;
        }

        return str_ends_with($palavra, 'l') && $token === substr($palavra, 0, -1).'is';
    }
}
