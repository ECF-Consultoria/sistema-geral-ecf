<?php

namespace Tests\Feature\Phase94;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
}
