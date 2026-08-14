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
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Criação do Grupo e Boas-vindas (PDF §7) — o documento pede os dois como
 * parte do "acompanhamento inicial da entrada da empresa".
 *
 * São dois passos internos simples, sem tabela nem resolver. O que estes
 * testes protegem é o acoplamento que decidimos NÃO criar: a ficha do cliente
 * não depende da mensagem de boas-vindas. Um checkbox interno esquecido não
 * pode deixar o cliente com a ficha bloqueada sem ninguém entender por quê.
 */
class OnboardingGrupoBoasVindasTest extends TestCase
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
        $company = Company::factory()->create();
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

    #[Test]
    public function os_dois_passos_do_pdf_7_nascem_como_internos_e_manuais(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        foreach (['grupo_criado', 'mensagem_boas_vindas'] as $chave) {
            $passo = $this->passo($onboarding, $chave);
            $this->assertSame(OnboardingPasso::DONO_INTERNO, $passo->dono, "chave={$chave}");
            $this->assertNull($passo->auto_fonte, "chave={$chave} — não há como o sistema verificar isso sozinho.");
        }
    }

    #[Test]
    public function a_mensagem_depende_do_grupo_existir(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertSame(['grupo_criado'], $this->passo($onboarding, 'mensagem_boas_vindas')->depende_de);

        // Grupo aberto, mensagem bloqueada até ele fechar.
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $this->passo($onboarding, 'grupo_criado')->status);
        $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $this->passo($onboarding, 'mensagem_boas_vindas')->status);

        app(OnboardingEngineService::class)->concluirManualmente(
            $this->passo($onboarding, 'grupo_criado'),
            User::factory()->create(),
        );

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $this->passo($onboarding, 'mensagem_boas_vindas')->status);
    }

    /**
     * O acoplamento que NÃO existe, e é de propósito. A mensagem de boas-vindas
     * é o que leva o link ao cliente — seria tentador travar a ficha nela. Mas
     * se alguém esquecer de marcar a mensagem como enviada, o cliente que já
     * recebeu o link ficaria olhando um passo bloqueado sem explicação.
     *
     * @test
     */
    public function ficha_do_cliente_nao_fica_bloqueada_pela_mensagem_de_boas_vindas(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        // Ninguém tocou em grupo nem em boas-vindas.
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $this->passo($onboarding, 'ficha_conta_preenchida')->status);

        $grupos = collect(app(OnboardingLinkService::class)->passosDoCliente($onboarding->company))->keyBy('chave');
        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $grupos['ficha_conta_preenchida']['status']);
    }

    #[Test]
    public function os_dois_passos_sao_internos_e_nao_aparecem_para_o_cliente(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $chaves = collect(app(OnboardingLinkService::class)->passosDoCliente($onboarding->company))
            ->pluck('chave')
            ->all();

        $this->assertNotContains('grupo_criado', $chaves);
        $this->assertNotContains('mensagem_boas_vindas', $chaves);
    }

    #[Test]
    public function definicao_subiu_de_versao_e_onboarding_novo_nasce_com_ela(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertSame(DefinicaoOnboarding::VERSAO, $onboarding->definicao_versao);
        $this->assertSame(
            count(DefinicaoOnboarding::paraServico($this->servicoDeGestao())),
            $onboarding->passos()->count(),
        );
    }
}
