<?php

namespace Tests\Feature\Phase106;

use App\Models\Company;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suite Feature — Fase 106 Plan 01 (SC1 — warm de 2 alvos).
 *
 * `WarmDesempenhoCache` passa a aquecer, na mesma execução, o mês corrente
 * E a competência do último mês fechado (via `MetricPeriodResolver`
 * `last_closed_month`). Ganha também `--mes=YYYY-MM` (catch-up de uma
 * competência específica) e `--user=*` (restringe a IDs específicos).
 *
 * Setup espelha `PerformanceCargoFilterTest`: setor+cargos (analista/
 * estrategista), users elegíveis com empresa ativa via `company_users`
 * (-3 meses, evita o filtro "empresa nova" do DESEMP-04).
 * `Http::fake()` determinístico — `computeCached()` bate na Adman/ML.
 */
class WarmDesempenhoCacheTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response([], 200)]);

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

        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Cria user elegível (analista/estrategista) + empresa ativa (-3 meses)
     * — mesmo padrão de `PerformanceCargoFilterTest::criarUserComCargo`.
     */
    private function criarUserElegivel(string $cargoSlug = 'analista'): User
    {
        $user = User::create([
            'name'     => 'User Warm ' . $cargoSlug . ' ' . uniqid(),
            'email'    => 'warm.' . $cargoSlug . '.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $cargoId = $cargoSlug === 'estrategista'
            ? $this->cargoEstrategistaId
            : $this->cargoAnalistaId;

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
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
            'role'        => 'consultor',
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $user;
    }

    private function service(): DesempenhoScoreService
    {
        return app(DesempenhoScoreService::class);
    }

    private function mesFechado(): Carbon
    {
        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => 'last_closed_month']);

        return Carbon::parse($periodo['bonus_competence_month'] . '-01')->startOfMonth();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Sem options: aquece mês corrente E último mês fechado (2 alvos)
    // ═══════════════════════════════════════════════════════════════════

    public function test_sem_options_aquece_mes_corrente_e_ultimo_mes_fechado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'America/Sao_Paulo'));

        $analista     = $this->criarUserElegivel('analista');
        $estrategista = $this->criarUserElegivel('estrategista');

        $mesCorrente = Carbon::now()->startOfMonth();
        $mesFechado  = $this->mesFechado();

        $this->assertTrue($mesFechado->lt($mesCorrente), 'Sanity: mes fechado deve ser anterior ao corrente.');

        $this->artisan('desempenho:warm-cache')->assertExitCode(0);

        $service = $this->service();

        $this->assertTrue($service->isCached($analista, $mesCorrente), 'Analista deve estar quente no mês corrente.');
        $this->assertTrue($service->isCached($analista, $mesFechado), 'Analista deve estar quente no mês fechado.');
        $this->assertTrue($service->isCached($estrategista, $mesCorrente), 'Estrategista deve estar quente no mês corrente.');
        $this->assertTrue($service->isCached($estrategista, $mesFechado), 'Estrategista deve estar quente no mês fechado.');

        // As 2 chaves são distintas por user (cacheKey deriva current_month vs YYYY-MM).
        $this->assertNotSame(
            $service->cacheKey($analista->id, $mesCorrente),
            $service->cacheKey($analista->id, $mesFechado)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. --mes=YYYY-MM: aquece SÓ aquela competência
    // ═══════════════════════════════════════════════════════════════════

    public function test_option_mes_aquece_so_a_competencia_pedida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'America/Sao_Paulo'));

        $user = $this->criarUserElegivel('analista');

        $mesCorrente = Carbon::now()->startOfMonth();
        $mesAlvo     = Carbon::parse('2026-05-01'); // competência arbitrária, distinta do corrente e do last_closed_month

        $this->artisan('desempenho:warm-cache', ['--mes' => '2026-05'])->assertExitCode(0);

        $service = $this->service();

        $this->assertTrue($service->isCached($user, $mesAlvo), '--mes deve aquecer a competência pedida.');
        $this->assertFalse($service->isCached($user, $mesCorrente), '--mes NÃO deve aquecer o mês corrente automaticamente.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. --user=<id>: aquece só aquele user
    // ═══════════════════════════════════════════════════════════════════

    public function test_option_user_restringe_aos_ids_pedidos(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'America/Sao_Paulo'));

        $userAlvo = $this->criarUserElegivel('analista');
        $userFrio = $this->criarUserElegivel('estrategista');

        $mesCorrente = Carbon::now()->startOfMonth();

        $this->artisan('desempenho:warm-cache', ['--user' => [$userAlvo->id]])->assertExitCode(0);

        $service = $this->service();

        $this->assertTrue($service->isCached($userAlvo, $mesCorrente), 'User pedido via --user deve ser aquecido.');
        $this->assertFalse($service->isCached($userFrio, $mesCorrente), 'User NÃO pedido via --user deve continuar frio.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. --mes inválido: falha graciosamente (FAILURE, sem 500)
    // ═══════════════════════════════════════════════════════════════════

    public function test_option_mes_invalida_retorna_failure_sem_criar_chave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'America/Sao_Paulo'));

        $user = $this->criarUserElegivel('analista');

        $exitCode = $this->artisan('desempenho:warm-cache', ['--mes' => 'abc'])->run();

        $this->assertSame(Command::FAILURE, $exitCode, '--mes inválido deve retornar FAILURE.');
    }

    public function test_option_mes_fora_do_intervalo_valido_retorna_failure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15', 'America/Sao_Paulo'));

        $user = $this->criarUserElegivel('analista');

        $exitCode = $this->artisan('desempenho:warm-cache', ['--mes' => '2026-13'])->run();

        $this->assertSame(Command::FAILURE, $exitCode, '--mes com mês inválido (13) deve retornar FAILURE.');
    }
}
