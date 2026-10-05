<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\Acoes\GravarFaixasAtacado;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\TestCase;

/** 166-09: gravar faixas de atacado % B2B com X-Version relida, sem repetir o 409. */
class AtacadoTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    /** Quando verdadeiro, o POST responde 409 item.version. */
    private bool $versaoMudou = false;

    /** Corpo do GET /prices que o servidor de mentira devolve. */
    private array $precos = [];

    private function cenario(string $leitura = 'prices_com_faixas', bool $liberada = true): void
    {
        $this->montarAlavancas('company', $liberada);
        $this->precos = self::fixtureAlavanca("doc/atacado/{$leitura}");
        $this->responder('GET', '#^/items/MLB1/prices$#', fn () => Http::response($this->precos, 200));
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => []]);
        $this->responder('POST', '#^/items/MLB1/prices/price-per-quantity$#', fn (Request $r) => $this->versaoMudou
            ? Http::response(['error' => 'The version provided is not the current one. Please fetch the item again', 'code' => 'item.version', 'status' => 409], 409)
            : Http::response(self::fixtureAlavanca('doc/atacado/post_pxq_ok'), 200));
        $this->responder('POST', '#^/prices-per-quantity/v1/recommendations$#', self::fixtureAlavanca('doc/atacado/recommendations'));
    }

    private function acao(array $dados): GravarFaixasAtacado
    {
        return new GravarFaixasAtacado($this->contaAlavanca(), ['item_id' => 'MLB1', ...$dados]);
    }

    private function gravar(array $dados): PubAlavancaEscrita
    {
        return app(EscritorAlavancas::class)->executar($this->acao($dados), $this->admin);
    }

    private function posts(): array
    {
        return array_values(array_filter($this->chamadasNaoGet(), fn ($c) => $c['caminho'] === '/items/MLB1/prices/price-per-quantity'));
    }

    private function faixa(float $p, int $q, ?string $id = null): array
    {
        return ['id' => $id, 'percentual' => $p, 'quantidade_minima' => $q];
    }

    public function test_grava_com_get_de_versao_imediatamente_antes_e_x_version_igual(): void
    {
        $this->cenario('prices_display_version');
        $this->precos['version'] = 21;

        $linha = $this->gravar(['faixas' => [$this->faixa(3.63, 2), $this->faixa(9.29, 4)]]);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $n = count($this->chamadas);
        $penultima = $this->chamadas[$n - 2];
        $ultima = $this->chamadas[$n - 1];
        $this->assertSame('GET', $penultima['metodo']);
        $this->assertSame('/items/MLB1/prices', $penultima['caminho']);
        $this->assertSame('true', $penultima['query']['display_version']);
        $this->assertSame('true', $penultima['cabecalhos']['show-all-prices'][0]);
        $this->assertSame('POST', $ultima['metodo']);
        $this->assertSame('/items/MLB1/prices/price-per-quantity', $ultima['caminho']);
        $this->assertSame('21', $ultima['cabecalhos']['X-Version'][0]);
        $this->assertArrayNotHasKey('remove-absolute-pxq', $ultima['query']);

        $ctx = ['channel_marketplace', 'user_type_business'];
        $this->assertSame(['price_per_quantity' => [
            ['type' => 'discount_percentage', 'percentage' => 3.63, 'conditions' => ['context_restrictions' => $ctx, 'min_purchase_unit' => 2, 'eligible' => true]],
            ['type' => 'discount_percentage', 'percentage' => 9.29, 'conditions' => ['context_restrictions' => $ctx, 'min_purchase_unit' => 4, 'eligible' => true]],
        ]], $ultima['corpo']);
    }

    public function test_faixa_mantida_vai_so_com_id_e_alterada_perde_o_id(): void
    {
        $this->cenario();

        $linha = $this->gravar(['faixas' => [$this->faixa(3.63, 2, '12'), $this->faixa(11, 4, '13')]]);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado, (string) $linha->mensagem);
        $corpo = $this->posts()[0]['corpo']['price_per_quantity'];
        $this->assertSame(['id' => '12'], $corpo[0]);
        $this->assertArrayNotHasKey('id', $corpo[1]);
        $this->assertSame(11.0, (float) $corpo[1]['percentage']);
    }

    public function test_lista_vazia_apaga_todas_e_avisa(): void
    {
        $this->cenario();

        $resumo = $this->acao(['faixas' => []])->resumo();
        $this->assertContains('Todas as faixas serão apagadas.', $resumo['avisos']);

        $linha = $this->gravar(['faixas' => []]);
        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
        $this->assertSame(['price_per_quantity' => []], $this->posts()[0]['corpo']);
    }

    public function test_absoluto_sem_confirmacao_e_recusado_e_com_confirmacao_leva_a_query(): void
    {
        $this->cenario('prices_display_version');
        $this->precos['prices'][] = ['id' => '5', 'type' => 'standard', 'amount' => 90, 'currency_id' => 'BRL',
            'conditions' => ['context_restrictions' => ['user_type_business'], 'min_purchase_unit' => 5]];

        $recusada = $this->gravar(['faixas' => [$this->faixa(5, 2)]]);
        $this->assertSame(PubAlavancaEscrita::RECUSADA, $recusada->resultado);
        $this->assertSame('ALAV-B2B-07', $recusada->erro_codigo);
        $this->assertSame([], $this->posts());

        $resumo = $this->acao(['faixas' => [$this->faixa(5, 2)], 'remover_absoluto' => true])->resumo();
        $this->assertContains('Vai substituir as faixas em valor fixo atuais.', $resumo['avisos']);

        $ok = $this->gravar(['faixas' => [$this->faixa(5, 2)], 'remover_absoluto' => true]);
        $this->assertSame(PubAlavancaEscrita::OK, $ok->resultado, (string) $ok->mensagem);
        $this->assertSame('true', $this->posts()[0]['query']['remove-absolute-pxq']);
    }

    public function test_id_inexistente_e_recusado(): void
    {
        $this->cenario();

        $linha = $this->gravar(['faixas' => [$this->faixa(3.63, 2, '99')]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-08', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }

    public function test_conta_sem_business_e_recusada(): void
    {
        $this->cenario();
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'tags' => ['normal']];

        $linha = $this->gravar(['faixas' => [$this->faixa(5, 2)]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-02', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }

    public function test_regra_de_faixa_violada_e_recusada_sem_post(): void
    {
        $this->cenario();

        $linha = $this->gravar(['faixas' => [$this->faixa(10, 2), $this->faixa(8, 5)]]);

        $this->assertSame('ALAV-B2B-06', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }

    public function test_409_de_versao_vira_erro_com_um_unico_post(): void
    {
        $this->cenario('prices_display_version');
        $this->versaoMudou = true;

        $linha = $this->gravar(['faixas' => [$this->faixa(5, 2)]]);

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertSame(409, $linha->http_status);
        $this->assertCount(1, $this->posts(), 'o 409 não é repetido');
        $this->assertStringStartsWith('Alguém alterou o preço deste anúncio enquanto você revisava', (string) $linha->mensagem);
    }

    public function test_400_5599_vira_erro_com_a_mensagem_do_recomendado(): void
    {
        $this->cenario('prices_display_version');
        $this->responder('POST', '#^/items/MLB1/prices/price-per-quantity$#',
            ['code' => 'prices.validator.validation.failed', 'cause_id' => 5599, 'error' => 'Amount above recommended', 'status' => 400], 400);

        $linha = $this->gravar(['faixas' => [$this->faixa(1, 2)]]);

        $this->assertSame(PubAlavancaEscrita::ERRO, $linha->resultado);
        $this->assertStringContainsString('menor que o recomendado', (string) $linha->mensagem);
        $this->assertCount(1, $this->posts());
    }

    public function test_preparo_sem_versao_e_recusada_sem_post(): void
    {
        $this->cenario('prices_display_version');
        unset($this->precos['version']);

        $linha = $this->gravar(['faixas' => [$this->faixa(5, 2)]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-09', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }

    public function test_resumo_em_conta_liberada_traz_recomendacoes_e_so_posta_a_consulta(): void
    {
        $this->cenario('prices_display_version');

        $resumo = $this->acao(['faixas' => [$this->faixa(4, 2), $this->faixa(8, 5), $this->faixa(9, 10)]])->resumo();

        $this->assertCount(3, $resumo['recomendacoes']);
        $this->assertTrue($resumo['recomendacoes'][2]['incoerente']);
        $naoGet = $this->chamadasNaoGet();
        $this->assertCount(1, $naoGet);
        $this->assertSame('/prices-per-quantity/v1/recommendations', $naoGet[0]['caminho']);
        $this->assertCount(3, $resumo['linhas']);
        $this->assertStringContainsString('A partir de 2 unidades', $resumo['linhas'][0]['rotulo']);
    }

    public function test_resumo_em_conta_nao_liberada_nao_tem_recomendacoes_nem_post(): void
    {
        $this->cenario('prices_display_version', liberada: false);

        $resumo = $this->acao(['faixas' => [$this->faixa(4, 2)]])->resumo();

        $this->assertNull($resumo['recomendacoes']);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_nenhuma_requisicao_vai_para_o_endpoint_absoluto(): void
    {
        $this->cenario('prices_display_version');

        $this->gravar(['faixas' => [$this->faixa(4, 2)]]);
        $this->acao(['faixas' => [$this->faixa(4, 2)]])->resumo();

        foreach ($this->chamadas as $c) {
            $this->assertStringNotContainsString('standard/quantity', $c['caminho']);
        }
    }

    public function test_leitura_sem_faixas_conhecidas_recusa_sem_post_e_nao_apaga_nada(): void
    {
        $this->cenario('prices_display_version');
        // Anúncio com a tag de atacado, mas a resposta de preços sem `price_per_quantity` (faixas desconhecidas).
        $this->responder('GET', '#^/items/MLB1$#', ['id' => 'MLB1', 'tags' => ['standard_price_by_quantity']]);

        $linha = $this->gravar(['faixas' => [$this->faixa(5, 2)]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-10', $linha->erro_codigo);
        $this->assertStringContainsString('Nada foi enviado', (string) $linha->mensagem);
        $this->assertSame([], $this->posts());
        $this->assertNull($linha->enviado_em);
    }

    public function test_preparo_sem_faixas_no_get_relido_recusa_sem_post(): void
    {
        $this->cenario();
        $chamadas = 0;
        $this->responder('GET', '#^/items/MLB1/prices$#', function () use (&$chamadas) {
            $chamadas++;
            $corpo = $this->precos;
            if ($chamadas > 1) {
                unset($corpo['price_per_quantity']); // o GET do preparo vem sem as faixas
            }

            return Http::response($corpo, 200);
        });

        $linha = $this->gravar(['faixas' => [$this->faixa(3.63, 2, '12')]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-10', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }

    public function test_faixas_mudaram_entre_a_conferencia_e_o_preparo_recusa_sem_post(): void
    {
        $this->cenario();
        $chamadas = 0;
        $this->responder('GET', '#^/items/MLB1/prices$#', function () use (&$chamadas) {
            $chamadas++;
            $corpo = $this->precos;
            if ($chamadas > 1) {
                array_pop($corpo['price_per_quantity']); // alguém excluiu uma faixa no meio do caminho
            }

            return Http::response($corpo, 200);
        });

        $linha = $this->gravar(['faixas' => [$this->faixa(3.63, 2, '12')]]);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-B2B-08', $linha->erro_codigo);
        $this->assertSame([], $this->posts());
    }
}
