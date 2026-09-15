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
 * Payload da tela interna de detalhe: endereço do portal, bloco de reunião e
 * `etapa` por passo.
 *
 * O que estes testes protegem: abrir a tela NÃO pode criar token — efeito
 * colateral de leitura —, e desde 15/09/2026 o endereço oferecido é o de LOGIN,
 * nunca uma URL com token. A distinção entre "o cliente não fez" e "o cliente
 * nem viu" passou a vir de `link.acessos`, por pessoa.
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
     *
     * Desde 15/09/2026 o endereço que a tela oferece é o de LOGIN, o mesmo
     * para todo cliente — não existe mais link por empresa para gerar.
     */
    #[Test]
    public function abrir_o_detalhe_nao_cria_link_e_oferece_o_login(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('link.existe', false)
                ->where('link.url', route('portal.entrada'))
            );

        $this->assertSame(0, \App\Models\OnboardingLink::count(), 'Ler a tela não pode gerar token');
    }

    /**
     * Empresa que ainda tem link antigo recebe o MESMO endereço de login. A URL
     * com token não pode voltar à tela: ela seria copiada e mandada ao cliente,
     * e não abre mais nada.
     *
     * Saiu junto o teste de `ultimo_acesso`: ele media a visita pelo link, que
     * deixou de acontecer. Quem entrou e quando está agora em `link.acessos`,
     * por pessoa (ver `DominioDoLinkTest`).
     */
    #[Test]
    public function link_antigo_nao_volta_a_tela_e_a_url_e_a_de_login(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->actingAs($this->admin())
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertDontSee($link->token, false)
            ->assertInertia(fn ($page) => $page
                ->where('link.existe', true)
                ->where('link.url', route('portal.entrada'))
            );
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

    /**
     * O cliente não pede mais reunião (19/08) — mas há linhas em PRODUÇÃO em
     * `solicitada`, gravadas antes da mudança, e o painel interno tem de
     * continuar sabendo lê-las. Por isso o estado é montado direto no modelo:
     * `solicitarReuniao()` não existe mais, e o que este teste protege é a
     * LEITURA do dado antigo, não o fluxo que o produzia.
     */
    #[Test]
    public function pedido_antigo_do_cliente_ainda_aparece_para_quem_opera(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $onboarding->forceFill([
            'reuniao_status'       => Onboarding::REUNIAO_SOLICITADA,
            'reuniao_solicitada_em' => now(),
        ])->save();

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
