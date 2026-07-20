<?php

namespace Tests\Feature\Dashboard;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fase 97 Plan 02 — Backend do redesign da Dashboard Mercado Livre.
 *
 * Cobre os widgets que ANTES ignoravam o recorte de filtros (CONTEXT
 * Riscos §2):
 *   1. `performance_equipe` ("Score da equipe") passa a listar só os
 *      analistas/estrategistas das empresas do recorte aplicado.
 *   2. `nps_pendentes` passa a usar `NpsPendingService::forCompanies($companies)`
 *      (recorte) em vez de `forCarteira` (todas as empresas do sistema).
 *   3. `nps_ruins` traz respostas de nota baixa (<=3) do recorte, EXCLUINDO
 *      as invalidadas pelo admin (Fase 96, `scopeValida`).
 *   4. `novas_empresas` traz os cards das empresas com contrato ativo
 *      iniciado no mês corrente (D3), com os campos completos do card.
 *
 * `Http::preventStrayRequests()` + `Cache::flush()` no setUp — nenhuma
 * chamada HTTP real deve ocorrer (empresas de fixture sem cust_id caem 100%
 * no fallback DB) e o cache do `DesempenhoScoreService` (chave versionada,
 * hoje v5) é isolado entre testes sem hardcodar a versão.
 */
class DashboardWidgetsRecorteTest extends TestCase
{
    use RefreshDatabase;

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
        Http::preventStrayRequests();
        // Empresas de fixture não têm cust_id (nem ml_store_id nem
        // adman_account_id) — o controller cai 100% no fallback DB
        // (adman_metrics), mas o DesempenhoScoreService::computeVarMargem
        // delega a AdmanMetricDiffService, que faz HTTP quando
        // adman_account_id está preenchido. Nenhuma empresa daqui preenche
        // esse campo, então este fake nunca deveria ser exercido — mantido
        // como cinto-de-segurança (mesmo padrão do Plan 102-01).
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);
        // Isola o cache versionado do DesempenhoScoreService entre testes
        // (agnóstico de versão — NÃO hardcodar a string da chave).
        Cache::flush();

        // Setor Performance + cargos analista/estrategista — fonte canônica
        // do cargo do user para o Score da equipe (users.role é legacy).
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance 97-02',
            'slug'       => 'performance-97-02-' . uniqid(),
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

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Empresa do universo Performance (contrato ativo, setor performance).
     * SEM cust_id — garante fallback DB puro, sem depender de cache Adman.
     */
    private function criarEmpresaPerformance(
        string $name,
        string $cnpj,
        ?string $dataContratacao = null,
    ): Company {
        $company = Company::create([
            'name'        => $name,
            'cnpj'        => $cnpj,
            'active'      => true,
            'marketplace' => 'meli',
        ]);

        $servico = Servico::create([
            'nome'          => 'Gestao Fase97-02 ' . uniqid(),
            'valor_padrao'  => 1000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1000,
            'data_contratacao' => $dataContratacao ?? now()->subYears(2)->toDateString(),
            'ativo'            => true,
        ]);

        return $company;
    }

    /**
     * Vincula o profissional à empresa via pivot `company_users` (ramo
     * legado `servico_id NULL` — resolvido pelo `CarteiraContextService`
     * porque a empresa já tem contrato Performance ativo) E dá o cargo
     * correspondente via `user_setores` (fonte do Score da equipe).
     */
    private function attachProfissional(User $user, Company $company, string $role): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $cargoId = $role === 'estrategista' ? $this->cargoEstrategistaId : $this->cargoAnalistaId;
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function seedMetricaDia(Company $company, int $diasAtras, float $revenue, float $marginPct): void
    {
        AdmanMetric::create([
            'company_id'              => $company->id,
            'reference_date'          => now()->subDays($diasAtras)->toDateString(),
            'revenue'                 => $revenue,
            'ad_spend'                => 10.0,
            'contribution_margin_pct' => $marginPct,
            'synced_at'               => now(),
        ]);
    }

    /**
     * NpsSurvey no template PRINCIPAL (2026-07-13 — só o modelo principal
     * alimenta os widgets da home, incl. `$npsResponses`/`nps_ruins` reusado
     * por este dashboard) completed + 1 answer na dimensão 'empresa' com o
     * peso desejado. Usa o template "NPS Padrão" seedado pela migration da
     * Phase 68 (já tem 1 pergunta dimensao='empresa' + 5 opções peso 1..5) —
     * evita recriar template/pergunta/opção do zero.
     */
    private function criarNpsRuim(
        Company $company,
        int $notaEmpresa,
        ?string $comment = null,
        bool $invalidada = false,
    ): NpsSurvey {
        $template = NpsTemplate::where('is_default', true)->firstOrFail();
        $question = NpsTemplateQuestion::where('template_id', $template->id)
            ->where('dimensao', 'empresa')
            ->firstOrFail();
        $option = NpsTemplateOption::where('question_id', $question->id)
            ->where('peso', $notaEmpresa)
            ->firstOrFail();

        $survey = NpsSurvey::create([
            'token'          => (string) Str::uuid(),
            'company_id'     => $company->id,
            'template_id'    => $template->id,
            'status'         => 'completed',
            'completed_at'   => now()->subDays(1),
            'expires_at'     => now()->addDays(30),
            'auto_generated' => true,
        ]);

        $response = NpsResponse::create([
            'survey_id'       => $survey->id,
            'respondent_name' => 'Cliente Teste',
            'comment'         => $comment,
            'invalidated_at'  => $invalidada ? now() : null,
        ]);

        NpsResponseAnswer::create([
            'response_id'                => $response->id,
            'template_question_id'      => $question->id,
            'template_option_id'        => $option->id,
            'question_texto_snapshot'   => $question->texto,
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'     => (string) $notaEmpresa,
            'option_peso_snapshot'      => $notaEmpresa,
        ]);

        return $survey;
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 1 — Score da equipe e NPS pendentes respeitam o recorte
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ANTES do fix (Riscos §2), `performance_equipe` ignorava company_id e
     * sempre listava TODOS os analistas/estrategistas do setor Performance.
     * Com o fix, filtrar por company_id restringe a lista aos responsáveis
     * daquela empresa.
     */
    public function test_score_da_equipe_respeita_o_recorte_de_company_id(): void
    {
        $admin = $this->criarAdmin();

        $empresaX = $this->criarEmpresaPerformance('Empresa X 97-02', '11111111000111');
        $empresaY = $this->criarEmpresaPerformance('Empresa Y 97-02', '22222222000122');

        $analistaX = User::factory()->create(['name' => 'Analista X', 'role' => 'consultor', 'active' => true]);
        $this->attachProfissional($analistaX, $empresaX, 'consultor');

        $estrategistaY = User::factory()->create(['name' => 'Estrategista Y', 'role' => 'mentor', 'active' => true]);
        $this->attachProfissional($estrategistaY, $empresaY, 'estrategista');

        // Filtro aplicado só na Empresa X — só o responsável dela deve
        // aparecer no Score da equipe.
        $response = $this->actingAs($admin)->get("/dashboard?period=30&company_id={$empresaX->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('performance_equipe', 1)
            ->where('performance_equipe.0.name', 'Analista X')
            ->has('performance_equipe.0.nota_final')
            ->has('performance_equipe.0.breakdown.carteira')
            ->has('performance_equipe.0.breakdown.nps')
            ->has('performance_equipe.0.breakdown.margem')
            ->has('performance_equipe.0.breakdown.tacos')
        );
    }

    /**
     * Sem filtro aplicado, `performance_equipe` continua cobrindo TODO o
     * universo Performance (não regride a visão ampla) — os dois
     * profissionais das duas empresas aparecem.
     */
    public function test_score_da_equipe_sem_filtro_cobre_todo_o_universo(): void
    {
        $admin = $this->criarAdmin();

        $empresaX = $this->criarEmpresaPerformance('Empresa X2 97-02', '33333333000133');
        $empresaY = $this->criarEmpresaPerformance('Empresa Y2 97-02', '44444444000144');

        $analistaX = User::factory()->create(['name' => 'Analista X2', 'role' => 'consultor', 'active' => true]);
        $this->attachProfissional($analistaX, $empresaX, 'consultor');

        $analistaY = User::factory()->create(['name' => 'Analista Y2', 'role' => 'consultor', 'active' => true]);
        $this->attachProfissional($analistaY, $empresaY, 'consultor');

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('performance_equipe', 2)
        );
    }

    /**
     * `nps_pendentes` ANTES usava `forCarteira()` que, para admin, retornava
     * TODAS as empresas do sistema — ignorando company_id. Com o fix
     * (`forCompanies($companies)`), o filtro passa a valer.
     */
    public function test_nps_pendentes_respeita_o_recorte_de_company_id(): void
    {
        // Depois do dia de cobrança default (25) — guard temporal do
        // NpsPendingService precisa estar satisfeito para marcar pendente.
        Carbon::setTestNow('2026-07-26 10:00:00');

        $admin = $this->criarAdmin();
        $empresaX = $this->criarEmpresaPerformance('Empresa Pend X', '55555555000155');
        $empresaY = $this->criarEmpresaPerformance('Empresa Pend Y', '66666666000166');

        $response = $this->actingAs($admin)->get("/dashboard?period=30&company_id={$empresaX->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('nps_pendentes', 1)
            ->where('nps_pendentes.0.company_id', $empresaX->id)
        );

        // grep de defesa: nenhum call-site do adminDashboard deve mais usar
        // forCarteira (só forCompanies).
        $controllerSource = file_get_contents(app_path('Http/Controllers/DashboardController.php'));
        $this->assertStringContainsString('forCompanies(', $controllerSource);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Task 2 — NPS ruim (scopeValida) e novas empresas do mês
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Resposta invalidada pelo admin (Fase 96) NÃO aparece em `nps_ruins`,
     * mesmo com nota baixa. Resposta válida com nota <=3 aparece.
     */
    public function test_nps_ruins_exclui_invalidadas_e_inclui_nota_baixa_valida(): void
    {
        $admin = $this->criarAdmin();

        $empresaInvalidada = $this->criarEmpresaPerformance('Empresa Invalidada', '77777777000177');
        $this->criarNpsRuim($empresaInvalidada, notaEmpresa: 1, comment: 'Pessimo atendimento', invalidada: true);

        $empresaValida = $this->criarEmpresaPerformance('Empresa Nota Baixa', '88888888000188');
        $this->criarNpsRuim($empresaValida, notaEmpresa: 2, comment: 'Podia melhorar', invalidada: false);

        // Nota boa (>=4) não deve entrar em nps_ruins.
        $empresaBoa = $this->criarEmpresaPerformance('Empresa Nota Boa', '99999999000199');
        $this->criarNpsRuim($empresaBoa, notaEmpresa: 5, comment: 'Excelente', invalidada: false);

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('nps_ruins', 1)
            ->where('nps_ruins.0.company_name', 'Empresa Nota Baixa')
            ->where('nps_ruins.0.nota', 2)
            ->where('nps_ruins.0.comment', 'Podia melhorar')
            ->has('nps_ruins.0.company_id')
            ->has('nps_ruins.0.survey_id')
            ->has('nps_ruins.0.data')
        );
    }

    /**
     * `nps_ruins` respeita o recorte de company_id (mesmo padrão dos demais
     * widgets corrigidos nesta fase).
     */
    public function test_nps_ruins_respeita_o_recorte_de_company_id(): void
    {
        $admin = $this->criarAdmin();

        $empresaX = $this->criarEmpresaPerformance('Empresa Ruim X', '10101010000101');
        $this->criarNpsRuim($empresaX, notaEmpresa: 1);

        $empresaY = $this->criarEmpresaPerformance('Empresa Ruim Y', '12121212000112');
        $this->criarNpsRuim($empresaY, notaEmpresa: 1);

        $response = $this->actingAs($admin)->get("/dashboard?period=30&company_id={$empresaX->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('nps_ruins', 1)
            ->where('nps_ruins.0.company_id', $empresaX->id)
        );
    }

    /**
     * `novas_empresas`: empresa com contrato ativo iniciado NESTE mês entra
     * na lista com os campos do card; empresa com contrato antigo não entra.
     */
    public function test_novas_empresas_traz_cards_por_inicio_de_contrato(): void
    {
        $admin = $this->criarAdmin();

        $grupo = CompanyGroup::create(['name' => 'Grupo Teste 97-02']);

        $empresaNova = $this->criarEmpresaPerformance(
            'Empresa Nova Card',
            '13131313000113',
            Carbon::now()->startOfMonth()->addDays(2)->toDateString(),
        );
        $empresaNova->update(['company_group_id' => $grupo->id]);

        $analista = User::factory()->create(['name' => 'Analista Nova', 'role' => 'consultor', 'active' => true]);
        $this->attachProfissional($analista, $empresaNova, 'consultor');

        // Faturamento parcial no período atual — status deve ficar
        // 'saudavel' (fatura + margem positiva).
        $this->seedMetricaDia($empresaNova, 1, 500.0, 20.0);

        $empresaAntiga = $this->criarEmpresaPerformance(
            'Empresa Antiga Card',
            '14141414000114',
            now()->subYears(3)->toDateString(),
        );

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('novas_empresas', 1)
            ->where('novas_empresas.0.id', $empresaNova->id)
            ->where('novas_empresas.0.name', 'Empresa Nova Card')
            ->where('novas_empresas.0.grupo', 'Grupo Teste 97-02')
            ->where('novas_empresas.0.status', 'saudavel')
            ->where('novas_empresas.0.analista', 'Analista Nova')
            ->has('novas_empresas.0.faturamento_parcial')
            ->has('novas_empresas.0.tacos')
        );
    }

    /**
     * Empresa nova SEM faturamento apurado ainda no período → status
     * 'ramp-up' (decisão do executor documentada no controller).
     */
    public function test_novas_empresas_sem_faturamento_fica_ramp_up(): void
    {
        $admin = $this->criarAdmin();

        $this->criarEmpresaPerformance(
            'Empresa Nova Sem Fat',
            '15151515000115',
            Carbon::now()->startOfMonth()->addDays(1)->toDateString(),
        );

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('novas_empresas', 1)
            ->where('novas_empresas.0.status', 'ramp-up')
        );
    }
}
