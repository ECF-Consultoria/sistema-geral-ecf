<?php

namespace App\Support\Publicador;

use App\Support\Publicador\Variacao\ChaveCanonica;

/**
 * Termos que o Mercado Livre VETA em título e descrição (decisão do usuário, 10/10/2026).
 *
 * O ML pausou um anúncio de teste da #459 por "criado-mudo" no título — infração LENGUAJE ("palavras
 * inapropriadas", política 1020) — e o título tinha vindo da IA. Por isso há duas pontas:
 * - o que a IA escreve (título, Modelo, descrição, "Anunciar por IA") passa por `trocar()`, que põe o termo aceito;
 * - o que a equipe digitar trava na conferência (`ValidadorRascunho`: V-TIT-05 no título, V-DES-05 na descrição)
 *   e já aparece na visão rápida da publicação em lote (`bloqueiosSemSchema`).
 *
 * A lista mora na config (`publicador.termos_vetados`: termo vetado → troca). Comparar ignora caixa, acento e
 * hífen × espaço ("Criado Mudo" = "criado-mudo"), e nunca pega pedaço de outra palavra.
 */
final class TermosVetados
{
    /** Os vetos conhecidos (a config parte daqui e pode acrescentar). Termo sem acento → troca. */
    public const PADRAO = [
        'criado-mudo' => 'mesa de cabeceira',
        'criados-mudos' => 'mesas de cabeceira',
    ];

    /** Letra → a classe que também casa as acentuadas (o termo da config é escrito sem acento). */
    private const ACENTOS = [
        'a' => '[aàáâãä]', 'e' => '[eèéêë]', 'i' => '[iìíîï]', 'o' => '[oòóôõö]', 'u' => '[uùúûü]', 'c' => '[cç]', 'n' => '[nñ]',
    ];

    /**
     * Os termos vetados (como estão na config) que o texto contém, na ordem da config.
     *
     * @return list<string>
     */
    public static function encontrar(?string $texto): array
    {
        $alvo = ' '.self::chave((string) $texto).' ';
        $saida = [];
        foreach (self::lista() as $termo => $troca) {
            if (str_contains($alvo, ' '.self::chave($termo).' ')) {
                $saida[] = $termo;
            }
        }

        return $saida;
    }

    /** O termo aceito no lugar do vetado (o da config). */
    public static function troca(string $termo): string
    {
        return self::lista()[$termo] ?? '';
    }

    /** O texto com cada termo vetado trocado pelo aceito; a caixa da troca segue a do trecho original. */
    public static function trocar(string $texto): string
    {
        foreach (self::lista() as $termo => $troca) {
            $texto = (string) preg_replace_callback(self::padrao($termo), fn (array $m) => self::naCaixaDe($m[0], $troca), $texto);
        }

        return $texto;
    }

    /** @return array<string, string> termo vetado → troca (fora do Laravel — teste de unidade puro —, a PADRAO) */
    private static function lista(): array
    {
        $bruta = app()->bound('config') ? config('publicador.termos_vetados', self::PADRAO) : self::PADRAO;
        $saida = [];
        foreach ((array) $bruta as $termo => $troca) {
            if (is_string($termo) && self::chave($termo) !== '' && is_string($troca) && trim($troca) !== '') {
                $saida[trim($termo)] = trim($troca);
            }
        }

        return $saida;
    }

    /** A forma de comparar: minúsculas, sem acento, só letras e números separados por um espaço. */
    private static function chave(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', ChaveCanonica::texto($texto)));
    }

    /** O termo no texto ORIGINAL: palavras separadas por espaço ou hífen, com ou sem acento, nunca pedaço de palavra. */
    private static function padrao(string $termo): string
    {
        $palavras = array_map(
            fn (string $p) => implode('', array_map(fn (string $l) => self::ACENTOS[$l] ?? preg_quote($l, '/'), mb_str_split($p))),
            explode(' ', self::chave($termo)),
        );

        return '/(?<![\p{L}\p{N}])'.implode('[\s\-]+', $palavras).'(?![\p{L}\p{N}])/iu';
    }

    /** "CRIADO MUDO" → "MESA DE CABECEIRA"; "Criado Mudo" → "Mesa De Cabeceira"; "Criado-mudo" → "Mesa de cabeceira". */
    private static function naCaixaDe(string $original, string $troca): string
    {
        $letras = (string) preg_replace('/[^\p{L}]+/u', '', $original);
        if ($letras !== '' && mb_strtoupper($letras) === $letras) {
            return mb_strtoupper($troca);
        }
        if ($letras === '' || mb_strtolower(mb_substr($letras, 0, 1)) === mb_substr($letras, 0, 1)) {
            return mb_strtolower($troca);
        }
        $palavras = preg_split('/[\s\-]+/u', trim($original), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $todasMaiusculas = array_filter($palavras, fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)) === mb_substr($p, 0, 1)) === $palavras;

        return $todasMaiusculas
            ? mb_convert_case($troca, MB_CASE_TITLE)
            : mb_strtoupper(mb_substr($troca, 0, 1)).mb_substr($troca, 1);
    }
}
