<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingRelatorio;
use App\Models\Servico;
use App\Models\User;
use App\Services\Onboarding\OnboardingEngineService;
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
 *
 * v10 DA DEFINIÇÃO: `relatorio_inicial` deixou de ser passo da régua — o
 * negócio disse que não faz parte do onboarding. A MÁQUINARIA continua de pé
 * (service, resolver, tabela `onboarding_relatorios` e a tela no painel), e
 * continua coberta aqui: o passo passou a ser criado à mão em
 * `onboardingEmAndamento()`, que é exatamente a situação dos onboardings
 * nascidos antes da v10 — os únicos que ainda o carregam.
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

        // Passo do relatório como um onboarding pré-v10 o carrega. `auto_fonte`
        // preservado: é o que garante que ninguém o feche na mão (D-19) e o que
        // o resolver procura para fechá-lo.
        OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 14,
            'etapa'         => OnboardingPasso::ETAPA_AGENDAMENTO,
            'chave'         => 'relatorio_inicial',
            'titulo'        => 'Relatório inicial da empresa',
            'dono'          => OnboardingPasso::DONO_INTERNO,
            'depende_de'    => ['metricas_da_conta', 'anuncios_ativos_inativos'],
            'sla_dias'      => 3,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_RELATORIO_INICIAL,
            'status'        => OnboardingPasso::STATUS_BLOQUEADO,
        ]);

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
    public function o_relatorio_saiu_da_regua_e_nao_trava_mais_a_reuniao(): void
    {
        $chavesDaRegua = collect(\App\Support\Onboarding\DefinicaoOnboarding::paraServico($this->servicoDeGestao()))
            ->pluck('chave')
            ->all();

        $this->assertNotContains('relatorio_inicial', $chavesDaRegua, 'O passo saiu na v10 e não deve voltar');

        // O ponto crítico da remoção: a reunião DEPENDIA do relatório. Se a
        // dependência tivesse ficado, ela nasceria bloqueada para sempre,
        // esperando um passo que não existe mais.
        $onboarding = $this->onboardingEmAndamento();

        $this->assertNotContains(
            'relatorio_inicial',
            $this->passo($onboarding, 'reuniao_realizada')->depende_de ?? [],
        );
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
        $this->assertSame('5_green', $dados['metricas']['reputacao_level']);

        // O que a API não devolveu vem marcado, nunca como zero.
        $this->assertContains('medalha', $dados['metricas']['nao_obtidos']);
    }

    #[Test]
    public function metricas_vem_do_grant_e_o_que_a_api_nao_deu_fica_marcado(): void
    {
        $onboarding = $this->onboardingEmAndamento();
        $this->comMapeamentoConcluido($onboarding);

        $dados = app(RelatorioInicialService::class)->gerar($onboarding)->dados;

        // Tudo vem do grant — o cliente não declara nada.
        $this->assertEquals(120000, $dados['metricas']['faturamento_3_meses']);
        $this->assertTrue($dados['metricas']['full_ativo']);

        // O que a API não devolveu fica visível, nunca vira zero.
        $this->assertContains('medalha', $dados['metricas']['nao_obtidos']);
    }

    #[Test]
    public function relatorio_sai_mesmo_com_mapeamento_incompleto(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        // Sem métricas, sem acervo — o relatório precisa sair assim mesmo.
        $dados = app(RelatorioInicialService::class)->gerar($onboarding)->dados;

        $this->assertNull($dados['estrutura']['anuncios_ativos']);
        $this->assertNull($dados['metricas']['faturamento_3_meses']);
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
