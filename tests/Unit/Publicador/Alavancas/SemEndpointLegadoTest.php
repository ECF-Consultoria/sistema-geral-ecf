<?php

namespace Tests\Unit\Publicador\Alavancas;

use Tests\TestCase;

/**
 * AL166-14 / AL166-15 (T-166-22, T-166-22b, T-166-44): guarda de FONTE contra caminhos desligados.
 *
 * - O endpoint absoluto de PxQ (`/prices/standard/quantity`) é descontinuado para B2B em 27/10/2026;
 * - os caminhos legados de Product Ads (`product_ads/items`, `product_ads/ads/search`, o prefixo
 *   `/marketplace/advertising` e `/advertising/advertisers/<id>/product_ads`) respondem 404 desde
 *   27/05/2026 e as métricas por anúncio saíram em 30/05/2026.
 *
 * Varre a fonte SEM comentários (os docblocks citam esses caminhos para explicar por que não existem).
 * Segunda varredura: nenhuma ação nem o escritor menciona `/advertising` (publicidade é só leitura, D-09).
 */
class SemEndpointLegadoTest extends TestCase
{
    /** padrão => rótulo mostrado na mensagem */
    private const PROIBIDOS = [
        '#/prices/standard/quantity#' => '/prices/standard/quantity',
        '#standard/quantity#' => 'standard/quantity',
        '#product_ads/items#' => 'product_ads/items',
        '#product_ads/ads/search#' => 'product_ads/ads/search',
        '#/marketplace/advertising#' => '/marketplace/advertising',
        '#/advertising/advertisers/[^/\s\'"]+/product_ads#' => '/advertising/advertisers/<id>/product_ads',
    ];

    /** @return list<string> caminhos absolutos dos arquivos das Alavancas que existem agora */
    private function arquivosDasAlavancas(): array
    {
        $arquivos = [];
        foreach (['app/Services/Publicador/Alavancas', 'resources/js/Components/Mlb/Alavancas'] as $pasta) {
            $arquivos = [...$arquivos, ...$this->arquivosDe(base_path($pasta))];
        }
        $arquivos = [...$arquivos, ...glob(base_path('app/Http/Controllers/MlbAlavancas*.php')) ?: []];
        foreach (['app/Jobs/Publicador/ExecutarLoteAlavancaJob.php', 'app/Console/Commands/PublicadorSondarAlavancas.php',
            'resources/js/Pages/Mlb/Publicador/Alavancas.jsx'] as $unico) {
            if (is_file(base_path($unico))) {
                $arquivos[] = base_path($unico);
            }
        }

        return $arquivos;
    }

    /** @return list<string> */
    private function arquivosDe(string $pasta): array
    {
        if (! is_dir($pasta)) {
            return [];
        }
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arq) {
            if ($arq->isFile() && in_array($arq->getExtension(), ['php', 'js', 'jsx'], true)) {
                $achados[] = $arq->getPathname();
            }
        }

        return $achados;
    }

    /** O código do arquivo sem comentários: PHP por `token_get_all`; JS/JSX removendo blocos e comentários de linha. */
    private static function semComentarios(string $conteudo, string $extensao): string
    {
        if ($extensao === 'php') {
            $codigo = '';
            foreach (token_get_all($conteudo) as $t) {
                if (is_array($t)) {
                    if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $codigo .= $t[1];
                } else {
                    $codigo .= $t;
                }
            }

            return $codigo;
        }

        $semBloco = preg_replace('#/\*[\s\S]*?\*/#', '', $conteudo) ?? $conteudo;

        return implode("\n", array_map(
            fn (string $linha) => preg_replace('#(^|[^:])//.*$#', '$1', $linha),
            preg_split('/\r?\n/', $semBloco) ?: [],
        ));
    }

    /** @return list<string> os rótulos dos padrões que aparecem no código */
    private static function proibidosEm(string $conteudo, string $extensao): array
    {
        $codigo = self::semComentarios($conteudo, $extensao);
        $achados = [];
        foreach (self::PROIBIDOS as $padrao => $rotulo) {
            if (preg_match($padrao, $codigo)) {
                $achados[] = $rotulo;
            }
        }

        return $achados;
    }

    /** @return list<string> */
    private static function publicidadeEm(string $conteudo, string $extensao): array
    {
        return str_contains(self::semComentarios($conteudo, $extensao), '/advertising') ? ['/advertising'] : [];
    }

    public function test_a_fonte_das_alavancas_nao_chama_endpoint_desligado(): void
    {
        $arquivos = $this->arquivosDasAlavancas();
        $this->assertNotEmpty($arquivos, 'a varredura não achou nenhum arquivo das Alavancas');

        $achados = [];
        foreach ($arquivos as $arq) {
            foreach (self::proibidosEm((string) file_get_contents($arq), pathinfo($arq, PATHINFO_EXTENSION)) as $padrao) {
                $achados[] = "Endpoint desligado/descontinuado em {$arq}: {$padrao} (AL166-14/AL166-15)";
            }
        }

        $this->assertSame([], $achados, implode("\n", $achados));
    }

    public function test_a_fonte_das_alavancas_nao_chama_o_endpoint_absoluto(): void
    {
        // 166-09: o PxQ absoluto nunca é chamado; cobertura mantida em separado da lista geral.
        $achados = [];
        foreach ($this->arquivosDe(base_path('app/Services/Publicador/Alavancas')) as $arq) {
            if (in_array('standard/quantity', self::proibidosEm((string) file_get_contents($arq), pathinfo($arq, PATHINFO_EXTENSION)), true)) {
                $achados[] = $arq;
            }
        }

        $this->assertSame([], $achados);
    }

    public function test_nenhuma_acao_nem_o_escritor_escreve_em_publicidade(): void
    {
        $arquivos = [...$this->arquivosDe(base_path('app/Services/Publicador/Alavancas/Acoes')),
            base_path('app/Services/Publicador/Alavancas/EscritorAlavancas.php')];

        $achados = [];
        foreach ($arquivos as $arq) {
            if (is_file($arq) && self::publicidadeEm((string) file_get_contents($arq), pathinfo($arq, PATHINFO_EXTENSION)) !== []) {
                $achados[] = $arq;
            }
        }

        $this->assertSame([], $achados, 'Escrita em publicidade está fora desta fase (D-09): '.implode(', ', $achados));
    }

    // ── Casos de controle: provam que a varredura enxerga o que deveria ──────────────────────────

    public function test_controle_php_detecta_cada_padrao_proibido_e_ignora_comentario(): void
    {
        foreach ([
            "/prices/standard/quantity" => '/prices/standard/quantity',
            '/advertising/advertisers/123/product_ads/items' => 'product_ads/items',
            '/advertising/MLB/advertisers/1/product_ads/ads/search' => 'product_ads/ads/search',
            '/marketplace/advertising/advertisers' => '/marketplace/advertising',
            '/advertising/advertisers/123/product_ads/campaigns' => '/advertising/advertisers/<id>/product_ads',
        ] as $caminho => $esperado) {
            $achados = self::proibidosEm("<?php\n\$x = '{$caminho}';\n", 'php');
            $this->assertContains($esperado, $achados, "não detectou {$caminho}");
        }

        $comentado = "<?php\n/** cita /prices/standard/quantity e product_ads/items */\n// product_ads/ads/search\n\$x = 1;\n";
        $this->assertSame([], self::proibidosEm($comentado, 'php'));
    }

    public function test_controle_jsx_detecta_no_codigo_e_ignora_comentario(): void
    {
        $this->assertContains('product_ads/items', self::proibidosEm("const u = '/advertising/x/product_ads/items';\n", 'jsx'));
        $comentado = "// product_ads/items\n/* /prices/standard/quantity */\nconst a = 'https://x';\n";
        $this->assertSame([], self::proibidosEm($comentado, 'jsx'));
    }

    public function test_controle_em_arquivo_temporario_e_detectado(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'alav');
        file_put_contents($tmp, "<?php\nreturn '/prices/standard/quantity';\n");
        try {
            $this->assertContains('/prices/standard/quantity', self::proibidosEm((string) file_get_contents($tmp), 'php'));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_controle_da_varredura_de_publicidade_nas_acoes(): void
    {
        $this->assertSame(['/advertising'], self::publicidadeEm("<?php\n\$c = '/advertising/MLB/x';\n", 'php'));
        $this->assertSame([], self::publicidadeEm("<?php\n// escreve em /advertising? não\n\$c = 1;\n", 'php'));
    }
}
