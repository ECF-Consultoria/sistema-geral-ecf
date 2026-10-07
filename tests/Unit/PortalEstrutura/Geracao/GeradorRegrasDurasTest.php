<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use PHPUnit\Framework\TestCase;

/**
 * Regras duras do gerador: o que NÃO sugerir (D-05, D-06, D-07, D-14, D-16).
 * Fixtures 100% sintéticas.
 */
class GeradorRegrasDurasTest extends TestCase
{
    private function tipos(): array
    {
        return [
            'mesa'    => ['nome' => 'Mesa', 'plural' => 'Mesas', 'qtd_combo' => [], 'qtd_combit' => [], 'ordem' => 10],
            'cadeira' => ['nome' => 'Cadeira', 'plural' => 'Cadeiras', 'qtd_combo' => [2, 4, 6], 'qtd_combit' => [2, 4, 6], 'ordem' => 70],
        ];
    }

    private function produto(int $id, string $nome, ?string $tipo, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'nome' => $nome, 'familia_id' => 1, 'familia' => 'Polo',
            'ambientes' => [10 => 'Sala'], 'tipo' => $tipo, 'qtd_combo' => null, 'qtd_combit' => null,
            'variacoes' => [['id' => $id * 100, 'ordem' => 0, 'eixo' => null, 'valor' => null, 'sku' => "S{$id}", 'oferta_id' => $id * 100 + 1]],
        ], $extra);
    }

    private function retrato(array $produtos, ?array $pares = null): array
    {
        return [
            'produtos'    => $produtos,
            'tipos'       => $this->tipos(),
            'pares'       => $pares ?? [['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'b']],
            'existentes'  => [],
            'descartadas' => [],
            'limites'     => ['max_titulo' => 60, 'max_sku' => 120],
        ];
    }

    /** @return list<string> */
    private function fases(array $retrato, string $fase): array
    {
        return array_values(array_map(
            fn ($s) => $s['chave'],
            array_filter(GeradorDeSugestoes::gerar($retrato), fn ($s) => $s['fase'] === $fase)
        ));
    }

    private function mesaECadeira(array $extraMesa = [], array $extraCadeira = []): array
    {
        return [$this->produto(1, 'Mesa Polo', 'mesa', $extraMesa), $this->produto(2, 'Cadeira Polo', 'cadeira', $extraCadeira)];
    }

    public function test_familias_diferentes_nao_formam_kit(): void
    {
        $r = $this->retrato($this->mesaECadeira([], ['familia_id' => 2, 'familia' => 'Outra']));
        $this->assertSame([], $this->fases($r, 'kit'));
        $this->assertSame([], $this->fases($r, 'combit'));
    }

    public function test_familia_nula_so_combo(): void
    {
        $r = $this->retrato($this->mesaECadeira(['familia_id' => null, 'familia' => null], ['familia_id' => null, 'familia' => null]));
        $this->assertSame([], $this->fases($r, 'kit'));
        $this->assertSame(['v200*2', 'v200*4', 'v200*6'], $this->fases($r, 'combo'));
    }

    public function test_sem_ambiente_em_comum_nao_ha_kit_nem_combit(): void
    {
        $r = $this->retrato($this->mesaECadeira([], ['ambientes' => [11 => 'Quarto']]));
        $this->assertSame([], $this->fases($r, 'kit'));
        $this->assertSame([], $this->fases($r, 'combit'));
        $this->assertCount(3, $this->fases($r, 'combo'));
    }

    public function test_produto_sem_tipo_so_combo_pelas_quantidades_do_produto(): void
    {
        $semOverride = $this->retrato([$this->produto(1, 'Mesa Polo', 'mesa'), $this->produto(3, 'Peça', null)]);
        $this->assertSame([], GeradorDeSugestoes::gerar($semOverride));

        $comOverride = $this->retrato([$this->produto(1, 'Mesa Polo', 'mesa'), $this->produto(3, 'Peça', null, ['qtd_combo' => [3]])]);
        $lista = GeradorDeSugestoes::gerar($comOverride);
        $this->assertCount(1, $lista);
        $this->assertSame('combo', $lista[0]['fase']);
        $this->assertSame('v300*3', $lista[0]['chave']);
    }

    public function test_par_fora_da_lista_nao_gera_nada(): void
    {
        $r = $this->retrato($this->mesaECadeira(), []);
        $this->assertSame([], $this->fases($r, 'kit'));
        $this->assertSame([], $this->fases($r, 'combit'));
    }

    public function test_par_na_lista_em_ordem_inversa_funciona_igual(): void
    {
        $normal  = $this->retrato($this->mesaECadeira());
        $inverso = $this->retrato($this->mesaECadeira(), [['a' => 'cadeira', 'b' => 'mesa', 'repete' => 'a']]);

        $this->assertSame(GeradorDeSugestoes::gerar($normal), array_map(
            function ($s) {
                $s['par'] = $s['par'] === null ? null : ['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'b'];

                return $s;
            },
            GeradorDeSugestoes::gerar($inverso)
        ));
    }

    public function test_combit_repete_so_o_lado_dirigido(): void
    {
        $r = $this->retrato($this->mesaECadeira());
        $this->assertSame(['v100*1+v200*2', 'v100*1+v200*4', 'v100*1+v200*6'], $this->fases($r, 'combit'));
    }

    public function test_combit_ambos_repete_os_dois_lados(): void
    {
        $r = $this->retrato(
            $this->mesaECadeira(['qtd_combit' => [2]]),
            [['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'ambos']]
        );
        $chaves = $this->fases($r, 'combit');
        $this->assertContains('v100*2+v200*1', $chaves);
        $this->assertContains('v100*1+v200*4', $chaves);
        $this->assertCount(4, $chaves);
    }

    public function test_mesmo_tipo_dos_dois_lados_repete_os_dois(): void
    {
        $r = $this->retrato(
            [$this->produto(1, 'Cadeira A', 'cadeira'), $this->produto(2, 'Cadeira B', 'cadeira')],
            [['a' => 'cadeira', 'b' => 'cadeira', 'repete' => 'a']]
        );
        $this->assertCount(6, $this->fases($r, 'combit'));
        $this->assertCount(1, $this->fases($r, 'kit'));
    }

    public function test_repete_nulo_so_kit(): void
    {
        $r = $this->retrato($this->mesaECadeira(), [['a' => 'mesa', 'b' => 'cadeira', 'repete' => null]]);
        $this->assertSame([], $this->fases($r, 'combit'));
        $this->assertSame(['v100*1+v200*1'], $this->fases($r, 'kit'));
    }

    public function test_override_vazio_do_produto_anula_o_combo_do_tipo(): void
    {
        $r = $this->retrato([$this->produto(2, 'Cadeira Polo', 'cadeira', ['qtd_combo' => []])]);
        $this->assertSame([], $this->fases($r, 'combo'));
    }

    public function test_override_do_produto_vence_o_tipo(): void
    {
        $r = $this->retrato([$this->produto(2, 'Cadeira Polo', 'cadeira', ['qtd_combo' => [8]])]);
        $this->assertSame(['v200*8'], $this->fases($r, 'combo'));
    }

    public function test_combit_usa_qtd_combit_do_lado_repetido_e_combo_usa_qtd_combo(): void
    {
        $r = $this->retrato($this->mesaECadeira([], ['qtd_combo' => [3], 'qtd_combit' => [5]]));
        $this->assertSame(['v200*3'], $this->fases($r, 'combo'));
        $this->assertSame(['v100*1+v200*5'], $this->fases($r, 'combit'));
    }

    public function test_quantidade_um_nunca_vira_combo(): void
    {
        $r = $this->retrato([$this->produto(2, 'Cadeira Polo', 'cadeira', ['qtd_combo' => [1, 2]])]);
        $this->assertSame(['v200*2'], $this->fases($r, 'combo'));
    }

    public function test_nunca_mais_de_dois_itens(): void
    {
        $r = $this->retrato([
            $this->produto(1, 'Mesa Polo', 'mesa'),
            $this->produto(2, 'Cadeira Polo', 'cadeira'),
            $this->produto(3, 'Cadeira Bis', 'cadeira'),
        ]);
        foreach (GeradorDeSugestoes::gerar($r) as $s) {
            $this->assertLessThanOrEqual(2, count($s['itens']));
        }
    }

    public function test_variacoes_em_paralelo_sem_cruzar(): void
    {
        $mesa = $this->produto(1, 'Mesa Polo', 'mesa', ['variacoes' => [
            ['id' => 11, 'ordem' => 0, 'eixo' => 'Cor', 'valor' => 'Natural', 'sku' => 'M1', 'oferta_id' => 1],
            ['id' => 12, 'ordem' => 1, 'eixo' => 'Cor', 'valor' => 'Preto', 'sku' => 'M2', 'oferta_id' => 2],
        ]]);
        $cadeira = $this->produto(2, 'Cadeira Polo', 'cadeira', ['variacoes' => [
            ['id' => 21, 'ordem' => 0, 'eixo' => 'Cor', 'valor' => 'Natural', 'sku' => 'C1', 'oferta_id' => 3],
            ['id' => 22, 'ordem' => 1, 'eixo' => 'Cor', 'valor' => 'Preto', 'sku' => 'C2', 'oferta_id' => 4],
        ]]);

        $this->assertSame(['v11*1+v21*1', 'v12*1+v22*1'], $this->fases($this->retrato([$mesa, $cadeira]), 'kit'));
    }
}
