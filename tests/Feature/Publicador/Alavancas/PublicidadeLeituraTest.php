<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\DatasDoMl;
use App\Services\Publicador\Alavancas\PublicidadeLeitura;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-05: publicidade só leitura, pelos caminhos atuais da doc e com os cabeçalhos de versão. */
class PublicidadeLeituraTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function leitura(): PublicidadeLeitura
    {
        return app(PublicidadeLeitura::class);
    }

    private function cenario(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/advertising/advertisers$#', self::fixtureAlavanca('doc/publicidade/advertisers'));
        $this->responder('GET', '#/product_ads/campaigns/search$#', self::fixtureAlavanca('doc/publicidade/campaigns_search'));
        $this->responder('GET', '#/product_ads/ad_groups/search$#', self::fixtureAlavanca('doc/publicidade/ad_groups_search'));
        $this->responder('GET', '#^/advertising/advertisers/bonifications$#', self::fixtureAlavanca('doc/publicidade/bonifications'));
    }

    private function janela(): array
    {
        $hoje = DatasDoMl::hoje();

        return [$hoje->subDays(29)->format('Y-m-d'), $hoje->format('Y-m-d')];
    }

    private function chamadasAds(): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => str_contains($c['caminho'], '/advertising/')));
    }

    private function cabecalho(array $chamada, string $nome): ?string
    {
        foreach ($chamada['cabecalhos'] as $k => $v) {
            if (strtolower($k) === strtolower($nome)) {
                return is_array($v) ? ($v[0] ?? null) : $v;
            }
        }

        return null;
    }

    public function test_anunciante_usa_api_version_1_e_guarda_o_id_do_site_mlb(): void
    {
        $this->cenario();

        $r = $this->leitura()->anunciante($this->contaAlavanca());
        $this->assertSame('1000001', $r['advertiser_id']);
        $this->assertNull($r['indisponivel']);

        $c = $this->chamadasAds()[0];
        $this->assertSame('/advertising/advertisers', $c['caminho']);
        $this->assertSame('PADS', $c['query']['product_id']);
        $this->assertSame('1', $this->cabecalho($c, 'Api-Version'));

        // 2ª leitura sem HTTP (24 h).
        $antes = count($this->chamadas);
        $this->leitura()->anunciante($this->contaAlavanca());
        $this->assertCount($antes, $this->chamadas);
    }

    public function test_sem_permissao_de_product_ads_vira_explicacao_e_nao_chama_campanhas(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/advertising/advertisers$#', ['message' => 'No permissions found', 'status' => 404], 404);
        [$de, $ate] = $this->janela();

        $r = $this->leitura()->campanhas($this->contaAlavanca(), $de, $ate);

        $this->assertSame('Product Ads não está ativo nesta conta.', $r['indisponivel']);
        $this->assertSame([], $r['campanhas']);
        $this->assertCount(1, $this->chamadasAds());
    }

    public function test_403_vira_a_mensagem_da_permissao_de_publicidade(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/advertising/advertisers$#', ['message' => 'forbidden'], 403);

        $r = $this->leitura()->anunciante($this->contaAlavanca());

        $this->assertNull($r['advertiser_id']);
        $this->assertStringContainsString('permissão de Publicidade', $r['indisponivel']);
    }

    public function test_campanhas_vao_com_api_version_2_metricas_e_resumo(): void
    {
        $this->cenario();
        [$de, $ate] = $this->janela();

        $r = $this->leitura()->campanhas($this->contaAlavanca(), $de, $ate);

        $c = $this->chamadasAds()[1];
        $this->assertSame('/advertising/MLB/advertisers/1000001/product_ads/campaigns/search', $c['caminho']);
        $this->assertSame('2', $this->cabecalho($c, 'api-version'));
        $this->assertSame('50', (string) $c['query']['limit']);
        $this->assertSame('0', (string) $c['query']['offset']);
        $this->assertSame($de, $c['query']['date_from']);
        $this->assertSame($ate, $c['query']['date_to']);
        $this->assertSame(PublicidadeLeitura::METRICAS, $c['query']['metrics']);
        $this->assertSame('true', $c['query']['metrics_summary']);

        $this->assertCount(2, $r['campanhas']);
        $k = $r['campanhas'][0];
        $this->assertSame('355189450', $k['id']);
        $this->assertSame('active', $k['status']);
        $this->assertEquals(900, $k["orcamento"]);
        $this->assertSame('VISIBILITY', $k['estrategia']);
        $this->assertEquals(50, $k["acos_alvo"]);
        $this->assertSame(120, $k['metricas']['cliques']);
        $this->assertSame(300.0, $k['metricas']['investimento']);
        $this->assertSame(1500.0, $k['metricas']['vendas']);
        $this->assertSame(300.0, $r['resumo']['investimento']);
    }

    #[DataProvider('janelasInvalidas')]
    public function test_janela_invalida_nao_faz_http(int $deriva, int $ateDias): void
    {
        $this->cenario();
        $hoje = DatasDoMl::hoje();
        $de = $hoje->subDays($deriva)->format('Y-m-d');
        $ate = $hoje->subDays($ateDias)->format('Y-m-d');

        try {
            $this->leitura()->campanhas($this->contaAlavanca(), $de, $ate);
            $this->fail('deveria lançar ALAV-DATA');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-DATA', $e->regra);
        }
        $this->assertSame([], $this->chamadasAds());
    }

    public static function janelasInvalidas(): array
    {
        return [
            'de há mais de 90 dias' => [91, 1],
            'ate no futuro' => [5, -1],
            'de maior que ate' => [2, 5],
        ];
    }

    public function test_ad_groups_filtram_por_item_ids_no_plural(): void
    {
        $this->cenario();
        [$de, $ate] = $this->janela();

        $r = $this->leitura()->adGroups($this->contaAlavanca(), $de, $ate, ['MLB1', 'MLB2', 'invalido', 'MLB3; drop']);

        $c = $this->chamadasAds()[1];
        $this->assertSame('/advertising/MLB/advertisers/1000001/product_ads/ad_groups/search', $c['caminho']);
        $this->assertSame('2', $this->cabecalho($c, 'api-version'));
        $this->assertSame('MLB1,MLB2', $c['query']['filters']['item_ids']);
        $this->assertArrayNotHasKey('filters[item_id]', $c['query']);
        $this->assertSame('clicks', $c['query']['sort_by']);

        $this->assertCount(2, $r['ad_groups']);
        $this->assertFalse($r['ad_groups'][0]['fora_de_campanha']);
        $this->assertTrue($r['ad_groups'][1]['fora_de_campanha']);
    }

    public function test_bonificacoes_somam_o_saldo_das_ativas(): void
    {
        $this->cenario();

        $r = $this->leitura()->bonificacoes($this->contaAlavanca());

        $c = $this->chamadasAds()[0];
        $this->assertSame('/advertising/advertisers/bonifications', $c['caminho']);
        $this->assertSame(7510.0, $r['saldo_total']);
        $this->assertCount(2, $r['itens']);
        $this->assertSame('smart_benefit', $r['itens'][0]['beneficio']);
        $this->assertSame('Liquida 10.10', $r['itens'][0]['campanha']);
        $this->assertSame(54, $r['itens'][1]['dias_restantes']);
    }

    public function test_bonificacoes_lista_vazia_tem_saldo_zero(): void
    {
        $this->cenario();
        $this->responder('GET', '#^/advertising/advertisers/bonifications$#', ['bonification' => []]);

        $r = $this->leitura()->bonificacoes($this->contaAlavanca());

        $this->assertSame(0.0, $r['saldo_total']);
        $this->assertSame([], $r['itens']);
    }

    public function test_resumo_junta_campanhas_ativas_investimento_e_bonificacao(): void
    {
        $this->cenario();

        $r = $this->leitura()->resumo($this->contaAlavanca());

        $this->assertSame(1, $r['campanhas_ativas']);
        $this->assertSame(300.0, $r['investimento']);
        $this->assertSame(1500.0, $r['vendas']);
        $this->assertSame(20.0, $r['acos']);
        $this->assertSame(7510.0, $r['bonificacao_saldo']);
    }

    public function test_falha_do_ml_lanca_e_nao_fica_em_cache(): void
    {
        $this->cenario();
        $this->responder('GET', '#/product_ads/campaigns/search$#', ['message' => 'erro'], 500);

        try {
            $this->leitura()->resumo($this->contaAlavanca());
            $this->fail('deveria lançar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        // Volta a responder: a chamada seguinte já lê certo (o erro não foi guardado).
        $this->responder('GET', '#/product_ads/campaigns/search$#', self::fixtureAlavanca('doc/publicidade/campaigns_search'));
        $this->assertSame(1, $this->leitura()->resumo($this->contaAlavanca())['campanhas_ativas']);
    }

    public function test_toda_chamada_de_publicidade_e_get(): void
    {
        $this->cenario();
        [$de, $ate] = $this->janela();
        $c = $this->contaAlavanca();

        $this->leitura()->campanhas($c, $de, $ate);
        $this->leitura()->adGroups($c, $de, $ate, ['MLB1']);
        $this->leitura()->bonificacoes($c);
        $this->leitura()->resumo($c, true);

        $ads = $this->chamadasAds();
        $this->assertNotEmpty($ads);
        foreach ($ads as $chamada) {
            $this->assertSame('GET', $chamada['metodo'], $chamada['caminho']);
        }
        $this->assertSame([], $this->chamadasNaoGet());
    }
}
