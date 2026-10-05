<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaPrecificacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Fase 167-05: frete ME2 estimado pela tabela da ECF e cotado pela conta do cliente.
 *
 * Modos de falha que estes testes impedem: cotar por tecla/linha colada (limite
 * de lote e cache), gravar o frete na Precificação (D-19), vazar o token do
 * cliente, e escrever na conta do cliente (só GET).
 */
class FreteDoProdutoTest extends TestCase
{
    use GabaritoDaPlanilhaEstrutural;
    use RefreshDatabase;

    private function svc(): FreteMe2Service
    {
        return app(FreteMe2Service::class);
    }

    /** Item no contrato do serviço, montado pela mesma regra de logística da tela. */
    private function item(float $c, float $l, float $a, float $kg, ?float $custo): array
    {
        $av = LogisticaProduto::daVolumes([['c' => $c, 'l' => $l, 'a' => $a, 'kg' => $kg]]);

        return ['pacote' => $av['pacote'], 'peso_faturado' => $av['peso_faturado'], 'logistica' => $av['logistica'], 'custo' => $custo];
    }

    private function conectar(Company $empresa): void
    {
        MlToken::create([
            'company_id' => $empresa->id, 'ml_user_id' => '436501796',
            'access_token' => 'fake-access-token', 'refresh_token' => 'fake-refresh-token',
            'token_type' => 'bearer', 'scope' => 'read write offline_access',
            'expires_at' => now()->addDays(6), 'last_refreshed_at' => now(),
            'status' => 'active', 'connected_at' => now(),
        ]);
        $empresa->unsetRelation('mlToken');
    }

    private function fretesApi(callable $porRequisicao): void
    {
        Http::fake(fn (Request $r) => Http::response($porRequisicao($r)));
    }

    // ═══ Task 1: estimativa ═══

    public function test_estimar_me2_com_custo_usa_a_tabela_e_nao_chama_o_ml(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $item = $this->item(93, 55, 6, 9.5, 100);
        $this->assertSame(LogisticaProduto::ME2, $item['logistica']);

        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertSame('tabela_ecf', $f['origem']);
        $this->assertSame('custo', $f['preco_origem']);
        $this->assertNotNull($f['valor']);
        $this->assertFalse($f['instavel']);
        Http::assertNothingSent();
    }

    public function test_estimar_sem_custo_usa_o_preco_de_referencia(): void
    {
        $empresa = $this->empresaDoGabarito();
        $f = $this->svc()->estimar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, null)])['v1'];

        $this->assertSame('referencia', $f['preco_origem']);
        $this->assertEquals(config('estrutura_produtos.frete.preco_referencia'), $f['preco_usado']);
        $this->assertSame('tabela_ecf', $f['origem']);
    }

    public function test_estimar_me1_e_pendente_nao_tem_frete(): void
    {
        $empresa = $this->empresaDoGabarito();
        $me1 = $this->item(187, 44, 12, 40, 100);
        $pendente = ['pacote' => null, 'peso_faturado' => null, 'logistica' => LogisticaProduto::PENDENTE, 'custo' => 100];
        $itens = ['a' => $me1, 'b' => $pendente];

        $r = $this->svc()->estimar($empresa, $itens);

        $this->assertNull($r['b']['valor']);
        $this->assertNull($r['b']['origem']);
        if ($me1['logistica'] !== LogisticaProduto::ME2) {
            $this->assertNull($r['a']['valor']);
            $this->assertNull($r['a']['origem']);
        }
    }

    public function test_estimar_para_depois_de_duas_recotacoes_e_marca_instavel(): void
    {
        // Tabela artificial: o frete alto joga o preço para a faixa cara, onde o frete é baixo, e volta.
        config([
            'estrutura_produtos.frete.faixas_peso'  => [0],
            'estrutura_produtos.frete.faixas_preco' => [0, 100],
            'estrutura_produtos.frete.tabela'       => [[50.0, 10.0]],
            'estrutura_produtos.frete.max_recotacoes' => 2,
        ]);
        $empresa = $this->empresaDoGabarito();
        $item = ['pacote' => ['c' => 10, 'l' => 10, 'a' => 10, 'peso_real' => 1.0], 'peso_faturado' => 1.0, 'logistica' => LogisticaProduto::ME2, 'custo' => 30.0];

        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertTrue($f['instavel']);
        $this->assertTrue($f['alerta_faixa']);
    }

    // ═══ Task 2: cotação real ═══

    public function test_cotar_chama_o_endpoint_certo_com_as_dimensoes_certas(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fretesApi(fn () => ['coverage' => ['all_country' => ['list_cost' => 23.45]]]);

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        $this->assertTrue($r['conectado']);
        $this->assertSame('api', $r['fretes']['v1']['origem']);
        $this->assertEquals(23.45, $r['fretes']['v1']['valor']);
        Http::assertSent(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return $req->method() === 'GET'
                && str_contains($req->url(), '/users/436501796/shipping_options/free')
                && $q['dimensions'] === '6x55x93,9500'
                && $q['listing_type_id'] === 'gold_special'
                && $q['mode'] === 'me2'
                && $q['logistic_type'] === 'drop_off';
        });
    }

    public function test_cotar_me1_e_pendente_nao_gera_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        Http::fake();
        $me1 = $this->item(187, 44, 12, 40, 100);
        $pendente = ['pacote' => null, 'peso_faturado' => null, 'logistica' => LogisticaProduto::PENDENTE, 'custo' => 100];

        $itens = ['b' => $pendente];
        if ($me1['logistica'] === LogisticaProduto::ME1) {
            $itens['a'] = $me1;
        }
        $this->svc()->cotar($empresa, $itens);

        Http::assertNothingSent();
    }

    public function test_cotar_respeita_o_limite_por_chamada_e_usa_cache(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fretesApi(fn () => ['coverage' => ['all_country' => ['list_cost' => 20.0]]]);

        // 15 variações com dimensões diferentes (cada uma exige a sua cotação).
        $itens = [];
        for ($i = 0; $i < 15; $i++) {
            $itens["v{$i}"] = $this->item(93, 40 + $i, 6, 9.5, 100);
        }

        $r1 = $this->svc()->cotar($empresa, $itens);
        $enviadas1 = count(Http::recorded());
        $this->assertLessThanOrEqual(12, $enviadas1);
        $this->assertGreaterThanOrEqual(3, $r1['pendentes']);

        $r2 = $this->svc()->cotar($empresa, $itens);
        $this->assertSame(0, $r2['pendentes']);
        foreach ($r2['fretes'] as $f) {
            $this->assertSame('api', $f['origem']);
        }

        $antes = count(Http::recorded());
        $r3 = $this->svc()->cotar($empresa, $itens);
        $this->assertSame($antes, count(Http::recorded()), 'a 3a chamada sai do cache');
        $this->assertSame(0, $r3['pendentes']);
    }

    public function test_cotar_recota_quando_o_frete_muda_a_faixa_e_avisa_frete_gratis_obrigatorio(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        // Preço baixo: frete 10 sem aviso; preço alto: frete 40 com frete grátis obrigatório.
        $this->fretesApi(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $alto = (float) $q['item_price'] >= 100;

            return ['coverage' => ['all_country' => array_filter([
                'list_cost' => $alto ? 40.0 : 10.0,
                'discount'  => $alto ? ['type' => 'mandatory'] : null,
            ])]];
        });
        // custo 30: sem frete ~43,2 (frete 10) -> com 10: ~57,6; mesma faixa baixa; usa custo maior para cruzar.
        $item = $this->item(93, 55, 6, 9.5, 65); // 65/0,695 = 93,5 (frete 10) -> com 10: 107,9 (cruza 100) -> frete 40
        $r = $this->svc()->cotar($empresa, ['v1' => $item]);

        $f = $r['fretes']['v1'];
        $this->assertSame('api', $f['origem']);
        $this->assertGreaterThan(1, count(Http::recorded()), 'houve re-cotação');
        $this->assertTrue($f['alerta_faixa']);
    }

    public function test_cotar_sem_conta_conectada_usa_a_tabela_sem_http(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        $this->assertFalse($r['conectado']);
        $this->assertSame('tabela_ecf', $r['fretes']['v1']['origem']);
        Http::assertNothingSent();
    }

    public function test_cotacao_com_http_500_so_afeta_aquela_variacao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        Http::fake(function (Request $req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

            return str_starts_with($q['dimensions'], '6x55x93,')
                ? Http::response('erro', 500)
                : Http::response(['coverage' => ['all_country' => ['list_cost' => 22.0]]]);
        });

        $r = $this->svc()->cotar($empresa, [
            'ruim' => $this->item(93, 55, 6, 9.5, 100),
            'boa'  => $this->item(91, 59, 20, 9.5, 100),
        ]);

        $this->assertSame('tabela_ecf', $r['fretes']['ruim']['origem']);
        $this->assertTrue($r['fretes']['ruim']['falhou']);
        $this->assertSame('api', $r['fretes']['boa']['origem']);
        $this->assertTrue($r['falhou']);
    }

    public function test_cotar_so_le_nao_grava_e_nao_vaza_o_token(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fretesApi(fn () => ['coverage' => ['all_country' => ['list_cost' => 21.0]]]);

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        Http::assertNotSent(fn (Request $req) => $req->method() !== 'GET');
        $this->assertSame(0, EstruturaPrecificacao::count());
        $this->assertStringNotContainsString('fake-access-token', json_encode($r));
    }

    public function test_depois_de_cotar_estimar_devolve_a_cotacao_da_api(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->fretesApi(fn () => ['coverage' => ['all_country' => ['list_cost' => 21.0]]]);
        $item = $this->item(93, 55, 6, 9.5, 100);

        $this->svc()->cotar($empresa, ['v1' => $item]);
        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertSame('api', $f['origem']);
        $this->assertEquals(21.0, $f['valor']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}
