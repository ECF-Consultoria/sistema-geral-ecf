<?php

namespace Tests\Feature\Phase74;

use App\Contracts\MetricsProvider;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Metrics\UnifiedMetricsDto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 74 · Plan 74-09 — Suite Feature bloqueante do `DesempenhoScoreService`.
 *
 * Cobre os requisitos travados no `74-SPEC.md`:
 *   DESEMP-01, DESEMP-02, DESEMP-03, DESEMP-04, DESEMP-05, DESEMP-06,
 *   DESEMP-08, DESEMP-10, DESEMP-11.
 *
 * A âncora bloqueante é o `test_fixture_carlos_retorna_nota_3_35_sem_bonus`
 * — se este teste passa, a matemática do módulo Desempenho está fiel à
 * decisão da diretoria/gestão da ECF em 2026-07-09.
 *
 * Estratégia (D-27/D-28/D-29):
 *  - `RefreshDatabase` + `PRAGMA foreign_keys = ON` (SQLite in-memory).
 *  - `MetricsProviderFactory` substituído por um stub controlável no container
 *    (`$this->providerStub`). O stub responde revenue por (empresa, mês) via
 *    map interno; o `caseFor` é configurável por empresa. Isola totalmente o
 *    HTTP externo (ML/Adman) sem quebrar a lógica end-to-end.
 *  - Carbon congelado em `2026-08-01 14:05:00` — cálculos referem julho/2026
 *    como mês fechado, agosto/2026 como corrente. Estabilidade determinística.
 *  - Fixture Carlos usa NPS legacy (score_analista int) para exercitar o
 *    fallback dual-path (Phase 72/73) sem depender de template v15 no seed.
 *  - Bonus faixas vêm da migration seed (Plan 74-02) — RefreshDatabase roda
 *    todas as migrations e as 4 faixas já estão presentes.
 *
 * @see .planning/phases/74-.../74-09-PLAN.md
 * @see .planning/phases/74-.../74-CONTEXT.md §D-27, D-28, D-29
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-01..11
 */
class DesempenhoScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stub do provider factory bindado no container no setUp.
     * Configurado por-teste via `$this->providerStub->configureRevenue(...)`
     * e `$this->providerStub->configureCase(...)`.
     */
    private DesempenhoScoreServiceTestProviderStub $providerStub;

    /** IDs de setor/cargos criados para o pivot `user_setores`. */
    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    /**
     * Serviço de setor performance criado sob demanda (lazy, 1× por teste) —
     * forward-compat Fase 91 (D-91-*): `CarteiraContextService::forUser()`
     * só resolve `company_users.servico_id` como elegível financeiramente
     * quando existe `contratos_servico` ativo do setor performance (ou
     * `servico_id` preenchido) apontando pra ele. Sem isso, o fixture inteiro
     * cairia em `sem_carteira=true` sob a régua nova (91-RESEARCH.md Pitfall 1).
     */
    private ?int $servicoPerfId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory precisa de foreign keys ativas para exercer o
        // schema real (constraints + cascade) — padrão do projeto.
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela o "agora" no primeiro dia de agosto/2026 às 14:05 BRT —
        // depois do sync Adman D-1 (11:00), como no cron do consolidar-mes.
        // Julho/2026 é o mês fechado; agosto/2026 é o corrente. Mantido em
        // 2026-08-01 (não movido pra setembro): o filtro "empresa nova"
        // (DESEMP-04, `computeVarFaturamento`) usa `$mes->subMonth()` como
        // limite e as fixtures usam offsets RELATIVOS a `now()` (`-3 months`)
        // — mover "agora" pra setembro empurraria esse offset para cima do
        // limite (edge exato), quebrando testes financeiros sem relação com
        // esta fase. Fase 105 (v18.0 · NPSWIN-01/02/04): a competência julho
        // passa a ler o NPS de AGOSTO (M+1) — como "agora" ainda é
        // 01/08 (início da janela M+1, ainda em coleta), os testes com NPS
        // real movido pra agosto funcionam normalmente (nps > 0 não passa
        // pela mecânica exclui-vs-penaliza); o único teste sensível ao
        // boundary "M+1 já fechou?" (`test_nps_medio_e_zero...`) documenta a
        // mudança de expectativa no próprio teste.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        // Substitui MetricsProviderFactory por um stub controlável — isola
        // qualquer chamada HTTP real ao ML/Adman API (DESEMP-11 D-27).
        $this->providerStub = new DesempenhoScoreServiceTestProviderStub();
        $this->app->instance(MetricsProviderFactory::class, $this->providerStub);

        // Fase 102 (fix plan-checker — BLOCKER) — ISOLAMENTO HTTP OBRIGATÓRIO:
        // computeVarMargem() passa a delegar a AdmanMetricDiffService::compute(),
        // que faz HTTP real (fetchPerformance + fetchAccountMetricsDetailedCached)
        // por empresa QUANDO ela tem adman_account_id preenchido.
        // Http::preventStrayRequests() falha ALTO se algum request escapar do
        // fake abaixo; o fake "sem .diff" força calculated_fallback
        // DETERMINÍSTICO (o golden vem do fixture local, nunca de prod).
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);

        // Setor Performance + cargos analista/estrategista — fonte canônica
        // do cargo do user (users.role é legacy; padrão das quicks 260610-f69).
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-74',
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

    /**
     * Cria um User consultor (role=consultor) já vinculado ao setor
     * Performance com cargo analista via `user_setores`.
     */
    private function criarUserAnalista(string $nome = 'Carlos'): User
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
     * Cria User com o cargo informado (via user_setores → cargos) — usado no
     * teste de dimensão por cargo (estrategista vs analista).
     */
    private function criarUserComCargo(string $nome, int $cargoId, string $role = 'consultor'): User
    {
        $user = User::factory()->create([
            'name'   => $nome,
            'role'   => $role,
            'active' => true,
        ]);
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
     * Serviço de setor performance do fixture — criado 1× por teste (lazy).
     * Forward-compat Fase 91: ver docblock da property `$servicoPerfId`.
     */
    private function servicoPerformanceId(): int
    {
        if ($this->servicoPerfId === null) {
            $this->servicoPerfId = (int) DB::table('servicos')->insertGetId([
                'nome'          => 'Serviço Performance (fixture Phase74)',
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
     * Cria uma Company e attach ao user via pivot `company_users` com
     * `created_at` controlado — parâmetro chave para exercitar o filtro
     * "empresa nova" do DESEMP-04.
     *
     * Forward-compat Fase 91 (D-91-*): além da pivot, cria `contratos_servico`
     * ativo do setor performance para a empresa E preenche `company_users.
     * servico_id` com esse serviço — sem isso, `CarteiraContextService::
     * forUser()` (fonte nova de `computeUniverso`) não resolveria o vínculo
     * como elegível financeiramente e a suite inteira cairia em
     * `sem_carteira=true` (91-RESEARCH.md Pitfall 1). Os valores esperados
     * dos 12 testes NÃO mudam — só a fonte de dado por trás do universo.
     */
    /**
     * @param  ?string  $admanAccountId  Fase 102 — preencher quando o teste
     *   for asserir `var_margem_pct` numérico: `AdmanMetricDiffService`
     *   retorna `emptyMetrics()` (sem HTTP nenhum) quando o custId está
     *   vazio, então SEM isso `var_margem_pct` fica sempre `null`. Testes
     *   que não asserem margem numérica podem deixar `null` (comportamento
     *   inalterado — zero HTTP, seguro sob `Http::preventStrayRequests()`).
     */
    private function criarEmpresaNaCarteira(
        User $user,
        string $pivotCreatedAt = '-3 months',
        string $role = 'consultor',
        ?string $admanAccountId = null,
    ): Company {
        // Ajuste 2026-07-09 (força tarefa): o filtro "empresa nova" agora usa
        // companies.created_at (não pivot->created_at). Continuamos aceitando
        // $pivotCreatedAt como parâmetro para preservar a API dos testes, mas
        // aplicamos o timestamp EM AMBOS os lugares (company + pivot) para o
        // cenário de teste bater com a lógica de produção.
        $ts = Carbon::parse($pivotCreatedAt)->toDateTimeString();

        $company = Company::factory()->create(
            $admanAccountId !== null ? ['adman_account_id' => $admanAccountId, 'marketplace' => 'meli'] : []
        );
        // Força companies.created_at no timestamp fixture-controlado.
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
            'role'        => $role,
            'servico_id'  => $servicoPerfId,
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);
        return $company->fresh();
    }

    /**
     * Insere 1 row de AdmanMetric para (empresa, mês) somando os totais no
     * primeiro dia do mês. O service usa SUM(revenue) e SUM(contribution_margin)
     * dentro do range whereDate — uma row por mês basta.
     */
    private function mockAdmanRevenueMargem(Company $c, string $mesYm, float $revenue, ?float $margem = null): void
    {
        AdmanMetric::create([
            'company_id'          => $c->id,
            'reference_date'      => Carbon::parse($mesYm . '-15')->toDateString(),
            'revenue'             => $revenue,
            'contribution_margin' => $margem,
        ]);
    }

    /**
     * Fixture DENSA (Fase 102 · Pitfall 1 do 102-RESEARCH.md) — popula 1 row
     * `AdmanMetric` por DIA nas janelas current/baseline REAIS de `$mesYm`
     * (resolvidas via `MetricPeriodResolver`, mesmo resolver usado pelo
     * service). Substitui o padrão "1 linha no dia 15": sob a régua nova
     * (baseline = N dias imediatamente anteriores, não mais mês-calendário),
     * um fixture esparso cai na interseção vazia de dias-comuns do
     * `AdmanMetricDiffService` — `var_margem_pct` viraria `null` mesmo com
     * dado presente.
     */
    private function mockAdmanDiario(
        Company $c,
        string $mesYm,
        float $revenueAtual,
        float $revenueAnterior,
        ?float $margemAtual = null,
        ?float $margemAnterior = null,
    ): void {
        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => $mesYm]);

        $this->semearDiario($c, $periodo['current_start'], $periodo['current_end'], $revenueAtual, $margemAtual);
        $this->semearDiario($c, $periodo['baseline_start'], $periodo['baseline_end'], $revenueAnterior, $margemAnterior);
    }

    /** Popula 1 row `AdmanMetric` por DIA em `[$inicio,$fim]` (inclusive), valores constantes. */
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

    // Cache do template principal do teste (1 pergunta dimensão 'analista').
    private ?NpsTemplate $principalTpl = null;
    private ?NpsTemplateQuestion $principalQuestion = null;

    /**
     * Template PRINCIPAL de teste com 1 pergunta escala dimensão 'analista' e
     * opções 1..5. 2026-07-13 — só o principal conta no desempenho, então as
     * respostas do fixture precisam ser v15 sob este template (não mais legacy).
     */
    private function principalTemplateAnalista(): array
    {
        if ($this->principalTpl) {
            return [$this->principalTpl, $this->principalQuestion];
        }

        // Desmarca o seed padrão (unique parcial em is_default) e cria o principal.
        NpsTemplate::query()->update(['is_default' => false]);
        $template = NpsTemplate::factory()->create([
            'nome'       => 'Principal Desempenho',
            'is_default' => true,
            'active'     => true,
        ]);
        $pergunta = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Nota do analista?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_ANALISTA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);
        for ($p = 1; $p <= 5; $p++) {
            NpsTemplateOption::create([
                'question_id' => $pergunta->id,
                'label'       => (string) $p,
                'peso'        => $p,
                'ordem'       => $p,
            ]);
        }
        NpsTemplate::resetPrincipalCache();

        $this->principalTpl      = $template;
        $this->principalQuestion = $pergunta;
        return [$template, $pergunta];
    }

    /**
     * Cria 1 NpsSurvey completed no mês sob o template PRINCIPAL (v15) + 1
     * NpsResponse + 1 answer dimensão 'analista' com o peso informado.
     *
     * `month_reference` fica NULL de propósito: como o índice de dedup é
     * (company_id, month_reference, template_id) WHERE completed, e o SQLite
     * trata NULL como distinto, isso permite múltiplas respostas principal no
     * mesmo mês para a mesma empresa (cenário do fixture Carlos).
     */
    private function mockNpsRespostaPrincipal(Company $c, string $mesYm, int $scoreAnalista): NpsResponse
    {
        [$template, $pergunta] = $this->principalTemplateAnalista();

        $completedAt = Carbon::parse($mesYm . '-10 09:00:00');
        $survey = NpsSurvey::factory()
            ->for($c)
            ->completed()
            ->create([
                'template_id'     => $template->id,
                'month_reference' => null,
                'completed_at'    => $completedAt,
            ]);

        $response = NpsResponse::factory()->create([
            'survey_id' => $survey->id,
        ]);

        $option = NpsTemplateOption::where('question_id', $pergunta->id)
            ->where('peso', $scoreAnalista)
            ->firstOrFail();

        NpsResponseAnswer::create([
            'response_id'                => $response->id,
            'template_question_id'       => $pergunta->id,
            'template_option_id'         => $option->id,
            'question_texto_snapshot'    => $pergunta->texto,
            'question_dimensao_snapshot' => 'analista',
            'option_label_snapshot'      => (string) $scoreAnalista,
            'option_peso_snapshot'       => $scoreAnalista,
        ]);

        return $response;
    }

    /**
     * Cria snapshot mensal fechado histórico — usado pelo DESEMP-08
     * (regra "2 meses consecutivos intermediário → máximo").
     */
    private function criarSnapshotMensal(User $u, string $mesReferencia, string $classificacao, int $score = 90): DesempenhoScoreSnapshot
    {
        return DesempenhoScoreSnapshot::create([
            'user_id'              => $u->id,
            'ref_date'             => $mesReferencia,
            'mes_referencia'       => $mesReferencia,
            'score'                => $score,
            'classificacao'        => $classificacao,
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 3,
            'empresas_eligiveis'   => 3,
            'breakdown_json'       => [],
        ]);
    }

    /**
     * Fixture Carlos — DESEMP-01 âncora bloqueante.
     *
     * Espec da diretoria (74-SPEC.md DESEMP-01) + Ajuste 2026-07-09 (histórico):
     * NPS médio 4.25, var_faturamento +3.00% (régua 4pts), var_margem +2.80%
     * (régua 4pts) → nota_final=4.08, faixa 'basico'.
     *
     * Fase 102 (v18.0 · BON-01/02/03) — RECALIBRAÇÃO, não preservação. A
     * âncora agora roda em modo MÊS FECHADO sob a régua nova: `compute()` com
     * `mes=2026-07-01` e `now()=2026-08-01` resolve `period_key='2026-07'`
     * (closed_period) → current=01/07..31/07 (31 dias), baseline = 31 dias
     * IMEDIATAMENTE ANTERIORES a 01/07 = 31/05..30/06 (janela-de-mesmo-
     * tamanho — NÃO MAIS 01/06..30/06 calendário). Ver 102-RESEARCH.md
     * Pitfall 1.
     *
     * ── var_faturamento_pct permanece EXATO em +3,00% ──────────────────────
     * Fixture usa valor CONSTANTE por dia em ambas as janelas (10.300/dia
     * atual, 10.000/dia baseline) — a razão soma-atual/soma-baseline NÃO
     * depende do comprimento da janela: SUM(10300×31)/SUM(10000×31) =
     * 10300/10000 = 1,03 exato, mesmo a baseline tendo mudado de forma.
     * régua_faturamento(+3,00%) = 4 pts (faixa 1% a 5%) — inalterado.
     *
     * ── var_margem_pct MUDA DE DEFINIÇÃO (BON-03) ──────────────────────────
     * Deixa de ser "% de variação da margem R$ absoluta" (fórmula antiga,
     * removida) e passa a ser a variação do `percentageMargin` da Adman
     * (margem como % da receita), via `AdmanMetricDiffService::
     * fallbackMargemPct()` — SUM(margem)/SUM(revenue)×100 em cada janela,
     * depois a variação % entre as duas:
     *   margem baseline = R$ 2.000,00/dia sobre R$ 10.000,00/dia de revenue
     *     → pctAnterior = 2000/10000×100 = 20,00%
     *   margem atual = R$ 2.152,70/dia sobre R$ 10.300,00/dia de revenue
     *     → pctAtual = 2152.70/10300×100 = 20,90%
     *   var_margem_pct = (20,90 - 20,00) / 20,00 × 100 = +4,50%
     * régua_margem(+4,50%) = 5 pts (>4%) — MUDA de bucket (era 4pts/+2,80%).
     *
     * ── nota_final NOVA (NÃO preservar 4,08) ────────────────────────────────
     *   NPS 4,25 + régua_fat(+3,00%)=4pts + régua_margem(+4,50%)=5pts
     *   → (4,25 + 4 + 5) / 3 = 13,25 / 3 = 4,4166... → round(2) = 4,42
     * faixa_bonus permanece 'basico' ([4.00,4.49]) — o NÚMERO é novo (4,42,
     * não 4,08), derivado da régua nova, JAMAIS ajustado só pra passar.
     *
     * Cada empresa tem `adman_account_id` distinto — necessário pro
     * `AdmanMetricDiffService` conseguir montar a chave/chamada (custId vazio
     * = `emptyMetrics()` sem HTTP). Fixture DENSA (1 row/dia) — Pitfall 1.
     *
     * Fase 105 (v18.0 · NPSWIN-03/04) — deslocamento da janela de NPS.
     * `computeNpsWindow()` (105-01) lê o NPS da competência FECHADA em M+1,
     * não mais em M: a competência julho/2026 agora lê o NPS coletado em
     * AGOSTO/2026 (não julho). As 4 respostas legacy [5,4,4,4] foram
     * movidas de `completed_at` julho→agosto — a RÉGUA e a ARITMÉTICA não
     * mudam (a média continua 4.25; `computeNpsMedio` filtra só pelo mês
     * passado, que agora é agosto em vez de julho), então o golden
     * (nps_medio 4.25 / nota_final 4.42 / faixa 'basico') permanece
     * IDÊNTICO — só a JANELA de leitura do NPS mudou, provando que o
     * dual-path e a régua seguem íntegros.
     */
    private function criarCarlosCompleto(): User
    {
        $carlos = $this->criarUserAnalista('Carlos');

        // 3 empresas na carteira há -3 meses (bem fora do filtro de empresa
        // nova de 2 meses do DESEMP-04), cada uma com custId distinto (a
        // chave de cache do AdmanMetricDiffService não inclui company_id).
        $empresas = [
            $this->criarEmpresaNaCarteira($carlos, '-3 months', admanAccountId: 'CUST-CARLOS-A'),
            $this->criarEmpresaNaCarteira($carlos, '-3 months', admanAccountId: 'CUST-CARLOS-B'),
            $this->criarEmpresaNaCarteira($carlos, '-3 months', admanAccountId: 'CUST-CARLOS-C'),
        ];

        // Fixture densa (1 row/dia) cobrindo current (01..31/07) e baseline
        // (31/05..30/06) — ver aritmética completa no docblock acima.
        foreach ($empresas as $c) {
            $this->mockAdmanDiario(
                $c,
                '2026-07',
                revenueAtual: 10300,
                revenueAnterior: 10000,
                margemAtual: 2152.70,
                margemAnterior: 2000.00,
            );
        }

        // NPS: 4 respostas legacy [5, 4, 4, 4] distribuídas — média 4.25 exata.
        // Distribuição: empresa 0 recebe 2 surveys (scores 5 e 4); empresas 1
        // e 2 recebem 1 survey cada (score 4 em ambas). Fase 105 (NPSWIN-04):
        // completed_at em AGOSTO (M+1 de julho), não mais julho — ver
        // docblock desta função.
        $this->mockNpsRespostaPrincipal($empresas[0], '2026-08', 5);
        $this->mockNpsRespostaPrincipal($empresas[0], '2026-08', 4);
        $this->mockNpsRespostaPrincipal($empresas[1], '2026-08', 4);
        $this->mockNpsRespostaPrincipal($empresas[2], '2026-08', 4);

        return $carlos;
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    // ─── DESEMP-01 · Fixture Carlos — âncora bloqueante ─────────────────────

    #[Test]
    public function test_fixture_carlos_retorna_nota_4_42_basico(): void
    {
        // DESEMP-01 / Fase 102 (BON-01/02/03) — contra regressão silenciosa.
        // Se este teste quebra, é sinal de que a matemática da engine v2
        // divergiu da spec OU a integração com MetricPeriodResolver/
        // AdmanMetricDiffService regrediu.
        //
        // RECALIBRAÇÃO Fase 102: o golden NÃO É MAIS 4.08 (v17, baseline
        // calendário + margem R$ absoluta). A baseline de mês fechado agora é
        // janela-de-mesmo-tamanho e var_margem_pct vem do percentageMargin da
        // Adman — aritmética completa no docblock de criarCarlosCompleto().
        $carlos = $this->criarCarlosCompleto();

        /** @var DesempenhoScoreService $service */
        $service = app(DesempenhoScoreService::class);
        $result  = $service->compute($carlos, Carbon::parse('2026-07-01'));

        // Componentes na escala natural (RAW % antes da normalização régua).
        $this->assertEqualsWithDelta(4.25, $result['componentes']['nps_medio'], 0.001,
            'NPS médio Carlos deve ser exatamente 4.25 (média legacy [5,4,4,4]).');
        $this->assertEqualsWithDelta(3.00, $result['componentes']['var_faturamento_pct'], 0.001,
            'Var faturamento Carlos permanece +3.00% — fixture uniforme, ratio independe do comprimento da janela.');
        $this->assertEqualsWithDelta(4.50, $result['componentes']['var_margem_pct'], 0.01,
            'Var margem (Fase 102 · BON-03): (20,90%-20,00%)/20,00%×100 = +4,50% (percentageMargin, não mais R$).');
        $this->assertNull($result['componentes']['absenteismo_pct'],
            'Absenteísmo sempre null nesta phase (DESEMP-06).');

        // Nota final — âncora RECALIBRADA pela régua nova (Fase 102).
        // Cálculo: NPS 4.25 + régua_fat(+3%)=4pts + régua_margem(+4.5%)=5pts
        //          → média = (4.25 + 4 + 5) / 3 = 4.4167 → round(2) = 4.42.
        $this->assertEqualsWithDelta(4.42, $result['nota_final'], 0.01,
            'Nota final Carlos = 4.42 (NPS 4.25 + régua_fat 4 + régua_margem 5 → média) — NOVO, não 4.08.');

        // Classificação — faixa básico ([4.00, 4.49]) — mesma faixa da v17,
        // número interno diferente.
        $this->assertSame('basico', $result['faixa_bonus'],
            'Faixa Carlos permanece basico (4.42 dentro de [4.00, 4.49]).');
        $this->assertFalse($result['sem_carteira']);
        $this->assertFalse($result['faixa_promovida']);
        $this->assertSame(3, $result['empresas_carteira']);
        $this->assertSame(3, $result['empresas_com_baseline']);
    }

    // ─── DESEMP-02 · Fórmula da nota final (média direta) ───────────────────

    #[Test]
    public function test_nota_final_aplica_reguas_1_5_e_media(): void
    {
        // DESEMP-02 (ajuste 2026-07-09) — chamada isolada do método privado
        // computeNotaFinal via reflection. Após o fix das réguas:
        //   (4.25, +3%, +2.8%) → (4.25, 4, 4) → média 4.08 (fixture Carlos)
        //   (null, +3%, +2.8%) → (—, 4, 4) → média 4.00 (nulls parciais viram média dos não-null)
        //   (4.25, null, null) → 4.25 (só NPS)
        //   (null, null, null) → null
        //
        // Fase 109 (SHOP-DES-02) — o 3º parâmetro de computeNotaFinal deixou
        // de ser a % BRUTA de margem (que o método aplicava reguaMargem()
        // internamente) e passou a ser `$margemPontos` JÁ RÉGUA'D (o blend
        // real+placeholder Shopee, calculado por `margemPontos()` em
        // `compute()`). Os valores abaixo são os PONTOS (reguaMargem($pct)),
        // não mais a % bruta — os resultados numéricos permanecem IDÊNTICOS
        // (regressão zero: só-performance passa o pReal puro, que é
        // algebricamente o mesmo valor que o método calculava internamente
        // antes da Fase 109).
        /** @var DesempenhoScoreService $service */
        $service = app(DesempenhoScoreService::class);
        $method  = new ReflectionMethod($service, 'computeNotaFinal');
        $method->setAccessible(true);

        $this->assertEqualsWithDelta(4.08, $method->invoke($service, 4.25, 3.0, 4.0), 0.001,
            'computeNotaFinal(4.25, 3.0, reguaMargem(2.8)=4.0) → 4.08 (NPS + régua_fat + margemPontos / 3).');

        $this->assertEqualsWithDelta(4.00, $method->invoke($service, null, 3.0, 4.0), 0.001,
            'NPS null → média dos restantes ((régua_fat 4 + margemPontos 4) / 2 = 4.00).');

        $this->assertEqualsWithDelta(4.25, $method->invoke($service, 4.25, null, null), 0.001,
            'Somente NPS presente → nota_final = próprio NPS (média de 1 elemento).');

        $this->assertNull($method->invoke($service, null, null, null),
            'Todos componentes null → nota_final = null.');

        // Casos extremos que motivaram o fix (variações negativas grandes):
        // (NPS 4 + régua_fat 1 + margemPontos 1) / 3 = 6/3 = 2.00.
        $this->assertEqualsWithDelta(2.00, $method->invoke($service, 4.0, -15.0, 1.0), 0.001,
            'Variações negativas fortes → régua 1+1 pt; nota final = 2.00, NUNCA negativa.');

        $this->assertEqualsWithDelta(1.00, $method->invoke($service, 1.0, -50.0, 1.0), 0.001,
            'Cenário pior caso → nota mínima absoluta = 1.00 (nunca abaixo).');

        $this->assertEqualsWithDelta(5.00, $method->invoke($service, 5.0, 100.0, 5.0), 0.001,
            'Cenário melhor caso → nota máxima = 5.00 (nunca acima).');
    }

    // ─── DESEMP-03 · NPS = média das notas do mês; sem notas força 0 ────────

    #[Test]
    public function test_nps_medio_e_zero_quando_user_sem_respostas_no_mes(): void
    {
        // DESEMP-03 (regra ORIGINAL, sem deslocamento) — user com carteira
        // ativa mas ZERO NpsResponse no mês recebia nps_medio = 0.0
        // (penaliza, decisão da diretoria).
        //
        // Fase 105 (v18.0 · NPSWIN-02) — FALLOUT ESPERADO: a competência
        // julho agora lê o NPS de AGOSTO (M+1), não mais de julho. "agora" é
        // 2026-08-01 (setUp) — a janela de agosto AINDA ESTÁ EM COLETA (só o
        // dia 1 se passou), então `computeNpsWindow()` aplica a mecânica
        // exclui-vs-penaliza (105-01) e retorna `null` (EXCLUÍDO — a
        // competência ainda vai receber NPS), não mais `0.0`. Isto NÃO é
        // regressão: é a semântica nova documentada em D1/105-CONTEXT.md —
        // só passaria a penalizar (0.0) se a janela de agosto já tivesse
        // fechado (ver `test_nps_medio_e_zero_com_m1_fechada_penaliza_com_0`
        // logo abaixo, que cobre o caso 0.0 explicitamente).
        $u = $this->criarUserAnalista('Sem NPS');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Sem NPS mas com baseline de faturamento para não devolver todos nulls.
        $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 10500, margem: 10250);
        $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertNull($r['componentes']['nps_medio'],
            'Sem NpsResponse E janela M+1 (agosto) ainda em coleta → nps_medio = null (excluído, não penaliza ainda).');
    }

    #[Test]
    public function test_nps_medio_e_zero_com_m1_fechada_penaliza_com_0(): void
    {
        // Fase 105 (v18.0 · NPSWIN-02) — complemento do teste acima: quando a
        // janela M+1 (agosto) JÁ FECHOU e continua sem nenhuma resposta real,
        // a mecânica exclui-vs-penaliza de `computeNpsWindow()` volta a
        // penalizar com 0.0 — comportamento original de DESEMP-03 preservado,
        // só que agora medido na janela de agosto (M+1), não julho.
        Carbon::setTestNow(Carbon::parse('2026-09-01 14:05:00'));

        $u = $this->criarUserAnalista('Sem NPS M+1 fechada');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

        $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 10500, margem: 10250);
        $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertSame(0.0, $r['componentes']['nps_medio'],
            'Sem NpsResponse E janela M+1 (agosto) já fechou → nps_medio = 0.0 (penaliza).');
    }

    #[Test]
    public function test_nps_medio_e_media_das_notas_recebidas_no_mes(): void
    {
        // DESEMP-03 — user com 3 respostas legacy [5, 4, 3] → média 4.00.
        // Fase 105 (NPSWIN-04): completed_at em AGOSTO — competência julho
        // lê o NPS de M+1 (agosto). Régua/aritmética inalteradas.
        $u = $this->criarUserAnalista('Analista NPS 4.0');
        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months');

        $this->mockNpsRespostaPrincipal($c1, '2026-08', 5);
        $this->mockNpsRespostaPrincipal($c2, '2026-08', 4);
        $this->mockNpsRespostaPrincipal($c3, '2026-08', 3);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(4.00, $r['componentes']['nps_medio'], 0.001,
            'Respostas [5,4,3] → nps_medio = 4.00 (média aritmética).');
    }

    // ─── DESEMP-04 · % var faturamento ──────────────────────────────────────

    #[Test]
    public function test_var_faturamento_usa_mediana_e_outlier_nao_manda_na_carteira(): void
    {
        // Quick 260731-pvk — reproduz o caso Douglas (debug
        // baseline-quase-zero-producao, empresa 332 "Lojão do Bras"): um
        // baseline residual POSITIVO (não zero, então o guard `anterior <= 0`
        // do calculated_fallback não pega) faz o `.diff` nativo da Adman
        // devolver uma % gigante que, sob MÉDIA, manda sozinha na carteira.
        //
        // 5 empresas, cada uma com admanAccountId distinto (a chave de cache
        // do diff service não inclui company_id):
        //   A: 9600  vs 10000 → -4,00%
        //   B: 9750  vs 10000 → -2,50%
        //   C: 9850  vs 10000 → -1,50%
        //   D: 10200 vs 10000 → +2,00%
        //   E: 10050 vs    50 → +20.000,00% (outlier — baseline residual R$50)
        //
        // Ordenado: [-4,00; -2,50; -1,50; +2,00; +20000,00] → mediana = -1,50
        // (empresa C, valor do meio). MÉDIA seria
        // (-4,00-2,50-1,50+2,00+20000,00)/5 = 3998,80 — a asserção abaixo
        // prova que NÃO é esse número.
        $u = $this->criarUserAnalista('Analista Mediana Outlier');

        $cA = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MED-A');
        $cB = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MED-B');
        $cC = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MED-C');
        $cD = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MED-D');
        $cE = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MED-E');

        $this->mockAdmanDiario($cA, '2026-07', revenueAtual: 9600,  revenueAnterior: 10000); // -4,00%
        $this->mockAdmanDiario($cB, '2026-07', revenueAtual: 9750,  revenueAnterior: 10000); // -2,50%
        $this->mockAdmanDiario($cC, '2026-07', revenueAtual: 9850,  revenueAnterior: 10000); // -1,50%
        $this->mockAdmanDiario($cD, '2026-07', revenueAtual: 10200, revenueAnterior: 10000); // +2,00%
        $this->mockAdmanDiario($cE, '2026-07', revenueAtual: 10050, revenueAnterior: 50);    // +20.000,00%

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(-1.50, $r['componentes']['var_faturamento_pct'], 0.001,
            'Mediana de [-4,00;-2,50;-1,50;+2,00;+20000,00] = -1,50 (empresa C, valor do meio).');
        $this->assertNotEqualsWithDelta(3998.80, $r['componentes']['var_faturamento_pct'], 0.001,
            'NÃO pode ser a média (3998,80) — é exatamente esse número que a mediana existe para evitar: o outlier E sozinho mandaria na carteira.');
        $this->assertSame(2.0, $r['pontos_componentes']['faturamento'],
            'reguaFaturamento(-1,50) = 2,0 ("queda leve"). Com a média (3998,80) seria 5,0 ("crescimento excelente") — nota máxima por artefato de baseline residual.');
        $this->assertSame(5, $r['empresas_com_baseline'],
            'D-1: o outlier CONTINUA na conta — nenhuma empresa é excluída, filtrada ou capada. Mediana muda o peso, não o universo.');
    }

    #[Test]
    public function test_var_faturamento_mediana_das_variacoes_por_empresa(): void
    {
        // Quick 260731-pvk — recalibrado de média para mediana (D-1).
        // DESEMP-04 (UNIFICADO 2026-07-22) — carteira com deltas -2%, +7%, +4%.
        // `var_faturamento_pct` vem do AdmanMetricDiffService (revenue.diff_pct),
        // MESMA fonte da carteira — então precisa de custId por empresa
        // (custId vazio = emptyMetrics) e fixture DENSA (1 row/dia cobrindo
        // current+baseline reais do resolver; esparso cairia na interseção
        // vazia de dias-comuns). O ratio atual/anterior independe do
        // comprimento da janela (valor constante/dia).
        //
        // Ordenado: [-2%, +4%, +7%] → mediana = +4,00% (valor do meio).
        // Média seria 3,00% (valor antigo, pré-mediana) — golden derivado da
        // regra nova, não ajustado só para passar.
        $u = $this->criarUserAnalista('Analista var_fat');

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-VARFAT-1');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-VARFAT-2');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-VARFAT-3');

        $this->mockAdmanDiario($c1, '2026-07', revenueAtual: 9800,  revenueAnterior: 10000); // -2%
        $this->mockAdmanDiario($c2, '2026-07', revenueAtual: 10700, revenueAnterior: 10000); // +7%
        $this->mockAdmanDiario($c3, '2026-07', revenueAtual: 10400, revenueAnterior: 10000); // +4%

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(4.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Carteira [-2%, +7%, +4%] ordenada = [-2%, +4%, +7%] → mediana = 4,00 (valor do meio, não a média 3,00).');
        $this->assertSame(3, $r['empresas_com_baseline'],
            'As 3 empresas têm baseline (diff_pct != null) → empresas_com_baseline = 3.');
    }

    #[Test]
    public function test_var_faturamento_exclui_empresa_sem_baseline_da_media(): void
    {
        // UNIFICADO 2026-07-22 — a antiga TRAVA DE COBERTURA própria do
        // desempenho (e antes dela, o filtro por `created_at`) foi REMOVIDA: o
        // faturamento passou a delegar ao AdmanMetricDiffService, MESMA fonte da
        // carteira. A exclusão de uma empresa sem baseline confiável não é mais
        // uma regra própria — é o GUARD NATURAL do diff service: sem dado na
        // janela de baseline, `revenue.diff_pct` volta `null` e a empresa não
        // conta (nem no desempenho, nem na carteira — as duas telas concordam).
        $u = $this->criarUserAnalista('Analista Empresa Sem Baseline');

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-SB-1');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-SB-2');
        $cSemBaseline = $this->criarEmpresaNaCarteira($u, '-15 days', admanAccountId: 'CUST-SB-NOVA');

        // C1 e C2 com deltas simétricos (+2% e +8% → média 5%) e fixture densa
        // cobrindo AMBAS as janelas (current + baseline) → qualificam.
        $this->mockAdmanDiario($c1, '2026-07', revenueAtual: 10200, revenueAnterior: 10000); // +2%
        $this->mockAdmanDiario($c2, '2026-07', revenueAtual: 10800, revenueAnterior: 10000); // +8%

        // A empresa nova só tem dado na janela ATUAL (nenhuma linha no baseline)
        // — o guard de dias-comuns do diff service devolve diff_pct=null e ela
        // é descartada do faturamento. Sem baseline não há variação comparável.
        $periodo = app(MetricPeriodResolver::class)->resolve(['period_key' => '2026-07']);
        $this->semearDiario($cSemBaseline, $periodo['current_start'], $periodo['current_end'], 20000, null);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(5.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Empresa sem baseline (diff_pct null) é excluída pelo guard do diff service — só C1 e C2 contam.');
        $this->assertSame(2, $r['empresas_com_baseline'],
            'Só 2 empresas com baseline comparável (diff_pct != null) qualificam.');
        $this->assertSame(3, $r['empresas_carteira'],
            'Carteira total continua sendo 3 empresas (a sem-baseline não some dela).');
    }

    // ─── DESEMP-05 · % var margem via Adman canônico ────────────────────────

    #[Test]
    public function test_var_margem_usa_adman_como_fonte_canonica(): void
    {
        // DESEMP-05 — mesmo com caseFor='so-ml' para a empresa, a margem SEMPRE
        // vem via AdmanMetricDiffService (Adman canônico — ML não expõe custo).
        $u = $this->criarUserAnalista('Analista Margem ML');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-MARGEM-ML');

        // Provider ML sinaliza que a empresa é "so-ml" — mas var_margem_pct
        // NUNCA passa pelo provider ML (nem antes nem depois da Fase 102);
        // é sempre AdmanMetric local via AdmanMetricDiffService.
        $this->providerStub->configureCase($c, 'so-ml');
        $this->providerStub->configureRevenue($c, '2026-07', 20000.0);
        $this->providerStub->configureRevenue($c, '2026-06', 15000.0);

        // Fase 102 (BON-03): AdmanMetric precisa de revenue > 0 (denominador
        // de percentageMargin) — fixture DENSA cobrindo current/baseline REAIS
        // (Pitfall 1). pctBaseline=2000/10000=20,00%; pctAtual=2300/10000=23,00%.
        $this->mockAdmanDiario(
            $c,
            '2026-07',
            revenueAtual: 10000,
            revenueAnterior: 10000,
            margemAtual: 2300,
            margemAnterior: 2000,
        );

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        // var_margem_pct (Fase 102 · BON-03) = (23,00-20,00)/20,00×100 = +15,00%
        // — NÃO É MAIS "(atual R$ - anterior R$)/anterior R$" (fórmula antiga).
        $this->assertEqualsWithDelta(15.00, $r['componentes']['var_margem_pct'], 0.01,
            'Margem vem sempre do Adman via AdmanMetricDiffService, mesmo com caseFor=so-ml.');
    }

    #[Test]
    public function test_var_margem_nao_inverte_sinal_quando_janela_atual_tem_dias_finais_sem_margem(): void
    {
        // Regressão · bug "Tomelin Aramados" (audit-ranking-margem-tomelin,
        // 2026-07-10): mês EM CURSO, Adman atrasa profitMargin vs revenue —
        // últimos dias da janela atual chegam com contribution_margin NULL
        // (revenue presente). Sem o fix, SUM(margem) da janela atual (só 5
        // dos 9 dias) era comparado contra a janela anterior COMPLETA (9
        // dias), invertendo o sinal da variação (real: melhora diária de
        // +50%; sem fix: aparecia como queda de ~-16,67%).
        //
        // "Agora" congelado em 09/07 10:00 → mês de referência (julho) fica
        // EM CURSO, janela atual = dia 1..9, janela anterior = dia 1..9 de
        // junho (mesmo range relativo) — replica exatamente o cenário real.
        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00'));

        // Fase 102: custId necessário pro AdmanMetricDiffService não
        // early-return (empty custId = emptyMetrics(), var_margem_pct=null).
        // Mês EM CURSO (comparison_mode=same_interval_previous_month) — a
        // baseline continua alinhada por dia (dia 1), Pitfall 1 NÃO se aplica
        // aqui (só afeta mês FECHADO/previous_equal_length_window).
        $u = $this->criarUserAnalista('Analista Margem Lag Adman');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-LAG-ADMAN');

        // Junho (janela anterior): 9 dias completos, margem 100/dia → soma 900.
        for ($dia = 1; $dia <= 9; $dia++) {
            AdmanMetric::create([
                'company_id'          => $c->id,
                'reference_date'      => Carbon::parse("2026-06-{$dia}")->toDateString(),
                'revenue'             => 1000,
                'contribution_margin' => 100,
            ]);
        }

        // Julho (janela atual): dias 1-5 com margem 150/dia (melhora real de
        // +50% vs junho); dias 6-9 com revenue sincronizado mas margem NULL
        // (lag da Adman — cenário exato do bug).
        for ($dia = 1; $dia <= 9; $dia++) {
            AdmanMetric::create([
                'company_id'          => $c->id,
                'reference_date'      => Carbon::parse("2026-07-{$dia}")->toDateString(),
                'revenue'             => 1000,
                'contribution_margin' => $dia <= 5 ? 150 : null,
            ]);
        }

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        // Fix: janela anterior recortada para os mesmos 5 dias comuns de
        // margem (100×5=500 vs 150×5=750 — SUM(contribution_margin) próprio
        // guard). Fase 102 (BON-03): o guard vive agora dentro de
        // AdmanMetricDiffService::somasComGuards() (fonte única — este teste
        // passou a provar o guard LÁ, não mais aqui). revenue tem seu PRÓPRIO
        // recorte de dias-comuns (9 dias completos em ambas as janelas,
        // nenhum NULL) → SUM(revenue)=9000 nos dois lados: pctAtual=
        // 750/9000×100=8,3333%, pctAnterior=500/9000×100=5,5556% →
        // (8,3333-5,5556)/5,5556×100=+50,00% — a razão percentageMargin
        // coincide com a razão de margem R$ porque o denominador (revenue)
        // é o MESMO nas duas janelas (750/500 = 1,5 → +50%, independente do
        // valor absoluto do denominador comum).
        $this->assertEqualsWithDelta(50.00, $r['componentes']['var_margem_pct'], 0.01,
            'Dias finais sem margem na janela atual NÃO devem inverter o sinal da variação — '
            .'o guard de dias-comuns (agora em AdmanMetricDiffService) recorta simetricamente (fix Tomelin).');
    }

    // ─── DESEMP-06 · Absenteísmo em standby ─────────────────────────────────

    #[Test]
    public function test_absenteismo_retorna_null_sempre(): void
    {
        // DESEMP-06 — placeholder até integração real (biometria/login).
        // Retorna null independente da carteira/NPS/faturamento do user.
        $carlos = $this->criarCarlosCompleto();
        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($carlos, Carbon::parse('2026-07-01'));

        $this->assertNull($r['componentes']['absenteismo_pct'],
            'Absenteísmo em standby — sempre null nesta phase.');
    }

    // ─── DESEMP-08 · Promoção por 2 meses consecutivos ──────────────────────

    #[Test]
    public function test_2_meses_consecutivos_intermediario_promove_para_maximo(): void
    {
        // DESEMP-08 — Junho intermediario + Julho intermediario natural →
        // Julho retorna 'maximo' + faixa_promovida=true.
        $u = $this->criarUserAnalista('Analista Promocao');

        // Snapshot histórico de junho já com faixa intermediario.
        $this->criarSnapshotMensal($u, '2026-06-01', 'intermediario', 95);

        // 3 empresas na carteira com faturamento/margem que geram nota_final
        // dentro da faixa intermediario (4.50-4.99). Calibragem pós réguas 1-5:
        //   NPS 5.00 + régua_fat(+4.75%) = 4 pts + régua_margem(+5.01%) = 5 pts
        //   → média = (5 + 4 + 5) / 3 = 14/3 ≈ 4.67 (intermediario).
        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-PROMO-1');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-PROMO-2');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-PROMO-3');

        // NPS 5.00 exato (3 respostas score_analista=5). Fase 105 (NPSWIN-04):
        // completed_at em AGOSTO (M+1 de julho) — régua inalterada.
        $this->mockNpsRespostaPrincipal($c1, '2026-08', 5);
        $this->mockNpsRespostaPrincipal($c2, '2026-08', 5);
        $this->mockNpsRespostaPrincipal($c3, '2026-08', 5);

        // Var faturamento: 4.75% (revenue prev 10.000/dia → current 10.475/dia,
        // ratio independe do comprimento da janela) → régua_fat 4 pts (1% a 5%).
        //
        // Fase 102 (BON-03) var_margem_pct: percentageMargin, não mais R$.
        // pctBaseline = 2000/10000×100 = 20,00%; pctAtual = 2200/10475×100 =
        // 21,0024% → diff = (21,0024-20,00)/20,00×100 = 5,0119% → round(2) =
        // 5,01% → régua_margem(5,01%) = 5 pts (>4%, mesmo bucket do valor
        // antigo +4,50%, mas NÚMERO diferente — não é a fórmula antiga).
        foreach ([$c1, $c2, $c3] as $c) {
            $this->mockAdmanDiario(
                $c,
                '2026-07',
                revenueAtual: 10475,
                revenueAnterior: 10000,
                margemAtual: 2200,
                margemAnterior: 2000,
            );
        }

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        // Nota esperada pós réguas: (5.00 + 4 + 5) / 3 = 4.67 (intermediario natural).
        $this->assertEqualsWithDelta(4.67, $r['nota_final'], 0.01,
            'Nota Julho deve cair dentro da faixa intermediario (4.67 esperado após réguas 1-5).');
        $this->assertSame('maximo', $r['faixa_bonus'],
            'DESEMP-08: Junho intermediario + Julho intermediario → promove para maximo.');
        $this->assertTrue($r['faixa_promovida'],
            'faixa_promovida deve ser true quando DESEMP-08 dispara.');
    }

    // ─── DESEMP-10 · Sem carteira ────────────────────────────────────────────

    #[Test]
    public function test_user_sem_carteira_retorna_sem_carteira_true_com_motivo_pt_br(): void
    {
        // DESEMP-10 — user com 0 empresas ativas na carteira retorna shape
        // com sem_carteira=true + motivo pt-BR "Sem carteira em julho/2026".
        $u = $this->criarUserAnalista('Analista sem carteira');

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertTrue($r['sem_carteira'],
            'User sem company_users no mês → sem_carteira = true.');
        $this->assertIsString($r['motivo']);
        $this->assertStringContainsString('Sem carteira em', $r['motivo'],
            'Motivo deve mencionar "Sem carteira em" em pt-BR.');
        $this->assertStringContainsString('julho', mb_strtolower($r['motivo']),
            'Motivo deve incluir o nome do mês em pt-BR (julho).');
        $this->assertSame(0, $r['empresas_carteira']);
        $this->assertNull($r['nota_final']);
        $this->assertNull($r['faixa_bonus']);
    }

    // ─── DESEMP-11 · Fonte UNIFICADA do faturamento (diff service) ──────────

    #[Test]
    public function test_var_faturamento_fonte_unificada_adman_exclui_sem_custid(): void
    {
        // DESEMP-11 (SUPERSEDED 2026-07-22 pela unificação): o antigo contrato
        // "ML-first + Adman fallback + exclusão 'none'" do `computeVarFaturamento`
        // foi removido. O faturamento agora delega ao AdmanMetricDiffService —
        // MESMA fonte e MESMA resolução de custId (`adman_account_id ?: ml_store_id`)
        // da carteira. Consequência: uma empresa conta pra baseline quando o diff
        // service tem `revenue.diff_pct` (custId + dado nas duas janelas); sem
        // custId → `emptyMetrics()` → fora (nem ML resgata mais). O stub de
        // provider (MetricsProviderFactory) já NÃO participa do faturamento.
        $u = $this->criarUserAnalista('Analista Multi-Fonte');

        $a = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-UNIF-A');
        $b = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-UNIF-B');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months'); // sem custId → excluída

        // A e B: custId + fixture densa nas duas janelas → contam.
        $this->mockAdmanDiario($a, '2026-07', revenueAtual: 11111, revenueAnterior: 10000); // +11.11%
        $this->mockAdmanDiario($b, '2026-07', revenueAtual: 11112, revenueAnterior: 10000); // +11.12%

        // C: sem adman_account_id nem ml_store_id → custId vazio → o diff service
        // devolve emptyMetrics() (zero HTTP) → revenue.diff_pct null → não conta.
        // Mesmo com AdmanMetric local presente, sem custId ela fica de fora — é o
        // que a carteira também mostraria (empresa "sem fonte").
        $this->mockAdmanDiario($c, '2026-07', revenueAtual: 99999, revenueAnterior: 1);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertSame(2, $r['empresas_com_baseline'],
            'Empresa sem custId (C) fica FORA do faturamento; só A e B (com custId) contam.');
        $this->assertNotNull($r['componentes']['var_faturamento_pct']);
        // Média de +11.11% (A) e +11.12% (B) ≈ +11.115%.
        $this->assertEqualsWithDelta(11.115, $r['componentes']['var_faturamento_pct'], 0.03,
            'var_faturamento_pct = média de A e B (via diff service); C excluída por não ter custId.');
    }

    // ─── Sanity: régua BonusFaixa via nota exata ────────────────────────────

    #[Test]
    public function test_nota_5_exata_retorna_maximo(): void
    {
        // DESEMP-08 — regra suplementar: nota = 5.00 exato sobe direto para
        // maximo (mesmo sem histórico). Cobre o branch de nota >= 5.00 no
        // promoverPor2MesesConsecutivos indiretamente via classificação
        // canônica da régua ([5.00, 5.00] é a faixa 'maximo' do seed).
        //
        // Fabricamos NPS = 5, var_fat > 5% → régua_fat 5 pts, var_margem > 4% → régua_margem 5 pts.
        // Média = (5 + 5 + 5) / 3 = 5.00 exato → faixa maximo direto.
        $u = $this->criarUserAnalista('Analista Nota Cheia');

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-NOTA5-1');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-NOTA5-2');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months', admanAccountId: 'CUST-NOTA5-3');

        // Revenue +10% (>5% → 5 pts). Fase 102 (BON-03) var_margem_pct:
        // pctBaseline=2000/10000×100=20,00%; pctAtual=2750/11000×100=25,00%
        // (exato) → diff=(25,00-20,00)/20,00×100=+25,00% (>4% → 5 pts).
        foreach ([$c1, $c2, $c3] as $c) {
            // Fase 105 (NPSWIN-04): completed_at em AGOSTO (M+1 de julho).
            $this->mockNpsRespostaPrincipal($c, '2026-08', 5);
            $this->mockAdmanDiario(
                $c,
                '2026-07',
                revenueAtual: 11000,
                revenueAnterior: 10000,
                margemAtual: 2750,
                margemAnterior: 2000,
            );
        }

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(5.00, $r['nota_final'], 0.001,
            'Nota final = 5.00 exata (NPS 5 + régua_fat 5 + régua_margem 5).');
        $this->assertSame('maximo', $r['faixa_bonus'],
            'Faixa 5.00 exata cai em maximo (régua seed [5.00, 5.00]).');
    }

    // ─── Bug 2026-07-13 · dimensão do NPS por CARGO (não por isMentor) ──────

    #[Test]
    public function test_nps_dimensao_por_cargo_estrategista_e_analista_diferem(): void
    {
        // Reproduz o bug relatado: estrategista (Douglas) e analista (Stefani)
        // da MESMA empresa recebiam a mesma nota NPS (a da dimensão 'analista'),
        // porque a dimensão era escolhida por isMentor() (role do sistema) e
        // estrategistas não têm role='mentor'. Agora a dimensão vem do CARGO
        // (user_setores→cargos), então cada um recebe a sua.
        $estrategista = $this->criarUserComCargo('Douglas', $this->cargoEstrategistaId);
        $analista     = $this->criarUserComCargo('Stefani', $this->cargoAnalistaId);

        // Empresa compartilhada (created_at -3 meses → não é "nova" no DESEMP-04).
        $ts = Carbon::parse('-3 months')->toDateTimeString();
        $empresa = Company::factory()->create();
        $empresa->timestamps = false;
        $empresa->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $empresa->timestamps = true;
        foreach ([[$estrategista, 'estrategista'], [$analista, 'consultor']] as [$u, $pivotRole]) {
            DB::table('company_users')->insert([
                'company_id'  => $empresa->id,
                'user_id'     => $u->id,
                'role'        => $pivotRole,
                'assigned_at' => $ts,
                'created_at'  => $ts,
                'updated_at'  => $ts,
            ]);
        }

        // Template principal com 1 pergunta 'estrategista' + 1 'analista'.
        NpsTemplate::query()->update(['is_default' => false]);
        $tpl = NpsTemplate::factory()->create(['nome' => 'Principal 2 dims', 'is_default' => true, 'active' => true]);
        $mkPergunta = function (string $dim) use ($tpl) {
            $q = NpsTemplateQuestion::create([
                'template_id' => $tpl->id,
                'texto'       => "Nota {$dim}?",
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => 1,
            ]);
            for ($p = 1; $p <= 5; $p++) {
                NpsTemplateOption::create(['question_id' => $q->id, 'label' => (string) $p, 'peso' => $p, 'ordem' => $p]);
            }
            return $q;
        };
        $qEstr = $mkPergunta('estrategista');
        $qAna  = $mkPergunta('analista');
        NpsTemplate::resetPrincipalCache();

        // 1 survey completed principal com respostas: estrategista=2, analista=4.
        $survey = NpsSurvey::factory()->for($empresa)->completed()->create([
            'template_id'     => $tpl->id,
            'month_reference' => null,
            'completed_at'    => Carbon::parse('2026-07-10 09:00:00'),
        ]);
        $response = NpsResponse::factory()->create(['survey_id' => $survey->id]);
        foreach ([[$qEstr, 'estrategista', 2], [$qAna, 'analista', 4]] as [$q, $dim, $peso]) {
            $opt = NpsTemplateOption::where('question_id', $q->id)->where('peso', $peso)->firstOrFail();
            NpsResponseAnswer::create([
                'response_id'                => $response->id,
                'template_question_id'       => $q->id,
                'template_option_id'         => $opt->id,
                'question_texto_snapshot'    => $q->texto,
                'question_dimensao_snapshot' => $dim,
                'option_label_snapshot'      => (string) $peso,
                'option_peso_snapshot'       => $peso,
            ]);
        }

        // Invoca o computeNpsMedio privado por reflection para os 2 cargos.
        $service = app(DesempenhoScoreService::class);
        $ref = new ReflectionMethod(DesempenhoScoreService::class, 'computeNpsMedio');
        $ref->setAccessible(true);

        $notaEstr = $ref->invoke($service, $estrategista, Carbon::parse('2026-07-01'));
        $notaAna  = $ref->invoke($service, $analista, Carbon::parse('2026-07-01'));

        $this->assertSame(2.0, $notaEstr, 'Estrategista deve receber a nota da dimensão estrategista (2)');
        $this->assertSame(4.0, $notaAna, 'Analista deve receber a nota da dimensão analista (4)');
        $this->assertNotSame($notaEstr, $notaAna, 'notas de cargos diferentes NÃO podem ser iguais');
    }
}

/**
 * Stub controlável do `MetricsProviderFactory` para testes Feature.
 *
 * Isola completamente as chamadas HTTP reais (ML/Adman) sem quebrar o
 * end-to-end do `DesempenhoScoreService`. A ordem de configuração é:
 *   1. `configureCase(Company, 'so-ml'|'so-adman'|'ambos'|'none')`
 *      → determina o que `caseFor()` retorna para a empresa.
 *   2. `configureRevenue(Company, 'YYYY-MM', float)`
 *      → alimenta o provider ML stub retornado por `forCompany()`.
 *
 * Default `caseFor` é `'so-adman'` (empresa cai no fallback Adman local sem
 * exercitar o path ML) — testes que precisam do stub ML devem chamar
 * `configureCase()` explicitamente.
 *
 * IMPORTANTE: subclasse do `MetricsProviderFactory` real com constructor
 * override que ignora as deps (AdmanMetricsProvider/MlMetricsProvider) —
 * o service usa APENAS `caseFor()` e `forCompany()`, então isolamento é
 * suficiente.
 */
class DesempenhoScoreServiceTestProviderStub extends MetricsProviderFactory
{
    /** @var array<int, string> map company_id → caseFor value */
    private array $caseByCompany = [];

    /** @var array<int, array<string, float>> map company_id → [YYYY-MM → revenue] */
    private array $revenueByCompany = [];

    public function __construct()
    {
        // Intencionalmente não chama parent::__construct() — as deps
        // AdmanMetricsProvider / MlMetricsProvider não são exercitadas
        // por este stub (o service usa apenas caseFor + forCompany).
    }

    public function configureCase(Company $c, string $case): void
    {
        $this->caseByCompany[$c->id] = $case;
    }

    public function configureRevenue(Company $c, string $mesYm, float $revenue): void
    {
        $this->revenueByCompany[$c->id] ??= [];
        $this->revenueByCompany[$c->id][$mesYm] = $revenue;
    }

    public function caseFor(Company $company): string
    {
        return $this->caseByCompany[$company->id] ?? 'so-adman';
    }

    /**
     * Retorna 1 provider stub que responde `readForCompany` com revenue
     * pré-configurado por (empresa, mês). Todos os outros campos numéricos
     * são null — o service só usa `revenue` deste DTO para var_faturamento.
     *
     * @return array<int, MetricsProvider>
     */
    public function forCompany(Company $company): array
    {
        $map = $this->revenueByCompany[$company->id] ?? [];
        return [new DesempenhoScoreServiceTestMlProviderStub($company->id, $map)];
    }
}

/**
 * Provider ML stub que retorna DTO com revenue mockado por mês.
 * Nenhum efeito colateral — usado apenas dentro da suite Feature Phase 74.
 */
class DesempenhoScoreServiceTestMlProviderStub implements MetricsProvider
{
    /**
     * @param array<string, float> $revenueByMonth  map YYYY-MM → revenue
     */
    public function __construct(
        private int $companyId,
        private array $revenueByMonth,
    ) {
    }

    public function supports(Company $company): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'stub';
    }

    public function readForCompany(Company $company, Carbon $from, Carbon $to): UnifiedMetricsDto
    {
        $key = $from->format('Y-m');
        $revenue = $this->revenueByMonth[$key] ?? null;

        return new UnifiedMetricsDto(
            company_id: $company->id,
            source: 'ml',
            period_from: $from,
            period_to: $to,
            revenue: $revenue,
            ad_spend: null,
            sold_quantity: null,
            tacos: null,
            net_billing: null,
            sales_fee: null,
            taxes: null,
            shipping_cost: null,
            product_cost: null,
            contribution_margin: null,
            acos: null,
            roas: null,
            clicks: null,
            impressions: null,
            orders_count: null,
        );
    }
}
