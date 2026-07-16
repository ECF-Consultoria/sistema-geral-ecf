<?php

namespace Tests\Feature\Phase94;

use App\Mail\NpsMonthlyMail;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\NpsDigisacEnvio;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use App\Services\Digisac\DigisacClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ContrataServicoNpsCoberto;
use Tests\TestCase;

/**
 * Phase 94 — trilha `nps_survey_events` (AB-94-3).
 *
 * Plano 94-02: eventos 'opened'/'expired'/'submitted' (NpsController) +
 * 'generated' no link manual.
 * Plano 94-03 (este): eventos do disparo mensal automático — 'generated'
 * (por survey criada), 'sent_email' (só sucesso do Mail::send) e
 * 'sent_digisac' (só envio confirmado) — e a linha do tempo E2E completa
 * (Success Criteria 4 da fase).
 */
class NpsSurveyEventsTest extends TestCase
{
    use RefreshDatabase;
    use ContrataServicoNpsCoberto;

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
     * Empresa elegível ao disparo mensal ESTRITO (DEC-79-A): aniversário no
     * dia de "hoje" (Carbon::setTestNow deve estar fixado ANTES da chamada) e
     * estrategista atribuído. `$templateIds`:
     *  - `null` (default): NÃO contrata nenhum serviço/scope — o teste monta
     *    a cobertura manualmente (ex.: cenário de 2 modelos com serviços
     *    isolados).
     *  - `[]`: usa o comportamento padrão do trait (serviço performance
     *    reaproveitado, coberto pelo "NPS Padrão" real).
     *  - `[id1, id2, ...]`: contrata/cobre um serviço por template informado.
     * Mesmo padrão de `NpsDispararMensalDigisacTest::criarEmpresaComEstrategista`.
     */
    private function criarEmpresaElegivelDisparoMensal(?array $templateIds = [], array $overrides = []): Company
    {
        $agora = Carbon::now('America/Sao_Paulo');

        $empresa = $this->criarEmpresa(array_merge([
            'email_cliente' => 'cliente@' . uniqid() . '.com',
        ], $overrides));

        $empresa->timestamps = false;
        $empresa->forceFill([
            'created_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
            'updated_at' => $agora->copy()->subYear()->setTime(10, 0, 0),
        ])->save();
        $empresa->timestamps = true;
        $empresa->refresh();

        $estrategista = User::factory()->create();
        $empresa->users()->attach($estrategista->id, [
            'role'        => 'estrategista',
            'assigned_at' => now(),
        ]);

        if ($templateIds === null) {
            // Nenhuma cobertura automática — teste monta manualmente.
        } elseif (empty($templateIds)) {
            $this->contratarServicoNpsCoberto($empresa);
        } else {
            foreach ($templateIds as $templateId) {
                $this->contratarServicoNpsCoberto($empresa, $templateId);
            }
        }

        return $empresa->fresh();
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

    // ═══ Plano 94-03 — eventos do disparo mensal automático (AB-94-3) ═══

    /**
     * Empresa elegível com 1 modelo aplicável e canal email ativo: 1 evento
     * 'generated' (metadata.origem=disparo_mensal, user_id/ip null) + 1
     * evento 'sent_email' com o survey_id correto.
     */
    public function test_disparo_mensal_emite_generated_e_sent_email_para_empresa_elegivel(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelDisparoMensal();

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $survey = NpsSurvey::where('company_id', $empresa->id)->firstOrFail();

        $eventoGenerated = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_GENERATED)
            ->first();
        $this->assertNotNull($eventoGenerated);
        $this->assertSame('disparo_mensal', $eventoGenerated->metadata['origem']);
        $this->assertNull($eventoGenerated->user_id);
        $this->assertNull($eventoGenerated->ip_address);

        $eventoEmail = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_SENT_EMAIL)
            ->first();
        $this->assertNotNull($eventoEmail);
        $this->assertSame($survey->id, $eventoEmail->survey_id);
    }

    /**
     * Empresa com 2 modelos aplicáveis (2 serviços cobertos DISTINTOS, cada
     * um coberto por 1 modelo próprio — servicos NOVOS, não o reaproveitado
     * pelo trait ContrataServicoNpsCoberto, para não colidir com o scope do
     * "NPS Padrão" real semeado por migration): 2 surveys criadas → 2
     * eventos 'generated', um por survey.
     */
    public function test_empresa_com_2_modelos_aplicaveis_emite_2_eventos_generated(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $templateA = NpsTemplate::factory()->create([
            'active'                  => true,
            'envio_automatico_mensal' => true,
        ]);
        $templateB = NpsTemplate::factory()->create([
            'active'                  => true,
            'envio_automatico_mensal' => true,
        ]);

        $empresa = $this->criarEmpresaElegivelDisparoMensal(null); // sem contrato/scope ainda

        // Dois serviços NOVOS (setor performance), 1 modelo cobrindo cada —
        // isolados do servico reaproveitado pelo trait (já scoped ao NPS
        // Padrão real via migration de seed retroativo).
        foreach ([$templateA, $templateB] as $template) {
            $servicoId = DB::table('servicos')->insertGetId([
                'nome'          => 'Servico Isolado ' . uniqid(),
                'valor_padrao'  => 0,
                'tipo_cobranca' => Servico::TIPO_MENSAL,
                'ativo'         => true,
                'setor'         => Servico::SETOR_PERFORMANCE,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            DB::table('contratos_servico')->insert([
                'company_id'       => $empresa->id,
                'servico_id'       => $servicoId,
                'valor_contratado' => 0,
                'data_contratacao' => now()->toDateString(),
                'ativo'            => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('nps_template_service_scopes')->updateOrInsert(
                ['template_id' => $template->id, 'servico_id' => $servicoId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }

        // Desativa o "NPS Padrão" real semeado por migration — sem contrato
        // novo nele, ele não entraria mesmo, mas garante isolamento total.
        NpsTemplate::where('is_default', true)->update(['envio_automatico_mensal' => false]);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 2);

        $totalGenerated = NpsSurveyEvent::where('event_type', NpsSurveyEvent::TYPE_GENERATED)->count();
        $this->assertSame(2, $totalGenerated);

        foreach (NpsSurvey::where('company_id', $empresa->id)->get() as $survey) {
            $this->assertSame(
                1,
                NpsSurveyEvent::where('survey_id', $survey->id)
                    ->where('event_type', NpsSurveyEvent::TYPE_GENERATED)
                    ->count()
            );
        }
    }

    /**
     * Mail::fake com falha forçada (Mailable lançando): NpsEmailEnvio
     * status=falha registrado (comportamento pré-existente) e ZERO evento
     * 'sent_email'.
     */
    public function test_falha_no_envio_de_email_nao_emite_evento_sent_email(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP indisponível'));

        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $this->criarEmpresaElegivelDisparoMensal();

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_email_envios', 1);
        $this->assertSame('falha', \App\Models\NpsEmailEnvio::first()->status);
        $this->assertSame(0, NpsSurveyEvent::where('event_type', NpsSurveyEvent::TYPE_SENT_EMAIL)->count());
    }

    /**
     * Canal Digisac com envio confirmado (status 'enviado'): 1 evento
     * 'sent_digisac' com metadata contendo nps_digisac_envio_id.
     */
    public function test_digisac_enviado_emite_evento_sent_digisac_com_envio_id_na_metadata(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_digisac_ativo', '1');
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelDisparoMensal(overrides: [
            'digisac_group_contact_id'     => 'contact-abc-123',
            'digisac_group_mapping_status' => 'mapped',
        ]);

        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn([
                    'provider_message_id' => 'msg-999',
                    'raw'                 => ['id' => 'msg-999', 'ok' => true],
                ]);
        });

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $survey = NpsSurvey::where('company_id', $empresa->id)->firstOrFail();
        $envioDigisac = NpsDigisacEnvio::where('survey_id', $survey->id)->firstOrFail();
        $this->assertSame(NpsDigisacEnvio::STATUS_ENVIADO, $envioDigisac->status);

        $eventoDigisac = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_SENT_DIGISAC)
            ->first();
        $this->assertNotNull($eventoDigisac);
        $this->assertSame($envioDigisac->id, $eventoDigisac->metadata['nps_digisac_envio_id']);
    }

    /**
     * Envio Digisac 'skipped' (empresa sem grupo mapeado): ZERO evento
     * 'sent_digisac'.
     */
    public function test_digisac_skipped_nao_emite_evento_sent_digisac(): void
    {
        Mail::fake();
        Configuracao::set('nps_envio_digisac_ativo', '1');
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $this->mock(DigisacClient::class, function ($mock) {
            $mock->shouldNotReceive('sendMessage');
        });

        $this->criarEmpresaElegivelDisparoMensal(); // sem digisac_group_contact_id

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $this->assertDatabaseCount('nps_digisac_envios', 1);
        $this->assertSame(NpsDigisacEnvio::STATUS_SKIPPED, NpsDigisacEnvio::first()->status);
        $this->assertSame(0, NpsSurveyEvent::where('event_type', NpsSurveyEvent::TYPE_SENT_DIGISAC)->count());
    }

    /**
     * Comando com --dry-run: ZERO eventos de qualquer tipo (o dry-run faz
     * `continue` ANTES do NpsSurvey::create, logo antes de qualquer emissor).
     */
    public function test_dry_run_nao_emite_nenhum_evento(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $this->criarEmpresaElegivelDisparoMensal();

        $this->artisan('nps:disparar-mensal', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('nps_surveys', 0);
        $this->assertSame(0, NpsSurveyEvent::count());
    }

    /**
     * Idempotência: 2ª rodada no mesmo mês pula as empresas (comportamento
     * pré-existente) e não cria eventos duplicados na 2ª rodada.
     */
    public function test_idempotencia_disparo_mensal_nao_duplica_eventos_na_segunda_rodada(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $this->criarEmpresaElegivelDisparoMensal();

        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $totalEventosAposPrimeiraRodada = NpsSurveyEvent::count();
        $this->assertGreaterThan(0, $totalEventosAposPrimeiraRodada);

        $this->artisan('nps:disparar-mensal')->assertSuccessful();
        $this->assertSame($totalEventosAposPrimeiraRodada, NpsSurveyEvent::count());
    }

    // ═══ Task 2 — linha do tempo completa E2E (Success Criteria 4 da fase) ═══

    /**
     * Fluxo E2E AUTOMÁTICO: nps:disparar-mensal (email) → GET no link →
     * POST v15 completo. Timeline exata: generated → sent_email → opened →
     * submitted.
     */
    public function test_timeline_e2e_fluxo_automatico_generated_sent_email_opened_submitted(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::create(2026, 7, 16, 9, 0, 0, 'America/Sao_Paulo'));

        $empresa = $this->criarEmpresaElegivelDisparoMensal();

        $this->artisan('nps:disparar-mensal')->assertSuccessful();

        $survey = NpsSurvey::where('company_id', $empresa->id)->firstOrFail();

        // Cliente abre o link (evento 'opened').
        $this->get("/nps/{$survey->token}")->assertOk();

        // Cliente responde (evento 'submitted') — monta answers para todas as
        // perguntas do template resolvido (seed "NPS Padrão": estrategista +
        // analista + empresa, escala 1..5).
        $answers = [];
        foreach (NpsTemplateQuestion::where('template_id', $survey->template_id)->get() as $pergunta) {
            $opcao = NpsTemplateOption::where('question_id', $pergunta->id)->where('peso', 4)->firstOrFail();
            $answers[(string) $pergunta->id] = $opcao->id;
        }

        $this->post("/nps/{$survey->token}", ['answers' => $answers])->assertOk();

        $survey->refresh();
        $this->assertSame('completed', $survey->status);

        $sequenciaEventos = $survey->events()->orderBy('id')->pluck('event_type')->all();

        $this->assertSame(
            [
                NpsSurveyEvent::TYPE_GENERATED,
                NpsSurveyEvent::TYPE_SENT_EMAIL,
                NpsSurveyEvent::TYPE_OPENED,
                NpsSurveyEvent::TYPE_SUBMITTED,
            ],
            $sequenciaEventos
        );
    }

    /**
     * Fluxo E2E MANUAL: generate() via HTTP admin → GET → GET de novo → POST.
     * Timeline: generated → opened → opened → submitted, com o 'generated'
     * carregando user_id do admin e metadata.origem='manual'.
     */
    public function test_timeline_e2e_fluxo_manual_generated_opened_opened_submitted(): void
    {
        $admin   = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();

        $this->actingAs($admin)
            ->post('/nps/generate', ['company_id' => $empresa->id])
            ->assertStatus(302);

        $survey = NpsSurvey::where('company_id', $empresa->id)->firstOrFail();

        // Primeira abertura.
        $this->get("/nps/{$survey->token}")->assertOk();
        // Segunda abertura (mesmo link, re-aberto).
        $this->get("/nps/{$survey->token}")->assertOk();

        // Responde — monta answers para todas as perguntas do template resolvido.
        $answers = [];
        foreach (NpsTemplateQuestion::where('template_id', $survey->template_id)->get() as $pergunta) {
            $opcao = NpsTemplateOption::where('question_id', $pergunta->id)->where('peso', 4)->firstOrFail();
            $answers[(string) $pergunta->id] = $opcao->id;
        }

        $this->post("/nps/{$survey->token}", ['answers' => $answers])->assertOk();

        $survey->refresh();
        $this->assertSame('completed', $survey->status);

        $eventos = $survey->events()->orderBy('id')->get();

        $this->assertSame(
            [
                NpsSurveyEvent::TYPE_GENERATED,
                NpsSurveyEvent::TYPE_OPENED,
                NpsSurveyEvent::TYPE_OPENED,
                NpsSurveyEvent::TYPE_SUBMITTED,
            ],
            $eventos->pluck('event_type')->all()
        );

        $eventoGerado = $eventos->firstWhere('event_type', NpsSurveyEvent::TYPE_GENERATED);
        $this->assertSame($admin->id, $eventoGerado->user_id);
        $this->assertSame('manual', $eventoGerado->metadata['origem']);
    }
}
