<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingFicha;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingFichaService;
use App\Services\Onboarding\OnboardingResolverResultado;
use App\Services\Onboarding\RelatorioInicialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Relatório inicial (PDF §3) — o documento apresentado na reunião de
 * onboarding, com seis seções.
 *
 * A divisão do trabalho é o que estes testes protegem:
 *  - o sistema monta o FACTUAL (cenário, métricas, estrutura) do que já sabe;
 *  - o analista escreve o JULGAMENTO (atenção, oportunidades, próximos passos);
 *  - o passo só fecha com as três seções escritas — retrato de dados sozinho
 *    não é relatório;
 *  - declarado e apurado andam LADO A LADO, nunca fundidos: a divergência é a
 *    informação.
 */
class OnboardingRelatorioInicialTest extends TestCase
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

    private function onboardingEmAndamento(): Onboarding
    {
        $company = Company::factory()->create(['name' => 'Empresa do Relatório']);
        $contrato = ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);

        $engine = app(OnboardingEngineService::class);
        $onboarding = $engine->criarParaContrato($contrato);
        $engine->confirmarResponsavel($onboarding, User::factory()->create());

        return $onboarding->fresh();
    }

    private function passo(Onboarding $onboarding, string $chave): OnboardingPasso
    {
        return OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', $chave)
            ->firstOrFail();
    }

    /** Deixa o mapeamento pronto: métricas apuradas + acervo contado. */
    private function comMapeamentoConcluido(Onboarding $onboarding): void
    {
        $engine = app(OnboardingEngineService::class);

        $engine->aplicarResultado($this->passo($onboarding, 'metricas_da_conta'), OnboardingResolverResultado::concluido([
            'nickname'            => 'LOJA_TESTE',
            'faturamento_3_meses' => 120000.0,
            'full'                => true,
            'reputacao'           => ['level_id' => '5_green', 'power_seller_status' => 'platinum'],
            'nao_obtidos'         => ['medalha'],
        ]));

        $engine->aplicarResultado($this->passo($onboarding, 'anuncios_ativos_inativos'), OnboardingResolverResultado::concluido([
            'ativos'   => 340,
            'inativos' => 12,
        ]));
    }

    // ─── O passo entra na definição no lugar certo ───────────────────────────

    #[Test]
    public function onboarding_de_gestao_passa_a_ter_15_passos_com_o_relatorio_antes_da_reuniao(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertSame(15, $onboarding->passos()->count());

        $relatorio = $this->passo($onboarding, 'relatorio_inicial');
        $this->assertSame(OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL, $relatorio->auto_fonte);
        $this->assertSame(['metricas_da_conta', 'anuncios_ativos_inativos'], $relatorio->depende_de);

        // A reunião não acontece sem o documento que ela existe para apresentar.
        $this->assertContains('relatorio_inicial', $this->passo($onboarding, 'reuniao_realizada')->depende_de);
    }

    // ─── O sistema monta o factual ───────────────────────────────────────────

    #[Test]
    public function gerar_monta_cenario_metricas_e_estrutura_do_que_o_sistema_ja_sabe(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);

        $relatorio = app(RelatorioInicialService::class)->gerar($onboarding, $this->admin());
        $dados = $relatorio->dados;

        $this->assertSame('Empresa do Relatório', $dados['cenario']['empresa']);
        $this->assertSame('LOJA_TESTE', $dados['cenario']['nickname_ml']);
        $this->assertSame(340, $dados['estrutura']['anuncios_ativos']);
        $this->assertSame(12, $dados['estrutura']['anuncios_inativos']);
        $this->assertSame('5_green', $dados['metricas']['reputacao']['apurado_level']);

        // O que a API não devolveu vem marcado, nunca como zero.
        $this->assertContains('medalha', $dados['metricas']['nao_obtidos']);
    }

    #[Test]
    public function declarado_e_apurado_ficam_lado_a_lado_e_a_divergencia_sobrevive(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);

        // O cliente declarou faturamento MUITO acima do apurado e disse não ter Full.
        app(OnboardingFichaService::class)->registrar(
            company: $onboarding->company,
            dados: ['faturamento_3_meses' => 500000, 'full_ativo' => false, 'reputacao_verde' => true],
            origem: OnboardingFicha::ORIGEM_CLIENTE,
        );

        $dados = app(RelatorioInicialService::class)->gerar($onboarding)->dados;

        // assertEquals, não assertSame: `dados` é JSON, e valor redondo volta do
        // banco como int (500000), não float (500000.0). O que importa aqui é o
        // número, e o snapshot não deve depender do tipo que o JSON escolheu.
        $this->assertEquals(500000, $dados['metricas']['faturamento_3_meses']['declarado']);
        $this->assertEquals(120000, $dados['metricas']['faturamento_3_meses']['apurado']);
        $this->assertFalse($dados['metricas']['full_ativo']['declarado']);
        $this->assertTrue($dados['metricas']['full_ativo']['apurado']);
    }

    #[Test]
    public function relatorio_sai_mesmo_com_mapeamento_incompleto(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        // Sem métricas, sem acervo — o relatório precisa sair assim mesmo.
        $dados = app(RelatorioInicialService::class)->gerar($onboarding)->dados;

        $this->assertNull($dados['estrutura']['anuncios_ativos']);
        $this->assertNull($dados['metricas']['faturamento_3_meses']['apurado']);
        $this->assertSame('Empresa do Relatório', $dados['cenario']['empresa']);
    }

    // ─── O passo só fecha com o julgamento escrito ───────────────────────────

    #[Test]
    public function retrato_de_dados_sozinho_nao_fecha_o_passo(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('onboarding.relatorio.gerar', $onboarding))
            ->assertRedirect();

        $this->assertNotSame(
            OnboardingPasso::STATUS_CONCLUIDO,
            $this->passo($onboarding, 'relatorio_inicial')->status,
            'Relatório sem análise escrita não é relatório.'
        );
    }

    #[Test]
    public function as_tres_secoes_escritas_fecham_o_passo(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('onboarding.relatorio.gerar', $onboarding));

        $this->actingAs($admin)->put(route('onboarding.relatorio.salvar', $onboarding), [
            'pontos_atencao'  => 'Reputação amarela em risco.',
            'oportunidades'   => 'Ativar Full na linha principal.',
            'proximos_passos' => 'Revisar 12 anúncios inativos.',
        ])->assertRedirect();

        $this->assertSame(OnboardingPasso::STATUS_CONCLUIDO, $this->passo($onboarding, 'relatorio_inicial')->status);
    }

    #[Test]
    public function duas_secoes_de_tres_nao_bastam(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('onboarding.relatorio.gerar', $onboarding));
        $this->actingAs($admin)->put(route('onboarding.relatorio.salvar', $onboarding), [
            'pontos_atencao' => 'Algo',
            'oportunidades'  => 'Outra coisa',
        ])->assertRedirect();

        $passo = $this->passo($onboarding, 'relatorio_inicial');
        $this->assertNotSame(OnboardingPasso::STATUS_CONCLUIDO, $passo->status);

        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->firstOrFail();
        $this->assertSame(['proximos_passos'], $relatorio->secoesPendentes());
    }

    #[Test]
    public function ninguem_fecha_o_relatorio_na_mao(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->expectException(\DomainException::class);
        app(OnboardingEngineService::class)->concluirManualmente(
            $this->passo($onboarding, 'relatorio_inicial'),
            $this->admin(),
        );
    }

    // ─── Regerar preserva o que a pessoa escreveu ────────────────────────────

    #[Test]
    public function regerar_atualiza_os_dados_e_preserva_o_texto_do_analista(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);
        $admin = $this->admin();
        $service = app(RelatorioInicialService::class);

        $service->gerar($onboarding, $admin);
        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->firstOrFail();
        $relatorio->update([
            'pontos_atencao'  => 'Texto que não pode sumir.',
            'oportunidades'   => 'Nem este.',
            'proximos_passos' => 'Nem este outro.',
        ]);

        // O acervo mudou; o relatório é regerado.
        app(OnboardingEngineService::class)->aplicarResultado(
            $this->passo($onboarding, 'anuncios_ativos_inativos'),
            OnboardingResolverResultado::concluido(['ativos' => 999, 'inativos' => 0]),
        );
        $service->gerar($onboarding, $admin);

        $relatorio->refresh();
        $this->assertSame(999, $relatorio->dados['estrutura']['anuncios_ativos'], 'O factual acompanha o dado novo.');
        $this->assertSame('Texto que não pode sumir.', $relatorio->pontos_atencao);
        $this->assertTrue($relatorio->completo());
    }

    #[Test]
    public function salvar_antes_de_gerar_nao_da_erro_e_gera_junto(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->actingAs($this->admin())
            ->put(route('onboarding.relatorio.salvar', $onboarding), ['pontos_atencao' => 'Escrevi antes de gerar.'])
            ->assertRedirect();

        $relatorio = OnboardingRelatorio::where('onboarding_id', $onboarding->id)->firstOrFail();
        $this->assertNotEmpty($relatorio->dados);
        $this->assertSame('Escrevi antes de gerar.', $relatorio->pontos_atencao);
    }

    // ─── Escopo ──────────────────────────────────────────────────────────────

    #[Test]
    public function usuario_fora_da_carteira_nao_gera_relatorio(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $forasteiro = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($forasteiro)
            ->post(route('onboarding.relatorio.gerar', $onboarding))
            ->assertForbidden();

        $this->assertSame(0, OnboardingRelatorio::count());
    }
}
