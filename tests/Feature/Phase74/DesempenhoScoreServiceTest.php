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
use App\Models\User;
use App\Services\DesempenhoScoreService;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Metrics\UnifiedMetricsDto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    protected function setUp(): void
    {
        parent::setUp();

        // SQLite in-memory precisa de foreign keys ativas para exercer o
        // schema real (constraints + cascade) — padrão do projeto.
        DB::statement('PRAGMA foreign_keys = ON');

        // Congela o "agora" no primeiro dia de agosto/2026 às 14:05 BRT —
        // depois do sync Adman D-1 (11:00), como no cron do consolidar-mes.
        // Julho/2026 é o mês fechado; agosto/2026 é o corrente.
        Carbon::setTestNow(Carbon::parse('2026-08-01 14:05:00'));

        // Substitui MetricsProviderFactory por um stub controlável — isola
        // qualquer chamada HTTP real ao ML/Adman API (DESEMP-11 D-27).
        $this->providerStub = new DesempenhoScoreServiceTestProviderStub();
        $this->app->instance(MetricsProviderFactory::class, $this->providerStub);

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
     * Cria uma Company e attach ao user via pivot `company_users` com
     * `created_at` controlado — parâmetro chave para exercitar o filtro
     * "empresa nova" do DESEMP-04.
     */
    private function criarEmpresaNaCarteira(User $user, string $pivotCreatedAt = '-3 months', string $role = 'consultor'): Company
    {
        // Ajuste 2026-07-09 (força tarefa): o filtro "empresa nova" agora usa
        // companies.created_at (não pivot->created_at). Continuamos aceitando
        // $pivotCreatedAt como parâmetro para preservar a API dos testes, mas
        // aplicamos o timestamp EM AMBOS os lugares (company + pivot) para o
        // cenário de teste bater com a lógica de produção.
        $ts = Carbon::parse($pivotCreatedAt)->toDateTimeString();

        $company = Company::factory()->create();
        // Força companies.created_at no timestamp fixture-controlado.
        $company->timestamps = false;
        $company->forceFill(['created_at' => $ts, 'updated_at' => $ts])->save();
        $company->timestamps = true;

        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => $role,
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
     * Espec da diretoria (74-SPEC.md DESEMP-01) + Ajuste 2026-07-09:
     *  - NPS médio: 4.25 → 4.25 pts (já é escala 1-5)
     *  - % var faturamento: 3.00 → régua faturamento retorna 4 pts (faixa 1% a 5%)
     *  - % var margem contribuição: 2.80 → régua margem retorna 4 pts (faixa 2% a 4%)
     *  - nota_final = round((4.25 + 4 + 4) / 3, 2) = 4.08
     *  - faixa_bonus = 'basico' (faixa 4.00 a 4.49)
     *
     * IMPORTANTE: Depois do primeiro deploy em prod, ficou claro que o cálculo
     * "média direta em escalas naturais" (raw %) permitia notas fora do range
     * [1, 5]: analistas com var_fat=-15% + var_margem=-20% ficavam com nota
     * ~-10, distorcendo toda a régua de bônus. Fix: variações passam pelas
     * réguas 1-5 (SPEC-04/SPEC-05) antes de entrar na média. Isso levou o
     * fixture Carlos de 3.35 (sem bônus) para 4.08 (básico) — mudança
     * intencional aprovada pela diretoria em 2026-07-09.
     *
     * Distribuição escolhida para determinismo (evita drift de arredondamento
     * float): as 3 empresas convergem para os mesmos deltas — o cerne do
     * teste é a MATEMÁTICA, não a variabilidade da carteira. Edge cases
     * de variabilidade são cobertos em testes separados abaixo.
     *  - Cada empresa: revenue julho 10.300 / junho 10.000 → +3.00%
     *  - Cada empresa: margem julho 10.280 / junho 10.000 → +2.80%
     *  - NPS: 4 respostas legacy score_analista distribuídas → média 4.25
     *    (5, 4, 4, 4 → 17/4 = 4.25)
     */
    private function criarCarlosCompleto(): User
    {
        $carlos = $this->criarUserAnalista('Carlos');

        // 3 empresas na carteira há -3 meses (bem fora do filtro de empresa
        // nova de 2 meses do DESEMP-04).
        $empresas = [
            $this->criarEmpresaNaCarteira($carlos, '-3 months'),
            $this->criarEmpresaNaCarteira($carlos, '-3 months'),
            $this->criarEmpresaNaCarteira($carlos, '-3 months'),
        ];

        // Revenue + margem em julho e junho/2026 — deltas convergem para
        // 3.00% e 2.80% em TODAS as empresas.
        foreach ($empresas as $c) {
            $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 10300, margem: 10280);
            $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);
        }

        // NPS: 4 respostas legacy [5, 4, 4, 4] distribuídas — média 4.25 exata.
        // Distribuição: empresa 0 recebe 2 surveys (scores 5 e 4); empresas 1
        // e 2 recebem 1 survey cada (score 4 em ambas).
        $this->mockNpsRespostaPrincipal($empresas[0], '2026-07', 5);
        $this->mockNpsRespostaPrincipal($empresas[0], '2026-07', 4);
        $this->mockNpsRespostaPrincipal($empresas[1], '2026-07', 4);
        $this->mockNpsRespostaPrincipal($empresas[2], '2026-07', 4);

        return $carlos;
    }

    // ═══ Testes ═══════════════════════════════════════════════════════════

    // ─── DESEMP-01 · Fixture Carlos — âncora bloqueante ─────────────────────

    #[Test]
    public function test_fixture_carlos_retorna_nota_4_08_basico(): void
    {
        // DESEMP-01 — contra regressão silenciosa. Se este teste quebra,
        // é sinal de que a matemática da engine v2 divergiu da spec.
        //
        // Ajuste 2026-07-09: fixture Carlos passou de 3.35 (sem_bonus) para
        // 4.08 (basico) quando as réguas 1-5 foram aplicadas às variações
        // antes da média. Ver comentário no criarCarlosCompleto().
        $carlos = $this->criarCarlosCompleto();

        /** @var DesempenhoScoreService $service */
        $service = app(DesempenhoScoreService::class);
        $result  = $service->compute($carlos, Carbon::parse('2026-07-01'));

        // Componentes na escala natural (RAW % antes da normalização régua).
        $this->assertEqualsWithDelta(4.25, $result['componentes']['nps_medio'], 0.001,
            'NPS médio Carlos deve ser exatamente 4.25 (média legacy [5,4,4,4]).');
        $this->assertEqualsWithDelta(3.00, $result['componentes']['var_faturamento_pct'], 0.001,
            'Var faturamento Carlos deve ser exatamente +3.00%.');
        $this->assertEqualsWithDelta(2.80, $result['componentes']['var_margem_pct'], 0.001,
            'Var margem contribuição Carlos deve ser exatamente +2.80%.');
        $this->assertNull($result['componentes']['absenteismo_pct'],
            'Absenteísmo sempre null nesta phase (DESEMP-06).');

        // Nota final — âncora bloqueante ajustada em 2026-07-09.
        // Cálculo: NPS 4.25 + régua_fat(+3%) = 4 pts + régua_margem(+2.8%) = 4 pts
        //          → média = (4.25 + 4 + 4) / 3 = 4.0833 → round(2) = 4.08.
        $this->assertEqualsWithDelta(4.08, $result['nota_final'], 0.001,
            'Nota final Carlos deve ser 4.08 (NPS 4.25 + régua_fat 4 + régua_margem 4 → média).');

        // Classificação — faixa básico ([4.00, 4.49]).
        $this->assertSame('basico', $result['faixa_bonus'],
            'Faixa Carlos deve ser basico (4.08 dentro de [4.00, 4.49]).');
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
        /** @var DesempenhoScoreService $service */
        $service = app(DesempenhoScoreService::class);
        $method  = new ReflectionMethod($service, 'computeNotaFinal');
        $method->setAccessible(true);

        $this->assertEqualsWithDelta(4.08, $method->invoke($service, 4.25, 3.0, 2.8), 0.001,
            'computeNotaFinal(4.25, 3.0, 2.8) → 4.08 (NPS + régua_fat + régua_margem / 3).');

        $this->assertEqualsWithDelta(4.00, $method->invoke($service, null, 3.0, 2.8), 0.001,
            'NPS null → média dos restantes ((régua_fat 4 + régua_margem 4) / 2 = 4.00).');

        $this->assertEqualsWithDelta(4.25, $method->invoke($service, 4.25, null, null), 0.001,
            'Somente NPS presente → nota_final = próprio NPS (média de 1 elemento).');

        $this->assertNull($method->invoke($service, null, null, null),
            'Todos componentes null → nota_final = null.');

        // Casos extremos que motivaram o fix (variações negativas grandes):
        // (NPS 4 + régua_fat 1 + régua_margem 1) / 3 = 6/3 = 2.00.
        $this->assertEqualsWithDelta(2.00, $method->invoke($service, 4.0, -15.0, -20.0), 0.001,
            'Variações negativas fortes → régua 1+1 pt; nota final = 2.00, NUNCA negativa.');

        $this->assertEqualsWithDelta(1.00, $method->invoke($service, 1.0, -50.0, -50.0), 0.001,
            'Cenário pior caso → nota mínima absoluta = 1.00 (nunca abaixo).');

        $this->assertEqualsWithDelta(5.00, $method->invoke($service, 5.0, 100.0, 100.0), 0.001,
            'Cenário melhor caso → nota máxima = 5.00 (nunca acima).');
    }

    // ─── DESEMP-03 · NPS = média das notas do mês; sem notas força 0 ────────

    #[Test]
    public function test_nps_medio_e_zero_quando_user_sem_respostas_no_mes(): void
    {
        // DESEMP-03 — user com carteira ativa mas ZERO NpsResponse no mês
        // recebe nps_medio = 0.0 (penaliza, decisão da diretoria).
        $u = $this->criarUserAnalista('Sem NPS');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Sem NPS mas com baseline de faturamento para não devolver todos nulls.
        $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 10500, margem: 10250);
        $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertSame(0.0, $r['componentes']['nps_medio'],
            'Sem NpsResponse no mês → nps_medio = 0.0 (não null, penaliza).');
    }

    #[Test]
    public function test_nps_medio_e_media_das_notas_recebidas_no_mes(): void
    {
        // DESEMP-03 — user com 3 respostas legacy [5, 4, 3] → média 4.00.
        $u = $this->criarUserAnalista('Analista NPS 4.0');
        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months');

        $this->mockNpsRespostaPrincipal($c1, '2026-07', 5);
        $this->mockNpsRespostaPrincipal($c2, '2026-07', 4);
        $this->mockNpsRespostaPrincipal($c3, '2026-07', 3);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(4.00, $r['componentes']['nps_medio'], 0.001,
            'Respostas [5,4,3] → nps_medio = 4.00 (média aritmética).');
    }

    // ─── DESEMP-04 · % var faturamento ──────────────────────────────────────

    #[Test]
    public function test_var_faturamento_media_das_variacoes_por_empresa(): void
    {
        // DESEMP-04 — carteira com deltas -2%, +7%, +4% → média 3.00%.
        $u = $this->criarUserAnalista('Analista var_fat');

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Empresa 1: 9800 / 10000 = -2%
        $this->mockAdmanRevenueMargem($c1, '2026-07', revenue: 9800);
        $this->mockAdmanRevenueMargem($c1, '2026-06', revenue: 10000);
        // Empresa 2: 10700 / 10000 = +7%
        $this->mockAdmanRevenueMargem($c2, '2026-07', revenue: 10700);
        $this->mockAdmanRevenueMargem($c2, '2026-06', revenue: 10000);
        // Empresa 3: 10400 / 10000 = +4%
        $this->mockAdmanRevenueMargem($c3, '2026-07', revenue: 10400);
        $this->mockAdmanRevenueMargem($c3, '2026-06', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(3.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Carteira [-2%, +7%, +4%] → var_faturamento_pct = 3.00 (média exata).');
        $this->assertSame(3, $r['empresas_com_baseline'],
            'As 3 empresas têm baseline (prev > 0) → empresas_com_baseline = 3.');
    }

    #[Test]
    public function test_var_faturamento_exclui_empresa_nova_da_media(): void
    {
        // DESEMP-04 — empresa recém-adicionada (pivot há 15 dias) NÃO entra
        // no cálculo de variação; só as antigas contam.
        $u = $this->criarUserAnalista('Analista Empresa Nova');

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $cNova = $this->criarEmpresaNaCarteira($u, '-15 days');

        // C1 e C2 ficam com deltas simétricos (+2% e +8% → média 5%).
        $this->mockAdmanRevenueMargem($c1, '2026-07', revenue: 10200);
        $this->mockAdmanRevenueMargem($c1, '2026-06', revenue: 10000);
        $this->mockAdmanRevenueMargem($c2, '2026-07', revenue: 10800);
        $this->mockAdmanRevenueMargem($c2, '2026-06', revenue: 10000);

        // A empresa NOVA teria delta absurdo (+100%). Se entrasse na média,
        // puxaria o resultado para acima de 5.00 — o filtro precisa excluí-la.
        $this->mockAdmanRevenueMargem($cNova, '2026-07', revenue: 20000);
        $this->mockAdmanRevenueMargem($cNova, '2026-06', revenue: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(5.00, $r['componentes']['var_faturamento_pct'], 0.001,
            'Empresa nova (pivot -15 dias) NÃO entra na média — apenas C1 e C2 contam.');
        $this->assertSame(2, $r['empresas_com_baseline'],
            'Só 2 empresas antigas qualificam (empresas_com_baseline).');
        $this->assertSame(3, $r['empresas_carteira'],
            'Carteira total continua sendo 3 empresas (a nova não some dela).');
    }

    // ─── DESEMP-05 · % var margem via Adman canônico ────────────────────────

    #[Test]
    public function test_var_margem_usa_adman_como_fonte_canonica(): void
    {
        // DESEMP-05 — mesmo com caseFor='so-ml' para a empresa, a margem SEMPRE
        // vem via AdmanMetric.contribution_margin (ML não expõe custo).
        $u = $this->criarUserAnalista('Analista Margem ML');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Provider ML sinaliza que a empresa é "so-ml", mas margem só vive
        // no Adman local. O service não deve ignorar o Adman aqui.
        $this->providerStub->configureCase($c, 'so-ml');
        $this->providerStub->configureRevenue($c, '2026-07', 20000.0);
        $this->providerStub->configureRevenue($c, '2026-06', 15000.0);

        // AdmanMetric só tem margem — revenue vem via ML stub.
        $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 0, margem: 11000);
        $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 0, margem: 10000);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        // Margem: (11000 - 10000) / 10000 = +10.00%
        $this->assertEqualsWithDelta(10.00, $r['componentes']['var_margem_pct'], 0.001,
            'Margem vem sempre do Adman (contribution_margin), mesmo com caseFor=so-ml.');
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

        $u = $this->criarUserAnalista('Analista Margem Lag Adman');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

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

        // Fix: janela anterior recortada para os mesmos 5 dias (100*5=500) —
        // var = (750-500)/500 = +50.00% (positiva, reflete a melhora real).
        // Sem o fix, o valor seria (750-900)/900 = -16.67% (sinal invertido).
        $this->assertEqualsWithDelta(50.00, $r['componentes']['var_margem_pct'], 0.01,
            'Dias finais sem margem na janela atual NÃO devem inverter o sinal da variação — '
            .'janela anterior deve ser recortada pra mesma contagem de dias (fix Tomelin).');
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
        //   NPS 5.00 + régua_fat(+4.75%) = 4 pts + régua_margem(+4.50%) = 5 pts
        //   → média = (5 + 4 + 5) / 3 = 14/3 ≈ 4.67 (intermediario).
        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months');

        // NPS 5.00 exato (3 respostas score_analista=5).
        $this->mockNpsRespostaPrincipal($c1, '2026-07', 5);
        $this->mockNpsRespostaPrincipal($c2, '2026-07', 5);
        $this->mockNpsRespostaPrincipal($c3, '2026-07', 5);

        // Var faturamento: 4.75% (3 empresas variando +4.75% cada).
        // Revenue prev 10.000 → current 10.475 → régua_fat 4 pts (faixa 1% a 5%)
        // Var margem: 4.50% (margem prev 10.000 → current 10.450) → régua_margem 5 pts (>4%)
        foreach ([$c1, $c2, $c3] as $c) {
            $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 10475, margem: 10450);
            $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);
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

    // ─── DESEMP-11 · Fonte ML-first + Adman fallback + exclusão "none" ──────

    #[Test]
    public function test_provider_ml_first_com_adman_fallback(): void
    {
        // DESEMP-11 — 3 empresas com casos diferentes:
        //   A: caseFor='so-ml'    → provider ML fornece revenue via stub.
        //   B: caseFor='so-adman' → AdmanMetric fornece revenue via SUM local.
        //   C: caseFor='none'     → EXCLUÍDA do cálculo (empresas_com_baseline não conta).
        $u = $this->criarUserAnalista('Analista Multi-Fonte');

        $a = $this->criarEmpresaNaCarteira($u, '-3 months');
        $b = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Empresa A — só-ML. Stub retorna revenue 10.000 (jun) e 11.111 (jul)
        // → +11.11%. AdmanMetric intencionalmente vazio para a A.
        $this->providerStub->configureCase($a, 'so-ml');
        $this->providerStub->configureRevenue($a, '2026-07', 11111.0);
        $this->providerStub->configureRevenue($a, '2026-06', 10000.0);
        // Mês anterior é SEMPRE Adman (baseline histórico local — service não
        // chama ML para o mês passado). Precisamos preencher junho no Adman.
        $this->mockAdmanRevenueMargem($a, '2026-06', revenue: 10000);
        // Julho — sem row para forçar o service a usar o stub.
        $this->mockAdmanRevenueMargem($a, '2026-07', revenue: 0);

        // Empresa B — só-Adman. AdmanMetric fornece 5.000 (jun) → 5.556 (jul)
        // → +11.12%.
        $this->providerStub->configureCase($b, 'so-adman');
        $this->mockAdmanRevenueMargem($b, '2026-07', revenue: 5556);
        $this->mockAdmanRevenueMargem($b, '2026-06', revenue: 5000);

        // Empresa C — 'none'. Deve ser EXCLUÍDA logo no filtro
        // "provider aplicável" do computeVarFaturamento (não conta como baseline).
        $this->providerStub->configureCase($c, 'none');
        $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 99999);
        $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 1);

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertSame(2, $r['empresas_com_baseline'],
            'Empresa "none" (C) fica FORA do cálculo; só A e B contam.');
        $this->assertNotNull($r['componentes']['var_faturamento_pct']);
        // Média de +11.11% (A ML) e +11.12% (B Adman) ≈ +11.12%.
        $this->assertEqualsWithDelta(11.115, $r['componentes']['var_faturamento_pct'], 0.03,
            'var_faturamento_pct = média de A (ML) e B (Adman), C excluída.');
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

        $c1 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c2 = $this->criarEmpresaNaCarteira($u, '-3 months');
        $c3 = $this->criarEmpresaNaCarteira($u, '-3 months');

        // Revenue +10% (>5% → 5 pts), margem +5% (>4% → 5 pts).
        foreach ([$c1, $c2, $c3] as $c) {
            $this->mockNpsRespostaPrincipal($c, '2026-07', 5);
            $this->mockAdmanRevenueMargem($c, '2026-07', revenue: 11000, margem: 10500);
            $this->mockAdmanRevenueMargem($c, '2026-06', revenue: 10000, margem: 10000);
        }

        $service = app(DesempenhoScoreService::class);
        $r = $service->compute($u, Carbon::parse('2026-07-01'));

        $this->assertEqualsWithDelta(5.00, $r['nota_final'], 0.001,
            'Nota final = 5.00 exata (NPS 5 + régua_fat 5 + régua_margem 5).');
        $this->assertSame('maximo', $r['faixa_bonus'],
            'Faixa 5.00 exata cai em maximo (régua seed [5.00, 5.00]).');
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
