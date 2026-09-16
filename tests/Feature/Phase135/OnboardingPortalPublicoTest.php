<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\EntraNoPortal;
use Tests\TestCase;

/**
 * Fase 135 Plano 11 — agregação por `chave` (D-10) do portal do cliente, e o
 * workspace de onboarding que o cliente opera.
 *
 * ### O que mudou em 15/09/2026
 * O link único por empresa (D-06) deixou de abrir o portal: a posse do token
 * não autoriza mais nada, e o cliente entra com o e-mail cadastrado em Acessos
 * do portal. Saíram daqui os casos que descreviam a porta antiga — 404 para
 * token inexistente, carimbo de `ultimo_acesso` no link, token de 48
 * caracteres gerado sob demanda. O que vale agora está em `PortalSemTokenTest`.
 *
 * O workspace, o isolamento do payload e a marcação de passo continuam aqui,
 * pela porta autenticada — são regras do onboarding, não da porta.
 */
class OnboardingPortalPublicoTest extends TestCase
{
    use EntraNoPortal;
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

    private function linkService(): OnboardingLinkService
    {
        return app(OnboardingLinkService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Onboarding de Gestão em `andamento` para $company (14 passos, seeder idempotente). */
    private function onboardingDeGestaoEmAndamento(Company $company): Onboarding
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = $this->engine()->criarParaContrato($contrato);
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    /** Onboarding de Gestão que NUNCA sai de `rascunho` (SC-04). */
    private function onboardingDeGestaoEmRascunho(Company $company): Onboarding
    {
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        return $this->engine()->criarParaContrato($contrato);
    }

    /**
     * Onboarding sintético (fora do template de Gestão) com UM passo
     * `dono=cliente` de chave/status/auto_fonte escolhidos — usado para
     * provar a agregação por chave entre DOIS templates diferentes (D-10),
     * já que a v1 real só tem Gestão para colidir consigo mesma.
     */
    private function onboardingSinteticoComPassoCliente(
        Company $company,
        string $chave,
        string $tituloServico,
        ?string $autoFonte,
        string $statusPasso
    ): Onboarding {
        $servico = Servico::create([
            'nome'          => $tituloServico,
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'status'      => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now(),
        ]);

        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => $chave,
            'titulo'        => 'Passo sintético ' . $chave,
            'dono'          => OnboardingPasso::DONO_CLIENTE,
            'auto_fonte'    => $autoFonte,
            'status'        => $statusPasso,
            'disponivel_em' => now(),
        ]);

        return $onboarding->fresh();
    }

    // ─── A antiga geração de link ───────────────────────────────────────────

    /**
     * Abas abertas antes de 15/09/2026 ainda podem disparar a rota antiga. Ela
     * precisa responder com o endereço de LOGIN — e não pode criar token, nem
     * na primeira chamada nem na segunda.
     */
    #[Test]
    public function gerar_link_nao_cria_token_e_informa_o_endereco_de_login(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        foreach ([1, 2] as $_) {
            $this->actingAs($admin)
                ->post(route('onboarding.link.gerar', $company))
                ->assertRedirect()
                ->assertSessionHas('success', fn ($mensagem) => str_contains($mensagem, route('portal.entrada')));
        }

        $this->assertSame(0, OnboardingLink::where('company_id', $company->id)->count());
    }

    // ─── passosDoPortal(): agregação por chave (D-10) ──────────────────────

    #[Test]
    public function passos_do_cliente_agrega_mesma_chave_de_dois_onboardings_ativos_em_um_grupo_so(): void
    {
        $company = Company::factory()->create();
        $this->onboardingSinteticoComPassoCliente($company, 'acesso_colaborador_ml', 'Serviço sintético A', null, OnboardingPasso::STATUS_ABERTO);
        $this->onboardingSinteticoComPassoCliente($company, 'acesso_colaborador_ml', 'Serviço sintético B', null, OnboardingPasso::STATUS_ABERTO);

        $grupos = $this->linkService()->passosDoPortal($company);

        $this->assertCount(1, $grupos);
        $this->assertSame('acesso_colaborador_ml', $grupos[0]['chave']);
        $this->assertCount(2, $grupos[0]['servicos']);
        $this->assertCount(2, $grupos[0]['onboarding_passo_ids']);
    }

    #[Test]
    public function passos_do_cliente_ignora_onboarding_em_rascunho(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmRascunho($company);

        $grupos = $this->linkService()->passosDoPortal($company);

        $this->assertSame([], $grupos);
    }

    /**
     * 14/09 — a régua do portal deixou de ser `dono=cliente` e passou a ser a
     * lista fechada `DefinicaoOnboarding::CHAVES_NO_PORTAL`, porque o portal
     * virou a tela onde o onboarding é OPERADO (analista e cliente juntos).
     *
     * Este teste é o cadeado dessa lista: passo novo não entra no portal por
     * acidente, e item removido de lá não volta sem alguém decidir.
     */
    #[Test]
    public function portal_traz_exatamente_as_chaves_declaradas_no_catalogo(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $chaves = collect($this->linkService()->passosDoPortal($company))
            ->pluck('chave')->sort()->values()->all();

        $this->assertSame([
            'acesso_colaborador_ml',
            'adman_responsabilidades_alinhadas',
            'adman_uso_explicado',
            'anuncios_ativos_inativos',
            'grant_sistema_ecf',
            'publicidade_investimento_explicado',
            'publicidade_processo_explicado',
            'publicidade_responsabilidades_alinhadas',
        ], $chaves);
    }

    /**
     * O que SAIU do portal em 14/09, e a ausência é o ponto do teste:
     * `ponto_contato_definido` e `participantes_reuniao_cadastrados` eram
     * `dono=cliente` e apareciam para ele, mas são cadastro INTERNO — a ECF
     * preenche com o que o cliente responde na reunião.
     */
    #[Test]
    public function cadastro_interno_de_pessoas_nao_aparece_mais_no_portal(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $chaves = collect($this->linkService()->passosDoPortal($company))->pluck('chave')->all();

        $this->assertNotContains('ponto_contato_definido', $chaves);
        $this->assertNotContains('participantes_reuniao_cadastrados', $chaves);
        $this->assertNotContains('analista_definido', $chaves);
        $this->assertNotContains('reuniao_realizada', $chaves);
    }

    #[Test]
    public function marcar_feito_por_chave_conclui_os_passos_dos_dois_onboardings_ativos_e_devolve_2(): void
    {
        $company = Company::factory()->create();
        $this->onboardingSinteticoComPassoCliente($company, 'chave_compartilhada', 'Serviço sintético A', null, OnboardingPasso::STATUS_ABERTO);
        $this->onboardingSinteticoComPassoCliente($company, 'chave_compartilhada', 'Serviço sintético B', null, OnboardingPasso::STATUS_ABERTO);

        $fechados = $this->linkService()->marcarFeitoPorChave($company, 'chave_compartilhada', '127.0.0.1');

        $this->assertSame(2, $fechados);
        $this->assertSame(
            2,
            OnboardingPasso::where('chave', 'chave_compartilhada')->where('status', OnboardingPasso::STATUS_CONCLUIDO)->count()
        );
    }

    #[Test]
    public function marcar_feito_por_chave_com_auto_fonte_lanca_domain_exception_d19(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $this->expectException(\DomainException::class);
        $this->linkService()->marcarFeitoPorChave($company, 'grant_sistema_ecf', '127.0.0.1');
    }

    // ─── O workspace, pela porta autenticada ────────────────────────────────

    #[Test]
    public function cliente_logado_abre_o_workspace_de_onboarding(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $this->entrarNoPortal($company)
            ->get(route('portal.auth.onboarding'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Onboarding/Publico', false));
    }

    #[Test]
    public function props_do_workspace_nao_expoem_dado_operacional_interno(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $response = $this->entrarNoPortal($company)->get(route('portal.auth.onboarding'));
        $response->assertOk();

        $response->assertInertia(function ($page) {
            $page->component('Onboarding/Publico', false);
            $json = json_encode($page->toArray());

            $this->assertStringNotContainsString('"responsavel"', $json);
            $this->assertStringNotContainsString('sla_dias', $json);
            $this->assertStringNotContainsString('dias_parado', $json);
        });
    }

    // ─── Marcar passo, pela porta autenticada ───────────────────────────────

    #[Test]
    public function patch_passo_com_chave_manual_conclui_o_passo(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingDeGestaoEmAndamento($company);

        $response = $this->entrarNoPortal($company)
            ->patch(route('portal.auth.onboarding.passo'), ['chave' => 'acesso_colaborador_ml']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'acesso_colaborador_ml')->firstOrFail();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
    }

    #[Test]
    public function patch_passo_com_chave_de_auto_fonte_devolve_erro_e_nao_muda_o_status_d19(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingDeGestaoEmAndamento($company);

        $statusOriginal = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'grant_sistema_ecf')->firstOrFail()->status;

        $response = $this->entrarNoPortal($company)
            ->patch(route('portal.auth.onboarding.passo'), ['chave' => 'grant_sistema_ecf']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('chave');

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'grant_sistema_ecf')->firstOrFail();
        $this->assertSame($statusOriginal, $passo->status);
    }
}
