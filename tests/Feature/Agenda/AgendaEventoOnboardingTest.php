<?php

namespace Tests\Feature\Agenda;

use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use App\Services\Onboarding\AgendaGoogleService;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Os eventos que o "Agendar" cria para um onboarding (16/09/2026).
 *
 * ### O que estes testes protegem
 * 1. **Que o convite chegue a quem deve, pela agenda de quem conduz** — o
 *    analista ou o estrategista, e ninguém mais.
 * 2. **Que o Meet seja pedido do jeito que o Google lê** (`conferenceDataVersion=1`);
 *    sem o parâmetro, o pedido é ignorado em silêncio e o evento nasce sem sala.
 * 3. **Que a reunião de onboarding continue sendo uma só**, com a data que o
 *    cliente vê no portal — e que a data valha mesmo se o convite falhar.
 * 4. **Que editar não apague o "aceito"** de quem já respondeu.
 * 5. **Que o retrato acompanhe o Google** quando o evento muda ou some por lá.
 */
class AgendaEventoOnboardingTest extends TestCase
{
    use CenarioAgenda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function servico(): AgendaGoogleService
    {
        return app(AgendaGoogleService::class);
    }

    // ─── Criar ──────────────────────────────────────────────────────────────

    public function test_mapeamento_com_meet_pede_a_sala_e_guarda_o_link(): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response([
                'id'          => 'evt_mapeamento',
                'hangoutLink' => 'https://meet.google.com/abc-defg-hij',
            ]),
        ]);
        [$onboarding, $analista, , $company] = $this->cenario();

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento(), $analista);

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);

        Http::assertSent(function (RequisicaoHttp $r) use ($company) {
            $corpo = $r->data();

            return $r->method() === 'POST'
                && str_contains($r->url(), 'sendUpdates=all')
                && str_contains($r->url(), 'conferenceDataVersion=1')
                && $r->hasHeader('Authorization', 'Bearer token-analista')
                && $corpo['conferenceData']['createRequest']['conferenceSolutionKey']['type'] === 'hangoutsMeet'
                && str_contains($corpo['description'], "[Cliente: {$company->name}]")
                && str_contains($corpo['description'], 'Levar o relatório da conta.')
                && $corpo['start']['timeZone'] === 'America/Sao_Paulo'
                && $corpo['start']['dateTime'] === '2026-09-22T10:00:00-03:00'
                && $corpo['end']['dateTime'] === '2026-09-22T10:45:00-03:00'
                && $corpo['attendees'] === [['email' => 'fulano@cliente.test', 'displayName' => 'Fulano Cliente']];
        });

        $evento = OnboardingEventoGoogle::sole();
        $this->assertSame('evt_mapeamento', $evento->google_event_id);
        $this->assertNull($evento->chave);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $evento->link_reuniao);
        $this->assertSame(OnboardingEventoGoogle::PLATAFORMA_MEET, $evento->plataforma);
        $this->assertSame('2026-09-22 10:00:00', $evento->inicio->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-22 10:45:00', $evento->fim->format('Y-m-d H:i:s'));
        $this->assertSame($analista->id, $evento->calendar_owner_user_id);
        $this->assertSame('cliente', $evento->participantes[0]['lado']);
    }

    public function test_dois_mapeamentos_convivem_no_mesmo_onboarding(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push(['id' => 'evt_1'])
            ->push(['id' => 'evt_2'])]);
        [$onboarding, $analista] = $this->cenario();

        $this->assertTrue($this->servico()->criar($onboarding, $this->dadosDoEvento(), $analista)['ok']);
        $this->assertTrue($this->servico()->criar($onboarding, $this->dadosDoEvento(), $analista)['ok']);

        $this->assertSame(['evt_1', 'evt_2'], OnboardingEventoGoogle::orderBy('id')->pluck('google_event_id')->all());
    }

    public function test_outro_evento_nao_leva_a_marca_de_cliente(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_outro'])]);
        [$onboarding, $analista, , $company] = $this->cenario();

        $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'tipo'       => OnboardingEventoGoogle::TIPO_OUTRO,
            'titulo'     => 'Alinhamento interno',
            'plataforma' => OnboardingEventoGoogle::PLATAFORMA_NENHUMA,
        ]), $analista);

        Http::assertSent(fn (RequisicaoHttp $r) => ! str_contains($r->data()['description'], '[Cliente:')
            && str_contains($r->data()['description'], "Empresa: {$company->name}")
            && ! str_contains($r->url(), 'conferenceDataVersion')
            && ! isset($r->data()['conferenceData']));
    }

    public function test_link_do_teams_vai_no_local_do_convite(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_teams'])]);
        [$onboarding, $analista] = $this->cenario();
        $teams = 'https://teams.microsoft.com/l/meetup-join/19%3ameeting';

        $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'plataforma' => OnboardingEventoGoogle::PLATAFORMA_LINK,
            'link'       => $teams,
        ]), $analista);

        Http::assertSent(fn (RequisicaoHttp $r) => $r->data()['location'] === $teams
            && str_contains($r->data()['description'], 'Link da reunião: '.$teams)
            && ! isset($r->data()['conferenceData']));

        $this->assertSame($teams, OnboardingEventoGoogle::sole()->link_reuniao);
    }

    public function test_estrategista_pode_organizar_pela_propria_agenda(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_estr'])]);
        [$onboarding, $analista, $estrategista] = $this->cenario(['google_estrategista' => true]);

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'organizador_id' => $estrategista->id,
            'participantes'  => [
                ['email' => 'fulano@cliente.test'],
                ['email' => $analista->email],
                // O próprio organizador na lista não vira convidado dele mesmo.
                ['email' => $estrategista->email],
            ],
        ]), $analista);

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);

        Http::assertSent(fn (RequisicaoHttp $r) => $r->hasHeader('Authorization', 'Bearer token-estrategista')
            && array_column($r->data()['attendees'], 'email') === ['fulano@cliente.test', mb_strtolower($analista->email)]);

        $this->assertSame($estrategista->id, OnboardingEventoGoogle::sole()->calendar_owner_user_id);
    }

    public function test_quem_nao_conduz_o_onboarding_nao_organiza(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario();
        $colega = User::factory()->create();
        $this->conectarGoogle($colega, 'token-colega');

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento(['organizador_id' => $colega->id]), $analista);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('analista ou do estrategista', $resultado['mensagem']);
        Http::assertNothingSent();
        $this->assertSame(0, OnboardingEventoGoogle::count());
    }

    public function test_organizador_sem_google_conectado_e_recusado_sem_chamar_a_api(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento(['organizador_id' => $estrategista->id]), $analista);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Estrategista da Conta ainda não conectou', $resultado['mensagem']);
        Http::assertNothingSent();
    }

    // ─── A reunião de onboarding ────────────────────────────────────────────

    public function test_reuniao_de_onboarding_pelo_agendar_marca_a_data_e_convida(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_kickoff'])]);
        [$onboarding, $analista] = $this->cenario();

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'tipo'   => OnboardingEventoGoogle::TIPO_KICKOFF,
            'titulo' => 'ECF · Cliente — Reunião de onboarding',
        ]), $analista);

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);
        $this->assertSame('2026-09-22 10:00', $onboarding->fresh()->reuniao_agendada_para->format('Y-m-d H:i'));
        $this->assertSame(OnboardingEventoGoogle::TIPO_KICKOFF, OnboardingEventoGoogle::sole()->chave);
    }

    public function test_reuniao_de_onboarding_somente_data_nao_chama_o_google(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario(['google_analista' => false]);

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'tipo'         => OnboardingEventoGoogle::TIPO_KICKOFF,
            'somente_data' => true,
        ]), $analista);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('2026-09-22 10:00', $onboarding->fresh()->reuniao_agendada_para->format('Y-m-d H:i'));
        Http::assertNothingSent();
        $this->assertSame(0, OnboardingEventoGoogle::count());
    }

    public function test_convite_que_falha_nao_desfaz_a_data_da_reuniao(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'x'], 500)]);
        [$onboarding, $analista] = $this->cenario();

        $resultado = $this->servico()->criar($onboarding, $this->dadosDoEvento([
            'tipo' => OnboardingEventoGoogle::TIPO_KICKOFF,
        ]), $analista);

        $this->assertFalse($resultado['ok']);
        $this->assertStringStartsWith('A data foi salva, mas o convite não saiu', $resultado['mensagem']);
        $this->assertNotNull($onboarding->fresh()->reuniao_agendada_para);
        $this->assertSame(0, OnboardingEventoGoogle::count());
    }

    public function test_marcar_a_reuniao_de_novo_atualiza_o_mesmo_convite(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_kickoff'])]);
        [$onboarding, $analista] = $this->cenario();
        $kickoff = ['tipo' => OnboardingEventoGoogle::TIPO_KICKOFF];

        $this->servico()->criar($onboarding, $this->dadosDoEvento($kickoff), $analista);
        $resultado = $this->servico()->criar($onboarding->fresh(), $this->dadosDoEvento($kickoff + [
            'inicio' => \Carbon\CarbonImmutable::parse('2026-09-23 15:00', 'America/Sao_Paulo'),
        ]), $analista);

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);
        Http::assertSentCount(3); // POST, GET (estado atual) e PATCH
        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH'
            && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events/evt_kickoff'));

        $this->assertSame(1, OnboardingEventoGoogle::count());
        $this->assertSame('2026-09-23 15:00', $onboarding->fresh()->reuniao_agendada_para->format('Y-m-d H:i'));
    }

    public function test_reuniao_cancelada_e_marcada_de_novo_vira_convite_novo_na_mesma_linha(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::sequence()
            ->push(['id' => 'evt_antigo'])
            ->push('', 204)
            ->push(['id' => 'evt_novo'])]);
        [$onboarding, $analista] = $this->cenario();
        $kickoff = ['tipo' => OnboardingEventoGoogle::TIPO_KICKOFF];

        $this->servico()->criar($onboarding, $this->dadosDoEvento($kickoff), $analista);
        $linha = OnboardingEventoGoogle::sole();
        $this->assertTrue($this->servico()->cancelar($linha, $analista)['ok']);
        $this->servico()->criar($onboarding->fresh(), $this->dadosDoEvento($kickoff), $analista);

        $linha->refresh();
        $this->assertSame('evt_novo', $linha->google_event_id);
        $this->assertTrue($linha->ativo());
        $this->assertSame(1, OnboardingEventoGoogle::count());
        Http::assertNotSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH');
    }

    public function test_convite_fixo_da_rotina_grava_o_retrato_com_a_regra(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_rotina'])]);
        [$onboarding, $analista] = $this->cenario();
        \App\Models\OnboardingAgenda::create([
            'onboarding_id' => $onboarding->id,
            'dia_semana'    => 3,
            'horario'       => '14:00',
            'periodicidade' => \App\Models\OnboardingAgenda::PERIODICIDADE_QUINZENAL,
            'definida_em'   => now(),
            'definida_por'  => $analista->id,
        ]);

        $this->assertTrue($this->servico()->enviar($onboarding->fresh(), OnboardingEventoGoogle::TIPO_RECORRENTE, $analista)['ok']);

        $linha = OnboardingEventoGoogle::sole();
        $this->assertSame(OnboardingEventoGoogle::TIPO_RECORRENTE, $linha->chave);
        $this->assertSame('RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE', $linha->recorrencia);
        $this->assertSame('14:00', $linha->inicio->format('H:i'));
        $this->assertSame(3, $linha->inicio->dayOfWeekIso);
        $this->assertSame('fulano@cliente.test', $linha->participantes[0]['email']);
    }

    public function test_convite_fixo_nao_mexe_em_reuniao_que_esta_na_agenda_do_estrategista(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario(['google_estrategista' => true]);
        app(OnboardingEngineService::class)->agendarReuniao($onboarding, now()->addDays(2), $analista);
        $this->vinculo($onboarding, $estrategista, [
            'tipo'  => OnboardingEventoGoogle::TIPO_KICKOFF,
            'chave' => OnboardingEventoGoogle::TIPO_KICKOFF,
        ]);

        $resultado = $this->servico()->enviar($onboarding->fresh(), OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('agenda de Estrategista da Conta', $resultado['mensagem']);
        Http::assertNothingSent();
        $this->assertSame($estrategista->id, OnboardingEventoGoogle::sole()->calendar_owner_user_id);
    }

    // ─── Editar e cancelar ──────────────────────────────────────────────────

    public function test_editar_mantem_a_resposta_de_quem_continua_convidado(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_edit']);

        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response([
            'id'        => 'evt_edit',
            'status'    => 'confirmed',
            'attendees' => [
                ['email' => $analista->email, 'organizer' => true, 'self' => true, 'responseStatus' => 'accepted'],
                ['email' => 'fulano@cliente.test', 'responseStatus' => 'accepted'],
                ['email' => 'saiu@cliente.test', 'responseStatus' => 'declined'],
            ],
            'hangoutLink' => 'https://meet.google.com/aaa-bbbb-ccc',
        ])]);

        $resultado = $this->servico()->atualizar($linha, $this->dadosDoEvento([
            'participantes' => [['email' => 'fulano@cliente.test'], ['email' => 'novo@cliente.test']],
        ]), $analista);

        $this->assertTrue($resultado['ok'], $resultado['mensagem']);

        Http::assertSent(function (RequisicaoHttp $r) use ($analista) {
            if ($r->method() !== 'PATCH') {
                return false;
            }

            $porEmail = collect($r->data()['attendees'])->keyBy('email');

            return $porEmail[mb_strtolower($analista->email)]['organizer'] === true
                && $porEmail['fulano@cliente.test']['responseStatus'] === 'accepted'
                && ! isset($porEmail['novo@cliente.test']['responseStatus'])
                && ! $porEmail->has('saiu@cliente.test')
                // Continuou no Meet: a sala não é pedida de novo.
                && ! array_key_exists('conferenceData', $r->data())
                && ! str_contains($r->url(), 'conferenceDataVersion');
        });
    }

    public function test_trocar_o_meet_por_link_tira_a_sala(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_troca']);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_troca', 'status' => 'confirmed'])]);

        $this->servico()->atualizar($linha, $this->dadosDoEvento([
            'plataforma' => OnboardingEventoGoogle::PLATAFORMA_LINK,
            'link'       => 'https://zoom.us/j/123',
        ]), $analista);

        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH'
            && array_key_exists('conferenceData', $r->data())
            && $r->data()['conferenceData'] === null
            && $r->data()['location'] === 'https://zoom.us/j/123'
            && str_contains($r->url(), 'conferenceDataVersion=1'));

        $this->assertSame('https://zoom.us/j/123', $linha->fresh()->link_reuniao);
    }

    public function test_editar_evento_apagado_no_google_o_marca_cancelado(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'gone'], 410)]);

        $resultado = $this->servico()->atualizar($linha, $this->dadosDoEvento(), $analista);

        $this->assertFalse($resultado['ok']);
        $this->assertSame(OnboardingEventoGoogle::STATUS_CANCELADO, $linha->fresh()->status);
        Http::assertNotSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH');
    }

    public function test_rotina_nao_se_edita_pelo_agendar(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista, [
            'tipo'        => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'chave'       => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'recorrencia' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE',
        ]);

        $this->assertFalse($this->servico()->atualizar($linha, $this->dadosDoEvento(), $analista)['ok']);
        Http::assertNothingSent();
    }

    public function test_cancelar_avisa_os_convidados_e_mantem_o_rastro(): void
    {
        [$onboarding, $analista] = $this->cenario();
        app(OnboardingEngineService::class)->agendarReuniao($onboarding, now()->addDays(2), $analista);
        $linha = $this->vinculo($onboarding, $analista, [
            'tipo'            => OnboardingEventoGoogle::TIPO_KICKOFF,
            'chave'           => OnboardingEventoGoogle::TIPO_KICKOFF,
            'google_event_id' => 'evt_cancelar',
        ]);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response('', 204)]);

        $resultado = $this->servico()->cancelar($linha, $analista);

        $this->assertTrue($resultado['ok']);
        $this->assertStringContainsString('continua marcada no onboarding', $resultado['mensagem']);
        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/events/evt_cancelar')
            && str_contains($r->url(), 'sendUpdates=all'));
        $this->assertSame(OnboardingEventoGoogle::STATUS_CANCELADO, $linha->fresh()->status);
        $this->assertNotNull($linha->fresh()->cancelado_em);
        $this->assertNotNull($onboarding->fresh()->reuniao_agendada_para);
    }

    // ─── O retrato acompanha o Google ───────────────────────────────────────

    public function test_conferir_marca_cancelado_quando_o_google_apagou(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'not found'], 404)]);

        $this->servico()->conferir($linha);

        $this->assertSame(OnboardingEventoGoogle::STATUS_CANCELADO, $linha->fresh()->status);
    }

    public function test_conferir_copia_o_horario_mudado_no_google(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response([
            'id'          => $linha->google_event_id,
            'status'      => 'confirmed',
            'summary'     => 'Mapeamento (remarcado)',
            'start'       => ['dateTime' => '2026-09-24T16:30:00Z'],
            'end'         => ['dateTime' => '2026-09-24T17:30:00Z'],
            'hangoutLink' => 'https://meet.google.com/nova-sala',
        ])]);

        $this->servico()->conferir($linha);
        $linha->refresh();

        // 16:30 UTC é 13:30 em Brasília.
        $this->assertSame('2026-09-24 13:30', $linha->inicio->format('Y-m-d H:i'));
        $this->assertSame('Mapeamento (remarcado)', $linha->titulo);
        $this->assertSame('https://meet.google.com/nova-sala', $linha->link_reuniao);
        $this->assertTrue($linha->ativo());
    }

    public function test_organizadores_sao_analista_e_estrategista_com_quem_marca_primeiro(): void
    {
        [$onboarding, $analista, $estrategista] = $this->cenario();

        $lista = $this->servico()->organizadores($onboarding, $estrategista);

        $this->assertSame([$analista->id, $estrategista->id], array_column($lista, 'id'));
        $this->assertSame([true, false], array_column($lista, 'conectado'));
        $this->assertSame($estrategista->id, $this->servico()->organizadorPadrao($onboarding, $estrategista));
        $this->assertSame($analista->id, $this->servico()->organizadorPadrao($onboarding, User::factory()->create()));
    }
}
