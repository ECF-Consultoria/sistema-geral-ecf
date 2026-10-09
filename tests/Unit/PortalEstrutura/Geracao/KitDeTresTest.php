<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\Geracao\ChaveDeComposicao;
use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use PHPUnit\Framework\TestCase;

/**
 * Kit de 3 produtos (08/10, pedido do usuário: gabinete + espelho + lixeira).
 *
 * Regra conservadora: três tipos DIFERENTES, os TRÊS pares na lista, mesma família
 * e ambiente em comum aos TRÊS. Só Kit (todos x1); nunca 4 ou mais produtos. As
 * variações casam pela âncora (o produto com mais variações), nunca cartesiano.
 * Fixtures 100% sintéticas.
 */
class KitDeTresTest extends TestCase
{
    private function tipos(): array
    {
        $t = fn (string $nome, string $plural, array $combo, int $ordem) => [
            'nome' => $nome, 'plural' => $plural, 'qtd_combo' => $combo, 'qtd_combit' => [], 'ordem' => $ordem,
        ];

        return [
            'gabinete'  => $t('Gabinete', 'Gabinetes', [], 80),
            'espelho'   => $t('Espelho', 'Espelhos', [], 82),
            'lixeira'   => $t('Lixeira', 'Lixeiras', [2], 84),
            'toalheiro' => $t('Toalheiro', 'Toalheiros', [], 86),
        ];
    }

    private function paresDoBanheiro(): array
    {
        return [
            ['a' => 'gabinete', 'b' => 'espelho', 'repete' => null],
            ['a' => 'gabinete', 'b' => 'lixeira', 'repete' => null],
            ['a' => 'espelho', 'b' => 'lixeira', 'repete' => null],
            ['a' => 'gabinete', 'b' => 'toalheiro', 'repete' => null],
        ];
    }

    private function v(int $id, ?string $valor = null, int $ordem = 0): array
    {
        return ['id' => $id, 'ordem' => $ordem, 'eixo' => $valor !== null ? 'Cor' : null, 'valor' => $valor, 'sku' => "S{$id}", 'oferta_id' => $id + 7000];
    }

    private function p(int $id, string $nome, string $tipo, array $variacoes, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'nome' => $nome, 'familia_id' => 3, 'familia' => 'Nuvem',
            'ambientes' => [20 => 'Banheiro'], 'tipo' => $tipo, 'qtd_combo' => null, 'qtd_combit' => null,
            'variacoes' => $variacoes,
        ], $extra);
    }

    private function gerar(array $produtos, ?array $pares = null): array
    {
        return GeradorDeSugestoes::gerar([
            'produtos'    => $produtos,
            'tipos'       => $this->tipos(),
            'pares'       => $pares ?? $this->paresDoBanheiro(),
            'existentes'  => [],
            'descartadas' => [],
            'limites'     => ['max_titulo' => 60, 'max_sku' => 120],
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function trios(array $lista): array
    {
        return array_values(array_filter($lista, fn ($s) => count($s['itens']) === 3));
    }

    private function banheiro(): array
    {
        return [
            $this->p(1, 'Lixeira Nuvem', 'lixeira', [$this->v(301)]),
            $this->p(2, 'Gabinete Nuvem', 'gabinete', [$this->v(101, 'Branco'), $this->v(102, 'Preto', 1)]),
            $this->p(3, 'Espelho Nuvem', 'espelho', [$this->v(201)]),
        ];
    }

    public function test_gabinete_espelho_e_lixeira_viram_kit_de_tres_por_variacao_do_gabinete(): void
    {
        $trios = $this->trios($this->gerar($this->banheiro()));

        $this->assertSame(['v101*1+v201*1+v301*1', 'v102*1+v201*1+v301*1'], array_column($trios, 'chave'));

        $t = $trios[0];
        $this->assertSame(EstruturaOferta::FASE_KIT, $t['fase']);
        $this->assertSame([1, 1, 1], array_column($t['itens'], 'quantidade'));
        $this->assertSame(['gabinete', 'espelho', 'lixeira'], $t['tipos'], 'orientado pela ordem do tipo');
        $this->assertSame('Gabinete Nuvem + Espelho Nuvem + Lixeira Nuvem — Branco', $t['nome']);
        $this->assertSame('KT-S101-S201-S301', $t['sku']);
        $this->assertSame('Mesma família: Nuvem. Ambiente em comum: Banheiro. Conjunto: gabinete + espelho + lixeira; todos os pares estão na lista.', $t['porque']);
        $this->assertSame(['Banheiro'], $t['ambientes']);
        $this->assertNull($t['par']);
        $this->assertTrue(ChaveDeComposicao::valida($t['chave']));
    }

    public function test_os_kits_de_dois_continuam_saindo(): void
    {
        $chaves = array_column(array_filter($this->gerar($this->banheiro()), fn ($s) => $s['fase'] === 'kit' && count($s['itens']) === 2), 'chave');
        sort($chaves);

        // gabinete(2) x espelho(1) = 2; gabinete(2) x lixeira(1) = 2; espelho x lixeira = 1.
        $this->assertSame(['v101*1+v201*1', 'v101*1+v301*1', 'v102*1+v201*1', 'v102*1+v301*1', 'v201*1+v301*1'], $chaves);
    }

    public function test_falta_um_par_na_lista_nao_tem_kit_de_tres(): void
    {
        $semEspelhoLixeira = array_values(array_filter($this->paresDoBanheiro(), fn ($p) => ! ($p['a'] === 'espelho' && $p['b'] === 'lixeira')));

        $this->assertSame([], $this->trios($this->gerar($this->banheiro(), $semEspelhoLixeira)));
    }

    public function test_ambiente_em_comum_so_a_dois_nao_tem_kit_de_tres(): void
    {
        $produtos = $this->banheiro();
        $produtos[0]['ambientes'] = [20 => 'Banheiro', 21 => 'Lavabo'];
        $produtos[2]['ambientes'] = [21 => 'Lavabo'];
        $produtos[1]['ambientes'] = [20 => 'Banheiro'];

        $this->assertSame([], $this->trios($this->gerar($produtos)));
    }

    public function test_familia_diferente_nao_tem_kit_de_tres(): void
    {
        $produtos = $this->banheiro();
        $produtos[2]['familia_id'] = 9;
        $produtos[2]['familia'] = 'Outra';

        $this->assertSame([], $this->trios($this->gerar($produtos)));
    }

    public function test_tipo_repetido_nao_tem_kit_de_tres(): void
    {
        $produtos = [
            $this->p(1, 'Gabinete A', 'gabinete', [$this->v(101)]),
            $this->p(2, 'Gabinete B', 'gabinete', [$this->v(102)]),
            $this->p(3, 'Espelho', 'espelho', [$this->v(201)]),
        ];
        $pares = array_merge($this->paresDoBanheiro(), [['a' => 'gabinete', 'b' => 'gabinete', 'repete' => null]]);

        $this->assertSame([], $this->trios($this->gerar($produtos, $pares)));
    }

    public function test_nunca_quatro_produtos(): void
    {
        $pares = [];
        foreach ([['gabinete', 'espelho'], ['gabinete', 'lixeira'], ['gabinete', 'toalheiro'], ['espelho', 'lixeira'], ['espelho', 'toalheiro'], ['lixeira', 'toalheiro']] as [$a, $b]) {
            $pares[] = ['a' => $a, 'b' => $b, 'repete' => null];
        }
        $produtos = array_merge($this->banheiro(), [$this->p(4, 'Toalheiro Nuvem', 'toalheiro', [$this->v(401)])]);

        $lista = $this->gerar($produtos, $pares);

        $this->assertLessThanOrEqual(3, max(array_map(fn ($s) => count($s['itens']), $lista)));
        // 4 tipos com todos os pares: C(4,3) = 4 trios, com 2 variações de gabinete onde ele entra.
        $this->assertCount(2 + 2 + 2 + 1, $this->trios($lista));
    }

    public function test_variacoes_casam_por_valor_pela_ancora_sem_cartesiano(): void
    {
        $produtos = [
            $this->p(1, 'Gabinete', 'gabinete', [$this->v(101, 'Branco'), $this->v(102, 'Preto', 1)]),
            $this->p(2, 'Espelho', 'espelho', [$this->v(201, 'Preto'), $this->v(202, 'Branco', 1)]),
            $this->p(3, 'Lixeira', 'lixeira', [$this->v(301, 'Inox')]),
        ];

        $this->assertSame(['v101*1+v202*1+v301*1', 'v102*1+v201*1+v301*1'], array_column($this->trios($this->gerar($produtos)), 'chave'));
    }

    public function test_e_deterministico_qualquer_que_seja_a_ordem_da_entrada(): void
    {
        $this->assertSame($this->gerar($this->banheiro()), $this->gerar(array_reverse($this->banheiro())));
    }
}
