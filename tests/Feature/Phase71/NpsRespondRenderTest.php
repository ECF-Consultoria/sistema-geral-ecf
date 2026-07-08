<?php

namespace Tests\Feature\Phase71;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 71 Plan 03 T1 — Suite Feature do GET /nps/{token} (respond).
 *
 * Cobre SC #1 e SC #4 do ROADMAP Phase 71 + REQs NPS-D-01/D-04:
 *   1. Survey v15.0 (template_id != null) injeta prop `template` com o shape
 *      completo consumido pelo `PreviewFormulario` do Plan 71-02 —
 *      {id, nome, descricao, perguntas: [{id, ordem, texto, tipo, dimensao,
 *      obrigatoria, options: [{id, ordem, label, peso}]}]}.
 *   2. Survey legacy (template_id null) recebe `template=null` + preserva
 *      props Phase 33 (`survey.estrategista_name`, `survey.textos`,
 *      `perguntas_extras`) — dual-path do RespondLegado.jsx (Plan 71-02).
 *   3. Survey status=completed renderiza `Nps/AlreadyCompleted`.
 *   4. Survey expirado (expires_at no passado + status=pending) renderiza
 *      `Nps/Expired` E atualiza `nps_surveys.status = 'expired'`.
 *   5. Token invalido -> 404 via `firstOrFail()`.
 *
 * As rotas /nps/{token} sao publicas guarded por token — SEM `actingAs`.
 *
 * Setup implicito via RefreshDatabase:
 *   - Migrations Phase 68 (100001 schema + 100003 nullable + 100005 dedup)
 *   - Seed "NPS Padrao" (migration 100004) — irrelevante aqui, criamos
 *     nossos proprios templates.
 *
 * Referencias:
 *   - .planning/phases/71-formul-rio-p-blico-din-mico/71-03-PLAN.md T1
 *   - .planning/phases/71-formul-rio-p-blico-din-mico/71-01-SUMMARY.md
 *   - app/Http/Controllers/NpsController.php linhas 307-426 (respond)
 */
class NpsRespondRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Garante FKs SQLite ativas — padrao Phase 68/69/70. Necessario porque
     * o survey referencia template_id via FK viva; se as FKs estivessem
     * off, colisoes silenciosas comprometeriam o realismo do teste.
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de setup
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Cria empresa minima para setup do survey.
     */
    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    /**
     * Cria template v15.0 com 1 pergunta escala + 5 opcoes (labels "1".."5",
     * pesos 1..5). Suficiente para assertar o shape do payload injetado.
     */
    private function fixtureTemplateEscala(): NpsTemplate
    {
        $t = NpsTemplate::factory()->create([
            'nome'      => 'Template Teste',
            'descricao' => 'Descricao teste v15',
            'active'    => true,
        ]);

        $q = NpsTemplateQuestion::create([
            'template_id' => $t->id,
            'texto'       => 'Como avalia o servico?',
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
            'dimensao'    => NpsTemplateQuestion::DIMENSAO_GERAL,
            'obrigatoria' => true,
            'ordem'       => 1,
        ]);

        for ($peso = 1; $peso <= 5; $peso++) {
            NpsTemplateOption::create([
                'question_id' => $q->id,
                'label'       => (string) $peso,
                'peso'        => $peso,
                'ordem'       => $peso,
            ]);
        }

        return $t->fresh(['questions.options']);
    }

    /**
     * Cria survey v15.0 (template_id preenchido) pending nao expirado.
     */
    private function criarSurveyV15(Company $company, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $company->id,
            'template_id'     => $template->id,
            'status'          => 'pending',
            'month_reference' => Carbon::now()->startOfMonth()->toDateString(),
            'expires_at'      => Carbon::now()->addDays(7),
            'auto_generated'  => true,
        ], $overrides));
    }

    /**
     * Cria survey legacy Phase 31/33 (template_id null) pending nao expirado.
     */
    private function criarSurveyLegacy(Company $company, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'          => Str::uuid()->toString(),
            'company_id'     => $company->id,
            'template_id'    => null,
            'status'         => 'pending',
            'expires_at'     => Carbon::now()->addDays(7),
            'auto_generated' => false,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 1 — v15 survey injeta template prop com shape completo
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_v15_survey_com_template_id_injeta_template_prop_com_shape_completo(): void
    {
        // Cenario canonico: survey aponta pra template com 1 pergunta escala
        // 5 options. Backend (Plan 71-01) monta $templatePayload e injeta em
        // Inertia::render('Nps/Respond', ['template' => ...]). Frontend
        // Plan 71-02 (Respond.jsx) consome este shape via
        // `template.perguntas[N].options[M].{id,label,peso}`.
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplateEscala();
        $survey   = $this->criarSurveyV15($empresa, $template);

        $response = $this->get(route('nps.respond', $survey->token));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/Respond')
            // Prop `template` presente com id do template criado
            ->has('template')
            ->has('template.id')
            ->where('template.nome', 'Template Teste')
            ->where('template.descricao', 'Descricao teste v15')
            // 1 pergunta escala com shape completo
            ->has('template.perguntas', 1)
            ->where('template.perguntas.0.texto', 'Como avalia o servico?')
            ->where('template.perguntas.0.tipo', 'escala')
            ->where('template.perguntas.0.dimensao', 'geral')
            ->where('template.perguntas.0.obrigatoria', true)
            // 5 options ordenadas com {id, label, peso}
            ->has('template.perguntas.0.options', 5)
            ->has('template.perguntas.0.options.0.id')
            ->where('template.perguntas.0.options.0.label', '1')
            ->where('template.perguntas.0.options.0.peso', 1)
            ->where('template.perguntas.0.options.4.label', '5')
            ->where('template.perguntas.0.options.4.peso', 5)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 2 — legacy survey sem template_id recebe template=null + props legacy
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_legacy_survey_sem_template_id_recebe_template_null_e_props_legacy(): void
    {
        // Path legacy: survey criado antes da Phase 68/69 nao tem template_id.
        // Backend (Plan 71-01) deve injetar `template=null` — Respond.jsx
        // (Plan 71-02) roteia para RespondLegado.jsx via early-return
        // `if (!template) return <RespondLegado .../>`. Props Phase 33 devem
        // continuar presentes para o form fixo antigo consumir.
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyLegacy($empresa);

        $response = $this->get(route('nps.respond', $survey->token));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/Respond')
            // template=null: Respond.jsx delega para RespondLegado.
            ->where('template', null)
            // Props legacy Phase 33 preservadas para o form fixo antigo.
            ->has('survey.estrategista_name')
            ->has('survey.analista_name')
            ->has('survey.tem_analista')
            ->has('survey.textos')
            ->has('perguntas_extras')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 3 — survey completed retorna Nps/AlreadyCompleted
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_completed_retorna_already_completed(): void
    {
        // Guard preservado da Phase 31 (NpsController::respond linhas 323-325):
        // se status=completed, renderiza AlreadyCompleted em vez do form. UX
        // impede re-resposta em survey ja fechado.
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplateEscala();
        $survey   = $this->criarSurveyV15($empresa, $template, [
            'status'       => 'completed',
            'completed_at' => now()->subMinutes(10),
        ]);

        $response = $this->get(route('nps.respond', $survey->token));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/AlreadyCompleted')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 4 — survey expirado renderiza Nps/Expired E atualiza status
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_survey_expired_renderiza_expired_e_atualiza_status(): void
    {
        // Guard preservado da Phase 31 (NpsController::respond linhas 327-330):
        // NpsSurvey::isExpired() = expires_at no passado E status=pending.
        // Ao GET, o controller atualiza status='expired' e renderiza
        // Nps/Expired. Idempotente — proxima GET nao dispara o UPDATE de novo
        // (isExpired ja retorna false com status!=pending).
        $empresa  = $this->criarEmpresa();
        $template = $this->fixtureTemplateEscala();
        $survey   = $this->criarSurveyV15($empresa, $template, [
            'expires_at' => Carbon::now()->subDays(1), // ja expirou
            'status'     => 'pending',
        ]);

        $response = $this->get(route('nps.respond', $survey->token));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Nps/Expired')
        );

        // Efeito colateral: status foi atualizado no banco (evita re-entrada
        // no branch expired em requests subsequentes; view historica passa a
        // exibir "expirado" em vez de "pendente").
        $this->assertDatabaseHas('nps_surveys', [
            'id'     => $survey->id,
            'status' => 'expired',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Test 5 — token invalido retorna 404
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_token_invalido_retorna_404(): void
    {
        // firstOrFail() no controller lanca ModelNotFoundException para tokens
        // desconhecidos; Laravel converte em 404. UX impede que link malicioso
        // vaze qualquer dado sobre a existencia (ou nao) do token.
        $response = $this->get(route('nps.respond', 'token-que-nao-existe-xxx'));

        $response->assertNotFound();
    }
}
