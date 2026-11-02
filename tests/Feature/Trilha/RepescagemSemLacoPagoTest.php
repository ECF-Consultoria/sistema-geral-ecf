<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\AvaliarCriativosAutomaticosJob;
use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\Configuracao;
use App\Models\EstruturaOferta;
use App\Models\EstruturaOfertaComponente;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Creative\CreativeEngineAtivo;
use App\Services\Publicador\CombosDoPlanejamentoService;
use App\Services\Publicador\Criativos\CriativosAutomaticosService;
use App\Services\Trilha\Ondas\SimplesPronta;
use App\Services\Trilha\Ondas\SoltarOndas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * A soltura das ondas não vira laço pago e não perde quem nunca começou (revisão de 02/11/2026). No caminho real do
 * preparo (`CenarioDoPreparo`: a cadeira com a ficha completa, a IA como dublê, a fila rodada à mão):
 *
 * - o Combo não pede o preparo do produto — se pedisse, a repescagem dele refaria, todo dia, a etapa paga que falhou
 *   na Simples;
 * - o item que bateu no teto do TEXTO volta no dia seguinte, mesmo com as imagens automáticas ligadas (a memória das
 *   imagens faz ele "andar" sem texto);
 * - a repescagem é uma vez só;
 * - a Simples só fica "pronta" para o Kit N depois que as imagens automáticas tiveram a vez delas.
 */
class RepescagemSemLacoPagoTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'America/Sao_Paulo'));
        $this->montarPreparo();
        config(['publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_o_combo_pede_so_o_preparo_dele_e_a_simples_que_falhou_nao_e_refeita_sozinha(): void
    {
        $p = $this->produtoDoPortal();
        $this->combo2($p->id);
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 1', 'data' => '2026-11-05']);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1]);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 2]);

        $dia1 = app(SoltarOndas::class)->rodar()[0];
        $this->assertEqualsCanonicalizing(["s-{$p->id}", "c-{$p->id}-2"], $dia1['pedidos']);
        // Um pedido por produto, com a IA restrita aos itens da onda.
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens !== null
            && array_values(array_diff($j->soItens, ["s-{$p->id}", "c-{$p->id}-2"])) === []);
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        $this->rodar(GerarPreparoIaJob::class);

        // A etapa do título falhou: a Simples andou e falhou — não volta sozinha.
        $r = $this->rascunhoDo($p);
        $estado = (array) $r->step_state;
        $estado['ia_preparo']['etapas']['titulo']['status'] = 'erro';
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);
        $antes = Queue::pushed(GerarPreparoIaJob::class)->count();

        foreach (['2026-11-06 00:07:00', '2026-11-07 00:07:00'] as $quando) {
            Carbon::setTestNow(Carbon::parse($quando, 'America/Sao_Paulo'));
            \App\Models\MlCategoriaSchema::query()->update(['fetched_at' => now()]);
            $r = app(SoltarOndas::class)->rodar()[0];
            // O combo (sem Kit N: a Simples falhou) pode ser repescado — mas o pedido leva só ELE, e a Simples que
            // falhou fica de fora: nenhuma etapa paga refeita de carona.
            $this->assertNotContains("s-{$p->id}", $r['repescados'], $quando);
            $this->rodar(PrepararProdutoNoPublicadorJob::class);
        }
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === ["c-{$p->id}-2"]);

        $this->assertSame($antes, Queue::pushed(GerarPreparoIaJob::class)->count(), 'nenhuma IA paga nova');
        $this->assertNull(Cache::get("publicador:preparo:cota:{$this->empresa->id}:2026-11-06"));
        $this->assertNull(Cache::get("publicador:preparo:cota:{$this->empresa->id}:2026-11-07"));
    }

    public function test_o_item_que_bateu_no_teto_do_texto_volta_uma_vez_mesmo_com_as_imagens_ligadas(): void
    {
        $this->ligarImagens();
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 0]);
        $p = $this->produtoDoPortal();
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 1', 'data' => '2026-11-05']);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1]);

        app(SoltarOndas::class)->rodar();
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        Queue::assertPushed(AvaliarCriativosAutomaticosJob::class);
        // As imagens rodaram e deixaram memória (aqui: barradas pelo teto de imagens do dia): o item "andou" sem texto.
        $r = $this->rascunhoDo($p);
        $estado = (array) $r->step_state;
        $estado[CriativosAutomaticosService::MEMORIA][CriativosAutomaticosService::ADIADO] = ['dia' => '2026-11-05', 'motivo' => 'limite'];
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);

        Carbon::setTestNow(Carbon::parse('2026-11-06 00:07:00', 'America/Sao_Paulo'));
        config(['publicador.preparo_ia.limite_diario_por_empresa' => 60]);
        $dia2 = app(SoltarOndas::class)->rodar()[0];

        $this->assertSame(["s-{$p->id}"], $dia2['repescados'], 'o texto nunca feito é pedido de novo');
        $this->rodar(PrepararProdutoNoPublicadorJob::class);
        Queue::assertPushed(GerarPreparoIaJob::class);

        // Uma vez só: no dia seguinte, mesmo que o texto não tenha saído, ninguém pede de novo.
        DB::table('pub_rascunhos')->where('id', $r->id)->update(['step_state' => json_encode($estado)]);
        Carbon::setTestNow(Carbon::parse('2026-11-07 00:07:00', 'America/Sao_Paulo'));
        $this->assertSame([], app(SoltarOndas::class)->rodar()[0]['repescados']);
    }

    public function test_a_simples_so_fica_pronta_para_o_kit_depois_da_vez_das_imagens(): void
    {
        $this->ligarImagens();
        $p = $this->produtoDoPortal();
        $this->combo2($p->id);
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda 1', 'data' => '2026-11-05']);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1]);
        TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 2]);

        // O texto roda; o elo das imagens (último da cadeia da descrição) fica na fila, sem memória ainda.
        $this->salvarERodar($p);
        $r = $this->rascunhoDo($p)->fresh();
        $this->assertSame('completo', $this->preparo()->situacaoDoTexto($r));
        $this->assertSame([], (array) (((array) $r->step_state)[CriativosAutomaticosService::MEMORIA] ?? []));

        $this->assertFalse(SimplesPronta::para($r), 'as imagens ainda não tiveram a vez delas');
        $this->assertFalse(app(CombosDoPlanejamentoService::class)->agendar((int) $this->pubDo($p)->id));
        Queue::assertNotPushed(CriarCombosDoPlanejamentoJob::class);

        // Sem memória passado o prazo (desfecho sem kit: sem foto, sem permissão…): pronta.
        Carbon::setTestNow(now()->addMinutes(SimplesPronta::ESPERA_DAS_IMAGENS_MIN + 1));
        \App\Models\MlCategoriaSchema::query()->update(['fetched_at' => now()]);
        $this->assertTrue(SimplesPronta::para($r->fresh()));
    }

    // ─── apoio ───

    private function combo2(int $produtoId): void
    {
        $simples = EstruturaOferta::where('company_id', $this->empresa->id)->where('fase', 'simples')->firstOrFail();
        $combo = EstruturaOferta::create(['company_id' => $this->empresa->id, 'variacao_id' => null, 'sku' => 'CAD-AZ-CB2', 'fase' => 'combo', 'nome' => 'Combo 2']);
        EstruturaOfertaComponente::create(['oferta_id' => $combo->id, 'componente_id' => $simples->id, 'quantidade' => 2]);
    }

    private function ligarImagens(): void
    {
        config(['publicador.criativos_auto.ativo' => true]);
        Configuracao::set(CriativosAutomaticosService::CHAVE_EMPRESAS, '*');
        $this->mock(CreativeEngineAtivo::class, fn ($m) => $m->shouldReceive('ativa')->andReturn(true));
    }
}
