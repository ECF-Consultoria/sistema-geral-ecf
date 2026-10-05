<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Services\Publicador\Alavancas\AtacadoLeitura;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-09: leitura do atacado % B2B (tag business, faixas com versão, recomendações). */
class AtacadoLeituraTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function leitura(): AtacadoLeitura
    {
        return app(AtacadoLeitura::class);
    }

    private function chamadasPara(string $caminhoParcial): array
    {
        return array_values(array_filter($this->chamadas, fn ($c) => str_contains($c['caminho'], $caminhoParcial)));
    }

    public function test_habilitado_pela_tag_business_da_conta_real(): void
    {
        $this->montarAlavancas();

        $this->assertTrue($this->leitura()->habilitado($this->contaAlavanca()));
    }

    public function test_sem_a_tag_business_nao_esta_habilitado(): void
    {
        $this->montarAlavancas();
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'tags' => ['normal']];

        $this->assertFalse($this->leitura()->habilitado($this->contaAlavanca(), true));
    }

    public function test_faixas_lidas_com_versao_e_cabecalho_sem_cache(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB1/prices$#', self::fixtureAlavanca('doc/atacado/prices_com_faixas'));

        $r = $this->leitura()->faixas($this->contaAlavanca(), 'MLB1');
        $this->leitura()->faixas($this->contaAlavanca(), 'MLB1');

        $this->assertCount(2, $this->chamadasPara('/items/MLB1/prices'), 'sem cache: cada leitura vai ao ML');
        $c = $this->chamadasPara('/items/MLB1/prices')[0];
        $this->assertSame('true', $c['query']['display_version']);
        $this->assertSame('true', $c['cabecalhos']['show-all-prices'][0]);

        $this->assertSame('14', $r['versao']);
        $this->assertSame(100.0, $r['preco_padrao']);
        $this->assertTrue($r['tem_faixas']);
        $this->assertFalse($r['tem_absoluto']);
        $this->assertNull($r['aviso']);
        $this->assertSame([
            ['id' => '12', 'percentual' => 3.63, 'quantidade_minima' => 2],
            ['id' => '13', 'percentual' => 9.29, 'quantidade_minima' => 4],
        ], $r['faixas']);
    }

    public function test_sem_price_per_quantity_cai_para_as_tags_do_anuncio(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB1/prices$#', self::fixtureAlavanca('doc/atacado/prices_display_version'));
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => ['standard_price_by_quantity']]);

        $r = $this->leitura()->faixas($this->contaAlavanca(), 'MLB1');

        $this->assertSame('9', $r['versao']);
        $this->assertTrue($r['tem_faixas']);
        $this->assertNull($r['faixas']);
        $this->assertSame('O Mercado Livre não devolveu as faixas atuais deste anúncio; confira no Mercado Livre antes de gravar.', $r['aviso']);
        $this->assertCount(1, array_filter($this->chamadas, fn ($c) => $c['caminho'] === '/items/MLB1'), 'a 2ª chamada é o GET do anúncio');
    }

    public function test_sem_faixas_e_sem_tag_diz_que_nao_tem(): void
    {
        $this->montarAlavancas();
        $this->responder('GET', '#^/items/MLB1/prices$#', self::fixtureAlavanca('doc/atacado/prices_display_version'));
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => []]);

        $r = $this->leitura()->faixas($this->contaAlavanca(), 'MLB1');

        $this->assertFalse($r['tem_faixas']);
        $this->assertSame([], $r['faixas']);
    }

    public function test_entrada_absoluta_em_prices_marca_tem_absoluto(): void
    {
        $this->montarAlavancas();
        $corpo = self::fixtureAlavanca('doc/atacado/prices_display_version');
        $corpo['prices'][] = ['id' => '5', 'type' => 'standard', 'amount' => 90, 'currency_id' => 'BRL',
            'conditions' => ['context_restrictions' => ['user_type_business'], 'min_purchase_unit' => 5]];
        $this->responder('GET', '#^/items/MLB1/prices$#', $corpo);
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => []]);

        $r = $this->leitura()->faixas($this->contaAlavanca(), 'MLB1');

        $this->assertTrue($r['tem_absoluto']);
        $this->assertSame(100.0, $r['preco_padrao'], 'o preço padrão ignora a entrada por quantidade');
    }

    public function test_recomendacoes_em_conta_liberada(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', self::fixtureAlavanca('doc/atacado/recommendations'));

        $r = $this->leitura()->recomendacoes($this->contaAlavanca(), 'MLB1', [2, 5, 10], 100.0);

        $posts = $this->chamadasNaoGet();
        $this->assertCount(1, $posts);
        $this->assertSame('/prices-per-quantity/v1/recommendations', $posts[0]['caminho']);
        $this->assertSame(['item_id' => 'MLB1', 'range_item_quantities' => [2, 5, 10], 'price' => ['standard_amount' => 100.0, 'currency' => 'BRL']], $posts[0]['corpo']);

        $this->assertFalse($r['sem_recomendacao']);
        $this->assertCount(3, $r['recomendacoes']);
        $this->assertSame(['quantidade' => 2, 'valor' => 176.97, 'percentual' => 4.340541, 'incoerente' => false, 'lucro' => 330.29, 'frete' => 23.65], $r['recomendacoes'][0]);
        $this->assertTrue($r['recomendacoes'][2]['incoerente']);
    }

    public function test_204_vira_sem_recomendacao(): void
    {
        $this->montarAlavancas();
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', [], 204);

        $r = $this->leitura()->recomendacoes($this->contaAlavanca(), 'MLB1', [2], 100.0);

        $this->assertTrue($r['sem_recomendacao']);
        $this->assertSame([], $r['recomendacoes']);
    }

    public function test_conta_nao_liberada_nao_faz_nenhuma_chamada(): void
    {
        $this->montarAlavancas('company', false);

        try {
            $this->leitura()->recomendacoes($this->contaAlavanca(), 'MLB1', [2], 100.0);
            $this->fail('devia lançar ALAV-LIB');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-LIB', $e->regra);
        }

        $this->assertSame([], $this->chamadas, 'nem o /users/me');
    }

    public function test_conta_liberada_sem_business_e_recusada_sem_post(): void
    {
        $this->montarAlavancas();
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'tags' => ['normal']];

        try {
            $this->leitura()->recomendacoes($this->contaAlavanca(), 'MLB1', [2], 100.0);
            $this->fail('devia lançar ALAV-B2B-02');
        } catch (RegraViolada $e) {
            $this->assertSame('ALAV-B2B-02', $e->regra);
        }

        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_quantidades_invalidas_sao_recusadas_sem_post(): void
    {
        $this->montarAlavancas();

        foreach ([[1, 2, 3, 4, 5, 6], [0, 2], [2, 2], [], [101]] as $qs) {
            try {
                $this->leitura()->recomendacoes($this->contaAlavanca(), 'MLB1', $qs, 100.0);
                $this->fail('devia lançar ALAV-B2B-01');
            } catch (RegraViolada $e) {
                $this->assertSame('ALAV-B2B-01', $e->regra);
            }
        }

        $this->assertSame([], $this->chamadasNaoGet());
    }
}
