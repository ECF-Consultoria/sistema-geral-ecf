<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Leitura das fixtures de payload do Bling (`tests/fixtures-bling/`).
 *
 * Falha alto (`RuntimeException`) quando a fixture não existe ou o JSON é inválido, em vez de
 * devolver um array vazio: fixture quebrada virando "array vazio" faria o teste passar à toa.
 */
final class BlingFixtures
{
    /**
     * Lê `tests/fixtures-bling/{$nome}.json` e devolve o array associativo decodificado.
     *
     * @return array<string, mixed>
     */
    public static function ler(string $nome): array
    {
        $dados = json_decode(self::cru($nome), true);

        if (! is_array($dados)) {
            throw new RuntimeException("Fixture inválida (JSON não decodificou para array): ".self::caminho($nome));
        }

        return $dados;
    }

    /** Devolve o corpo bruto (string) da fixture, para montar `Http::fake`. */
    public static function cru(string $nome): string
    {
        $caminho = self::caminho($nome);

        if (! is_file($caminho)) {
            throw new RuntimeException("Fixture do Bling não encontrada: {$caminho}");
        }

        $conteudo = file_get_contents($caminho);

        if ($conteudo === false) {
            throw new RuntimeException("Não foi possível ler a fixture do Bling: {$caminho}");
        }

        return $conteudo;
    }

    private static function caminho(string $nome): string
    {
        return base_path("tests/fixtures-bling/{$nome}.json");
    }
}
