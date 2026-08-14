<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingEngineService;
use App\Support\Onboarding\DefinicaoOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 04 (Task 2) — cobre `OnboardingEngineService::criarParaContrato()`
 * e `montarPassos()`: montagem do onboarding a partir da versão congelada do
 * template, sem rede, com guard de duplicidade em duas camadas.
 */
class OnboardingEngineMontagemTest extends TestCase
{
    use RefreshDatabase;

    /** O Servico "Gestão" real, publicado pelas migrations do catálogo. */
    private function servicoDeGestao(): Servico
    {
        return Servico::query()
            ->where('ativo', true)
            ->where('setor', Servico::SETOR_PERFORMANCE)
            ->where('nome', 'like', '%Gestão%')
            ->firstOrFail();
    }

    private function contratoDeGestao(): ContratoServico
    {
        $company = Company::factory()->create();

        return ContratoServico::factory()
            ->paraServico($this->servicoDeGestao())
            ->create(['company_id' => $company->id]);
    }

    /** @test */
    public function contrato_de_gestao_gera_onboarding_em_rascunho_com_14_passos_bloqueados(): void
    {
        $contrato = $this->contratoDeGestao();

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        $this->assertNotNull($onboarding);
        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertSame(14, OnboardingPasso::where('onboarding_id', $onboarding->id)->count());

        foreach (OnboardingPasso::where('onboarding_id', $onboarding->id)->get() as $passo) {
            $this->assertSame(OnboardingPasso::STATUS_BLOQUEADO, $passo->status);
            $this->assertNull($passo->disponivel_em);
        }
    }

    /** @test */
    public function contrato_de_servico_sem_template_ativo_devolve_null_sem_criar_nada(): void
    {
        $servicoSemTemplate = Servico::create([
            'nome'          => 'Serviço sem template ' . uniqid(),
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_OUTROS,
        ]);
        $company = Company::factory()->create();
        $contrato = ContratoServico::factory()
            ->paraServico($servicoSemTemplate)
            ->create(['company_id' => $company->id]);

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        $this->assertNull($onboarding);
        $this->assertSame(0, Onboarding::count());
    }

    /** @test */
    public function chamar_duas_vezes_para_o_mesmo_contrato_devolve_o_mesmo_onboarding_sem_duplicar(): void
    {
        $contrato = $this->contratoDeGestao();

        $service = new OnboardingEngineService();
        $primeiro = $service->criarParaContrato($contrato);
        $segundo = $service->criarParaContrato($contrato);

        $this->assertNotNull($primeiro);
        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, Onboarding::count());
        $this->assertSame(14, OnboardingPasso::count());
    }

    /**
     * O congelamento mudou de forma: em vez de um ponteiro para a versão ativa
     * de uma tabela, o onboarding carimba a versão da definição em código e
     * COPIA a definição para dentro de cada passo. É a cópia que garante que
     * mudar a receita não mexe em quem já nasceu.
     *
     * @test
     */
    public function onboarding_carimba_a_versao_da_definicao_e_copia_a_definicao_para_os_passos(): void
    {
        $contrato = $this->contratoDeGestao();

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        $this->assertSame(DefinicaoOnboarding::VERSAO, $onboarding->definicao_versao);

        $passo = OnboardingPasso::where('onboarding_id', $onboarding->id)
            ->where('chave', 'grant_sistema_ecf')
            ->firstOrFail();

        // A definição veio junto — o passo não depende de nenhuma outra tabela
        // para saber o que é.
        $this->assertSame('Grant com o Sistema ECF (OAuth)', $passo->titulo);
        $this->assertSame(OnboardingPasso::DONO_CLIENTE, $passo->dono);
        $this->assertSame(OnboardingPasso::AUTO_FONTE_ML_TOKEN, $passo->auto_fonte);
        $this->assertSame(['acesso_colaborador_ml'], $passo->depende_de);
    }

    /** @test */
    public function nenhuma_chamada_de_rede_no_engine(): void
    {
        $conteudo = file_get_contents(app_path('Services/Onboarding/OnboardingEngineService.php'));

        $this->assertSame(
            0,
            preg_match('/Http::|Artisan::call|fetchPerformance|fetchUserInfo/', $conteudo)
        );
    }
}
