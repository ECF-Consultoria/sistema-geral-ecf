<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\ApoioEscritaHttp;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-11 (D-04): prévia assinada que não escreve; confirmar só com a assinatura certa e uma vez só. */
class PreviaEConfirmarTest extends TestCase
{
    use ApoioEscritaHttp;
    use CenarioAlavancas;
    use RefreshDatabase;

    // ═══ Prévia ═══

    public function test_previa_devolve_resumo_e_assinatura_sem_escrever_no_ml(): void
    {
        $this->cenarioDeal(1);

        $r = $this->previaDe('convite.inscrever', [$this->itemDeal(1, 85)]);

        $r->assertOk()->assertJsonPath('acao', 'convite.inscrever')->assertJsonPath('liberada', true)
            ->assertJsonPath('motivo', null)->assertJsonPath('regra', null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}\.\d+$/', (string) $r->json('assinatura'));
        $this->assertSame($this->ancora->chaveContaMl(), $r->json('resumo.conta.chave'));
        $this->assertSame('Produto MLB1', $r->json('resumo.itens.0.titulo'));
        $this->assertSame(100, $r->json('resumo.itens.0.preco_atual'));
        $this->assertEquals(85, $r->json('resumo.itens.0.preco_promocao'));
        $this->assertEquals(15, $r->json('resumo.itens.0.desconto_percentual'));
        // O bloco "quanto a loja recebe" vem junto, calculado pela análise.
        $this->assertTrue($r->json('resumo.itens.0.recebe.calculado'));
        $this->assertNotNull($r->json('resumo.itens.0.recebe.promocao'));
        $this->assertNull($r->json('resumo.itens.0.analise'), 'o pedido de análise não vaza para a tela');
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(0, PubAlavancaEscrita::count());
    }

    public function test_previa_de_conta_nao_liberada_mostra_tudo_mas_nao_assina(): void
    {
        $this->cenarioDeal(1, 'company', false);

        $r = $this->previaDe('convite.inscrever', [$this->itemDeal()]);

        $r->assertOk()->assertJsonPath('liberada', false)->assertJsonPath('regra', 'ALAV-LIB')->assertJsonPath('assinatura', null);
        $this->assertNotEmpty($r->json('motivo'));
        $this->assertSame('Produto MLB1', $r->json('resumo.itens.0.titulo'));
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_previa_de_atacado_so_faz_a_consulta_de_recomendacao_por_post(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items$#', fn () => Http::response([['code' => 200, 'body' => ['id' => 'MLB1', 'seller_id' => 1555596317, 'title' => 'Produto MLB1', 'price' => 100, 'status' => 'active', 'attributes' => []]]], 200));
        $this->responder('GET', '#^/items/MLB1/prices$#', self::fixtureAlavanca('doc/atacado/prices_com_faixas'));
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => []]);
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', self::fixtureAlavanca('doc/atacado/recommendations'));

        $r = $this->previaDe('atacado.gravar', [['item_id' => 'MLB1', 'faixas' => [['id' => null, 'percentual' => 5, 'quantidade_minima' => 3]]]]);

        $r->assertOk();
        $caminhos = array_map(fn ($c) => $c['caminho'], $this->escritasNoMl());
        $this->assertSame(['/prices-per-quantity/v1/recommendations'], array_values(array_unique($caminhos)));
    }

    public function test_previa_de_50_produtos_le_em_bloco_e_analisa_so_os_20_primeiros(): void
    {
        $this->cenarioDeal(50);
        $itens = array_map(fn ($i) => $this->itemDeal($i), range(1, 50));

        $t = microtime(true);
        $r = $this->previaDe('convite.inscrever', $itens);
        $segundos = round(microtime(true) - $t, 2);
        fwrite(STDERR, "\n[166-11] prévia de 50 produtos: {$segundos}s\n");

        $r->assertOk()->assertJsonPath('analise_limitada', true);
        $this->assertCount(50, $r->json('resumo.itens'));
        $this->assertContains('Análise dos 20 primeiros produtos; a escrita vale para todos.', $r->json('resumo.avisos'));
        $this->assertTrue($r->json('resumo.itens.0.recebe.calculado'));
        $this->assertTrue($r->json('resumo.itens.19.recebe.calculado'));
        $this->assertArrayNotHasKey('recebe', $r->json('resumo.itens.20'));

        // 50 produtos = 3 multigets (20+20+10); a análise reaproveita; a promoção é lida numa passada só.
        $this->assertLessThanOrEqual(3, count($this->chamadasAoMl('GET', '#^/items$#')));
        $this->assertCount(1, $this->chamadasAoMl('GET', '#^/seller-promotions/promotions/P-1/items$#'));
        $this->assertSame([], $this->chamadasAoMl('GET', '#^/seller-promotions/items/MLB\d+$#'), 'sem leitura por item');
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_previa_recusa_zero_e_mais_de_50_itens_e_item_invalido(): void
    {
        $this->cenarioDeal(1);

        $this->previaDe('convite.inscrever', [])->assertStatus(422);
        $r = $this->previaDe('convite.inscrever', array_map(fn ($i) => $this->itemDeal($i), range(1, 51)));
        $r->assertStatus(422)->assertJsonPath('regra', 'ALAV-LOTE');

        $this->previaDe('convite.inscrever', [$this->itemDeal(1), ['item_id' => 'xyz', 'promotion_type' => 'DEAL']])
            ->assertStatus(422)->assertJsonValidationErrors(['itens.1.item_id']);
        $this->previaDe('acao.inventada', [$this->itemDeal()])->assertStatus(422)->assertJsonPath('regra', 'ALAV-ACAO');
        $this->assertSame([], $this->escritasNoMl());
    }

    public function test_regra_violada_num_item_traz_contexto_e_prefixo_do_mlb(): void
    {
        $this->cenarioDeal(2);

        // Preço fora da faixa do convite (70–95) no 2º item.
        $r = $this->previaDe('convite.inscrever', [$this->itemDeal(1, 85), $this->itemDeal(2, 60)]);

        $r->assertStatus(422)->assertJsonPath('regra', 'ALAV-CONV-06')
            ->assertJsonPath('contexto.item', 1)->assertJsonPath('contexto.item_id', 'MLB2');
        $this->assertStringStartsWith('MLB2: ', $r->json('message'));

        // Com um item só a mensagem vem sem prefixo.
        $um = $this->previaDe('convite.inscrever', [$this->itemDeal(1, 60)]);
        $this->assertStringStartsNotWith('MLB1: ', $um->json('message'));
    }

    public function test_precos_sao_normalizados_antes_de_assinar(): void
    {
        $this->cenarioDeal(1);
        $previa = $this->previaDe('convite.inscrever', [$this->itemDeal(1, 85.123)])->assertOk();

        $this->confirmarCom('convite.inscrever', [$this->itemDeal(1, 85.12)], $previa->json('assinatura'))->assertOk();

        $this->assertEquals(85.12, $this->escritasNoMl()[0]['corpo']['deal_price']);
    }

    // ═══ Confirmar: assinatura ═══

    public function test_sem_assinatura_e_422_e_nada_sai(): void
    {
        $this->cenarioDeal(1);

        $this->confirmarCom('convite.inscrever', [$this->itemDeal()], null)
            ->assertStatus(422)->assertJsonPath('regra', 'ALAV-ASSIN')
            ->assertJsonPath('message', 'A conferência expirou ou mudou. Refaça a conferência antes de confirmar.');
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(0, PubAlavancaEscrita::count());
    }

    public function test_assinatura_de_outro_usuario_outra_conta_expirada_ou_preco_trocado_e_422(): void
    {
        $this->cenarioDeal(1);
        $itens = [$this->itemDeal(1, 85)];
        $outro = User::factory()->create(['role' => 'admin']);

        $assinaturas = [
            'outro usuário' => $this->assinaturaForjada('convite.inscrever', $itens, null, $outro->id),
            'outra conta' => $this->assinaturaForjada('convite.inscrever', $itens, 'empresa-99999'),
            'outra ação' => $this->assinaturaForjada('convite.alterar', $itens),
        ];
        foreach ($assinaturas as $rotulo => $assinatura) {
            $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertStatus(422)->assertJsonPath('regra', 'ALAV-ASSIN');
        }

        // Preço trocado depois da prévia.
        $previa = $this->previaDe('convite.inscrever', $itens)->assertOk();
        $this->confirmarCom('convite.inscrever', [$this->itemDeal(1, 80)], $previa->json('assinatura'))
            ->assertStatus(422)->assertJsonPath('regra', 'ALAV-ASSIN');

        // Expirada (10 minutos de validade).
        $this->travel(11)->minutes();
        $this->confirmarCom('convite.inscrever', $itens, $previa->json('assinatura'))->assertStatus(422)->assertJsonPath('regra', 'ALAV-ASSIN');

        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(0, PubAlavancaEscrita::count());
    }

    public function test_confirmar_com_assinatura_valida_escreve_uma_vez_e_devolve_a_escrita(): void
    {
        $this->cenarioDeal(1);
        $itens = [$this->itemDeal(1, 85)];
        $previa = $this->previaDe('convite.inscrever', $itens)->assertOk();

        $r = $this->confirmarCom('convite.inscrever', $itens, $previa->json('assinatura'));

        $r->assertOk()->assertJsonPath('escrita.resultado', 'OK')->assertJsonPath('escrita.item_id', 'MLB1')
            ->assertJsonPath('escrita.acao', 'convite.inscrever')->assertJsonPath('escrita.alavanca', 'promocao');
        $this->assertNotNull($r->json('escrita.id'));
        $this->assertCount(1, $this->escritasNoMl());
        $this->assertSame('/seller-promotions/items/MLB1', $this->escritasNoMl()[0]['caminho']);
        $this->assertSame(1, PubAlavancaEscrita::where('resultado', 'OK')->count());
    }

    public function test_o_mesmo_corpo_enviado_de_novo_e_409_sem_escrever_nem_criar_linha(): void
    {
        $this->cenarioDeal(1);
        $itens = [$this->itemDeal(1, 85)];
        $assinatura = $this->previaDe('convite.inscrever', $itens)->json('assinatura');

        $this->confirmarCom('convite.inscrever', $itens, $assinatura)->assertOk();
        $this->confirmarCom('convite.inscrever', $itens, $assinatura)
            ->assertStatus(409)->assertJsonPath('regra', 'ALAV-ASSIN-USADA')->assertJsonPath('message', 'Esta confirmação já foi usada.');

        $this->assertCount(1, $this->escritasNoMl());
        $this->assertSame(1, PubAlavancaEscrita::count());
    }

    // ═══ Confirmar: resultados do ML ═══

    public function test_erro_do_ml_volta_200_com_o_resultado_e_a_mensagem(): void
    {
        $this->cenarioDeal(1);
        $this->responder('POST', '#^/seller-promotions/items/MLB\d+$#', fn () => Http::response(['message' => 'invalid', 'error' => 'bad_request', 'status' => 400], 400));
        $itens = [$this->itemDeal()];

        $r = $this->confirmarCom('convite.inscrever', $itens, $this->previaDe('convite.inscrever', $itens)->json('assinatura'));

        $r->assertOk()->assertJsonPath('escrita.resultado', 'ERRO')->assertJsonPath('escrita.http_status', 400);
        $this->assertNotEmpty($r->json('escrita.mensagem'));
    }

    public function test_incerto_do_ml_volta_200_e_nao_reenvia(): void
    {
        $this->cenarioDeal(1);
        $this->responder('POST', '#^/seller-promotions/items/MLB\d+$#', fn () => Http::response(['message' => 'oops'], 500));
        $itens = [$this->itemDeal()];

        $r = $this->confirmarCom('convite.inscrever', $itens, $this->previaDe('convite.inscrever', $itens)->json('assinatura'));

        $r->assertOk()->assertJsonPath('escrita.resultado', 'INCERTO')->assertJsonPath('escrita.http_status', 500);
        $this->assertCount(1, $this->escritasNoMl());
    }

    public function test_recusada_por_regra_local_e_422_com_a_escrita(): void
    {
        $this->cenarioDeal(1);
        // Assinatura forjada para um preço fora da faixa: a prévia real o barraria.
        $itens = [$this->itemDeal(1, 60)];

        $r = $this->confirmarCom('convite.inscrever', $itens, $this->assinaturaForjada('convite.inscrever', $itens));

        $r->assertStatus(422)->assertJsonPath('regra', 'ALAV-CONV-06')->assertJsonPath('escrita.resultado', 'RECUSADA');
        $this->assertSame([], $this->escritasNoMl());
        $this->assertSame(1, PubAlavancaEscrita::where('resultado', 'RECUSADA')->count());
    }
}
