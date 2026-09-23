<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\OnboardingEventoGoogle;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Portal\AgendamentoPortalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * O cliente marca a reunião de onboarding pelo Portal (23/09/2026).
 *
 * O que estes testes prendem:
 *  - só aparecem horários em que analista E estrategista estão livres, em dia
 *    útil, das 9h às 18h, a partir do próximo dia útil;
 *  - o cliente não recebe nada da agenda de ninguém além da lista de horários;
 *  - marcar confere o horário DE NOVO no servidor — horário ocupado ou forjado
 *    é recusado sem criar evento;
 *  - reunião já marcada (pela equipe) não se marca de novo pelo portal;
 *  - o Google vem ANTES da data: falhou o convite, nada é gravado.
 */
class AgendamentoPeloPortalTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    /** Quarta, 23/09/2026, 10h de Brasília — a janela vai de 24/09 a 08/10. */
    private const AGORA = '2026-09-23 10:00';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        \Carbon\Carbon::setTestNow(CarbonImmutable::parse(self::AGORA, 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AGORA, 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * O Google deste teste: o `freeBusy` responde por TOKEN (cada pessoa tem a
     * sua agenda) e a criação de evento devolve um Meet.
     *
     * @param  array<string, array<int, array{0: string, 1: string}>>  $ocupadoPorToken
     */
    private function google(array $ocupadoPorToken = [], int $statusCriacao = 200): void
    {
        Http::fake(function (Request $request) use ($ocupadoPorToken, $statusCriacao) {
            if (str_contains($request->url(), '/freeBusy')) {
                $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
                $busy = collect($ocupadoPorToken[$token] ?? [])->map(fn ($b) => [
                    'start' => CarbonImmutable::parse($b[0], 'America/Sao_Paulo')->utc()->toIso8601ZuluString(),
                    'end'   => CarbonImmutable::parse($b[1], 'America/Sao_Paulo')->utc()->toIso8601ZuluString(),
                ])->all();

                return Http::response(['calendars' => ['primary' => ['busy' => $busy]]]);
            }

            if (str_contains($request->url(), '/events') && $request->method() === 'POST') {
                return $statusCriacao === 200
                    ? Http::response(['id' => 'evt_portal', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])
                    : Http::response(['error' => 'boom'], $statusCriacao);
            }

            return Http::response([], 404);
        });
    }

    /** @return array{0: Onboarding, 1: User, 2: User, 3: Company} */
    private function cenario(bool $estrategistaConectado = true): array
    {
        $company = Company::create([
            'name'         => 'Loja Portal '.uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);

        $servico = Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        // E-mails fixos só no primeiro cenário: o teste de outra empresa monta dois.
        $sufixo = User::where('email', 'analista@ecf.test')->exists() ? '.'.uniqid() : '';
        $analista = User::factory()->create(['name' => 'Ana Analista', 'email' => 'analista@ecf.test'.$sufixo]);
        $estrategista = User::factory()->create(['name' => 'Edu Estrategista', 'email' => 'estrategista@ecf.test'.$sufixo]);
        app(OnboardingEngineService::class)->definirResponsaveis($onboarding, $estrategista, $analista);

        GoogleToken::create([
            'user_id' => $analista->id, 'access_token' => 'tk-analista', 'refresh_token' => 'r', 'expires_at' => now()->addHour(),
        ]);

        if ($estrategistaConectado) {
            GoogleToken::create([
                'user_id' => $estrategista->id, 'access_token' => 'tk-estrategista', 'refresh_token' => 'r', 'expires_at' => now()->addHour(),
            ]);
        }

        OnboardingContato::create([
            'onboarding_id' => $onboarding->id,
            'papel'         => OnboardingContato::PAPEL_PONTO_CONTATO,
            'nome'          => 'Contato Cliente',
            'email'         => 'contato@cliente.test',
        ]);

        return [$onboarding->fresh(), $analista, $estrategista, $company];
    }

    private function servico(): AgendamentoPortalService
    {
        return app(AgendamentoPortalService::class);
    }

    // ─── Os horários ────────────────────────────────────────────────────────

    public function test_so_oferece_horario_livre_para_os_dois_em_dia_util(): void
    {
        $this->google([
            'tk-analista'      => [['2026-09-24 10:00', '2026-09-24 11:30']],
            'tk-estrategista'  => [['2026-09-25 14:00', '2026-09-25 15:00']],
        ]);
        [$onboarding] = $this->cenario();

        $r = $this->servico()->horarios($onboarding);

        $this->assertNull($r['erro']);
        $h = $r['horarios'];

        // Hoje fica de fora; o primeiro dia é amanhã, às 9h.
        $this->assertSame('2026-09-24T09:00:00-03:00', $h[0]);
        // Ocupado do ANALISTA: 10h e 11h (a de 11h esbarra no fim às 11h30).
        $this->assertNotContains('2026-09-24T10:00:00-03:00', $h);
        $this->assertNotContains('2026-09-24T11:00:00-03:00', $h);
        $this->assertContains('2026-09-24T12:00:00-03:00', $h);
        // Ocupado do ESTRATEGISTA também tira o horário.
        $this->assertNotContains('2026-09-25T14:00:00-03:00', $h);
        $this->assertContains('2026-09-25T15:00:00-03:00', $h);
        // A última reunião termina às 18h.
        $this->assertContains('2026-09-24T17:00:00-03:00', $h);
        $this->assertNotContains('2026-09-24T18:00:00-03:00', $h);
        // Sábado e domingo não entram.
        $this->assertEmpty(array_filter($h, fn ($x) => str_starts_with($x, '2026-09-26') || str_starts_with($x, '2026-09-27')));
        // A janela acaba 14 dias depois do primeiro dia útil.
        $this->assertEmpty(array_filter($h, fn ($x) => $x >= '2026-10-08'));
    }

    /** Sem a agenda do estrategista não dá para saber se ele está livre — não se oferece horário. */
    public function test_sem_a_agenda_do_estrategista_o_portal_nao_oferece_marcar(): void
    {
        $this->google();
        [$onboarding] = $this->cenario(estrategistaConectado: false);

        $this->assertFalse($this->servico()->podeAgendar($onboarding));
        $this->assertNotNull($this->servico()->horarios($onboarding)['erro']);
        Http::assertNothingSent();
    }

    public function test_reuniao_ja_marcada_pela_equipe_so_aparece_a_data(): void
    {
        $this->google();
        [$onboarding, $analista, , $company] = $this->cenario();
        app(OnboardingEngineService::class)->agendarReuniao($onboarding, now()->addDays(2)->setTime(15, 0), $analista);

        $this->assertFalse($this->servico()->podeAgendar($onboarding->fresh()));

        $this->entrarNoPortal($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id,
                'inicio'        => '2026-09-24T09:00:00-03:00',
            ])
            ->assertSessionHasErrors('inicio');

        $this->assertSame(0, OnboardingEventoGoogle::count());
    }

    /** O JSON que chega ao navegador é só a lista de horários — nada da agenda de ninguém. */
    public function test_rota_de_horarios_nao_leva_nada_alem_dos_horarios(): void
    {
        $this->google(['tk-analista' => [['2026-09-24 10:00', '2026-09-24 11:00']]]);
        [$onboarding, , , $company] = $this->cenario();

        $json = $this->entrarNoPortal($company)
            ->getJson(route('portal.auth.onboarding.horarios', ['onboarding_id' => $onboarding->id]))
            ->assertOk()
            ->json();

        $this->assertSame(['horarios', 'erro'], array_keys($json));
        $this->assertNotContains('2026-09-24T10:00:00-03:00', $json['horarios']);
    }

    public function test_onboarding_de_outra_empresa_da_404(): void
    {
        $this->google();
        [$onboarding] = $this->cenario();
        [, , , $outra] = $this->cenario();

        $this->entrarNoPortal($outra)
            ->getJson(route('portal.auth.onboarding.horarios', ['onboarding_id' => $onboarding->id]))
            ->assertNotFound();
    }

    // ─── Marcar ─────────────────────────────────────────────────────────────

    public function test_cliente_marca_e_o_evento_nasce_na_agenda_do_analista_com_meet(): void
    {
        $this->google();
        [$onboarding, $analista, $estrategista, $company] = $this->cenario();
        $cliente = $this->clienteDoPortal($company, ['nome' => 'Dona da Loja', 'email' => 'dona@loja.test']);

        $this->entrarNoPortal($company, $cliente)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id,
                'inicio'        => '2026-09-24T09:00:00-03:00',
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/events') || $request->method() !== 'POST') {
                return false;
            }

            $emails = collect($request->data()['attendees'] ?? [])->pluck('email')->all();

            return $request->hasHeader('Authorization', 'Bearer tk-analista')
                && str_contains($request->url(), 'conferenceDataVersion=1')
                && str_contains($request->url(), 'sendUpdates=all')
                && in_array('estrategista@ecf.test', $emails, true)
                && in_array('contato@cliente.test', $emails, true)
                && in_array('dona@loja.test', $emails, true)
                && ! in_array('analista@ecf.test', $emails, true);
        });

        $onboarding->refresh();
        $this->assertTrue($onboarding->reuniao_agendada_para->equalTo(CarbonImmutable::parse('2026-09-24T09:00:00-03:00')));
        // Quem marcou foi o cliente — não se atribui a ninguém da equipe.
        $this->assertNull($onboarding->reuniao_agendada_por);

        $evento = OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)->where('chave', 'kickoff')->sole();
        $this->assertSame($analista->id, $evento->calendar_owner_user_id);
        $this->assertNull($evento->enviado_por);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $evento->link_reuniao);

        // O card do portal passa a mostrar a data e o link, sem o botão.
        $reuniao = collect(app(\App\Services\Onboarding\OnboardingLinkService::class)->reunioesDaEmpresa($company))->first();
        $this->assertFalse($reuniao['pode_agendar']);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $reuniao['link']);
    }

    public function test_horario_ocupado_ou_forjado_e_recusado_sem_criar_evento(): void
    {
        $this->google(['tk-estrategista' => [['2026-09-24 09:00', '2026-09-24 10:00']]]);
        [$onboarding, , , $company] = $this->cenario();

        foreach (['2026-09-24T09:00:00-03:00', '2026-09-24T03:00:00-03:00', '2026-09-26T10:00:00-03:00'] as $tentativa) {
            $this->entrarNoPortal($company)
                ->post(route('portal.auth.onboarding.agendar'), ['onboarding_id' => $onboarding->id, 'inicio' => $tentativa])
                ->assertSessionHasErrors('inicio');
        }

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/events'));
        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
    }

    /** O Google vem primeiro: sem convite, o cliente não fica com uma reunião que ninguém tem na agenda. */
    public function test_falha_no_google_nao_grava_a_data(): void
    {
        $this->google(statusCriacao: 500);
        [$onboarding, , , $company] = $this->cenario();

        $this->entrarNoPortal($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id,
                'inicio'        => '2026-09-24T09:00:00-03:00',
            ])
            ->assertSessionHasErrors('inicio');

        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
        $this->assertSame(0, OnboardingEventoGoogle::count());
    }
}
