<?php

namespace Tests\Feature\Phase96;

use App\Models\Company;
use App\Models\NpsResponse;
use App\Models\NpsSurvey;
use App\Models\NpsSurveyEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 96 Plan 01 (AB-96-1) — endurecimento da Regra 4 da Fase 94: um POST
 * /nps/{token} em sessão autenticada de usuário interno passa a ser
 * BLOQUEADO (nenhuma NpsResponse é criada), com evento `blocked` auditado.
 *
 * A ABERTURA (GET) continua permitida e inalterada — só o SUBMIT é afetado.
 */
class NpsBloqueioSessaoInternaTest extends TestCase
{
    use RefreshDatabase;

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

    // ═══ Task 1 — 7º event_type `blocked` no enum + constante do model ═══

    /**
     * Constante TYPE_BLOCKED existe e está presente em TYPES.
     */
    public function test_constante_type_blocked_existe_e_esta_em_types(): void
    {
        $this->assertSame('blocked', NpsSurveyEvent::TYPE_BLOCKED);
        $this->assertContains(NpsSurveyEvent::TYPE_BLOCKED, NpsSurveyEvent::TYPES);
    }

    /**
     * event_type='blocked' persiste no SQLite dos testes sem violar o CHECK
     * constraint (Pitfall 3 do 96-RESEARCH — armadilha já documentada no
     * projeto para ALTER de enum).
     */
    public function test_evento_blocked_persiste_no_sqlite_sem_violar_check(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $evento = NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_BLOCKED,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'PHPUnit',
            'user_id'    => null,
            'metadata'   => null,
        ]);

        $this->assertDatabaseHas('nps_survey_events', [
            'id'         => $evento->id,
            'event_type' => 'blocked',
        ]);
    }

    // ═══ Task 2 — interceptação em submitResponse() + Nps/Blocked.jsx ═══

    /**
     * Usuário interno autenticado faz POST /nps/{token}: resposta Inertia
     * renderiza 'Nps/Blocked', NENHUMA NpsResponse é criada e existe 1
     * evento 'blocked' com o user_id da sessão.
     */
    public function test_submit_de_usuario_interno_logado_e_bloqueado_e_auditado(): void
    {
        $interno = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->actingAs($interno)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
            ->post("/nps/{$survey->token}", [
                'score_estrategista' => 5,
                'score_empresa'      => 5,
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Nps/Blocked'));

        $this->assertSame(0, NpsResponse::where('survey_id', $survey->id)->count());

        $evento = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_BLOCKED)
            ->first();

        $this->assertNotNull($evento);
        $this->assertSame($interno->id, $evento->user_id);
        $this->assertSame('198.51.100.30', $evento->ip_address);
    }

    /**
     * Submit ANÔNIMO (sem sessão autenticada) continua criando a
     * NpsResponse normalmente — o fluxo público não regride.
     */
    public function test_submit_anonimo_continua_criando_a_resposta_normalmente(): void
    {
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->post("/nps/{$survey->token}", [
            'score_estrategista' => 5,
            'score_empresa'      => 5,
        ])->assertOk();

        $this->assertSame(1, NpsResponse::where('survey_id', $survey->id)->count());
        $this->assertSame(
            0,
            NpsSurveyEvent::where('survey_id', $survey->id)->where('event_type', NpsSurveyEvent::TYPE_BLOCKED)->count()
        );
    }

    /**
     * GET /nps/{token} logado como interno continua 200 e emitindo 'opened'
     * — regressão do comportamento da Fase 94 (a ABERTURA nunca foi tocada).
     */
    public function test_get_logado_como_interno_continua_permitido_e_emite_opened(): void
    {
        $interno = User::factory()->create(['role' => 'admin']);
        $empresa = $this->criarEmpresa();
        $survey  = $this->criarSurveyPendente($empresa);

        $this->actingAs($interno)
            ->get("/nps/{$survey->token}")
            ->assertOk();

        $evento = NpsSurveyEvent::where('survey_id', $survey->id)
            ->where('event_type', NpsSurveyEvent::TYPE_OPENED)
            ->first();

        $this->assertNotNull($evento);
        $this->assertSame($interno->id, $evento->user_id);
    }
}
