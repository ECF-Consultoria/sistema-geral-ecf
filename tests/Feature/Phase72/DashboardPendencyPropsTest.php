<?php

namespace Tests\Feature\Phase72;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 72 Plan 04 T3 — Suite Feature de prop injection dos controllers.
 *
 * Cobre SC #2 + SC #3 + SC #5 do ROADMAP Phase 72 + REQs NPS-E-02, E-03, E-05:
 *   1. adminDashboard injeta `nps_pendentes` no Dashboard/Admin com shape completo
 *   2. userDashboard filtra por carteira (lider consultor s/ core.dashboard →
 *      hita userDashboard e ve apenas pivot company_users)
 *   3. PortfolioController::show injeta `nps_pendentes` no Portfolio/Show
 *   4. CompanyController::show usa NpsScoreCalculator para surveys v15 (dual-path
 *      preservado; legacy le direto de nps_responses.score_*)
 *
 * Padrao herdado das Phases 70/71 — assertInertia com `has()`/`where()` +
 * Carbon::setTestNow para guard temporal.
 *
 * Referencias:
 *   - .planning/phases/72-dashboards-pendencias-dia-cobranca/72-02-PLAN.md
 *   - app/Http/Controllers/DashboardController.php (adminDashboard + userDashboard)
 *   - app/Http/Controllers/PortfolioController.php::renderPortfolio
 *   - app/Http/Controllers/CompanyController.php::show
 *   - app/Services/Nps/NpsScoreCalculator.php (compute por dimensao)
 */
class DashboardPendencyPropsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrao herdado.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Limpa Carbon::setTestNow entre testes — evita vazamento temporal.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — adminDashboard injeta nps_pendentes com shape completo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_admin_dashboard_injeta_nps_pendentes_com_shape_completo(): void
    {
        // Cenario: admin com 2 empresas ativas + hoje > diaCobranca (dia 26
        // vs default 25) => ambas aparecem em nps_pendentes com shape do
        // NpsPendingService documentado no Plan 72-01 (6 chaves).
        Carbon::setTestNow('2026-07-26 09:00:00');

        $admin = User::factory()->create(['role' => 'admin']);

        // Fase 97 Plan 02 (DASH-97-1 · Riscos §2) — `nps_pendentes` do
        // adminDashboard passou a usar `forCompanies($companies)` (o MESMO
        // recorte "universo Performance" dos demais widgets) em vez de
        // `forCarteira` (que para admin trazia TODAS as empresas do sistema,
        // sem exigir contrato Performance ativo). Sem contrato, estas
        // empresas de fixture cairiam FORA do recorte e sumiriam da lista —
        // por isso cada uma ganha um contrato ativo de serviço Performance.
        $empresaAlpha = Company::factory()->create(['name' => 'Empresa Alpha']);
        $empresaBeta  = Company::factory()->create(['name' => 'Empresa Beta']);

        foreach ([$empresaAlpha, $empresaBeta] as $empresa) {
            $servico = Servico::create([
                'nome'          => 'Gestao Fase72 ' . uniqid(),
                'valor_padrao'  => 1000,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
            ]);
            ContratoServico::create([
                'company_id'       => $empresa->id,
                'servico_id'       => $servico->id,
                'valor_contratado' => 1000,
                'data_contratacao' => now()->subYears(2)->toDateString(),
                'ativo'            => true,
            ]);
        }

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->has('nps_pendentes', 2)
            // Shape completo do primeiro item — contrato consumido pelo
            // NpsPendingWidget do Plan 72-03. Ordering por name ASC:
            // "Empresa Alpha" vem antes de "Empresa Beta".
            ->has('nps_pendentes.0.company_id')
            ->has('nps_pendentes.0.name')
            ->has('nps_pendentes.0.template_id')
            ->has('nps_pendentes.0.template_nome')
            ->has('nps_pendentes.0.month_reference')
            ->has('nps_pendentes.0.dias_atraso')
            ->where('nps_pendentes.0.name', 'Empresa Alpha')
            ->where('nps_pendentes.1.name', 'Empresa Beta')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — userDashboard filtra por carteira (lider consultor s/ core.dashboard)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_user_dashboard_filtra_carteira(): void
    {
        // userDashboard() so e alcancado quando:
        //   NOT admin AND (isLider AND NOT core.dashboard) AND
        //   NOT (!isLider AND (isConsultor OR isMentor))
        // Traducao pratica: consultor + lider de setor slug != 'performance'
        // (senao ganha AUTO_LIDERANCA_PERFORMANCE.core.dashboard e vai pra
        // adminDashboard). Este e o path que o Plan 72-02 T1 explicitamente
        // instrumentou (linha 1025 do controller).
        Carbon::setTestNow('2026-07-28 09:00:00');

        // Cria setor 'polos' (nao Performance) — user lider dele NAO ganha
        // core.dashboard automaticamente.
        $setor = Setor::create([
            'nome'      => 'Polos',
            'slug'      => 'polos',
            'active'    => true,
            'is_system' => false,
        ]);

        $consultor = User::factory()->create(['role' => 'consultor']);

        // Attach ao pivot setor_lideres — isso torna isLider() = true sem
        // conceder core.dashboard (que so vem via AUTO_LIDERANCA_PERFORMANCE).
        DB::table('setor_lideres')->insert([
            'setor_id'    => $setor->id,
            'user_id'     => $consultor->id,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Sanity check pre-flight: user esta no path certo (lider sem
        // core.dashboard) — evita falso positivo se o routing mudar.
        $this->assertTrue($consultor->fresh()->isLider(),
            'setup: consultor deveria ser lider apos attach em setor_lideres');
        $this->assertFalse($consultor->fresh()->hasPermission('core.dashboard'),
            'setup: consultor NAO deveria ter core.dashboard (setor slug != performance)');

        // Setup empresas: 2 na carteira (pivot company_users) + 1 fora.
        $c1 = Company::factory()->create(['name' => 'Carteira A']);
        $c2 = Company::factory()->create(['name' => 'Carteira B']);
        Company::factory()->create(['name' => 'Fora da carteira']);

        $consultor->companies()->attach($c1->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);
        $consultor->companies()->attach($c2->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);

        $response = $this->actingAs($consultor)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/User')
            // Apenas 2 pendentes — empresa "Fora da carteira" filtrada pelo
            // NpsPendingService::forCarteira (pivot company_users).
            ->has('nps_pendentes', 2)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — PortfolioController::show injeta nps_pendentes
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_portfolio_show_injeta_pendentes(): void
    {
        // Fluxo: admin visualiza carteira de um consultor. PortfolioController
        // ::renderPortfolio (linha 972) chama NpsPendingService::forCarteira
        // para o USER dono do portfolio (nao o admin logado). Widget do Plan
        // 72-03 renderiza no Portfolio/Show.
        Carbon::setTestNow('2026-07-28 09:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $ownerConsultor = User::factory()->create(['role' => 'consultor']);

        // 2 empresas na carteira do consultor dono do portfolio.
        $c1 = Company::factory()->create(['name' => 'Portfolio Empresa 1']);
        $c2 = Company::factory()->create(['name' => 'Portfolio Empresa 2']);

        $ownerConsultor->companies()->attach($c1->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);
        $ownerConsultor->companies()->attach($c2->id, [
            'role'        => 'consultor',
            'assigned_at' => now()->toDateString(),
        ]);

        // Ajuste 2026-07-09 — auto-view do owner preserva Portfolio/Show
        // (renderPortfolio + injeção de nps_pendentes). Admin abrindo OUTRO
        // user vai para Portfolio/AdminCarteira (view enxuta). Aqui testamos
        // que o widget de pendências NPS aparece no shape do renderPortfolio.
        $response = $this->actingAs($ownerConsultor)->get(
            route('portfolio.show', $ownerConsultor)
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Portfolio/Show')
            // Prop presente + preenchida com 2 empresas da carteira do owner
            ->has('nps_pendentes', 2)
            ->has('nps_pendentes.0.company_id')
            ->has('nps_pendentes.0.name')
            ->has('nps_pendentes.0.template_id')
            ->has('nps_pendentes.0.dias_atraso')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — CompanyController::show usa NpsScoreCalculator em surveys v15
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_company_show_usa_calculator_para_survey_v15(): void
    {
        // Dual-path do Plan 72-02 T2: surveys v15 (template_id != null) tem
        // score_empresa RECALCULADO a partir de nps_response_answers via
        // NpsScoreCalculator. Surveys legacy (template_id == null) mantem
        // os score_* legados persistidos em nps_responses.
        //
        // Setup v15: 1 template + 1 pergunta dimensao='empresa' + survey
        // completed + response com 1 answer peso=4. Esperado no payload:
        // nps_surveys.0.response.score_empresa === 4.0 (float, vem do AVG).
        $admin = User::factory()->create(['role' => 'admin']);

        // Template v15 com 1 pergunta na dimensao 'empresa'.
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template v15 Teste',
            'active' => true,
        ]);

        $question = NpsTemplateQuestion::create([
            'template_id' => $template->id,
            'texto'       => 'Como avalia a ECF?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_EMPRESA,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        $option = NpsTemplateOption::create([
            'question_id' => $question->id,
            'label'       => '4',
            'peso'        => 4,
            'ordem'       => 4,
        ]);

        $company = Company::factory()->create(['name' => 'Empresa Show v15']);

        $survey = NpsSurvey::create([
            'token'          => (string) Str::uuid(),
            'company_id'     => $company->id,
            'template_id'    => $template->id,
            'status'         => 'completed',
            'completed_at'   => now()->subDays(2),
            'expires_at'     => now()->addDays(30),
            'auto_generated' => true,
        ]);

        // NpsResponse SEM score_empresa preenchido (v15 grava NULL nas colunas
        // legacy — a fonte de verdade e nps_response_answers).
        $response = NpsResponse::create([
            'survey_id'          => $survey->id,
            'respondent_name'    => 'Cliente Exemplo',
            'score_estrategista' => null,
            'score_analista'     => null,
            'score_empresa'      => null,
            'comment'            => null,
        ]);

        // 1 answer com peso=4 dimensao='empresa' — o calculator deve
        // retornar AVG=4.0 (unica linha).
        NpsResponseAnswer::create([
            'response_id'                => $response->id,
            'template_question_id'       => $question->id,
            'template_option_id'         => $option->id,
            'question_texto_snapshot'    => $question->texto,
            'question_dimensao_snapshot' => 'empresa',
            'option_label_snapshot'      => '4',
            'option_peso_snapshot'       => 4,
        ]);

        $httpResponse = $this->actingAs($admin)->get(
            route('companies.show', $company)
        );

        $httpResponse->assertOk();
        $httpResponse->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Show')
            // Sanity check: 1 survey v15 no payload.
            ->has('company.nps_surveys', 1)
            // Path critico: score_empresa === 4 vem do NpsScoreCalculator
            // (AVG do option_peso_snapshot filtrado por dimensao=empresa).
            // Nota: PHP casta para (float), mas Inertia serializa 4.0 como
            // integer 4 na resposta JSON (json_encode drop de trailing zero).
            // Assertar como integer 4 pra bater com o payload real. Se este
            // valor fosse null, seria o path LEGACY lendo direto de
            // score_empresa (que esta NULL neste setup) — indicando que o
            // dual-path do Plan 72-02 T2 NAO esta funcionando.
            ->where('company.nps_surveys.0.response.score_empresa', 4)
            // score_analista e score_estrategista: sem answers dessas
            // dimensoes → calculator retorna null (semantica "nao tem
            // pergunta desta dimensao neste template", documentada no
            // NpsScoreCalculator §Comportamento 3).
            ->where('company.nps_surveys.0.response.score_analista', null)
            ->where('company.nps_surveys.0.response.score_estrategista', null)
        );
    }
}
