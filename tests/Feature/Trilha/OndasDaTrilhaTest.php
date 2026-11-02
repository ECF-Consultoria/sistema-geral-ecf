<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O Planejamento dentro da trilha (2ª entrega, 02/11/2026), pelas rotas da equipe: criar, editar e apagar onda; pôr,
 * mover e tirar oferta — com as regras da data, do Combo × Simples e de quem pode escrever. A porta do cliente não
 * ganha escrita nenhuma.
 */
class OndasDaTrilhaTest extends TestCase
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

    // ═══ Onda ═══════════════════════════════════════════════════════════════

    public function test_criar_onda_com_nome_e_data_e_as_recusas(): void
    {
        $this->postJson($this->url('ondas'), ['nome' => 'Onda 1', 'data' => '2026-11-10'])
            ->assertCreated()
            ->assertJsonPath('onda.nome', 'Onda 1')
            ->assertJsonPath('onda.data', '2026-11-10')
            ->assertJsonPath('onda.comecou', false)
            ->assertJsonPath('mensagem', 'Onda 1 criada: começa em 10/11.');
        $this->assertSame('2026-11-10', TrilhaOnda::first()->dia());
        $this->assertSame($this->dev->id, (int) TrilhaOnda::first()->criada_por);

        $this->postJson($this->url('ondas'), ['nome' => 'Velha', 'data' => '2026-11-04'])->assertStatus(422)->assertJsonValidationErrors('data');
        $this->postJson($this->url('ondas'), ['nome' => 'Longe', 'data' => '2027-11-06'])->assertStatus(422)->assertJsonValidationErrors('data');
        $this->postJson($this->url('ondas'), ['nome' => '   ', 'data' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('nome');
        $this->postJson($this->url('ondas'), ['nome' => 'Torta', 'data' => '10/11/2026'])->assertStatus(422)->assertJsonValidationErrors('data');
        $this->assertSame(1, TrilhaOnda::count());
    }

    public function test_nome_de_onda_repetido_na_loja_e_recusado(): void
    {
        $this->postJson($this->url('ondas'), ['nome' => 'Onda Kits', 'data' => '2026-11-10'])->assertCreated();
        // O duplo clique em "Criar onda" (ou a sugestão repetida) não cria a segunda igual — sem caixa e sem acento.
        $this->postJson($this->url('ondas'), ['nome' => 'onda kits', 'data' => '2026-11-11'])
            ->assertStatus(422)->assertJsonPath('errors.nome.0', 'Já existe uma onda com esse nome nesta loja.');
        $outra = $this->onda($this->empresa, '2026-11-12', 'Onda Simples');
        $this->putJson($this->url("ondas/{$outra->id}"), ['nome' => 'ONDA KITS'])->assertStatus(422);
        // Renomear para o próprio nome vale; e outra loja pode usar o mesmo nome.
        $this->putJson($this->url("ondas/{$outra->id}"), ['nome' => 'Onda Simples'])->assertOk();
        $this->onda($this->empresaDaTrilha(), '2026-11-10', 'Onda Kits');
        $this->assertSame(3, TrilhaOnda::count());
    }

    public function test_onda_de_hoje_comeca_na_hora(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $onda = $this->onda($this->empresa, '2026-11-08');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}");

        // Mudar a data para hoje é o "começar agora": a soltura roda no pedido.
        $this->putJson($this->url("ondas/{$onda->id}"), ['data' => '2026-11-05'])->assertOk();

        $this->assertNotNull($onda->fresh()->liberada_em);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $a['produto']->id);
    }

    public function test_a_data_e_o_apagar_so_valem_antes_de_a_onda_comecar(): void
    {
        $comecou = $this->onda($this->empresa, '2026-11-05', 'Onda Hoje');
        $agendada = $this->onda($this->empresa, '2026-11-09', 'Onda Futura');
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->itemNaOnda($agendada, "s-{$a['produto']->id}");

        $this->putJson($this->url("ondas/{$comecou->id}"), ['data' => '2026-11-12'])->assertStatus(422)->assertJsonValidationErrors('data');
        $this->putJson($this->url("ondas/{$comecou->id}"), ['nome' => 'Onda de hoje'])->assertOk();
        $this->assertSame('Onda de hoje', $comecou->fresh()->nome);
        $this->deleteJson($this->url("ondas/{$comecou->id}"))->assertStatus(422);

        $this->deleteJson($this->url("ondas/{$agendada->id}"))
            ->assertOk()
            ->assertJsonPath('mensagem', 'Onda Futura apagada. 1 oferta(s) voltaram para Sem onda.');
        $this->assertSame(0, TrilhaOndaItem::count(), 'a cascata tira os itens');
        $this->assertNotNull(TrilhaOnda::find($comecou->id));
    }

    // ═══ Pôr, mover e tirar ═════════════════════════════════════════════════

    public function test_por_mover_e_tirar_a_oferta_da_onda_agendada(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $onda1 = $this->onda($this->empresa, '2026-11-08', 'Onda 1');
        $onda2 = $this->onda($this->empresa, '2026-11-09', 'Onda 2');
        $s = "s-{$a['produto']->id}";

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $onda1->id])
            ->assertOk()->assertJsonPath('mensagem', 'Na Onda 1. O robô começa em 08/11.');
        $this->assertSame($onda1->id, (int) TrilhaOndaItem::first()->onda_id);

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $onda2->id])->assertOk();
        $this->assertSame($onda2->id, (int) TrilhaOndaItem::first()->onda_id);

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => null])
            ->assertOk()->assertJsonPath('mensagem', 'Tirada da onda: volta para Sem onda.');
        $this->assertSame(0, TrilhaOndaItem::count());
        Queue::assertNothingPushed();
    }

    public function test_por_numa_onda_que_ja_comecou_chama_o_robo_e_ela_nao_sai_mais(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $hoje = $this->onda($this->empresa, '2026-11-05', 'Onda Hoje');
        $outra = $this->onda($this->empresa, '2026-11-09', 'Onda Futura');
        $s = "s-{$a['produto']->id}";

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $hoje->id])
            ->assertOk()->assertJsonPath('mensagem', 'Na Onda Hoje. Ela já começou: o robô foi chamado.');
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 1);
        $this->assertNotNull(TrilhaOndaItem::first()->pedido_em);

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $outra->id])->assertStatus(422);
        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => null])->assertStatus(422);
    }

    public function test_o_combo_puxa_a_simples_e_nao_comeca_antes_dela(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $cedo = $this->onda($this->empresa, '2026-11-07', 'Onda 1');
        $tarde = $this->onda($this->empresa, '2026-11-09', 'Onda 2');
        $s = "s-{$a['produto']->id}";
        $c = "c-{$a['produto']->id}-2";

        // A Simples sem onda entra junto, com aviso.
        $this->putJson($this->url('planejamento'), ['itens' => [$c], 'onda_id' => $tarde->id])
            ->assertOk()
            ->assertJsonPath('avisos.0', 'A simples de Vaso Alfa entrou junto, na Onda 2: o combo nasce dela.');
        $this->assertEqualsCanonicalizing([$s, $c], TrilhaOndaItem::all()->map->chave()->all());

        // O combo não começa antes da Simples.
        $this->putJson($this->url('planejamento'), ['itens' => [$c], 'onda_id' => $cedo->id])->assertStatus(422)->assertJsonValidationErrors('itens');
        // A Simples não sai da onda nem vai para depois do combo.
        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => null])->assertStatus(422);
        $depois = $this->onda($this->empresa, '2026-11-12', 'Onda 3');
        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $depois->id])->assertStatus(422);
        // Adiantar a Simples vale.
        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $cedo->id])->assertOk();
    }

    public function test_editar_a_data_ou_apagar_a_onda_nao_fura_a_regra_do_combo_nem_trava_outro_produto(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB-1');
        $this->ofertaComposta($this->empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $onda1 = $this->onda($this->empresa, '2026-11-06', 'Onda 1');
        $onda2 = $this->onda($this->empresa, '2026-11-08', 'Onda 2');
        $this->putJson($this->url('planejamento'), ['itens' => ["s-{$a['produto']->id}"], 'onda_id' => $onda1->id])->assertOk();
        $this->putJson($this->url('planejamento'), ['itens' => ["c-{$a['produto']->id}-2"], 'onda_id' => $onda2->id])->assertOk();

        // A Simples não pode ir para depois do combo dela, nem sumir deixando o combo noutra onda.
        $this->putJson($this->url("ondas/{$onda1->id}"), ['data' => '2026-11-10'])->assertStatus(422)->assertJsonValidationErrors('itens');
        $this->assertSame('2026-11-06', $onda1->fresh()->dia());
        $this->deleteJson($this->url("ondas/{$onda1->id}"))->assertStatus(422);
        $this->assertNotNull(TrilhaOnda::find($onda1->id));
        // O combo também não pode ir para antes da Simples.
        $this->putJson($this->url("ondas/{$onda2->id}"), ['data' => '2026-11-05'])->assertStatus(422);
        // Mexer na data sem quebrar a regra vale.
        $this->putJson($this->url("ondas/{$onda2->id}"), ['data' => '2026-11-07'])->assertOk();

        // Um produto sem relação nenhuma entra em qualquer onda.
        $this->putJson($this->url('planejamento'), ['itens' => ["s-{$b['produto']->id}"], 'onda_id' => $onda2->id])->assertOk();

        // Apagar a onda do COMBO vale: ele volta para Sem onda.
        $this->deleteJson($this->url("ondas/{$onda2->id}"))->assertOk();
        $this->assertSame(["s-{$a['produto']->id}"], TrilhaOndaItem::all()->map->chave()->all());
    }

    public function test_entrar_ou_mudar_de_onda_recomeca_a_contagem_do_item(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $onda1 = $this->onda($this->empresa, '2026-11-08', 'Onda 1');
        $onda2 = $this->onda($this->empresa, '2026-11-09', 'Onda 2');
        $s = "s-{$a['produto']->id}";

        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $onda1->id])->assertOk();
        $linha = TrilhaOndaItem::first();
        $this->assertSame('2026-11-05 10:00', $linha->na_onda_desde->copy()->setTimezone(TrilhaOnda::FUSO)->format('Y-m-d H:i'));

        Carbon::setTestNow(Carbon::parse('2026-11-06 11:00:00', TrilhaOnda::FUSO));
        $linha->update(['pedido_em' => now(), 'repescado_em' => now()]);
        $this->putJson($this->url('planejamento'), ['itens' => [$s], 'onda_id' => $onda2->id])->assertOk();
        $linha->refresh();
        $this->assertSame('2026-11-06 11:00', $linha->na_onda_desde->copy()->setTimezone(TrilhaOnda::FUSO)->format('Y-m-d H:i'));
        $this->assertNull($linha->pedido_em);
        $this->assertNull($linha->repescado_em);
    }

    public function test_so_entra_em_onda_a_oferta_da_etapa_3_desta_loja(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA-1');
        $andou = $this->produtoCompleto($this->empresa, 'Vaso Andou', 'VD-1');
        $this->rascunhoDoPub($this->grupoNoPublicador($this->empresa, $andou), PubRascunho::VALIDATED);
        $semProduto = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'AVULSO-1', 'fase' => 'simples', 'nome' => 'Avulso']);
        $outraLoja = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outraLoja, 'Vaso Fora', 'VX-1');
        $ondaDeFora = $this->onda($outraLoja, '2026-11-08');
        $onda = $this->onda($this->empresa, '2026-11-08');

        $this->putJson($this->url('planejamento'), ['itens' => ["s-{$andou['produto']->id}"], 'onda_id' => $onda->id])->assertStatus(422);
        $this->putJson($this->url('planejamento'), ['itens' => ["o-{$semProduto->id}"], 'onda_id' => $onda->id])->assertStatus(422);
        $this->putJson($this->url('planejamento'), ['itens' => ["s-{$deFora['produto']->id}"], 'onda_id' => $onda->id])->assertStatus(422);
        $this->putJson($this->url('planejamento'), ['itens' => ["s-{$a['produto']->id}"], 'onda_id' => $ondaDeFora->id])->assertStatus(422);
        $this->putJson($this->url('planejamento'), ['itens' => ['x-1'], 'onda_id' => $onda->id])->assertStatus(422);
        $this->assertSame(0, TrilhaOndaItem::count());

        // A onda de outra loja pelo endereço desta: 404.
        $this->putJson($this->url("ondas/{$ondaDeFora->id}"), ['nome' => 'Invasão'])->assertNotFound();
        $this->deleteJson($this->url("ondas/{$ondaDeFora->id}"))->assertNotFound();
    }

    // ═══ Quem pode ══════════════════════════════════════════════════════════

    public function test_quem_nao_pode_escrever_no_portal_da_empresa_recebe_403_e_empresa_fora_da_chave_404(): void
    {
        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $this->actingAs($semCarteira->refresh())->postJson($this->urlDe($this->empresa, 'ondas'), ['nome' => 'X', 'data' => '2026-11-10'])->assertForbidden();

        // Na trilha, mas sem a chave de trabalho: a escrita não existe.
        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $this->actingAs($this->dev)->postJson($this->urlDe($soLeitura, 'ondas'), ['nome' => 'X', 'data' => '2026-11-10'])->assertNotFound();
        // Id de 19 dígitos: 404, nunca 500.
        $this->actingAs($this->dev)->postJson('/trilha/1234567890123456789/ondas', ['nome' => 'X', 'data' => '2026-11-10'])->assertNotFound();
        $this->assertSame(0, TrilhaOnda::count());
    }

    public function test_a_porta_do_cliente_nao_tem_rota_de_escrita(): void
    {
        $metodos = [];
        foreach (Route::getRoutes() as $rota) {
            if (str_starts_with((string) $rota->getName(), 'portal.auth.trilha')) {
                $metodos = [...$metodos, ...$rota->methods()];
            }
        }
        $this->assertNotSame([], $metodos);
        $this->assertSame([], array_values(array_diff(array_unique($metodos), ['GET', 'HEAD'])));

        // E cada escrita da equipe tem o seu prefixo de throttle.
        foreach (['trilha.ondas.criar', 'trilha.ondas.editar', 'trilha.ondas.excluir', 'trilha.planejamento'] as $nome) {
            $this->assertContains('throttle:30,1,trilha.ondas', Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
    }

    // ─── apoio ───

    private function url(string $caminho): string
    {
        $this->actingAs($this->dev);

        return $this->urlDe($this->empresa, $caminho);
    }

    private function urlDe(Company $empresa, string $caminho): string
    {
        return "/trilha/{$empresa->id}/{$caminho}";
    }
}
