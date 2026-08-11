<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingEngineService;
use Database\Seeders\OnboardingTemplateGestaoSeeder;
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
    public function contrato_de_gestao_gera_onboarding_em_rascunho_com_13_passos_bloqueados(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();
        $contrato = $this->contratoDeGestao();

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        $this->assertNotNull($onboarding);
        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->status);
        $this->assertSame(13, OnboardingPasso::where('onboarding_id', $onboarding->id)->count());

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
        (new OnboardingTemplateGestaoSeeder())->run();
        $contrato = $this->contratoDeGestao();

        $service = new OnboardingEngineService();
        $primeiro = $service->criarParaContrato($contrato);
        $segundo = $service->criarParaContrato($contrato);

        $this->assertNotNull($primeiro);
        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, Onboarding::count());
        $this->assertSame(13, OnboardingPasso::count());
    }

    /** @test */
    public function template_id_aponta_para_a_versao_ativa_no_momento_da_criacao(): void
    {
        (new OnboardingTemplateGestaoSeeder())->run();
        $servico = $this->servicoDeGestao();
        $templateAtivo = OnboardingTemplate::where('servico_id', $servico->id)
            ->where('ativo', true)
            ->firstOrFail();

        $contrato = $this->contratoDeGestao();

        $onboarding = (new OnboardingEngineService())->criarParaContrato($contrato);

        $this->assertSame($templateAtivo->id, $onboarding->template_id);
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
