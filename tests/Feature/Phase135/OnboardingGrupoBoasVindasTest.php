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
 * São dois passos internos simples, sem tabela nem resolver. Não têm auto_fonte
 * porque o sistema não consegue verificar sozinho que um grupo foi criado nem
 * que uma mensagem foi enviada — fingir automação ali seria pior que o
 * checkbox honesto.
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
    public function a_mensagem_de_boas_vindas_nasce_interna_e_manual(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $passo = $this->passo($onboarding, 'mensagem_boas_vindas');
        $this->assertSame(OnboardingPasso::DONO_INTERNO, $passo->dono);
        $this->assertNull($passo->auto_fonte, 'Não há como o sistema verificar isso sozinho.');
    }

    /** v7 — o negócio removeu o passo do grupo de WhatsApp do onboarding. */
    #[Test]
    public function o_passo_do_grupo_de_whatsapp_nao_existe_mais(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertNull(
            $onboarding->passos()->where('chave', 'grupo_criado')->first(),
            'grupo_criado saiu da definição na v7'
        );

        $this->assertNotContains(
            'grupo_criado',
            collect(DefinicaoOnboarding::paraServico($this->servicoDeGestao()))->pluck('chave')->all()
        );
    }

    #[Test]
    public function a_mensagem_nao_depende_mais_de_nada(): void
    {
        $onboarding = $this->onboardingEmAndamento();

        $this->assertNull($this->passo($onboarding, 'mensagem_boas_vindas')->depende_de);

        // Sem dependência, nasce aberta com os demais — não fica esperando
        // ninguém fechar um passo que já não existe.
        $this->assertSame(
            OnboardingPasso::STATUS_ABERTO,
            $this->passo($onboarding, 'mensagem_boas_vindas')->status
        );
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
