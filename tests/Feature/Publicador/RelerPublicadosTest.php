<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\RelerEstadoDoPublicadoJob;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use App\Models\User;
use App\Services\Publicador\ClienteMlPublicador;
use App\Services\Publicador\ConferenciaService;
use App\Services\Publicador\EditorRascunhoService;
use App\Services\Publicador\PublicacaoService;
use App\Support\Portal\AtorDoPortal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioCadeira;
use Tests\TestCase;

/**
 * R17 (pós-demo, 22/10/2026) — os anúncios publicados ANTES do `RelerEstadoDoPublicadoJob` seguiam "Publicado"
 * na lista final do editor estando pausados no ML (na #459: MLB5439825875, MLB5439857995 e os 4 da Opala). O
 * `publicador:reler-publicados` agenda a releitura deles: simulação por padrão, só GET, só conta liberada do
 * mesmo vendedor, com teto e lotes; e uma rodada diária no `routes/console.php`.
 *
 * O ML é simulado: nenhum anúncio de verdade é lido aqui.
 */
class RelerPublicadosTest extends TestCase
{
    use CenarioCadeira;
    use RefreshDatabase;

    /** O anúncio como o ML o devolve agora em `GET /items/{id}`: pausado depois de criar. */
    private array $noMl = ['status' => 'paused', 'sub_status' => [], 'shipping' => ['mode' => 'me2', 'free_shipping' => true]];

    private int $posts = 0;

    private PubPublicacao $p;

    private PubPublicacaoItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario();
        $this->fakeMl([
            '*/items/*/description' => fn () => Http::response(['plain_text' => 'ok'], 200),
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/items/MLB*' => fn (Request $q) => Http::response(['id' => basename(parse_url($q->url(), PHP_URL_PATH)), 'permalink' => 'https://produto.mercadolivre.com.br/x', 'tags' => [], ...$this->noMl]),
            '*/items' => fn (Request $q) => Http::response(['id' => sprintf('MLB80000000%02d', ++$this->posts), 'status' => 'active',
                'family_name' => $q->data()['family_name'] ?? null, 'listing_type_id' => $q->data()['listing_type_id'] ?? null], 201),
        ]);
        $this->efetivos = ['titulos' => ['gold_special' => null], 'precos' => ['gold_special' => 207.19], 'mlbs' => []];

        $v = app(ConferenciaService::class)->conferir($this->r->fresh());
        $this->assertContains($v->resultado, [ConferenciaService::OK, ConferenciaService::AVISOS], json_encode($v->issues, JSON_UNESCAPED_UNICODE));
        $p = app(PublicacaoService::class)->iniciar($this->r->fresh(), AtorDoPortal::daEquipe(User::factory()->create()), cienteDosAvisos: true);
        $this->assertTrue(app(PublicacaoService::class)->executarFatia($p->fresh(), 45));
        $this->p = $p->fresh();

        // Como um anúncio publicado ANTES de 22/10: sem o estado relido, criado há 5 dias.
        $this->item = $this->p->itens()->firstOrFail();
        $this->item->update(['avisos' => collect((array) $this->item->avisos)->except('estado')->all(), 'criado_em' => now()->subDays(5)]);
        Queue::fake(); // esquece o Job agendado pela própria publicação
    }

    private function itemNaTela(): array
    {
        return app(EditorRascunhoService::class)->estado($this->r->fresh())['publicacao']['itens'][0];
    }

    /** Mais um anúncio na mesma publicação (outra cor), também antigo e nunca relido. */
    private function outroItem(int $indice, string $mlb, array $estado = []): PubPublicacaoItem
    {
        $novo = $this->item->replicate();
        $novo->fill(['indice' => $indice, 'ml_item_id' => $mlb, 'avisos' => $estado === [] ? [] : ['estado' => $estado]])->save();

        return $novo;
    }

    public function test_sem_aplicar_e_simulacao_nada_vai_para_a_fila_nem_ao_ml(): void
    {
        $antes = $this->chamadas('/items/MLB');

        $this->artisan('publicador:reler-publicados')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->expectsOutputToContain('a reler: 1 anúncio(s) em 1 Job(s)')
            ->expectsOutputToContain('Rode com --aplicar')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->chamadas('/items/MLB'));
    }

    public function test_aplicar_agenda_o_mesmo_job_e_a_lista_final_passa_a_mostrar_o_pausado(): void
    {
        $this->assertNull($this->itemNaTela()['situacao_ml'], 'antes: "Publicado" — o estado nunca foi relido');

        $this->artisan('publicador:reler-publicados --aplicar')->assertSuccessful();

        $jobs = Queue::pushed(RelerEstadoDoPublicadoJob::class);
        $this->assertCount(1, $jobs);
        $job = $jobs->first();
        $this->assertSame($this->p->id, $job->publicacaoId);
        $this->assertSame([$this->item->id], $job->itens);
        $this->assertSame('high', $job->queue);

        // O Job roda: um GET por anúncio, e o item antigo ganha a situação na lista final.
        $antes = count(Http::recorded());
        $job->handle(app(ClienteMlPublicador::class));
        $doJob = collect(Http::recorded())->slice($antes)->filter(fn ($par) => str_contains($par[0]->url(), '/items/MLB'));
        $this->assertCount(1, $doJob);
        $this->assertSame(['status' => 'paused', 'texto' => 'Pausado pelo Mercado Livre', 'motivo' => null], $this->itemNaTela()['situacao_ml']);
        $this->assertSame(['GET'], $doJob->map(fn ($par) => $par[0]->method())->unique()->values()->all(), 'só leitura no ML');
    }

    public function test_job_em_lote_le_so_os_itens_pedidos(): void
    {
        $outro = $this->outroItem(7, 'MLB8000000077');

        $this->assertSame(1, (new RelerEstadoDoPublicadoJob($this->p->id, [$outro->id]))->reler(app(ClienteMlPublicador::class)));
        $this->assertSame('paused', $outro->fresh()->avisos['estado']['status']);
        $this->assertArrayNotHasKey('estado', (array) $this->item->fresh()->avisos);
    }

    public function test_lido_ha_pouco_e_encerrado_ficam_de_fora_e_horas_zero_rele_mesmo_assim(): void
    {
        $this->item->update(['avisos' => ['estado' => ['status' => 'active', 'lido_em' => now()->subHours(2)->toIso8601String()]]]);
        $this->outroItem(7, 'MLB8000000077', ['status' => 'closed', 'lido_em' => now()->subDays(3)->toIso8601String()]);

        $this->artisan('publicador:reler-publicados --aplicar')
            ->expectsOutputToContain('a reler: 0 anúncio(s)')
            ->expectsOutputToContain('lidos há menos de 24 h: 1 · encerrados no ML: 1')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('publicador:reler-publicados --aplicar --horas=0')->assertSuccessful();
        $this->assertSame([$this->item->id], Queue::pushed(RelerEstadoDoPublicadoJob::class)->first()->itens, 'o encerrado não volta nunca');
    }

    public function test_publicado_antes_da_janela_de_dias_nao_entra(): void
    {
        $this->item->update(['criado_em' => now()->subDays(40)]);

        $this->artisan('publicador:reler-publicados --aplicar')->expectsOutputToContain('a reler: 0 anúncio(s)')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('publicador:reler-publicados --aplicar --dias=60')->assertSuccessful();
        Queue::assertPushed(RelerEstadoDoPublicadoJob::class, 1);
    }

    public function test_conta_que_deixou_de_ser_liberada_ou_trocou_de_vendedor_fica_fora(): void
    {
        config(['publicador.contas_liberadas' => ['companies' => [], 'mlb_empresas' => []]]);
        $this->artisan('publicador:reler-publicados --aplicar')->expectsOutputToContain('fora (conta não liberada ou outro vendedor): 1')->assertSuccessful();
        Queue::assertNothingPushed();

        config(['publicador.contas_liberadas' => ['companies' => [$this->empresa->id], 'mlb_empresas' => []]]);
        $this->empresa->mlToken()->update(['ml_user_id' => '999']);
        $this->artisan('publicador:reler-publicados --aplicar')->expectsOutputToContain('fora (conta não liberada ou outro vendedor): 1')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_filtro_por_conta_pela_chave_do_publicador(): void
    {
        $this->artisan('publicador:reler-publicados --aplicar --conta=company-999999')->expectsOutputToContain('a reler: 0 anúncio(s)')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('publicador:reler-publicados --aplicar --conta='.$this->empresa->chaveContaMl())
            ->expectsOutputToContain($this->empresa->chaveContaMl().': 1')->assertSuccessful();
        Queue::assertPushed(RelerEstadoDoPublicadoJob::class, 1);
    }

    public function test_teto_e_lotes_espacados_nunca_lidos_primeiro(): void
    {
        config(['publicador.releitura_publicados.lote' => 1, 'publicador.releitura_publicados.intervalo_s' => 60]);
        $lidoOntem = $this->outroItem(7, 'MLB8000000077', ['status' => 'active', 'lido_em' => now()->subDays(2)->toIso8601String()]);
        $nunca = $this->outroItem(8, 'MLB8000000088');

        $this->artisan('publicador:reler-publicados --aplicar --teto=2')
            ->expectsOutputToContain('a reler: 2 anúncio(s) em 2 Job(s) · 1 acima do teto de 2')
            ->assertSuccessful();

        $jobs = Queue::pushed(RelerEstadoDoPublicadoJob::class)->values();
        $this->assertCount(2, $jobs);
        $this->assertEqualsCanonicalizing([$this->item->id, $nunca->id], $jobs->flatMap(fn ($j) => $j->itens)->all(), 'os nunca lidos antes do lido anteontem');
        $this->assertNotContains($lidoOntem->id, $jobs->flatMap(fn ($j) => $j->itens)->all());
        // Um lote por minuto: o 2º sai 60 s depois do 1º.
        $atrasos = $jobs->map(fn ($j) => $j->delay instanceof \DateTimeInterface ? $j->delay->getTimestamp() - now()->getTimestamp() : (int) $j->delay)->sort()->values()->all();
        $this->assertEqualsWithDelta(0, $atrasos[0], 2);
        $this->assertEqualsWithDelta(60, $atrasos[1], 2);
    }

    public function test_o_mesmo_mlb_em_duas_publicacoes_e_lido_uma_vez(): void
    {
        $outraPublicacao = $this->p->replicate();
        $outraPublicacao->fill(['chave_idempotencia' => 'outra-'.$this->p->id])->save();
        $copia = $this->item->replicate();
        $copia->fill(['publicacao_id' => $outraPublicacao->id])->save();

        $this->artisan('publicador:reler-publicados --aplicar')->expectsOutputToContain('a reler: 1 anúncio(s)')->assertSuccessful();
        $this->assertSame([$copia->id], Queue::pushed(RelerEstadoDoPublicadoJob::class)->first()->itens, 'o item mais novo');
    }

    public function test_rodada_diaria_agendada_com_aplicar_em_sao_paulo_e_desligavel(): void
    {
        $eventos = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'publicador:reler-publicados'));

        $this->assertCount(1, $eventos, 'agendada exatamente uma vez');
        $e = $eventos->first();
        $this->assertStringContainsString('--aplicar', (string) $e->command);
        $this->assertSame('20 9 * * *', $e->expression);
        $this->assertSame('America/Sao_Paulo', $e->timezone);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertTrue($e->filtersPass($this->app));

        config(['publicador.releitura_publicados.ativo' => false]);
        $this->assertFalse($e->filtersPass($this->app), 'PUBLICADOR_RELEITURA_ATIVO=false desliga');
    }
}
