<?php

namespace Tests\Feature\Trilha;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaProdutoGeracao;
use App\Models\EstruturaSugestaoDescartada;
use App\Models\TrilhaMapeamento;
use App\Models\TrilhaOnda;
use App\Models\User;
use App\Services\Trilha\TrilhaDaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * O Mapeamento dentro da trilha (2ª entrega, 02/11/2026), pelas rotas da equipe: o servidor expande a escolha por
 * cor (Combo N por cor, Kit/Combit por par de cores em paralelo, "Juntar todos" pela âncora), com as siglas do
 * sistema e a régua do "Montar kit" do Portal; o "Concluir" grava tudo ou nada, marca o produto como mapeado e
 * descarta as sugestões que sobraram dele.
 */
class MapeamentoNaTrilhaTest extends TestCase
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
        $this->dev = User::factory()->create(['role' => 'admin', 'active' => true, 'name' => 'Rafael']);
        $this->dev->forceFill(['is_dev' => true])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_as_opcoes_trazem_combos_e_so_os_parceiros_fora_da_lista(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB', ['Azul', 'Verde']);
        $naLista = $this->produtoCompleto($this->empresa, 'Peça Gama', 'PG');
        $naLista['produto']->update(['titulo' => null]);

        $r = $this->actingAs($this->dev)->getJson($this->url($a, 'mapeamento'))->assertOk();

        $this->assertSame('VA', $r->json('produto.codigo'));
        $this->assertSame(['VA-1', 'VA-2'], array_column($r->json('produto.cores'), 'sku'));
        $this->assertSame([2, 3, 4, 6], array_column($r->json('combos'), 'quantidade'));
        $this->assertSame('nenhuma', $r->json('combos.0.existe'));
        $this->assertSame([(int) $b['produto']->id], array_column($r->json('parceiros'), 'produto_id'), 'o da Lista não entra');
        $this->assertTrue($r->json('parceiros.0.casa'));
        $this->assertSame('Sala de estar', $r->json('parceiros.0.ambiente'));
    }

    public function test_combo_por_cor_e_kit_e_combit_por_par_de_cores_com_as_siglas_do_sistema(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB', ['Azul', 'Verde']);

        $previa = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), [
            'combos' => [2], 'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 1]], 'juntar' => false,
        ])->assertOk();

        $linhas = collect($previa->json('linhas'));
        $this->assertSame('sempre', $linhas[0]['estado']);
        $this->assertSame("s-{$a['produto']->id}", $linhas[0]['chave']);
        $combo = $linhas->firstWhere('chave', "c-{$a['produto']->id}-2");
        $this->assertSame(['nova', ['VA-1-CB2', 'VA-2-CB2']], [$combo['estado'], $combo['skus']]);
        $kits = $linhas->where('tipo', 'Kit')->values();
        // Um kit por par de cores, em paralelo; o SKU na ordem do TIPO, como o "Montar kit" (Banqueta tem tipo, Vaso não).
        $this->assertSame([['KT-BB-1-VA-1'], ['KT-BB-2-VA-2']], $kits->pluck('skus')->all());
        $this->assertSame(3, $previa->json('novas'));
        $this->assertTrue($previa->json('pode_concluir'));
        $this->assertSame(0, EstruturaOferta::where('fase', '!=', 'simples')->count(), 'a prévia não grava');

        $combit = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), [
            'combos' => [], 'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 4]],
        ])->assertOk();
        $this->assertSame(['Combit', 'Combit'], collect($combit->json('linhas'))->where('chave', '!=', "s-{$a['produto']->id}")->pluck('tipo')->values()->all());
        $this->assertStringStartsWith('CT4-', $combit->json('linhas.1.skus.0'));
    }

    public function test_juntar_todos_faz_uma_oferta_por_cor_da_ancora(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB');
        $c = $this->produtoCompleto($this->empresa, 'Cesto Delta', 'CD');

        $previa = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), [
            'combos' => [], 'juntar' => true,
            'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 1], ['produto_id' => $c['produto']->id, 'quantidade' => 1]],
        ])->assertOk();

        $kits = collect($previa->json('linhas'))->where('tipo', 'Kit')->values();
        $this->assertCount(2, $kits, 'uma por cor da âncora (Vaso Alfa, 2 cores)');
        $this->assertSame(['KT-BB-VA-1-CD', 'KT-BB-VA-2-CD'], $kits->pluck('skus')->flatten()->all());

        $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), [
            'combos' => [], 'juntar' => true, 'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('juntar');
    }

    public function test_concluir_grava_marca_e_a_trilha_mostra_as_ofertas_sem_onda(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA', ['Azul', 'Verde']);
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', 'BB', ['Azul', 'Verde']);
        $escolha = ['combos' => [2], 'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 1]], 'juntar' => false];

        $r = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento'), $escolha)->assertOk();

        $this->assertSame('3 ofertas criadas.', $r->json('mensagem'));
        $this->assertSame(['VA-1-CB2', 'VA-2-CB2'], collect($r->json('criadas'))->firstWhere('chave', "c-{$a['produto']->id}-2")['skus']);
        $this->assertSame(4, EstruturaOferta::where('fase', '!=', 'simples')->count(), '2 combos (um por cor) + 2 kits');
        $marca = TrilhaMapeamento::where('produto_id', $a['produto']->id)->first();
        $this->assertSame($this->dev->id, (int) $marca->concluido_por);
        $this->assertDatabaseHas('activity_log', ['description' => 'Mapeamento de Vaso Alfa concluído na trilha: 3 oferta(s) criada(s)']);

        $trilha = app(TrilhaDaEmpresa::class)->montar($this->empresa->fresh());
        $this->assertNull($this->produtoNaTrilha($trilha, $a['produto']->id)['etapa'], 'mapeado');
        $this->assertSame('mapa', $this->produtoNaTrilha($trilha, $b['produto']->id)['etapa'], 'o parceiro segue com o mapeamento dele');
        foreach (["s-{$a['produto']->id}", "c-{$a['produto']->id}-2"] as $chave) {
            $this->assertSame('Sem onda · falta escolher a data', $this->itemDaTrilha($trilha, $chave)['status'], $chave);
        }

        // De novo, a mesma escolha: tudo "já existe" e nada nasce.
        $previa = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), $escolha)->assertOk();
        $this->assertSame(0, $previa->json('novas'));
        $this->assertSame(['ja_existe', 'ja_existe', 'ja_existe'], collect($previa->json('linhas'))->skip(1)->pluck('estado')->values()->all());
        $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento'), $escolha)->assertOk();
        $this->assertSame(4, EstruturaOferta::where('fase', '!=', 'simples')->count());
    }

    public function test_concluir_descarta_as_sugestoes_que_sobraram_do_produto(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        // O sistema sugere o Combo 2 e o Combo 3 deste produto.
        EstruturaProdutoGeracao::create(['produto_id' => $a['produto']->id, 'company_id' => $this->empresa->id, 'tipo_id' => null, 'qtd_combo' => '2,3', 'qtd_combit' => null]);

        $opcoes = $this->actingAs($this->dev)->getJson($this->url($a, 'mapeamento'))->assertOk();
        $this->assertTrue(collect($opcoes->json('combos'))->firstWhere('quantidade', 3)['sugerido']);

        $r = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento'), ['combos' => [2], 'parceiros' => []])->assertOk();

        $this->assertSame(1, $r->json('descartadas'), 'o Combo 3 não escolhido vai para Descartadas');
        $this->assertSame(1, EstruturaSugestaoDescartada::where('company_id', $this->empresa->id)->count());
    }

    public function test_sku_acima_do_limite_bloqueia_e_nada_e_gravado(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', str_repeat('A', 70));
        $b = $this->produtoCompleto($this->empresa, 'Banqueta Beta', str_repeat('B', 70));
        $escolha = ['combos' => [2], 'parceiros' => [['produto_id' => $b['produto']->id, 'quantidade' => 1]]];

        $previa = $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), $escolha)->assertOk();
        $this->assertFalse($previa->json('pode_concluir'));
        $this->assertStringContainsString('SKU com', (string) $previa->json('mensagem'));

        $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento'), $escolha)->assertStatus(422);
        $this->assertSame(0, EstruturaOferta::where('fase', '!=', 'simples')->count(), 'nem o combo, que cabia, foi gravado');
        $this->assertSame(0, TrilhaMapeamento::count());
    }

    public function test_produto_na_lista_parceiro_de_fora_e_quem_nao_pode(): void
    {
        $a = $this->produtoCompleto($this->empresa, 'Vaso Alfa', 'VA');
        $naLista = $this->produtoCompleto($this->empresa, 'Peça Gama', 'PG');
        $naLista['produto']->update(['descricao' => '']);
        $outraLoja = $this->empresaDaTrilha();
        $deFora = $this->produtoCompleto($outraLoja, 'Vaso Fora', 'VX');

        $this->actingAs($this->dev)->getJson($this->url($naLista, 'mapeamento'))->assertStatus(422);
        $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), ['combos' => [], 'parceiros' => [['produto_id' => $naLista['produto']->id, 'quantidade' => 1]]])
            ->assertStatus(422);
        $this->actingAs($this->dev)->postJson($this->url($a, 'mapeamento/previa'), ['combos' => [], 'parceiros' => [['produto_id' => $deFora['produto']->id, 'quantidade' => 1]]])
            ->assertStatus(422);
        // O produto de outra loja pelo endereço desta: 404.
        $this->actingAs($this->dev)->getJson("/trilha/{$this->empresa->id}/produtos/{$deFora['produto']->id}/mapeamento")->assertNotFound();

        $semCarteira = User::factory()->create(['role' => 'consultor', 'active' => true]);
        $semCarteira->forceFill(['is_dev' => true])->save();
        $this->actingAs($semCarteira->refresh())->getJson($this->url($a, 'mapeamento'))->assertForbidden();

        $soLeitura = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($soLeitura, 'Vaso Leitura', 'VL');
        $this->actingAs($this->dev)->getJson("/trilha/{$soLeitura->id}/produtos/{$p['produto']->id}/mapeamento")->assertNotFound();
    }

    private function url(array $p, string $resto): string
    {
        return "/trilha/{$p['produto']->company_id}/produtos/{$p['produto']->id}/{$resto}";
    }
}
