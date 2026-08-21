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

    // ─── O cliente NÃO pede ─────────────────────────────────────────────────
    //
    // Até 19/08 o portal tinha um botão "Solicitar reunião" e o cliente
    // disparava `POST /onboarding-cliente/{token}/reuniao`. O negócio derrubou
    // o fluxo: quem define data e hora somos nós, e a partir daí cobramos a
    // presença dele. Os testes de solicitação saíram junto com a rota, o
    // método do controller e `OnboardingEngineService::solicitarReuniao()`.
    //
    // O que ficou de pé é a constante `REUNIAO_SOLICITADA` e a coluna
    // `reuniao_solicitada_em`: há linhas em produção nesse estado e o painel
    // interno ainda sabe desenhá-las. O teste abaixo é o que impede a rota de
    // voltar por descuido.

    #[Test]
    public function nao_existe_rota_para_o_cliente_pedir_reuniao(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('onboarding.publico.reuniao'),
            'O cliente não pede reunião: quem define a data somos nós (decisão de 19/08).'
        );

        $this->assertFalse(
            method_exists(\App\Services\Onboarding\OnboardingEngineService::class, 'solicitarReuniao'),
            'Sem produtor de REUNIAO_SOLICITADA — a constante segue só para ler dado antigo.'
        );
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

        $this->get(route('portal.onboarding', $link->token))
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
