<?php

namespace Tests\Feature\Trilha;

use App\Jobs\Publicador\CriarCombosDoPlanejamentoJob;
use App\Jobs\Publicador\PrepararProdutoNoPublicadorJob;
use App\Jobs\Publicador\SincronizarProdutoDoPortalJob;
use App\Models\Company;
use App\Models\EstruturaOferta;
use App\Models\PubRascunho;
use App\Models\TrilhaOnda;
use App\Models\TrilhaOndaItem;
use App\Services\Trilha\Ondas\SoltarOndas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Trilha\Concerns\MontaTrilha;
use Tests\TestCase;

/**
 * A data da onda solta o agente (2ª entrega, 02/11/2026): a passada de `trilha:soltar-ondas` grava `liberada_em` uma vez,
 * pede o preparo pelo MESMO caminho de um save (`PreparoIaAgenda::aoSalvar` → Sincronizar + preparo com a marca do
 * debounce), respeita o lote, repesca uma vez por dia só quem nunca começou e limpa a linha de combo órfã.
 */
class SolturaDaOndaTest extends TestCase
{
    use MontaTrilha;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        config(['trilha.empresas' => [], 'trilha.trabalho.empresas' => [], 'publicador.preparo_ia.ativo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_onda_solta_as_00h07_do_dia_e_nao_na_vespera(): void
    {
        [$empresa, $a, $b, $kit] = $this->cenario();
        $onda = $this->onda($empresa, '2026-11-06', 'Onda 1');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}");
        $this->itemNaOnda($onda, "o-{$kit->id}");

        $this->agora('2026-11-05 23:59:00');
        $this->soltura()->rodar();
        $this->assertNull($onda->fresh()->liberada_em);
        Queue::assertNothingPushed();

        $this->agora('2026-11-06 00:07:00');
        $resumo = $this->soltura()->rodar();

        $this->assertNotNull($onda->fresh()->liberada_em);
        $this->assertSame(['Onda 1'], $resumo[0]['soltas']);
        $this->assertSame(2, TrilhaOndaItem::whereNotNull('pedido_em')->count());
        // O kit pede o preparo dos produtos das peças; o Sincronizar de cada um leva o kit junto.
        $pedidos = Queue::pushed(PrepararProdutoNoPublicadorJob::class)->map(fn ($j) => $j->estruturaProdutoId)->sort()->values()->all();
        $this->assertSame([(int) $a['produto']->id, (int) $b['produto']->id], $pedidos);
        Queue::assertPushed(SincronizarProdutoDoPortalJob::class, 2);
        $this->assertDatabaseHas('activity_log', ['description' => 'Onda Onda 1 começou (2026-11-06): o agente começa a preparar as ofertas dela']);

        // A segunda passada do mesmo dia não pede nada de novo.
        $this->soltura()->rodar();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);
    }

    public function test_sem_aplicar_nada_e_gravado_e_nada_sai(): void
    {
        [$empresa, $a] = $this->cenario();
        $onda = $this->onda($empresa, '2026-11-06');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}");
        $this->agora('2026-11-06 00:07:00');

        $resumo = $this->soltura()->rodar(aplicar: false);

        $this->assertSame(["s-{$a['produto']->id}"], $resumo[0]['pedidos']);
        $this->assertNull($onda->fresh()->liberada_em);
        $this->assertSame(0, TrilhaOndaItem::whereNotNull('pedido_em')->count());
        Queue::assertNothingPushed();
    }

    public function test_o_lote_limita_os_produtos_por_passada_e_o_resto_vai_na_seguinte(): void
    {
        config(['trilha.ondas.lote' => 2]);
        $empresa = $this->empresaDaTrilha();
        $onda = $this->onda($empresa, '2026-11-06');
        foreach (['A', 'B', 'C'] as $letra) {
            $p = $this->produtoCompleto($empresa, "Vaso {$letra}", "V{$letra}-1");
            $this->itemNaOnda($onda, "s-{$p['produto']->id}");
        }
        $this->agora('2026-11-06 00:07:00');

        $this->soltura()->rodar();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 2);

        $this->agora('2026-11-06 00:22:00');
        $this->soltura()->rodar();
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, 3);
        $this->assertSame(3, TrilhaOndaItem::whereNotNull('pedido_em')->count());
    }

    public function test_repesca_uma_vez_so_quem_nunca_comecou(): void
    {
        [$empresa, $a, $b] = $this->cenario();
        $onda = $this->onda($empresa, '2026-11-06', 'Onda 1', '2026-11-06 00:07:00');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}", '2026-11-06 00:07:00');
        $this->itemNaOnda($onda, "s-{$b['produto']->id}", '2026-11-06 00:07:00');
        // B começou (o preparo gravou a etapa): não volta sozinho.
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $b), PubRascunho::DRAFT, [
            'ia_preparo' => ['hash' => 'h', 'etapas' => ['titulo' => ['status' => 'erro', 'em' => '2026-11-06T00:10:00-03:00']]],
        ]);

        $this->agora('2026-11-06 15:07:00');
        $this->soltura()->rodar();
        Queue::assertNothingPushed();

        $this->agora('2026-11-07 00:07:00');
        $resumo = $this->soltura()->rodar();
        $this->assertSame(["s-{$a['produto']->id}"], $resumo[0]['repescados']);
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $a['produto']->id);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->estruturaProdutoId === (int) $b['produto']->id);

        // Passados os dias da repescagem, ninguém é pedido de novo.
        $this->agora('2026-11-20 00:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados']);
    }

    public function test_combo_de_onda_que_comecou_pede_o_kit_n_e_a_linha_orfa_e_limpa(): void
    {
        [$empresa, $a] = $this->cenario();
        $combo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $a), PubRascunho::PUBLISHED);
        $onda = $this->onda($empresa, '2026-11-06');
        $this->itemNaOnda($onda, "c-{$a['produto']->id}-2");
        $this->itemNaOnda($onda, "c-{$a['produto']->id}-3");
        $this->agora('2026-11-06 00:07:00');

        $resumo = $this->soltura()->rodar();

        // A Simples está publicada: o Kit 2 é agendado.
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class);
        // O Combo 3 não existe (nenhuma cor): a linha sai.
        $this->assertSame(["c-{$a['produto']->id}-3"], $resumo[0]['orfaos']);
        $this->assertSame(0, TrilhaOndaItem::where('quantidade', 3)->count());
        $this->assertSame(1, TrilhaOndaItem::where('quantidade', 2)->count());
        unset($combo);
    }

    public function test_item_posto_numa_onda_antiga_conta_a_janela_da_entrada_e_e_repescado_uma_vez(): void
    {
        [$empresa, $a] = $this->cenario();
        // A onda começou há 10 dias; o item entra hoje (fora da janela de 7 dias se ela contasse do dia da onda).
        $velha = $this->onda($empresa, '2026-10-27', 'Onda Velha', '2026-10-27 00:07:00');
        $this->agora('2026-11-06 09:00:00');
        $linha = $this->itemNaOnda($velha, "s-{$a['produto']->id}");
        $linha->update(['na_onda_desde' => now()]);

        $this->assertSame(["s-{$a['produto']->id}"], $this->soltura()->rodar()[0]['pedidos']);

        $this->agora('2026-11-07 00:07:00');
        $this->assertSame(["s-{$a['produto']->id}"], $this->soltura()->rodar()[0]['repescados'], 'a janela conta da entrada');
        $this->assertNotNull($linha->fresh()->repescado_em);

        $this->agora('2026-11-08 00:07:00');
        $this->assertSame([], $this->soltura()->rodar()[0]['repescados'], 'uma vez só');
    }

    public function test_linha_de_combo_orfa_em_onda_futura_tambem_e_limpa(): void
    {
        [$empresa, $a] = $this->cenario();
        $combo = $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $futura = $this->onda($empresa, '2026-11-20', 'Onda Futura');
        $this->itemNaOnda($futura, "c-{$a['produto']->id}-2");
        $this->agora('2026-11-06 00:07:00');

        // O combo é apagado por FORA do Eloquent (o TrilhaComboExcluidoObserver não vê): a rede de segurança da soltura
        // tira a linha na passada seguinte, mesmo com a onda longe de começar.
        DB::table('estrutura_oferta_componentes')->where('oferta_id', $combo->id)->delete();
        DB::table('estrutura_ofertas')->where('id', $combo->id)->delete();
        $this->assertSame(["c-{$a['produto']->id}-2"], $this->soltura()->rodar()[0]['orfaos']);
        $this->assertSame(0, TrilhaOndaItem::count());
    }

    public function test_o_kit_n_e_pedido_no_maximo_uma_vez_por_hora(): void
    {
        [$empresa, $a] = $this->cenario();
        $this->ofertaComposta($empresa, EstruturaOferta::FASE_COMBO, 'VA-1-CB2', 'Combo 2 Vaso Alfa', [[$a['simples'][0], 2]]);
        $this->rascunhoDoPub($this->grupoNoPublicador($empresa, $a), PubRascunho::PUBLISHED);
        $this->itemNaOnda($this->onda($empresa, '2026-11-06'), "c-{$a['produto']->id}-2");

        foreach (['2026-11-06 00:07:00', '2026-11-06 00:22:00', '2026-11-06 00:37:00'] as $quando) {
            $this->agora($quando);
            $this->soltura()->rodar();
        }
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 1);

        $this->agora('2026-11-06 01:22:00');
        $this->soltura()->rodar();
        Queue::assertPushed(CriarCombosDoPlanejamentoJob::class, 2);
        // O combo pede o preparo do produto só para ele (a IA não refaz a Simples nem os kits de carona).
        Queue::assertPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === ["c-{$a['produto']->id}-2"]);
        Queue::assertNotPushed(PrepararProdutoNoPublicadorJob::class, fn ($j) => $j->soItens === null);
    }

    public function test_empresa_fora_da_chave_de_trabalho_nao_e_tocada(): void
    {
        $empresa = $this->empresaDaTrilha(trabalho: false);
        $p = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $onda = $this->onda($empresa, '2026-11-06');
        $this->itemNaOnda($onda, "s-{$p['produto']->id}");
        $this->agora('2026-11-06 00:07:00');

        $this->assertSame([], $this->soltura()->rodar());
        $this->assertNull($onda->fresh()->liberada_em);
        Queue::assertNothingPushed();
    }

    public function test_o_comando_sem_aplicar_so_mostra(): void
    {
        [$empresa, $a] = $this->cenario();
        $onda = $this->onda($empresa, '2026-11-06', 'Onda 1');
        $this->itemNaOnda($onda, "s-{$a['produto']->id}");
        $this->agora('2026-11-06 00:07:00');

        $this->artisan('trilha:soltar-ondas')->expectsOutputToContain('[só leitura]')->assertExitCode(0);
        $this->assertNull($onda->fresh()->liberada_em);

        $this->artisan('trilha:soltar-ondas', ['--aplicar' => true])->assertExitCode(0);
        $this->assertNotNull($onda->fresh()->liberada_em);
        $this->artisan('trilha:soltar-ondas', ['--empresa' => 'abc'])->assertExitCode(2);
    }

    public function test_agendado_aos_minutos_7_22_37_52_e_so_roda_com_a_chave(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'trilha:soltar-ondas'));

        $this->assertNotNull($evento, 'a soltura das ondas não está no routes/console.php');
        $this->assertStringContainsString('--aplicar', (string) $evento->command);
        $this->assertSame('7,22,37,52 * * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
        $this->assertTrue($evento->withoutOverlapping);
        $this->assertStringContainsString('trilha-ondas.log', (string) $evento->output);

        $this->assertFalse($evento->filtersPass($this->app), 'sem empresa na chave, não roda');
        $this->empresaDaTrilha();
        $this->assertTrue($evento->filtersPass($this->app));
    }

    public function test_a_chave_de_trabalho_nasce_vazia(): void
    {
        $antes = getenv('TRILHA_TRABALHO_EMPRESAS');
        putenv('TRILHA_TRABALHO_EMPRESAS');
        unset($_ENV['TRILHA_TRABALHO_EMPRESAS'], $_SERVER['TRILHA_TRABALHO_EMPRESAS']);
        try {
            $this->assertSame([], (require base_path('config/trilha.php'))['trabalho']['empresas']);

            putenv('TRILHA_TRABALHO_EMPRESAS=459, abc,0,12');
            $this->assertSame([459, 12], (require base_path('config/trilha.php'))['trabalho']['empresas']);
        } finally {
            putenv($antes === false ? 'TRILHA_TRABALHO_EMPRESAS' : "TRILHA_TRABALHO_EMPRESAS={$antes}");
        }
    }

    // ─── apoio ───

    /** @return array{0: Company, 1: array, 2: array, 3: EstruturaOferta} */
    private function cenario(): array
    {
        $empresa = $this->empresaDaTrilha();
        $a = $this->produtoCompleto($empresa, 'Vaso Alfa', 'VA-1');
        $b = $this->produtoCompleto($empresa, 'Banqueta Beta', 'BB-1');
        $kit = $this->ofertaComposta($empresa, EstruturaOferta::FASE_KIT, 'KT-VA-1-BB-1', 'Kit Alfa e Beta', [[$a['simples'][0], 1], [$b['simples'][0], 1]]);

        return [$empresa, $a, $b, $kit];
    }

    private function agora(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, TrilhaOnda::FUSO));
    }

    private function soltura(): SoltarOndas
    {
        return app(SoltarOndas::class);
    }
}
