<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingFicha;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ficha da conta — as 7 informações de "Métricas e situação da conta"
 * declaradas pelo cliente, pelas duas portas (link público e painel interno).
 *
 * O que estes testes protegem, em ordem de importância:
 *  1. `null` é "não respondido" e NUNCA vira `false`/`0` no banco.
 *  2. A procedência (`cliente` × `equipe`) é gravada — sem ela, os dois lados
 *     viram a mesma coisa e a comparação futura com a API perde sentido.
 *  3. O passo só fecha porque EXISTE ficha; ninguém marca "feito" na mão.
 */
class OnboardingFichaContaTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Empresa com onboarding de Gestão em `andamento` (o passo da ficha já existe e está aberto). */
    private function empresaComOnboardingEmAndamento(): Company
    {
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $engine = app(OnboardingEngineService::class);
        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $company->fresh();
    }

    private function passoDaFicha(Company $company): OnboardingPasso
    {
        return OnboardingPasso::whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id))
            ->where('chave', 'ficha_conta_preenchida')
            ->firstOrFail();
    }

    private function tokenPublico(Company $company): string
    {
        return app(OnboardingLinkService::class)->paraEmpresa($company)->token;
    }

    /** @return array<string, mixed> As 7 respostas completas. */
    private function respostasCompletas(): array
    {
        return [
            'faturamento_3_meses'       => 150000.50,
            'marketplace'               => 'Mercado Livre',
            'full_ativo'                => true,
            'full_pontuacao'            => 87,
            'reputacao_verde'           => true,
            'medalha_atual'             => 'Prata',
            'objetivos_proxima_medalha' => 'Aumentar faturamento e reduzir cancelamentos.',
        ];
    }

    // ─── O passo nasce no lugar certo da definição ───────────────────────────

    #[Test]
    public function passo_da_ficha_nasce_no_comeco_dono_cliente_e_verificado_pelo_sistema(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $passo = $this->passoDaFicha($company);

        // Posição exata não é o ponto — passos entram no meio da definição. O
        // que importa é que a ficha vem CEDO (antes dos acessos, quando o
        // sistema ainda não consegue buscar nada sozinho).
        $this->assertLessThan(
            OnboardingPasso::whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id))
                ->where('chave', 'grant_sistema_ecf')->value('ordem'),
            $passo->ordem,
        );
        $this->assertSame(OnboardingPasso::DONO_CLIENTE, $passo->dono);
        $this->assertSame(OnboardingPasso::AUTO_FONTE_FICHA_CONTA, $passo->auto_fonte);
        $this->assertNull($passo->depende_de, 'A ficha não depende de nada — é o começo do fluxo.');
    }

    #[Test]
    public function onboarding_de_gestao_tem_todos_os_passos_da_definicao(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        $total = OnboardingPasso::whereHas('onboarding', fn ($q) => $q->where('company_id', $company->id))->count();

        // Lido da própria definição — acrescentar um passo não pode quebrar um
        // teste que não fala sobre contagem.
        $esperado = count(\App\Support\Onboarding\DefinicaoOnboarding::paraServico($this->servicoDeGestao()));
        $this->assertSame($esperado, $total);
    }

    // ─── Porta 1: o cliente preenche pelo link público ───────────────────────

    #[Test]
    public function cliente_preenche_pelo_link_publico_e_a_procedencia_fica_cliente(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        $response = $this->post(route('onboarding.publico.ficha-conta', $token), $this->respostasCompletas());

        $response->assertRedirect();

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(OnboardingFicha::ORIGEM_CLIENTE, $ficha->origem);
        $this->assertNull($ficha->preenchida_por, 'Não há usuário logado na porta pública.');
        $this->assertSame(87, $ficha->full_pontuacao);
        $this->assertTrue($ficha->reputacao_verde);
    }

    #[Test]
    public function token_inexistente_devolve_404_e_nao_cria_ficha(): void
    {
        $this->post(route('onboarding.publico.ficha-conta', str_repeat('z', 48)), $this->respostasCompletas())
            ->assertNotFound();

        $this->assertSame(0, OnboardingFicha::count());
    }

    // ─── Porta 2: a equipe preenche pelo painel, em call ─────────────────────

    #[Test]
    public function equipe_preenche_pelo_painel_e_a_procedencia_registra_quem_foi(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $analista = $this->admin();

        $response = $this->actingAs($analista)
            ->post(route('onboarding.ficha-conta.salvar', $company), $this->respostasCompletas());

        $response->assertRedirect();

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(OnboardingFicha::ORIGEM_EQUIPE, $ficha->origem);
        $this->assertSame($analista->id, $ficha->preenchida_por);
    }

    #[Test]
    public function usuario_sem_acesso_a_empresa_recebe_403(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $forasteiro = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($forasteiro)
            ->post(route('onboarding.ficha-conta.salvar', $company), $this->respostasCompletas())
            ->assertForbidden();

        $this->assertSame(0, OnboardingFicha::count());
    }

    // ─── "Não respondido" nunca vira "não" ───────────────────────────────────

    #[Test]
    public function campo_omitido_fica_null_e_nao_vira_false(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        // Cliente responde só o que sabe: nada de Full nem de medalha.
        $this->post(route('onboarding.publico.ficha-conta', $token), [
            'faturamento_3_meses' => 90000,
            'marketplace'         => 'Mercado Livre',
        ])->assertRedirect();

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();

        $this->assertNull($ficha->full_ativo, 'Pergunta em branco não é "não tem Full".');
        $this->assertNull($ficha->reputacao_verde);
        $this->assertNull($ficha->full_pontuacao);
        $this->assertNull($ficha->medalha_atual);

        // A distinção precisa sobreviver à ida ao banco, não só ao model.
        $cru = DB::table('onboarding_fichas')->where('company_id', $company->id)->first();
        $this->assertNull($cru->full_ativo);
        $this->assertNull($cru->reputacao_verde);
    }

    #[Test]
    public function false_explicito_e_gravado_como_false_e_nao_confundido_com_ausencia(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        $this->post(route('onboarding.publico.ficha-conta', $token), [
            'full_ativo'      => false,
            'reputacao_verde' => false,
        ])->assertRedirect();

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();

        $this->assertFalse($ficha->full_ativo);
        $this->assertFalse($ficha->reputacao_verde);
        $this->assertNotNull($ficha->full_ativo, '"Não tenho Full" é resposta, não ausência de resposta.');
    }

    #[Test]
    public function respondidas_conta_so_o_que_foi_de_fato_respondido(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        $this->post(route('onboarding.publico.ficha-conta', $token), [
            'faturamento_3_meses' => 90000,
            'marketplace'         => 'Mercado Livre',
            'full_ativo'          => false,
        ])->assertRedirect();

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();

        $this->assertSame(3, $ficha->respondidas());
    }

    // ─── O passo fecha por existir ficha, nunca na mão ───────────────────────

    #[Test]
    public function passo_da_ficha_fecha_sozinho_quando_a_ficha_chega(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        $this->assertNotSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passoDaFicha($company)->status,
            'Sem ficha, o passo não pode estar concluído.'
        );

        $this->post(route('onboarding.publico.ficha-conta', $this->tokenPublico($company)), $this->respostasCompletas())
            ->assertRedirect();

        $passo = $this->passoDaFicha($company);
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertSame(OnboardingFicha::ORIGEM_CLIENTE, $passo->valor['origem']);
        $this->assertSame(7, $passo->valor['respondidas']);
    }

    #[Test]
    public function ficha_parcial_tambem_fecha_o_passo_e_registra_o_que_ficou_em_branco(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        $this->post(route('onboarding.publico.ficha-conta', $this->tokenPublico($company)), [
            'faturamento_3_meses' => 90000,
        ])->assertRedirect();

        $passo = $this->passoDaFicha($company);

        // Exigir as 7 travaria o onboarding numa pergunta que o cliente pode
        // legitimamente não saber — as em branco viajam no valor, visíveis.
        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);
        $this->assertSame(1, $passo->valor['respondidas']);
        $this->assertContains('full_pontuacao', $passo->valor['nao_respondidas']);
        $this->assertContains('objetivos_proxima_medalha', $passo->valor['nao_respondidas']);
    }

    #[Test]
    public function ninguem_fecha_o_passo_da_ficha_na_mao(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $passo = $this->passoDaFicha($company);

        $this->expectException(\DomainException::class);
        app(OnboardingEngineService::class)->concluirManualmente($passo, $this->admin());
    }

    // ─── Reenvio sobrescreve, inclusive a procedência ────────────────────────

    #[Test]
    public function equipe_completando_depois_do_cliente_assume_a_procedencia(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();
        $analista = $this->admin();

        $this->post(route('onboarding.publico.ficha-conta', $this->tokenPublico($company)), [
            'faturamento_3_meses' => 90000,
        ])->assertRedirect();

        $this->actingAs($analista)
            ->post(route('onboarding.ficha-conta.salvar', $company), $this->respostasCompletas())
            ->assertRedirect();

        $this->assertSame(1, OnboardingFicha::where('company_id', $company->id)->count(), 'Uma ficha por empresa.');

        $ficha = OnboardingFicha::where('company_id', $company->id)->firstOrFail();
        $this->assertSame(OnboardingFicha::ORIGEM_EQUIPE, $ficha->origem);
        $this->assertSame($analista->id, $ficha->preenchida_por);
        $this->assertSame(7, $ficha->respondidas());
    }

    // ─── Validação ───────────────────────────────────────────────────────────

    #[Test]
    public function pontuacao_do_full_negativa_e_recusada(): void
    {
        $company = $this->empresaComOnboardingEmAndamento();

        $this->post(route('onboarding.publico.ficha-conta', $this->tokenPublico($company)), [
            'full_pontuacao' => -5,
        ])->assertSessionHasErrors('full_pontuacao');

        $this->assertSame(0, OnboardingFicha::count());
    }

    #[Test]
    public function painel_interno_ve_a_procedencia_que_o_portal_publico_esconde(): void
    {
        $this->withoutVite();
        $company = $this->empresaComOnboardingEmAndamento();
        $analista = $this->admin();
        $onboarding = Onboarding::where('company_id', $company->id)->firstOrFail();

        $this->actingAs($analista)
            ->post(route('onboarding.ficha-conta.salvar', $company), $this->respostasCompletas())
            ->assertRedirect();

        $this->actingAs($analista)
            ->get(route('onboarding.painel.show', $onboarding))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Detalhe', false)
                ->where('ficha_conta.origem', OnboardingFicha::ORIGEM_EQUIPE)
                ->where('ficha_conta.preenchida_por', $analista->name)
                ->where('ficha_conta.respondidas', 7)
                ->etc()
            );
    }

    // ─── O portal do cliente não vaza operação interna ───────────────────────

    #[Test]
    public function payload_publico_da_ficha_nao_expoe_procedencia_nem_ip(): void
    {
        $this->withoutVite();
        $company = $this->empresaComOnboardingEmAndamento();
        $token = $this->tokenPublico($company);

        $this->actingAs($this->admin())
            ->post(route('onboarding.ficha-conta.salvar', $company), $this->respostasCompletas())
            ->assertRedirect();

        $this->get(route('onboarding.publico.workspace', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Publico', false)
                ->has('ficha_conta.respostas')
                ->where('ficha_conta.respondidas', 7)
                ->missing('ficha_conta.origem')
                ->missing('ficha_conta.preenchida_por')
                ->missing('ficha_conta.ip')
                ->etc()
            );
    }
}
