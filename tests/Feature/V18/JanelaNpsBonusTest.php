<?php

namespace Tests\Feature\V18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsResponseScore;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 105 Plan 01 (v18.0 · NPSWIN-01/02) — deslocamento +1 mês da JANELA de
 * NPS no motor de bônus, só no caminho fechado (D1 do 105-CONTEXT.md).
 *
 * Modelo de negócio (105-CONTEXT.md): a janela de bônus dura 2 meses — mês M
 * coleta o FINANCEIRO, mês M+1 coleta o NPS (o cliente só avalia DEPOIS do
 * trabalho feito). Bônus de competência M = financeiro(M) + NPS(coletado em
 * M+1). Bug de prod: Felipe competência junho lia NPS de junho (0 respostas
 * → 0.0 → nota tankada ~1.5) quando devia ler julho (4.97 → nota ~3.5).
 *
 * D1 (TRAVADO): o deslocamento vale SÓ no caminho oficial/mês fechado. No mês
 * EM CURSO o componente NPS é EXCLUÍDO (null, nunca 0.0) — o NPS de um mês em
 * curso só é coletado no mês seguinte, que ainda não existe.
 *
 * Blocker 1 do plan-checker (mecânica exclui-vs-0.0): "a janela M+1 já
 * fechou?" tem que ser comparado por DATA (`now()->startOfDay() >=
 * endOfMonth($mesNps)->startOfDay()`), NUNCA por timestamp
 * (`endOfMonth($mesNps) < now()`) — o cron D2 (105-02) congela no ÚLTIMO DIA
 * de M+1 às 14h, e `endOfMonth()` = 23:59:59 do MESMO dia; a comparação por
 * timestamp daria sempre falso nesse instante (23:59:59 < 14:00 é falso),
 * classificando toda competência com 0 NPS real como "ainda coletando" (null)
 * em vez de "fechada com 0" (0.0) — o oposto da regra. O Teste 5 trava esse
 * boundary exato.
 *
 * ISOLAMENTO HTTP OBRIGATÓRIO: `Http::preventStrayRequests()` no setUp — o
 * `compute()` alcança `AdmanMetricDiffService`/providers ML sempre que a
 * carteira tem empresa elegível financeiramente. Todo teste usa
 * `fakeAdmanSemDiff()` (404 nos 2 endpoints) para forçar `calculated_fallback`
 * determinístico sobre o fixture local (`AdmanMetric`), zero rede real.
 *
 * Seed de NPS: em vez de rodar o fluxo real `POST /nps/{token}` (pesado,
 * exercita o `NpsSnapshotService` inteiro), semeia DIRETO as 3 tabelas do
 * snapshot congelado da Fase 79 (`nps_surveys` → `nps_responses` →
 * `nps_response_scores` → `nps_score_assignments`) — o MESMO shape que
 * `notasPorAtribuicao()` (caminho A do `computeNpsMedio`, INTOCADO por este
 * plano) lê. `computeNpsMedio` não é o alvo deste plano — só a JANELA
 * (qual mês é passado pra ele) muda.
 *
 * @see .planning/phases/105-correcao-janela-nps-bonus-competencia-v18-0/105-01-PLAN.md
 * @see .planning/phases/105-correcao-janela-nps-bonus-competencia-v18-0/105-CONTEXT.md
 */
class JanelaNpsBonusTest extends TestCase
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
            'slug'       => 'performance-105-01',
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

    // ═══ Helpers — carteira/financeiro (espelha DesempenhoPeriodoOficialTest) ══

    private function criarUserAnalista(string $nome = 'Analista V18 Janela NPS'): User
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
                'nome'          => 'Serviço Performance (fixture 105-01)',
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

    /**
     * Empresa elegível financeiramente (vínculo de serviço Performance ativo)
     * com `adman_account_id` fixo — necessário pro `AdmanMetricDiffService`
     * conseguir montar a chamada/chave de cache (custId vazio = early-return
     * `emptyMetrics()`, sem HTTP nenhum). Cadastrada há 6 meses por padrão —
     * escapa do filtro "empresa nova" (DESEMP-04) em qualquer competência
     * usada nesta suíte.
     */
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

    /**
     * Fixture financeira PADRÃO da competência junho/2026 (mes M usado nos
     * Testes 1/3/4/5): current (01..30/06) receita R$1000/dia, margem 24%
     * (R$240/dia) — baseline (02..31/05) receita R$1000/dia, margem 25%
     * (R$250/dia). Determinístico independente de "agora":
     *  - var_faturamento_pct = 0,00% → régua "<1" → 3 pts.
     *  - var_margem_pct = (24-25)/25*100 = -4,00% → régua "<=-2" → 2 pts
     *    (margem negativa — espelha o cenário real do Felipe/105-CONTEXT.md).
     */
    private function seedFinanceiroJunhoFechado(Company $c): void
    {
        $this->semearDiario($c, '2026-06-01', '2026-06-30', revenue: 1000.0, margem: 240.0);
        $this->semearDiario($c, '2026-05-02', '2026-05-31', revenue: 1000.0, margem: 250.0);
    }

    /**
     * Semeia 1 nota de NPS via ATRIBUIÇÃO congelada (caminho A do
     * `computeNpsMedio`, Fase 79/80 — INTOCADO por este plano). Insere direto
     * nas 3 tabelas do snapshot em vez de rodar o fluxo `POST /nps/{token}`
     * completo — é o MESMO shape que `notasPorAtribuicao()` lê (join por
     * `nps_score_assignments.user_id` + `nps_surveys.completed_at` no mês).
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
            'respondent_name' => 'Cliente V18 Janela NPS',
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

    // ═══ Teste 1 — Felipe: competência fechada lê NPS de M+1 ═══════════════

    #[Test]
    public function test_competencia_fechada_le_nps_de_m_mais_1(): void
    {
        // "Agora" = 20/07/2026 → junho/2026 é mês FECHADO (não é o corrente).
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Felipe');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-01-FELIPE');
        $this->seedFinanceiroJunhoFechado($company);

        // ZERO respostas em junho (M). 1 resposta em julho (M+1) com nota 4.97
        // — espelha o caso real do Felipe (105-CONTEXT.md).
        $this->seedNpsNota($user, $company, Carbon::parse('2026-07-15 10:00:00'), 4.97);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertEqualsWithDelta(4.97, $r['componentes']['nps_medio'], 0.01,
            'Competência junho (fechada) tem que ler o NPS coletado em julho (M+1), não o de junho (0 respostas → 0.0).');

        $this->assertTrue($r['componentes_disponiveis']['nps_medio'],
            'NPS disponível (não nulo) quando M+1 tem resposta real.');

        // nota_final = round((npsPts=4.97 + fatPts=3.0 + margemPts=2.0)/3, 2) = 3.32.
        // Se tivesse lido M (0 respostas → 0.0 → npsPts clamp=1.0), a nota seria
        // round((1+3+2)/3,2) = 2.00 — a tankada que o bug de prod produzia.
        $this->assertEqualsWithDelta(3.32, $r['nota_final'], 0.01,
            'nota_final reflete o NPS de M+1 (4.97), não a tankada por 0.0 de M.');
        $this->assertGreaterThan(3.0, $r['nota_final'],
            'Prova qualitativa: lendo M+1 a nota fica bem acima da tankada (~2.00) que M (0 respostas) produziria.');
    }

    // ═══ Teste 2 — mês em curso EXCLUI o NPS (null, nunca 0.0) ═════════════

    #[Test]
    public function test_mes_em_curso_usa_piso_nps(): void
    {
        // "Agora" = 20/07/2026, dentro do próprio mês de julho → julho é o
        // mês CORRENTE (operacional). Item 2 (2026-07-21): mês em curso usa
        // PISO NPS 1.0 (supera a exclusão da Fase 105) — o NPS entra com 1.0
        // desde o dia 1 pra não inflar a nota só com financeiro.
        Carbon::setTestNow(Carbon::parse('2026-07-20 14:00:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Em Curso');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-01-EMCURSO');

        // Financeiro determinístico do mês em curso (operational): current
        // 01..20/07 R$1000/dia margem 25%; baseline 01..20/06 (alinhado por
        // dia) R$900/dia margem 25% → var_fat=+11,11% (régua >5 → 5pts),
        // var_margem=0,00% (régua <=1 → 3pts).
        $this->semearDiario($company, '2026-07-01', '2026-07-20', revenue: 1000.0, margem: 250.0);
        $this->semearDiario($company, '2026-06-01', '2026-06-20', revenue: 900.0, margem: 225.0);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-07-01'));

        $this->assertSame(1.0, $r['componentes']['nps_medio'],
            'Item 2: mês em curso usa PISO NPS 1.0 (não mais null) — o NPS real entra quando o mês fecha.');
        $this->assertTrue($r['componentes_disponiveis']['nps_medio'],
            'componentes_disponiveis.nps_medio = true: o piso 1.0 é um componente presente.');

        // nota_final = (nps=1.0 + margem ausente=0 + fatPts=5.0)/3 = 2.00.
        //
        // ATUALIZADO em 2026-08-10 (quick 260810-mt8): o divisor virou fixo 3 e
        // o indicador ausente soma zero. O valor esperado ANTES era 3.00, mas
        // pelo motivo errado — o comentário dizia "margemPts=3.0", quando na
        // verdade a margem sempre esteve AUSENTE neste cenário (o teste semeia
        // `adman_metrics` local e não responde pelo endpoint da Adman, então
        // não há `percentageMargin` de onde tirar a variação). A conta que
        // passava era (1,0 + 5,0)/2 = 3,00, que coincidia com o número do
        // comentário e escondia a ausência.
        $this->assertNull($r['pontos_componentes']['margem'],
            'Sem resposta da Adman não há margem — o payload reporta ausente, não zero.');
        $this->assertEqualsWithDelta(2.0, $r['nota_final'], 0.01,
            'Piso NPS 1,0 + margem ausente (0) + faturamento 5,0, sobre divisor fixo 3.');
    }

    // ═══ Teste 3 — fechada, janela M+1 JÁ ENCERRADA, 0 respostas → 0.0 ═════

    #[Test]
    public function test_fechada_janela_m_mais_1_encerrada_zero_respostas_penaliza(): void
    {
        // "Agora" = 15/09/2026 — tanto junho (M) quanto julho (M+1) estão
        // totalmente no passado; a janela de coleta de julho já se encerrou.
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Fechada Encerrada Zero');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-01-ENCERRADA');
        $this->seedFinanceiroJunhoFechado($company);

        // "Ruído" em junho (M) — prova que a implementação lê M+1 e NÃO M: se
        // lesse M por engano, devolveria 3.0 (a nota do ruído), não 0.0.
        $this->seedNpsNota($user, $company, Carbon::parse('2026-06-10 09:00:00'), 3.0);
        // ZERO respostas reais em julho (M+1).

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertSame(0.0, $r['componentes']['nps_medio'],
            'Janela M+1 (julho) já encerrada com 0 respostas reais → PENALIZA (0.0), decisão da diretoria. '
            . 'Se a implementação lesse M (junho) por engano, o valor seria 3.0 (o ruído semeado), não 0.0.');
        $this->assertTrue($r['componentes_disponiveis']['nps_medio'],
            '0.0 é um valor "disponível" (não exclusão) — o componente participa normalmente da média.');
    }

    // ═══ Teste 4 — fechada, janela M+1 AINDA EM COLETA, 0 respostas → null ═

    #[Test]
    public function test_fechada_janela_m_mais_1_em_coleta_zero_respostas_exclui(): void
    {
        // "Agora" = 10/07/2026 (dia 10 de julho = M+1, ainda dentro do mês de
        // coleta). Junho (M) já é mês fechado (não é o corrente).
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Fechada Em Coleta Zero');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-01-COLETA');
        $this->seedFinanceiroJunhoFechado($company);

        // ZERO respostas em julho (M+1) — e nenhuma em junho (M) também.

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertNull($r['componentes']['nps_medio'],
            'Janela M+1 (julho) ainda EM COLETA (hoje=10/07, antes do fim do mês) com 0 respostas → EXCLUI (null), '
            . 'não tanka — a competência ainda vai receber NPS até o fim de julho.');
        $this->assertFalse($r['componentes_disponiveis']['nps_medio'],
            'componentes_disponiveis.nps_medio=false quando o componente é excluído por coleta em andamento.');
    }

    // ═══ Teste 5 — BOUNDARY do cron: último dia de M+1 às 14h, 0 → 0.0 ═════

    #[Test]
    public function test_boundary_ultimo_dia_m_mais_1_14h_zero_respostas_penaliza(): void
    {
        // "Agora" = 31/07/2026 14:00:00 — o EXATO instante em que o cron D2
        // (105-02) congela o mês. Trava o Blocker 1: comparação por DATA
        // (now()->startOfDay() >= endOfMonth($mesNps)->startOfDay()) tem que
        // dar TRUE aqui (31/07 00:00 >= 31/07 00:00), mesmo o "agora" (14h)
        // sendo ANTES de endOfMonth() como TIMESTAMP (31/07 23:59:59). Uma
        // comparação por timestamp (`endOfMonth($mesNps) < now()`) daria
        // FALSE (23:59:59 < 14:00 é falso) e classificaria errado como
        // "ainda coletando" (null) — o OPOSTO da regra.
        Carbon::setTestNow(Carbon::parse('2026-07-31 14:00:00'));
        $this->fakeAdmanSemDiff();

        $user    = $this->criarUserAnalista('Boundary Cron');
        $company = $this->criarEmpresaElegivel($user, 'CUST-105-01-BOUNDARY');
        $this->seedFinanceiroJunhoFechado($company);

        // "Ruído" em junho (M) — mesma prova anti-leitura-errada do Teste 3.
        $this->seedNpsNota($user, $company, Carbon::parse('2026-06-20 09:00:00'), 1.5);
        // ZERO respostas reais em julho (M+1).

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($user, Carbon::parse('2026-06-01'));

        $this->assertSame(0.0, $r['componentes']['nps_medio'],
            'BOUNDARY (Blocker 1): no último dia de M+1 às 14h, a janela M+1 já conta como FECHADA por '
            . 'comparação de DATA (não timestamp) → 0 respostas reais PENALIZA (0.0), não exclui (null).');
        $this->assertTrue($r['componentes_disponiveis']['nps_medio'],
            '0.0 no boundary é valor "disponível" — participa normalmente da média, não é exclusão.');
    }
}
