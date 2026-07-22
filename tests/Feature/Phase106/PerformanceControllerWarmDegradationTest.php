<?php

namespace Tests\Feature\Phase106;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Suite Feature — Fase 106 Plan 02 (PERF-01 · SC2/SC3).
 *
 * Prova a degradação graciosa do gate quente/frio em `PerformanceController::index`
 * quando o período selecionado está FECHADO (`$periodoResolvido['is_closed']`):
 *
 *  - Task 1 (SC2/SC3): profissional FRIO (sem `isCached`) não é computado ao vivo
 *    — vira linha placeholder `calculando:true`; profissional QUENTE mostra a nota
 *    real; modo EM CURSO permanece intocado (gate não atua).
 *  - Task 2 (SC2): havendo ≥1 frio, dispara `desempenho:warm-cache` via
 *    `Artisan::queue`, protegido por lock `Cache::add` — nunca empilha 2×.
 *
 * Setup mirroring `PerformanceCargoFilterTest`, mas com `company_users.servico_id`
 * PREENCHIDO (fonte 1 — CTX-05) apontando para o serviço canônico de setor
 * `performance` já semeado pelas migrations (`Gestão`), evitando depender de
 * `contratos_servico` (o ramo legado do CarteiraContextService exige contrato
 * ativo — pitfall que derruba `PerformanceCargoFilterTest`, fora de escopo aqui).
 */
class PerformanceControllerWarmDegradationTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $servicoPerformanceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Serviço canônico setor=performance (semeado via migration
        // 2026_06_18_100002_seed_servicos_setor.php — "Gestão"). Usar servico_id
        // PREENCHIDO (fonte 1) evita depender de `contratos_servico` (ramo
        // legado exige contrato ativo — não é o foco deste plano).
        $this->servicoPerformanceId = (int) Servico::where('setor', Servico::SETOR_PERFORMANCE)->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Perf106 ' . uniqid(),
            'email'    => 'admin.p106.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Cria um analista com carteira ativa (1 empresa, vínculo servico_id
     * PREENCHIDO -3 meses) — passa pelo filtro `sem_carteira` do
     * `DesempenhoScoreService::computeUniverso` e aparece no ranking.
     */
    private function criarUserAnalista(): User
    {
        $user = User::create([
            'name'     => 'Analista Perf106 ' . uniqid(),
            'email'    => 'analista.p106.' . uniqid() . '@ecf.test',
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
        $ts = now()->subMonths(3)->toDateTimeString();
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

    /**
     * Popula o cache de `computeCached()` para (user, mês) — via a MESMA
     * chave pública (`cacheKey()`), simulando um profissional "quente".
     */
    private function preAquecerCache(User $user, Carbon $mes, float $notaFinal = 3.5): void
    {
        $scoreService = app(DesempenhoScoreService::class);
        $cacheKey     = $scoreService->cacheKey($user->id, $mes);

        Cache::put($cacheKey, [
            'user_id'               => $user->id,
            'user_name'             => $user->name,
            'mes_referencia'        => $mes->toDateString(),
            'sem_carteira'          => false,
            'motivo'                => null,
            'empresas_carteira'     => 1,
            'empresas_com_baseline' => 1,
            'componentes' => [
                'nps_medio'           => 4.0,
                'var_faturamento_pct' => 2.0,
                'var_margem_pct'      => 1.0,
                'absenteismo_pct'     => null,
            ],
            'nota_final'      => $notaFinal,
            'faixa_bonus'     => 'intermediario',
            'faixa_promovida' => false,
            'empresas_unicas'               => 1,
            'vinculos_servico'              => 1,
            'vinculos_financeiros'          => 1,
            'vinculos_sem_fonte_financeira' => 0,
            'score_status'                  => 'official',
            'componentes_disponiveis' => [
                'nps_medio'           => true,
                'var_faturamento_pct' => true,
                'var_margem_pct'      => true,
            ],
        ], 300);
    }

    private function propsDaResposta($response): array
    {
        return $response->viewData('page')['props'] ?? [];
    }

    private function linhaDoUser(array $ranking, int $userId): ?array
    {
        foreach ($ranking as $r) {
            if (($r['id'] ?? null) === $userId) {
                return $r;
            }
        }

        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Task 1 — Gate quente/frio + placeholder calculando + prop aquecendo
    // ═════════════════════════════════════════════════════════════════════════

    public function test_modo_fechado_user_frio_retorna_placeholder_calculando(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $frio = $this->criarUserAnalista();

        // Zero compute ao vivo — qualquer HTTP real estoura o teste. Queue::fake()
        // isola o dispatch do warm sob-demanda (Task 2, testado à parte) — sem
        // fake, QUEUE_CONNECTION=sync (phpunit.xml) rodaria o Artisan::queue
        // SINCRONAMENTE dentro da própria requisição, o que aqueceria o cache
        // do frio ali mesmo e mascararia a prova de "zero compute na requisição".
        Http::preventStrayRequests();
        Queue::fake();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        $props  = $this->propsDaResposta($response);
        $linha  = $this->linhaDoUser($props['ranking'] ?? [], $frio->id);

        $this->assertNotNull($linha, 'user frio deve aparecer no ranking (placeholder), não sumir');
        $this->assertTrue($linha['calculando'] ?? false, 'user frio deve vir com calculando=true');
        $this->assertNull($linha['nota_final'], 'user frio não pode ter nota_final ao vivo');
        $this->assertTrue($props['aquecendo'] ?? false, 'prop aquecendo deve ser true com >=1 frio');

        // Prova definitiva de zero compute: se computeCached tivesse rodado,
        // teria escrito a chave de cache — isCached continua false.
        $mes      = Carbon::createFromFormat('Y-m-d', '2026-06-01')->startOfMonth();
        $isCached = app(DesempenhoScoreService::class)->isCached($frio, $mes);
        $this->assertFalse($isCached, 'gate não pode ter computado o user frio ao vivo');
    }

    public function test_modo_fechado_user_quente_retorna_nota_real(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $quente = $this->criarUserAnalista();

        $mes = Carbon::createFromFormat('Y-m-d', '2026-06-01')->startOfMonth();
        $this->preAquecerCache($quente, $mes, 3.5);

        Http::preventStrayRequests();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        $props = $this->propsDaResposta($response);
        $linha = $this->linhaDoUser($props['ranking'] ?? [], $quente->id);

        $this->assertNotNull($linha);
        $this->assertFalse($linha['calculando'] ?? false, 'user quente não deve vir calculando');
        $this->assertSame(3.5, $linha['nota_final'], 'user quente deve mostrar a nota real do cache');
        $this->assertFalse($props['aquecendo'] ?? true, 'sem nenhum frio, aquecendo deve ser false');
    }

    public function test_modo_em_curso_gate_atua_quando_frio(): void
    {
        // 2026-07-22: o gate passou a atuar TAMBÉM no mês em curso (antes só
        // fechado). Profissional com cache frio → placeholder `calculando` +
        // `aquecendo=true`, NUNCA compute ao vivo na tela (plano §9.1). Antes
        // o Em curso caía no compute síncrono e travava a tela.
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $user = $this->criarUserAnalista();

        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $props = $this->propsDaResposta($response);
        $linha = $this->linhaDoUser($props['ranking'] ?? [], $user->id);

        $this->assertNotNull($linha);
        $this->assertTrue($linha['calculando'] ?? false, 'mês em curso frio: linha vem como calculando (gate atua)');
        $this->assertTrue($props['aquecendo'] ?? false, 'mês em curso frio: aquecendo=true (warm sob-demanda disparado)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Task 2 — Dispatch do warm sob-demanda com lock anti-duplicação
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Extrai `func_get_args()` gravado em `QueuedCommand::$data` (protected) —
     * `Illuminate\Foundation\Console\Kernel::queue()` chama
     * `QueuedCommand::dispatch(func_get_args())`, então `$data[0]` é o nome do
     * comando e `$data[1]` são os parâmetros (`--mes`/`--user`).
     */
    private function dadosDoJob(QueuedCommand $job): array
    {
        $ref = new \ReflectionProperty(QueuedCommand::class, 'data');
        $ref->setAccessible(true);

        return $ref->getValue($job);
    }

    public function test_frio_dispara_warm_sob_demanda_uma_vez(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $frio = $this->criarUserAnalista();

        Queue::fake();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        Queue::assertPushed(QueuedCommand::class, 1);

        Queue::assertPushed(QueuedCommand::class, function (QueuedCommand $job) use ($frio) {
            $dados   = $this->dadosDoJob($job);
            $comando = $dados[0] ?? null;
            $params  = $dados[1] ?? [];

            return $comando === 'desempenho:warm-cache'
                && ($params['--mes'] ?? null) === '2026-06'
                && in_array($frio->id, $params['--user'] ?? [], true);
        });
    }

    public function test_segundo_request_nao_duplica_dispatch_por_causa_do_lock(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $this->criarUserAnalista();

        Queue::fake();

        $this->get('/performance?mes=2026-06')->assertStatus(200);
        $this->get('/performance?mes=2026-06')->assertStatus(200);

        Queue::assertPushed(QueuedCommand::class, 1);
    }

    public function test_todos_quentes_nao_dispara_warm(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->actingAsAdmin();
        $quente = $this->criarUserAnalista();

        $mes = Carbon::createFromFormat('Y-m-d', '2026-06-01')->startOfMonth();
        $this->preAquecerCache($quente, $mes, 4.0);

        Queue::fake();

        $response = $this->get('/performance?mes=2026-06');
        $response->assertStatus(200);

        $props = $this->propsDaResposta($response);
        $this->assertFalse($props['aquecendo'] ?? true, 'todos quentes: aquecendo deve ser false');

        Queue::assertNotPushed(QueuedCommand::class);
    }
}
