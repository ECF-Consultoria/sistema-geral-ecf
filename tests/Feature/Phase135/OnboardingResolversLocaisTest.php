<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Services\Onboarding\Resolvers\AdmanAccountIdResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 135 Plano 03 — resolvers locais (sem I/O de rede) do template de
 * Gestão: passo 3 (`adman_account_id_preenchido`) e passo 5
 * (`ml_token_ativo`, D-19).
 */
class OnboardingResolversLocaisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um Onboarding + OnboardingPasso mínimos, com o `auto_fonte`
     * indicado, para exercitar um resolver isoladamente.
     */
    private function criarOnboardingComPasso(Company $company, string $autoFonte, string $chave): array
    {
        $servico = Servico::create([
            'nome'          => 'Gestao',
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $template = OnboardingTemplate::create([
            'servico_id' => $servico->id,
            'versao'     => 1,
            'ativo'      => true,
        ]);

        $templatePasso = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => $chave,
            'titulo'      => $chave,
            'dono'        => TemplatePasso::DONO_SISTEMA,
            'auto_fonte'  => $autoFonte,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => $chave,
        ]);

        return [$onboarding, $passo];
    }

    // ─── Passo 3: AdmanAccountIdResolver ────────────────────────────────────

    /** @test */
    public function empresa_com_adman_account_id_so_no_pivot_resolve_concluido(): void
    {
        $company = Company::factory()->create(['adman_account_id' => null]);
        CompanyMarketplace::factory()->create([
            'company_id'  => $company->id,
            'marketplace' => 'meli',
            'adman_id'    => 'PIVOT_123',
        ]);

        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company->fresh(),
            TemplatePasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            'planilha_adman'
        );

        $resultado = app(AdmanAccountIdResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame('PIVOT_123', $resultado->valor['adman_account_id']);
    }

    /** @test */
    public function empresa_sem_adman_account_id_em_lugar_nenhum_resolve_nao_coletado(): void
    {
        $company = Company::factory()->create(['adman_account_id' => null]);

        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company,
            TemplatePasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            'planilha_adman'
        );

        $resultado = app(AdmanAccountIdResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
    }
}
