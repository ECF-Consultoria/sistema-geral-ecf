<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 105 Plan 02 (v18.0 · NPSWIN-03) — timing do cron `desempenho:consolidar-mes`
 * passa de "dia 1 às 14:00" para "ÚLTIMO DIA do mês, às 14:00" (D2 do
 * 105-CONTEXT.md), congelando o snapshot no FIM do mês de coleta do NPS.
 *
 * Modelo de negócio: a janela de bônus dura 2 meses — mês M coleta o
 * FINANCEIRO, mês M+1 coleta o NPS. O cron ANTIGO rodava dia 1 de M+1 14:00,
 * no COMEÇO da coleta de M+1 (quase 0 respostas existiam ainda). Aplicar o
 * deslocamento +1 da 105-01 sem mover o cron congelaria 0 NPS todo mês —
 * pior que o bug original (Pitfall 3 do RESEARCH). Mover pro ÚLTIMO DIA do
 * mês faz o congelamento capturar a coleta completa de M+1.
 *
 * O cálculo de competência do command (`Carbon::today()->subMonthNoOverflow()`)
 * JÁ estava correto para o novo timing — rodando no último dia de julho,
 * today->subMonth() = junho. Este plano só muda o AGENDAMENTO (routes/console.php)
 * e a DOCUMENTAÇÃO do command; a lógica de $mes não muda.
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO: `Http::preventStrayRequests()` no setUp — o
 * command chama `DesempenhoScoreService::compute()` (não computeCached()),
 * que alcança `AdmanMetricDiffService`/providers ML sempre que a carteira
 * tem empresa elegível financeiramente. `fakeAdmanSemDiff()` força
 * `calculated_fallback` determinístico sobre o fixture local (`AdmanMetric`).
 *
 * Fixtures espelhadas de `tests/Feature/V18/JanelaNpsBonusTest.php` (105-01).
 *
 * @see .planning/phases/105-correcao-janela-nps-bonus-competencia-v18-0/105-02-PLAN.md
 * @see .planning/phases/105-correcao-janela-nps-bonus-competencia-v18-0/105-CONTEXT.md
 */
class ConsolidarMesJanelaNpsTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        // ISOLAMENTO HTTP OBRIGATÓRIO — nenhuma requisição real à Adman.
        Http::preventStrayRequests();

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-105-02',
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

    // ═══ Helpers — carteira/financeiro (espelha JanelaNpsBonusTest 105-01) ═══

    private function criarUserAnalista(string $nome = 'Analista V18 Cron Fim de Mes'): User
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

    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture 105-02)',
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

    /** Empresa elegível financeiramente (vínculo de serviço Performance ativo). */
    private function criarEmpresaElegivel(User $user, string $custId, string $createdAt = '-6 months'): Company
    {
        $ts = Carbon::parse($createdAt)->toDateTimeString();

        $company = Company::factory()->create(['adman_account_id' => $custId, 'marketplace' => 'meli']);
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

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

    /** Fixture DENSA — 1 row `AdmanMetric` por DIA cobrindo `[$inicio,$fim]` (inclusive). */
    private function semearDiario(Company $c, string $inicio, string $fim, float $revenue, ?float $margem): void
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

    /** Fake dos 2 endpoints Adman SEM `.diff` — força `calculated_fallback` determinístico. */
    private function fakeAdmanSemDiff(): void
    {
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);
    }

    /** Fixture financeira determinística da competência junho/2026 (mês M). */
    private function seedFinanceiroJunhoFechado(Company $c): void
    {
        $this->semearDiario($c, '2026-06-01', '2026-06-30', revenue: 1000.0, margem: 240.0);
        $this->semearDiario($c, '2026-05-02', '2026-05-31', revenue: 1000.0, margem: 250.0);
    }

    /**
     * Semeia 1 nota de NPS via ATRIBUIÇÃO congelada (mesmo shape lido por
     * `notasPorAtribuicao()`, INTOCADO por este plano — só o TIMING do cron muda).
     */
    private function seedNpsNota(User $user, Company $company, Carbon $completedAt, float $score, string $role = 'consultor'): void
    {
        $survey = NpsSurvey::create([
            'token'        => (string) Str::uuid(),
            'company_id'   => $company->id,
            'generated_by' => null,
            'expires_at'   => $completedAt->copy()->addDays(30),
            'completed_at' => $completedAt,
            'status'       => 'completed',
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente V18 Cron Fim de Mes',
        ]);

        $npsScore = NpsResponseScore::create([
            'nps_response_id' => $response->id,
            'company_id'      => $company->id,
            'dimensao'        => 'analista',
            'score_sum'       => $score,
            'question_count'  => 1,
            'average_score'   => $score,
            'calculated_at'   => $completedAt,
        ]);

        NpsScoreAssignment::create([
            'nps_response_id'       => $response->id,
            'nps_response_score_id' => $npsScore->id,
            'company_id'            => $company->id,
            'servico_id'            => $this->servicoPerformanceId(),
            'service_setor'         => Servico::SETOR_PERFORMANCE,
            'role'                  => $role,
            'user_id'               => $user->id,
            'average_score'         => $score,
            'assigned_at'           => $completedAt,
        ]);
    }

    // ═══ Teste A — congelamento no fim do mês captura NPS de M+1 ═══════════

    #[Test]
    public function test_cron_no_ultimo_dia_do_mes_congela_competencia_m_com_nps_de_m_mais_1(): void
    {
        // "Agora" = 31/07/2026 14:05 — o EXATO instante em que o cron D2
        // (após reagendado no Task 2) roda. Rodando no ÚLTIMO DIA de julho,
        // today->subMonthNoOverflow() = junho ⇒ competência congelada = junho (M).
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:05:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Cron Fim de Mes');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-02-CRON');
        $this->seedFinanceiroJunhoFechado($company);

        // ZERO respostas em junho (M). 1 resposta em julho (M+1) com nota 4.5
        // — se o command lesse M (bug antigo), o nps_medio seria 0.0.
        $this->seedNpsNota($user, $company, Carbon::parse('2026-07-15 10:00:00'), 4.5);

        $this->artisan('desempenho:consolidar-mes')->assertExitCode(0);

        $snapJunho = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->first();

        $this->assertNotNull($snapJunho,
            'Rodando o cron no último dia de julho (fim do mês de coleta), a competência congelada '
            . 'deve ser junho (mês corrente − 1 = M), não julho.');

        $snapJulho = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-07-01')
            ->first();

        $this->assertNull($snapJulho,
            'O cron NÃO deve gravar snapshot para julho (M+1) — a competência congelada é sempre M.');

        $this->assertEqualsWithDelta(4.5, $snapJunho->breakdown_json['componentes']['nps_medio'], 0.01,
            'O snapshot mensal de junho (M) deve capturar o NPS coletado em julho (M+1) via compute() '
            . 'deslocado da 105-01 — provando que o congelamento no FIM do mês de coleta funciona. '
            . 'Se lesse M (junho, 0 respostas), o valor seria 0.0.');

        $this->assertTrue($snapJunho->breakdown_json['componentes_disponiveis']['nps_medio'],
            'NPS disponível (não excluído) quando M+1 já fechou e tem resposta real.');
    }

    // ═══ Teste B — override --mes continua funcionando + idempotência ══════

    #[Test]
    public function test_override_mes_continua_funcionando_e_idempotente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:05:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Cron Override Mes');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-02-OVERRIDE');
        $this->seedFinanceiroJunhoFechado($company);
        $this->seedNpsNota($user, $company, Carbon::parse('2026-07-10 09:00:00'), 3.8);

        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->count(), '--mes=2026-06 explícito deve gravar exatamente 1 snapshot de junho.');

        // Rerun — updateOrCreate NÃO deve duplicar.
        $this->artisan('desempenho:consolidar-mes', ['--mes' => '2026-06'])->assertExitCode(0);

        $this->assertSame(1, DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', '2026-06-01')
            ->count(), 'Rerun no mesmo --mes NÃO deve duplicar — updateOrCreate atualiza a mesma row.');
    }
}
