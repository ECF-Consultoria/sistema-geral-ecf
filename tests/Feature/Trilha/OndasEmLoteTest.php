<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * R12 do 1º teste do usuário (04/11/2026): no Planejamento, marcar várias ofertas e [Pôr na onda] de uma vez.
 *
 * O que este teste segura:
 * - as regras são AS DE PÔR UM — cada recusa do lote traz o mesmo texto que o `planejar` (a rota de um item) dá no 422
 *   para aquele item;
 * - a resposta é POR ITEM (entrou / já estava / recusado com o motivo) e o recusado não barra os outros;
 * - nada parcial DENTRO de um item: o Combo e a Simples que ele puxa entram juntos ou nenhum;
 * - o Combo marcado junto com a Simples dele não é recusado por vir antes dela na lista;
 * - a guarda e a forma do pedido são as da escrita das ondas (403/404/422), com contador próprio.
 */
class OndasEmLoteTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    private Company $empresa;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_poe_varias_de_uma_vez_e_responde_por_item_o_recusado_nao_barra_os_outros(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB-1');
        $c = $this->produtoCompleto($this->empresa, 'Vaso Gama', 'VG-1');
        $andou = $this->produtoCompleto($this->empresa, 'Vaso Andou', 'VD-1');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $andou), PubRascunho::VALIDATED);
        $avulsa = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AVULSO-1', 'fase' => 'simples', 'nome' => 'Avulso']);
        $onda = $this->onda($this->empresa, '2026-11-08', 'Onda 1');
        $sA = "s-{$a['produto']->id}";
        $sB = "s-{$b['produto']->id}";
        $sC = "s-{$c['produto']->id}";
        $sD = "s-{$andou['produto']->id}";
        $oX = "o-{$avulsa->id}";

        $r = $this->lote([$sA, $sB, $sD, $oX, $sC], $onda->id)->assertOk();

        $this->assertSame([
            [$sA, 'Vaso Alfa', 'entrou'], [$sB, 'Vaso Beta', 'entrou'], [$sD, 'Vaso Andou', 'recusado'],
            [$oX, 'Avulso', 'recusado'], [$sC, 'Vaso Gama', 'entrou'],
        ], array_map(fn (array $i) => [$i['item'], $i['nome'], $i['situacao']], $r->json('resultados')), 'na ordem do pedido, com o nome');
        $this->assertSame('"Vaso Andou" já começou (preparada, em revisão ou no ar): não entra em onda.', $r->json('resultados.2.motivo'));
        $this->assertSame('"Avulso" não tem produto na Lista: por enquanto fica fora das ondas.', $r->json('resultados.3.motivo'));
        $this->assertSame([3, 2], [$r->json('entraram'), $r->json('recusadas')]);
        $this->assertSame('3 ofertas na Onda 1. O robô começa em 08/11. 2 recusadas: veja o motivo de cada uma.', $r->json('mensagem'));
        $this->assertEqualsCanonicalizing([$sA, $sB, $sC], TrilhaOndaItem::all()->map->chave()->all());
        $this->assertSame([$onda->id], TrilhaOndaItem::query()->distinct()->pluck('onda_id')->map(fn ($id) => (int) $id)->all());
        Queue::assertNothingPushed();

        // UM registro no histórico, com o que entrou e o que foi recusado (e por quê).
        $eventos = $this->eventos();
        $this->assertCount(1, $eventos);
        $this->assertSame([true, [$sA, $sB, $sC]], [$eventos[0]->getExtraProperty('lote'), $eventos[0]->getExtraProperty('itens')]);
        $this->assertSame([$sD, $oX], array_keys($eventos[0]->getExtraProperty('recusados')));
        $this->assertSame($this->dev->id, (int) $eventos[0]->causer_id);
    }

    /**
     * As MESMAS regras de pôr um: para cada caso que o `planejar` recusa (422), o lote recusa aquele item com o MESMO
     * texto — a oferta que andou, a sem produto, a de outra loja, a que está numa onda que já começou e o Combo antes da
     * Simples.
     */
    public function test_cada_recusa_do_lote_e_a_mesma_de_por_um(): void
    {
        $andou = $this->produtoCompleto($this->empresa, 'Vaso Andou', 'VD-1');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $andou), PubRascunho::VALIDATED);
        $avulsa = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AVULSO-1', 'fase' => 'simples', 'nome' => 'Avulso']);
        $deFora = $this->produtoCompleto($this->empresaDaTrilha(), 'Vaso Fora', 'VX-1');
        $presa = $this->produtoCompleto($this->empresa, 'Vaso Preso', 'VP-1');
        $comCombo = $this->produtoCompleto($this->empresa, 'Vaso Combo', 'VC-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VC-1-CB2', 'Combo 2 Vaso Combo', [[$comCombo['simples'][0], 2]]);

        $hoje = $this->onda($this->empresa, '2026-11-05', 'Onda Hoje', '2026-11-05 07:00:00');
        $cedo = $this->onda($this->empresa, '2026-11-07', 'Onda Cedo');
        $tarde = $this->onda($this->empresa, '2026-11-09', 'Onda Tarde');
        $this->itemNaOnda($hoje, "s-{$presa['produto']->id}", '2026-11-05 07:05:00');
        $this->itemNaOnda($tarde, "s-{$comCombo['produto']->id}");

        $casos = [
            "s-{$andou['produto']->id}"     => $cedo,
            "o-{$avulsa->id}"               => $cedo,
            "s-{$deFora['produto']->id}"    => $cedo,
            "s-{$presa['produto']->id}"     => $cedo,
            "c-{$comCombo['produto']->id}-2" => $cedo,
        ];
        foreach ($casos as $chave => $onda) {
            $deUm = $this->putJson($this->url('planejamento'), ['itens' => [$chave], 'onda_id' => $onda->id])->assertStatus(422)->json('errors.itens.0');
            $this->assertNotEmpty($deUm, $chave);

            $doLote = $this->lote([$chave], $onda->id)->assertOk()->json('resultados.0');
            $this->assertSame(['recusado', $deUm], [$doLote['situacao'], $doLote['motivo']], $chave);
        }
        $this->assertEqualsCanonicalizing(["s-{$presa['produto']->id}", "s-{$comCombo['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all(), 'nada mudou');
        $this->assertCount(0, $this->eventos(), 'nada gravado, nada registrado');
    }

    /**
     * O Combo sem a Simples planejada a puxa para a mesma onda (a regra de pôr um) — e isso é UM item: se o Combo for
     * recusado, a Simples que ele puxaria também não entra, e o próximo item do lote confere o plano de antes. O estado de
     * partida (um Combo numa onda com a Simples sem onda) a regra não deixaria nascer: vem direto do banco, como o que
     * sobra de um dado antigo.
     */
    public function test_o_combo_e_a_simples_que_ele_puxa_entram_juntos_ou_nenhum(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        foreach ([2, 3, 4] as $n) {
            $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, "VA-1-CB{$n}", "Combo {$n} Vaso Alfa", [[$p['simples'][0], $n]]);
        }
        $s = "s-{$p['produto']->id}";
        [$c2, $c3, $c4] = ["c-{$p['produto']->id}-2", "c-{$p['produto']->id}-3", "c-{$p['produto']->id}-4"];
        $antes = $this->onda($this->empresa, '2026-11-06', 'Onda Antes');
        $depois = $this->onda($this->empresa, '2026-11-08', 'Onda Depois');

        // O caso normal: o Combo puxa a Simples, e a resposta diz quem veio junto.
        $r = $this->lote([$c2], $depois->id)->assertOk();
        $this->assertSame(['entrou', [$s]], [$r->json('resultados.0.situacao'), $r->json('resultados.0.junto')]);
        $this->assertSame('A simples de Vaso Alfa entrou junto, na Onda Depois: o combo nasce dela.', $r->json('avisos.0'));
        $this->assertSame('2 ofertas na Onda Depois. O robô começa em 08/11.', $r->json('mensagem'));
        $this->assertEqualsCanonicalizing([$s, $c2], TrilhaOndaItem::all()->map->chave()->all());

        // Recomeça: o Combo 3 numa onda que começa ANTES, com a Simples sem onda (dado antigo).
        TrilhaOndaItem::query()->delete();
        $this->itemNaOnda($antes, $c3);
        $r = $this->lote([$c2, $c4], $depois->id)->assertOk();

        // Cada Combo puxaria a Simples para 08/11, depois do Combo 3 (06/11): os dois são recusados, e a Simples não fica.
        $this->assertSame(['recusado', 'recusado'], array_column($r->json('resultados'), 'situacao'));
        $this->assertStringContainsString('A simples de Vaso Alfa começaria em 08/11', $r->json('resultados.1.motivo'));
        $this->assertSame([$c3], TrilhaOndaItem::all()->map->chave()->all(), 'nem o Combo nem a Simples que ele puxaria');
        $this->assertSame('Nenhuma oferta entrou na Onda Depois. 2 recusadas: veja o motivo de cada uma.', $r->json('mensagem'));
    }

    /**
     * A Simples e o Combo dela marcados juntos, com o Combo ANTES na lista: o lote confere as Simples primeiro, então o
     * Combo encontra a Simples já adiantada — o mesmo que o `planejar` aceita para os dois de uma vez.
     */
    public function test_o_combo_marcado_com_a_simples_entra_mesmo_vindo_antes_dela_na_lista(): void
    {
        $p = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$p['simples'][0], 2]]);
        $s = "s-{$p['produto']->id}";
        $c = "c-{$p['produto']->id}-2";
        $cedo = $this->onda($this->empresa, '2026-11-07', 'Onda Cedo');
        $tarde = $this->onda($this->empresa, '2026-11-09', 'Onda Tarde');
        $this->itemNaOnda($tarde, $s);

        $r = $this->lote([$c, $s], $cedo->id)->assertOk();

        $this->assertSame([[$c, 'entrou'], [$s, 'entrou']], array_map(fn (array $i) => [$i['item'], $i['situacao']], $r->json('resultados')));
        $this->assertSame([$cedo->id, $cedo->id], TrilhaOndaItem::query()->orderBy('id')->pluck('onda_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_o_que_ja_estava_na_onda_nao_muda_nada(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $onda = $this->onda($this->empresa, '2026-11-08', 'Onda 1');
        $linha = $this->itemNaOnda($onda, "s-{$a['produto']->id}");

        $r = $this->lote(["s-{$a['produto']->id}"], $onda->id)->assertOk();

        $this->assertSame(['ja_estava', null, []], [$r->json('resultados.0.situacao'), $r->json('resultados.0.motivo'), $r->json('resultados.0.junto')]);
        $this->assertSame('Nada mudou: já estavam na Onda 1.', $r->json('mensagem'));
        $this->assertSame($linha->na_onda_desde?->toIso8601String(), $linha->fresh()->na_onda_desde?->toIso8601String(), 'a contagem do item não recomeça');
        $this->assertCount(0, $this->eventos());
    }

    /** Numa onda que já começou o que entra começa na hora: a soltura roda depois do pedido, para os itens novos. */
    public function test_na_onda_que_ja_comecou_o_robo_e_chamado(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Vaso Beta', 'VB-1');
        $hoje = $this->onda($this->empresa, '2026-11-05', 'Onda Hoje');

        $this->lote(["s-{$a['produto']->id}", "s-{$b['produto']->id}"], $hoje->id)
            ->assertOk()
            ->assertJsonPath('mensagem', '2 ofertas na Onda Hoje. Ela já começou: o robô foi chamado.')
            ->assertJsonPath('onda', ['id' => $hoje->id, 'nome' => 'Onda Hoje', 'data' => '2026-11-05', 'comecou' => true]);

        foreach ([$a, $b] as $p) {
            Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $p['produto']->id);
        }
        $this->assertSame(2, TrilhaOndaItem::query()->whereNotNull('pedido_em')->count());
    }

    /** A forma do pedido é do pedido inteiro (422); a guarda é a das escritas das ondas; contador próprio. */
    public function test_a_forma_do_pedido_e_a_guarda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $onda = $this->onda($this->empresa, '2026-11-08', 'Onda 1');
        $ondaDeFora = $this->onda($this->empresaDaTrilha(), '2026-11-08', 'Onda de Fora');
        $s = "s-{$a['produto']->id}";

        $this->assertErro($this->lote([], $onda->id), 'itens', 'Marque ao menos uma oferta.');
        $muitos = array_map(fn (int $i) => "s-{$i}", range(1, OndasDaTrilha::MAXIMO_DO_LOTE + 1));
        $this->assertErro($this->lote($muitos, $onda->id), 'itens', 'Até 100 ofertas por vez.');
        $this->assertErro($this->lote([$s, 'x-1'], $onda->id), 'itens.1', 'Oferta inválida.');
        $this->putJson($this->url('planejamento/lote'), ['itens' => [$s]])->assertStatus(422)->assertJsonValidationErrors('onda_id');
        // Tirar não é pelo lote: sem onda, 422.
        $this->putJson($this->url('planejamento/lote'), ['itens' => [$s], 'onda_id' => null])->assertStatus(422)->assertJsonValidationErrors('onda_id');
        $this->assertErro($this->lote([$s], $ondaDeFora->id), 'onda_id', 'Essa onda não existe nesta loja.');
        $this->assertSame(0, TrilhaOndaItem::count());

        // Quem não escreve no Portal da empresa: 403. A loja fora da trilha de trabalho: 404.
        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $this->actingAs($semCarteira->refresh())->putJson("/trilha/{$this->empresa->id}/planejamento/lote", ['itens' => [$s], 'onda_id' => $onda->id])->assertForbidden();
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $this->actingAs($this->dev)->putJson("/trilha/{$soLeitura->id}/planejamento/lote", ['itens' => [$s], 'onda_id' => $onda->id])->assertNotFound();
        $this->assertSame(0, TrilhaOndaItem::count());

        $rota = Route::getRoutes()->getByName('trilha.planejamento.lote');
        $this->assertSame(['PUT'], array_values(array_diff($rota->methods(), ['HEAD'])));
        $this->assertContains('throttle:10,1,trilha.ondas.lote', $rota->gatherMiddleware());
        $this->assertContains('modulo:trilha.produtos', $rota->gatherMiddleware());
    }

    // ─── apoio ───

    private function lote(array $itens, ?int $ondaId): TestResponse
    {
        return $this->putJson($this->url('planejamento/lote'), ['itens' => $itens, 'onda_id' => $ondaId]);
    }

    private function url(string $caminho): string
    {
        $this->actingAs($this->dev);

        return "/trilha/{$this->empresa->id}/{$caminho}";
    }

    private function eventos()
    {
        return Activity::query()->where('log_name', 'portal')->orderBy('id')->get()
            ->filter(fn (Activity $a) => $a->getExtraProperty('evento') === 'trilha_itens_na_onda')->values();
    }

    /** `assertJsonPath` não alcança `errors.itens.1` com o ponto da chave (learnings §31). */
    private function assertErro(TestResponse $r, string $campo, string $mensagem): void
    {
        $r->assertStatus(422);
        $this->assertSame($mensagem, $r->json('errors')[$campo][0] ?? null, $campo);
    }
}
