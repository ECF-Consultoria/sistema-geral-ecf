<?php

namespace Tests\Feature\Publicador;

use App\Jobs\Publicador\GerarPropostaDaJardinagemJob;
use App\Models\Company;
use App\Models\MlToken;
use App\Models\PubJardinagem;
use App\Models\PubPublicacao;
use App\Models\PubPublicacaoItem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Publicador\Concerns\CenarioTarefas;
use Tests\TestCase;

/**
 * Jardinagem mensal dos publicados pela árvore do MAG (23/10/2026): o comando `publicador:jardinagem`
 * escolhe quem fechou o mês, lê visitas e vendas no ML (simulado aqui — nenhum anúncio real é lido),
 * aplica a régua e grava o mês de cada anúncio.
 */
class JardinagemTest extends TestCase
{
    use CenarioTarefas;
    use RefreshDatabase;

    private Company $conta;

    private CarbonImmutable $hoje;

    /** O que o ML devolve por MLB: status, sold_quantity e a série de visitas (data => visitas). */
    private array $noMl = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->hoje = CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
        $this->conta = Company::factory()->create();
        MlToken::create(['company_id' => $this->conta->id, 'ml_user_id' => '1555596317', 'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token', 'token_type' => 'bearer', 'expires_at' => now()->addHours(5),
            'last_refreshed_at' => now(), 'status' => 'active', 'connected_at' => now()]);
        config(['publicador.contas_liberadas' => ['companies' => [$this->conta->id], 'mlb_empresas' => []]]);
        // A Jardinagem nasce desligada (23/10/2026); estes testes provam o que ela faz LIGADA.
        config(['publicador.jardinagem.ativo' => true]);
        $this->conta = $this->conta->fresh();

        Http::preventStrayRequests();
        Http::fake([
            '*/items?ids=*' => fn (Request $q) => Http::response(array_map(fn ($id) => isset($this->noMl[$id])
                ? ['code' => 200, 'body' => ['id' => $id, 'status' => $this->noMl[$id]['status'], 'sold_quantity' => $this->noMl[$id]['vendidos'],
                    'title' => "Titulo de {$id}", 'listing_type_id' => $this->noMl[$id]['tipo'] ?? 'gold_special']]
                : ['code' => 404, 'body' => []], explode(',', $q->data()['ids']))),
            '*/visits/time_window*' => function (Request $q) {
                $mlb = explode('/', parse_url($q->url(), PHP_URL_PATH))[2];
                if (($this->noMl[$mlb]['visitas'] ?? null) === 'falha') {
                    return Http::response(['message' => 'erro interno'], 500);
                }
                $results = [];
                foreach ($this->noMl[$mlb]['visitas'] ?? [] as $data => $n) {
                    $results[] = ['date' => "{$data}T00:00:00Z", 'total' => $n, 'visits_detail' => []];
                }

                return Http::response(['item_id' => $mlb, 'results' => $results]);
            },
        ]);
    }

    private function dia(int $atras): string
    {
        return $this->hoje->subDays($atras)->format('Y-m-d');
    }

    /** Uma publicação da conta com os MLBs dados, criada há `dias` dias. */
    private function publicadoHa(int $dias, array $mlbs, ?Company $conta = null): PubPublicacao
    {
        $p = $this->publicacaoConcluida($conta ?? $this->conta, $mlbs);
        PubPublicacaoItem::where('publicacao_id', $p->id)->update(['criado_em' => $this->hoje->subDays($dias)->setTime(15, 0)]);

        return $p;
    }

    /** Visitas espalhadas: `n` por dia nos últimos `dias` dias inteiros, e o de HOJE (que não conta). */
    private function serie(int $porDia, int $dias, int $deHoje = 999): array
    {
        $serie = [];
        for ($i = $dias; $i >= 1; $i--) {
            $serie[$this->dia($i)] = $porDia;
        }
        $serie[$this->dia(0)] = $deHoje;

        return $serie;
    }

    public function test_simulacao_conta_quem_fechou_o_mes_e_nao_le_nem_grava(): void
    {
        $this->publicadoHa(31, ['MLB1001' => 'gold_special']);
        $this->publicadoHa(10, ['MLB2001' => 'gold_special']);

        $this->artisan('publicador:jardinagem')
            ->expectsOutputToContain('SIMULAÇÃO')
            ->expectsOutputToContain('mês fechado (a analisar): 1')
            ->expectsOutputToContain('em observação: 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PubJardinagem::count());
    }

    public function test_aplicar_grava_o_mes_com_o_ramo_da_arvore_e_pede_o_titulo_so_do_sem_tracao(): void
    {
        $this->publicadoHa(30, ['MLB1001' => 'gold_special', 'MLB1002' => 'gold_pro']);
        // Clássico: 1 visita/dia por 30 dias = 30 (< 100), nenhuma venda → sem tração. O dia anterior à
        // publicação e o de hoje NÃO contam.
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 0, 'visitas' => [$this->dia(31) => 500, ...$this->serie(1, 30)]];
        // Premium: 20/dia = 600 visitas, nenhuma venda → visita sem venda.
        $this->noMl['MLB1002'] = ['status' => 'active', 'vendidos' => 0, 'tipo' => 'gold_pro', 'visitas' => $this->serie(20, 30)];

        $this->artisan('publicador:jardinagem --aplicar')->expectsOutputToContain('Gravado.')->assertSuccessful();

        $classico = PubJardinagem::where('ml_item_id', 'MLB1001')->sole();
        $this->assertSame(PubJardinagem::SEM_TRACAO, $classico->diagnostico);
        $this->assertSame(30, $classico->visitas);
        $this->assertSame(0, $classico->vendas);
        $this->assertSame(0.0, $classico->conversao);
        $this->assertSame(1, $classico->ciclo);
        $this->assertSame($this->dia(30), substr((string) $classico->janela_inicio, 0, 10));
        $this->assertSame($this->dia(0), substr((string) $classico->janela_fim, 0, 10));
        $this->assertSame('company-'.$this->conta->id, $classico->conta_chave);
        $this->assertSame(['nova_oferta'], array_keys($classico->checklist));
        $this->assertSame('gerando', $classico->proposta['estado']);

        $premium = PubJardinagem::where('ml_item_id', 'MLB1002')->sole();
        $this->assertSame(PubJardinagem::VISITA_SEM_VENDA, $premium->diagnostico);
        $this->assertSame(600, $premium->visitas);
        $this->assertSame(['criativo', 'elasticidade'], array_keys($premium->checklist));
        $this->assertNull($premium->proposta);

        Queue::assertPushed(GerarPropostaDaJardinagemJob::class, 1);
        Queue::assertPushed(GerarPropostaDaJardinagemJob::class, fn ($job) => $job->jardinagemId === $classico->id);
    }

    public function test_mes_seguinte_mede_as_vendas_pela_diferenca_e_substitui_o_mes_aberto(): void
    {
        $this->publicadoHa(60, ['MLB1001' => 'gold_special']);
        $item = PubPublicacaoItem::where('ml_item_id', 'MLB1001')->sole();
        $anterior = PubJardinagem::create([
            'ml_item_id' => 'MLB1001', 'ciclo' => 1, 'status' => PubJardinagem::PENDENTE, 'diagnostico' => PubJardinagem::CONVERSAO_BAIXA,
            'publicacao_item_id' => $item->id, 'conta_chave' => 'company-'.$this->conta->id,
            'janela_inicio' => $this->dia(60), 'janela_fim' => $this->dia(30), 'visitas' => 300, 'vendas' => 3, 'vendidos_total' => 3,
            'conversao' => 1.0, 'checklist' => PubJardinagem::checklistInicial(PubJardinagem::CONVERSAO_BAIXA),
        ]);
        // 10 visitas/dia × 30 = 300; vendidos 3 → 12 = 9 vendas no mês → 3% → conversão boa.
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 12, 'visitas' => $this->serie(10, 30)];

        $this->artisan('publicador:jardinagem --aplicar')->assertSuccessful();

        $novo = PubJardinagem::where('ml_item_id', 'MLB1001')->where('ciclo', 2)->sole();
        $this->assertSame(9, $novo->vendas);
        $this->assertSame(300, $novo->visitas);
        $this->assertSame(3.0, $novo->conversao);
        $this->assertSame(12, $novo->vendidos_total);
        $this->assertSame(PubJardinagem::CONVERSAO_BOA, $novo->diagnostico);
        $this->assertSame($this->dia(30), substr((string) $novo->janela_inicio, 0, 10));
        $this->assertSame(PubJardinagem::SUBSTITUIDA, $anterior->fresh()->status);
        Queue::assertNotPushed(GerarPropostaDaJardinagemJob::class);
    }

    public function test_rodar_duas_vezes_no_mesmo_dia_nao_duplica_o_mes(): void
    {
        $this->publicadoHa(30, ['MLB1001' => 'gold_special']);
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 0, 'visitas' => $this->serie(1, 30)];

        $this->artisan('publicador:jardinagem --aplicar')->assertSuccessful();
        $this->artisan('publicador:jardinagem --aplicar')->expectsOutputToContain('mês fechado (a analisar): 0')->assertSuccessful();

        $this->assertSame(1, PubJardinagem::count());
    }

    public function test_pausado_no_ml_nao_entra_e_encerrado_nem_e_lido(): void
    {
        $this->publicadoHa(30, ['MLB1001' => 'gold_special', 'MLB1002' => 'gold_pro']);
        PubPublicacaoItem::where('ml_item_id', 'MLB1002')->update(['avisos' => ['estado' => ['status' => 'closed']]]);
        $this->noMl['MLB1001'] = ['status' => 'paused', 'vendidos' => 0, 'visitas' => []];

        $this->artisan('publicador:jardinagem --aplicar')
            ->expectsOutputToContain('encerrados: 1')
            ->expectsOutputToContain('MLB1001: Está "paused" no Mercado Livre.')
            ->assertSuccessful();

        $this->assertSame(0, PubJardinagem::count());
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), 'MLB1002'));
        // Pausado: nem as visitas são pedidas.
        Http::assertNotSent(fn (Request $q) => str_contains($q->url(), 'time_window'));
    }

    public function test_conta_nao_liberada_fica_de_fora_sem_chamada_ao_ml(): void
    {
        $outra = Company::factory()->create();
        $this->publicadoHa(40, ['MLB9001' => 'gold_special'], $outra);

        $this->artisan('publicador:jardinagem --aplicar')
            ->expectsOutputToContain('fora (conta não liberada ou outro vendedor): 1')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PubJardinagem::count());
    }

    public function test_ler_mostra_o_diagnostico_sem_gravar(): void
    {
        $this->publicadoHa(8, ['MLB1001' => 'gold_special']);
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 1, 'visitas' => $this->serie(5, 8)];

        // 40 visitas, 1 venda = 2,5% → boa; com `--dias=7` não precisa esperar o mês.
        $this->artisan('publicador:jardinagem --ler --dias=7')
            ->expectsOutputToContain('LEITURA')
            ->expectsOutputToContain('MLB1001 (mês 1): 40 visitas, 1 vendas, conversão 2,50% → Conversão boa')
            ->assertSuccessful();

        $this->assertSame(0, PubJardinagem::count());
        Queue::assertNothingPushed();
    }

    public function test_ml_que_falha_nas_visitas_nao_grava_e_o_anuncio_volta_amanha(): void
    {
        $this->publicadoHa(30, ['MLB1001' => 'gold_special']);
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 0, 'visitas' => 'falha'];

        $this->artisan('publicador:jardinagem --aplicar')->expectsOutputToContain('não lidos: 1')->assertSuccessful();

        $this->assertSame(0, PubJardinagem::count());
        $this->artisan('publicador:jardinagem')->expectsOutputToContain('mês fechado (a analisar): 1')->assertSuccessful();
    }

    public function test_rodada_diaria_agendada_com_aplicar_e_so_roda_ligada(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'publicador:jardinagem'));

        $this->assertNotNull($evento, 'a rodada diária da Jardinagem não está no routes/console.php');
        $this->assertStringContainsString('--aplicar', (string) $evento->command);
        $this->assertSame('40 9 * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);

        $this->assertTrue($evento->filtersPass($this->app));
        config(['publicador.jardinagem.ativo' => false]);
        $this->assertFalse($evento->filtersPass($this->app), 'desligada, a rodada diária não pode rodar');
    }

    public function test_nasce_desligada_e_desligada_nao_le_nem_grava(): void
    {
        $this->assertFalse((bool) ((require base_path('config/publicador.php'))['jardinagem']['ativo']),
            'o padrão do config tem de ser DESLIGADO até o usuário liberar');

        config(['publicador.jardinagem.ativo' => false]);
        $this->publicadoHa(30, ['MLB1001' => 'gold_special']);
        $this->noMl['MLB1001'] = ['status' => 'active', 'vendidos' => 0, 'visitas' => $this->serie(1, 30)];

        $this->artisan('publicador:jardinagem --aplicar')->expectsOutputToContain('A Jardinagem está desligada')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PubJardinagem::count());
        Queue::assertNothingPushed();

        // `--ler` segue valendo para conferir (só GET, nada gravado).
        $this->artisan('publicador:jardinagem --ler')->expectsOutputToContain('→ Sem tração')->assertSuccessful();
        $this->assertSame(0, PubJardinagem::count());
    }
}
