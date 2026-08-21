<?php

namespace Tests\Feature\Phase135;

use App\Jobs\ResolveOnboardingPassoJob;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingMapeamento;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use App\Services\Onboarding\OnboardingMapeamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mapeamento Inicial: sincronizar, ver o apurado, confirmar, e preencher só o
 * que a API não entrega.
 *
 * As duas coisas que estes testes existem para impedir:
 *  - o apurado ser COPIADO para `onboarding_mapeamentos` (duas versões da
 *    verdade);
 *  - "não coletado" virar "zero" na visão que a tela consome (D-11).
 */
class OnboardingMapeamentoTest extends TestCase
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

    private function service(): OnboardingMapeamentoService
    {
        return app(OnboardingMapeamentoService::class);
    }

    private function onboardingEmAndamento(?Company $company = null): Onboarding
    {
        $company ??= Company::factory()->create();

        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $onboarding = Onboarding::where('contrato_servico_id', $contrato->id)->firstOrFail();
        app(OnboardingEngineService::class)->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    /** Deixa os passos do mapeamento concluídos com um apurado conhecido. */
    private function comApurado(Onboarding $onboarding, array $metricas = [], array $acervo = []): void
    {
        $onboarding->passos()->where('chave', 'metricas_da_conta')->update([
            'status' => OnboardingPasso::STATUS_CONCLUIDO,
            'valor'  => json_encode(array_merge([
                'nickname'            => 'LOJA_X',
                'faturamento_3_meses' => 120000.0,
                'full'                => true,
                'reputacao'           => ['level_id' => '5_green', 'power_seller_status' => 'gold'],
                'medalha_conta'       => ['medalha_atual' => 'gold', 'proxima_medalha' => 'platinum', 'bloqueios' => []],
                'medalha_parceiro'    => ['parceiro' => 'ECF Consultoria'],
                'nao_obtidos'         => [],
            ], $metricas)),
        ]);

        $onboarding->passos()->where('chave', 'anuncios_ativos_inativos')->update([
            'status' => OnboardingPasso::STATUS_CONCLUIDO,
            'valor'  => json_encode(array_merge(['ativos' => 140, 'inativos' => 12], $acervo)),
        ]);
    }

    // ─── A visão lê da ÚNICA fonte ──────────────────────────────────────────

    #[Test]
    public function visao_monta_o_apurado_a_partir_do_valor_dos_passos(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);

        $visao = $this->service()->visao($onboarding->fresh());

        $this->assertSame('pronto', $visao['estado']);
        $this->assertSame('LOJA_X', $visao['conta']['nickname']);
        // `assertEquals`, não `assertSame`: `json_encode(120000.0)` grava
        // `120000` e volta como int. Float com casas decimais sobrevive
        // normalmente — o que colapsa é só o float redondo, e o valor
        // numérico é o mesmo dos dois lados.
        $this->assertEquals(120000.0, $visao['conta']['faturamento_3_meses']);
        $this->assertTrue($visao['conta']['full_ativo']);
        $this->assertSame(140, $visao['anuncios']['ativos']);
        $this->assertSame(12, $visao['anuncios']['inativos']);
        $this->assertSame('gold', $visao['conta']['medalha_conta']['medalha_atual']);
        $this->assertSame('ECF Consultoria', $visao['conta']['medalha_parceiro']['parceiro']);
    }

    /**
     * O apurado tem UMA fonte. Copiá-lo para a tabela criaria a pergunta "qual
     * das duas está certa?" seis meses depois.
     */
    #[Test]
    public function apurado_nao_e_copiado_para_a_tabela_de_mapeamento(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);

        $this->service()->confirmar($onboarding->fresh(), OnboardingMapeamento::CANAL_CLIENTE_PORTAL);

        $colunas = \Schema::getColumnListing('onboarding_mapeamentos');

        foreach (['faturamento_3_meses', 'nickname', 'reputacao', 'medalha', 'ativos', 'inativos'] as $proibida) {
            $this->assertNotContains($proibida, $colunas, "O apurado não pode ter cópia em coluna ({$proibida})");
        }
    }

    // ─── "Não coletado" nunca vira zero (D-11) ──────────────────────────────

    #[Test]
    public function passo_em_coleta_devolve_estado_buscando_e_nao_numeros(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $onboarding->passos()->where('chave', 'anuncios_ativos_inativos')->update([
            'status'             => OnboardingPasso::STATUS_AGUARDANDO_COLETA,
            'coleta_iniciada_em' => now(),
        ]);

        $visao = $this->service()->visao($onboarding->fresh());

        $this->assertSame('buscando', $visao['estado']);
        $this->assertNull($visao['anuncios']['ativos'], 'Coleta em voo não pode virar 0 anúncios');
        $this->assertNull($visao['anuncios']['inativos']);
    }

    #[Test]
    public function sonda_indeterminada_nao_e_lida_como_dado_ausente_comum(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $onboarding->passos()->where('chave', 'metricas_da_conta')->update([
            'status' => OnboardingPasso::STATUS_INDETERMINADO,
        ]);

        $this->assertSame('indisponivel', $this->service()->visao($onboarding->fresh())['estado']);
    }

    #[Test]
    public function zero_real_apurado_chega_como_zero_e_nao_como_ausente(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding, acervo: ['ativos' => 0, 'inativos' => 0]);

        $visao = $this->service()->visao($onboarding->fresh());

        $this->assertSame(0, $visao['anuncios']['ativos'], 'Zero apurado de verdade é 0, não null');
        $this->assertSame(0, $visao['anuncios']['inativos']);
    }

    #[Test]
    public function antes_do_grant_o_estado_e_bloqueado(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        // Recém-criado: metricas_da_conta depende do grant e nasce bloqueado.
        $this->assertSame('bloqueado', $this->service()->visao($onboarding)['estado']);
    }

    /**
     * O caso que mentia em produção: um passo `aberto` esperando pré-requisito
     * humano e outro `bloqueado`. Nada em voo — e a tela dizia "Buscando os
     * dados da conta…" para um cliente que ficaria esperando para sempre.
     */
    #[Test]
    public function passo_aberto_sem_coleta_em_voo_nao_diz_que_esta_buscando(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $onboarding->passos()->where('chave', 'anuncios_ativos_inativos')
            ->update(['status' => OnboardingPasso::STATUS_ABERTO]);
        $onboarding->passos()->where('chave', 'metricas_da_conta')
            ->update(['status' => OnboardingPasso::STATUS_BLOQUEADO]);

        $this->assertSame(
            'pendente',
            $this->service()->visao($onboarding->fresh())['estado'],
            'Sem coleta em voo, a tela não pode afirmar que está buscando'
        );
    }

    /**
     * O outro lado do mesmo erro: com os passos ABERTOS (dependência já caiu,
     * prontos para buscar), a tela dizia "Ainda não dá para buscar" e o
     * usuário não tinha o que fazer. `aberto` é justamente o estado em que
     * Sincronizar resolve.
     */
    #[Test]
    public function passos_abertos_sao_pendentes_e_nao_bloqueados(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $onboarding->passos()->whereIn('chave', OnboardingMapeamentoService::CHAVES_APURADAS)
            ->update(['status' => OnboardingPasso::STATUS_ABERTO]);

        $this->assertSame('pendente', $this->service()->visao($onboarding->fresh())['estado']);
    }

    /** Só bloqueado de verdade quando nenhum passo abriu. */
    #[Test]
    public function bloqueado_exige_que_nenhum_passo_tenha_aberto(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $onboarding->passos()->whereIn('chave', OnboardingMapeamentoService::CHAVES_APURADAS)
            ->update(['status' => OnboardingPasso::STATUS_BLOQUEADO]);

        $this->assertSame('bloqueado', $this->service()->visao($onboarding->fresh())['estado']);
    }

    // ─── Sincronizar ────────────────────────────────────────────────────────

    #[Test]
    public function sincronizar_despacha_job_para_os_passos_pendentes(): void
    {
        Queue::fake();

        $onboarding = $this->onboardingEmAndamento();
        $onboarding->passos()->whereIn('chave', OnboardingMapeamentoService::CHAVES_APURADAS)->update([
            'status'     => OnboardingPasso::STATUS_ABERTO,
            'updated_at' => now()->subHour(),
        ]);

        $despachados = $this->service()->sincronizar($onboarding->fresh());

        $this->assertSame(2, $despachados);
        Queue::assertPushed(ResolveOnboardingPassoJob::class, 2);
    }

    /**
     * O portal é público e sem login. Sem cooldown, cliques repetidos
     * empilhariam sondas contra a Adman, que tem ADMAN_RATE_LIMIT_RPM = 10.
     */
    #[Test]
    public function sincronizar_respeita_cooldown_e_nao_empilha_sondas(): void
    {
        Queue::fake();

        $onboarding = $this->onboardingEmAndamento();
        $onboarding->passos()->whereIn('chave', OnboardingMapeamentoService::CHAVES_APURADAS)->update([
            'status'     => OnboardingPasso::STATUS_ABERTO,
            'updated_at' => now(),
        ]);

        $this->assertSame(0, $this->service()->sincronizar($onboarding->fresh()));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function sincronizar_nao_redispara_passo_ja_concluido(): void
    {
        Queue::fake();

        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);
        $onboarding->passos()->update(['updated_at' => now()->subDay()]);

        $this->assertSame(0, $this->service()->sincronizar($onboarding->fresh()));
        Queue::assertNothingPushed();
    }

    // ─── Confirmação: mesma ficha, dois canais ──────────────────────────────

    #[Test]
    public function cliente_confirma_pelo_portal_e_o_canal_fica_registrado(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->post(route('onboarding.publico.mapeamento.confirmar', $link->token), [
            'onboarding_id'  => $onboarding->id,
            'full_pontuacao' => 78,
        ])->assertSessionHasNoErrors();

        $mapa = OnboardingMapeamento::where('onboarding_id', $onboarding->id)->firstOrFail();

        $this->assertSame(OnboardingMapeamento::CANAL_CLIENTE_PORTAL, $mapa->confirmado_canal);
        $this->assertSame(78, $mapa->full_pontuacao);
        $this->assertNotNull($mapa->confirmado_em);
        $this->assertNull($mapa->confirmado_por, 'Não há usuário autenticado no portal — inventar um mentiria');
    }

    #[Test]
    public function equipe_confirma_em_call_e_o_canal_e_outro(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('onboarding.mapeamento.confirmar', $onboarding), ['full_pontuacao' => 55])
            ->assertSessionHasNoErrors();

        $mapa = OnboardingMapeamento::where('onboarding_id', $onboarding->id)->firstOrFail();

        $this->assertSame(OnboardingMapeamento::CANAL_INTERNO_CALL, $mapa->confirmado_canal);
        $this->assertSame($admin->id, $mapa->confirmado_por);
    }

    /** Confirmar de novo sem digitar a pontuação não pode apagar a anterior. */
    #[Test]
    public function reconfirmar_sem_pontuacao_preserva_a_que_ja_estava(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->service()->confirmar($onboarding, OnboardingMapeamento::CANAL_CLIENTE_PORTAL, fullPontuacao: 90);
        $this->service()->confirmar($onboarding, OnboardingMapeamento::CANAL_INTERNO_CALL);

        $this->assertSame(90, OnboardingMapeamento::where('onboarding_id', $onboarding->id)->value('full_pontuacao'));
    }

    #[Test]
    public function pontuacao_fora_de_zero_a_cem_e_rejeitada(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->post(route('onboarding.publico.mapeamento.confirmar', $link->token), [
            'onboarding_id'  => $onboarding->id,
            'full_pontuacao' => 140,
        ])->assertSessionHasErrors('full_pontuacao');
    }

    #[Test]
    public function canal_fora_do_catalogo_e_rejeitado(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->expectException(\DomainException::class);
        $this->service()->confirmar($onboarding, 'telepatia');
    }

    /** Token de uma empresa não confirma mapeamento de outra. */
    #[Test]
    public function token_nao_atravessa_para_outra_empresa(): void
    {
        $minha = $this->onboardingEmAndamento();
        $alheia = $this->onboardingEmAndamento();
        $link = app(OnboardingLinkService::class)->paraEmpresa($minha->company);

        $this->post(route('onboarding.publico.mapeamento.confirmar', $link->token), [
            'onboarding_id' => $alheia->id,
        ])->assertSessionHasErrors('onboarding_id');

        $this->assertSame(0, OnboardingMapeamento::where('onboarding_id', $alheia->id)->count());
    }

    // ─── Payload das telas ──────────────────────────────────────────────────

    #[Test]
    public function portal_do_cliente_entrega_o_mapeamento(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);
        $link = app(OnboardingLinkService::class)->paraEmpresa($onboarding->company);

        $this->get(route('portal.onboarding', $link->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('mapeamentos', 1)
                ->where('mapeamentos.0.estado', 'pronto')
                ->where('mapeamentos.0.onboarding_id', $onboarding->id)
            );
    }

    #[Test]
    public function tela_interna_entrega_o_mapeamento(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comApurado($onboarding);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mapeamento.estado', 'pronto')
                ->where('mapeamento.conta.nickname', 'LOJA_X')
            );
    }
}
