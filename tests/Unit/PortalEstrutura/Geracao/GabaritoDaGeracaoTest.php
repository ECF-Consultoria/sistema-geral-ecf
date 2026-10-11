<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use PHPUnit\Framework\TestCase;

/**
 * Gabarito SINTÉTICO da geração (Fase 168, PR168-15).
 *
 * Nada aqui vem da planilha real: só a FORMA medida nela foi copiada (família
 * "polo" com vários produtos, famílias de 1 produto, grupos de 2 variações, par
 * sem ambiente em comum, produto sem tipo, variação sem oferta). Nomes e SKUs
 * são fictícios.
 *
 * Conta de cada número esperado:
 *  - Combo (15): P2 cadeira [2,4,6] x v201,v202 = 6 (v203 sem oferta fora);
 *    P4 banqueta [2,3,4] x v401 = 3; P8 cadeira x v801 = 3; P10 cadeira x v1001 = 3.
 *    Mesa, banco, cama, criado-mudo e P7 (sem tipo) não têm quantidade de Combo.
 *  - Kit (5): mesa+cadeira em paralelo por Cor = (v101,v201) e (v102,v202); o
 *    primeiro já existe, sobra 1. mesa+banco (banco com 1 variação) = (v101,v301)
 *    e (v102,v301). cama+criado-mudo = (v501,v601) e (v501,v602). Total 1+2+2.
 *  - Combit (8): mesa+cadeira repete cadeira, [2,4,6] x 2 pares = 6 (um deles
 *    descartado); mesa+banco repete banco, banco sem quantidade = 0;
 *    cama+criado-mudo repete criado-mudo, override [2] x 2 pares = 2.
 *  - Ficam de fora: mesa+banqueta (sem ambiente em comum), P7 (sem tipo),
 *    cadeira+banco (par fora da lista), P8/P9/P10 (família de 1 ou nula).
 */
class GabaritoDaGeracaoTest extends TestCase
{
    private function v(int $id, ?string $valor, int $ordem = 0, bool $comOferta = true): array
    {
        return [
            'id' => $id, 'ordem' => $ordem, 'eixo' => $valor !== null ? 'Cor' : null, 'valor' => $valor,
            'sku' => "SKU{$id}", 'oferta_id' => $comOferta ? $id + 9000 : null,
        ];
    }

    private function p(int $id, string $nome, ?string $tipo, array $ambientes, array $variacoes, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'nome' => $nome, 'familia_id' => 1, 'familia' => 'Polo',
            'ambientes' => $ambientes, 'tipo' => $tipo, 'qtd_combo' => null, 'qtd_combit' => null,
            'variacoes' => $variacoes,
        ], $extra);
    }

    private function retrato(): array
    {
        $tipo = fn (string $nome, string $plural, array $combo, array $combit, int $ordem) => [
            'nome' => $nome, 'plural' => $plural, 'qtd_combo' => $combo, 'qtd_combit' => $combit, 'ordem' => $ordem,
        ];

        $sala = [10 => 'Sala de jantar'];

        return [
            'tipos' => [
                'mesa'         => $tipo('Mesa', 'Mesas', [], [], 10),
                'cama'         => $tipo('Cama', 'Camas', [], [], 20),
                'criado-mudo'  => $tipo('Criado-mudo', 'Criados-mudos', [], [], 60),
                'cadeira'      => $tipo('Cadeira', 'Cadeiras', [2, 4, 6], [2, 4, 6], 70),
                'banqueta'     => $tipo('Banqueta', 'Banquetas', [2, 3, 4], [2, 3, 4], 74),
                'banco'        => $tipo('Banco', 'Bancos', [], [], 76),
            ],
            'pares' => [
                ['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'b'],
                ['a' => 'mesa', 'b' => 'banco', 'repete' => 'b'],
                ['a' => 'mesa', 'b' => 'banqueta', 'repete' => 'b'],
                ['a' => 'cama', 'b' => 'criado-mudo', 'repete' => 'b'],
            ],
            'produtos' => [
                $this->p(1, 'Mesa Polo', 'mesa', $sala, [$this->v(101, 'Natural'), $this->v(102, 'Preto', 1)]),
                $this->p(2, 'Cadeira Polo', 'cadeira', $sala, [$this->v(201, 'Natural'), $this->v(202, 'Preto', 1), $this->v(203, 'Branco', 2, false)]),
                $this->p(3, 'Banco Polo', 'banco', $sala, [$this->v(301, null)]),
                $this->p(4, 'Banqueta Polo', 'banqueta', [12 => 'Varanda'], [$this->v(401, null)]),
                $this->p(5, 'Cama Polo', 'cama', [11 => 'Quarto'], [$this->v(501, null)]),
                $this->p(6, 'Criado-mudo Polo', 'criado-mudo', [11 => 'Quarto'], [$this->v(601, null), $this->v(602, null, 1)], ['qtd_combit' => [2]]),
                $this->p(7, 'Peça Decorativa Polo', null, $sala, [$this->v(701, null)]),
                $this->p(8, 'Cadeira Solo', 'cadeira', $sala, [$this->v(801, null)], ['familia_id' => 2, 'familia' => 'Solo A']),
                $this->p(9, 'Mesa Solo', 'mesa', $sala, [$this->v(901, null)], ['familia_id' => 3, 'familia' => 'Solo B']),
                $this->p(10, 'Cadeira Avulsa', 'cadeira', $sala, [$this->v(1001, null)], ['familia_id' => null, 'familia' => null]),
            ],
            'existentes'  => ['v101*1+v201*1' => true],
            'descartadas' => ['v102*1+v202*4' => '2026-10-01'],
            'limites'     => ['max_titulo' => 60, 'max_sku' => 120],
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function porFase(): array
    {
        $fases = ['combo' => [], 'kit' => [], 'combit' => []];
        foreach (GeradorDeSugestoes::gerar($this->retrato()) as $s) {
            $fases[$s['fase']][] = $s;
        }

        return $fases;
    }

    public function test_contagens_do_gabarito(): void
    {
        $f = $this->porFase();

        $this->assertCount(15, $f['combo']);
        $this->assertCount(5, $f['kit']);
        $this->assertCount(8, $f['combit']);
        $this->assertCount(28, GeradorDeSugestoes::gerar($this->retrato()));
    }

    public function test_exatamente_uma_descartada(): void
    {
        $descartadas = array_filter(GeradorDeSugestoes::gerar($this->retrato()), fn ($s) => $s['descartada']);

        $this->assertCount(1, $descartadas);
        $this->assertSame('v102*1+v202*4', array_values($descartadas)[0]['chave']);
        $this->assertSame('2026-10-01', array_values($descartadas)[0]['descartada_em']);
    }

    public function test_kits_esperados(): void
    {
        $chaves = array_column($this->porFase()['kit'], 'chave');
        sort($chaves);
        $esperado = ['v102*1+v202*1', 'v101*1+v301*1', 'v102*1+v301*1', 'v501*1+v601*1', 'v501*1+v602*1'];
        sort($esperado);

        $this->assertSame($esperado, $chaves);
    }

    public function test_combos_por_produto(): void
    {
        $contagem = [];
        foreach ($this->porFase()['combo'] as $s) {
            $id = $s['itens'][0]['produto_id'];
            $contagem[$id] = ($contagem[$id] ?? 0) + 1;
        }
        ksort($contagem);

        $this->assertSame([2 => 6, 4 => 3, 8 => 3, 10 => 3], $contagem + []);
    }

    public function test_ausencias(): void
    {
        $lista = GeradorDeSugestoes::gerar($this->retrato());

        foreach ($lista as $s) {
            $variacoes = array_column($s['itens'], 'variacao_id');
            $this->assertNotContains(203, $variacoes, 'variação sem oferta');
            $this->assertFalse(in_array(101, $variacoes, true) && in_array(202, $variacoes, true), 'cruzou v101 com v202');
            $this->assertFalse(in_array(102, $variacoes, true) && in_array(201, $variacoes, true), 'cruzou v102 com v201');
            $this->assertLessThanOrEqual(2, count($variacoes), 'trio');

            if (count($variacoes) > 1) {
                $produtos = array_column($s['itens'], 'produto_id');
                $this->assertNotSame([1, 4], $produtos, 'mesa + banqueta sem ambiente em comum');
                $this->assertNotContains(7, $produtos, 'produto sem tipo');
                $this->assertNotContains(8, $produtos, 'família de 1 produto');
                $this->assertNotContains(9, $produtos, 'família de 1 produto');
                $this->assertNotContains(10, $produtos, 'sem família');
            }
        }
    }

    public function test_combit_so_repete_o_lado_dirigido(): void
    {
        foreach ($this->porFase()['combit'] as $s) {
            [$primeiro, $segundo] = $s['itens'];
            $this->assertSame(1, $primeiro['quantidade'], $s['chave']);
            $this->assertGreaterThanOrEqual(2, $segundo['quantidade'], $s['chave']);
        }
    }

    public function test_nomes_conferidos(): void
    {
        $por = array_column(GeradorDeSugestoes::gerar($this->retrato()), null, 'chave');

        $this->assertSame('Combo 4 Cadeiras Polo — Natural', $por['v201*4']['nome']);
        $this->assertSame('Mesa Polo + 4 Cadeiras — Natural', $por['v101*1+v201*4']['nome']);
        $this->assertSame('Mesa Polo + Banco Polo — Natural', $por['v101*1+v301*1']['nome']);
    }
}
