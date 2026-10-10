<?php

namespace Tests\Feature\PortalCliente\Estrutura\Produtos;

use App\Models\Company;
use App\Models\EstruturaPrecificacao;
use App\Models\MlToken;
use App\Services\Portal\Estrutura\Produtos\FreteMe2Service;
use App\Services\Portal\Estrutura\Produtos\LogisticaProduto;
use App\Services\Portal\Estrutura\Produtos\ModalidadeDeEnvio;
use App\Services\Portal\Estrutura\Produtos\TabelaFreteEcf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GabaritoDaPlanilhaEstrutural;
use Tests\TestCase;

/**
 * Frete ME2 estimado pela tabela de custos do ML e cotado pela conta do cliente — por
 * tipo de anúncio, cada um no próprio preço ("seguir o ML em tudo", 09/10/2026).
 *
 * Modos de falha que estes testes impedem: cotar o Premium no preço do Clássico, juntar
 * 78,99 e 79 na mesma cotação, cotar sem `free_shipping` ou com o `logistic_type` errado,
 * cotar por tecla/linha colada (limite de lote e cache), vazar o token do cliente e
 * escrever na conta do cliente (só GET).
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

    /** A preferência de envio real da #459 (drop_off padrão, Full ativo). */
    private function preferencia459(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures-ml/sondagem/conta/shipping_preferences.json')), true)['resposta'];
    }

    /**
     * O ML falso: `shipping_preferences` devolve `$preferencia` (padrão: a da #459) e cada
     * cotação passa por `$cotacao($query)` — um corpo, ou uma resposta pronta do `Http::`.
     */
    private function mlFalso(callable $cotacao, ?array $preferencia = null): void
    {
        $preferencia ??= $this->preferencia459();
        Http::fake(function (Request $r) use ($cotacao, $preferencia) {
            if (str_contains($r->url(), '/shipping_preferences')) {
                return Http::response($preferencia);
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $corpo = $cotacao($q);

            return is_array($corpo) ? Http::response($corpo) : $corpo;
        });
    }

    /** O ML respondendo a própria tabela (+ `$extra`, como uma reputação que paga mais). */
    private function pelaTabela(array $q, float $extra = 0.0): array
    {
        [$medidas, $gramas] = explode(',', $q['dimensions']);
        [$a, $l, $c] = array_map('floatval', explode('x', $medidas));
        $faturado = LogisticaProduto::avaliar(['c' => $c, 'l' => $l, 'a' => $a, 'peso_real' => (float) $gramas / 1000])['peso_faturado'];
        $preco = (float) $q['item_price'];

        return ['coverage' => ['all_country' => array_filter([
            'list_cost'             => round(TabelaFreteEcf::valor($faturado, $preco) + $extra, 2),
            'discount'              => ['type' => 'mandatory'],
            'free_shipping_by_meli' => $preco < 79 ? true : null,
        ], fn ($v) => $v !== null)]];
    }

    /** As cotações enviadas, na ordem: [listing_type_id, item_price, query]. */
    private function cotacoesEnviadas(): array
    {
        $saida = [];
        foreach (Http::recorded() as [$req]) {
            if (str_contains($req->url(), '/shipping_options/free')) {
                parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                $saida[] = [$q['listing_type_id'], (float) $q['item_price'], $q];
            }
        }

        return $saida;
    }

    // ═══ Estimativa (sem requisição) ═══

    public function test_estimar_me2_com_custo_usa_a_tabela_e_nao_chama_o_ml(): void
    {
        Http::fake();
        $empresa = $this->empresaDoGabarito();
        $item = $this->item(93, 55, 6, 9.5, 100);
        $this->assertContains($item['logistica'], [LogisticaProduto::ME2, LogisticaProduto::ME2_FULL]);

        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertSame('tabela_ecf', $f['origem']);
        $this->assertSame('custo', $f['preco_origem']);
        $this->assertNotNull($f['valor']);
        $this->assertFalse($f['instavel']);
        // O topo é o Clássico; os dois tipos vêm em `por_tipo`.
        $this->assertSame($f['valor'], $f['por_tipo']['classico']['valor']);
        $this->assertSame('tabela_ecf', $f['por_tipo']['premium']['origem']);
        $this->assertGreaterThan($f['por_tipo']['classico']['preco_usado'], $f['por_tipo']['premium']['preco_usado']);
        Http::assertNothingSent();
    }

    /**
     * Cada tipo no PRÓPRIO preço: com custo 45 e 15×15×20 de 500 g (fatura 750 g), o
     * Clássico para em R$ 76,91 (faixa até 78,99: R$ 8,45) e o Premium, com a comissão
     * maior, em R$ 92,17 (faixa a partir de 79: R$ 14,45, com frete grátis obrigatório).
     */
    public function test_cada_tipo_tem_o_frete_da_faixa_do_proprio_preco(): void
    {
        $empresa = $this->empresaDoGabarito();

        $f = $this->svc()->estimar($empresa, ['v1' => $this->item(15, 15, 20, 0.5, 45)])['v1'];

        $this->assertSame(8.45, $f['por_tipo']['classico']['valor']);
        $this->assertSame(76.91, $f['por_tipo']['classico']['preco_usado']);
        $this->assertFalse($f['por_tipo']['classico']['gratis_obrigatorio']);
        $this->assertSame(14.45, $f['por_tipo']['premium']['valor']);
        $this->assertSame(92.17, $f['por_tipo']['premium']['preco_usado']);
        $this->assertTrue($f['por_tipo']['premium']['gratis_obrigatorio']);
        $this->assertFalse($f['por_tipo']['premium']['instavel']);
    }

    /** Abaixo de R$ 19 o custo é no máximo metade do preço — e o ponto fixo acha esse preço. */
    public function test_abaixo_de_19_reais_o_frete_e_metade_do_preco(): void
    {
        $empresa = $this->empresaDoGabarito();

        $f = $this->svc()->estimar($empresa, ['v1' => $this->item(10, 10, 10, 0.2, 1)])['v1']['por_tipo']['classico'];

        $this->assertLessThan(19, $f['preco_usado']);
        $this->assertLessThan(5.65, $f['valor'], 'a tabela diria 5,65');
        $this->assertEqualsWithDelta($f['preco_usado'] / 2, $f['valor'], 0.02);
        $this->assertFalse($f['instavel']);
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
        $this->assertSame(LogisticaProduto::ME1, $me1['logistica']);
        $pendente = ['pacote' => null, 'peso_faturado' => null, 'logistica' => LogisticaProduto::PENDENTE, 'custo' => 100];

        $r = $this->svc()->estimar($empresa, ['a' => $me1, 'b' => $pendente]);

        foreach (['a', 'b'] as $id) {
            $this->assertNull($r[$id]['valor']);
            $this->assertNull($r[$id]['origem']);
            $this->assertNull($r[$id]['por_tipo']['premium']['valor']);
        }
    }

    public function test_estimar_que_nao_converge_marca_instavel(): void
    {
        // Tabela artificial: o frete alto joga o preço para a faixa cara, onde o frete é baixo, e volta.
        config([
            'estrutura_produtos.frete.faixas_peso'  => [0],
            'estrutura_produtos.frete.faixas_preco' => [0, 100],
            'estrutura_produtos.frete.tabela'       => [[50.0, 10.0]],
        ]);
        $empresa = $this->empresaDoGabarito();
        $item = ['pacote' => ['c' => 10, 'l' => 10, 'a' => 10, 'peso_real' => 1.0], 'peso_faturado' => 1.0, 'logistica' => LogisticaProduto::ME2, 'custo' => 30.0];

        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertTrue($f['instavel']);
        $this->assertTrue($f['alerta_faixa']);
    }

    /** BE-IN-03: lado abaixo de 0,5 cm não vai ao ML como 0 (que responde 400); vai como 1. */
    public function test_dimensoes_do_ml_nunca_tem_lado_zero(): void
    {
        $this->assertSame('1x1x93,9500', FreteMe2Service::dimensions(['a' => 0.3, 'l' => 0.01, 'c' => 93, 'peso_real' => 9.5]));
        $this->assertSame('6x55x93,1', FreteMe2Service::dimensions(['a' => 6, 'l' => 55, 'c' => 93, 'peso_real' => 0.0004]));
        $this->assertSame('6x55x93,9500', FreteMe2Service::dimensions(['a' => 6, 'l' => 55, 'c' => 93, 'peso_real' => 9.5]));
    }

    /** BE-WR-07: com o cache no banco, a estimativa de 30 variações lê o cache numa consulta só. */
    public function test_estimar_le_o_cache_numa_consulta_so(): void
    {
        config(['cache.default' => 'database']);
        $empresa = $this->empresaDoGabarito();
        $itens = [];
        for ($i = 0; $i < 30; $i++) {
            $itens["v{$i}"] = $this->item(93, 30 + $i, 6, 9.5, 100);
        }

        $leituras = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$leituras) {
            if (str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, '"cache"')) {
                $leituras++;
            }
        });

        $r = $this->svc()->estimar($empresa, $itens);

        $this->assertCount(30, $r);
        $this->assertSame('tabela_ecf', $r['v0']['origem']);
        $this->assertSame(1, $leituras);
    }

    // ═══ Cotação real ═══

    public function test_cotar_cada_tipo_no_proprio_preco_com_free_shipping_e_a_modalidade_da_conta(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn () => ['coverage' => ['all_country' => ['list_cost' => 23.45]]]);

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        $this->assertTrue($r['conectado']);
        $this->assertSame('api', $r['fretes']['v1']['origem']);
        $this->assertEquals(23.45, $r['fretes']['v1']['valor']);
        $this->assertSame('api', $r['fretes']['v1']['por_tipo']['premium']['origem']);

        $enviadas = $this->cotacoesEnviadas();
        $classico = array_values(array_filter($enviadas, fn ($e) => $e[0] === 'gold_special'));
        $premium  = array_values(array_filter($enviadas, fn ($e) => $e[0] === 'gold_pro'));
        $this->assertNotEmpty($classico);
        $this->assertNotEmpty($premium);
        // O Premium vai ao ML no preço DELE, acima do Clássico (a comissão é maior).
        $this->assertGreaterThan($classico[0][1], $premium[0][1]);
        foreach ($enviadas as [, $preco, $q]) {
            $this->assertSame('6x55x93,9500', $q['dimensions']);
            $this->assertSame('me2', $q['mode']);
            $this->assertSame('drop_off', $q['logistic_type'], 'a preferência da #459');
            $this->assertSame($preco >= 79 ? 'true' : 'false', $q['free_shipping']);
        }
        Http::assertSent(fn (Request $req) => str_contains($req->url(), '/users/436501796/shipping_preferences'));
    }

    public function test_cotar_o_que_nao_cabe_em_modalidade_nenhuma_e_pendente_nao_gera_requisicao(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        Http::fake();
        $me1 = $this->item(250, 44, 12, 60, 100);   // lado 250 e 60 kg: fora até da Coleta
        $pendente = ['pacote' => null, 'peso_faturado' => null, 'logistica' => LogisticaProduto::PENDENTE, 'custo' => 100];

        $r = $this->svc()->cotar($empresa, ['a' => $me1, 'b' => $pendente]);

        Http::assertNothingSent();
        $this->assertNull($r['fretes']['a']['valor']);
    }

    /** A conta despacha pela Coleta: o que nos Correios seria ME1 cabe no ME2 e é cotado como cross_docking. */
    public function test_conta_na_coleta_cota_o_que_nos_correios_seria_me1(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $coleta = ['logistics' => [['mode' => 'me2', 'types' => [
            ['type' => 'drop_off', 'default' => false, 'status' => 'active'],
            ['type' => 'cross_docking', 'default' => true, 'status' => 'active'],
        ]]]];
        $this->mlFalso(fn (array $q) => $this->pelaTabela($q), $coleta);
        $item = $this->item(187, 44, 12, 28.5, 300);
        $this->assertSame(LogisticaProduto::ME1, $item['logistica'], 'pelos limites dos Correios');

        $r = $this->svc()->cotar($empresa, ['v1' => $item]);

        $this->assertSame('api', $r['fretes']['v1']['origem']);
        $this->assertNotEmpty($this->cotacoesEnviadas());
        foreach ($this->cotacoesEnviadas() as [, , $q]) {
            $this->assertSame('cross_docking', $q['logistic_type']);
        }
        $this->assertSame('cross_docking', ModalidadeDeEnvio::emCache($empresa));
        // A modalidade e a cotação ficam guardadas: a próxima chamada não vai ao ML.
        $antes = count(Http::recorded());
        $this->svc()->cotar($empresa, ['v1' => $item]);
        $this->assertSame($antes, count(Http::recorded()));
    }

    public function test_cotar_respeita_o_limite_por_chamada_e_usa_cache(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn (array $q) => $this->pelaTabela($q));
        $limite = (int) config('estrutura_produtos.frete.max_por_requisicao');

        // 15 variações com dimensões diferentes: cada uma pede Clássico e Premium.
        $itens = [];
        for ($i = 0; $i < 15; $i++) {
            $itens["v{$i}"] = $this->item(93, 40 + $i, 6, 9.5, 100);
        }

        $r1 = $this->svc()->cotar($empresa, $itens);
        $this->assertLessThanOrEqual($limite, count($this->cotacoesEnviadas()));
        $this->assertSame(15 - intdiv($limite, 2), $r1['pendentes']);

        $r2 = $this->svc()->cotar($empresa, $itens);
        $this->assertSame(0, $r2['pendentes']);
        foreach ($r2['fretes'] as $f) {
            $this->assertSame('api', $f['por_tipo']['classico']['origem']);
            $this->assertSame('api', $f['por_tipo']['premium']['origem']);
        }

        $antes = count(Http::recorded());
        $r3 = $this->svc()->cotar($empresa, $itens);
        $this->assertSame($antes, count(Http::recorded()), 'a 3a chamada sai do cache');
        $this->assertSame(0, $r3['pendentes']);
    }

    /**
     * 78,99 e 79 são faixas diferentes (a chave antiga, `round($preco)`, juntava os dois). Com
     * as respostas reais da #459: R$ 78,99 → 8,45 com o ML bancando; R$ 79 → 14,45 com frete
     * grátis obrigatório para o vendedor.
     */
    public function test_cotacao_de_78_99_nao_serve_para_79(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $fixture = fn (string $nome) => json_decode(file_get_contents(base_path("tests/fixtures-ml/sondagem/conta/shipping_options_free_{$nome}.json")), true)['resposta'];
        $this->mlFalso(fn (array $q) => (float) $q['item_price'] >= 79 ? $fixture('79') : $fixture('78_99'));
        $item = $this->item(15, 15, 20, 0.5, null);   // sem custo: o preço é o de referência

        config(['estrutura_produtos.frete.preco_referencia' => 78.99]);
        $r = $this->svc()->cotar($empresa, ['v1' => $item])['fretes']['v1'];
        $this->assertSame('api', $r['origem']);
        $this->assertEquals(8.45, $r['valor']);
        $this->assertFalse($r['gratis_obrigatorio'], 'abaixo de 79 o ML banca o frete');
        $this->assertSame(['false'], array_values(array_unique(array_map(fn ($e) => $e[2]['free_shipping'], $this->cotacoesEnviadas()))));

        config(['estrutura_produtos.frete.preco_referencia' => 79]);
        $estimado = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];
        $this->assertSame('tabela_ecf', $estimado['origem'], 'a cotação de 78,99 não vale para 79');
        $this->assertSame(14.45, $estimado['valor']);

        $r = $this->svc()->cotar($empresa, ['v1' => $item])['fretes']['v1'];
        $this->assertSame('api', $r['origem']);
        $this->assertEquals(14.45, $r['valor']);
        $this->assertTrue($r['gratis_obrigatorio']);
        $ultima = $this->cotacoesEnviadas()[count($this->cotacoesEnviadas()) - 1][2];
        $this->assertSame('true', $ultima['free_shipping']);
    }

    /**
     * A cotação real paga mais que a tabela (reputação amarela, categoria especial…) e leva o
     * preço para a faixa de cima: a faixa nova é cotada na rodada seguinte, e o resultado é o
     * da API nas duas pontas.
     */
    public function test_cotar_recota_quando_a_cotacao_leva_o_preco_para_outra_faixa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn (array $q) => $this->pelaTabela($q, 30));

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 65)]);

        $f = $r['fretes']['v1'];
        $this->assertSame('api', $f['origem']);
        $this->assertEquals(65.85 + 30, $f['valor'], 'a faixa a partir de R$ 200, cotada');
        $this->assertGreaterThanOrEqual(200, $f['preco_usado']);
        $this->assertTrue($f['gratis_obrigatorio'], 'o aviso vem da resposta da API');
        $this->assertFalse($f['instavel']);
        $classico = array_filter($this->cotacoesEnviadas(), fn ($e) => $e[0] === 'gold_special');
        $this->assertCount(2, $classico, 'houve re-cotação');
    }

    /** Sem rodada para a faixa nova, o frete fica marcado: "neste preço o frete pode mudar de faixa". */
    public function test_sem_recotacao_o_preco_que_saiu_da_faixa_cotada_fica_instavel(): void
    {
        config(['estrutura_produtos.frete.max_recotacoes' => 0]);
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn (array $q) => $this->pelaTabela($q, 30));

        $f = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 65)])['fretes']['v1'];

        $this->assertTrue($f['instavel']);
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
        $this->mlFalso(fn (array $q) => str_starts_with($q['dimensions'], '6x55x93,')
            ? Http::response('erro', 500)
            : $this->pelaTabela($q));

        $r = $this->svc()->cotar($empresa, [
            'ruim' => $this->item(93, 55, 6, 9.5, 100),
            'boa'  => $this->item(91, 59, 20, 9.5, 100),
        ]);

        $this->assertSame('tabela_ecf', $r['fretes']['ruim']['origem']);
        $this->assertTrue($r['fretes']['ruim']['falhou']);
        $this->assertTrue($r['fretes']['ruim']['por_tipo']['premium']['falhou']);
        $this->assertSame('api', $r['fretes']['boa']['origem']);
        $this->assertFalse($r['fretes']['boa']['falhou']);
        $this->assertTrue($r['falhou']);
    }

    /**
     * BE-WR-06: com o ML devolvendo 429, cada cotação é UMA tentativa com timeout curto — sem o
     * refazer em série do getMany (retry de 429 com sleep de até 8 s) — e cai na tabela.
     */
    public function test_429_nao_e_refeito_em_serie_nem_dorme_e_a_cotacao_tem_timeout_curto(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        ModalidadeDeEnvio::guardar($empresa->id, 'drop_off');
        $opcoes = [];
        Http::fake(function (Request $req, array $options) use (&$opcoes) {
            $opcoes[] = $options;

            return Http::response(['message' => 'too_many_requests'], 429, ['Retry-After' => '8']);
        });

        $inicio = microtime(true);
        $r = $this->svc()->cotar($empresa, [
            'v1' => $this->item(93, 55, 6, 9.5, 100),
            'v2' => $this->item(91, 59, 20, 9.5, 100),
        ]);

        $this->assertLessThan(3.0, microtime(true) - $inicio, 'nada de sleep dentro da requisição web');
        $this->assertCount(4, Http::recorded(), 'uma tentativa por tipo de cada variação, sem refazer em série');
        $this->assertSame(8, $opcoes[0]['timeout']);
        $this->assertSame(3, $opcoes[0]['connect_timeout']);
        $this->assertTrue($r['falhou']);
        foreach (['v1', 'v2'] as $id) {
            $this->assertSame('tabela_ecf', $r['fretes'][$id]['origem']);
            $this->assertTrue($r['fretes'][$id]['falhou']);
        }
    }

    /** Sem a preferência de envio (o ML em 429), nada de cotar no escuro: uma requisição e a tabela. */
    public function test_preferencia_de_envio_sem_resposta_nao_cota_no_escuro(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        Http::fake(fn () => Http::response(['message' => 'too_many_requests'], 429));

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        $this->assertCount(1, Http::recorded());
        $this->assertTrue($r['falhou']);
        $this->assertSame('tabela_ecf', $r['fretes']['v1']['origem']);
        $this->assertTrue($r['fretes']['v1']['falhou']);
        $this->assertNull(ModalidadeDeEnvio::emCache($empresa));
        $this->assertFalse(Cache::has(ModalidadeDeEnvio::chave($empresa->id)), 'falha passageira não fica guardada');
    }

    /** BE-WR-06: queda de conexão (timeout/rede) também vira a tabela, sem nova tentativa. */
    public function test_queda_de_conexao_cai_na_tabela_sem_nova_tentativa(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        Http::fake(fn () => Http::failedConnection('cURL error 28: Operation timed out'));

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        $this->assertTrue($r['falhou']);
        $this->assertSame('tabela_ecf', $r['fretes']['v1']['origem']);
        $this->assertTrue($r['fretes']['v1']['falhou']);
        $this->assertLessThanOrEqual(1, count(Http::recorded()));
    }

    public function test_cotar_so_le_nao_grava_e_nao_vaza_o_token(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn (array $q) => $this->pelaTabela($q));

        $r = $this->svc()->cotar($empresa, ['v1' => $this->item(93, 55, 6, 9.5, 100)]);

        Http::assertNotSent(fn (Request $req) => $req->method() !== 'GET');
        $this->assertSame(0, EstruturaPrecificacao::count());
        $this->assertStringNotContainsString('fake-access-token', json_encode($r));
    }

    public function test_depois_de_cotar_estimar_devolve_a_cotacao_da_api_dos_dois_tipos(): void
    {
        $empresa = $this->empresaDoGabarito();
        $this->conectar($empresa);
        $this->mlFalso(fn () => ['coverage' => ['all_country' => ['list_cost' => 21.0]]]);
        $item = $this->item(93, 55, 6, 9.5, 100);

        $this->svc()->cotar($empresa, ['v1' => $item]);
        $f = $this->svc()->estimar($empresa, ['v1' => $item])['v1'];

        $this->assertSame('api', $f['origem']);
        $this->assertEquals(21.0, $f['valor']);
        $this->assertSame('api', $f['por_tipo']['premium']['origem']);
        $this->assertNotNull($f['cotado_em']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }
}
