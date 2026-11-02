<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Models\PubAgenteExecucao;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Publicador\Agente\EntradaDoPreparo;
use App\Services\Publicador\Agente\ExecucoesDoAgente;
use App\Services\Publicador\Agente\PoliticaDoAgente;
use App\Services\Publicador\Agente\VarreduraDoAgente;
use App\Support\Publicador\RegraViolada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Agente\CenarioDoAgente;
use Tests\TestCase;

/**
 * O agente da Aprovação na empresa com a trilha de trabalho ligada (2ª entrega, 02/11/2026): ele também espera a
 * onda. O "Preparar agora" da oferta segurada é recusado com a data (sem criar execução nem ocupar vaga), a varredura
 * pula a empresa, e a execução que chegue ao preparo por outro caminho (Retomar, recuperação) encerra BLOQUEADA com
 * o motivo — nunca fica "preparando" à espera de um texto que não vem.
 */
class AgenteNaOndaTest extends TestCase
{
    use CenarioDoAgente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', TrilhaOnda::FUSO));
        $this->montarAgente();
        config(['publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_preparar_agora_da_oferta_que_espera_a_onda_e_recusado_com_a_data(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 2', 'data' => '2026-11-10']);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $pub->estrutura_produto_id, 'quantidade' => 1]);

        try {
            app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
            $this->fail('o pedido devia ser recusado');
        } catch (RegraViolada $e) {
            $this->assertSame(PoliticaDoAgente::REGRA_ONDA, $e->regra);
            $this->assertSame('Esta oferta espera a Onda 2, que começa em 10/11. Para adiantar, mude a data da onda na trilha.', $e->getMessage());
        }
        $this->assertSame(0, PubAgenteExecucao::count(), 'nenhuma execução criada');
        Queue::assertNotPushed(GerarPreparoIaJob::class);

        // Chegou a data: o mesmo pedido é admitido e a IA prepara. (O schema guardado acompanha o relógio, senão
        // ele "venceria" e o teste iria ao ML.)
        Carbon::setTestNow(Carbon::parse('2026-11-10 08:00:00', TrilhaOnda::FUSO));
        \App\Models\MlCategoriaSchema::query()->update(['fetched_at' => now()]);
        $r = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
        $this->assertTrue($r['nova']);
        Queue::assertPushed(GerarPreparoIaJob::class);
    }

    public function test_fora_de_onda_a_mensagem_diz_que_falta_a_onda(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();

        $this->expectExceptionMessage('Esta oferta ainda não está numa onda da trilha.');
        app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
    }

    public function test_a_varredura_pula_a_empresa_da_trilha(): void
    {
        $this->ligar();
        $this->noPublicador();

        $r = app(VarreduraDoAgente::class)->rodar();

        $this->assertSame(0, $r['lojas']);
        $this->assertSame(0, $r['pedidos']);
        $this->assertSame(0, PubAgenteExecucao::count());
    }

    public function test_execucao_que_chega_ao_preparo_por_fora_da_politica_encerra_bloqueada(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        // Uma execução já admitida (antes de a chave ligar) que o "Retomar" continua.
        config(['trilha.trabalho.empresas' => []]);
        $execucao = app(ExecucoesDoAgente::class)->admitir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin)['execucao'];
        config(['trilha.trabalho.empresas' => [$this->empresa->id]]);

        app(EntradaDoPreparo::class)->continuar($execucao);

        $execucao->refresh();
        $this->assertSame(PubAgenteExecucao::BLOQUEADA, $execucao->estado);
        $this->assertStringContainsString('onda', (string) $execucao->motivo);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
    }

    public function test_erro_ao_ler_a_onda_recusa_com_aviso_e_nunca_vira_erro_500(): void
    {
        $this->ligar();
        $pub = $this->noPublicador();
        \Illuminate\Support\Facades\Schema::drop('trilha_onda_itens');

        try {
            app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);
            $this->fail('o pedido devia ser recusado');
        } catch (RegraViolada $e) {
            $this->assertSame(PoliticaDoAgente::REGRA_ONDA, $e->regra);
            $this->assertSame('A trilha não conseguiu conferir a onda desta oferta agora. Tente de novo em instantes.', $e->getMessage());
        }
        $this->assertSame(0, PubAgenteExecucao::count());
        Queue::assertNotPushed(GerarPreparoIaJob::class);
    }

    public function test_fora_da_chave_o_agente_segue_como_antes(): void
    {
        config(['trilha.trabalho.empresas' => []]);
        $this->ligar();
        $pub = $this->noPublicador();

        $r = app(EntradaDoPreparo::class)->pedir($this->conta(), $pub, PubAgenteExecucao::ORIGEM_MANUAL, $this->admin);

        $this->assertTrue($r['nova']);
        Queue::assertPushed(GerarPreparoIaJob::class);
    }
}
