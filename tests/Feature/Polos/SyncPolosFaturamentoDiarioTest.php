<?php

// O faturamento dos polos tem de se atualizar INTEIRO todo dia, sozinho (pedido de 30/09/2026).
//
// Até então o agendamento era Schedule::job: o job esperava ~4h na fila `default` e o teto de
// 25 min do worker cortava metade do roster (130 de 262), então cada empresa só era atualizada
// a cada ~2 dias. Agora o agendamento roda `polos:warm` em processo próprio, sem teto. Estes
// testes travam as três peças: o agendamento, a varredura completa e a trava contra duas
// varreduras simultâneas (que estourariam a cota de ~10 rpm da Adman).

namespace Tests\Feature\Polos;

use App\Jobs\SyncPolosFaturamentoJob;
use App\Models\MlbEmpresa;
use App\Models\PoloFaturamentoSnapshot;
use App\Services\AdmanService;
use App\Services\MlCategoriaService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/** @group polos */
class SyncPolosFaturamentoDiarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adman.polos_pausa_ms' => 0]); // sem o throttle de 7s por empresa
    }

    private function empresa(string $cust, string $fase): void
    {
        MlbEmpresa::create([
            'nome' => "Empresa {$cust}", 'fase' => $fase, 'projeto' => 'POLOS', 'cust_id' => $cust,
            'polo' => 'Arapongas', 'estagio' => 'Não Listado', 'problema' => false,
        ]);
    }

    /** Adman respondendo para todo cust, exceto os `$recusados` (null = "Customer not found"). */
    private function admanFake(array $recusados = []): void
    {
        $this->mock(AdmanService::class, function ($m) use ($recusados) {
            $m->shouldReceive('fetchPerformanceBreakdown')->andReturnUsing(
                fn ($cust) => in_array($cust, $recusados, true)
                    ? null
                    : ['gross_billing' => 1000.0, 'net_por_categoria' => []],
            );
            $m->shouldReceive('fetchAdsInvestmentTotal')->andReturn(0.0);
        });
        $this->mock(MlCategoriaService::class);
    }

    public function test_agendamento_diario_roda_o_comando_fora_da_fila(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        $warm = $eventos->filter(fn ($e) => str_contains((string) $e->command, 'polos:warm'));
        $this->assertCount(1, $warm, 'polos:warm tem de estar agendado exatamente uma vez.');

        $e = $warm->first();
        $this->assertSame('0 13 * * *', $e->expression);
        $this->assertSame('America/Sao_Paulo', $e->timezone);
        $this->assertTrue($e->runInBackground, 'Varredura de ~50 min não pode segurar o schedule:run.');
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame(180, $e->expiresAt, 'Mutex de 24h pularia o dia seguinte se o processo morrer.');

        // O agendamento antigo (job na fila) não pode voltar: é ele que deixava metade de fora.
        $this->assertFalse(
            $eventos->contains(fn ($ev) => str_contains((string) $ev->description, 'SyncPolosFaturamentoJob')),
            'O sync diário não deve voltar a ser Schedule::job.',
        );
    }

    public function test_polos_warm_atualiza_o_roster_inteiro_e_confere_pelo_banco(): void
    {
        foreach (['101' => 'M1', '201' => 'M2', '301' => 'M3', '401' => 'M4', '402' => 'M4'] as $cust => $fase) {
            $this->empresa($cust, $fase);
        }
        $this->empresa('001', 'M0'); // fora do roster M1–M4
        $this->admanFake(recusados: ['402']);

        $this->artisan('polos:warm')
            ->expectsOutputToContain('atualizadas 4/5')
            ->expectsOutputToContain('Sem atualizar (Adman recusou ou falhou): 402')
            ->assertSuccessful();

        $mes = now()->format('Ym');
        $this->assertEqualsCanonicalizing(
            ['101', '201', '301', '401'],
            PoloFaturamentoSnapshot::where('mes', $mes)->pluck('cust_id')->all(),
        );
    }

    public function test_varredura_simultanea_e_pulada_sem_chamar_a_adman(): void
    {
        $this->empresa('201', 'M2');
        $adman = Mockery::mock(AdmanService::class);
        $adman->shouldNotReceive('fetchPerformanceBreakdown');

        $outra = Cache::lock('polos-sync-faturamento:handle', 60);
        $this->assertTrue($outra->get());

        try {
            (new SyncPolosFaturamentoJob(null, null, null))->handle($adman, new MlCategoriaService());
        } finally {
            $outra->release();
        }

        $this->assertSame(0, PoloFaturamentoSnapshot::count());
    }

    public function test_lock_e_liberado_ao_fim_para_o_proximo_sync(): void
    {
        $this->empresa('201', 'M2');
        $this->admanFake();

        (new SyncPolosFaturamentoJob(null, null, null))->handle(app(AdmanService::class), app(MlCategoriaService::class));

        $proximo = Cache::lock('polos-sync-faturamento:handle', 60);
        $this->assertTrue($proximo->get(), 'Lock preso depois do fim bloquearia o botão Sincronizar.');
        $proximo->release();
    }

    public function test_botao_sincronizar_continua_na_fila_com_orcamento(): void
    {
        // O worker mata o job em 1800s: na fila o teto de 1500s tem de continuar valendo.
        $job = new SyncPolosFaturamentoJob();
        $orcamento = (new \ReflectionProperty($job, 'orcamento'))->getValue($job);

        $this->assertSame(1500, $orcamento);
    }

    public function test_job_ja_na_fila_antes_do_deploy_desserializa_com_orcamento(): void
    {
        // Payload serializado antes de `orcamento` existir não traz a propriedade — é o
        // mesmo que instanciar sem construtor. Com propriedade promovida ela ficaria
        // não inicializada e o job pendente na fila quebraria depois do deploy.
        $job = (new \ReflectionClass(SyncPolosFaturamentoJob::class))->newInstanceWithoutConstructor();
        $orcamento = (new \ReflectionProperty($job, 'orcamento'))->getValue($job);

        $this->assertSame(1500, $orcamento);
    }
}
