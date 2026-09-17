<?php

namespace Tests\Feature\Agenda;

use App\Models\OnboardingEventoGoogle;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * As rotas da Agenda (16/09/2026): quem entra, o que é recusado antes de
 * chegar ao Google, e o formato que a tela lê.
 */
class AgendaRotasTest extends TestCase
{
    use CenarioAgenda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->withoutVite();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-16 09:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** O corpo que o drawer manda. */
    private function formulario(array $troca = []): array
    {
        return array_merge([
            'tipo'          => OnboardingEventoGoogle::TIPO_MAPEAMENTO,
            'titulo'        => 'Mapeamento da conta',
            'inicio'        => '2026-09-22T10:00',
            'duracao'       => 60,
            'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_MEET,
            'participantes' => [['email' => 'fulano@cliente.test', 'nome' => 'Fulano']],
        ], $troca);
    }

    // ─── A página ───────────────────────────────────────────────────────────

    public function test_a_pagina_abre_com_o_onboarding_em_contexto_para_quem_tem_acesso(): void
    {
        Http::fake();
        [$onboarding, $analista, , $company] = $this->cenario();

        $this->actingAs($analista)
            ->get(route('agenda.index', ['onboarding' => $onboarding->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Agenda/Index')
                ->where('conectado', true)
                ->where('contexto.id', $onboarding->id)
                ->where('contexto.empresa', $company->name)
                ->has('onboardings', 1));

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->get(route('agenda.index', ['onboarding' => $onboarding->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('contexto', null)
                ->where('conectado', false)
                ->has('onboardings', 0));

        // Montar a página não chama o Google: a agenda carrega depois, em JSON.
        // (Outras chamadas, como o contador de alertas das props compartilhadas,
        // não são desta tela.)
        Http::assertNotSent(fn (RequisicaoHttp $r) => str_contains($r->url(), 'googleapis.com'));
    }

    public function test_eventos_exige_intervalo_valido_e_curto(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->getJson(route('agenda.eventos'))->assertUnprocessable();
        $this->actingAs($usuario)
            ->getJson(route('agenda.eventos', ['inicio' => '2026-09-20', 'fim' => '2026-09-10']))
            ->assertUnprocessable();
        $this->actingAs($usuario)
            ->getJson(route('agenda.eventos', ['inicio' => '2026-01-01', 'fim' => '2026-06-01']))
            ->assertUnprocessable();
    }

    public function test_eventos_com_onboarding_alheio_da_403(): void
    {
        Http::fake();
        [$onboarding] = $this->cenario();

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->getJson(route('agenda.eventos', [
                'inicio'     => '2026-09-21',
                'fim'        => '2026-09-27',
                'onboarding' => $onboarding->id,
            ]))
            ->assertForbidden();
    }

    public function test_eventos_devolve_o_formato_da_tela(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => [$this->itemGoogle()]])]);
        [, $analista] = $this->cenario();

        $this->actingAs($analista)
            ->getJson(route('agenda.eventos', ['inicio' => '2026-09-21', 'fim' => '2026-09-27']))
            ->assertOk()
            ->assertJsonStructure([
                'inicio', 'fim', 'conectado', 'erro',
                'eventos' => [['id', 'titulo', 'inicio', 'fim', 'dia_inteiro', 'origem', 'tipo', 'vinculo',
                    'plataforma', 'link', 'participantes', 'organizador', 'edicao']],
            ]);
    }

    // ─── Criar ──────────────────────────────────────────────────────────────

    public function test_criar_evento_do_onboarding_devolve_201_e_grava(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_http'])]);
        [$onboarding, $analista] = $this->cenario();

        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario(['onboarding_id' => $onboarding->id]))
            ->assertCreated()
            ->assertJson(['ok' => true, 'evento' => ['google_event_id' => 'evt_http']]);

        $this->assertSame('2026-09-22 10:00', OnboardingEventoGoogle::sole()->inicio->format('Y-m-d H:i'));
    }

    public function test_criar_evento_de_onboarding_sem_a_carteira_da_403_sem_chamar_o_google(): void
    {
        Http::fake();
        [$onboarding] = $this->cenario();

        $this->actingAs($this->userComPermissaoDeOnboarding())
            ->postJson(route('agenda.eventos.store'), $this->formulario(['onboarding_id' => $onboarding->id]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_link_e_obrigatorio_e_precisa_ser_url_na_plataforma_link(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_presencial'])]);
        [$onboarding, $analista] = $this->cenario();

        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario([
                'onboarding_id' => $onboarding->id,
                'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_LINK,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['link' => 'Cole o link da reunião.']);

        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario([
                'onboarding_id' => $onboarding->id,
                'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_LINK,
                'link'          => 'sala do teams',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['link']);

        // As duas recusas pararam antes do Google.
        Http::assertNothingSent();

        // No presencial, o mesmo campo é o endereço — texto livre.
        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario([
                'onboarding_id' => $onboarding->id,
                'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_PRESENCIAL,
                'link'          => 'Av. Paulista, 1000 — sala 3',
            ]))
            ->assertCreated();

        Http::assertSent(fn (RequisicaoHttp $r) => $r->data()['location'] === 'Av. Paulista, 1000 — sala 3');
    }

    public function test_sem_cliente_so_da_para_criar_outro_evento(): void
    {
        Http::fake();
        [, $analista] = $this->cenario();

        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario())
            ->assertUnprocessable()
            ->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    public function test_evento_sem_cliente_vai_para_a_agenda_de_quem_cria(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response($this->itemGoogle([
            'id'      => 'evt_meu',
            'summary' => 'Planejamento de mídia',
        ]))]);
        [, $analista] = $this->cenario();

        $this->actingAs($analista)
            ->postJson(route('agenda.eventos.store'), $this->formulario([
                'tipo'          => OnboardingEventoGoogle::TIPO_OUTRO,
                'titulo'        => 'Planejamento de mídia',
                'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_NENHUMA,
                'descricao'     => 'Revisar verba',
                // Mais de um convidado, com repetição e caixa alta: todos chegam
                // ao Google, uma vez cada (16/09 — o convite saía sem ninguém).
                'participantes' => [
                    ['email' => 'Fulano@Cliente.test', 'nome' => 'Fulano'],
                    ['email' => 'ciclano@cliente.test'],
                    ['email' => 'fulano@cliente.test'],
                ],
            ]))
            ->assertCreated()
            ->assertJsonPath('evento.google_event_id', 'evt_meu')
            ->assertJsonPath('evento.edicao', 'google');

        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer token-analista')
            && str_contains($r->url(), 'sendUpdates=all')
            && array_column($r->data()['attendees'], 'email') === ['fulano@cliente.test', 'ciclano@cliente.test']
            && $r->data()['description'] === 'Revisar verba'
            && ! str_contains($r->data()['description'], '[Cliente:'));
        $this->assertSame(0, OnboardingEventoGoogle::count());
    }

    // ─── Editar e cancelar ──────────────────────────────────────────────────

    public function test_editar_evento_do_onboarding_pela_rota(): void
    {
        [$onboarding, $analista, $estrategista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista, ['google_event_id' => 'evt_rota']);
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_rota', 'status' => 'confirmed'])]);

        // Quem edita é o estrategista; o evento continua na agenda do analista.
        $this->actingAs($estrategista)
            ->patchJson(route('agenda.eventos.update', $linha->id), $this->formulario(['inicio' => '2026-09-25T16:00']))
            ->assertOk()
            ->assertJson(['ok' => true]);

        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH' && $r->hasHeader('Authorization', 'Bearer token-analista'));
        $this->assertSame('2026-09-25 16:00', $linha->fresh()->inicio->format('Y-m-d H:i'));
    }

    public function test_editar_nao_aceita_trocar_de_onboarding(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista);

        $this->actingAs($analista)
            ->patchJson(route('agenda.eventos.update', $linha->id), $this->formulario(['onboarding_id' => 999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['onboarding_id']);
    }

    public function test_cancelar_evento_de_onboarding_alheio_da_403(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario();
        $linha = $this->vinculo($onboarding, $analista);

        $this->actingAs(User::factory()->create(['role' => 'consultor']))
            ->deleteJson(route('agenda.eventos.destroy', $linha->id))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertTrue($linha->fresh()->ativo());
    }

    public function test_evento_proprio_organizado_por_outra_pessoa_nao_se_edita(): void
    {
        [, $analista] = $this->cenario();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response($this->itemGoogle([
            'id'        => 'evt_alheio',
            'organizer' => ['email' => 'outra@empresa.test', 'self' => false],
        ]))]);

        $this->actingAs($analista)
            ->patchJson(route('agenda.google.update', 'evt_alheio'), $this->formulario(['tipo' => null]))
            ->assertUnprocessable()
            ->assertJson(['ok' => false]);

        Http::assertNotSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH');
    }

    public function test_evento_proprio_de_serie_nao_se_cancela_aqui(): void
    {
        [, $analista] = $this->cenario();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response($this->itemGoogle([
            'id'               => 'evt_serie_1',
            'recurringEventId' => 'evt_serie',
        ]))]);

        $this->actingAs($analista)
            ->deleteJson(route('agenda.google.destroy', 'evt_serie_1'))
            ->assertUnprocessable();

        Http::assertNotSent(fn (RequisicaoHttp $r) => $r->method() === 'DELETE');
    }

    public function test_editar_evento_proprio_so_reescreve_a_descricao_se_ela_mudou(): void
    {
        [, $analista] = $this->cenario();
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response($this->itemGoogle([
            'id'          => 'evt_meu',
            'description' => '<b>Pauta</b><br>Item 1',
            'attendees'   => [['email' => 'x@y.test', 'responseStatus' => 'accepted']],
        ]))]);

        $this->actingAs($analista)
            ->patchJson(route('agenda.google.update', 'evt_meu'), $this->formulario([
                'tipo'          => null,
                'plataforma'    => OnboardingEventoGoogle::PLATAFORMA_NENHUMA,
                'descricao'     => "Pauta\nItem 1",
                'participantes' => [['email' => 'x@y.test']],
            ]))
            ->assertOk();

        Http::assertSent(fn (RequisicaoHttp $r) => $r->method() === 'PATCH'
            && ! array_key_exists('description', $r->data())
            && $r->data()['attendees'][0]['responseStatus'] === 'accepted');
    }

    // ─── Cartão e disponibilidade ───────────────────────────────────────────

    public function test_cartao_do_onboarding_em_json(): void
    {
        Http::fake();
        [$onboarding, $analista] = $this->cenario();
        $this->vinculo($onboarding, $analista);

        $this->actingAs($this->darPermissaoDeOnboarding($analista))
            ->getJson(route('onboarding.agenda.eventos', ['onboarding' => $onboarding->id, 'mes' => '2026-09']))
            ->assertOk()
            ->assertJsonPath('mes', '2026-09')
            ->assertJsonCount(1, 'proximos')
            ->assertJsonStructure(['eventos', 'proximos', 'organizadores', 'organizador_padrao', 'participantes', 'pode_agendar']);

        $this->actingAs($this->userComPermissaoDeOnboarding())
            ->getJson(route('onboarding.agenda.eventos', $onboarding->id))
            ->assertForbidden();
    }

    public function test_disponibilidade_so_abre_a_agenda_de_quem_conduz(): void
    {
        Http::fake(['https://www.googleapis.com/calendar/v3/*' => Http::response(['items' => []])]);
        [$onboarding, $analista, $estrategista] = $this->cenario(['google_estrategista' => true]);
        $colega = User::factory()->create();
        $analista = $this->darPermissaoDeOnboarding($analista);

        $this->actingAs($analista)
            ->getJson(route('onboarding.agenda.disponibilidade', ['onboarding' => $onboarding->id, 'organizador' => $colega->id]))
            ->assertForbidden();

        $this->actingAs($analista)
            ->getJson(route('onboarding.agenda.disponibilidade', ['onboarding' => $onboarding->id, 'organizador' => $estrategista->id]))
            ->assertOk()
            ->assertJsonPath('dono_id', $estrategista->id)
            ->assertJsonPath('e_voce', false);

        Http::assertSent(fn (RequisicaoHttp $r) => $r->hasHeader('Authorization', 'Bearer token-estrategista'));
    }
}
