<?php

namespace Tests\Unit\Publicador;

use App\Models\MlbEmpresa;
use App\Models\PubProduto;
use Carbon\CarbonInterface;
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

    // ═══ A família em Eloquent ═══════════════════════════════════════════════

    public function test_eh_kit_exige_base_e_duas_unidades(): void
    {
        $base = $this->base($this->empresa());

        $this->assertFalse($base->ehKit(), 'base nunca é kit');
        $this->assertFalse($this->kit($base, 1, 2)->ehKit(), 'aponta para o base mas leva 1 unidade: não é kit');
        $this->assertTrue($this->kit($base, 2, 3)->ehKit());
    }

    public function test_base_e_kits_resolvem_a_familia_nos_dois_sentidos(): void
    {
        $base = $this->base($this->empresa());
        $kit3 = $this->kit($base, 3, 3);
        $kit2 = $this->kit($base, 2, 2);

        $this->assertNull($base->base, 'o base não aponta para ninguém');
        $this->assertSame($base->id, $kit2->base->id);
        // `kits()` ordena por fase, não pela ordem de criação (o Kit 3 nasceu primeiro).
        $this->assertSame([$kit2->id, $kit3->id], $base->kits()->pluck('id')->all());
        $this->assertSame([], $kit2->kits()->pluck('id')->all(), 'kit nunca tem kit (sem cadeia)');
    }

    public function test_familia_e_a_mesma_lista_vista_do_base_ou_de_um_kit(): void
    {
        $base = $this->base($this->empresa());
        $kit4 = $this->kit($base, 4, 4);
        $kit2 = $this->kit($base, 2, 2);
        // Outro base da mesma empresa: não pode vazar para a família.
        $this->base($this->empresa('Outro polo'), 'MES-01', 'Mesa');

        $esperada = [$base->id, $kit2->id, $kit4->id];
        $this->assertSame($esperada, $base->familia()->pluck('id')->all(), 'vista do base');
        $this->assertSame($esperada, $kit4->familia()->pluck('id')->all(), 'vista de um kit');
        $this->assertSame([1, 2, 4], $base->familia()->pluck('fase')->all(), 'em ordem de fase');
    }

    /** Os casts das colunas do 175-01 — sem eles quem lê precisa converter à mão. */
    public function test_casts_das_colunas_de_fase(): void
    {
        $base = $this->base($this->empresa());
        $kit = $this->kit($base, 2, 2);
        $kit->update(['kit_sugestao_recusada_em' => '2026-10-08 13:45:00']);

        $lido = $kit->fresh();
        $this->assertSame(2, $lido->fase);
        $this->assertSame(2, $lido->quantidade_kit);
        $this->assertTrue($lido->estoque_calculado);
        $this->assertInstanceOf(CarbonInterface::class, $lido->kit_sugestao_recusada_em);
        $this->assertSame('2026-10-08 13:45:00', $lido->kit_sugestao_recusada_em->format('Y-m-d H:i:s'));

        $this->assertSame(1, $base->fresh()->fase);
        $this->assertFalse($base->fresh()->estoque_calculado);
        $this->assertNull($base->fresh()->kit_sugestao_recusada_em);
    }

    // ═══ Fixtures ════════════════════════════════════════════════════════════

    private function empresa(string $nome = 'Polo das Fases'): MlbEmpresa
    {
        return MlbEmpresa::create(['nome' => $nome, 'projeto' => 'POLOS']);
    }

    private function base(MlbEmpresa $empresa, string $sku = 'CAD-01', string $nome = 'Cadeira'): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $empresa->id, 'sku' => $sku, 'nome' => $nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR]);
    }

    private function kit(PubProduto $base, int $quantidade, int $fase): PubProduto
    {
        return PubProduto::create(['mlb_empresa_id' => $base->mlb_empresa_id, 'company_id' => $base->company_id,
            'sku' => $base->sku.'-KIT'.$quantidade, 'nome' => 'Kit '.$quantidade.' '.$base->nome,
            'origem' => PubProduto::ORIGEM_PUBLICADOR, 'produto_base_id' => $base->id,
            'quantidade_kit' => $quantidade, 'fase' => $fase, 'estoque_calculado' => true]);
    }
}
