<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PortfolioScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 46 Plan 46-02 (REQ-46-02).
 *
 * Wave 2 — camada de leitura do histórico longitudinal:
 *   1. PerformanceController::index() enriquece cada item do ranking com:
 *       - delta_vs_ontem (score_hoje − snapshot mais recente STRICTLY antes de hoje)
 *       - delta_vs_semana_passada (score_hoje − snapshot mais recente <= today-7d)
 *   2. Novo endpoint GET /api/performance/{user}/evolucao que retorna a curva de
 *      score do user ao longo de N dias (default 30, clamp 7..365).
 *
 * Estratégia:
 *   - PortfolioScoreService trocado por fake bind() — controla score retornado
 *     pelo compute() pra simular "hoje".
 *   - Snapshots inseridos diretamente em desempenho_score_snapshots para simular
 *     histórico (dias anteriores). Não chamamos o comando do schedule.
 *   - Setor + cargos analista/estrategista no setUp() (mesmo pattern do
 *     PerformanceCargoFilterTest e DesempenhoScoreSnapshotTest da Wave 1).
 */
class DesempenhoEvolucaoTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    /**
     * Map user_id => array(payload de PortfolioScoreService::compute()).
     *
     * @var array<int, array<string, mixed>>
     */
    private array $fakeScores = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-46-02',
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

    /**
     * Cria admin (short-circuit em hasPermission) e autentica.
     */
    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin 46-02 ' . uniqid(),
            'email'    => 'admin.4602.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    private function criarUserComCargo(string $cargoSlug): User
    {
        $user = User::create([
            'name'     => 'User ' . $cargoSlug . ' ' . uniqid(),
            'email'    => $cargoSlug . '.' . uniqid() . '@ecf.test',
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

        return $user;
    }

    /**
     * Substitui PortfolioScoreService no container por uma fake controlada
     * via $this->fakeScores. Users sem fake retornam payload com score 0.
     */
    private function bindarScoreServiceFake(): void
    {
        $self = $this;
        $this->app->bind(PortfolioScoreService::class, function () use ($self) {
            return new class($self) extends PortfolioScoreService {
                public function __construct(private DesempenhoEvolucaoTest $owner) {}

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

    public function fakeScoresPublic(): array
    {
        return $this->fakeScores;
    }

    /**
     * Payload mínimo aceito pelo PerformanceController::index() (chaves
     * obrigatórias do array 'metricas' pra evitar undefined offset).
     */
    private function payloadFake(float $score, string $classificacao = 'bom'): array
    {
        return [
            'tem_base_comparativa' => true,
            'empresas_eligiveis'   => 3,
            'empresas_carteira'    => 5,
            'metricas' => [
                'crescimento_ajustado_pct' => 5.0,
                'empresas_em_crescimento'  => ['count' => 2, 'total' => 5, 'pct' => 40.0],
                'atingimento_meta'         => ['pct' => 80.0],
                'recuperacao'              => ['pct' => 50.0],
                'execucao_ads'             => ['pct' => 70.0],
                'qualidade'                => ['avg_nps' => 4.0, 'meetings' => 10, 'absenteismo_pct' => 5.0],
                'faturamento'              => ['atual' => 100000.0, 'anterior' => 95000.0],
            ],
            'pontos_categoria' => [],
            'score'            => $score,
            'classificacao'    => $classificacao,
            'periodo'          => [],
        ];
    }

    /**
     * Insere um snapshot histórico diretamente na tabela (sem rodar comando).
     */
    private function inserirSnapshot(int $userId, string $refDate, float $score, int $rankingPos = null): void
    {
        DB::table('desempenho_score_snapshots')->insert([
            'user_id'              => $userId,
            'ref_date'             => $refDate,
            'score'                => (int) round($score),
            'classificacao'        => 'bom',
            'ranking_pos'          => $rankingPos,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 5,
            'empresas_eligiveis'   => 3,
            'breakdown_json'       => json_encode([]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    /**
     * Extrai 1 item do ranking pelo user_id (props Inertia).
     */
    private function itemDoRanking($response, int $userId): ?array
    {
        $ranking = $response->viewData('page')['props']['ranking'] ?? [];
        foreach ($ranking as $item) {
            if (($item['id'] ?? null) === $userId) {
                return $item;
            }
        }
        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TAREFA 1 — Deltas no ranking de /performance
    // ═════════════════════════════════════════════════════════════════════════

    public function test_delta_null_quando_sem_snapshot_anterior(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(75.0)];

        // Nenhum snapshot histórico inserido.
        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item, 'User deve aparecer no ranking.');
        $this->assertArrayHasKey('delta_vs_ontem', $item, 'Item deve carregar a chave delta_vs_ontem.');
        $this->assertArrayHasKey('delta_vs_semana_passada', $item, 'Item deve carregar a chave delta_vs_semana_passada.');
        $this->assertNull($item['delta_vs_ontem'], 'Sem snapshot anterior, delta_vs_ontem deve ser null.');
        $this->assertNull($item['delta_vs_semana_passada'], 'Sem snapshot anterior, delta_vs_semana_passada deve ser null.');
    }

    public function test_delta_vs_ontem_calculado_corretamente(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(75.0)];

        // Snapshot de ontem com score 70.
        $this->inserirSnapshot($u->id, now()->subDay()->toDateString(), 70.0);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item);
        // 75 - 70 = 5.0
        $this->assertSame(5.0, $item['delta_vs_ontem']);
    }

    public function test_delta_vs_semana_passada_usa_mais_recente_dentro_da_janela(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(80.0)];

        // Snapshots: D-7 score 60, D-8 score 50, D-9 score 40.
        // delta_vs_semana_passada deve usar D-7 (mais recente <= today-7d) → 80-60=20.
        $this->inserirSnapshot($u->id, now()->subDays(7)->toDateString(), 60.0);
        $this->inserirSnapshot($u->id, now()->subDays(8)->toDateString(), 50.0);
        $this->inserirSnapshot($u->id, now()->subDays(9)->toDateString(), 40.0);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item);
        $this->assertSame(20.0, $item['delta_vs_semana_passada'],
            'Deve usar snapshot D-7 (mais recente dentro da janela <= today-7d).');
        // delta_vs_ontem usa D-7 também (snapshot mais recente strict < hoje).
        $this->assertSame(20.0, $item['delta_vs_ontem']);
    }

    public function test_delta_vs_ontem_pode_ser_negativo(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(70.0)];

        $this->inserirSnapshot($u->id, now()->subDay()->toDateString(), 80.0);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item);
        // 70 - 80 = -10.0
        $this->assertSame(-10.0, $item['delta_vs_ontem']);
    }

    public function test_delta_vs_ontem_pega_anterior_disponivel_se_cron_falhou(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(75.0)];

        // Cron falhou em D-1 e D-2: snapshot mais recente é D-3 (score 65).
        $this->inserirSnapshot($u->id, now()->subDays(3)->toDateString(), 65.0);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item);
        // 75 - 65 = 10.0 (usa D-3 porque é o mais recente strict < hoje).
        $this->assertSame(10.0, $item['delta_vs_ontem']);
        // delta_vs_semana_passada → null (D-3 não cabe na janela <= today-7d).
        $this->assertNull($item['delta_vs_semana_passada']);
    }
}
