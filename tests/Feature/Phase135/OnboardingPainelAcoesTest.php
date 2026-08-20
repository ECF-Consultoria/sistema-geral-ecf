<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 135 Plano 09 — Task 1: gate de acesso do painel operacional
 * (`permission:core.onboarding`, distinto do `role:admin` do CRUD de
 * template — D-04). Task 3 acrescenta as duas ações da Coordenação
 * (confirmar responsável / concluir passo manual) a esta mesma suíte.
 */
class OnboardingPainelAcoesTest extends TestCase
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

    /** Onboarding recém-criado em `rascunho`, 14 passos montados (todos `bloqueado`). */
    private function onboardingEmRascunho(?Company $company = null): Onboarding
    {
        $servico = $this->servicoDeGestao();

        $company ??= Company::factory()->create();
        $contrato = ContratoServico::factory()->paraServico($servico)->create(['company_id' => $company->id]);

        return $this->engine()->criarParaContrato($contrato);
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)->where('chave', $chave)->firstOrFail();
    }

    /**
     * Cria um setor com `core.onboarding` concedido em `setor_permissoes` e
     * devolve um user (não-admin) membro dele — mesmo padrão de
     * `tests/Feature/Phase123/PerformanceAutorizacaoTest.php`.
     */
    private function userComPermissaoPainel(): User
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Coordenação Onboarding 135-09',
            'slug'       => 'coordenacao-onboarding-135-09',
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    // ─── Task 1: gate de acesso ─────────────────────────────────────────────

    #[Test]
    public function get_onboarding_sem_permission_e_sem_ser_admin_retorna_403(): void
    {
        $consultor = User::factory()->create(['role' => 'consultor']);

        $response = $this->actingAs($consultor)->get(route('onboarding.painel.index'));

        $response->assertForbidden();
    }

    #[Test]
    public function get_onboarding_com_permission_retorna_200(): void
    {
        $this->withoutVite();
        $user = $this->userComPermissaoPainel();

        $this->assertTrue($user->hasPermission('core.onboarding'), 'setup: user deveria ter core.onboarding via setor_permissoes');

        $response = $this->actingAs($user)->get(route('onboarding.painel.index'));

        $response->assertOk();
    }

    #[Test]
    public function get_onboarding_como_admin_sem_a_permission_explicita_retorna_200(): void
    {
        $this->withoutVite();

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
    }

    // ─── Task 3: confirmar responsável ──────────────────────────────────────

    #[Test]
    public function confirmar_responsavel_move_para_andamento_e_carimba_disponivel_em_nos_passos_sem_dependencia(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $responsavel = User::factory()->create();

        $response = $this->actingAs($this->admin())->post(
            route('onboarding.responsavel.confirmar', $onboarding),
            ['responsavel_id' => $responsavel->id]
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $onboarding->refresh();
        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->status);
        $this->assertSame($responsavel->id, $onboarding->responsavel_id);
        $this->assertNotNull($onboarding->iniciado_em);

        // Os passos sem depende_de destravam na hora. Eram 5 (135-04-PLAN.md);
        // v10 removeu `mensagem_boas_vindas` e `confirmacao_pagamento` da régua.
        foreach (['grant_sistema_ecf', 'planilha_custos_adman', 'custos_app_ecf'] as $chave) {
            $passo = $this->passo($onboarding, $chave);
            $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->status, "passo {$chave} deveria estar aberto");
            $this->assertNotNull($passo->disponivel_em, "passo {$chave} deveria ter disponivel_em carimbado");
        }

        // Passo com dependência continua bloqueado. v17: os dois grants
        // deixaram de depender do OAuth, então quem sobra com dependência é a
        // coleta automática — que de fato só roda depois do grant.
        $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $this->passo($onboarding, 'metricas_da_conta')->status);
    }

    #[Test]
    public function confirmar_responsavel_num_onboarding_ja_em_andamento_devolve_erro_de_validacao_nao_500(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());
        $this->assertSame(Onboarding::STATUS_ANDAMENTO, $onboarding->fresh()->status);

        $response = $this->actingAs($this->admin())->post(
            route('onboarding.responsavel.confirmar', $onboarding),
            ['responsavel_id' => User::factory()->create()->id]
        );

        $response->assertStatus(302);
        $response->assertSessionHasErrors('responsavel_id');
    }

    #[Test]
    public function onboarding_em_rascunho_traz_responsavel_sugerido_quando_ha_vinculo_consultor(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create();
        $onboarding = $this->onboardingEmRascunho($company);

        $consultor = User::factory()->create();
        DB::table('company_users')->insert([
            'company_id' => $company->id,
            'user_id'    => $consultor->id,
            'role'       => 'consultor',
            'servico_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())->get(route('onboarding.painel.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Onboarding/Painel')
            ->where('empresas.0.onboardings.0.responsavel_sugerido.id', $consultor->id)
        );

        // Sugerir não é confirmar (D-05) — o onboarding continua em rascunho.
        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->fresh()->status);
    }

    // ─── Task 3: concluir passo manual ──────────────────────────────────────

    #[Test]
    public function concluir_passo_manual_aberto_conclui_e_grava_feito_por_e_feito_em(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $admin = $this->admin();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        // 'custos_app_ecf': dono=cliente, sem auto_fonte — passo manual, já aberto.
        $passo = $this->passo($onboarding, 'custos_app_ecf');

        $response = $this->actingAs($admin)->post(route('onboarding.passos.concluir', $passo));

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $passo->refresh();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertSame($admin->id, $passo->feito_por);
        $this->assertNotNull($passo->feito_em);
    }

    /**
     * D-19 mudou de forma, não de intenção.
     *
     * A regra original proibia conclusão manual de passo automático em
     * QUALQUER caminho. Na prática isso criou beco sem saída: "Planilha de
     * custos ADMAN" só fecha com `companies.adman_account_id` preenchido, e
     * empresa conectada só por OAuth não tem esse campo — o passo não fechava
     * sozinho nem podia ser fechado à mão, travando tudo que dependia dele.
     *
     * Agora quem opera POR DENTRO pode concluir como override explícito, e
     * isso fica registrado. O portal do CLIENTE continua barrado — a proteção
     * que importa (cliente não fecha o que o sistema deveria confirmar) está
     * coberta por OnboardingEtapasEInstrucoesTest.
     */
    #[Test]
    public function interno_conclui_passo_automatico_como_override_registrado(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        $passo = $this->passo($onboarding, 'planilha_custos_adman');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('onboarding.passos.concluir', $passo))
            ->assertStatus(302)
            ->assertSessionHasNoErrors();

        $fresh = $passo->fresh();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $fresh->status);
        $this->assertTrue($fresh->valor['concluido_manualmente'] ?? false, 'o override precisa ficar registrado');
        $this->assertSame($admin->id, $fresh->valor['override_por'] ?? null);
    }

    /** O caminho de volta que faltava: desmarcar um passo concluído. */
    #[Test]
    public function desmarcar_devolve_o_passo_para_aberto_e_limpa_o_registro(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        $passo = $this->passo($onboarding, 'planilha_custos_adman');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('onboarding.passos.concluir', $passo))->assertSessionHasNoErrors();
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->fresh()->status);

        $this->actingAs($admin)->post(route('onboarding.passos.reabrir', $passo))->assertSessionHasNoErrors();

        $fresh = $passo->fresh();
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $fresh->status);
        $this->assertNull($fresh->feito_por);
        $this->assertNull($fresh->feito_em);
        $this->assertArrayNotHasKey('concluido_manualmente', $fresh->valor ?? []);
    }

    #[Test]
    public function desmarcar_passo_que_nao_esta_concluido_devolve_erro(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());

        $passo = $this->passo($onboarding, 'custos_app_ecf');

        $this->actingAs($this->admin())
            ->post(route('onboarding.passos.reabrir', $passo))
            ->assertInvalid(['passo']);
    }

    // ─── Task 3: escopo de carteira nas ações ───────────────────────────────

    #[Test]
    public function usuario_fora_da_carteira_recebe_403_em_confirmar_responsavel_e_concluir_passo(): void
    {
        $onboarding = $this->onboardingEmRascunho();
        $this->engine()->confirmarResponsavel($onboarding, User::factory()->create());
        $passo = $this->passo($onboarding, 'custos_app_ecf');

        $foraDaCarteira = $this->userComPermissaoPainel();

        $respostaResponsavel = $this->actingAs($foraDaCarteira)->post(
            route('onboarding.responsavel.confirmar', $onboarding),
            ['responsavel_id' => $foraDaCarteira->id]
        );
        $respostaResponsavel->assertForbidden();

        $respostaConcluir = $this->actingAs($foraDaCarteira)->post(route('onboarding.passos.concluir', $passo));
        $respostaConcluir->assertForbidden();
    }
}
