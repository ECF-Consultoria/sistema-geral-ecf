<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
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
     * Substitui DesempenhoScoreService no container por uma fake controlada
     * via $this->fakeScores. Cada user_id mapeia pro próprio payload retornado
     * em compute(). Users sem fake retornam payload default (score 0).
     *
     * Phase 74 D-05/D-06 — o serviço v1 (`PortfolioScoreService`) foi apagado
     * e substituído pelo `DesempenhoScoreService` no mesmo commit. A assinatura
     * de compute() ganhou `Carbon $mesReferencia` como 2º parâmetro (D-07).
     */
    private function bindarScoreServiceFake(): void
    {
        $self = $this;
        $this->app->bind(DesempenhoScoreService::class, function () use ($self) {
            return new class($self) extends DesempenhoScoreService {
                public function __construct(private DesempenhoScoreSnapshotTest $owner) {}

                // Fase 102 (deviation Rule 3 — blocking, fora do edit-set
                // declarado): compute() do parent ganhou `?array
                // $periodoOverride = null` (BON-01/02) — a assinatura do
                // override precisa ser compatível (LSP) senão é fatal error
                // de PHP em tempo de boot, não um teste que falha.
                public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null): array
                {
                    if (isset($this->owner->fakeScoresPublic()[$user->id])) {
                        return $this->owner->fakeScoresPublic()[$user->id];
                    }
                    // Payload default — user sem fake vira `sem_carteira=true`
                    // (spec DESEMP-10), garantindo que ele NÃO é gravado no
                    // snapshot pelo command (mesmo comportamento anterior:
                    // score=0/classificacao=critico faziam o Model create,
                    // mas em v2 com DESEMP-10 os users default são pulados).
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

    /**
     * Exposto pública porque a closure anônima precisa ler $this->fakeScores
     * (que é private). Mantemos só o getter.
     */
    public function fakeScoresPublic(): array
    {
        return $this->fakeScores;
    }

    /**
     * Constrói um payload de `DesempenhoScoreService::compute()` fake no
     * shape v2 (Phase 74 D-07).
     *
     * @param float  $score          score legado 0-100 (compat) — usado pra
     *                                calcular `nota_final = score / 20`
     * @param string $classificacao  slug da faixa de bônus (ex. `sem_bonus`,
     *                                `basico`, `intermediario`, `maximo`)
     * @param array  $componentes    override dos 4 componentes (default:
     *                                nps=4.0, var_fat=3.0, var_margem=2.8,
     *                                absenteismo=null)
     */
    private function payloadFake(float $score, string $classificacao = 'basico', array $componentes = []): array
    {
        $nota = round($score / 20, 2);

        return [
            'user_id'               => 0,   // populado pelo compute() fake usando $user
            'user_name'             => '',
            'mes_referencia'        => now()->startOfMonth()->toDateString(),
            'sem_carteira'          => false,
            'motivo'                => null,
            'empresas_carteira'     => 7,
            'empresas_com_baseline' => 5,
            'componentes' => array_merge([
                'nps_medio'           => 4.0,
                'var_faturamento_pct' => 3.0,
                'var_margem_pct'      => 2.8,
                'absenteismo_pct'     => null,
            ], $componentes),
            'nota_final'      => $nota,
            'faixa_bonus'     => $classificacao,
            'faixa_promovida' => false,
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

    public function test_unique_constraint_user_id_ref_date_mes_referencia(): void
    {
        // Phase 74 D-03 · o unique legado `(user_id, ref_date)` foi substituído
        // pelo unique `(user_id, ref_date, mes_referencia)` — permite que a
        // MESMA dupla (user, ref_date) apareça 1x como diário
        // (`mes_referencia=NULL`) e 1x como mensal (`mes_referencia=YYYY-MM-01`).
        //
        // Este teste valida que 2 rows MENSAIS iguais (mesmo mes_referencia)
        // colidem no unique. NULL vs NULL não colide (SQL padrão trata NULL
        // como distinto), então testamos com mes_referencia populado.
        $user = $this->criarUserComCargo('analista');

        DB::table('desempenho_score_snapshots')->insert([
            'user_id'              => $user->id,
            'ref_date'             => '2026-06-01',
            'mes_referencia'       => '2026-06-01',
            'score'                => 70,
            'classificacao'        => 'basico',
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
            'ref_date'             => '2026-06-01',
            'mes_referencia'       => '2026-06-01',
            'score'                => 80,
            'classificacao'        => 'intermediario',
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

        // Phase 74 D-07 · shape v2 usa `faixa_bonus` como slug e `nota_final`
        // como escala 0-5 (score legado = nota_final * 20).
        $this->fakeScores = [
            $u1->id => $this->payloadFake(72.0, 'basico'),         // nota_final 3.60
            $u2->id => $this->payloadFake(88.0, 'intermediario'),  // nota_final 4.40
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('desempenho_score_snapshots')->count(),
            'Deve criar exatamente 1 snapshot por user elegível (analista/estrategista).');

        // Lemos via Model pra que o cast de date normalize o formato (SQLite armazena
        // como 'YYYY-MM-DD 00:00:00' enquanto a comparação via Eloquent é por Carbon).
        $snap1 = \App\Models\DesempenhoScoreSnapshot::query()
            ->where('user_id', $u1->id)
            ->whereDate('ref_date', '2026-06-30')
            ->first();
        $this->assertNotNull($snap1, 'Snapshot do analista deve existir.');
        $this->assertSame(72, (int) $snap1->score);
        $this->assertSame('basico', $snap1->classificacao);
        $this->assertTrue((bool) $snap1->tem_base_comparativa);
        $this->assertSame(7, (int) $snap1->empresas_carteira);
        $this->assertSame(5, (int) $snap1->empresas_eligiveis);
    }

    public function test_command_idempotente_re_run_atualiza_nao_duplica(): void
    {
        $this->bindarScoreServiceFake();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [
            $u->id => $this->payloadFake(60.0, 'sem_bonus'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        // 1 snapshot total (SQLite armazena date como datetime, então whereDate normaliza).
        $this->assertSame(1, DB::table('desempenho_score_snapshots')->count());

        // Segundo run — score atualizado, mas continua 1 linha por (user, data).
        $this->fakeScores = [
            $u->id => $this->payloadFake(85.0, 'intermediario'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('desempenho_score_snapshots')->count(),
            'Re-run no mesmo dia NÃO deve duplicar — updateOrCreate atualiza.');

        $snap = \App\Models\DesempenhoScoreSnapshot::query()
            ->where('user_id', $u->id)
            ->whereDate('ref_date', '2026-06-30')
            ->firstOrFail();
        $this->assertSame(85, (int) $snap->score, 'Score deve refletir o 2º run.');
        $this->assertSame('intermediario', $snap->classificacao);
    }

    public function test_breakdown_json_persistido_como_array(): void
    {
        $this->bindarScoreServiceFake();

        $u = $this->criarUserComCargo('analista');
        $this->fakeScores = [
            $u->id => $this->payloadFake(70.0, 'basico'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        $model = \App\Models\DesempenhoScoreSnapshot::query()
            ->where('user_id', $u->id)
            ->firstOrFail();

        $this->assertIsArray($model->breakdown_json,
            'Cast breakdown_json => array deve devolver array PHP.');
        // Phase 74 D-07 · shape v2 — chaves canônicas do compute()
        // (`componentes`, `nota_final`, `faixa_bonus`).
        $this->assertArrayHasKey('componentes', $model->breakdown_json);
        $this->assertArrayHasKey('nota_final', $model->breakdown_json);
        $this->assertArrayHasKey('faixa_bonus', $model->breakdown_json);
        $this->assertEqualsWithDelta(3.50, $model->breakdown_json['nota_final'], 0.001,
            'Nota final = score/20 = 70/20 = 3.50 no payload fake.');
    }

    public function test_ranking_pos_ordenado_por_score_desc(): void
    {
        $this->bindarScoreServiceFake();

        $u1 = $this->criarUserComCargo('analista');      // score 50 → 3º
        $u2 = $this->criarUserComCargo('analista');      // score 80 → 1º
        $u3 = $this->criarUserComCargo('estrategista');  // score 65 → 2º

        $this->fakeScores = [
            $u1->id => $this->payloadFake(50.0, 'sem_bonus'),
            $u2->id => $this->payloadFake(80.0, 'basico'),
            $u3->id => $this->payloadFake(65.0, 'sem_bonus'),
        ];

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-06-30'])
            ->assertExitCode(0);

        // Lemos via Model + array nativo pra evitar Collection-com-keys-numéricas (offset posicional).
        $snaps = \App\Models\DesempenhoScoreSnapshot::query()
            ->whereDate('ref_date', '2026-06-30')
            ->get()
            ->keyBy('user_id')
            ->all();

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
            $analista->id   => $this->payloadFake(70.0, 'basico'),
            $publicador->id => $this->payloadFake(90.0, 'intermediario'),
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
            $u1->id => $this->payloadFake(60.0, 'sem_bonus'),
            $u2->id => $this->payloadFake(75.0, 'basico'),
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
