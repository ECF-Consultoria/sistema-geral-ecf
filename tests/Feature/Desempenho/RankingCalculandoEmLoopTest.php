<?php

namespace Tests\Feature\Desempenho;

use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incidente de 2026-10-01 — "/performance não carrega, está calculando em loop".
 *
 * Duas causas, uma prova cada:
 *
 *  1. O ranking checava o cache ANTES do snapshot mensal congelado. Competência
 *     consolidada, com a chave expirada, virava "calculando…" e disparava um
 *     warm que, em mês antigo, ocupa o `ecf-worker-high` por ~25 min.
 *  2. O warm agendado só cobria o mês corrente e o último fechado. No dia 1º o
 *     M-2 (agosto, em 01/10) saía da janela sem ter sido consolidado, e as
 *     chaves dele expiraram em bloco 7 dias depois do último compute.
 */
class RankingCalculandoEmLoopTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $servicoPerformanceId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);

        $this->setorId = (int) (DB::table('setores')->where('nome', 'Performance')->value('id')
            ?? DB::table('setores')->insertGetId([
                'nome'       => 'Performance',
                'slug'       => 'performance',
                'active'     => true,
                'is_system'  => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->servicoPerformanceId = (int) Servico::where('setor', Servico::SETOR_PERFORMANCE)->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::create([
            'name'     => 'Admin Loop ' . uniqid(),
            'email'    => 'admin.loop.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]));
    }

    private function criarAnalista(): User
    {
        $user = User::create([
            'name'     => 'Analista Loop ' . uniqid(),
            'email'    => 'analista.loop.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $company = Company::factory()->create();
        $ts = now()->subMonths(6)->toDateTimeString();
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'servico_id'  => $this->servicoPerformanceId,
            'role'        => 'consultor',
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $user;
    }

    private function congelar(User $user, string $mes, array $breakdown): void
    {
        DesempenhoScoreSnapshot::create([
            'user_id'              => $user->id,
            'ref_date'             => $mes,
            'mes_referencia'       => $mes,
            'score'                => 84,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => $breakdown,
        ]);
    }

    private function breakdownCompleto(float $nota): array
    {
        return [
            'nota_final'        => $nota,
            'faixa_bonus'       => 'intermediario',
            'score_status'      => 'complete',
            'empresas_carteira' => 1,
            'componentes'       => [
                'nps_medio'           => 4.0,
                'var_faturamento_pct' => 2.0,
                'var_margem_pct'      => 1.0,
                'absenteismo_pct'     => null,
            ],
        ];
    }

    private function linhaDoUser($response, int $userId): ?array
    {
        foreach ($response->viewData('page')['props']['ranking'] ?? [] as $r) {
            if (($r['id'] ?? null) === $userId) {
                return $r;
            }
        }

        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Ranking — snapshot congelado decide antes do gate de cache
    // ═════════════════════════════════════════════════════════════════════════

    public function test_competencia_congelada_com_cache_frio_mostra_a_nota_sem_calcular(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();
        $this->congelar($analista, '2026-06-01', $this->breakdownCompleto(4.2));

        // A chave do cache de junho NÃO existe — é o estado de produção depois
        // de 7 dias sem compute.
        $this->assertFalse(app(DesempenhoScoreService::class)->isCached($analista, Carbon::parse('2026-06-01')));

        Http::preventStrayRequests();
        Queue::fake();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        $linha = $this->linhaDoUser($response, $analista->id);
        $this->assertNotNull($linha);
        $this->assertFalse($linha['calculando'], 'snapshot congelado não pode virar "calculando…"');
        $this->assertSame(4.2, $linha['nota_final'], 'a nota vem do snapshot congelado');
        $this->assertFalse($response->viewData('page')['props']['aquecendo']);

        // Nenhum warm: era esse job que ocupava o worker-high por ~25 min.
        Queue::assertNotPushed(QueuedCommand::class);
    }

    public function test_snapshot_sem_componentes_continua_passando_pelo_gate(): void
    {
        Carbon::setTestNow('2026-10-01 12:00:00');
        $this->actingAsAdmin();
        $analista = $this->criarAnalista();
        // Snapshot antigo, sem `componentes`: a tela precisa do compute — e o
        // compute frio continua proibido na requisição.
        $this->congelar($analista, '2026-06-01', ['nota_final' => 4.2]);

        Http::preventStrayRequests();
        Queue::fake();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        $linha = $this->linhaDoUser($response, $analista->id);
        $this->assertNotNull($linha);
        $this->assertTrue($linha['calculando']);
        $this->assertTrue($response->viewData('page')['props']['aquecendo']);
        Queue::assertPushed(QueuedCommand::class, 1);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. Warm agendado — cobre o M-2 enquanto ele não estiver congelado
    // ═════════════════════════════════════════════════════════════════════════

    public function test_warm_agendado_aquece_o_mes_anterior_ao_ultimo_fechado_de_quem_nao_esta_congelado(): void
    {
        // 01/10: último fechado = setembro; M-2 = agosto, a competência cujo
        // bônus se fecha agora.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'America/Sao_Paulo'));

        $congelado = $this->criarAnalista();
        $vivo      = $this->criarAnalista();
        $this->congelar($congelado, '2026-08-01', $this->breakdownCompleto(4.2));

        $this->artisan('desempenho:warm-cache')->assertExitCode(0);

        $service = app(DesempenhoScoreService::class);
        $agosto  = Carbon::parse('2026-08-01');

        $this->assertTrue($service->isCached($vivo, $agosto), 'M-2 sem snapshot congelado precisa ficar quente');
        $this->assertFalse($service->isCached($congelado, $agosto), 'M-2 congelado é lido da tabela — não gasta compute');

        // Os dois alvos de antes seguem iguais, para os dois.
        foreach ([$congelado, $vivo] as $u) {
            $this->assertTrue($service->isCached($u, Carbon::parse('2026-10-01')));
            $this->assertTrue($service->isCached($u, Carbon::parse('2026-09-01')));
        }
    }

    public function test_warm_sob_demanda_nao_pula_congelado(): void
    {
        // O filtro é só do modo agendado: `--mes` explícito é pedido humano
        // (ou do gate) e aquece o que foi pedido.
        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00', 'America/Sao_Paulo'));

        $congelado = $this->criarAnalista();
        $this->congelar($congelado, '2026-08-01', $this->breakdownCompleto(4.2));

        $this->artisan('desempenho:warm-cache', ['--mes' => '2026-08', '--user' => [$congelado->id]])
            ->assertExitCode(0);

        $this->assertTrue(app(DesempenhoScoreService::class)->isCached($congelado, Carbon::parse('2026-08-01')));
    }
}
