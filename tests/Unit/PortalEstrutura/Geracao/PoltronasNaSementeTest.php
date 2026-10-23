<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use App\Services\Portal\Estrutura\Geracao\Quantidades;
use PHPUnit\Framework\TestCase;

/**
 * Teste da empresa #459 (22/10/2026): a "Poltrona Rodrigues Marrom" e a "Poltrona Decorativa Opala" — mesma família
 * "Poltrona", ambiente "Sala", tipo Poltrona — não receberam nenhum Combo nem Kit, e uma mesa de cabeceira recebeu Combo.
 *
 * Não é defeito do gerador: é a SEMENTE (`config/estrutura_geracao.php`, mantida depois pela tela admin
 * /dev/estrutura-geracao). O tipo Poltrona nasce sem quantidade de Combo e nenhum par de tipos tem Poltrona — nem
 * Poltrona + Poltrona, como o Cama + Cama. O criado-mudo ("mesa de cabeceira") nasce com Combo de 2. Completude e
 * "já publicado" não entram na regra: só a variação com oferta simples ligada (D-09).
 */
class PoltronasNaSementeTest extends TestCase
{
    /** Tipos e pares como a semente os grava (o mesmo que o RetratoDoCatalogo lê do banco). */
    private static function semente(): array
    {
        $config = require __DIR__.'/../../../../config/estrutura_geracao.php';

        $tipos = [];
        foreach ($config['tipos'] as $slug => $t) {
            $tipos[$slug] = ['nome' => $t['nome'], 'plural' => $t['plural'], 'ordem' => $t['ordem'],
                'qtd_combo' => Quantidades::doTipo($t['qtd_combo']), 'qtd_combit' => Quantidades::doTipo($t['qtd_combit'])];
        }
        $pares = array_map(fn (array $p) => ['a' => $p['tipos'][0], 'b' => $p['tipos'][1], 'repete' => $p['repete']], $config['pares']);

        return [$tipos, $pares];
    }

    private static function produto(int $id, string $nome, string $tipo, string $cor, string $familia = 'Poltrona'): array
    {
        return ['id' => $id, 'nome' => $nome, 'familia_id' => 1, 'familia' => $familia, 'ambientes' => [10 => 'Sala'],
            'tipo' => $tipo, 'qtd_combo' => null, 'qtd_combit' => null,
            'variacoes' => [['id' => $id * 10, 'ordem' => 0, 'eixo' => 'Cor', 'valor' => $cor, 'sku' => "SKU{$id}", 'oferta_id' => $id * 100]]];
    }

    private static function gerar(array $tipos, array $pares): array
    {
        return GeradorDeSugestoes::gerar(['tipos' => $tipos, 'pares' => $pares, 'existentes' => [], 'descartadas' => [],
            'limites' => ['max_titulo' => 60, 'max_sku' => 120], 'produtos' => [
                self::produto(1, 'Poltrona Rodrigues', 'poltrona', 'Marrom'),
                self::produto(2, 'Poltrona Decorativa Opala', 'poltrona', 'Cinza'),
                self::produto(3, 'Mesa de Cabeceira Lis', 'criado-mudo', 'Branco', 'Quarto'),
            ]]);
    }

    public function test_com_a_semente_as_poltronas_nao_tem_combo_nem_kit_e_o_criado_mudo_tem_combo(): void
    {
        [$tipos, $pares] = self::semente();

        $this->assertSame([], $tipos['poltrona']['qtd_combo'], 'a semente não dá Combo à Poltrona');
        $this->assertSame([], array_filter($pares, fn ($p) => in_array('poltrona', [$p['a'], $p['b']], true)), 'nenhum par tem Poltrona');

        $s = self::gerar($tipos, $pares);
        $this->assertSame([[EstruturaOferta::FASE_COMBO, 3]], array_map(fn ($x) => [$x['fase'], $x['itens'][0]['produto_id']], $s));
    }

    public function test_com_quantidade_e_o_par_poltrona_poltrona_cadastrados_saem_combo_e_kit(): void
    {
        [$tipos, $pares] = self::semente();
        // O que a equipe cadastra na tela admin: Combo "2" no tipo Poltrona e o par Poltrona + Poltrona (só Kit).
        $tipos['poltrona']['qtd_combo'] = [2];
        $pares[] = ['a' => 'poltrona', 'b' => 'poltrona', 'repete' => null];

        $s = self::gerar($tipos, $pares);
        $fases = array_count_values(array_column($s, 'fase'));

        $this->assertSame(1, $fases[EstruturaOferta::FASE_KIT] ?? 0, 'Kit Rodrigues + Opala');
        $this->assertSame(3, $fases[EstruturaOferta::FASE_COMBO] ?? 0, 'Combo 2 de cada poltrona e o do criado-mudo');
        $this->assertArrayNotHasKey(EstruturaOferta::FASE_COMBIT, $fases, 'par sem repetição: nada de Combit');
    }
}
