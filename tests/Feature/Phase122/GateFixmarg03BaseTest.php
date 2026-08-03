<?php

namespace Tests\Feature\Phase122;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 122 Plano 03 (D-122-05/D-122-06) — a BASE do gate FIXMARG-03 segue a
 * feature flag `metrics.performance_company_first_score`, não o shape do
 * relatório. Mocka `DesempenhoScoreService::compute()` por inteiro (Mockery
 * partial) para controlar precisamente o shape de `margem_amostra` que
 * chega ao gate — o cálculo real de `margem_amostra` já foi provado pela
 * `MargemAmostraPpTest` (122-02); esta suíte testa só a DECISÃO do gate em
 * `ConsolidarMesDesempenho`.
 *
 * Todos os testes asseram por RECONSULTA ao banco, nunca por stdout do
 * comando (122-CONTEXT.md item 5).
 */
class GateFixmarg03BaseTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-122-gate',
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    private function criarUserAnalista(string $nome): User
    {
        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $this->cargoAnalistaId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /** Linha no shape de `CompanyScoreService::computeEmpresasScore()`. */
    private function linha(int $companyId): object
    {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'nota_empresa'          => 4.0,
            'nota_empresa_parcial'  => 4.0,
            'margem_var_pp'         => 2.0,
            'componentes_presentes' => 3,
        ];
    }

    /** Monta o payload completo de `compute()` — só o necessário pro gate. */
    private function resultado(array $margemAmostra, array $empresasScore): array
    {
        return [
            'sem_carteira'          => false,
            'nota_final'            => 4.0,
            'faixa_bonus'           => 'intermediario',
            'empresas_carteira'     => count($empresasScore),
            'empresas_com_baseline' => count($empresasScore),
            'margem_amostra'        => $margemAmostra,
            'empresas_score'        => $empresasScore,
            // Sinal canônico (D-05 da Fase 121) de que o shadow rodou —
            // basta existir a chave, o valor não é lido pelo gate.
            'score_status_por_empresa' => 'official',
        ];
    }

    private function mockCompute(array $result): void
    {
        $this->mock(DesempenhoScoreService::class, function ($mock) use ($result) {
            $mock->shouldReceive('compute')->once()->andReturn($result);
        });
    }

    // ═══ Cenário 1 — flag desligada, legado saudável → CONGELA ═════════════

    #[Test]
    public function flag_desligada_com_pp_degradado_e_legado_saudavel_congela_usando_o_legado(): void
    {
        // Flag continua false (default de config/metrics.php) — não setar.
        $user = $this->criarUserAnalista('Gate Legado Saudavel');
        $c1   = Company::factory()->create();

        $this->mockCompute($this->resultado(
            [
                'n_real' => 5, 'n_elegivel' => 10, 'cobertura' => 0.50, 'base' => 'margem_var_pp',
                'legado' => ['n_real' => 9, 'n_elegivel' => 10, 'cobertura' => 0.90],
            ],
            [$this->linha($c1->id)],
        ));

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertTrue(
            DesempenhoScoreSnapshot::where('user_id', $user->id)->whereDate('mes_referencia', '2026-07-01')->exists()
        );

        $rows = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->get();

        $this->assertCount(1, $rows, 'Gate leu a cobertura LEGADA (0.90 >= 0.7) — congelou e gravou a linha por empresa.');
        $this->assertSame('consolidar_mes', $rows->first()->origem);
    }

    // ═══ Cenário 2 — flag desligada, legado degradado → RECUSA ═════════════

    #[Test]
    public function flag_desligada_com_pp_saudavel_e_legado_degradado_recusa_usando_o_legado(): void
    {
        $user = $this->criarUserAnalista('Gate Legado Degradado');
        $c1   = Company::factory()->create();

        // Snapshot anterior já congelado — tem que ser PRESERVADO.
        $anterior = DesempenhoScoreSnapshot::create([
            'user_id'              => $user->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => '2026-07-01',
            'score'                => 77,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 3.85, 'faixa_bonus' => 'intermediario'],
        ]);

        // Linha por empresa pré-existente — tem que permanecer intocada (D-122-06).
        app(CompanyScoreSnapshotWriter::class)->sync(
            $user,
            Carbon::parse('2026-07-01'),
            collect([$this->linha($c1->id)]),
            CompanyScoreSnapshotWriter::ORIGEM_CONSOLIDAR_MES,
        );
        $linhaAntes = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->first();

        $this->mockCompute($this->resultado(
            [
                'n_real' => 9, 'n_elegivel' => 10, 'cobertura' => 0.90, 'base' => 'margem_var_pp',
                'legado' => ['n_real' => 5, 'n_elegivel' => 10, 'cobertura' => 0.50],
            ],
            [$this->linha($c1->id)],
        ));

        Log::spy();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $anterior->refresh();
        $this->assertSame(77, $anterior->score, 'Gate leu a cobertura LEGADA (0.50 < 0.7) — RECUSOU e preservou o snapshot antigo.');

        $linhaDepois = DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->first();
        $this->assertSame($linhaAntes->updated_at->toDateTimeString(), $linhaDepois->updated_at->toDateTimeString(),
            'D-122-06: recusa do gate NÃO chama o writer — a linha por empresa fica intocada.');
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count());
    }

    // ═══ Cenário 3 — flag ligada, pp degradado → RECUSA usando pp ══════════

    #[Test]
    public function flag_ligada_com_pp_degradado_recusa_usando_pp(): void
    {
        config(['metrics.performance_company_first_score' => true]);

        $user = $this->criarUserAnalista('Gate Flag Ligada');
        $c1   = Company::factory()->create();

        $this->mockCompute($this->resultado(
            [
                'n_real' => 5, 'n_elegivel' => 10, 'cobertura' => 0.50, 'base' => 'margem_var_pp',
                'legado' => ['n_real' => 9, 'n_elegivel' => 10, 'cobertura' => 0.90],
            ],
            [$this->linha($c1->id)],
        ));

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertDatabaseMissing('desempenho_score_snapshots', [
            'user_id'        => $user->id,
            'mes_referencia' => '2026-07-01',
        ]);
        $this->assertSame(0, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count(),
            'Flag ligada: gate leu a cobertura PP (0.50 < 0.7), mesmo com o legado saudável — RECUSOU.');
    }

    // ═══ Cenário 4 — payload sem sub-chave `legado` (shape antigo) ═════════

    #[Test]
    public function payload_sem_subchave_legado_le_o_proprio_margem_amostra_sem_erro(): void
    {
        $user = $this->criarUserAnalista('Gate Shape Antigo');
        $c1   = Company::factory()->create();

        $this->mockCompute($this->resultado(
            ['n_real' => 10, 'n_elegivel' => 10, 'cobertura' => 1.0],
            [$this->linha($c1->id)],
        ));

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertTrue(
            DesempenhoScoreSnapshot::where('user_id', $user->id)->whereDate('mes_referencia', '2026-07-01')->exists()
        );
        $this->assertSame(1, DesempenhoCompanyScoreSnapshot::where('user_id', $user->id)->count());
    }

    // ═══ Cenário 5 — Log::error registra as DUAS coberturas ════════════════

    #[Test]
    public function log_de_recusa_registra_base_gate_cobertura_pp_e_cobertura_legado(): void
    {
        $user = $this->criarUserAnalista('Gate Log Contexto');
        $c1   = Company::factory()->create();

        $this->mockCompute($this->resultado(
            [
                'n_real' => 9, 'n_elegivel' => 10, 'cobertura' => 0.90, 'base' => 'margem_var_pp',
                'legado' => ['n_real' => 5, 'n_elegivel' => 10, 'cobertura' => 0.50],
            ],
            [$this->linha($c1->id)],
        ));

        Log::spy();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = []) use ($user) {
                return is_string($message)
                    && str_contains($message, 'degradada')
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['base_gate'] ?? null) === 'var_margem_pct'
                    && abs(($context['cobertura_pp'] ?? -1) - 0.90) < 0.001
                    && abs(($context['cobertura_legado'] ?? -1) - 0.50) < 0.001;
            })
            ->atLeast()->once();
    }
}
