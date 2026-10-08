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
 * contidos em outro maior são descartados.
 *
 * Categoria primeiro, e ela só decide quando aponta UM tipo. Categoria ambígua
 * ("Bancos e Banquetas") ou sem tipo cai para o nome (08/10). No nome vence o tipo
 * do NÚCLEO: o que aparece primeiro; empate na posição, o trecho mais longo
 * ("Gabinete Armário Banheiro com Nichos" é gabinete; "Espelho com Prateleira" é
 * espelho). Se o nome também não tiver tipo, a ambiguidade da categoria fica
 * registrada nos candidatos e o produto vai para o painel "Sem tipo".
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
        $daCategoria = self::achados(self::normalizar($categoria), $tipos);
        $slugsCategoria = self::slugsOrdenados($daCategoria, $tipos);

        if (count($slugsCategoria) === 1) {
            return ['slug' => $slugsCategoria[0], 'candidatos' => $slugsCategoria, 'fonte' => 'categoria'];
        }

        $doNome = self::achados(self::normalizar($nome), $tipos);
        if ($doNome !== []) {
            return [
                'slug'       => self::doNucleo($doNome, $tipos),
                'candidatos' => self::slugsOrdenados($doNome, $tipos),
                'fonte'      => 'nome',
            ];
        }

        if ($slugsCategoria !== []) {
            return ['slug' => null, 'candidatos' => $slugsCategoria, 'fonte' => 'categoria'];
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
     * Trechos que sobram depois de descartar os contidos em outro maior.
     *
     * @param  array<string, array{palavras: list<string>, ordem: int}>  $tipos
     * @return list<array{0:string,1:int,2:int}>  [slug, início, fim] por token
     */
    private static function achados(string $texto, array $tipos): array
    {
        if ($texto === '') {
            return [];
        }

        $tokens = explode(' ', $texto);
        $achados = [];

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
                $sobram[] = [$slug, $ini, $fim];
            }
        }

        return $sobram;
    }

    /**
     * Slugs distintos dos trechos, pela ordem do tipo (desempate pelo slug).
     *
     * @param  list<array{0:string,1:int,2:int}>  $achados
     * @return list<string>
     */
    private static function slugsOrdenados(array $achados, array $tipos): array
    {
        $slugs = array_values(array_unique(array_column($achados, 0)));
        usort($slugs, fn ($a, $b) => [$tipos[$a]['ordem'] ?? 0, $a] <=> [$tipos[$b]['ordem'] ?? 0, $b]);

        return array_map('strval', $slugs);
    }

    /**
     * O tipo do núcleo do nome: o trecho que começa primeiro; empate, o mais longo;
     * empate ainda, a ordem do tipo.
     *
     * @param  list<array{0:string,1:int,2:int}>  $achados
     */
    private static function doNucleo(array $achados, array $tipos): string
    {
        usort($achados, fn ($x, $y) => [$x[1], $y[2] - $y[1], $tipos[$x[0]]['ordem'] ?? 0, $x[0]]
            <=> [$y[1], $x[2] - $x[1], $tipos[$y[0]]['ordem'] ?? 0, $y[0]]);

        return $achados[0][0];
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
