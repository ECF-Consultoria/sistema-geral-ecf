<?php

namespace Tests\Feature\Publicador\Lote;

use App\Jobs\Publicador\ConferirEmLoteJob;
use App\Models\Company;
use App\Models\PubProduto;
use App\Models\PubRascunho;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\Fila\ConferenciaEmLoteService;
use App\Services\Publicador\Fila\ResumoRapidoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Conferir selecionados" (10/10/2026): um `ConferirEmLoteJob` por produto, espaçados 10 s na fila `high`; cada um
 * abre o rascunho, aplica a regra do frete grátis obrigatório IGUAL à do editor e confere com o ML (simulado). O
 * que está na fila, publicado ou é de outra conta não confere.
 */
class ConferirEmLoteTest extends TestCase
{
    use CenarioDaFila;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00', 'America/Sao_Paulo'));
        Queue::fake();
        Notification::fake();
        $this->montarFila(3);
    }

    private function conferirPelaTela(array $produtos): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson($this->rotaLote('conferir'), ['produtos' => array_map(fn (PubProduto $p) => $p->id, $produtos)]);
    }

    public function test_um_job_por_produto_espacados_de_10_segundos_na_fila_high(): void
    {
        $r = $this->conferirPelaTela($this->cadeiras)->assertStatus(202)->json();

        $this->assertSame(array_map(fn ($p) => $p->id, $this->cadeiras), $r['enfileirados']);
        $this->assertSame('Conferindo 3 produtos com o Mercado Livre, um a cada 10 segundos.', $r['mensagem']);
        Queue::assertPushed(ConferirEmLoteJob::class, 3);
        Queue::assertPushedOn('high', ConferirEmLoteJob::class);
        $atrasos = collect(Queue::pushed(ConferirEmLoteJob::class))->map(fn ($j) => (int) round(now()->diffInSeconds($j->delay, true)))->sort()->values()->all();
        $this->assertSame([0, 10, 20], $atrasos);
        $this->assertTrue(collect($r['linhas'])->every(fn ($l) => $l['conferindo'] === true), 'a linha mostra "conferindo…" até o job terminar');
        $this->assertSame(0, count(Http::recorded()), 'o clique não fala com o ML: quem fala é o job');

        // Dois cliques não dobram as conferências (um job por produto).
        $this->conferirPelaTela($this->cadeiras);
        Queue::assertPushed(ConferirEmLoteJob::class, 3);
    }

    public function test_o_job_abre_liga_o_frete_gratis_obrigatorio_e_confere(): void
    {
        [$a] = $this->cadeiras;
        $r = $this->rascunhoDe($a);
        $r->update(['envio' => ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false]]);
        Cache::put(ResumoRapidoService::chaveConferindo($a->id), 'x', 600);

        (new ConferirEmLoteJob($a->id))->handle(app(ConferenciaEmLoteService::class));

        $r = $r->fresh();
        $this->assertTrue($r->envio['frete_gratis'], 'R$ 150 está na faixa em que o ML exige frete grátis do vendedor');
        $this->assertSame(1, count(Http::recorded(fn (Request $q) => str_contains($q->url(), 'shipping_options/free'))));
        $v = $r->validacoes()->latest('id')->first();
        $this->assertSame('L3', $v->camada);
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS]);
        $this->assertSame($r->revisao, $v->revisao, 'a conferência vale para a versão com o frete grátis');
        $this->assertSame(PubRascunho::VALIDATED, $r->status);
        $this->assertArrayHasKey('resumo', (array) $r->step_state, 'o resumo de pendências da lista foi recalculado');
        $this->assertFalse(Cache::has(ResumoRapidoService::chaveConferindo($a->id)));

        // Abaixo de R$ 79 o ML banca: o frete grátis fica como estava.
        $b = $this->cadeiras[1];
        $rb = $this->rascunhoDe($b);
        $unica = $this->repo->snapshot($rb)->variantes[0];
        $this->repo->gravarVariacao($rb->fresh(), [], [$unica->comDados([...$unica->dados, 'precos' => ['gold_special' => 60.0]])]);
        $rb->fresh()->update(['envio' => ['modo' => 'me2', 'frete_gratis' => false, 'retirada' => false]]);
        app(ConferenciaEmLoteService::class)->executar($b->id);
        $this->assertFalse($rb->fresh()->envio['frete_gratis']);
    }

    public function test_nao_confere_o_que_esta_na_fila_o_publicado_nem_o_de_outra_conta(): void
    {
        [$a, $b, $c] = $this->cadeiras;
        $this->conferirTodas([$a]);
        $this->agendar([$a])->assertCreated();
        $this->rascunhoDe($b)->update(['status' => PubRascunho::PUBLISHED]);
        $alheio = PubProduto::create(['company_id' => Company::factory()->create()->id, 'sku' => 'X', 'nome' => 'X', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $r = $this->conferirPelaTela([$a, $b, $c, $alheio])->assertStatus(202)->json();

        $this->assertSame([$c->id], $r['enfileirados']);
        $this->assertSame('Está na fila de publicação: tire da fila para conferir de novo.', $r['ignorados'][$a->id]);
        $this->assertSame('Já publicado.', $r['ignorados'][$b->id]);
        $this->assertSame('Este produto não é desta conta.', $r['ignorados'][$alheio->id]);

        $this->conferirPelaTela([$a])->assertStatus(422);
        // E o job que já estava na fila também não confere quem entrou na fila depois.
        $this->assertSame('na_fila', app(ConferenciaEmLoteService::class)->executar($a->id));
    }

    public function test_produto_sem_rascunho_ganha_o_rascunho_ao_conferir(): void
    {
        $novo = PubProduto::create(['company_id' => $this->empresa->id, 'sku' => 'NOVO-01', 'nome' => 'Poltrona', 'origem' => PubProduto::ORIGEM_PUBLICADOR]);

        $resultado = app(ConferenciaEmLoteService::class)->executar($novo->id);

        $r = PubRascunho::where('produto_id', $novo->id)->firstOrFail();
        $this->assertSame('conferido:BLOQUEADO', $resultado, 'sem categoria a conferência para no primeiro passo');
        $this->assertSame('V-CAT-01', $r->validacoes()->latest('id')->first()->issues[0]['regra']);
    }

    public function test_limite_de_100_por_clique(): void
    {
        $this->actingAs($this->admin)->postJson($this->rotaLote('conferir'), ['produtos' => range(1, 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('produtos');
    }
}
