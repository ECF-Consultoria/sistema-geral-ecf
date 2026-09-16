<?php

namespace Tests\Feature\Onboarding;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingAgenda;
use App\Models\OnboardingContato;
use App\Models\OnboardingEventoGoogle;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\AgendaGoogleService;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O convite da reunião do onboarding no Google Agenda (15/09/2026).
 *
 * ### O que estes testes protegem
 * 1. **Que ninguém receba convite sem alguém pedir.** O Google dispara e-mail ao
 *    criar o evento. Todo caminho que não pode enviar precisa parar ANTES da
 *    chamada — por isso quase todo caso aqui termina em `assertNothingSent()`.
 * 2. **Que clicar duas vezes não encha a agenda do cliente.** O segundo envio é
 *    PATCH no mesmo evento, não um convite novo.
 * 3. **Que o convite chegue:** `sendUpdates=all` é o que faz o Google mandar o
 *    e-mail. Sem ele o evento nasce mudo, e o sintoma seria "criei o convite e o
 *    cliente não recebeu nada".
 *
 * ### Por que cada teste declara a resposta do Google
 * `Http::fake()` ACUMULA stubs e o primeiro que casa vence. Com um fake genérico
 * no `setUp`, o 403 declarado dentro do teste de escopo insuficiente nunca era
 * usado: a chamada respondia 200 e o teste passava medindo o caminho feliz.
 * Declarar por teste é o que mantém cada caso medindo o que promete.
 */
class AgendaGoogleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /** A resposta que o Google vai dar NESTE teste. */
    private function googleResponde(array $corpo = ['id' => 'evt_google_1'], int $status = 200): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response($corpo, $status),
        ]);
    }

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Onboarding em andamento, com analista que já conectou o Google e um contato com e-mail. */
    private function cenario(array $opcoes = []): array
    {
        $company = Company::create([
            'name'         => 'Empresa Agenda '.uniqid(),
            'cnpj'         => substr(str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT), 0, 14),
            'active'       => true,
            'status'       => 'ativo',
            'empresa_nova' => false,
        ]);

        $contrato = ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $this->servicoDeGestao()->id,
            'valor_contratado' => 1500,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $analista = User::factory()->create(['name' => 'Analista da Conta']);
        $estrategista = User::factory()->create(['name' => 'Estrategista da Conta']);
        app(OnboardingEngineService::class)->definirResponsaveis($onboarding, $estrategista, $analista);

        if ($opcoes['google'] ?? true) {
            GoogleToken::create([
                'user_id'       => $analista->id,
                'access_token'  => 'token-de-teste',
                'refresh_token' => 'refresh-de-teste',
                // No futuro: sem isto o serviço tentaria renovar o token e o
                // teste mediria a renovação em vez do que ele promete medir.
                'expires_at'    => now()->addHour(),
            ]);
        }

        if ($opcoes['contato'] ?? true) {
            OnboardingContato::create([
                'onboarding_id' => $onboarding->id,
                'papel'         => OnboardingContato::PAPEL_PONTO_CONTATO,
                'nome'          => 'Fulano Cliente',
                'email'         => 'fulano@cliente.test',
            ]);
        }

        if ($opcoes['data'] ?? true) {
            app(OnboardingEngineService::class)->agendarReuniao(
                $onboarding->fresh(),
                now()->addDays(3)->setTime(14, 0),
                $analista
            );
        }

        if ($opcoes['agenda'] ?? false) {
            OnboardingAgenda::create([
                'onboarding_id' => $onboarding->id,
                'dia_semana'    => 2, // terça
                'horario'       => '14:00',
                'periodicidade' => OnboardingAgenda::PERIODICIDADE_QUINZENAL,
                'definida_em'   => now(),
                'definida_por'  => $analista->id,
            ]);
        }

        return [$onboarding->fresh(), $analista, $estrategista, $company];
    }

    private function servico(): AgendaGoogleService
    {
        return app(AgendaGoogleService::class);
    }

    // ─── O caminho feliz ────────────────────────────────────────────────────

    public function test_convite_do_kickoff_vai_com_convidados_fuso_e_pedido_de_envio(): void
    {
        $this->googleResponde();
        [$onboarding, $analista, $estrategista, $company] = $this->cenario();

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertTrue($r['ok'], $r['mensagem']);

        Http::assertSent(function ($request) use ($company, $estrategista, $analista) {
            $corpo = $request->data();
            $emails = collect($corpo['attendees'] ?? [])->pluck('email')->all();

            return $request->method() === 'POST'
                && str_contains($request->url(), 'sendUpdates=all')
                && str_contains($corpo['summary'], $company->name)
                && str_contains($corpo['description'], '[Cliente: '.$company->name.']')
                && $corpo['start']['timeZone'] === 'America/Sao_Paulo'
                && in_array('fulano@cliente.test', $emails, true)
                && in_array(mb_strtolower($estrategista->email), $emails, true)
                // O dono da agenda é o organizador: convidá-lo seria convite
                // para si mesmo.
                && ! in_array(mb_strtolower($analista->email), $emails, true)
                // Kickoff é evento único.
                && ! array_key_exists('recurrence', $corpo);
        });

        $evento = OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)->firstOrFail();
        $this->assertSame('evt_google_1', $evento->google_event_id);
        $this->assertSame($analista->id, $evento->calendar_owner_user_id);
        $this->assertSame(2, $evento->convidados);
    }

    public function test_reuniao_recorrente_vai_como_serie_quinzenal_no_dia_combinado(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario(['agenda' => true]);

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_RECORRENTE, $analista);

        $this->assertTrue($r['ok'], $r['mensagem']);

        Http::assertSent(function ($request) {
            $corpo = $request->data();

            return isset($corpo['recurrence'][0])
                && $corpo['recurrence'][0] === 'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=TU'
                && str_starts_with(substr($corpo['start']['dateTime'], 11), '14:00');
        });
    }

    /**
     * Clicar de novo não pode gerar um segundo convite: o cliente receberia dois
     * e-mails e ficaria com duas reuniões na agenda para o mesmo compromisso.
     */
    public function test_segundo_envio_atualiza_o_mesmo_evento_em_vez_de_criar_outro(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario();

        $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF, $analista);
        $r = $this->servico()->enviar($onboarding->fresh(), OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertTrue($r['ok'], $r['mensagem']);
        $this->assertSame(1, OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)->count());

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), 'events/evt_google_1')
            && str_contains($request->url(), 'sendUpdates=all'));
    }

    // ─── Tudo que precisa parar ANTES de tocar no Google ────────────────────

    public function test_sem_data_marcada_nao_chama_o_google(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario(['data' => false]);

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Marque a data', $r['mensagem']);
        Http::assertNothingSent();
    }

    public function test_agenda_incompleta_nao_chama_o_google(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_RECORRENTE, $analista);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Combine dia, horário', $r['mensagem']);
        Http::assertNothingSent();
    }

    public function test_analista_sem_google_conectado_e_recusado_com_o_nome_dele(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario(['google' => false]);

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Analista da Conta', $r['mensagem']);
        $this->assertStringContainsString('não conectou', $r['mensagem']);
        Http::assertNothingSent();
    }

    /**
     * Sem e-mail de ninguém do cliente, o convite não alcança quem ele existe
     * para alcançar — e criá-lo mesmo assim encheria a agenda da equipe de
     * eventos que o cliente nunca vê.
     */
    public function test_sem_contato_com_email_nao_chama_o_google(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario(['contato' => false]);

        // O estrategista tem e-mail, mas é da ECF. Tirá-lo isola o que o teste
        // mede: a ausência de contato do CLIENTE.
        $onboarding->forceFill(['responsavel_estrategista_id' => null])->save();

        $r = $this->servico()->enviar($onboarding->fresh(), OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('e-mail', $r['mensagem']);
        Http::assertNothingSent();
    }

    public function test_onboarding_sem_responsavel_nao_tem_de_quem_seja_a_agenda(): void
    {
        $this->googleResponde();
        [$onboarding, $analista] = $this->cenario();

        $onboarding->forceFill([
            'responsavel_id'              => null,
            'responsavel_analista_id'     => null,
            'responsavel_estrategista_id' => null,
        ])->save();

        $r = $this->servico()->enviar($onboarding->fresh(), OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('analista', $r['mensagem']);
        Http::assertNothingSent();
    }

    // ─── O erro que vai acontecer nos primeiros dias ────────────────────────

    /**
     * Quem conectou o Google antes de 15/09/2026 consentiu só leitura. O Google
     * responde 403, e a mensagem precisa mandar reconectar em vez de parecer
     * falha do sistema.
     */
    public function test_token_antigo_pede_reconexao_e_nao_grava_evento(): void
    {
        $this->googleResponde([
            'error' => [
                'code'    => 403,
                'message' => 'Request had insufficient authentication scopes.',
                'errors'  => [['reason' => 'insufficientPermissions']],
            ],
        ], 403);

        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->enviar($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF, $analista);

        $this->assertFalse($r['ok'], 'O 403 do Google não pode virar sucesso.');
        $this->assertStringContainsString('reconectar', $r['mensagem']);
        $this->assertStringContainsString('Analista da Conta', $r['mensagem']);
        $this->assertSame(0, OnboardingEventoGoogle::where('onboarding_id', $onboarding->id)->count());
    }

    // ─── A prévia que a tela usa ────────────────────────────────────────────

    public function test_previa_diz_quem_recebe_e_o_que_falta_sem_chamar_o_google(): void
    {
        $this->googleResponde();
        [$onboarding] = $this->cenario();

        $previa = $this->servico()->previa($onboarding, OnboardingEventoGoogle::TIPO_KICKOFF);

        $this->assertTrue($previa['pode_enviar']);
        $this->assertNull($previa['impedimento']);
        $this->assertSame('Analista da Conta', $previa['dono']);
        $this->assertFalse($previa['ja_enviado']);
        $this->assertContains('fulano@cliente.test', collect($previa['convidados'])->pluck('email')->all());

        // Sem agenda combinada, o outro convite não pode ser oferecido.
        $recorrente = $this->servico()->previa($onboarding, OnboardingEventoGoogle::TIPO_RECORRENTE);
        $this->assertFalse($recorrente['pode_enviar']);

        Http::assertNothingSent();
    }
}
