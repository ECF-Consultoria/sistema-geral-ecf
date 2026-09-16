<?php

namespace Tests\Feature\Onboarding;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\GoogleToken;
use App\Models\Onboarding;
use App\Models\OnboardingEventoGoogle;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\AgendaGoogleService;
use App\Services\Onboarding\OnboardingEngineService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A semana da agenda dentro da ficha do onboarding (16/09/2026).
 *
 * ### O que estes testes protegem
 * 1. **Que a agenda de uma pessoa não vire mural.** Quem não é dono vê horário
 *    ocupado SEM o assunto: "consulta médica" e "entrevista" ficam na conta de
 *    quem as marcou. A exceção são os eventos que o próprio sistema criou para
 *    este onboarding.
 * 2. **Que esta leitura nunca derrube a tela.** O Google fora do ar vira frase;
 *    o campo de data continua valendo, porque marcar reunião não pode depender
 *    de API de terceiro.
 * 3. **Que nada saia à toa.** Sem token conectado, nenhuma chamada é feita.
 * 4. **Que a régua de acesso do onboarding valha aqui também** — a agenda de um
 *    colega não é endereço aberto a quem não tem a empresa na carteira.
 */
class AgendaSemanaTest extends TestCase
{
    use RefreshDatabase;

    /** Quarta-feira: no meio da semana, para a virada ser visível nas duas pontas. */
    private const REFERENCIA = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /** O que o Google devolve NESTE teste (o fake acumula: o primeiro que casa vence). */
    private function googleResponde(array $itens, int $status = 200): void
    {
        Http::fake([
            'https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => $itens], $status),
        ]);
    }

    private function referencia(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::REFERENCIA, 'America/Sao_Paulo');
    }

    private function segunda(): CarbonImmutable
    {
        return $this->referencia()->startOfWeek(CarbonInterface::MONDAY);
    }

    /** Um compromisso do Google, com horário dentro da semana de referência. */
    private function evento(array $atributos = []): array
    {
        $inicio = $this->segunda()->addDays(2)->setTime(10, 0);

        return array_merge([
            'id'      => 'evt_'.uniqid(),
            'summary' => 'Consulta médica',
            'status'  => 'confirmed',
            'start'   => ['dateTime' => $inicio->toIso8601String()],
            'end'     => ['dateTime' => $inicio->addHour()->toIso8601String()],
        ], $atributos);
    }

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    /** Onboarding em andamento com analista que já conectou o Google. */
    private function cenario(array $opcoes = []): array
    {
        $company = Company::create([
            'name'         => 'Empresa Semana '.uniqid(),
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
                // Sem isto o serviço tentaria renovar o token e o teste mediria
                // a renovação em vez do que promete medir.
                'expires_at'    => now()->addHour(),
            ]);
        }

        return [$onboarding->fresh(), $analista, $estrategista, $company];
    }

    private function servico(): AgendaGoogleService
    {
        return app(AgendaGoogleService::class);
    }

    // ─── A leitura da semana ────────────────────────────────────────────────

    public function test_a_semana_pedida_ao_google_e_a_da_data_de_referencia(): void
    {
        $this->googleResponde([$this->evento()]);
        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->semana($onboarding, $this->referencia(), $analista);

        $this->assertSame($this->segunda()->toDateString(), $r['inicio']);
        $this->assertSame($this->segunda()->addDays(6)->toDateString(), $r['fim']);
        $this->assertTrue(CarbonImmutable::parse($r['inicio'])->isMonday());
        $this->assertNull($r['erro']);
        $this->assertCount(1, $r['eventos']);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($query['timeMin'] ?? '', $this->segunda()->toDateString())
                && str_starts_with($query['timeMax'] ?? '', $this->segunda()->addDays(6)->toDateString());
        });
    }

    public function test_o_dono_da_agenda_ve_o_assunto_dos_proprios_compromissos(): void
    {
        $this->googleResponde([$this->evento(['summary' => 'Consulta médica'])]);
        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->semana($onboarding, $this->referencia(), $analista);

        $this->assertTrue($r['e_voce']);
        $this->assertSame('Consulta médica', $r['eventos'][0]['titulo']);
    }

    /**
     * O ponto sensível da funcionalidade: para achar horário livre basta saber
     * que está ocupado. O assunto da agenda de alguém é dele.
     */
    public function test_quem_nao_e_dono_ve_o_horario_ocupado_mas_nao_o_assunto(): void
    {
        $this->googleResponde([$this->evento(['summary' => 'Consulta médica'])]);
        [$onboarding] = $this->cenario();

        $curioso = User::factory()->create(['role' => 'admin']);

        $r = $this->servico()->semana($onboarding, $this->referencia(), $curioso);

        $this->assertFalse($r['e_voce']);
        $this->assertCount(1, $r['eventos'], 'o horário ocupado precisa aparecer');
        $this->assertNull($r['eventos'][0]['titulo'], 'o assunto NÃO pode vazar');
        $this->assertNotEmpty($r['eventos'][0]['inicio']);
        $this->assertNotEmpty($r['eventos'][0]['fim']);
    }

    /**
     * A exceção da regra acima: o título deste evento foi o sistema que
     * escreveu, a partir deste onboarding. Escondê-lo faria a própria reunião do
     * cliente aparecer como "Ocupado" na tela de quem a marcou.
     */
    public function test_o_evento_deste_onboarding_aparece_com_nome_ate_para_quem_nao_e_dono(): void
    {
        [$onboarding, $analista] = $this->cenario();

        OnboardingEventoGoogle::create([
            'onboarding_id'          => $onboarding->id,
            'tipo'                   => OnboardingEventoGoogle::TIPO_RECORRENTE,
            'google_event_id'        => 'evt_serie',
            'calendar_owner_user_id' => $analista->id,
            'calendar_owner_email'   => $analista->email,
            'enviado_em'             => now(),
            'enviado_por'            => $analista->id,
            'convidados'             => 2,
        ]);

        // Ocorrência de uma série: o Google sufixa o id do evento-mãe.
        $this->googleResponde([$this->evento([
            'id'      => 'evt_serie_20260916T170000Z',
            'summary' => 'ECF · Cliente — Reunião de acompanhamento',
        ])]);

        $r = $this->servico()->semana($onboarding, $this->referencia(), User::factory()->create());

        $this->assertTrue($r['eventos'][0]['nosso']);
        $this->assertSame('ECF · Cliente — Reunião de acompanhamento', $r['eventos'][0]['titulo']);
    }

    /**
     * Nem tudo que está no calendário ocupa a pessoa. Tratar aniversário e
     * evento recusado como ocupado esconderia horário bom — o efeito prático
     * seria a reunião ir para mais longe sem motivo.
     */
    public function test_cancelado_recusado_e_livre_nao_ocupam_horario(): void
    {
        $this->googleResponde([
            $this->evento(['status' => 'cancelled']),
            $this->evento(['transparency' => 'transparent', 'summary' => 'Aniversário']),
            $this->evento(['attendees' => [['self' => true, 'responseStatus' => 'declined']]]),
        ]);

        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->semana($onboarding, $this->referencia(), $analista);

        $this->assertSame([], $r['eventos']);
    }

    public function test_sem_google_conectado_nada_e_pedido_e_a_tela_sabe_oferecer_conexao(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario(['google' => false]);

        $r = $this->servico()->semana($onboarding, $this->referencia(), $analista);

        $this->assertFalse($r['conectado']);
        $this->assertTrue($r['e_voce'], 'é o próprio analista: a tela oferece conectar a agenda dele');
        $this->assertSame([], $r['eventos']);
        $this->assertNull($r['erro'], 'falta de conexão não é erro — é convite a conectar');

        Http::assertNothingSent();
    }

    public function test_falha_do_google_vira_frase_e_nao_derruba_a_escolha_de_horario(): void
    {
        $this->googleResponde([], 500);
        [$onboarding, $analista] = $this->cenario();

        $r = $this->servico()->semana($onboarding, $this->referencia(), $analista);

        $this->assertNotNull($r['erro']);
        $this->assertStringContainsString($analista->name, $r['erro']);
        $this->assertSame([], $r['eventos']);
    }

    // ─── A rota ─────────────────────────────────────────────────────────────

    public function test_a_rota_devolve_a_semana_em_json(): void
    {
        $this->googleResponde([$this->evento()]);
        [$onboarding] = $this->cenario();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('onboarding.agenda.disponibilidade', [
                'onboarding' => $onboarding->id,
                'inicio'     => self::REFERENCIA,
            ]))
            ->assertOk()
            ->assertJsonStructure(['inicio', 'fim', 'dono', 'e_voce', 'conectado', 'eventos', 'erro']);
    }

    public function test_quem_nao_tem_a_empresa_na_carteira_nao_abre_a_agenda(): void
    {
        Http::fake();
        [$onboarding] = $this->cenario();

        $this->actingAs($this->userComPermissaoDeOnboarding())
            ->getJson(route('onboarding.agenda.disponibilidade', $onboarding->id))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /** Tem `core.onboarding` (passa no middleware), mas nenhuma empresa na carteira. */
    private function userComPermissaoDeOnboarding(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Coordenação Agenda Semana',
            'slug'       => 'coordenacao-agenda-semana',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('setor_permissoes')->insert([
            'setor_id'       => $setorId,
            'permission_key' => 'core.onboarding',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $user = User::factory()->create(['role' => 'consultor']);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => null,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user->fresh();
    }
}
