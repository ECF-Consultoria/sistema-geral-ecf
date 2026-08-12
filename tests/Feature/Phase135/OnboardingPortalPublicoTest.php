<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingLink;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Database\Seeders\OnboardingTemplateGestaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 135 Plano 11 — link único por EMPRESA (D-06) e agregação por `chave`
 * (D-10) do portal público do cliente. Task 1 cobre
 * `OnboardingLinkService` + a rota interna de geração de link; Task 2
 * acrescenta `workspace()`/`marcarFeito()`/`anexarFicha()` do
 * `OnboardingPublicoController` a esta mesma suíte.
 */
class OnboardingPortalPublicoTest extends TestCase
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

    private function linkService(): OnboardingLinkService
    {
        return app(OnboardingLinkService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Onboarding de Gestão em `andamento` para $company (13 passos, seeder idempotente). */
    private function onboardingDeGestaoEmAndamento(Company $company): Onboarding
    {
        (new OnboardingTemplateGestaoSeeder())->run();
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
        (new OnboardingTemplateGestaoSeeder())->run();
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

        $template = OnboardingTemplate::create([
            'servico_id'   => $servico->id,
            'versao'       => 1,
            'ativo'        => true,
            'publicado_em' => now(),
        ]);

        TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => $chave,
            'titulo'      => 'Passo sintético ' . $chave,
            'dono'        => TemplatePasso::DONO_CLIENTE,
            'auto_fonte'  => $autoFonte,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
            'status'      => Onboarding::STATUS_ANDAMENTO,
            'iniciado_em' => now(),
        ]);

        $this->engine()->montarPassos($onboarding);

        OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->update(['status' => $statusPasso, 'disponivel_em' => now()]);

        return $onboarding->fresh();
    }

    // ─── paraEmpresa() / rota interna de geração de link ────────────────────

    #[Test]
    public function post_link_cria_um_onboarding_link_com_token_de_48_caracteres(): void
    {
        $company = Company::factory()->create();

        $response = $this->actingAs($this->admin())->post(route('onboarding.link.gerar', $company));

        $response->assertRedirect();
        $this->assertSame(1, OnboardingLink::where('company_id', $company->id)->count());
        $link = OnboardingLink::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(48, strlen($link->token));
    }

    #[Test]
    public function chamar_gerar_link_duas_vezes_mantem_um_unico_token(): void
    {
        $company = Company::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('onboarding.link.gerar', $company));
        $primeiroToken = OnboardingLink::where('company_id', $company->id)->firstOrFail()->token;

        $this->actingAs($admin)->post(route('onboarding.link.gerar', $company));

        $this->assertSame(1, OnboardingLink::where('company_id', $company->id)->count());
        $this->assertSame($primeiroToken, OnboardingLink::where('company_id', $company->id)->firstOrFail()->token);
    }

    // ─── passosDoCliente(): agregação por chave (D-10) ──────────────────────

    #[Test]
    public function passos_do_cliente_agrega_mesma_chave_de_dois_onboardings_ativos_em_um_grupo_so(): void
    {
        $company = Company::factory()->create();
        $this->onboardingSinteticoComPassoCliente($company, 'acesso_colaborador_generico', 'Serviço sintético A', null, OnboardingPasso::STATUS_ABERTO);
        $this->onboardingSinteticoComPassoCliente($company, 'acesso_colaborador_generico', 'Serviço sintético B', null, OnboardingPasso::STATUS_ABERTO);

        $grupos = $this->linkService()->passosDoCliente($company);

        $this->assertCount(1, $grupos);
        $this->assertSame('acesso_colaborador_generico', $grupos[0]['chave']);
        $this->assertCount(2, $grupos[0]['servicos']);
        $this->assertCount(2, $grupos[0]['onboarding_passo_ids']);
    }

    #[Test]
    public function passos_do_cliente_ignora_onboarding_em_rascunho(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmRascunho($company);

        $grupos = $this->linkService()->passosDoCliente($company);

        $this->assertSame([], $grupos);
    }

    #[Test]
    public function passos_do_cliente_so_traz_passos_dono_cliente_do_template_de_gestao(): void
    {
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);

        $grupos = $this->linkService()->passosDoCliente($company);
        $chaves = collect($grupos)->pluck('chave')->sort()->values()->all();

        $this->assertSame(['acesso_colaborador_ml', 'custos_app_ecf', 'grant_sistema_ecf'], $chaves);
    }

    // ─── marcarFeitoPorChave(): conclusão em massa + D-19 ───────────────────

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

    // ─── OnboardingPublicoController::workspace() ───────────────────────────

    #[Test]
    public function get_workspace_com_token_valido_retorna_200_sem_autenticacao(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $response = $this->get(route('onboarding.publico.workspace', $link->token));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Onboarding/Publico', false));
    }

    #[Test]
    public function get_workspace_com_token_inexistente_retorna_404(): void
    {
        $response = $this->get(route('onboarding.publico.workspace', 'token-inexistente-0000'));

        $response->assertNotFound();
    }

    #[Test]
    public function get_workspace_carimba_ultimo_acesso(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);
        $this->assertNull($link->ultimo_acesso);

        $this->get(route('onboarding.publico.workspace', $link->token));

        $this->assertNotNull($link->fresh()->ultimo_acesso);
    }

    #[Test]
    public function props_do_workspace_nao_expoem_dado_operacional_interno(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $response = $this->get(route('onboarding.publico.workspace', $link->token));
        $response->assertOk();

        $response->assertInertia(function ($page) {
            $page->component('Onboarding/Publico', false);
            $json = json_encode($page->toArray());

            $this->assertStringNotContainsString('"responsavel"', $json);
            $this->assertStringNotContainsString('sla_dias', $json);
            $this->assertStringNotContainsString('dias_parado', $json);
        });
    }

    // ─── OnboardingPublicoController::marcarFeito() ─────────────────────────

    #[Test]
    public function patch_passo_com_chave_manual_conclui_o_passo(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $response = $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'custos_app_ecf']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'custos_app_ecf')->firstOrFail();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
    }

    #[Test]
    public function patch_passo_com_chave_de_auto_fonte_devolve_422_e_nao_muda_o_status_d19(): void
    {
        $company = Company::factory()->create();
        $onboarding = $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $statusOriginal = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'grant_sistema_ecf')->firstOrFail()->status;

        $response = $this->patch(route('onboarding.publico.passo', $link->token), ['chave' => 'grant_sistema_ecf']);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('chave');

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'grant_sistema_ecf')->firstOrFail();
        $this->assertSame($statusOriginal, $passo->status);
    }

    // ─── OnboardingPublicoController::anexarFicha() ─────────────────────────

    #[Test]
    public function post_ficha_com_pdf_valido_grava_o_arquivo_e_nao_conclui_o_passo_d16(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $onboarding = $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $arquivo = UploadedFile::fake()->create('ficha-cadastral.pdf', 200, 'application/pdf');

        $response = $this->post(route('onboarding.publico.ficha', $link->token), ['ficha' => $arquivo]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', 'ficha_cliente_recebida')->firstOrFail();
        $this->assertNotSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertSame('ficha-cadastral.pdf', $passo->valor['nome_original']);
        Storage::disk('local')->assertExists($passo->valor['arquivo']);
    }

    #[Test]
    public function post_ficha_com_executavel_e_rejeitado_pela_validacao(): void
    {
        Storage::fake('local');
        $company = Company::factory()->create();
        $this->onboardingDeGestaoEmAndamento($company);
        $link = $this->linkService()->paraEmpresa($company);

        $arquivo = UploadedFile::fake()->create('malware.exe', 50, 'application/x-msdownload');

        $response = $this->post(route('onboarding.publico.ficha', $link->token), ['ficha' => $arquivo]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('ficha');
    }
}
