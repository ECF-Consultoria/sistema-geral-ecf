<?php

namespace Tests\Feature\Phase110;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Phase74\DesempenhoScoreServiceTestProviderStub;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Phase 110 · Plan 02 (FIXMARG-03) — gate de qualidade da amostra de margem
 * no congelamento mensal (`ConsolidarMesDesempenho`).
 *
 * Cobre os 5 casos do `110-02-PLAN.md`:
 *  1. Amostra DEGRADADA + snapshot mensal ANTERIOR existente → RECUSA
 *     (preserva o antigo) + Log::error (sem_snapshot_anterior=false).
 *  2. Amostra SAUDÁVEL → persiste normalmente.
 *  3. User só-Shopee (sem empresa Adman elegível) → cobertura=1.0, NÃO é
 *     tratado como degradado.
 *  4. Idempotência preservada após o gate (rerun não duplica).
 *  5. Amostra DEGRADADA + SEM snapshot anterior → NENHUMA row criada +
 *     Log::error ACIONÁVEL (sem_snapshot_anterior=true, impacto_desemp08=true).
 *
 * Reusa o padrão de fixture do `Phase74/ConsolidarMesDesempenhoCommandTest`
 * (Carbon::setTestNow no cron mensal, provider stub, Http::fake obrigatório)
 * + o trait `CriaCenarioResponsaveis` (v16.0) para montar vínculos
 * performance/Shopee sem depender de helpers privados de outra suite.
 *
 * @see .planning/phases/110-.../110-02-PLAN.md <design_decision>
 */
class ConsolidarMesMargemResilienteTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private DesempenhoScoreServiceTestProviderStub $providerStub;
    private int $setorId;
    private int $cargoAnalistaId;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela em 2026-08-01 14:05 BRT — batendo o schedule do cron
        // mensal. Mês anterior = julho/2026 (fechado), usado com --mes
        // explícito em todos os testes para evitar ambiguidade.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        // Reusa o stub definido no Plan 74-09 (evita duplicação).
        require_once __DIR__ . '/../Phase74/DesempenhoScoreServiceTest.php';
        $this->providerStub = new DesempenhoScoreServiceTestProviderStub();
        $this->app->instance(MetricsProviderFactory::class, $this->providerStub);

        // O comando chama compute() PURO — delega margem/faturamento ao
        // AdmanMetricDiffService (HTTP quando a empresa tem custId). As
        // empresas "degradadas" desta suite NÃO têm custId (o objetivo é
        // justamente simular ausência de amostra), então nenhum request
        // real é esperado — o fake abaixo é defesa em profundidade.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-110-02',
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

    // ═══ Helpers ═══════════════════════════════════════════════════════════

    private function criarUserAnalista(string $nome): User
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

    /**
     * Attach uma empresa nova (sem `adman_account_id`) ao user via serviço
     * performance — conta como elegível a margem (`fonte=adman` via
     * `flagsFinanceirasPorSetor`), mas SEM custId nem AdmanMetric nenhum, o
     * diff service devolve `emptyMetrics()` (`compute()` linha ~110: custId
     * vazio ⇒ curto-circuita). É o jeito mais simples de simular "empresa
     * elegível SEM margem real" (amostra degradada) sem depender do gate de
     * cobertura interno do 110-01 (que exige custId pra sequer tentar o
     * local).
     */
    private function criarEmpresaSemMargem(User $user, int $servicoPerf): Company
    {
        $ts = Carbon::parse('-3 months')->toDateTimeString();
        $company = Company::factory()->create();
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        return $company->fresh();
    }

    /**
     * Attach uma empresa com margem REAL — custId + AdmanMetric denso nas
     * janelas current/baseline do mês (mesmo padrão de
     * `Phase74/ConsolidarMesDesempenhoCommandTest::preencherDadosDaCarteira`).
     */
    private function criarEmpresaComMargemReal(User $user, int $servicoPerf, string $mesYm): Company
    {
        $company = $this->criarEmpresaSemMargem($user, $servicoPerf);

        $company->timestamps = false;
        $company->forceFill(['adman_account_id' => 'CUST-110-' . $company->id, 'marketplace' => 'meli'])->save();
        $company->timestamps = true;

        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesYm]);

        // Revenue +3% / margem % ~+1,94% — valores constantes por dia.
        $this->semearDiario($company, $periodo['current_start'],  $periodo['current_end'],  10300, 2100);
        $this->semearDiario($company, $periodo['baseline_start'], $periodo['baseline_end'], 10000, 2000);

        return $company->fresh();
    }

    private function semearDiario(Company $c, string $inicio, string $fim, float $revenue, float $margem): void
    {
        $cursor  = Carbon::parse($inicio);
        $fimData = Carbon::parse($fim);
        while ($cursor->lte($fimData)) {
            AdmanMetric::create([
                'company_id'          => $c->id,
                'reference_date'      => $cursor->toDateString(),
                'revenue'             => $revenue,
                'contribution_margin' => $margem,
            ]);
            $cursor->addDay();
        }
    }

    /** NPS legacy — competência `$mesYm` (fechada) lê o M+1 (105-01). */
    private function seedNps(Company $c, int $nota, string $mesYm): void
    {
        $mesNps = Carbon::parse($mesYm . '-01')->addMonthNoOverflow()->format('Y-m');
        $survey = NpsSurvey::factory()->for($c)->completed()->create([
            'completed_at' => Carbon::parse($mesNps . '-10 09:00:00'),
        ]);
        NpsResponse::factory()->create([
            'survey_id'      => $survey->id,
            'score_analista' => $nota,
        ]);
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    // ─── Caso 1: degradada + snapshot anterior → RECUSA e preserva ─────────

    #[Test]
    public function test_amostra_degradada_com_snapshot_anterior_preserva_o_antigo_e_loga_alerta(): void
    {
        $u = $this->criarUserAnalista('Degradado Com Anterior');
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);

        // 1 empresa com margem real + 3 sem dado nenhum → cobertura 1/4=0,25 (<0,7).
        $companyBoa = $this->criarEmpresaComMargemReal($u, $servicoPerf, '2026-07');
        $this->seedNps($companyBoa, 4, '2026-07');
        for ($i = 0; $i < 3; $i++) {
            $this->criarEmpresaSemMargem($u, $servicoPerf);
        }

        // Snapshot anterior já congelado (o valor bom que a rede de
        // segurança deve preservar).
        $anterior = DesempenhoScoreSnapshot::create([
            'user_id'              => $u->id,
            'ref_date'             => '2026-07-01',
            'mes_referencia'       => '2026-07-01',
            'score'                => 88,
            'classificacao'        => 'maximo',
            'ranking_pos'          => 1,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => ['nota_final' => 4.4, 'faixa_bonus' => 'maximo'],
        ]);

        Log::spy();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $anterior->refresh();
        $this->assertSame(88, $anterior->score,
            'Score do snapshot antigo PRESERVADO — amostra degradada não pode sobrescrever.');
        $this->assertSame('maximo', $anterior->classificacao);
        $this->assertEqualsWithDelta(4.4, $anterior->breakdown_json['nota_final'], 0.001,
            'breakdown_json antigo PRESERVADO — não é o breakdown recém-computado.');

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = []) use ($u) {
                return is_string($message)
                    && str_contains($message, 'degradada')
                    && ($context['user_id'] ?? null) === $u->id
                    && ($context['mes_referencia'] ?? null) === '2026-07-01'
                    && ($context['sem_snapshot_anterior'] ?? null) === false;
            })
            ->atLeast()->once();
    }

    // ─── Caso 2: cobertura saudável → persiste normal ──────────────────────

    #[Test]
    public function test_amostra_saudavel_persiste_normalmente(): void
    {
        $u = $this->criarUserAnalista('Saudavel');
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $company = $this->criarEmpresaComMargemReal($u, $servicoPerf, '2026-07');
        $this->seedNps($company, 4, '2026-07');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();

        $this->assertNotNull($snap, 'Cobertura saudável (1.0) deve persistir o snapshot normalmente.');
        $this->assertNotNull($snap->breakdown_json['nota_final']);
        $this->assertEqualsWithDelta(1.0, $snap->breakdown_json['margem_amostra']['cobertura'], 0.001);
    }

    // ─── Caso 3: só-Shopee → cobertura 1.0, NÃO é degradado ────────────────

    #[Test]
    public function test_user_so_shopee_nao_e_tratado_como_degradado(): void
    {
        $u = $this->criarUserAnalista('So Shopee');
        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE);

        $company = Company::factory()->create();
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $u->id, 'consultor', $servicoShopee);

        Log::spy();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $snap = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $u->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();

        $this->assertNotNull($snap,
            'Ausência de empresa Adman elegível NÃO é degradação — snapshot deve ser persistido.');
        $this->assertEqualsWithDelta(1.0, $snap->breakdown_json['margem_amostra']['cobertura'], 0.001);
        $this->assertSame(0, $snap->breakdown_json['margem_amostra']['n_elegivel']);

        Log::shouldNotHaveReceived('error', [
            \Mockery::on(fn ($message) => is_string($message) && str_contains($message, 'degradada')),
        ]);
    }

    // ─── Caso 4: idempotência preservada após o gate ───────────────────────

    #[Test]
    public function test_idempotencia_preservada_apos_o_gate(): void
    {
        $u = $this->criarUserAnalista('Idempotente Saudavel');
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);
        $company = $this->criarEmpresaComMargemReal($u, $servicoPerf, '2026-07');
        $this->seedNps($company, 5, '2026-07');

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()->count());

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();
        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()->count(),
            'Rerun no mesmo mês NÃO duplica — updateOrCreate atualiza a mesma row.');

        $snap = DesempenhoScoreSnapshot::mensal()->where('user_id', $u->id)->first();
        $this->assertSame(1, (int) $snap->ranking_pos,
            'ranking_pos populado normalmente para a row efetivamente persistida.');
    }

    // ─── Caso 5: degradada + SEM snapshot anterior → nenhuma row + alerta ──

    #[Test]
    public function test_amostra_degradada_sem_snapshot_anterior_nao_cria_row_e_loga_alerta_acionavel(): void
    {
        $u = $this->criarUserAnalista('Degradado Sem Anterior');
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE);

        $companyBoa = $this->criarEmpresaComMargemReal($u, $servicoPerf, '2026-07');
        $this->seedNps($companyBoa, 4, '2026-07');
        for ($i = 0; $i < 3; $i++) {
            $this->criarEmpresaSemMargem($u, $servicoPerf);
        }

        // Sem snapshot pré-existente para este user/mês.
        Log::spy();

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-07'])->assertSuccessful();

        $this->assertDatabaseMissing('desempenho_score_snapshots', [
            'user_id'        => $u->id,
            'mes_referencia' => '2026-07-01',
        ]);

        Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $context = []) use ($u) {
                return is_string($message)
                    && str_contains($message, 'DESEMP-08')
                    && str_contains($message, 're-rodar')
                    && ($context['user_id'] ?? null) === $u->id
                    && ($context['mes_referencia'] ?? null) === '2026-07-01'
                    && ($context['sem_snapshot_anterior'] ?? null) === true
                    && ($context['impacto_desemp08'] ?? null) === true;
            })
            ->atLeast()->once();
    }
}
