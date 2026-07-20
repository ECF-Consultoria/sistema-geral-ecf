<?php

namespace Tests\Feature\Phase74;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 74 · Plan 74-10 (Arquivo B) — Suite Feature do comando
 * `desempenho:consolidar-mes` (mensal) + preservação do comando
 * `desempenho:snapshot-scores` (diário) — DESEMP-09, DESEMP-10.
 *
 * Cobre:
 *  - Comando aceita --mes=YYYY-MM ou defaulta para mês anterior ao hoje
 *  - Idempotência: rerun no mesmo mês NÃO duplica rows (updateOrCreate)
 *  - User sem carteira é SKIPPED (não grava row)
 *  - ranking_pos populado corretamente por (mes_referencia, score DESC)
 *  - Snapshot diário preserva mes_referencia=NULL e não colide com mensal
 *
 * Reutiliza o provider stub definido no Plan 74-09
 * (`DesempenhoScoreServiceTestProviderStub`) via require_once — evita
 * duplicação e mantém a mesma superfície mockada.
 *
 * @see .planning/phases/74-.../74-10-PLAN.md (Task 2)
 * @see .planning/phases/74-.../74-CONTEXT.md §D-08, D-09
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-09
 */
class ConsolidarMesDesempenhoCommandTest extends TestCase
{
    use RefreshDatabase;

    private DesempenhoScoreServiceTestProviderStub $providerStub;
    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    /**
     * Serviço de setor performance do fixture — forward-compat Fase 91
     * (D-91-*), mesmo padrão aplicado em `Phase74/DesempenhoScoreServiceTest.
     * php::criarEmpresaNaCarteira()`. Deviation Rule 1 (91-01-PLAN.md não
     * declarava este arquivo, mas o regressão obrigatório "Phase74 completo"
     * exige que ele continue verde): sem `contratos_servico` ativo, o
     * `CarteiraContextService::forUser()` (fonte nova de `computeUniverso`)
     * não resolve o vínculo `servico_id=NULL` como elegível — os users desta
     * suite cairiam em `sem_carteira=true` e o comando pularia a gravação do
     * snapshot para todos eles.
     */
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela em 2026-08-01 14:05 BRT — batendo o schedule do cron
        // mensal (dia 1, 14:00). Mês anterior = julho/2026 (fechado).
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        // Garante que a suite do Plan 74-09 (arquivo DesempenhoScoreServiceTest.php)
        // esteja carregada — reutilizamos o stub definido lá.
        require_once __DIR__ . '/DesempenhoScoreServiceTest.php';

        $this->providerStub = new DesempenhoScoreServiceTestProviderStub();
        $this->app->instance(MetricsProviderFactory::class, $this->providerStub);

        // Fase 102 (fix plan-checker — BLOCKER) — ISOLAMENTO HTTP OBRIGATÓRIO:
        // este comando chama compute() direto (não computeCached()), que
        // agora delega margem a AdmanMetricDiffService (HTTP quando a empresa
        // tem adman_account_id). As empresas desta suite NÃO têm custId
        // (var_margem_pct não é asserida aqui), então nenhum request real é
        // esperado — o fake abaixo é defesa em profundidade.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        // Setor + cargos analista/estrategista (mesma fonte canônica dos
        // controllers de Performance — user_setores → cargos.slug).
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-74-cmd',
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

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarUserAnalista(string $nome = 'Analista'): User
    {
        $user = User::factory()->create([
            'name'   => $nome,
            'role'   => 'consultor',
            'active' => true,
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
        return $user;
    }

    /** Ver docblock da property `$servicoPerfId`. */
    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture Phase74 Consolidar)',
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $this->servicoPerfId;
    }

    private function criarEmpresaNaCarteira(User $user, string $pivotCreatedAt = '-3 months'): Company
    {
        $company = Company::factory()->create();
        $ts = Carbon::parse($pivotCreatedAt)->toDateTimeString();

        $servicoPerfId = $this->servicoPerformanceId();

        DB::table('contratos_servico')->insert([
            'company_id'       => $company->id,
            'servico_id'       => $servicoPerfId,
            'valor_contratado' => 0,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $servicoPerfId,
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);
        return $company->fresh();
    }

    /**
     * Preenche AdmanMetric + NPS legacy para a empresa cair como "empresa
     * com baseline" no cálculo — parâmetros afinados para o comando gravar
     * snapshot sem cair no branch sem_carteira ou nota null.
     */
    private function preencherDadosDaCarteira(User $u, Company $c, int $nota, string $mesYm = '2026-07'): void
    {
        $anterior = Carbon::parse($mesYm . '-01')->subMonth()->format('Y-m');

        // Revenue e margem — deltas pequenos suficientes para nota_final
        // caber na régua sem_bonus quando NPS < 4.00.
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-15')->toDateString(),
            'revenue'             => 10300,
            'contribution_margin' => 10280,
        ]);
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($anterior . '-15')->toDateString(),
            'revenue'             => 10000,
            'contribution_margin' => 10000,
        ]);

        // 1 NpsResponse legacy — score_analista int.
        $survey = NpsSurvey::factory()->for($c)->completed()->create([
            'completed_at' => Carbon::parse($mesYm . '-10 09:00:00'),
        ]);
        NpsResponse::factory()->create([
            'survey_id'      => $survey->id,
            'score_analista' => $nota,
        ]);
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    // ─── Default: mês anterior ao hoje ──────────────────────────────────────

    #[Test]
    public function test_comando_grava_snapshot_com_mes_referencia_do_mes_anterior_quando_sem_flag(): void
    {
        // DESEMP-09 · sem --mes, o comando calcula o MÊS ANTERIOR ao hoje.
        // Carbon congelado em 2026-08-01 → mes_referencia = 2026-07-01.
        $u = $this->criarUserAnalista('Analista Default');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');
        $this->preencherDadosDaCarteira($u, $c, nota: 4, mesYm: '2026-07');

        $this->artisan('desempenho:consolidar-mes')->assertSuccessful();

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();

        $this->assertNotNull($snap, 'Snapshot mensal julho/2026 deve existir para o user.');
        $this->assertNotNull($snap->breakdown_json,
            'Coluna breakdown_json deve carregar o shape completo do compute().');
        $this->assertNotNull($snap->breakdown_json['nota_final']);
    }

    // ─── Flag --mes=YYYY-MM força reprocessamento ──────────────────────────

    #[Test]
    public function test_comando_aceita_mes_flag_yyyy_mm(): void
    {
        // DESEMP-09 · --mes=YYYY-MM permite reprocessar qualquer mês (catch-up
        // pós-incident). Escolho junho/2026 para não conflitar com o default.
        $u = $this->criarUserAnalista('Analista Flag Mes');
        $c = $this->criarEmpresaNaCarteira($u, '-6 months');
        $this->preencherDadosDaCarteira($u, $c, nota: 4, mesYm: '2026-06');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertSuccessful();

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->first();

        $this->assertNotNull($snap, 'Snapshot mensal junho/2026 deve existir para o user.');
    }

    // ─── Idempotência: rerun não duplica ────────────────────────────────────

    #[Test]
    public function test_idempotencia_do_command_consolidar_mes(): void
    {
        // DESEMP-09 · rodar 2 vezes consecutivas gera 1 row por user, não 2.
        // updateOrCreate(['user_id', 'mes_referencia']) garante a
        // idempotência canônica.
        $u = $this->criarUserAnalista('Analista Idempotente');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');
        $this->preencherDadosDaCarteira($u, $c, nota: 4, mesYm: '2026-07');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()->count());

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()->count(),
            'Rerun no mesmo mês NÃO duplica — updateOrCreate atualiza a mesma row.');
    }

    // ─── Sem carteira: skipped, sem row ─────────────────────────────────────

    #[Test]
    public function test_comando_pula_user_sem_carteira_no_mes(): void
    {
        // DESEMP-10 · user sem company_users NÃO recebe row.
        $u = $this->criarUserAnalista('Analista Sem Carteira');
        // Não attach empresa → sem_carteira=true → skip.

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertFalse(
            DesempenhoScoreSnapshot::mensal()->where('user_id', $u->id)->exists(),
            'User sem carteira NÃO deve receber row de snapshot mensal.'
        );
    }

    // ─── ranking_pos populado por mes_referencia ────────────────────────────

    #[Test]
    public function test_comando_popular_ranking_pos_por_mes_referencia(): void
    {
        // DESEMP-09 · após consolidar, cada snapshot mensal recebe
        // ranking_pos calculado por score DESC, filtrando por mes_referencia.
        // Usamos NPS diferentes para gerar 3 nota_finais distintas → 3
        // ranks distintos.
        $u1 = $this->criarUserAnalista('Rank 1');
        $u2 = $this->criarUserAnalista('Rank 2');
        $u3 = $this->criarUserAnalista('Rank 3');

        // Cada user recebe 1 empresa com dados calibrados para produzir
        // notas diferentes. NPS domina a nota (var_fat e var_margem ficam
        // uniformes em 3.0/2.8 para os 3 users).
        foreach ([[$u1, 5], [$u2, 4], [$u3, 3]] as [$u, $nota]) {
            $c = $this->criarEmpresaNaCarteira($u, '-3 months');
            $this->preencherDadosDaCarteira($u, $c, nota: $nota, mesYm: '2026-07');
        }

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        // Recupera as 3 rows do mês. Score é (nota_final * 20) arredondado.
        // Ordem esperada por score DESC: u1 (nota mais alta) > u2 > u3.
        $snaps = DesempenhoScoreSnapshot::mensal()
            ->whereDate('mes_referencia', '2026-07-01')
            ->get()
            ->keyBy('user_id');

        $this->assertCount(3, $snaps, 'Deve gerar 1 snapshot por user elegível.');
        $this->assertSame(1, (int) $snaps[$u1->id]->ranking_pos,
            'User com maior nota (NPS=5) deve ser rank 1.');
        $this->assertSame(2, (int) $snaps[$u2->id]->ranking_pos,
            'User com nota intermediária (NPS=4) deve ser rank 2.');
        $this->assertSame(3, (int) $snaps[$u3->id]->ranking_pos,
            'User com menor nota (NPS=3) deve ser rank 3.');
    }

    // ─── Snapshot diário preservado (mes_referencia=NULL) ───────────────────

    #[Test]
    public function test_command_snapshot_diario_preservado_grava_mes_referencia_null(): void
    {
        // D-02 · snapshot diário (Phase 46 preservada) grava
        // `mes_referencia = NULL` e coexiste com o mensal na MESMA tabela.
        $u = $this->criarUserAnalista('Analista Diario');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');
        $this->preencherDadosDaCarteira($u, $c, nota: 4, mesYm: '2026-08');

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-08-01'])->assertSuccessful();

        $diario = DesempenhoScoreSnapshot::diario()
            ->where('user_id', $u->id)
            ->whereDate('ref_date', '2026-08-01')
            ->first();

        $this->assertNotNull($diario, 'Snapshot diário 2026-08-01 deve existir.');
        $this->assertNull($diario->mes_referencia,
            'Diário grava com mes_referencia = NULL (D-02).');
    }

    // ─── Snapshot diário também pula user sem carteira ─────────────────────

    #[Test]
    public function test_command_snapshot_diario_pula_user_sem_carteira(): void
    {
        // DESEMP-10 (também aplica ao diário) — user sem company_users
        // não gera row no snapshot diário.
        $u = $this->criarUserAnalista('Diario Sem Carteira');
        // Não attach empresa.

        $this->artisan('desempenho:snapshot-scores', ['--data' => '2026-08-01'])->assertSuccessful();

        $this->assertFalse(
            DesempenhoScoreSnapshot::diario()->where('user_id', $u->id)->exists(),
            'Snapshot diário NÃO deve ser gravado para user sem carteira.'
        );
    }
}
