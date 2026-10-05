<?php

namespace Tests\Unit\Publicador\Alavancas;

use Tests\TestCase;

/**
 * 166-03 (AL166-05): toda escrita das Alavancas ao ML sai por EscritorAlavancas.php.
 * Lê a fonte SEM comentários (token_get_all) e procura `daConta(` com método diferente de GET.
 */
class UnicoCaminhoDeEscritaTest extends TestCase
{
    /**
     * @param  list<string>  $arquivos
     * @return list<array{arquivo: string, linha: int, tipo: string}> ocorrências suspeitas
     */
    private function varrer(array $arquivos): array
    {
        $achados = [];
        foreach ($arquivos as $arquivo) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($arquivo)),
                fn ($t) => ! (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)),
            ));
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                $texto = is_array($t) ? $t[1] : $t;
                $linha = is_array($t) ? $t[2] : 0;

                if ($texto === 'daConta' && ($tokens[$i + 1] ?? null) === '(') {
                    $metodo = $this->segundoArgumento($tokens, $i + 2);
                    if (! in_array($metodo, ["'GET'", '"GET"'], true)) {
                        $achados[] = ['arquivo' => $arquivo, 'linha' => $linha, 'tipo' => 'escrita'];
                    }
                }
                if ($texto === 'enviarFoto' && ($tokens[$i + 1] ?? null) === '(') {
                    $achados[] = ['arquivo' => $arquivo, 'linha' => $linha, 'tipo' => 'foto'];
                }
                if ($texto === 'Http' && ($tokens[$i + 1] ?? null) !== null
                    && (is_array($tokens[$i + 1]) ? $tokens[$i + 1][1] : $tokens[$i + 1]) === '::') {
                    $achados[] = ['arquivo' => $arquivo, 'linha' => $linha, 'tipo' => 'http'];
                }
            }
        }

        return $achados;
    }

    /** O 2º argumento da chamada (no nível de parênteses atual), como texto. */
    private function segundoArgumento(array $tokens, int $inicio): string
    {
        $nivel = 0;
        $virgulas = 0;
        $atual = '';
        for ($i = $inicio, $n = count($tokens); $i < $n; $i++) {
            $texto = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if (in_array($texto, ['(', '[', '{'], true)) {
                $nivel++;
            } elseif (in_array($texto, [')', ']', '}'], true)) {
                if ($nivel === 0) {
                    break;
                }
                $nivel--;
            } elseif ($texto === ',' && $nivel === 0) {
                $virgulas++;
                if ($virgulas === 2) {
                    break;
                }

                continue;
            }
            if ($virgulas === 1) {
                $atual .= $texto;
            }
        }

        return $atual;
    }

    /** @return list<string> */
    private function arquivosDasAlavancas(): array
    {
        $arquivos = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Services/Publicador/Alavancas'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $arquivos[] = $f->getPathname();
            }
        }
        $arquivos = [...$arquivos, ...glob(app_path('Http/Controllers/MlbAlavancas*.php')) ?: []];
        if (is_file($job = app_path('Jobs/Publicador/ExecutarLoteAlavancaJob.php'))) {
            $arquivos[] = $job;
        }

        return $arquivos;
    }

    public function test_toda_escrita_esta_no_escritor_e_ninguem_usa_http_direto(): void
    {
        $arquivos = $this->arquivosDasAlavancas();
        $this->assertNotEmpty($arquivos);

        foreach ($this->varrer($arquivos) as $a) {
            if ($a['tipo'] === 'escrita' && basename($a['arquivo']) === 'EscritorAlavancas.php') {
                continue;
            }
            $this->fail("Escrita ao ML fora do EscritorAlavancas em {$a['arquivo']}:{$a['linha']} ({$a['tipo']}) — toda escrita passa pelo executor único (AL166-05)");
        }
        $this->addToAssertionCount(1);
    }

    public function test_o_escritor_realmente_escreve_por_daconta(): void
    {
        $achados = $this->varrer([app_path('Services/Publicador/Alavancas/EscritorAlavancas.php')]);

        $this->assertNotEmpty(array_filter($achados, fn ($a) => $a['tipo'] === 'escrita'));
    }

    public function test_caso_de_controle_detecta_post_fora_do_escritor(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'alav-guarda-'.uniqid();
        mkdir($dir);
        $ruim = $dir.DIRECTORY_SEPARATOR.'Intruso.php';
        file_put_contents($ruim, "<?php\nclass Intruso { function f(\$c) { \$this->cliente->daConta(\$c, 'POST', '/x'); } }\n");
        $comentado = $dir.DIRECTORY_SEPARATOR.'Comentado.php';
        file_put_contents($comentado, "<?php\n// \$x->daConta(\$c, 'POST', '/x');\nclass A { function f(\$c) { \$this->cliente->daConta(\$c, 'GET', '/x'); } }\n");

        try {
            $this->assertCount(1, $this->varrer([$ruim]), 'o POST fora do escritor precisa ser detectado');
            $this->assertCount(0, $this->varrer([$comentado]), 'comentário e GET não contam');
        } finally {
            @unlink($ruim);
            @unlink($comentado);
            @rmdir($dir);
        }
    }
}
