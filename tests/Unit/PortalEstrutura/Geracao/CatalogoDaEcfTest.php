<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\TipoDoProduto;
use Tests\TestCase;

/** Trava a forma do vocabulário da ECF e as quantidades aprovadas (D-22). */
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

    /** D-22 (substitui o D-13): quantidades como a planilha usa, aprovadas pelo usuário. */
    public function test_quantidades_como_a_planilha_usa(): void
    {
        $t = config('estrutura_geracao.tipos');

        $esperado = [
            'cadeira'      => ['2, 4, 6, 8', '2, 4, 6'],
            'banqueta'     => ['2, 3, 4', '2'],
            'banco'        => ['2', '2'],
            'prateleira'   => ['2, 3', '2'],
            'cabeceira'    => ['2', '2'],
            'criado-mudo'  => ['2', '2'],
            'mesa-lateral' => ['2', '2'],
            'cama'         => ['2', null],
            'mesa'         => ['0', '0'],
            // 08/10: só a lixeira tem Combo no banheiro; nenhum tem Combit.
            'lixeira'      => ['2', null],
            'gabinete'     => [null, null],
            'espelho'      => [null, null],
        ];

        foreach ($esperado as $slug => [$combo, $combit]) {
            $this->assertSame($combo, $t[$slug]['qtd_combo'], "combo de $slug");
            $this->assertSame($combit, $t[$slug]['qtd_combit'], "combit de $slug");
        }
    }

    /** D-21 e D-23: 18 pares aprovados; bicama em cama; tipo beliche. 08/10: +5 pares de banheiro. */
    public function test_lista_aprovada_de_pares_e_tipos(): void
    {
        $this->assertCount(23, config('estrutura_geracao.pares'));
        $this->assertContains('bicama', config('estrutura_geracao.tipos.cama.palavras'));
        $this->assertSame(['beliche', 'treliche'], config('estrutura_geracao.tipos.beliche.palavras'));
        $this->assertSame(21, config('estrutura_geracao.tipos.beliche.ordem'));
    }
}
