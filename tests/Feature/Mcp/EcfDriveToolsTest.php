<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `alertas_estrategicos` e `painel_executivo` — leem a API do ECF Drive pelas
 * MESMAS chamadas da tela (e caem no mesmo cache).
 */
class EcfDriveToolsTest extends TestCase
{
    use ChamaMcp, RefreshDatabase;

    private const SIGNAL = [
        'id'         => 91,
        'eventType'  => 'seller.gmv_queda_mom',
        'custId'     => '1354156948',
        'severity'   => 'critical',
        'periodKey'  => '202609',
        'payload'    => ['gmv_atual' => 11135.78, 'gmv_anterior' => 47315.69, 'delta_pct' => -76.46],
        'detectedAt' => '2026-10-05T07:30:00Z',
        'ackAt'      => null,
    ];

    /**
     * A API respondendo normal. Fora do setUp de propósito: `Http::fake()`
     * ACUMULA stubs e o primeiro que casa vence — o teste de API fora do ar
     * precisa nascer sem estes.
     */
    private function apiNoAr(): void
    {
        Http::fake([
            '*/signals*' => fn (RequisicaoHttp $r) => Http::response(
                str_contains($r->url(), 'limit=1')
                    ? ['data' => [], 'total' => str_contains($r->url(), 'severity=critical') ? 4 : 0]
                    : ['data' => [self::SIGNAL], 'total' => 1, 'page' => 1, 'limit' => 25]
            ),
            '*/carteira/resumo*'    => Http::response(['mesAtual' => '202609', 'mesAnterior' => '202608', 'gmv' => ['atual' => 1000, 'anterior' => 900]]),
            '*/carteira/historico*' => Http::response(['data' => [['mes' => '202608', 'gmv' => 900], ['mes' => '202609', 'gmv' => 1000]]]),
            '*/carteira/breakdown*' => fn (RequisicaoHttp $r) => Http::response(['dimensao' => $r['dimensao'], 'distribuicao' => [['canal' => 'FULL', 'pct' => 35.4]]]),
        ]);
    }

    public function test_alertas_traz_a_empresa_pelo_cust_e_os_contadores_da_tela(): void
    {
        $this->apiNoAr();
        $this->empresaPerformance(['name' => 'Loja Caiu', 'adman_account_id' => '1354156948']);
        $consultor = User::factory()->create(['role' => 'consultor', 'active' => true]);

        $resposta = $this->ferramenta($consultor, 'alertas_estrategicos', ['criticidade' => 'critical']);

        $this->assertSame(1, $resposta['total']);
        $this->assertSame('Loja Caiu', $resposta['itens'][0]['empresa']['nome']);
        $this->assertSame('Queda de faturamento', $resposta['itens'][0]['tipo_rotulo']);
        $this->assertSame(['critical' => 4, 'warning' => 0, 'info' => 0], $resposta['resumo_nao_vistos']);

        // Mesmo filtro que a tela manda para a API: não vistos por padrão.
        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), '/signals')
            && str_contains($r->url(), 'severity=critical')
            && str_contains($r->url(), 'acked=0'));
    }

    public function test_alertas_por_nome_resolve_o_cust_ou_explica_a_ambiguidade(): void
    {
        $this->apiNoAr();
        $this->empresaPerformance(['name' => 'Casa Azul', 'adman_account_id' => '555']);
        $this->empresaPerformance(['name' => 'Casa Verde', 'adman_account_id' => '666']);
        $admin = $this->admin();

        $this->ferramenta($admin, 'alertas_estrategicos', ['empresa' => 'Azul']);
        Http::assertSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'cust_id=555'));

        $this->assertStringContainsString('Mais de uma empresa', $this->erroDaFerramenta($admin, 'alertas_estrategicos', ['empresa' => 'Casa']));
    }


    public function test_painel_executivo_so_admin_e_mesmas_chamadas_da_tela(): void
    {
        $this->apiNoAr();
        $this->assertNotContains('painel_executivo', $this->ferramentasVisiveis(User::factory()->create(['role' => 'consultor', 'active' => true])));

        $resposta = $this->ferramenta($this->admin(), 'painel_executivo');

        $this->assertSame('202609', $resposta['mes_mais_recente']);
        $this->assertSame(['programa', 'frete', 'cluster', 'localidade'], array_keys($resposta['divisoes']));
        $this->assertCount(2, $resposta['historico_mensal']);

        // Sem `mes`, a divisão vai SEM tim_month_id — a mesma chamada (e o mesmo
        // cache) da tela.
        Http::assertNotSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'tim_month_id'));
    }

    public function test_painel_executivo_por_mes_e_mes_inexistente(): void
    {
        $this->apiNoAr();
        $admin = $this->admin();

        $resposta = $this->ferramenta($admin, 'painel_executivo', ['mes' => '202608', 'divisao' => 'frete']);
        $this->assertSame(900, $resposta['mes_pedido']['gmv']);
        $this->assertSame(['frete'], array_keys($resposta['divisoes']));

        $this->assertStringContainsString('não tem dados do mês 202401', $this->erroDaFerramenta($admin, 'painel_executivo', ['mes' => '202401']));
        $this->assertStringContainsString('Mês inválido', $this->erroDaFerramenta($admin, 'painel_executivo', ['mes' => 'setembro']));
    }

    public function test_ecf_drive_fora_do_ar_vira_mensagem_legivel(): void
    {
        Http::fake(['*' => Http::response('erro', 503)]);

        $this->assertStringContainsString('indisponível', $this->erroDaFerramenta($this->admin(), 'painel_executivo'));
        $this->assertStringContainsString('indisponível', $this->erroDaFerramenta($this->admin(), 'alertas_estrategicos'));
    }
}
