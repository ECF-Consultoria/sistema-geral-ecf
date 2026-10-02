<?php

namespace Tests\Unit\Publicador\Concerns;

/**
 * Snapshot de JSON para os payloads (`11` §Convenções: "o montador deve ser
 * testável sem rede … usar snapshots de JSON").
 *
 * O arquivo fica em `tests/fixtures-ml/snapshots/<nome>.json`. Para criar ou
 * atualizar de propósito: `ATUALIZAR_SNAPSHOTS=1 php vendor/bin/phpunit …`, e
 * revisar o diff no git — o snapshot É o que vai para o Mercado Livre.
 */
trait ComparaSnapshot
{
    protected function assertSnapshotJson(string $nome, array $dados): void
    {
        $arquivo = dirname(__DIR__, 3).'/fixtures-ml/snapshots/'.$nome.'.json';
        $json = json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        if (getenv('ATUALIZAR_SNAPSHOTS') === '1') {
            if (! is_dir(dirname($arquivo))) {
                mkdir(dirname($arquivo), 0775, true);
            }
            file_put_contents($arquivo, $json);
        }

        $this->assertFileExists($arquivo, "Snapshot {$nome} não existe — rode com ATUALIZAR_SNAPSHOTS=1 e revise.");
        $this->assertSame(file_get_contents($arquivo), $json, "O payload mudou em relação ao snapshot {$nome}.");
    }
}
