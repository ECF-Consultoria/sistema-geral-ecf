<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\TipoDoProduto;
use Tests\TestCase;

/** Trava a forma do vocabulário da ECF e as quantidades literais do D-07/D-13. */
class CatalogoDaEcfTest extends TestCase
{
    public function test_tipos_bem_formados(): void
    {
        $tipos = config('estrutura_geracao.tipos');

        $this->assertCount(count(array_unique(array_keys($tipos))), $tipos);

        foreach ($tipos as $slug => $t) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]{2,40}$/', (string) $slug);
            $this->assertNotSame('', $t['nome']);
            $this->assertNotSame('', $t['plural']);
            $this->assertLessThanOrEqual(60, mb_strlen($t['nome']));
            $this->assertLessThanOrEqual(60, mb_strlen($t['plural']));
            $this->assertNotEmpty($t['palavras'], $slug);

            foreach ($t['palavras'] as $p) {
                $this->assertSame(TipoDoProduto::normalizar($p), $p, "palavra '$p' de $slug");
            }
            foreach (['qtd_combo', 'qtd_combit'] as $k) {
                $this->assertTrue(
                    $t[$k] === null || preg_match('/^(0|\d+(\s*,\s*\d+)*)$/', $t[$k]) === 1,
                    "$k de $slug"
                );
            }
        }
    }

    public function test_pares_referenciam_tipos_existentes(): void
    {
        $tipos = config('estrutura_geracao.tipos');
        $vistos = [];

        foreach (config('estrutura_geracao.pares') as $par) {
            [$a, $b] = $par['tipos'];
            $this->assertArrayHasKey($a, $tipos);
            $this->assertArrayHasKey($b, $tipos);
            $this->assertTrue(in_array($par['repete'], [null, 'ambos', $a, $b], true));

            $chave = implode('+', collect([$a, $b])->sort()->values()->all());
            $this->assertArrayNotHasKey($chave, $vistos, "par repetido $chave");
            $vistos[$chave] = true;
        }
    }

    public function test_quantidades_literais(): void
    {
        $t = config('estrutura_geracao.tipos');

        $this->assertSame('2, 4, 6', $t['cadeira']['qtd_combo']);
        $this->assertSame('2, 4, 6', $t['cadeira']['qtd_combit']);
        $this->assertSame('2, 3, 4', $t['banqueta']['qtd_combo']);
        $this->assertSame('2, 3, 4', $t['banqueta']['qtd_combit']);
        $this->assertSame('0', $t['mesa']['qtd_combo']);
        $this->assertSame('0', $t['mesa']['qtd_combit']);
    }
}
