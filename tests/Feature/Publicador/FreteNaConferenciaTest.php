<?php

namespace Tests\Feature\Publicador;

use App\Models\EstruturaPrecificacao;
use App\Models\PubValidacao;
use App\Models\User;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\FreteParaAPrecificacaoService;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * 11/10/2026 — a segunda das três conferências de frete, no caminho de verdade (conferência com o Mercado
 * Livre simulado): o que o ML cota AGORA contra o que a Precificação do Portal usou no preço.
 *
 * O usuário: "na hora de conferir no Mercado Livre, vai ver se o que foi conferido via API pelo publicador é
 * o mesmo que foi conferido no portal". Centavos passam; diferença grande pede para refazer o preço; é sempre
 * aviso, nunca trava. E o botão aprovado: levar o frete do Mercado Livre para a Precificação.
 */
class FreteNaConferenciaTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    private const PORTAL = [
        'titulos' => ['gold_special' => null, 'gold_pro' => null],
        'precos' => ['gold_special' => 207.19, 'gold_pro' => 223.26],
        'promocoes' => ['gold_special' => 172.66, 'gold_pro' => 186.05],
        'sem_frete' => ['gold_special' => false, 'gold_pro' => false],
        'mlbs' => [],
    ];

    private const PACOTE = [
        'SELLER_PACKAGE_HEIGHT' => ['value_name' => '60 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '50 cm'],
        'SELLER_PACKAGE_LENGTH' => ['value_name' => '40 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '9000 g'],
    ];

    /** O que o Mercado Livre responde na cotação; nulo = responde 500. */
    private ?float $freteDoMl = 20.00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl(['*/shipping_options/free*' => fn () => $this->freteDoMl === null
            ? Http::response(['message' => 'erro'], 500)
            : Http::response(['coverage' => ['all_country' => ['list_cost' => $this->freteDoMl, 'currency_id' => 'BRL', 'billable_weight' => 20000]]])]);
        $this->efetivos = self::PORTAL;
        $this->fretes = ['por_tipo' => [
            'gold_special' => ['valor' => 20.00, 'origem' => 'digitado', 'oferta_id' => $this->produto->oferta_id],
            'gold_pro' => ['valor' => 20.00, 'origem' => 'digitado', 'oferta_id' => $this->produto->oferta_id],
        ], 'por_variante' => []];
        $this->repo->gravarAtributos($this->r, [...self::ATRIBUTOS, ...self::PACOTE]);
        // O preço digitado do cenário (150) sai: vale o do Portal.
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->r = $this->r->fresh();
    }

    private function conferir(): PubValidacao
    {
        return app(ConferenciaService::class)->conferir($this->r->fresh());
    }

    private static function doFrete(PubValidacao $v): array
    {
        return array_values(array_filter((array) $v->issues, fn ($i) => $i['regra'] === 'V-FRT-01'));
    }

    private function cotacoes(): array
    {
        return array_map(fn ($par) => $par[0]->data(), Http::recorded(fn (Request $r) => str_contains($r->url(), '/shipping_options/free'))->values()->all());
    }

    public function test_frete_igual_nao_avisa_e_a_cotacao_usa_os_parametros_da_precificacao(): void
    {
        $this->freteDoMl = 20.45;   // 45 centavos de diferença

        $v = $this->conferir();

        $this->assertSame([], self::doFrete($v), json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $frete = $v->respostas_ml['frete'];
        $this->assertTrue($frete['aplicavel']);
        $this->assertSame('60x50x40,9000', $frete['dimensions'], 'o pacote do RASCUNHO, que é o que vai ao anúncio');
        $this->assertCount(1, $frete['linhas'], 'só o tipo ligado no rascunho (Clássico)');
        $linha = $frete['linhas'][0];
        $this->assertSame('igual', $linha['nivel']);
        // assertEquals: o JSON devolve 20.0 como 20.
        $this->assertEquals([20.0, 20.45, $this->produto->oferta_id], [$linha['portal'], $linha['ml'], $linha['oferta_id']]);
        $this->assertSame(20000, $linha['peso_faturado']);

        // Comparável com a do Portal: o preço da PROMOÇÃO (não o anunciado), a logística e o frete grátis pela faixa.
        $q = $this->cotacoes()[0];
        $this->assertEquals(172.66, $q['item_price']);
        $this->assertSame(['gold_special', 'me2', 'drop_off', 'true', '60x50x40,9000'], [$q['listing_type_id'], $q['mode'], $q['logistic_type'], $q['free_shipping'], $q['dimensions']]);
    }

    public function test_diferenca_acima_de_um_real_vira_aviso_e_a_conferencia_fica_com_avisos(): void
    {
        $this->freteDoMl = 26.85;   // R$ 6,85 a mais... e 25% do frete: pede para refazer.
        $v = $this->conferir();
        $this->assertSame(ConferenciaService::AVISOS, $v->resultado, 'aviso, nunca bloqueio');
        [$p] = self::doFrete($v);
        $this->assertSame('WARNING', $p['severidade']);
        $this->assertSame('L3', $p['camada']);
        $this->assertSame('Frete do Clássico: o Mercado Livre cobra hoje R$ 26,85 e a Precificação usou R$ 20,00 (digitado no Portal). Diferença de R$ 6,85. O preço foi calculado com frete menor que o real: refaça o preço.', $p['mensagem']);
        // O "Corrigir" acende o preço daquele tipo e daquela variação.
        $this->assertEquals(['etapa' => 'E10', 'campo' => 'preco', 'alvo' => 'gold_special', 'variante' => '__single__',
            'frete' => ['portal' => 20.0, 'ml' => 26.85, 'nivel' => 'reprecificar']], $p['alvo']);
    }

    public function test_diferenca_pequena_so_avisa_sem_pedir_para_refazer(): void
    {
        $this->fretes['por_tipo']['gold_special']['valor'] = 100.00;
        $this->fretes['por_tipo']['gold_special']['origem'] = 'tabela';
        $this->freteDoMl = 106.85;

        [$p] = self::doFrete($this->conferir());

        $this->assertSame('diferente', $p['alvo']['frete']['nivel']);
        $this->assertStringEndsWith('(estimado pela tabela). Diferença de R$ 6,85.', $p['mensagem']);
    }

    public function test_fora_do_mercado_envios_nao_ha_frete_para_conferir(): void
    {
        $this->r->update(['envio' => ['modo' => 'not_specified', 'frete_gratis' => false, 'retirada' => false]]);
        $this->freteDoMl = 240.90;

        $v = $this->conferir();

        $this->assertSame([], self::doFrete($v));
        $this->assertSame(['aplicavel' => false, 'motivo' => 'envio_not_specified'], $v->respostas_ml['frete']);
        $this->assertSame([], $this->cotacoes(), 'nem pergunta');
    }

    /** O caso da Poltrona Opala: o validate avisa que o anúncio perde o Mercado Envios; sai "a combinar". */
    public function test_anuncio_que_vai_perder_o_mercado_envios_nao_compara_frete(): void
    {
        $this->freteDoMl = 240.90;
        $this->validate = fn (array $corpo) => Http::response(['message' => 'Validation error', 'error' => 'validation_error', 'status' => 400, 'cause' => [
            ['cause_id' => 4057, 'code' => 'shipping.lost_me2_by_intersected_logistics', 'type' => 'warning',
                'message' => 'User/Catalog has not intersected me2 logistics', 'references' => ['catalog.shipping_preferences.modes']],
        ]], 400);

        $v = $this->conferir();

        $this->assertSame([], self::doFrete($v));
        $this->assertSame(['aplicavel' => false, 'motivo' => 'sem_mercado_envios'], $v->respostas_ml['frete']);
        $this->assertSame([], $this->cotacoes());
    }

    public function test_cotacao_que_falha_nao_derruba_a_conferencia_nem_inventa_aviso(): void
    {
        $this->freteDoMl = null;

        $v = $this->conferir();

        $this->assertNotSame(ConferenciaService::ERRO, $v->resultado);
        $this->assertSame([], self::doFrete($v));
        $this->assertSame([], $v->respostas_ml['frete']['linhas']);
    }

    public function test_sem_pacote_ou_sem_frete_na_precificacao_nao_pergunta_ao_mercado_livre(): void
    {
        // Precificação sem frete para este produto.
        $this->fretes = ['por_tipo' => ['gold_special' => ['valor' => null, 'origem' => null, 'oferta_id' => $this->produto->oferta_id]], 'por_variante' => []];
        $this->assertSame([], self::doFrete($this->conferir()));
        $this->assertSame([], $this->cotacoes());

        // Rascunho sem as medidas do pacote.
        $this->repo->gravarAtributos($this->r->fresh(), self::ATRIBUTOS);
        $this->assertSame(['aplicavel' => false, 'motivo' => 'sem_pacote'], $this->conferir()->respostas_ml['frete']);
    }

    // ─── O botão: levar o frete do Mercado Livre para a Precificação ───

    public function test_levar_o_frete_grava_o_do_mercado_livre_na_precificacao_e_pede_conferir_de_novo(): void
    {
        $oferta = $this->produto->oferta;
        EstruturaPrecificacao::create(['oferta_id' => $oferta->id, 'custo' => 100, 'frete_classico' => 20, 'frete_premium' => 25, 'imposto' => 9.5]);
        $this->freteDoMl = 26.85;
        $this->conferir();
        $revisao = $this->r->fresh()->revisao;

        $resumo = app(FreteParaAPrecificacaoService::class)->aplicar($this->r->fresh(), User::factory()->create(['role' => 'admin']));

        $this->assertSame(['aplicadas' => 1, 'ofertas' => ['CAD-01']], $resumo);
        $linha = EstruturaPrecificacao::where('oferta_id', $oferta->id)->first();
        $this->assertEquals(26.85, $linha->frete_classico, 'o frete do Mercado Livre virou o digitado do Clássico');
        // Só o frete que divergiu muda: custo, o outro tipo e a exceção ficam como estavam.
        $this->assertEquals([100, 25, 9.5], [(float) $linha->custo, (float) $linha->frete_premium, (float) $linha->imposto]);
        // O preço que vem do Portal mudou: a conferência anterior deixa de valer.
        $this->assertSame($revisao + 1, $this->r->fresh()->revisao);

        // De novo, sem conferir: não há o que levar (a conferência é de outra revisão).
        try {
            app(FreteParaAPrecificacaoService::class)->aplicar($this->r->fresh(), User::factory()->create(['role' => 'admin']));
            $this->fail('levou frete de uma conferência que não vale mais');
        } catch (RegraViolada $e) {
            $this->assertSame('FRT-01', $e->regra);
        }
    }

    public function test_frete_igual_nao_tem_o_que_levar_e_o_valor_nunca_vem_do_pedido(): void
    {
        $this->freteDoMl = 20.30;
        $this->conferir();

        $this->assertSame([], app(FreteParaAPrecificacaoService::class)->oQueLevar($this->r->fresh()));

        // Pela rota: o corpo do pedido não muda nada (o número vem do que está gravado), e sem o que levar é 422.
        $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('mlb.anuncios.publicador.frete-precificacao', ['produto' => $this->produto->id]), ['frete' => 999, 'oferta_id' => 1])
            ->assertStatus(422)
            ->assertJsonPath('regra', 'FRT-01');
        $this->assertSame(0, EstruturaPrecificacao::count());
    }

    public function test_pela_rota_o_frete_vai_para_a_precificacao_e_a_tela_recebe_o_estado_novo(): void
    {
        $this->freteDoMl = 35.00;
        $this->conferir();

        $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('mlb.anuncios.publicador.frete-precificacao', ['produto' => $this->produto->id]))
            ->assertOk()
            ->assertJsonPath('frete_levado.aplicadas', 1)
            ->assertJsonPath('frete_levado.ofertas', ['CAD-01'])
            ->assertJsonPath('conferencia.vale', false);

        $this->assertEquals(35.00, EstruturaPrecificacao::where('oferta_id', $this->produto->oferta_id)->value('frete_classico'));
    }
}
