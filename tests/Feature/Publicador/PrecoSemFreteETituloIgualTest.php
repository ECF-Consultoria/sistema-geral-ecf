<?php

namespace Tests\Feature\Publicador;

use App\Models\PubValidacao;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Support\Publicador\Payload\Alvo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * Decisões do usuário de 10/10/2026 no caminho de verdade (editor e conferência, com o ML simulado):
 *  - V-SAL-08: preço da Precificação do Portal calculado SEM frete bloqueia (o digitado passa);
 *  - V-TIT-04: o mesmo título no Clássico e no Premium bloqueia (o ML barra dois anúncios iguais);
 *  - o estado do editor leva o `portal` de cada variante (anunciado, mínimo, sem frete) para a tela
 *    mostrar a promoção automática, e se a conta cria a promoção sozinha.
 */
class PrecoSemFreteETituloIgualTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->fakeMl();
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->efetivos = self::PORTAL;
        // O preço digitado do cenário (150) sai: vale o do Portal.
        $this->r->variantes()->first()->precos()->update(['preco' => null]);
        $this->r = $this->r->fresh();
    }

    private function conferir(): PubValidacao
    {
        return app(ConferenciaService::class)->conferir($this->r->fresh());
    }

    private static function regras(PubValidacao $v): array
    {
        return array_column((array) $v->issues, 'regra');
    }

    public function test_preco_do_portal_sem_frete_bloqueia_a_conferencia_sem_chamar_o_validate(): void
    {
        $this->efetivos = [...self::PORTAL, 'sem_frete' => ['gold_special' => true, 'gold_pro' => false]];

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $p = collect($v->issues)->firstWhere('regra', 'V-SAL-08');
        $this->assertNotNull($p, json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['etapa' => 'E10', 'alvo' => 'gold_special', 'variante' => '__single__', 'campo' => 'preco'], $p['alvo']);
        $this->assertSame(0, $this->chamadas('/items/validate'), 'bloqueio local: o ML nem é consultado');

        // Com o frete na Precificação, passa.
        $this->efetivos = self::PORTAL;
        $this->assertNotContains('V-SAL-08', self::regras($this->conferir()));
    }

    public function test_preco_digitado_passa_mesmo_com_o_portal_sem_frete(): void
    {
        $this->efetivos = [...self::PORTAL, 'sem_frete' => ['gold_special' => true, 'gold_pro' => true]];
        $this->variante(['SELLER_SKU' => ['value_name' => 'CAD-01'], 'GTIN' => ['value_name' => '7896553367645']], preco: 199.90);

        $v = $this->conferir();

        $this->assertNotContains('V-SAL-08', self::regras($v));
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
    }

    public function test_titulo_igual_no_classico_e_no_premium_bloqueia_a_conferencia(): void
    {
        $this->repo->gravarAlvos($this->r, [new Alvo('gold_special', 'Cadeira Escritório Executiva ECF Giratória'), new Alvo('gold_pro', 'cadeiras escritorio executivas ecf giratorias')]);

        $v = $this->conferir();

        $this->assertSame(ConferenciaService::BLOQUEADO, $v->resultado);
        $p = collect($v->issues)->firstWhere('regra', 'V-TIT-04');
        $this->assertNotNull($p, json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['etapa' => 'E7', 'alvo' => 'gold_pro'], $p['alvo']);
        $this->assertStringContainsString('o Mercado Livre não aceita dois anúncios com o mesmo título', $p['mensagem']);
        $this->assertSame(0, $this->chamadas('/items/validate'));
    }

    public function test_o_editor_mostra_o_portal_da_variante_a_promocao_automatica_e_o_bloqueio(): void
    {
        $this->efetivos = [...self::PORTAL, 'sem_frete' => ['gold_special' => true, 'gold_pro' => false]];

        $e = app(EditorRascunhoService::class)->estado($this->r);

        $this->assertSame(['anunciado' => 207.19, 'minimo' => 172.66, 'sem_frete' => true], $e['variantes'][0]['portal']['gold_special']);
        $this->assertSame(207.19, $e['variantes'][0]['precos_efetivos']['gold_special']);
        $this->assertSame(['automatica' => true, 'dias' => 14], $e['promocao_automatica']);
        $this->assertContains('V-SAL-08', array_column($e['problemas'], 'regra'));

        // Conta fora da lista das Alavancas: a tela avisa que a promoção é à mão.
        config(['publicador.alavancas.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $this->assertFalse(app(EditorRascunhoService::class)->estado($this->r)['promocao_automatica']['automatica']);
    }
}
