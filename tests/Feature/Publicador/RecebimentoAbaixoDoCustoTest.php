<?php

namespace Tests\Feature\Publicador;

use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\EstruturaPrecificacao;
use App\Models\EstruturaPrecificacaoParametros;
use App\Models\PubProduto;
use App\Services\Portal\Estrutura\EstruturaPrecificacaoService;
use App\Services\Publicador\DadosEfetivosService;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * O card "Quanto você recebe" passa a contar a verdade (quick 261010-ptg).
 *
 * O caso que o usuário mediu em 10/10/2026 na Poltrona Beny: custo R$ 280, preço do Portal
 * 483,45 (Clássico) e 520,92 (Premium), recebimento 263,84 e 281,75 — prejuízo contra o custo,
 * porque a oferta não tem frete na Precificação do Portal. O preço NÃO está errado: o `minimo`
 * é o ponto de equilíbrio e o `anunciado` é `minimo × 1,2`. Errado estava a tela, que mostrava
 * só o recebimento, sem custo e sem dizer que o imposto de 19% embutido no preço AINDA é devido
 * (o Mercado Livre não o desconta).
 *
 * O Premium é a demonstração: parece sobrar R$ 1,75 e está R$ 97,22 no vermelho depois do
 * imposto que o próprio preço reserva.
 *
 * Tarifa e frete sempre por `Http::fake` — nenhuma chamada real ao Mercado Livre.
 */
class RecebimentoAbaixoDoCustoTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** Os preços da Precificação do Portal da Poltrona Beny (custo 280, sem frete). */
    private const PRECOS = ['gold_special' => 483.45, 'gold_pro' => 520.92];

    /** Tarifa do ML por tipo: o rateio é arbitrário, o que foi medido é o TOTAL com o frete. */
    private const TARIFA = ['gold_special' => 157.26, 'gold_pro' => 176.82];

    private const FRETE = 62.35;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();

        // Os dois tipos ligados, com títulos diferentes (o V-TIT-04 barra título igual).
        $this->repo->gravarAlvos($this->r, [
            new Alvo('gold_special', 'Poltrona Beny Decorativa Pés Madeira Veludo'),
            new Alvo('gold_pro', 'Poltrona Beny Decorativa Veludo Base Giratória'),
        ]);
        // As medidas do pacote fechado: sem elas o `simular` nem consulta o frete.
        $this->repo->gravarAtributos($this->r, [...self::ATRIBUTOS,
            'SELLER_PACKAGE_HEIGHT' => ['value_name' => '70 cm'], 'SELLER_PACKAGE_WIDTH' => ['value_name' => '70 cm'],
            'SELLER_PACKAGE_LENGTH' => ['value_name' => '80 cm'], 'SELLER_PACKAGE_WEIGHT' => ['value_name' => '12000 g'],
        ]);
        $this->r->update(['step_state' => ['conta' => ['sellerId' => '1555596317']]]);

        $this->efetivos = ['titulos' => ['gold_special' => null, 'gold_pro' => null], 'precos' => self::PRECOS,
            'promocoes' => ['gold_special' => 402.88, 'gold_pro' => 434.10],
            'sem_frete' => ['gold_special' => false, 'gold_pro' => false], 'mlbs' => []];
        $this->custos = ['custo' => 280.0, 'origem' => 'produto', 'imposto' => 19.0, 'por_variante' => []];

        // O preço digitado do cenário (150) sai: vale o do Portal.
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->r = $this->r->fresh();
    }

    /** @param ?float $frete nulo = o pacote não tem cobertura (o ML responde sem `list_cost`) */
    private function fakeDoMl(?float $frete = self::FRETE): void
    {
        $this->fakeMl([
            '*/oauth/token' => Http::response(['access_token' => 'app-token', 'expires_in' => 21600]),
            '*/sites/MLB/listing_prices*' => fn (Request $q) => Http::response([
                'listing_type_id' => $q->data()['listing_type_id'],
                'sale_fee_amount' => self::TARIFA[$q->data()['listing_type_id']],
            ]),
            '*/shipping_options/free*' => Http::response($frete === null
                ? ['coverage' => ['all_country' => []]]
                : ['coverage' => ['all_country' => ['list_cost' => $frete]]]),
        ]);
    }

    private function simular(): array
    {
        $this->fakeDoMl();

        return app(EditorRascunhoService::class)->simular($this->r->fresh());
    }

    /** Preço digitado na variante, nos dois tipos — byte a byte o mesmo da sugestão sem frete. */
    private function digitarOsMesmosPrecos(): void
    {
        $unica = $this->repo->snapshot($this->r->fresh())->variantes[0];
        $this->repo->gravarVariacao($this->r->fresh(), [], [$unica->comDados(['precos' => self::PRECOS])]);
        $this->r = $this->r->fresh();
    }

    // ═══ O caso canônico da Poltrona Beny ═══════════════════════════════════

    public function test_classico_mostra_custo_lucro_imposto_reservado_e_margem(): void
    {
        $s = $this->simular()['gold_special'];

        $this->assertSame(483.45, $s['preco']);
        $this->assertSame(263.84, $s['voce_recebe'], 'o recebimento que o usuário mediu em 10/10');
        $this->assertSame(280.0, $s['custo']);
        $this->assertSame('produto', $s['custo_origem']);
        $this->assertSame(19.0, $s['imposto_pct']);
        $this->assertSame(-16.16, $s['lucro'], 'antes do imposto: 263,84 − 280');
        $this->assertSame(91.86, $s['imposto_reservado'], '19% de 483,45 — o preço reserva, o ML não desconta');
        $this->assertSame(-108.02, $s['lucro_depois_do_imposto']);
        $this->assertSame(-22.34, $s['margem_pct']);
        $this->assertTrue($s['abaixo_do_custo']);
        $this->assertFalse($s['prejuizo_com_imposto'], 'o aviso de cima já cobre este tipo');
        $this->assertTrue($s['preco_do_portal']);
        $this->assertFalse($s['portal_sem_frete']);
    }

    /** O conserto D: o Premium PARECE sobrar R$ 1,75 e está R$ 97,22 no vermelho. */
    public function test_premium_que_parece_sobrar_um_e_setenta_e_cinco_e_prejuizo_depois_do_imposto(): void
    {
        $s = $this->simular()['gold_pro'];

        $this->assertSame(520.92, $s['preco']);
        $this->assertSame(281.75, $s['voce_recebe']);
        $this->assertSame(1.75, $s['lucro'], 'o número que a tela mostrava como se fosse lucro');
        $this->assertSame(98.97, $s['imposto_reservado']);
        $this->assertSame(-97.22, $s['lucro_depois_do_imposto']);
        $this->assertSame(-18.66, $s['margem_pct']);
        $this->assertFalse($s['abaixo_do_custo'], 'recebe 281,75 e o custo é 280: passa do custo');
        $this->assertTrue($s['prejuizo_com_imposto'], 'mas o imposto reservado leva ao vermelho');
    }

    // ═══ O aviso não depende de procedência (conserto C) ════════════════════

    public function test_preco_digitado_igual_ao_do_portal_tambem_avisa_abaixo_do_custo(): void
    {
        $this->digitarOsMesmosPrecos();

        $s = $this->simular();

        $this->assertFalse($s['gold_special']['preco_do_portal'], 'o preço é da pessoa, não do Portal');
        $this->assertFalse($s['gold_pro']['preco_do_portal']);
        $this->assertTrue($s['gold_special']['abaixo_do_custo'], 'o aviso é sobre o número, não sobre a origem dele');
        $this->assertTrue($s['gold_pro']['prejuizo_com_imposto']);
        $this->assertSame(-108.02, $s['gold_special']['lucro_depois_do_imposto']);
    }

    public function test_preco_do_portal_calculado_sem_frete_vem_marcado(): void
    {
        $this->efetivos = [...$this->efetivos, 'sem_frete' => ['gold_special' => true, 'gold_pro' => false]];

        $s = $this->simular();

        $this->assertTrue($s['gold_special']['portal_sem_frete']);
        $this->assertFalse($s['gold_pro']['portal_sem_frete']);

        // A marca é fato DA OFERTA, não do preço exibido: segue de pé com o preço digitado. É o mesmo
        // fato que o V-SAL-08 e o `PrecoDaPromocao` leem — se ela sumisse aqui, o card diria "sem
        // desconto automático porque o preço do Portal foi calculado sem frete" sem mostrar o aviso.
        $this->digitarOsMesmosPrecos();
        $this->assertTrue($this->simular()['gold_special']['portal_sem_frete']);
    }

    // ═══ O desconto automático de 14 dias (acréscimo pedido em 10/10) ══════

    /** O número vem do `PrecoDaPromocao` — a MESMA conta do gatilho pós-publicação e do espelho em JS. */
    public function test_o_card_diz_a_que_preco_o_desconto_automatico_leva_o_anuncio(): void
    {
        $s = $this->simular();

        $this->assertTrue($s['gold_special']['promocao']['calculavel']);
        $this->assertSame(402.88, $s['gold_special']['promocao']['preco'], 'o mínimo do Portal, sem conta nova no meio');
        $this->assertSame(16.67, $s['gold_special']['promocao']['percentual']);
        $this->assertSame(14, $s['gold_special']['promocao']['dias']);
        $this->assertNull($s['gold_special']['promocao']['motivo_texto']);
        $this->assertSame(434.10, $s['gold_pro']['promocao']['preco']);
        $this->assertSame(16.67, $s['gold_pro']['promocao']['percentual']);
    }

    /** O caso da Poltrona Beny: sem frete no Portal NÃO há desconto automático — e a tela diz por quê. */
    public function test_sem_frete_no_portal_nao_ha_desconto_automatico_e_o_motivo_vem_em_portugues(): void
    {
        $this->efetivos = [...$this->efetivos, 'sem_frete' => ['gold_special' => true, 'gold_pro' => true]];

        $p = $this->simular()['gold_special']['promocao'];

        $this->assertFalse($p['calculavel']);
        $this->assertSame('sem_frete', $p['motivo']);
        $this->assertSame('o preço do Portal foi calculado sem frete', $p['motivo_texto']);
        $this->assertNull($p['preco']);
        $this->assertNull($p['percentual']);
    }

    public function test_preco_ja_no_minimo_do_portal_nao_tem_desconto_automatico(): void
    {
        $unica = $this->repo->snapshot($this->r->fresh())->variantes[0];
        $this->repo->gravarVariacao($this->r->fresh(), [], [$unica->comDados(['precos' => ['gold_special' => 402.88, 'gold_pro' => 434.10]])]);
        $this->r = $this->r->fresh();

        $p = $this->simular()['gold_special']['promocao'];

        $this->assertFalse($p['calculavel']);
        $this->assertSame('no_minimo', $p['motivo']);
        $this->assertSame('o preço publicado já está no preço mínimo do Portal (ou abaixo)', $p['motivo_texto']);
    }

    public function test_sem_preco_de_promocao_no_portal_o_motivo_e_sem_portal(): void
    {
        $this->efetivos = [...$this->efetivos, 'promocoes' => ['gold_special' => null, 'gold_pro' => null]];

        $p = $this->simular()['gold_special']['promocao'];

        $this->assertFalse($p['calculavel']);
        $this->assertSame('sem_portal', $p['motivo']);
        $this->assertSame('a Precificação do Portal não tem preço de promoção para este produto', $p['motivo_texto']);
    }

    // ═══ Os vazios: sem custo não se inventa prejuízo ═══════════════════════

    public function test_sem_custo_no_portal_nao_ha_lucro_nem_margem_nem_aviso(): void
    {
        $this->custos = ['custo' => null, 'origem' => null, 'imposto' => 19.0, 'por_variante' => []];

        $s = $this->simular()['gold_special'];

        $this->assertNull($s['custo']);
        $this->assertNull($s['custo_origem']);
        $this->assertNull($s['lucro']);
        $this->assertNull($s['lucro_depois_do_imposto']);
        $this->assertNull($s['margem_pct']);
        $this->assertSame(91.86, $s['imposto_reservado'], 'o imposto não depende do custo');
        $this->assertFalse($s['abaixo_do_custo']);
        $this->assertFalse($s['prejuizo_com_imposto']);
    }

    public function test_sem_imposto_configurado_os_campos_do_imposto_ficam_nulos(): void
    {
        $this->custos = ['custo' => 280.0, 'origem' => 'digitado', 'imposto' => null, 'por_variante' => []];

        $s = $this->simular()['gold_special'];

        $this->assertNull($s['imposto_pct']);
        $this->assertNull($s['imposto_reservado']);
        $this->assertNull($s['lucro_depois_do_imposto']);
        $this->assertNull($s['margem_pct']);
        $this->assertSame(-16.16, $s['lucro'], 'o lucro antes do imposto segue medível');
        $this->assertTrue($s['abaixo_do_custo']);
    }

    public function test_pacote_sem_cobertura_deixa_o_frete_desconhecido_sem_perder_o_lucro(): void
    {
        $this->fakeDoMl(null);

        $s = app(EditorRascunhoService::class)->simular($this->r->fresh())['gold_special'];

        $this->assertFalse($s['frete_conhecido']);
        $this->assertNull($s['frete']);
        // Sem o frete o recebimento fica OTIMISTA (483,45 − 157,26) — e ainda assim abaixo do custo.
        $this->assertSame(326.19, $s['voce_recebe']);
        $this->assertSame(46.19, $s['lucro']);
        $this->assertSame(-45.67, $s['lucro_depois_do_imposto'], 'otimista e já no vermelho depois do imposto');
        $this->assertFalse($s['abaixo_do_custo']);
        $this->assertTrue($s['prejuizo_com_imposto']);
    }

    // ═══ O contrato antigo não se move ═════════════════════════════════════

    public function test_as_seis_chaves_do_simulador_continuam_na_ordem_com_os_mesmos_valores(): void
    {
        $s = $this->simular()['gold_special'];

        $this->assertSame(['preco', 'tarifa', 'frete', 'voce_recebe', 'percentual', 'frete_conhecido'],
            array_slice(array_keys($s), 0, 6), 'as 6 chaves do SimuladorVoceRecebe abrem a saída, na ordem');
        $this->assertSame(['preco' => 483.45, 'tarifa' => 157.26, 'frete' => 62.35, 'voce_recebe' => 263.84,
            'percentual' => 54.57, 'frete_conhecido' => true], array_slice($s, 0, 6));
    }

    // ═══ O custo lido da Precificação do Portal de verdade ═════════════════

    /**
     * Aqui o `DadosEfetivosService` é o REAL (o cenário mocka o do container), para provar que
     * `custosDoProduto` lê a Precificação do Portal — e que a exceção da oferta vence o parâmetro
     * da empresa, a MESMA precedência da visão rápida do lote.
     */
    public function test_custos_do_produto_le_a_precificacao_do_portal_com_a_excecao_da_oferta(): void
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'POL-BENY', 'fase' => 'simples', 'nome' => 'Poltrona Beny']);
        EstruturaPrecificacao::create(['oferta_id' => $oferta->id, 'custo' => 280]);
        $produto = PubProduto::daOferta($oferta);
        $real = new DadosEfetivosService(app(EstruturaPrecificacaoService::class));

        $c = $real->custosDoProduto($produto);

        $this->assertSame(280.0, $c['custo']);
        $this->assertSame('digitado', $c['origem']);
        $this->assertSame(EstruturaPrecificacaoParametros::PADROES['imposto'], $c['imposto'], 'sem linha de parâmetros, o padrão da empresa');
        $this->assertSame([], $c['por_variante']);

        // A exceção de imposto da oferta vence o parâmetro da empresa.
        EstruturaPrecificacao::where('oferta_id', $oferta->id)->update(['imposto' => 6]);
        $this->assertSame(6.0, $real->custosDoProduto($produto->fresh())['imposto']);
    }

    public function test_custos_do_produto_sem_oferta_nao_inventa_custo(): void
    {
        $empresa = Company::factory()->create();
        $produto = PubProduto::create(['company_id' => $empresa->id, 'sku' => 'SOLTO-1', 'nome' => 'Solto', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        $real = new DadosEfetivosService(app(EstruturaPrecificacaoService::class));

        $c = $real->custosDoProduto($produto);

        $this->assertNull($c['custo']);
        $this->assertNull($c['origem']);
        $this->assertSame(EstruturaPrecificacaoParametros::PADROES['imposto'], $c['imposto'], 'o imposto é da empresa, não da oferta');
        $this->assertSame([], $c['por_variante']);
    }

    /** `daProduto()` e `daOferta()` NÃO ganham chave: o `VisaoRapidaDoLoteTest` compara o array inteiro. */
    public function test_o_custo_nao_entra_nos_efetivos_comparados_com_o_lote(): void
    {
        $empresa = Company::factory()->create();
        $oferta = EstruturaOferta::create(['company_id' => $empresa->id, 'sku' => 'POL-BENY', 'fase' => 'simples', 'nome' => 'Poltrona Beny']);
        EstruturaPrecificacao::create(['oferta_id' => $oferta->id, 'custo' => 280]);
        $real = new DadosEfetivosService(app(EstruturaPrecificacaoService::class));

        $e = $real->daProduto(PubProduto::daOferta($oferta));

        $this->assertSame(['titulos', 'precos', 'promocoes', 'sem_frete', 'mlbs'], array_keys($e));
    }
}
