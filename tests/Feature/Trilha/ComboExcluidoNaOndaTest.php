<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoVariacao;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use App\Services\Portal\Estrutura\EstruturaOfertaService;
use App\Services\Trilha\Ondas\OndasDaTrilha;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use App\Services\Trilha\TrilhaDaEmpresa;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O Combo que DEIXA DE EXISTIR sai da onda na hora da exclusão (`TrilhaComboExcluidoObserver`, revisão adversarial de
 * 02/11/2026). Antes isso era deduzido pela idade das ofertas (`comboRecriado`), que errava: o combo que ganhou uma cor
 * nova e depois perdeu a original nunca deixou de existir, mas parecia "recriado" e perdia a onda — até a que já tinha
 * começado, segurando o Kit N.
 */
class ComboExcluidoNaOndaTest extends TestCase
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
        $this->agora('2026-11-05 10:00:00');
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
        $this->empresa = $this->empresaDaTrilha();
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_o_combo_que_ganhou_uma_cor_e_perdeu_a_original_continua_na_onda_que_ja_comecou(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul']);
        $azul = $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-CB2', 'Combo 2 Vaso Alfa Azul', [[$a['simples'][0], 2]]);
        $c = "c-{$a['produto']->id}-2";
        $comecou = $this->onda($this->empresa, '2026-11-05', 'Onda Hoje', '2026-11-05 00:07:00');
        $this->itemNaOnda($comecou, "s-{$a['produto']->id}", '2026-11-05 00:07:00');
        $this->itemNaOnda($comecou, $c, '2026-11-05 00:07:00');

        // 10:05: a cor Verde chega, com o Combo 2 dela. 10:10: a cor Azul sai do combo (o cliente trocou a cor).
        $this->agora('2026-11-05 10:05:00');
        $verde = $this->novaCor($a, 'Verde');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-2-CB2', 'Combo 2 Vaso Alfa Verde', [[$verde, 2]]);
        $this->agora('2026-11-05 10:10:00');
        $azul->componentes()->delete();
        $azul->delete();

        // O Combo 2 nunca deixou de existir: a linha fica, a tela mostra a onda e a trava não segura o Kit N.
        $this->assertSame(1, TrilhaOndaItem::query()->where('quantidade', 2)->count());
        $this->assertSame(0, OndasDaTrilha::limparCombosOrfaos($this->empresa, TrilhaDaEmpresa::itens($this->empresa)), 'a rede de segurança também não a vê como órfã');
        $this->assertSame($comecou->id, $this->itemDaTrilha($this->trilha(), $c)['onda_id']);
        $this->assertFalse(app(PortaoDaOnda::class)->seguraOCombo((int) $a['produto']->id, 2, (int) $this->empresa->id));
    }

    public function test_apagada_a_ultima_cor_pelo_portal_a_linha_sai_na_hora_e_o_combo_aceito_de_novo_nasce_sem_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $ator = AtorDoPortal::daEquipe($this->dev);
        $portal = app(EstruturaOfertaService::class);
        $portal->criarCombos($a['simples'][0]->fresh(), [2], null, null, $ator);
        $portal->criarCombos($a['simples'][1]->fresh(), [2], null, null, $ator);
        $c = "c-{$a['produto']->id}-2";
        $futura = $this->onda($this->empresa, '2026-11-20', 'Onda Futura');
        $this->itemNaOnda($futura, "s-{$a['produto']->id}");
        $this->itemNaOnda($futura, $c);
        $combos = EstruturaOferta::query()->where('company_id', $this->empresa->id)->where('fase', EstruturaOferta::FASE_COMBO)->orderBy('id')->get();
        $this->assertCount(2, $combos);

        // Uma cor sai: o Combo 2 segue existindo (a outra cor), a linha fica.
        $portal->excluir($combos[0], $ator);
        $this->assertSame(1, TrilhaOndaItem::query()->where('quantidade', 2)->count(), 'ainda há uma cor no Combo 2');

        // A última sai: a linha vai junto, sem esperar a soltura.
        $portal->excluir($combos[1], $ator);
        $this->assertSame(0, TrilhaOndaItem::query()->where('quantidade', 2)->count(), 'o combo deixou de existir');
        $this->assertSame(1, TrilhaOndaItem::query()->where('quantidade', 1)->count(), 'a Simples segue na onda');

        // Aceito de novo minutos depois: é outra decisão — nasce Sem onda, e a trava segura o Kit N até alguém planejar.
        $this->agora('2026-11-05 10:05:00');
        $portal->criarCombos($a['simples'][0]->fresh(), [2], null, null, $ator);
        $this->assertNull($this->itemDaTrilha($this->trilha(), $c)['onda_id']);
        $this->assertTrue(app(PortaoDaOnda::class)->seguraOCombo((int) $a['produto']->id, 2, (int) $this->empresa->id));
    }

    public function test_fora_da_chave_apagar_combo_nao_toca_a_trilha(): void
    {
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $a = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $combo = $this->ofertaComposta($soLeitura, EstruturaOferta::FASE_COMBO, 'VL-CB2', 'Combo 2 Vaso Leitura', [[$a['simples'][0], 2]]);
        // Linha que só existiria se a chave tivesse estado ligada: fora dela o observador não lê nem apaga nada.
        $onda = $this->onda($soLeitura, '2026-11-20', 'Onda Antiga');
        $this->itemNaOnda($onda, "c-{$a['produto']->id}-2");

        $combo->componentes()->delete();
        $combo->delete();

        $this->assertSame(1, TrilhaOndaItem::query()->where('company_id', $soLeitura->id)->count());
    }

    // ─── apoio ───

    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
    }

    private function trilha(): array
    {
        return app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh());
    }

    /** Uma cor nova no produto, com a oferta simples dela (como o cadastro do Portal cria). */
    private function novaCor(array $p, string $cor): EstruturaOferta
    {
        $v = EstruturaProdutoVariacao::create([
            'produto_id' => $p['produto']->id, 'company_id' => $this->empresa->id, 'ordem' => 9,
            'codigo' => "VA-{$cor}", 'eixo' => 'cor', 'valor' => $cor, 'custo' => 120,
        ]);

        return EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => $v->id, 'sku' => $v->codigo,
            'fase' => EstruturaOferta::FASE_SIMPLES, 'nome' => "Vaso Alfa {$cor}"]);
    }
}
