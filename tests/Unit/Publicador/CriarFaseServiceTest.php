<?php

namespace Tests\Unit\Publicador;

use App\Models\PubProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 175 Plano 175-02 (§5 da ETAPA-3) — `CriarFaseService` e os helpers de
 * família do `PubProduto`.
 *
 * As duas primeiras baterias são dos helpers PUROS (`proximaFase` /
 * `proximaQuantidade`): é o que a §8 da spec pede ("funções puras com teste
 * unitário"). Elas não encostam no banco de propósito — a regra "menor N ≥ 2
 * que a família ainda não tem" é aritmética, e aritmética se prova sem fixture.
 *
 * ⚠️ `pub_produtos.fase` é o NÚMERO da fase. `estrutura_ofertas.fase` é o TIPO da
 * oferta no Portal (`simples|combo|kit|combit`) — nada a ver.
 *
 * @group phase175
 */
class CriarFaseServiceTest extends TestCase
{
    use RefreshDatabase;

    // ═══ Helpers puros do PubProduto (sem banco) ═════════════════════════════

    /** `max(fases) + 1`; família só com a Fase 1 (ou lista vazia) → 2. */
    public function test_proxima_fase_e_a_maior_mais_um(): void
    {
        $this->assertSame(2, PubProduto::proximaFase([]), 'família vazia: o base é a Fase 1, o próximo é 2');
        $this->assertSame(2, PubProduto::proximaFase([1]), 'só o base: próximo é 2');
        $this->assertSame(3, PubProduto::proximaFase([1, 2]));
        $this->assertSame(4, PubProduto::proximaFase([3, 1, 2]), 'lista fora de ordem');
        $this->assertSame(6, PubProduto::proximaFase([1, 2, 5]), 'buraco na sequência não é reaproveitado: fase é cronológica');
    }

    /** Menor inteiro ≥ 2 que a família ainda não tem — AQUI o buraco é reaproveitado. */
    public function test_proxima_quantidade_e_o_menor_inteiro_livre_acima_de_um(): void
    {
        $this->assertSame(2, PubProduto::proximaQuantidade([]), 'família vazia: o primeiro kit é o de 2');
        $this->assertSame(2, PubProduto::proximaQuantidade([1]), 'a unidade do base (1) nunca conta como kit');
        $this->assertSame(3, PubProduto::proximaQuantidade([1, 2]));
        $this->assertSame(3, PubProduto::proximaQuantidade([1, 2, 4]), 'família com Kit 2 e Kit 4 → 3');
        $this->assertSame(5, PubProduto::proximaQuantidade([4, 2, 1, 3]), 'lista fora de ordem');
        $this->assertSame(2, PubProduto::proximaQuantidade([5, 9]), 'nada entre 2 e 4: devolve 2');
        $this->assertSame(3, PubProduto::proximaQuantidade([2, 2, 2]), 'repetida não abre buraco');
    }
}
