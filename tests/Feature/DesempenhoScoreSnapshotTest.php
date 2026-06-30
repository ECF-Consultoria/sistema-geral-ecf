<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PortfolioScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Suite Feature — Phase 46 Plan 46-01 (REQ-46-01).
 *
 * Cobre o backbone de persistência diária do score de desempenho:
 *  - Migration `desempenho_score_snapshots` (colunas + unique composto)
 *  - Model `DesempenhoScoreSnapshot` com cast `breakdown_json => array`
 *  - Comando Artisan `desempenho:snapshot-scores` (idempotente, ranking_pos
 *    populado pós-lote, filtro de cargo via user_setores → cargos)
 *
 * Estratégia:
 *  - PortfolioScoreService é trocado por uma fake bind() no container que
 *    devolve payload pré-fabricado por user (sem tocar Adman/DB de métricas).
 *  - Setor + cargos analista/estrategista são criados em setUp() — mesmo
 *    pattern do PerformanceCargoFilterTest.
 *  - Ranking esperado: score DESC, com 1 = melhor.
 */
class DesempenhoScoreSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;
    private int $cargoPublicadorId;

    /**
     * Map user_id => array(payload do PortfolioScoreService::compute()).
     * Definido por teste via $this->fakeScoresPara().
     *
     * @var array<int, array<string, mixed>>
     */
    private array $fakeScores = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Setor "Performance" + cargos analista/estrategista (mesmos do
        // PerformanceCargoFilterTest). Adicionamos cargo publicador pra
        // garantir que ele NÃO entra no snapshot.
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-46',
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

        $this->cargoPublicadorId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Publicador',
            'slug'       => 'publicador',
            'active'     => true,
            'ordem'      => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Cria um user consultor com cargo no pivot user_setores.
     */
    private function criarUserComCargo(string $cargoSlug): User
    {
        $user = User::create([
            'name'     => 'User ' . $cargoSlug . ' ' . uniqid(),
            'email'    => $cargoSlug . '.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $cargoId = match ($cargoSlug) {
            'estrategista' => $this->cargoEstrategistaId,
            'publicador'   => $this->cargoPublicadorId,
            default        => $this->cargoAnalistaId,
        };

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /**
     * Substitui PortfolioScoreService no container por uma fake controlada
     * via $this->fakeScores. Cada user_id mapeia pro próprio payload retornado
     * em compute(). Users sem fake retornam payload default (score 0).
     */
    private function bindarScoreServiceFake(): void
    {
        $self = $this;
        $this->app->bind(PortfolioScoreService::class, function () use ($self) {
            return new class($self) extends PortfolioScoreService {
                public function __construct(private DesempenhoScoreSnapshotTest $owner) {}

                public function compute(User $user): array
                {
                    if (isset($this->owner->fakeScoresPublic()[$user->id])) {
                        return $this->owner->fakeScoresPublic()[$user->id];
                    }
                    return [
                        'tem_base_comparativa' => false,
                        'empresas_eligiveis'   => 0,
                        'empresas_carteira'    => 0,
                        'metricas'             => [],
                        'pontos_categoria'     => [],
                        'score'                => 0.0,
                        'classificacao'        => 'critico',
                        'periodo'              => [],
                    ];
                }
            };
        });
    }

    /**
     * Exposto pública porque a closure anônima precisa ler $this->fakeScores
     * (que é private). Mantemos só o getter.
     */
    public function fakeScoresPublic(): array
    {
        return $this->fakeScores;
    }

    /**
     * Constrói um payload de PortfolioScoreService::compute() fake.
     */
    private function payloadFake(float $score, string $classificacao = 'bom', array $metricasExtras = []): array
    {
        return [
            'tem_base_comparativa' => true,
            'empresas_eligiveis'   => 5,
            'empresas_carteira'    => 7,
            'metricas' => array_merge([
                'crescimento_ajustado_pct' => 7.5,
                'empresas_em_crescimento'  => ['count' => 3, 'total' => 5, 'pct' => 60.0],
                'atingimento_meta'         => ['pct' => 82.0, 'target_value' => 100000, 'realized_value' => 82000, 'origem' => 'portfolio'],
                'recuperacao'              => ['recuperadas' => 1, 'em_queda' => 2, 'pct' => 50.0],
                'execucao_ads'             => ['com_ads_ativos' => 4, 'total' => 5, 'pct' => 80.0],
                'qualidade'                => ['avg_nps' => 4.2, 'meetings' => 12, 'absenteismo_pct' => 8.3],
                'faturamento'              => ['atual' => 120000.0, 'anterior' => 110000.0],
            ], $metricasExtras),
            'pontos_categoria' => [],
            'score'            => $score,
            'classificacao'    => $classificacao,
            'periodo'          => [
                'from' => '2026-05-31',
                'to'   => '2026-06-30',
            ],
        ];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TAREFA 1 — Migration: schema + unique constraint
    // ═════════════════════════════════════════════════════════════════════════

    public function test_tabela_tem_colunas_esperadas(): void
    {
        $this->assertTrue(
            Schema::hasTable('desempenho_score_snapshots'),
            'Tabela desempenho_score_snapshots deve existir após migrate.'
        );

        $this->assertTrue(
            Schema::hasColumns('desempenho_score_snapshots', [
                'id',
                'user_id',
                'ref_date',
                'score',
                'classificacao',
                'ranking_pos',
                'tem_base_comparativa',
                'empresas_carteira',
                'empresas_eligiveis',
                'breakdown_json',
                'created_at',
                'updated_at',
            ]),
            'Tabela desempenho_score_snapshots deve ter todas as colunas do schema.'
        );
    }

    public function test_unique_constraint_user_id_ref_date(): void
    {
        $user = $this->criarUserComCargo('analista');

        DB::table('desempenho_score_snapshots')->insert([
            'user_id'              => $user->id,
            'ref_date'             => '2026-06-30',
            'score'                => 70,
            'classificacao'        => 'bom',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 5,
            'empresas_eligiveis'   => 4,
            'breakdown_json'       => json_encode([]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('desempenho_score_snapshots')->insert([
            'user_id'              => $user->id,
            'ref_date'             => '2026-06-30',
            'score'                => 80,
            'classificacao'        => 'bom',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 5,
            'empresas_eligiveis'   => 4,
            'breakdown_json'       => json_encode([]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TAREFA 2 — Command: persistência, idempotência, ranking_pos, cast
    // ═════════════════════════════════════════════════════════════════════════

    public function test_command_persiste_snapshot_por_user_eligivel(): void
    {
        $this->bindarScoreServiceFake();

        $u1 = $this->criarUserComCargo('analista');
        $u2 = $this->criarUserComCargo('estrategista');

        $this->fakeScores = [
            $u1->id => $this->payloadFake(72.0, 'bom'),
            $u2->id => $this->payloadFake(88.0, 'excelente'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('desempenho_score_snapshots')->count(),
            'Deve criar exatamente 1 snapshot por user elegível (analista/estrategista).');

        $snap1 = DB::table('desempenho_score_snapshots')
            ->where('user_id', $u1->id)
            ->where('ref_date', '2026-06-30')
            ->first();
        $this->assertNotNull($snap1, 'Snapshot do analista deve existir.');
        $this->assertSame(72, (int) $snap1->score);
        $this->assertSame('bom', $snap1->classificacao);
        $this->assertSame(1, (int) $snap1->tem_base_comparativa);
        $this->assertSame(7, (int) $snap1->empresas_carteira);
        $this->assertSame(5, (int) $snap1->empresas_eligiveis);
    }

    public function test_command_idempotente_re_run_atualiza_nao_duplica(): void
    {
        $this->bindarScoreServiceFake();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [
            $u->id => $this->payloadFake(60.0, 'atencao'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('desempenho_score_snapshots')
            ->where('ref_date', '2026-06-30')->count());

        // Segundo run — score atualizado, mas continua 1 linha por (user, data).
        $this->fakeScores = [
            $u->id => $this->payloadFake(85.0, 'excelente'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('desempenho_score_snapshots')
            ->where('ref_date', '2026-06-30')->count(),
            'Re-run no mesmo dia NÃO deve duplicar — updateOrCreate atualiza.');

        $snap = DB::table('desempenho_score_snapshots')
            ->where('user_id', $u->id)
            ->where('ref_date', '2026-06-30')
            ->first();
        $this->assertSame(85, (int) $snap->score, 'Score deve refletir o 2º run.');
        $this->assertSame('excelente', $snap->classificacao);
    }

    public function test_breakdown_json_persistido_como_array(): void
    {
        $this->bindarScoreServiceFake();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [
            $u->id => $this->payloadFake(70.0, 'bom'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $model = \App\Models\DesempenhoScoreSnapshot::query()
            ->where('user_id', $u->id)
            ->firstOrFail();

        $this->assertIsArray($model->breakdown_json,
            'Cast breakdown_json => array deve devolver array PHP.');
        $this->assertArrayHasKey('crescimento_ajustado_pct', $model->breakdown_json);
        $this->assertArrayHasKey('atingimento_meta', $model->breakdown_json);
        $this->assertSame(82.0, $model->breakdown_json['atingimento_meta']['pct']);
    }

    public function test_ranking_pos_ordenado_por_score_desc(): void
    {
        $this->bindarScoreServiceFake();

        $u1 = $this->criarUserComCargo('analista');      // score 50 → 3º
        $u2 = $this->criarUserComCargo('analista');      // score 80 → 1º
        $u3 = $this->criarUserComCargo('estrategista');  // score 65 → 2º

        $this->fakeScores = [
            $u1->id => $this->payloadFake(50.0, 'atencao'),
            $u2->id => $this->payloadFake(80.0, 'bom'),
            $u3->id => $this->payloadFake(65.0, 'bom'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $snaps = DB::table('desempenho_score_snapshots')
            ->where('ref_date', '2026-06-30')
            ->orderBy('user_id')
            ->get()
            ->keyBy('user_id');

        $this->assertSame(3, (int) $snaps[$u1->id]->ranking_pos, 'User com score 50 deve ser 3º.');
        $this->assertSame(1, (int) $snaps[$u2->id]->ranking_pos, 'User com score 80 deve ser 1º.');
        $this->assertSame(2, (int) $snaps[$u3->id]->ranking_pos, 'User com score 65 deve ser 2º.');
    }

    public function test_ignora_users_sem_cargo_analista_ou_estrategista(): void
    {
        $this->bindarScoreServiceFake();

        $analista   = $this->criarUserComCargo('analista');
        $publicador = $this->criarUserComCargo('publicador');

        $this->fakeScores = [
            $analista->id   => $this->payloadFake(70.0, 'bom'),
            $publicador->id => $this->payloadFake(90.0, 'excelente'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('desempenho_score_snapshots')->count(),
            'Apenas analista/estrategista entram no snapshot — publicador é ignorado.');
        $this->assertNotNull(
            DB::table('desempenho_score_snapshots')
                ->where('user_id', $analista->id)->first(),
            'Snapshot do analista deve existir.'
        );
        $this->assertNull(
            DB::table('desempenho_score_snapshots')
                ->where('user_id', $publicador->id)->first(),
            'Snapshot do publicador NÃO deve existir.'
        );
    }

    public function test_filtro_user_isolado_via_opcao(): void
    {
        $this->bindarScoreServiceFake();

        $u1 = $this->criarUserComCargo('analista');
        $u2 = $this->criarUserComCargo('analista');

        $this->fakeScores = [
            $u1->id => $this->payloadFake(60.0, 'atencao'),
            $u2->id => $this->payloadFake(75.0, 'bom'),
        ];

        $this->artisan('desempenho:snapshot-scores', [
            '--data' => '2026-06-30',
            '--user' => $u1->id,
        ])->assertExitCode(0);

        $this->assertSame(1, DB::table('desempenho_score_snapshots')->count(),
            'Com --user, apenas 1 snapshot deve ser criado.');
        $this->assertNotNull(
            DB::table('desempenho_score_snapshots')->where('user_id', $u1->id)->first()
        );
        $this->assertNull(
            DB::table('desempenho_score_snapshots')->where('user_id', $u2->id)->first()
        );
    }
}
