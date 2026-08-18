<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Payload da tela interna de detalhe: link do cliente (com `ultimo_acesso`),
 * bloco de reunião e `etapa` por passo.
 *
 * O que estes testes protegem: abrir a tela NÃO pode criar token — efeito
 * colateral de leitura —, e a distinção entre "o cliente não fez" e "o cliente
 * nem viu" precisa chegar à tela.
 */
class OnboardingDetalhePayloadTest extends TestCase
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

    private function onboardingEmAndamento(): Onboarding
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => Company::factory()->create()->id]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
        app(OnboardingEngineService::class)->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ─── Link do cliente ────────────────────────────────────────────────────

    /**
     * Abrir a tela é LEITURA. Se o `show()` chamasse `paraEmpresa()` (que é
     * `firstOrCreate`), toda visita criaria token para empresa que talvez
     * nunca receba o link.
     */
    #[Test]
    public function abrir_o_detalhe_nao_cria_link(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('link.existe', false)
                ->where('link.url', null)
            );

        $this->assertSame(0, \App\Models\OnboardingLink::count(), 'Ler a tela não pode gerar token');
    }

    #[Test]
    public function link_existente_chega_com_url_completa(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('link.existe', true)
                ->where('link.url', route('onboarding.publico.workspace', $link->token))
                ->where('link.ultimo_acesso', null)
            );
    }

    /**
     * A informação que separa "não fez" de "nem viu". `ultimo_acesso` já era
     * gravado a cada visita e não era exibido em lugar nenhum.
     */
    #[Test]
    public function ultimo_acesso_do_cliente_chega_a_tela_interna(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        // O cliente abre o portal.
        $this->get(route('onboarding.publico.workspace', $link->token))->assertOk();

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->whereNot('link.ultimo_acesso', null));
    }

    // ─── Reunião ────────────────────────────────────────────────────────────

    #[Test]
    public function bloco_de_reuniao_chega_vazio_quando_ninguem_pediu_nem_marcou(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reuniao.status', null)
                ->where('reuniao.agendada_para', null)
                ->where('reuniao.realizada', false)
            );
    }

    #[Test]
    public function pedido_do_cliente_aparece_para_quem_opera(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        app(OnboardingEngineService::class)->solicitarReuniao($onboarding);

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reuniao.status', 'solicitada')
                ->whereNot('reuniao.solicitada_em', null)
            );
    }

    /** O painel interno PODE saber quem marcou — só o portal do cliente é que não. */
    #[Test]
    public function tela_interna_mostra_quem_marcou_a_reuniao(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $quem = User::factory()->create(['name' => 'Fulana Coordenação']);
        app(OnboardingEngineService::class)->agendarReuniao($onboarding, now()->addDay(), $quem);

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reuniao.status', 'agendada')
                ->where('reuniao.agendada_por', 'Fulana Coordenação')
            );
    }

    // ─── Etapa por passo ────────────────────────────────────────────────────

    #[Test]
    public function todo_passo_chega_com_etapa_para_a_tela_agrupar(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(function ($page) {
                $passos = $page->toArray()['props']['passos'];

                $this->assertNotEmpty($passos);

                foreach ($passos as $passo) {
                    $this->assertNotNull(
                        $passo['etapa'],
                        "O passo {$passo['chave']} chega sem etapa e a tela não teria como agrupá-lo"
                    );
                }

                return $page;
            });
    }
}
