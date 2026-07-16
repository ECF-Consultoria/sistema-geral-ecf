<?php

namespace Tests\Feature\Phase94;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 94 Plan 02 — trilha `nps_survey_events` (AB-94-3).
 *
 * Task 1: eventos 'opened'/'expired' emitidos pelo GET /nps/{token}.
 * Task 2 (estendido depois): eventos 'submitted' + dedup 23000.
 * Task 3 (estendido depois): evento 'generated' no link manual.
 */
class NpsSurveyEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarEmpresa(array $overrides = []): Company
    {
        return Company::create(array_merge([
            'name'   => 'Empresa Teste ' . uniqid(),
            'cnpj'   => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active' => true,
        ], $overrides));
    }

    private function criarSurveyPendente(Company $empresa, array $overrides = []): NpsSurvey
    {
        return NpsSurvey::create(array_merge([
            'token'        => Str::uuid()->toString(),
            'company_id'   => $empresa->id,
            'generated_by' => null,
            'expires_at'   => now()->addDays(7),
            'status'       => 'pending',
        ], $overrides));
    }

    /**
     * Primeira abertura emite evento 'opened' com metadata first_open=true
     * e user_id nulo (visitante anônimo).
     */
    public function test_primeira_abertura_emite_evento_opened_com_first_open_true(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $this->assertSame(1, NpsSurveyEvent::where('survey_id', $survey->id)->count());

        $evento = NpsSurveyEvent::where('survey_id', $survey->id)->first();
        $this->assertSame(NpsSurveyEvent::TYPE_OPENED, $evento->event_type);
        $this->assertSame('203.0.113.55', $evento->ip_address);
        $this->assertNull($evento->user_id);
        $this->assertTrue($evento->metadata['first_open']);
    }

    /**
     * Segunda abertura emite um SEGUNDO evento 'opened' com
     * metadata first_open=false — o primeiro evento permanece intacto.
     */
    public function test_segunda_abertura_emite_novo_evento_opened_com_first_open_false(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        Carbon::setTestNow('2026-07-16 10:00:00');
        $this->get("/nps/{$survey->token}")->assertOk();

        Carbon::setTestNow('2026-07-16 10:05:00');
        $this->get("/nps/{$survey->token}")->assertOk();

        $eventos = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_OPENED)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $eventos);
        $this->assertTrue($eventos[0]->metadata['first_open']);
        $this->assertFalse($eventos[1]->metadata['first_open']);
    }

    /**
     * GET autenticado (sessão admin coexistente) carrega user_id no evento
     * 'opened' — insumo para a Fase 95/96 (Regra 4 de suspeita).
     */
    public function test_abertura_autenticada_carrega_user_id_no_evento(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->actingAs($admin)
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $evento = NpsSurveyEvent::where('survey_id', $survey->id)->first();
        $this->assertSame($admin->id, $evento->user_id);
    }

    /**
     * GET em survey vencida: rastro grava, status vira 'expired' e o
     * evento 'expired' é emitido NA MESMA passada do 'opened' — total
     * 1 opened + 1 expired.
     */
    public function test_abertura_em_survey_expirada_emite_opened_e_expired(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa, [
            'expires_at' => now()->subDay(),
        ]);

        $this->assertSame(0, NpsSurveyEvent::where('survey_id', $survey->id)->count());

        $this->get("/nps/{$survey->token}")->assertOk();

        $survey->refresh();
        $this->assertSame('expired', $survey->status);

        $this->assertSame(
            1,
            NpsSurveyEvent::where('survey_id', $survey->id)->where('event_type', NpsSurveyEvent::TYPE_OPENED)->count()
        );
        $this->assertSame(
            1,
            NpsSurveyEvent::where('survey_id', $survey->id)->where('event_type', NpsSurveyEvent::TYPE_EXPIRED)->count()
        );
    }

    /**
     * POST bem-sucedido nos DOIS paths (v15 e legado) emite evento
     * 'submitted' com metadata.response_id — prova do helper compartilhado.
     */
    public function test_submit_bem_sucedido_emite_evento_submitted_nos_dois_paths(): void
    {
        // ─── Path legado ─────────────────────────────────────────────────
        $empresaLegado = $this->criarEmpresa();
        $surveyLegado  = $this->criarSurveyPendente($empresaLegado);

        $this->post("/nps/{$surveyLegado->token}", [
            'score_estrategista' => 5,
            'score_empresa'      => 5,
        ])->assertOk();

        $respostaLegado = NpsResponse::where('survey_id', $surveyLegado->id)->firstOrFail();
        $eventoLegado   = NpsSurveyEvent::where('survey_id', $surveyLegado->id)
            ->where('event_type', NpsSurveyEvent::TYPE_SUBMITTED)
            ->first();

        $this->assertNotNull($eventoLegado);
        $this->assertSame($respostaLegado->id, $eventoLegado->metadata['response_id']);

        // ─── Path v15 ────────────────────────────────────────────────────
        $empresaV15 = $this->criarEmpresa();
        $template   = NpsTemplate::factory()->create(['active' => true]);
        $pergunta   = NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
        ]);
        $opcao = NpsTemplateOption::factory()->create([
            'question_id' => $pergunta->id,
            'label'       => '4',
            'peso'        => 4,
        ]);
        $surveyV15 = $this->criarSurveyPendente($empresaV15, [
            'template_id' => $template->id,
            'expires_at'  => now()->addDays(30),
        ]);

        $this->post("/nps/{$surveyV15->token}", [
            'answers' => [(string) $pergunta->id => $opcao->id],
        ])->assertOk();

        $respostaV15 = NpsResponse::where('survey_id', $surveyV15->id)->firstOrFail();
        $eventoV15   = NpsSurveyEvent::where('survey_id', $surveyV15->id)
            ->where('event_type', NpsSurveyEvent::TYPE_SUBMITTED)
            ->first();

        $this->assertNotNull($eventoV15);
        $this->assertSame($respostaV15->id, $eventoV15->metadata['response_id']);
    }

    /**
     * Dedup 23000 (2a completação da mesma company/month_reference/template):
     * a transação inteira reverte — nenhuma NpsResponse órfã e NENHUM evento
     * 'submitted' extra fica gravado para a tentativa que falhou.
     */
    public function test_dedup_23000_reverte_transacao_sem_evento_submitted_orfao(): void
    {
        $empresa  = $this->criarEmpresa();
        $template = NpsTemplate::factory()->create(['active' => true]);
        $pergunta = NpsTemplateQuestion::factory()->create([
            'template_id' => $template->id,
            'tipo'        => NpsTemplateQuestion::TIPO_ESCALA,
        ]);
        $opcao = NpsTemplateOption::factory()->create([
            'question_id' => $pergunta->id,
            'label'       => '4',
            'peso'        => 4,
        ]);

        $mesReferencia = now()->startOfMonth()->toDateString();

        // 1a survey — completada com sucesso.
        $survey1 = $this->criarSurveyPendente($empresa, [
            'template_id'     => $template->id,
            'month_reference' => $mesReferencia,
            'expires_at'      => now()->addDays(30),
        ]);

        $this->post("/nps/{$survey1->token}", [
            'answers' => [(string) $pergunta->id => $opcao->id],
        ])->assertOk();

        $totalSubmittedAntes = NpsSurveyEvent::where('event_type', NpsSurveyEvent::TYPE_SUBMITTED)->count();
        $this->assertSame(1, $totalSubmittedAntes);

        // 2a survey — MESMA (company, month_reference, template) ainda pending.
        $survey2 = $this->criarSurveyPendente($empresa, [
            'template_id'     => $template->id,
            'month_reference' => $mesReferencia,
            'expires_at'      => now()->addDays(30),
        ]);

        $this->post("/nps/{$survey2->token}", [
            'answers' => [(string) $pergunta->id => $opcao->id],
        ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Nps/AlreadyCompleted'));

        // Transação reverteu — nenhuma NpsResponse órfã para a 2a survey.
        $this->assertSame(0, NpsResponse::where('survey_id', $survey2->id)->count());

        // Nenhum evento 'submitted' extra — total continua 1 (só o da 1a survey).
        $this->assertSame(
            1,
            NpsSurveyEvent::where('event_type', NpsSurveyEvent::TYPE_SUBMITTED)->count()
        );
    }

    /**
     * POST /nps/generate (admin autenticado) emite evento 'generated' com
     * survey_id do survey novo, user_id do admin, ip do request e
     * metadata.origem='manual'.
     */
    public function test_generate_manual_emite_evento_generated_com_metadata_origem_manual(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/nps/generate', ['company_id' => $empresa->id])
            ->assertStatus(302);

        $survey = NpsSurvey::where('company_id', $empresa->id)->firstOrFail();

        $evento = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_GENERATED)
            ->first();

        $this->assertNotNull($evento);
        $this->assertSame($admin->id, $evento->user_id);
        $this->assertSame('198.51.100.20', $evento->ip_address);
        $this->assertSame('manual', $evento->metadata['origem']);
    }

    /**
     * Geração que falha ANTES do NpsSurvey::create (empresa sem contrato
     * ativo no serviço coberto pelo modelo) NÃO emite evento 'generated'.
     */
    public function test_generate_que_falha_antes_do_create_nao_emite_evento(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $empresa  = $this->criarEmpresa();
        $servico  = Servico::create([
            'nome'          => 'Servico Sem Contrato ' . uniqid(),
            'valor_padrao'  => 1000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);
        $template = NpsTemplate::factory()->create(['active' => true]);
        $template->servicos()->attach($servico->id);

        $totalEventosAntes = NpsSurveyEvent::count();

        $this->actingAs($admin)
            ->post('/nps/generate', [
                'company_id'  => $empresa->id,
                'template_id' => $template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, NpsSurvey::where('company_id', $empresa->id)->count());
        $this->assertSame($totalEventosAntes, NpsSurveyEvent::count());
    }
}
