<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\GerarPreparoIaJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Models\EstruturaProduto;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Trilha\Ondas\PortaoDaOnda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Publicador\Lote\CenarioDoPreparo;
use Tests\TestCase;

/**
 * A trava do agente na data da onda (Trilha de produtos, 2ª entrega, 02/11/2026): na empresa com a trilha de trabalho
 * ligada, salvar no Portal NÃO chama a IA da oferta que ainda não andou e não está numa onda que já começou — e o
 * item segurado não gasta nada (cota intacta, nenhuma memória gravada, nenhum Job da cadeia).
 *
 * O cenário é o do preparo do Publicador (`CenarioDoPreparo`: a cadeira com a ficha completa, a IA como dublê, a
 * fila `Queue::fake()` rodada à mão) — o caminho real do save até `avaliarIa`.
 */
class PortaoDaOndaTest extends TestCase
{
    use CenarioDoPreparo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O relógio antes do cenário: o schema da categoria é guardado "agora" e ficaria velho com o relógio adiantado.
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'America/Sao_Paulo'));
        $this->montarPreparo();
        // O preparo ligado aqui, e não pelo `.env` (uma máquina com `PUBLICADOR_PREPARO_IA_ATIVO=false` faria todo
        // teste "passar" sem a IA nunca ser chamada).
        config(['publicador.preparo_ia.ativo' => true, 'publicador.criativos_auto.ativo' => false]);
        config(['trilha.empresas' => [$this->empresa->id], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sem_onda_o_save_sincroniza_mas_nao_chama_a_ia_nem_gasta_a_cota(): void
    {
        $p = $this->produtoDoPortal();

        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['pronto'], $preparos, 'o preparo rodou (o produto chegou ao Publicador)');
        $this->assertSame([], $ia, 'nenhuma etapa da IA');
        Queue::assertNotPushed(GerarPreparoIaJob::class);
        $r = $this->rascunhoDo($p);
        $this->assertArrayNotHasKey('ia_preparo', (array) $r->step_state, 'nada gravado na memória do preparo');
        $this->assertNull(Cache::get($this->chaveDaCota()), 'a cota do dia não foi tocada');
        $this->assertSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
    }

    public function test_numa_onda_de_amanha_tambem_espera(): void
    {
        $p = $this->produtoDoPortal();
        $this->naOnda($p, '2026-11-06');

        [, $ia] = $this->salvarERodar($p);

        $this->assertSame([], $ia);
        $this->assertSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
    }

    public function test_numa_onda_que_comeca_hoje_a_ia_prepara(): void
    {
        $p = $this->produtoDoPortal();
        $this->naOnda($p, '2026-11-05');

        [$preparos, $ia] = $this->salvarERodar($p);

        $this->assertSame(['pronto'], $preparos);
        $this->assertNotSame([], $ia, 'a cadeia da IA rodou');
        $this->assertArrayHasKey('ia_preparo', (array) $this->rascunhoDo($p)->fresh()->step_state);
    }

    public function test_o_que_ja_andou_nao_e_segurado_mesmo_fora_de_onda(): void
    {
        $p = $this->produtoDoPortal();
        $linha = $this->naOnda($p, '2026-11-05');
        $this->salvarERodar($p);
        // A oferta saiu da onda (alguém apagou a linha no banco): o rascunho já tem o preparo, então andou.
        $linha->delete();

        $this->assertNotSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
    }

    public function test_fora_da_chave_de_trabalho_tudo_segue_como_antes(): void
    {
        // Só a porta da trilha aberta (a 1ª entrega): o agente não muda.
        config(['trilha.trabalho.empresas' => []]);
        $p = $this->produtoDoPortal();

        [, $ia] = $this->salvarERodar($p);

        $this->assertNotSame([], $ia, 'a IA preparou como sempre');
        $this->assertSame(1, (int) Cache::get($this->chaveDaCota()), 'a cota foi gasta como sempre');
    }

    public function test_empresa_na_chave_de_trabalho_mas_fora_da_trilha_tambem_segue_como_antes(): void
    {
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [$this->empresa->id]]);
        $p = $this->produtoDoPortal();

        [, $ia] = $this->salvarERodar($p);

        $this->assertNotSame([], $ia);
    }

    public function test_publicado_continua_intocavel_e_o_kit_da_fase_segue_o_fluxo_dele(): void
    {
        $p = $this->produtoDoPortal();
        $this->salvarERodar($p);
        $pub = $this->pubDo($p);

        $kit = PubProduto::create(['company_id' => $this->empresa->id, 'produto_base_id' => $pub->id, 'quantidade_kit' => 2,
            'sku' => 'CAD-CB2', 'nome' => 'Kit 2', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);
        PubRascunho::create(['produto_id' => $kit->id, 'status' => PubRascunho::DRAFT, 'revisao' => 1, 'step_state' => []]);
        $this->assertSame('kit_da_fase', $this->preparo()->avaliarIa($kit));

        $this->rascunhoDo($p)->update(['status' => PubRascunho::PUBLISHED]);
        $this->assertSame('intocavel', $this->preparo()->avaliarIa($pub));
    }

    public function test_erro_ao_ler_a_onda_segura_e_registra(): void
    {
        $p = $this->produtoDoPortal();
        // O Sincronizar cria o pub e o rascunho; a IA fica segurada (sem onda).
        $this->salvarERodar($p);
        Schema::drop('trilha_onda_itens');
        Log::spy();

        $this->assertSame(PortaoDaOnda::AGUARDANDO, $this->preparo()->avaliarIa($this->pubDo($p)));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '[Trilha] Trava da onda'));
    }

    public function test_o_preparo_agendado_que_acorda_antes_da_data_continua_segurado(): void
    {
        // O Job do save fica na fila; a onda é de amanhã. Ele acorda e não chama a IA.
        $p = $this->produtoDoPortal();
        $this->naOnda($p, '2026-11-06');
        app(\App\Services\Publicador\PreparoIaAgenda::class)->aoSalvar((int) $p->company_id, [$p->id]);

        $this->assertSame(['pronto'], $this->rodar(PrepararProdutoNoPublicadorJob::class));
        Queue::assertNotPushed(GerarPreparoIaJob::class);
    }

    // ─── apoio ───

    private function naOnda(EstruturaProduto $p, string $data): TrilhaOndaItem
    {
        $onda = TrilhaOnda::create(['company_id' => $this->empresa->id, 'nome' => 'Onda '.$data, 'data' => $data]);

        return TrilhaOndaItem::create(['onda_id' => $onda->id, 'company_id' => $this->empresa->id, 'produto_id' => $p->id, 'quantidade' => 1]);
    }

    private function chaveDaCota(): string
    {
        return "publicador:preparo:cota:{$this->empresa->id}:".now()->format('Y-m-d');
    }
}
