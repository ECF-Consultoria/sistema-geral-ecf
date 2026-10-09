<?php

namespace Tests\Unit\PortalEstrutura\Geracao;

use App\Models\EstruturaOferta;
use App\Services\Portal\Estrutura\Geracao\GeradorDeSugestoes;
use PHPUnit\Framework\TestCase;

/**
 * Gerador puro: determinismo, forma da saída e regra de composição (Fase 168).
 * Fixtures 100% sintéticas, em arrays.
 */
class GeradorDeSugestoesTest extends TestCase
{
    private function tipos(): array
    {
        return [
            'mesa'    => ['nome' => 'Mesa', 'plural' => 'Mesas', 'qtd_combo' => [], 'qtd_combit' => [], 'ordem' => 10],
            'cadeira' => ['nome' => 'Cadeira', 'plural' => 'Cadeiras', 'qtd_combo' => [2, 4], 'qtd_combit' => [2, 4], 'ordem' => 70],
        ];
    }

    private function variacao(int $id, ?int $oferta = null, ?string $valor = null, int $ordem = 0): array
    {
        return ['id' => $id, 'ordem' => $ordem, 'eixo' => $valor !== null ? 'Cor' : null, 'valor' => $valor, 'sku' => "SKU{$id}", 'oferta_id' => $oferta ?? $id + 5000];
    }

    private function produto(int $id, string $nome, ?string $tipo, array $variacoes, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'nome' => $nome, 'familia_id' => 1, 'familia' => 'Polo',
            'ambientes' => [10 => 'Sala'], 'tipo' => $tipo, 'qtd_combo' => null, 'qtd_combit' => null,
            'variacoes' => $variacoes,
        ], $extra);
    }

    private function retrato(array $produtos, array $extra = []): array
    {
        return array_merge([
            'produtos'    => $produtos,
            'tipos'       => $this->tipos(),
            'pares'       => [['a' => 'mesa', 'b' => 'cadeira', 'repete' => 'b']],
            'existentes'  => [],
            'descartadas' => [],
            'limites'     => ['max_titulo' => 60, 'max_sku' => 120],
        ], $extra);
    }

    private function basico(): array
    {
        return $this->retrato([
            $this->produto(1, 'Mesa Polo', 'mesa', [$this->variacao(101, null, 'Natural'), $this->variacao(102, null, 'Preto', 1)]),
            $this->produto(2, 'Cadeira Polo', 'cadeira', [$this->variacao(201, null, 'Natural'), $this->variacao(202, null, 'Preto', 1)]),
        ]);
    }

    public function test_e_deterministico(): void
    {
        $r = $this->basico();
        $this->assertSame(GeradorDeSugestoes::gerar($r), GeradorDeSugestoes::gerar($r));
    }

    public function test_ordem_da_entrada_nao_muda_a_saida(): void
    {
        $r = $this->basico();
        $embaralhado = $r;
        $embaralhado['produtos'] = array_reverse($r['produtos']);
        foreach ($embaralhado['produtos'] as &$p) {
            $p['variacoes'] = array_reverse($p['variacoes']);
        }
        unset($p);

        $this->assertSame(GeradorDeSugestoes::gerar($r), GeradorDeSugestoes::gerar($embaralhado));
    }

    public function test_so_variacao_com_oferta_entra(): void
    {
        $r = $this->retrato([
            $this->produto(2, 'Cadeira Polo', 'cadeira', [
                $this->variacao(201, null, 'Natural'),
                ['id' => 202, 'ordem' => 1, 'eixo' => 'Cor', 'valor' => 'Preto', 'sku' => 'SKU202', 'oferta_id' => null],
            ]),
        ]);

        foreach (GeradorDeSugestoes::gerar($r) as $s) {
            foreach ($s['itens'] as $i) {
                $this->assertNotSame(202, $i['variacao_id']);
            }
        }
        $this->assertCount(2, GeradorDeSugestoes::gerar($r));
    }

    public function test_cada_fase_cumpre_a_regra_de_composicao(): void
    {
        $fases = [];
        foreach (GeradorDeSugestoes::gerar($this->basico()) as $s) {
            $fases[$s['fase']] = true;
            $qtds = array_column($s['itens'], 'quantidade');
            $this->assertLessThanOrEqual(2, count($qtds));

            match ($s['fase']) {
                EstruturaOferta::FASE_COMBO  => $this->assertTrue(count($qtds) === 1 && $qtds[0] >= 2),
                EstruturaOferta::FASE_KIT    => $this->assertSame([1, 1], $qtds),
                EstruturaOferta::FASE_COMBIT => $this->assertTrue(count($qtds) === 2 && max($qtds) >= 2 && min($qtds) === 1),
            };
        }
        $this->assertCount(3, $fases);
    }

    public function test_campos_preenchidos(): void
    {
        foreach (GeradorDeSugestoes::gerar($this->basico()) as $s) {
            foreach (['chave', 'nome', 'sku', 'porque'] as $campo) {
                $this->assertNotSame('', (string) $s[$campo], $campo);
            }
            $this->assertFalse($s['descartada']);
            $this->assertNull($s['descartada_em']);
            $this->assertSame('Polo', $s['familia']);
        }
    }

    public function test_avisos_usam_os_limites_do_retrato(): void
    {
        $r = $this->basico();
        $r['limites'] = ['max_titulo' => 5, 'max_sku' => 3];

        foreach (GeradorDeSugestoes::gerar($r) as $s) {
            $codigos = array_column($s['avisos'], 'codigo');
            $this->assertContains('titulo_longo', $codigos);
            $this->assertContains('sku_longo', $codigos);
        }
    }

    public function test_ordem_familia_fase_chave(): void
    {
        $r = $this->retrato([
            $this->produto(9, 'Cadeira Zeta', 'cadeira', [$this->variacao(901)], ['familia_id' => 5, 'familia' => 'Zeta']),
            $this->produto(8, 'Cadeira Avulsa', 'cadeira', [$this->variacao(801)], ['familia_id' => null, 'familia' => null]),
            $this->produto(1, 'Mesa Alfa', 'mesa', [$this->variacao(101)], ['familia_id' => 2, 'familia' => 'Alfa']),
            $this->produto(2, 'Cadeira Alfa', 'cadeira', [$this->variacao(201)], ['familia_id' => 2, 'familia' => 'Alfa']),
        ]);

        $lista = GeradorDeSugestoes::gerar($r);
        $familias = array_map(fn ($s) => $s['familia'], $lista);
        $this->assertSame(['Alfa', 'Zeta', null], array_values(array_unique($familias, SORT_REGULAR)));

        // 08/10: dentro da família, Kit e Combit antes do Combo.
        $ordemFase = ['kit' => 0, 'combit' => 1, 'combo' => 2];
        $alfa = array_values(array_filter($lista, fn ($s) => $s['familia'] === 'Alfa'));
        $fases = array_map(fn ($s) => $ordemFase[$s['fase']], $alfa);
        $ordenado = $fases;
        sort($ordenado);
        $this->assertSame($ordenado, $fases);
        $this->assertSame('kit', $alfa[0]['fase']);
        $this->assertSame('combo', $alfa[count($alfa) - 1]['fase']);
    }

    public function test_kit_orienta_pela_ordem_do_tipo(): void
    {
        $r = $this->retrato([
            $this->produto(1, 'Cadeira Polo', 'cadeira', [$this->variacao(201)]),
            $this->produto(2, 'Mesa Polo', 'mesa', [$this->variacao(101)]),
        ]);

        $kit = array_values(array_filter(GeradorDeSugestoes::gerar($r), fn ($s) => $s['fase'] === 'kit'))[0];
        $this->assertSame('Mesa Polo + Cadeira Polo', $kit['nome']);
        $this->assertSame(['mesa', 'cadeira'], $kit['tipos']);
    }

    /** 08/10 (teste do usuário): cores que não se repetem entre mesa e cadeira davam zero Kit. */
    public function test_mesa_e_cadeira_com_cores_diferentes_geram_kit_e_combit(): void
    {
        $r = $this->retrato([
            $this->produto(1, 'Mesa Polo', 'mesa', [$this->variacao(101, null, 'Freijó'), $this->variacao(102, null, 'Off White', 1)]),
            $this->produto(2, 'Cadeira Polo', 'cadeira', [$this->variacao(201, null, 'Linho Bege'), $this->variacao(202, null, 'Linho Cinza', 1)]),
        ]);

        $por = array_column(GeradorDeSugestoes::gerar($r), null, 'chave');

        $this->assertSame('kit', $por['v101*1+v201*1']['fase']);
        $this->assertSame('kit', $por['v102*1+v202*1']['fase']);
        $this->assertSame('Mesa Polo + Cadeira Polo — Freijó / Linho Bege', $por['v101*1+v201*1']['nome']);
        $this->assertSame('combit', $por['v101*1+v201*4']['fase']);
        $this->assertArrayNotHasKey('v101*1+v202*1', $por, 'sem cartesiano');
    }

    public function test_existente_nao_sai_e_descartada_volta_marcada(): void
    {
        $r = $this->basico();
        $r['existentes']  = ['v101*1+v201*1' => true];
        $r['descartadas'] = ['v101*1+v201*2' => '2026-10-01'];

        $lista = GeradorDeSugestoes::gerar($r);
        $porChave = array_column($lista, null, 'chave');

        $this->assertArrayNotHasKey('v101*1+v201*1', $porChave);
        $this->assertTrue($porChave['v101*1+v201*2']['descartada']);
        $this->assertSame('2026-10-01', $porChave['v101*1+v201*2']['descartada_em']);
    }

    public function test_chaves_unicas(): void
    {
        $chaves = array_column(GeradorDeSugestoes::gerar($this->basico()), 'chave');
        $this->assertSame($chaves, array_values(array_unique($chaves)));
    }

    public function test_nomes_do_combo_e_do_combit(): void
    {
        $por = array_column(GeradorDeSugestoes::gerar($this->basico()), null, 'chave');

        $this->assertSame('Kit 4 Cadeiras Polo — Natural', $por['v201*4']['nome']);
        $this->assertSame('Mesa Polo + 4 Cadeiras — Natural', $por['v101*1+v201*4']['nome']);
    }
}
