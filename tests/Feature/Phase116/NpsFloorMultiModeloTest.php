<?php

namespace Tests\Feature\Phase116;

use App\Models\Company;
use App\Models\NpsImputedAssignment;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Nps\NpsImputationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 116 Plan 03 (RED) — não respondido PARCIAL (multi-modelo, C5):
 * empresa com 2 serviços ativos, cada um com o SEU modelo NPS. Um modelo
 * respondido + o outro não respondido só puxa nota 1 do modelo que ficou sem
 * resposta — e só o RESPONSÁVEL daquele serviço/modelo recebe a linha
 * imputada (NPSFLOOR-06).
 *
 * Molde herdado de `tests/Feature/Phase116/NpsFloorAreaNpsTest.php` (mesmos
 * helpers de fixture) e `tests/Feature/V16/AtribuicaoConsolidadoNpsTest.php`
 * (cenário multi-serviço/multi-modelo).
 *
 * @see .planning/phases/116-.../116-03-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md (C5)
 */
class NpsFloorMultiModeloTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Helpers de fixture (mesmo padrão de NpsFloorAreaNpsTest)
    // ═══════════════════════════════════════════════════════════════════

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function imputationService(): NpsImputationService
    {
        return app(NpsImputationService::class);
    }

    private function criarTemplateEscopado(array $dimensoes, array $servicoIds): NpsTemplate
    {
        $template = NpsTemplate::factory()->create([
            'nome'   => 'Template Multi 116-03 ' . uniqid(),
            'active' => true,
        ]);

        $ordem = 1;
        foreach ($dimensoes as $dim) {
            $question = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => 'Pergunta ' . $dim . ' ' . uniqid() . '?',
                'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
                'dimensao'    => $dim,
                'obrigatoria' => true,
                'ordem'       => $ordem++,
            ]);

            for ($peso = 1; $peso <= 5; $peso++) {
                NpsTemplateOption::create([
                    'question_id' => $question->id,
                    'label'       => (string) $peso,
                    'peso'        => $peso,
                    'ordem'       => $peso,
                ]);
            }
        }

        foreach ($servicoIds as $sid) {
            $template->serviceScopes()->attach($sid);
        }

        return $template->fresh(['questions.options']);
    }

    private function payloadComPeso(NpsTemplate $template, int $peso): array
    {
        $answers = [];
        foreach ($template->questions as $q) {
            $answers[(string) $q->id] = $q->options->firstWhere('peso', $peso)->id;
        }

        return $answers;
    }

    private function responder(Company $empresa, NpsTemplate $template, int $peso): NpsResponse
    {
        $survey = NpsSurvey::create([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(30),
            'status'       => 'pending',
            'template_id'  => $template->id,
        ]);

        $this->post("/nps/{$survey->token}", [
            'respondent_name' => 'Cliente Multi 116-03',
            'answers'         => $this->payloadComPeso($template, $peso),
        ])->assertOk();

        return NpsResponse::where('survey_id', $survey->id)->firstOrFail();
    }

    private function criarSurveyNaoRespondido(Company $empresa, NpsTemplate $template, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'           => Str::uuid()->toString(),
            'company_id'      => $empresa->id,
            'generated_by'    => null,
            'expires_at'      => now()->endOfMonth(),
            'status'          => 'pending',
            'template_id'     => $template->id,
            'month_reference' => now()->copy()->startOfMonth()->toDateString(),
            'auto_generated'  => true,
        ], $overrides));
    }

    private function propsDoIndex(User $user, array $query = []): array
    {
        $props = null;
        $this->actingAs($user)
            ->get(route('nps.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Nps/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * Monta o cenário base: empresa com 2 serviços ativos (A e B), cada um
     * com seu próprio responsável (role 'consultor'/analista) e seu próprio
     * modelo NPS escopado (dimensão analista).
     *
     * @return array{empresa: Company, servicoA: int, servicoB: int, userA: User, userB: User, templateA: NpsTemplate, templateB: NpsTemplate}
     */
    private function montarCenarioDoisModelos(): array
    {
        $empresa  = Company::factory()->create(['active' => true]);
        $servicoA = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $servicoB = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($empresa->id, $servicoA, true);
        $this->criarContrato($empresa->id, $servicoB, true);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->inserirPivot($empresa->id, $userA->id, 'consultor', $servicoA);
        $this->inserirPivot($empresa->id, $userB->id, 'consultor', $servicoB);

        $templateA = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoA]);
        $templateB = $this->criarTemplateEscopado([NpsTemplateQuestion::DIMENSAO_ANALISTA], [$servicoB]);

        return compact('empresa', 'servicoA', 'servicoB', 'userA', 'userB', 'templateA', 'templateB');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1 — modelo A respondido (5) + modelo B não respondido (1) → média
    //     da dimensão analista = 3.0 (NPSFLOOR-06)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_modelo_nao_respondido_conta_1_e_modelo_respondido_mantem_nota_real(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $admin = $this->admin();

        $cenario = $this->montarCenarioDoisModelos();

        // Modelo A respondido nota 5.
        $this->responder($cenario['empresa'], $cenario['templateA'], 5);

        // Modelo B não respondido.
        $this->criarSurveyNaoRespondido($cenario['empresa'], $cenario['templateB'], ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        // 2026-08-14 — a nota 1 do não respondido só entra na média DEPOIS que
        // a coleta do mês encerra (régua do bônus, `NpsJanelaResolver`). O
        // cenário é montado dentro de julho; a tela é lida com julho fechado.
        Carbon::setTestNow(Carbon::parse('2026-08-01 00:00:01'));

        $props = $this->propsDoIndex($admin, ['mes' => '2026-07']);

        $this->assertEquals(3.0, $props['cards']['analista']['media']);
        $this->assertEquals(2, $props['cards']['analista']['total']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2 — SÓ o responsável do modelo NÃO respondido recebe a linha
    //     imputada (o do modelo respondido não recebe nota 1 nenhuma)
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function test_apenas_responsavel_do_modelo_nao_respondido_recebe_nota_1(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));

        $cenario = $this->montarCenarioDoisModelos();

        $this->responder($cenario['empresa'], $cenario['templateA'], 5);
        $this->criarSurveyNaoRespondido($cenario['empresa'], $cenario['templateB'], ['month_reference' => '2026-07-01']);
        $this->imputationService()->materializarLote(Carbon::parse('2026-07-01'));

        $this->assertSame(0, NpsImputedAssignment::where('user_id', $cenario['userA']->id)->count(),
            'o responsável do modelo RESPONDIDO não pode receber nota 1 imputada.');
        $this->assertSame(1, NpsImputedAssignment::where('user_id', $cenario['userB']->id)->count(),
            'só o responsável do modelo NÃO respondido recebe a linha imputada.');
    }
}
