<?php

namespace Tests\Feature\DemandasDev;

use App\Models\DevDemanda;
use App\Models\DevReuniao;
use App\Models\GoogleToken;
use App\Models\User;
use App\Services\DevDemandas\ReuniaoDevGoogleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reuniões dev pelo Google Agenda.
 *
 * O que estes testes prendem:
 *  - agendar cria o evento na agenda de QUEM AGENDA, com Meet (`conferenceDataVersion=1`),
 *    convite por e-mail (`sendUpdates=all`) e os participantes como convidados;
 *  - Google recusou → nada gravado; sem Google conectado → frase, nada gravado;
 *  - os anexos do evento viram os links, e link colado à mão nunca é sobrescrito;
 *  - editar/cancelar usam o token do ORGANIZADOR, mesmo quando outro admin clica.
 *
 * `Http::fake()` acumula stubs e o primeiro que casa vence — um fake por teste.
 */
class ReunioesDevGoogleTest extends TestCase
{
    use LiberaModulosDev;
    use RefreshDatabase;

    private const EVENTOS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->liberarModulosDev();
        Carbon::setTestNow('2026-09-22 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(bool $comGoogle = true): User
    {
        $u = User::factory()->create(['role' => 'admin', 'active' => true, 'email' => 'erlon' . uniqid() . '@ecfconsultoria.com.br']);
        if ($comGoogle) {
            GoogleToken::create(['user_id' => $u->id, 'access_token' => 'tk-' . $u->id, 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        }

        return $u;
    }

    private function dev(string $email): User
    {
        return User::factory()->create(['role' => 'consultor', 'active' => true, 'email' => $email]);
    }

    private function agendar(User $quem, array $extra = [])
    {
        return $this->actingAs($quem)->post('/dev/demandas/reunioes', $extra + [
            'convite' => true, 'titulo' => 'Alinhamento da Entrada', 'modulo' => 'Entrada', 'pauta' => 'Revisar layout',
            'data' => '2026-09-24', 'hora' => '14:00', 'duracao' => 45,
        ]);
    }

    public function test_agendar_cria_evento_com_meet_e_convida_os_participantes(): void
    {
        Http::fake([self::EVENTOS => Http::response(['id' => 'evt-1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])]);
        $admin = $this->admin();
        $maycon = $this->dev('maycon@ecfconsultoria.com.br');
        $barreto = $this->dev('barreto@ecfconsultoria.com.br');
        $d1 = DevDemanda::create(['codigo' => 'DEV-01', 'titulo' => 'Layout da Entrada', 'prioridade' => 1, 'data_entrada' => '2026-09-19']);
        $d2 = DevDemanda::create(['codigo' => 'DEV-06', 'titulo' => 'Filtros da Entrada', 'prioridade' => 2, 'data_entrada' => '2026-09-19']);

        $this->agendar($admin, ['participantes' => [$maycon->id, $barreto->id], 'demandas' => [$d1->id, $d2->id]])
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($r) {
            $corpo = $r->data();
            $convidados = collect($corpo['attendees'])->pluck('email')->sort()->values()->all();

            return $r->method() === 'POST'
                && str_contains($r->url(), 'sendUpdates=all')
                && str_contains($r->url(), 'conferenceDataVersion=1')
                && $r->header('Authorization')[0] === 'Bearer tk-' . User::where('role', 'admin')->first()->id
                && $corpo['summary'] === 'Alinhamento da Entrada'
                && str_starts_with($corpo['start']['dateTime'], '2026-09-24T14:00:00')
                && str_starts_with($corpo['end']['dateTime'], '2026-09-24T14:45:00')
                && $convidados === ['barreto@ecfconsultoria.com.br', 'maycon@ecfconsultoria.com.br']
                && str_contains($corpo['description'], 'DEV-01 — Layout da Entrada')
                && str_contains($corpo['description'], 'DEV-06 — Filtros da Entrada')
                && str_contains($corpo['description'], 'Módulo: Entrada')
                && isset($corpo['conferenceData']['createRequest']['requestId']);
        });

        $r = DevReuniao::sole();
        $this->assertSame('evt-1', $r->google_event_id);
        $this->assertSame($admin->id, $r->google_organizador_id);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $r->meet_link);
        $this->assertSame('2026-09-24 14:00', $r->inicio->format('Y-m-d H:i'));
        $this->assertSame('45 min', $r->duracao);
        $this->assertSame(2, $r->participantesUsuarios()->count());
        $this->assertSame(2, $r->demandas()->count());
        $this->assertSame(DevReuniao::STATUS_AGENDADA, $r->status());
    }

    public function test_sem_google_conectado_nada_e_gravado(): void
    {
        Http::fake();

        $this->agendar($this->admin(comGoogle: false))->assertSessionHas('error', ReuniaoDevGoogleService::SEM_GOOGLE);

        Http::assertNothingSent();
        $this->assertSame(0, DevReuniao::count());
    }

    public function test_google_recusou_nada_e_gravado_e_escopo_antigo_vira_frase(): void
    {
        Http::fake([self::EVENTOS => Http::response(['error' => ['message' => 'insufficientPermissions']], 403)]);

        $this->agendar($this->admin())->assertSessionHas('error', ReuniaoDevGoogleService::RECONECTAR);

        $this->assertSame(0, DevReuniao::count());
    }

    public function test_convite_exige_horario(): void
    {
        Http::fake();

        $this->agendar($this->admin(), ['hora' => ''])->assertSessionHasErrors('hora');

        Http::assertNothingSent();
    }

    public function test_anexos_do_evento_viram_links_sem_sobrescrever_o_colado_a_mao(): void
    {
        $admin = $this->admin();
        $r = DevReuniao::create([
            'data' => '2026-09-21', 'inicio' => '2026-09-21 14:00', 'fim' => '2026-09-21 15:00', 'titulo' => 'Revisão',
            'google_event_id' => 'evt-9', 'google_organizador_id' => $admin->id,
            'link_transcricao' => 'https://docs.google.com/colado-a-mao',
        ]);
        Http::fake([self::EVENTOS => Http::response(['id' => 'evt-9', 'attachments' => [
            ['fileUrl' => 'https://drive.google.com/gravacao', 'title' => 'Revisão - 2026/09/21 14:00 GMT-03:00 - Gravação', 'mimeType' => 'video/mp4'],
            ['fileUrl' => 'https://docs.google.com/transcricao', 'title' => 'Revisão - Transcrição', 'mimeType' => 'application/vnd.google-apps.document'],
            ['fileUrl' => 'https://docs.google.com/gemini', 'title' => 'Anotações do Gemini', 'mimeType' => 'application/vnd.google-apps.document'],
        ]])]);

        $this->actingAs($admin)->post("/dev/demandas/reunioes/{$r->id}/buscar-gravacao")->assertSessionHas('success');

        $r->refresh();
        $this->assertSame('https://drive.google.com/gravacao', $r->link_gravacao);
        $this->assertSame('https://docs.google.com/colado-a-mao', $r->link_transcricao);
        $this->assertSame('https://docs.google.com/gemini', $r->link_resumo);
        $this->assertNotNull($r->anexos_buscados_em);
    }

    public function test_sem_anexos_ainda_avisa_sem_erro(): void
    {
        $admin = $this->admin();
        $r = DevReuniao::create(['data' => '2026-09-21', 'titulo' => 'x', 'google_event_id' => 'evt-9', 'google_organizador_id' => $admin->id]);
        Http::fake([self::EVENTOS => Http::response(['id' => 'evt-9'])]);

        $this->actingAs($admin)->post("/dev/demandas/reunioes/{$r->id}/buscar-gravacao")->assertSessionHas('aviso');
    }

    public function test_classifica_anexos_em_ingles_tambem(): void
    {
        $achados = ReuniaoDevGoogleService::classificarAnexos([
            ['fileUrl' => 'g', 'title' => 'Meeting - Recording', 'mimeType' => 'video/mp4'],
            ['fileUrl' => 't', 'title' => 'Meeting - Transcript'],
            ['fileUrl' => 'n', 'title' => 'Notes by Gemini'],
            ['fileUrl' => 'x', 'title' => 'Apresentação.pdf', 'mimeType' => 'application/pdf'],
        ]);

        $this->assertSame(['link_gravacao' => 'g', 'link_transcricao' => 't', 'link_resumo' => 'n'], $achados);
    }

    public function test_editar_usa_o_token_do_organizador_e_preserva_quem_ja_aceitou(): void
    {
        $organizador = $this->admin();
        $outroAdmin = $this->admin();
        $maycon = $this->dev('maycon@ecfconsultoria.com.br');
        $barreto = $this->dev('barreto@ecfconsultoria.com.br');
        $r = DevReuniao::create([
            'data' => '2026-09-24', 'inicio' => '2026-09-24 14:00', 'fim' => '2026-09-24 15:00', 'titulo' => 'Alinhamento',
            'google_event_id' => 'evt-5', 'google_organizador_id' => $organizador->id,
        ]);
        $r->participantesUsuarios()->sync([$maycon->id]);

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response(['id' => 'evt-5', 'description' => 'antiga', 'attendees' => [
                    ['email' => 'maycon@ecfconsultoria.com.br', 'responseStatus' => 'accepted'],
                ]])
                : Http::response(['id' => 'evt-5']);
        });

        $this->actingAs($outroAdmin)->put("/dev/demandas/reunioes/{$r->id}", [
            'titulo' => 'Alinhamento', 'data' => '2026-09-25', 'hora' => '10:30', 'duracao' => 60,
            'participantes' => [$maycon->id, $barreto->id],
        ])->assertSessionHas('success');

        Http::assertSent(function ($req) use ($organizador) {
            if ($req->method() !== 'PATCH') {
                return false;
            }
            $convidados = collect($req->data()['attendees'])->keyBy('email');

            return $req->header('Authorization')[0] === 'Bearer tk-' . $organizador->id
                && str_starts_with($req->data()['start']['dateTime'], '2026-09-25T10:30:00')
                && $convidados['maycon@ecfconsultoria.com.br']['responseStatus'] === 'accepted'
                && isset($convidados['barreto@ecfconsultoria.com.br']);
        });
        $this->assertSame('2026-09-25 10:30', $r->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_cancelar_avisa_no_google_e_marca_cancelada(): void
    {
        $admin = $this->admin();
        $r = DevReuniao::create(['data' => '2026-09-24', 'inicio' => '2026-09-24 14:00', 'fim' => '2026-09-24 15:00', 'titulo' => 'x', 'google_event_id' => 'evt-7', 'google_organizador_id' => $admin->id]);
        Http::fake([self::EVENTOS => Http::response([], 204)]);

        $this->actingAs($admin)->post("/dev/demandas/reunioes/{$r->id}/cancelar")->assertSessionHas('success');

        Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), 'evt-7') && str_contains($req->url(), 'sendUpdates=all'));
        $this->assertSame(DevReuniao::STATUS_CANCELADA, $r->fresh()->status());
    }

    public function test_convidado_sem_demanda_entra_e_ve_a_reuniao(): void
    {
        $dev = $this->dev('novo@ecfconsultoria.com.br');
        $r = DevReuniao::create(['data' => '2026-09-24', 'titulo' => 'Onboarding do dev novo']);
        $r->participantesUsuarios()->sync([$dev->id]);

        $this->actingAs($dev)->get('/dev/demandas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('reunioes', 1)
            ->where('reunioes.0.titulo', 'Onboarding do dev novo'));
    }

    public function test_comando_busca_so_reunioes_encerradas_com_convite_e_links_faltando(): void
    {
        $admin = $this->admin();
        $alvo = DevReuniao::create(['data' => '2026-09-21', 'inicio' => '2026-09-21 14:00', 'fim' => '2026-09-21 15:00', 'titulo' => 'alvo', 'google_event_id' => 'evt-a', 'google_organizador_id' => $admin->id]);
        DevReuniao::create(['data' => '2026-09-25', 'inicio' => '2026-09-25 14:00', 'fim' => '2026-09-25 15:00', 'titulo' => 'futura', 'google_event_id' => 'evt-f', 'google_organizador_id' => $admin->id]);
        DevReuniao::create(['data' => '2026-09-21', 'inicio' => '2026-09-21 09:00', 'fim' => '2026-09-21 10:00', 'titulo' => 'sem convite']);
        DevReuniao::create(['data' => '2026-09-10', 'inicio' => '2026-09-10 09:00', 'fim' => '2026-09-10 10:00', 'titulo' => 'velha', 'google_event_id' => 'evt-v', 'google_organizador_id' => $admin->id]);
        Http::fake([self::EVENTOS => Http::response(['id' => 'evt-a', 'attachments' => [
            ['fileUrl' => 'https://drive.google.com/g', 'title' => 'Gravação', 'mimeType' => 'video/mp4'],
        ]])]);

        $this->artisan('demandas-dev:buscar-gravacoes')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'evt-a'));
        $this->assertSame('https://drive.google.com/g', $alvo->fresh()->link_gravacao);
    }
}
