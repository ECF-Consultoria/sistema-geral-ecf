<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use App\Services\Portal\Estrutura\Geracao\VariacoesEmParalelo;
use PHPUnit\Framework\TestCase;

/**
 * `VariacoesEmParalelo::casarComAncora()` (o "Juntar todos" do Mapeamento da Trilha, 02/11/2026) dá as MESMAS cores
 * que o Kit de 3 do gerador (`GeradorDeSugestoes::paraOTrio`, que não foi mexido): os cenários do `KitDeTresTest`.
 */
class CasarComAncoraTest extends TestCase
{
    private function v(int $id, ?string $valor = null, int $ordem = 0): array
    {
        return ['id' => $id, 'ordem' => $ordem, 'eixo' => $valor !== null ? 'Cor' : null, 'valor' => $valor, 'sku' => "S{$id}", 'oferta_id' => $id + 7000];
    }

    private function p(int $id, string $nome, string $tipo, array $variacoes): array
    {
        return ['id' => $id, 'nome' => $nome, 'familia_id' => 3, 'familia' => 'Nuvem', 'ambientes' => [20 => 'Banheiro'], 'tipo' => $tipo,
            'qtd_combo' => null, 'qtd_combit' => null, 'variacoes' => $variacoes];
    }

    /** As chaves dos kits de 3 do gerador. @return list<string> */
    private function doGerador(array $produtos): array
    {
        $t = fn (string $nome, int $ordem) => ['nome' => $nome, 'plural' => $nome.'s', 'qtd_combo' => [], 'qtd_combit' => [], 'ordem' => $ordem];
        $lista = GeradorDeSugestoes::gerar([
            'produtos'    => $produtos,
            'tipos'       => ['gabinete' => $t('Gabinete', 80), 'espelho' => $t('Espelho', 82), 'lixeira' => $t('Lixeira', 84)],
            'pares'       => [['a' => 'gabinete', 'b' => 'espelho', 'repete' => null], ['a' => 'gabinete', 'b' => 'lixeira', 'repete' => null],
                ['a' => 'espelho', 'b' => 'lixeira', 'repete' => null]],
            'existentes'  => [],
            'descartadas' => [],
            'limites'     => ['max_titulo' => 60, 'max_sku' => 120],
        ]);

        return array_values(array_column(array_filter($lista, fn ($s) => count($s['itens']) === 3), 'chave'));
    }

    /** As chaves da função nova, com as listas na orientação do gerador (ordem do tipo). @return list<string> */
    private function daFuncao(array ...$listas): array
    {
        return array_map(function (array $combinacao) {
            $mapa = [];
            foreach ($combinacao as $v) {
                $mapa[$v['id']] = 1;
            }

            return ChaveDeComposicao::de($mapa);
        }, VariacoesEmParalelo::casarComAncora($listas));
    }

    public function test_uma_combinacao_por_cor_da_ancora_como_o_gerador(): void
    {
        $gabinete = [$this->v(101, 'Branco'), $this->v(102, 'Preto', 1)];
        $espelho = [$this->v(201)];
        $lixeira = [$this->v(301)];

        $esperado = $this->doGerador([
            $this->p(1, 'Lixeira Nuvem', 'lixeira', $lixeira),
            $this->p(2, 'Gabinete Nuvem', 'gabinete', $gabinete),
            $this->p(3, 'Espelho Nuvem', 'espelho', $espelho),
        ]);

        $this->assertSame(['v101*1+v201*1+v301*1', 'v102*1+v201*1+v301*1'], $esperado);
        $this->assertSame($esperado, $this->daFuncao($gabinete, $espelho, $lixeira));
    }

    public function test_casam_por_valor_pela_ancora_sem_cartesiano_como_o_gerador(): void
    {
        $gabinete = [$this->v(101, 'Branco'), $this->v(102, 'Preto', 1)];
        $espelho = [$this->v(201, 'Preto'), $this->v(202, 'Branco', 1)];
        $lixeira = [$this->v(301, 'Inox')];

        $esperado = $this->doGerador([
            $this->p(1, 'Gabinete', 'gabinete', $gabinete),
            $this->p(2, 'Espelho', 'espelho', $espelho),
            $this->p(3, 'Lixeira', 'lixeira', $lixeira),
        ]);

        $this->assertSame($esperado, $this->daFuncao($gabinete, $espelho, $lixeira));
        // A ordem das listas na saída é a de quem chamou, qualquer que seja a âncora.
        $this->assertSame([101, 202, 301], array_column(VariacoesEmParalelo::casarComAncora([$gabinete, $espelho, $lixeira])[0], 'id'));
    }

    public function test_lista_vazia_nao_combina(): void
    {
        $this->assertSame([], VariacoesEmParalelo::casarComAncora([[$this->v(1)], []]));
        $this->assertSame([], VariacoesEmParalelo::casarComAncora([]));
    }
}
