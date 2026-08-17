<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Agendamento da reunião: o cliente PEDE (sem data), o responsável MARCA data
 * e hora, e o cliente passa a ver.
 *
 * Antes disso `agendar_reuniao_onboarding` era um checkbox interno — o cliente
 * não tinha como pedir a reunião nem como saber quando ela seria.
 */
class OnboardingReuniaoTest extends TestCase
{
    use RefreshDatabase;

    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function engine(): OnboardingEngineService
    {
        return app(OnboardingEngineService::class);
    }

    private function onboardingEmAndamento(?Company $company = null): Onboarding
    {
        $company ??= Company::factory()->create();

        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    // ─── O cliente pede ─────────────────────────────────────────────────────

    #[Test]
    public function cliente_solicita_a_reuniao_pelo_portal_sem_escolher_data(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->post(route('onboarding.publico.reuniao', $link->token), [
            'onboarding_id' => $onboarding->id,
        ])->assertSessionHasNoErrors();

        $onboarding->refresh();

        $this->assertSame(Onboarding::REUNIAO_SOLICITADA, $onboarding->reuniao_status);
        $this->assertNotNull($onboarding->reuniao_solicitada_em);
        $this->assertNull($onboarding->reuniao_agendada_para, 'Quem escolhe a data é o responsável, não o cliente');
    }

    #[Test]
    public function solicitar_duas_vezes_nao_move_nada(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertTrue($this->engine()->solicitarReuniao($onboarding));
        $primeiroPedido = $onboarding->fresh()->reuniao_solicitada_em;

        $this->assertFalse($this->engine()->solicitarReuniao($onboarding->fresh()));
        $this->assertEquals($primeiroPedido, $onboarding->fresh()->reuniao_solicitada_em);
    }

    /**
     * O caso que apagaria informação: cliente clica de novo depois de a data
     * já estar marcada. Rebaixar para `solicitada` sumiria com a data da tela
     * dele.
     */
    #[Test]
    public function pedido_do_cliente_nao_rebaixa_uma_reuniao_ja_agendada(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $quando = now()->addDays(3)->setTime(14, 30);
        $this->engine()->agendarReuniao($onboarding, $quando, User::factory()->create());

        $this->assertFalse($this->engine()->solicitarReuniao($onboarding->fresh()));

        $onboarding->refresh();
        $this->assertSame(Onboarding::REUNIAO_AGENDADA, $onboarding->reuniao_status);
        $this->assertEquals($quando->format('Y-m-d H:i'), $onboarding->reuniao_agendada_para->format('Y-m-d H:i'));
    }

    #[Test]
    public function rascunho_nao_aceita_pedido_de_reuniao(): void
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => Company::factory()->create()->id]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertFalse($this->engine()->solicitarReuniao($onboarding));
        $this->assertNull($onboarding->fresh()->reuniao_status);
    }

    /**
     * Token válido não pode virar chave para o onboarding de outra empresa só
     * trocando o id no corpo do request.
     */
    #[Test]
    public function token_de_uma_empresa_nao_solicita_reuniao_de_outra(): void
    {
        $minha = $this->onboardingEmAndamento();
        $alheia = $this->onboardingEmAndamento();

        $link = app(OnboardingLinkService::class)->paraEmpresa($minha->company);

        $this->post(route('onboarding.publico.reuniao', $link->token), [
            'onboarding_id' => $alheia->id,
        ])->assertSessionHasErrors('onboarding_id');

        $this->assertNull($alheia->fresh()->reuniao_status);
    }

    // ─── O responsável marca ────────────────────────────────────────────────

    #[Test]
    public function responsavel_agenda_data_e_hora_e_o_cliente_passa_a_ver(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $admin = User::factory()->create(['role' => 'admin']);
        $quando = now()->addDays(5)->setTime(10, 0);

        $this->actingAs($admin)
            ->post(route('onboarding.reuniao.agendar', $onboarding), [
                'reuniao_agendada_para' => $quando->toDateTimeString(),
            ])->assertSessionHasNoErrors();

        $onboarding->refresh();
        $this->assertSame(Onboarding::REUNIAO_AGENDADA, $onboarding->reuniao_status);
        $this->assertSame($admin->id, $onboarding->reuniao_agendada_por);

        $reunioes = app(OnboardingLinkService::class)->reunioesDaEmpresa($onboarding->company);

        $this->assertCount(1, $reunioes);
        $this->assertSame('agendada', $reunioes[0]['status']);
        $this->assertNotNull($reunioes[0]['agendada_para']);
    }

    #[Test]
    public function remarcar_troca_a_data_e_mantem_agendada(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $por = User::factory()->create();

        $this->engine()->agendarReuniao($onboarding, now()->addDays(2)->setTime(9, 0), $por);
        $nova = now()->addDays(9)->setTime(16, 0);
        $this->engine()->agendarReuniao($onboarding->fresh(), $nova, $por);

        $onboarding->refresh();
        $this->assertSame(Onboarding::REUNIAO_AGENDADA, $onboarding->reuniao_status);
        $this->assertEquals($nova->format('Y-m-d H:i'), $onboarding->reuniao_agendada_para->format('Y-m-d H:i'));
    }

    #[Test]
    public function nao_se_agenda_reuniao_de_onboarding_em_rascunho(): void
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => Company::factory()->create()->id]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        $this->engine()->agendarReuniao($onboarding, now()->addDay(), User::factory()->create());
    }

    // ─── "Aconteceu?" continua sendo do PASSO ───────────────────────────────

    /**
     * `reuniao_status` tem dois valores, não três. Quem responde "a reunião
     * aconteceu?" é o passo `reuniao_realizada` — duplicar isso numa coluna
     * criaria duas versões da mesma verdade.
     */
    #[Test]
    public function realizada_vem_do_passo_e_nao_de_um_terceiro_status(): void
    {
        $this->assertSame(
            ['solicitada', 'agendada'],
            Onboarding::REUNIAO_STATUSES,
            'Não pode existir um terceiro estado que duplique o passo'
        );

        $onboarding = $this->onboardingEmAndamento();
        $this->engine()->agendarReuniao($onboarding, now()->addDay(), User::factory()->create());

        $reunioes = app(OnboardingLinkService::class)->reunioesDaEmpresa($onboarding->company);
        $this->assertFalse($reunioes[0]['realizada']);

        $onboarding->passos()
            ->where('chave', 'reuniao_realizada')
            ->update(['status' => OnboardingPasso::STATUS_CONCLUIDO]);

        $reunioes = app(OnboardingLinkService::class)->reunioesDaEmpresa($onboarding->company);
        $this->assertTrue($reunioes[0]['realizada']);
    }

    // ─── Payload do portal ──────────────────────────────────────────────────

    #[Test]
    public function workspace_entrega_as_reunioes_junto_com_os_passos(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->get(route('onboarding.publico.workspace', $link->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Publico')
                ->has('reunioes', 1)
                ->where('reunioes.0.onboarding_id', $onboarding->id)
                ->where('reunioes.0.status', null)
            );
    }

    /** T-135-11-02 — o portal não conta ao cliente quem, do nosso lado, marcou. */
    #[Test]
    public function payload_do_cliente_nao_expoe_quem_agendou(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->engine()->agendarReuniao($onboarding, now()->addDay(), User::factory()->create());

        $reuniao = app(OnboardingLinkService::class)->reunioesDaEmpresa($onboarding->company)[0];

        $this->assertArrayNotHasKey('reuniao_agendada_por', $reuniao);
        $this->assertArrayNotHasKey('agendada_por', $reuniao);
    }

    #[Test]
    public function onboarding_em_rascunho_nao_aparece_no_portal(): void
    {
        $company = Company::factory()->create();

        ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $this->assertSame([], app(OnboardingLinkService::class)->reunioesDaEmpresa($company));
    }
}
