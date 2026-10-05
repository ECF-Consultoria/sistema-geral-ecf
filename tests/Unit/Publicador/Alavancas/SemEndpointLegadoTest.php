<?php

namespace Tests\Unit\Publicador\Alavancas;

use Tests\TestCase;

/**
 * 166-09 (T-166-44): o endpoint absoluto de PxQ (`/prices/standard/quantity`) é descontinuado para B2B
 * em 27/10/2026 e nunca é chamado pelas Alavancas. Varre a fonte SEM comentários (os docblocks o citam).
 */
class SemEndpointLegadoTest extends TestCase
{
    public function test_a_fonte_das_alavancas_nao_chama_o_endpoint_absoluto(): void
    {
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app/Services/Publicador/Alavancas')));
        foreach ($it as $arq) {
            if (! $arq->isFile() || $arq->getExtension() !== 'php') {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($arq->getPathname())) as $t) {
                if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && str_contains($t[1], 'standard/quantity')) {
                    $achados[] = $arq->getPathname().':'.$t[2];
                }
            }
        }

        $this->assertSame([], $achados);
    }
}
