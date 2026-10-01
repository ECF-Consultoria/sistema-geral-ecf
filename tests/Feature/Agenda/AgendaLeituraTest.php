<?php

namespace Tests\Feature\Agenda;

use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use App\Services\Agenda\AgendaService;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Agenda\EventoGoogle;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A leitura da Agenda (16/09/2026).
 *
 * ### O que estes testes protegem
 * 1. **Que a agenda de uma pessoa não vaze para outra.** Cada um lê o próprio
 *    Google; dos eventos de onboarding, só vê quem conduz o onboarding — ou quem
 *    abriu a Agenda pela ficha e tem a empresa na carteira.
 * 2. **Que quem não conectou o Google ainda veja os eventos do onboarding**,
 *    pelo retrato — e que nenhuma chamada saia à toa.
 * 3. **Que a data velha não seja desenhada** quando o evento foi movido no
 *    Google.
 * 4. **Que o cartão da ficha não chame o Google a cada visita**: só confere o
 *    retrato velho.
 */
class AgendaLeituraTest extends TestCase
{
    use CenarioAgenda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-16 09:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function servico(): AgendaService
    {
        return app(AgendaService::class);
    }

    private function semana(): array
    {
        return [
            CarbonImmutable::parse('2026-09-21', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-09-27', 'America/Sao_Paulo'),
        ];
    }

    // ─── A agenda da pessoa ─────────────────────────────────────────────────

    public function test_o_google_da_pessoa_vem_com_o_evento_do_onboarding_identificado(): void
    {
        [$onboarding, $analista, , $company] = $this->cenario();
        $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_onb']);

        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => [
            $this->itemGoogle([
                'id'          => 'evt_onb',
                'summary'     => 'ECF · Cliente — Mapeamento da conta',
                'start'       => ['dateTime' => '2026-09-22T10:00:00-03:00'],
                'end'         => ['dateTime' => '2026-09-22T11:00:00-03:00'],
                'hangoutLink' => 'https://meet.google.com/aaa-bbbb-ccc',
            ]),
            $this->itemGoogle(['id' => 'evt_pessoal']),
        ]])]);

        $resposta = $this->servico()->periodo($analista, ...$this->semana());

        $this->assertTrue($resposta['conectado']);
        $this->assertNull($resposta['erro']);
        $porId = collect($resposta['eventos'])->keyBy('google_event_id');
        $this->assertCount(2, $porId);

        $doOnboarding = $porId['evt_onb'];
        $this->assertSame($company->name, $doOnboarding['vinculo']['empresa']);
        $this->assertSame(OnboardingEventoGoogle::TIPO_MAPEAMENTO, $doOnboarding['tipo']);
        $this->assertSame('google_meet', $doOnboarding['plataforma']);
        $this->assertSame('vinculo', $doOnboarding['edicao']);

        $pessoal = $porId['evt_pessoal'];
        $this->assertNull($pessoal['vinculo']);
        $this->assertSame('Consulta médica', $pessoal['titulo']);
        // Organizado por ela e não é série: edita pelo sistema.
        $this->assertSame('google', $pessoal['edicao']);
    }

    public function test_evento_do_google_de_outra_pessoa_nao_e_editavel_aqui(): void
    {
        [, $analista] = $this->cenario();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => [
            $this->itemGoogle(['id' => 'evt_convite', 'organizer' => ['email' => 'outra@empresa.test', 'self' => false]]),
            $this->itemGoogle(['id' => 'evt_serie', 'recurringEventId' => 'serie_1']),
            $this->itemGoogle(['id' => 'evt_cancelado', 'status' => 'cancelled']),
        ]])]);

        $eventos = collect($this->servico()->periodo($analista, ...$this->semana())['eventos'])->keyBy('google_event_id');

        $this->assertNull($eventos['evt_convite']['edicao']);
        $this->assertNull($eventos['evt_serie']['edicao']);
        $this->assertTrue($eventos['evt_serie']['recorrente']);
        $this->assertFalse($eventos->has('evt_cancelado'));
    }

    public function test_sem_google_conectado_os_eventos_do_onboarding_vem_do_retrato(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_retrato']);

        $resposta = $this->servico()->periodo($estrategista, ...$this->semana());

        $this->assertFalse($resposta['conectado']);
        $this->assertCount(1, $resposta['eventos']);
        $evento = $resposta['eventos'][0];
        $this->assertSame('sistema', $evento['origem']);
        $this->assertSame('https://meet.google.com/aaa-bbbb-ccc', $evento['link']);
        $this->assertSame('Analista da Conta', $evento['organizador']['nome']);
        $this->assertSame('2026-09-22T10:00:00-03:00', $evento['inicio']);
        Http::assertNothingSent();
    }

    public function test_link_do_teams_gravado_aparece_como_teams(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $this->vinculo($onboarding, $analista, [
            'plataforma'   => OnboardingEventoGoogle::PLATAFORMA_LINK,
            'link_reuniao' => 'https://teams.microsoft.com/l/meetup-join/abc',
        ]);

        $evento = $this->servico()->periodo($estrategista, ...$this->semana())['eventos'][0];

        $this->assertSame('teams', $evento['plataforma']);
        $this->assertSame('https://teams.microsoft.com/l/meetup-join/abc', $evento['link']);
    }

    public function test_evento_de_onboarding_alheio_nao_aparece(): void
    {
        Http::fake();
        [$onboarding, $analista, , $company] = $this->cenario();
        $this->vinculo($onboarding, $analista);

        $deFora = User::factory()->create();
        $comCarteira = $this->userComPermissaoDeOnboarding($company);

        $this->assertSame([], $this->servico()->periodo($deFora, ...$this->semana())['eventos']);
        // Ter a empresa na carteira não basta sem o contexto da ficha…
        $this->assertSame([], $this->servico()->periodo($comCarteira, ...$this->semana())['eventos']);
        // …e com ele, o evento entra.
        $this->assertCount(1, $this->servico()->periodo($comCarteira, ...[...$this->semana(), $onboarding])['eventos']);
    }

    public function test_quem_conduz_sem_a_carteira_ve_o_evento_mas_nao_edita(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario(['carteira' => false]);
        $this->vinculo($onboarding, $analista);

        $eventos = $this->servico()->periodo($estrategista, ...$this->semana())['eventos'];

        // É a mesma régua da ficha: sem a empresa na carteira, a ficha também
        // não abre (medido em produção em 16/09/2026: 2 de 6 papéis assim).
        $this->assertCount(1, $eventos);
        $this->assertNull($eventos[0]['edicao']);
    }

    public function test_rotina_sem_google_vira_uma_ocorrencia_por_quinzena(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $this->vinculo($onboarding, $analista, [
            'tipo'        => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'chave'       => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'inicio'      => CarbonImmutable::parse('2026-09-02 14:00', 'America/Sao_Paulo'),
            'fim'         => CarbonImmutable::parse('2026-09-02 15:00', 'America/Sao_Paulo'),
            'recorrencia' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE',
        ]);

        $eventos = $this->servico()->periodo(
            $estrategista,
            CarbonImmutable::parse('2026-09-14', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-10-11', 'America/Sao_Paulo'),
        )['eventos'];

        $this->assertSame(
            ['2026-09-16T14:00:00-03:00', '2026-09-30T14:00:00-03:00'],
            array_column($eventos, 'inicio'),
        );
        // A rotina se ajusta pela ficha, nunca pelo "editar" do evento.
        $this->assertSame([null, null], array_column($eventos, 'edicao'));
    }

    /**
     * 23/09/2026 — série encerrada no Google (UNTIL) ou com número fixo de
     * reuniões (COUNT) parava de existir lá e seguia desenhada aqui para
     * sempre, para quem não lê a agenda do dono.
     */
    public function test_rotina_projetada_respeita_o_fim_da_serie(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $vinculo = $this->vinculo($onboarding, $analista, [
            'tipo'        => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'chave'       => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'inicio'      => CarbonImmutable::parse('2026-09-02 14:00', 'America/Sao_Paulo'),
            'fim'         => CarbonImmutable::parse('2026-09-02 15:00', 'America/Sao_Paulo'),
            // Encerrada no dia 20: a reunião do dia 30 não existe mais.
            'recorrencia' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;UNTIL=20260920T030000Z',
        ]);

        $periodo = fn () => array_column($this->servico()->periodo(
            $estrategista,
            CarbonImmutable::parse('2026-09-14', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-10-11', 'America/Sao_Paulo'),
        )['eventos'], 'inicio');

        $this->assertSame(['2026-09-16T14:00:00-03:00'], $periodo());

        // COUNT=3: 02/09, 16/09 e 30/09 — nada em outubro.
        $vinculo->update(['recorrencia' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;COUNT=3']);
        $this->assertSame(['2026-09-16T14:00:00-03:00', '2026-09-30T14:00:00-03:00'], $periodo());

        $vinculo->update(['recorrencia' => 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=WE;COUNT=2']);
        $this->assertSame(['2026-09-16T14:00:00-03:00'], $periodo());
    }

    public function test_reuniao_marcada_sem_convite_aparece_como_do_sistema(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        app(OnboardingEngineService::class)->agendarReuniao(
            $onboarding,
            CarbonImmutable::parse('2026-09-23 09:30', 'America/Sao_Paulo'),
            $analista,
        );

        $eventos = $this->servico()->periodo($estrategista, ...$this->semana())['eventos'];

        $this->assertCount(1, $eventos);
        $this->assertSame('k:'.$onboarding->id, $eventos[0]['id']);
        $this->assertTrue($eventos[0]['vinculo']['sem_convite']);
        $this->assertSame('kickoff', $eventos[0]['edicao']);
    }

    public function test_evento_movido_no_google_nao_aparece_na_data_velha(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_movido']);

        Http::fake([
            // A semana do retrato não traz o evento…
            'https://www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => []]),
            // …porque ele foi para outubro.
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/evt_movido' => Http::response([
                'id'     => 'evt_movido',
                'status' => 'confirmed',
                'start'  => ['dateTime' => '2026-10-06T10:00:00-03:00'],
                'end'    => ['dateTime' => '2026-10-06T11:00:00-03:00'],
            ]),
        ]);

        $resposta = $this->servico()->periodo($analista, ...$this->semana());

        $this->assertSame([], $resposta['eventos']);
        $this->assertSame('2026-10-06 10:00', $linha->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_google_fora_do_ar_mostra_o_retrato_e_a_frase(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $this->vinculo($onboarding, $analista);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'x'], 503)]);

        $resposta = $this->servico()->periodo($analista, ...$this->semana());

        $this->assertNotNull($resposta['erro']);
        $this->assertCount(1, $resposta['eventos']);
        $this->assertSame('sistema', $resposta['eventos'][0]['origem']);
    }

    // ─── O cartão da ficha ──────────────────────────────────────────────────

    public function test_cartao_traz_proximos_em_ordem_e_quem_pode_organizar(): void
    {
        Http::fake();
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $this->vinculo($onboarding, $analista, [
            'titulo' => 'Depois',
            'inicio' => CarbonImmutable::parse('2026-10-02 10:00', 'America/Sao_Paulo'),
            'fim'    => CarbonImmutable::parse('2026-10-02 11:00', 'America/Sao_Paulo'),
        ]);
        $this->vinculo($onboarding, $analista, ['titulo' => 'Antes']);
        $this->vinculo($onboarding, $analista, [
            'titulo' => 'Já passou',
            'inicio' => CarbonImmutable::parse('2026-09-10 10:00', 'America/Sao_Paulo'),
            'fim'    => CarbonImmutable::parse('2026-09-10 11:00', 'America/Sao_Paulo'),
        ]);
        $this->vinculo($onboarding, $analista, ['titulo' => 'Cancelado', 'status' => OnboardingEventoGoogle::STATUS_CANCELADO]);

        $cartao = $this->servico()->doOnboarding($onboarding, $estrategista, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(['Antes', 'Depois'], array_column($cartao['proximos'], 'titulo'));
        $this->assertSame('2026-08-30', $cartao['inicio']);
        $this->assertSame('2026-10-03', $cartao['fim']);
        $this->assertSame(['Já passou', 'Antes', 'Depois'], array_column($cartao['eventos'], 'titulo'));
        $this->assertSame([$analista->id, $estrategista->id], array_column($cartao['organizadores'], 'id'));
        $this->assertSame($estrategista->id, $cartao['organizador_padrao']);
        $this->assertEqualsCanonicalizing(
            ['fulano@cliente.test', mb_strtolower($analista->email), mb_strtolower($estrategista->email)],
            array_column($cartao['participantes'], 'email'),
        );
        // Tudo recente: nada a conferir no Google.
        Http::assertNothingSent();
    }

    public function test_cartao_confere_so_o_retrato_velho(): void
    {
        [$onboarding, $analista] = $this->cenario();
        $velho = $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_velho', 'sincronizado_em' => now()->subHour()]);
        $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_recente']);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'gone'], 410)]);

        $cartao = $this->servico()->doOnboarding($onboarding, $analista, CarbonImmutable::parse('2026-09-01'));

        Http::assertSentCount(1);
        Http::assertSent(fn (RequisicaoHttp $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events/evt_velho'));
        $this->assertSame(OnboardingEventoGoogle::STATUS_CANCELADO, $velho->fresh()->status);
        $this->assertCount(1, $cartao['proximos']);
    }

    // ─── Plataforma ─────────────────────────────────────────────────────────

    public function test_plataforma_e_reconhecida_pelo_que_o_google_registrou(): void
    {
        $this->assertSame('google_meet', EventoGoogle::plataforma(['hangoutLink' => 'https://meet.google.com/x'])['plataforma']);

        $teams = EventoGoogle::plataforma(['conferenceData' => [
            'conferenceSolution' => ['name' => 'Microsoft Teams Meeting', 'key' => ['type' => 'addOn']],
            'entryPoints'        => [['entryPointType' => 'video', 'uri' => 'https://teams.microsoft.com/l/abc']],
        ]]);
        $this->assertSame(['teams', 'https://teams.microsoft.com/l/abc'], [$teams['plataforma'], $teams['link']]);

        $zoom = EventoGoogle::plataforma(['description' => '<p>Entrar: <a href="https://us02web.zoom.us/j/99">https://us02web.zoom.us/j/99</a></p>']);
        $this->assertSame(['zoom', 'https://us02web.zoom.us/j/99'], [$zoom['plataforma'], $zoom['link']]);

        $this->assertSame('presencial', EventoGoogle::plataforma(['location' => 'Av. Paulista, 1000'])['plataforma']);
        $this->assertSame('link', EventoGoogle::plataforma(['location' => 'https://whereby.com/sala'])['plataforma']);
        $this->assertNull(EventoGoogle::plataforma([])['plataforma']);
    }

    public function test_descricao_em_html_vira_texto(): void
    {
        $this->assertSame(
            "Pauta:\nItem 1 & 2",
            EventoGoogle::texto('<b>Pauta:</b><br>Item 1 &amp; 2'),
        );
    }
}
