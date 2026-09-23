<?php

namespace Tests\Feature\PortalCliente;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingContato;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Portal\AgendamentoPortalService;
use App\Support\Portal\PortalContexto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * A EQUIPE marca a reunião de onboarding pelo Portal (23/09/2026).
 *
 * O que estes testes prendem:
 *  - o CLIENTE não agenda nada: as duas rotas recusam a sessão dele, e o
 *    payload dele não traz quem organiza nem "pode agendar";
 *  - a equipe marca pelo mesmo caminho do "Agendar" da ficha: evento na
 *    agenda do organizador escolhido, Meet, convite aos contatos e à equipe;
 *  - organizador sem Google é recusado ANTES de gravar a data;
 *  - com a reunião marcada, o cliente recebe tudo: data, fim e link;
 *  - as sugestões são horários livres dos DOIS que conduzem — atalho, não
 *    trava (a equipe marca fora delas).
 */
class AgendamentoPeloPortalTest extends TestCase
{
    use EntraNoPortal;
    use RefreshDatabase;

    /** Quarta, 23/09/2026, 10h de Brasília — as sugestões vão de 24/09 a 08/10. */
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
    private function google(array $ocupadoPorToken = []): void
    {
        Http::fake(function (Request $request) use ($ocupadoPorToken) {
            if (str_contains($request->url(), '/freeBusy')) {
                $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
                $busy = collect($ocupadoPorToken[$token] ?? [])->map(fn ($b) => [
                    'start' => CarbonImmutable::parse($b[0], 'America/Sao_Paulo')->utc()->toIso8601ZuluString(),
                    'end'   => CarbonImmutable::parse($b[1], 'America/Sao_Paulo')->utc()->toIso8601ZuluString(),
                ])->all();

                return Http::response(['calendars' => ['primary' => ['busy' => $busy]]]);
            }

            if (str_contains($request->url(), '/events') && $request->method() === 'POST') {
                return Http::response(['id' => 'evt_portal', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij']);
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

        $analista = User::factory()->create(['name' => 'Ana Analista']);
        $estrategista = User::factory()->create(['name' => 'Edu Estrategista']);
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

    /** Sessão de EQUIPE no portal da empresa — a que o ticket de 60 s deixa. */
    private function comoEquipe(Company $empresa, ?User $membro = null): static
    {
        $membro ??= User::factory()->create(['role' => 'admin']);

        return $this->withSession([
            PortalContexto::SESSAO_EQUIPE => $membro->id,
            'portal_empresa_id'           => $empresa->id,
        ]);
    }

    private function reuniao(Company $company, bool $paraEquipe = false): array
    {
        return collect(app(OnboardingLinkService::class)->reunioesDaEmpresa($company, $paraEquipe))->first();
    }

    // ─── Sugestões ──────────────────────────────────────────────────────────

    public function test_sugere_horario_livre_para_os_dois_em_dia_util(): void
    {
        $this->google([
            'tk-analista'     => [['2026-09-24 10:00', '2026-09-24 11:30']],
            'tk-estrategista' => [['2026-09-25 14:00', '2026-09-25 15:00']],
        ]);
        [$onboarding] = $this->cenario();

        $r = app(AgendamentoPortalService::class)->sugestoes($onboarding);

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
        // A última termina às 18h; fim de semana não entra.
        $this->assertContains('2026-09-24T17:00:00-03:00', $h);
        $this->assertNotContains('2026-09-24T18:00:00-03:00', $h);
        $this->assertEmpty(array_filter($h, fn ($x) => str_starts_with($x, '2026-09-26') || str_starts_with($x, '2026-09-27')));
    }

    // ─── O cliente não agenda ───────────────────────────────────────────────

    public function test_cliente_nao_agenda_nem_le_sugestoes(): void
    {
        $this->google();
        [$onboarding, , , $company] = $this->cenario();

        $this->entrarNoPortal($company)
            ->getJson(route('portal.auth.onboarding.horarios', ['onboarding_id' => $onboarding->id]))
            ->assertForbidden();

        $this->entrarNoPortal($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id, 'inicio' => '2026-09-24 09:00', 'duracao' => 60,
            ])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
    }

    public function test_payload_do_cliente_nao_traz_organizadores_nem_agendar(): void
    {
        $this->google();
        [, , , $company] = $this->cenario();

        $cliente = $this->reuniao($company);
        $equipe = $this->reuniao($company, paraEquipe: true);

        $this->assertFalse($cliente['pode_agendar']);
        $this->assertSame([], $cliente['organizadores']);
        $this->assertTrue($equipe['pode_agendar']);
        $this->assertCount(2, $equipe['organizadores']);
    }

    // ─── A equipe agenda ────────────────────────────────────────────────────

    public function test_equipe_agenda_pelo_portal_com_meet_e_o_cliente_ve_tudo(): void
    {
        $this->google();
        [$onboarding, $analista, $estrategista, $company] = $this->cenario();
        $membro = User::factory()->create(['role' => 'admin']);

        $this->comoEquipe($company, $membro)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id'  => $onboarding->id,
                'inicio'         => '2026-09-24 09:00',
                'duracao'        => 45,
                'organizador_id' => $analista->id,
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(function (Request $request) use ($estrategista) {
            if (! str_contains($request->url(), '/events') || $request->method() !== 'POST') {
                return false;
            }

            $emails = collect($request->data()['attendees'] ?? [])->pluck('email')->all();

            return $request->hasHeader('Authorization', 'Bearer tk-analista')
                && str_contains($request->url(), 'conferenceDataVersion=1')
                && in_array('contato@cliente.test', $emails, true)
                && in_array(mb_strtolower($estrategista->email), $emails, true);
        });

        $onboarding->refresh();
        $this->assertTrue($onboarding->reuniao_agendada_para->equalTo(CarbonImmutable::parse('2026-09-24 09:00', 'America/Sao_Paulo')));
        $this->assertSame($membro->id, $onboarding->reuniao_agendada_por);

        // O que o CLIENTE passa a ver: data, fim, link.
        $reuniao = $this->reuniao($company);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $reuniao['link']);
        $this->assertTrue($reuniao['convite_enviado']);
        $this->assertTrue(CarbonImmutable::parse($reuniao['termina_em'])->equalTo(CarbonImmutable::parse('2026-09-24 09:45', 'America/Sao_Paulo')));
    }

    /** Sugestão é atalho: a equipe marca fora dela (e fora do horário comercial). */
    public function test_equipe_marca_horario_fora_das_sugestoes(): void
    {
        $this->google(['tk-analista' => [['2026-09-24 18:00', '2026-09-24 20:00']]]);
        [$onboarding, $analista, , $company] = $this->cenario();

        $this->comoEquipe($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id, 'inicio' => '2026-09-24 18:30', 'duracao' => 60, 'organizador_id' => $analista->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($onboarding->fresh()->reuniao_agendada_para);
    }

    /** Organizador sem Google: recusado ANTES da data — senão o cliente veria reunião sem convite. */
    public function test_organizador_sem_google_e_recusado_sem_gravar_a_data(): void
    {
        $this->google();
        [$onboarding, , $estrategista, $company] = $this->cenario(estrategistaConectado: false);

        $this->comoEquipe($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id, 'inicio' => '2026-09-24 09:00', 'duracao' => 60, 'organizador_id' => $estrategista->id,
            ])
            ->assertSessionHasErrors('inicio');

        Http::assertNothingSent();
        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
    }

    /**
     * O Google recusou o evento: nada de data. Pego na conferência visual —
     * o `criar()` da ficha grava a data ANTES do Google, e o cliente passava a
     * ver "reunião marcada" sem link e sem convite.
     */
    public function test_falha_no_google_nao_grava_a_data(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'boom'], 500)]);
        [$onboarding, $analista, , $company] = $this->cenario();

        $this->comoEquipe($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id, 'inicio' => '2026-09-24 09:00', 'duracao' => 60, 'organizador_id' => $analista->id,
            ])
            ->assertSessionHasErrors('inicio');

        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
        $this->assertSame(0, \App\Models\OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)->count());
    }

    public function test_data_no_passado_e_recusada(): void
    {
        $this->google();
        [$onboarding, $analista, , $company] = $this->cenario();

        $this->comoEquipe($company)
            ->post(route('portal.auth.onboarding.agendar'), [
                'onboarding_id' => $onboarding->id, 'inicio' => '2026-09-22 09:00', 'duracao' => 60, 'organizador_id' => $analista->id,
            ])
            ->assertSessionHasErrors('inicio');

        $this->assertNull($onboarding->fresh()->reuniao_agendada_para);
    }

    public function test_onboarding_de_outra_empresa_da_404(): void
    {
        $this->google();
        [$onboarding] = $this->cenario();
        [, , , $outra] = $this->cenario();

        $this->comoEquipe($outra)
            ->getJson(route('portal.auth.onboarding.horarios', ['onboarding_id' => $onboarding->id]))
            ->assertNotFound();
    }
}
