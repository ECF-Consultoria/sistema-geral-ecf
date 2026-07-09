<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
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
     * Substitui DesempenhoScoreService no container por uma fake controlada
     * via $this->fakeScores. Users sem fake retornam payload sem_carteira=true
     * (mesma semântica v2 do DESEMP-10 — user default é pulado no ranking).
     *
     * Phase 74 D-05/D-06 — v1 (`PortfolioScoreService`) apagado; v2
     * (`DesempenhoScoreService`) tem assinatura `compute(User, Carbon)`.
     */
    private function bindarScoreServiceFake(): void
    {
        $self = $this;
        $this->app->bind(DesempenhoScoreService::class, function () use ($self) {
            return new class($self) extends DesempenhoScoreService {
                public function __construct(private DesempenhoEvolucaoTest $owner) {}

                public function compute(User $user, Carbon $mesReferencia): array
                {
                    if (isset($this->owner->fakeScoresPublic()[$user->id])) {
                        return $this->owner->fakeScoresPublic()[$user->id];
                    }
                    return [
                        'user_id'               => $user->id,
                        'user_name'             => $user->name,
                        'mes_referencia'        => $mesReferencia->toDateString(),
                        'sem_carteira'          => true,
                        'motivo'                => 'default fake — sem carteira',
                        'empresas_carteira'     => 0,
                        'empresas_com_baseline' => 0,
                        'componentes'           => [
                            'nps_medio'           => null,
                            'var_faturamento_pct' => null,
                            'var_margem_pct'      => null,
                            'absenteismo_pct'     => null,
                        ],
                        'nota_final'      => null,
                        'faixa_bonus'     => null,
                        'faixa_promovida' => false,
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
     * Payload mínimo aceito pelo PerformanceController::index() no shape v2
     * (Phase 74 D-07). Cada teste usa este helper para descrever a nota do
     * user "hoje" e comparar contra snapshots históricos inseridos direto na
     * tabela (que continuam com colunas legacy `score` int 0-100).
     *
     * @param float  $score          score legado 0-100 (compat) — usado como
     *                                base para `nota_final = score / 20`.
     * @param string $classificacao  slug da faixa de bônus.
     */
    private function payloadFake(float $score, string $classificacao = 'basico'): array
    {
        $nota = round($score / 20, 2);
        return [
            'user_id'               => 0,
            'user_name'             => '',
            'mes_referencia'        => now()->startOfMonth()->toDateString(),
            'sem_carteira'          => false,
            'motivo'                => null,
            'empresas_carteira'     => 5,
            'empresas_com_baseline' => 3,
            'componentes' => [
                'nps_medio'           => 4.0,
                'var_faturamento_pct' => 3.0,
                'var_margem_pct'      => 2.8,
                'absenteismo_pct'     => null,
            ],
            'nota_final'      => $nota,
            'faixa_bonus'     => $classificacao,
            'faixa_promovida' => false,
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
        // Phase 74 D-07 · deltas ficam expressos na ESCALA 0-5 da nota_final.
        // Hoje: score=75 → nota=3.75. Snap ontem: score=70 → nota=3.50.
        // delta = 3.75 - 3.50 = 0.25
        $this->assertSame(0.25, $item['delta_vs_ontem']);
    }

    public function test_delta_vs_semana_passada_usa_mais_recente_dentro_da_janela(): void
    {
        $this->bindarScoreServiceFake();
        $this->actingAsAdmin();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [$u->id => $this->payloadFake(80.0)];

        // Snapshots: D-7 score 60, D-8 score 50, D-9 score 40.
        // delta_vs_semana_passada deve usar D-7 (mais recente <= today-7d).
        // Hoje nota=4.00; D-7 nota=3.00 → delta = 1.00.
        $this->inserirSnapshot($u->id, now()->subDays(7)->toDateString(), 60.0);
        $this->inserirSnapshot($u->id, now()->subDays(8)->toDateString(), 50.0);
        $this->inserirSnapshot($u->id, now()->subDays(9)->toDateString(), 40.0);

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $item = $this->itemDoRanking($response, $u->id);
        $this->assertNotNull($item);
        // Phase 74 D-07 · escala 0-5. 80/20=4.00; 60/20=3.00 → delta=1.00.
        $this->assertSame(1.0, $item['delta_vs_semana_passada'],
            'Deve usar snapshot D-7 (mais recente dentro da janela <= today-7d).');
        $this->assertSame(1.0, $item['delta_vs_ontem']);
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
        // Phase 74 D-07 · escala 0-5. 70/20=3.50; 80/20=4.00 → delta=-0.50.
        $this->assertSame(-0.5, $item['delta_vs_ontem']);
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
        // Phase 74 D-07 · escala 0-5. 75/20=3.75; 65/20=3.25 → delta=0.50.
        $this->assertSame(0.5, $item['delta_vs_ontem']);
        // delta_vs_semana_passada → null (D-3 não cabe na janela <= today-7d).
        $this->assertNull($item['delta_vs_semana_passada']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // TAREFA 2 — Endpoint GET /api/performance/{user}/evolucao
    // ═════════════════════════════════════════════════════════════════════════

    public function test_evolucao_retorna_200_com_permission_core_performance(): void
    {
        $this->actingAsAdmin();
        $u = $this->criarUserComCargo('analista');

        $response = $this->getJson(route('performance.evolucao', $u->id));
        $response->assertStatus(200);

        $payload = $response->json();
        $this->assertArrayHasKey('user_id', $payload);
        $this->assertArrayHasKey('period', $payload);
        $this->assertArrayHasKey('serie', $payload);
        $this->assertSame($u->id, $payload['user_id']);
        $this->assertSame(30, $payload['period'], 'Period default deve ser 30.');
        $this->assertIsArray($payload['serie']);
    }

    public function test_evolucao_retorna_403_sem_permission(): void
    {
        // User comum (sem permission core.performance e sem isAdmin).
        $semPerm = User::create([
            'name'     => 'Sem Perm ' . uniqid(),
            'email'    => 'sem.perm.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);
        $this->actingAs($semPerm);

        $alvo = $this->criarUserComCargo('analista');

        $response = $this->getJson(route('performance.evolucao', $alvo->id));
        $response->assertStatus(403);
    }

    public function test_evolucao_serie_ordenada_asc_por_date(): void
    {
        $this->actingAsAdmin();
        $u = $this->criarUserComCargo('analista');

        // Insere em ordem invertida para validar que o ORDER BY no controller é ASC.
        $this->inserirSnapshot($u->id, now()->subDays(1)->toDateString(), 80.0, 1);
        $this->inserirSnapshot($u->id, now()->subDays(5)->toDateString(), 60.0, 3);
        $this->inserirSnapshot($u->id, now()->subDays(3)->toDateString(), 70.0, 2);

        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=30');
        $response->assertStatus(200);

        $serie = $response->json('serie');
        $this->assertCount(3, $serie);

        // Datas devem sair ASC: D-5 → D-3 → D-1.
        $datas = array_column($serie, 'date');
        $this->assertSame(now()->subDays(5)->toDateString(), $datas[0]);
        $this->assertSame(now()->subDays(3)->toDateString(), $datas[1]);
        $this->assertSame(now()->subDays(1)->toDateString(), $datas[2]);

        // Cada ponto tem chaves date, score, ranking_pos.
        $this->assertArrayHasKey('date', $serie[0]);
        $this->assertArrayHasKey('score', $serie[0]);
        $this->assertArrayHasKey('ranking_pos', $serie[0]);
        $this->assertSame(60, $serie[0]['score']);
        $this->assertSame(3, $serie[0]['ranking_pos']);
    }

    public function test_evolucao_respeita_period_query(): void
    {
        $this->actingAsAdmin();
        $u = $this->criarUserComCargo('analista');

        // 3 snapshots: D-3, D-10, D-20.
        $this->inserirSnapshot($u->id, now()->subDays(3)->toDateString(), 80.0);
        $this->inserirSnapshot($u->id, now()->subDays(10)->toDateString(), 70.0);
        $this->inserirSnapshot($u->id, now()->subDays(20)->toDateString(), 60.0);

        // period=7 → só D-3 entra.
        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=7');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('serie'));
        $this->assertSame(7, $response->json('period'));

        // period=30 → todos os 3 entram.
        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=30');
        $this->assertCount(3, $response->json('serie'));
        $this->assertSame(30, $response->json('period'));
    }

    public function test_evolucao_clamp_period(): void
    {
        $this->actingAsAdmin();
        $u = $this->criarUserComCargo('analista');

        // period acima do max → vira 365.
        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=999');
        $response->assertStatus(200);
        $this->assertSame(365, $response->json('period'));

        // period abaixo do min → vira 7.
        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=2');
        $response->assertStatus(200);
        $this->assertSame(7, $response->json('period'));

        // period invalido (string) → default 30.
        $response = $this->getJson(route('performance.evolucao', $u->id) . '?period=abc');
        $response->assertStatus(200);
        $this->assertSame(30, $response->json('period'));
    }

    public function test_evolucao_serie_vazia_quando_sem_snapshots(): void
    {
        $this->actingAsAdmin();
        $u = $this->criarUserComCargo('analista');

        $response = $this->getJson(route('performance.evolucao', $u->id));
        $response->assertStatus(200);
        $this->assertSame([], $response->json('serie'));
    }
}
