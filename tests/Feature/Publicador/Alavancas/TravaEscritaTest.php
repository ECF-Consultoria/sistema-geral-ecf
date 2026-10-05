<?php

namespace Tests\Feature\Publicador\Alavancas;

use App\Models\PubAlavancaEscrita;
use App\Services\Publicador\Alavancas\EscritorAlavancas;
use App\Support\Publicador\AlavancasLiberadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publicador\Alavancas\Concerns\CenarioAlavancas;
use Tests\Feature\Publicador\Alavancas\Fakes\AcaoDeTeste;
use Tests\TestCase;

/** 166-03: a trava das Alavancas e o vendedor do token valem NO SERVIDOR, antes de qualquer escrita (D-03). */
class TravaEscritaTest extends TestCase
{
    use CenarioAlavancas;
    use RefreshDatabase;

    private function escritor(): EscritorAlavancas
    {
        return app(EscritorAlavancas::class);
    }

    private function acao(array $dados = []): AcaoDeTeste
    {
        return new AcaoDeTeste($this->contaAlavanca(), ['item_id' => 'MLB123', ...$dados]);
    }

    public function test_conta_fora_da_lista_vira_recusada_sem_nenhuma_chamada(): void
    {
        $this->montarAlavancas('company', liberada: false);

        $linha = $this->escritor()->executar($this->acao(), $this->admin);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-LIB', $linha->erro_codigo);
        $this->assertSame(AlavancasLiberadas::MOTIVO, $linha->mensagem);
        $this->assertSame([], $this->chamadas, 'nem /users/me pode sair');
    }

    public function test_liberada_so_na_publicacao_continua_recusada(): void
    {
        $this->montarAlavancas('company', liberada: false);
        config(['publicador.contas_liberadas' => ['companies' => [$this->ancora->id], 'mlb_empresas' => []]]);

        $linha = $this->escritor()->executar($this->acao(), $this->admin);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('ALAV-LIB', $linha->erro_codigo);
        $this->assertSame([], $this->chamadas);
    }

    public function test_token_de_outro_vendedor_nao_escreve(): void
    {
        $this->montarAlavancas('company');
        $this->usuario = [...self::fixtureSondagem('conta/usuario'), 'id' => 999];

        $linha = $this->escritor()->executar($this->acao(), $this->admin);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame('V-ACC-03', $linha->erro_codigo);
        $this->assertSame([], $this->chamadasNaoGet());
    }

    public function test_liberada_e_vendedor_certo_escreve_e_guarda_a_resposta_crua(): void
    {
        $this->montarAlavancas('company');
        $this->responder('POST', '#^/seller-promotions/items/MLB123$#', ['id' => 'P1', 'ok' => true]);

        $linha = $this->escritor()->executar($this->acao(), $this->admin, 'lote-1');

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
        $this->assertSame(200, $linha->http_status);
        $this->assertSame(['id' => 'P1', 'ok' => true], $linha->resposta);
        $this->assertSame('POST', $linha->metodo);
        $this->assertSame('/seller-promotions/items/MLB123', $linha->caminho);
        $this->assertSame('P1', $linha->promotion_id);
        $this->assertSame('lote-1', $linha->lote_uuid);
        $this->assertSame($this->ancora->id, $linha->company_id);
        $this->assertCount(1, $this->chamadasNaoGet());
    }

    public function test_vale_para_mlb_empresa_sem_company(): void
    {
        $this->montarAlavancas('mlb_empresa');
        $this->responder('POST', '#^/seller-promotions/items/MLB123$#', ['ok' => true]);

        $linha = $this->escritor()->executar($this->acao(), $this->admin);

        $this->assertSame(PubAlavancaEscrita::OK, $linha->resultado);
        $this->assertSame($this->ancora->id, $linha->mlb_empresa_id);
        $this->assertNull($linha->company_id);
    }

    public function test_mlb_empresa_nao_liberada_tambem_e_recusada(): void
    {
        $this->montarAlavancas('mlb_empresa', liberada: false);

        $linha = $this->escritor()->executar($this->acao(), $this->admin);

        $this->assertSame(PubAlavancaEscrita::RECUSADA, $linha->resultado);
        $this->assertSame([], $this->chamadas);
    }

    public function test_consulta_por_post_segue_a_mesma_trava(): void
    {
        $this->montarAlavancas('company', liberada: false);

        try {
            $this->escritor()->consultaPorPost($this->contaAlavanca(), '/prices-per-quantity/v1/recommendations', ['x' => 1]);
            $this->fail('devia recusar');
        } catch (\App\Support\Publicador\RegraViolada $e) {
            $this->assertSame('ALAV-LIB', $e->regra);
        }
        $this->assertSame([], $this->chamadas);
    }
}
