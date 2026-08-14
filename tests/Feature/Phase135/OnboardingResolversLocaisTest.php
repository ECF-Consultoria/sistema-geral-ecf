<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\CompanyMarketplace;
use App\Models\MlToken;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\Resolvers\AdmanAccountIdResolver;
use App\Services\Onboarding\Resolvers\MlTokenAtivoResolver;
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

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
        ]);

        // O passo carrega a própria definição — não existe mais a indireção
        // template → template_passo → passo.
        $passo = OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => $chave,
            'titulo'        => $chave,
            'dono'          => OnboardingPasso::DONO_SISTEMA,
            'auto_fonte'    => $autoFonte,
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
            OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
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
            OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID,
            'planilha_adman'
        );

        $resultado = app(AdmanAccountIdResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
    }

    /** @test */
    public function adman_account_id_resolver_e_sincrono(): void
    {
        $this->assertFalse(app(AdmanAccountIdResolver::class)->assincrono());
    }

    // ─── Passo 5: MlTokenAtivoResolver (D-19) ───────────────────────────────

    /** @test */
    public function empresa_com_ml_token_active_resolve_concluido(): void
    {
        $company = Company::factory()->create();
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999888777',
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addHour(),
            'status'        => 'active',
            'connected_at'  => now(),
        ]);

        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company,
            OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'grant_ecf'
        );

        $resultado = app(MlTokenAtivoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame('999888777', $resultado->valor['ml_user_id']);
    }

    /** @test */
    public function empresa_com_ml_token_revoked_resolve_nao_coletado(): void
    {
        $company = Company::factory()->create();
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '111222333',
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addHour(),
            'status'        => 'revoked',
            'connected_at'  => now(),
        ]);
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company,
            OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'grant_ecf'
        );

        $resultado = app(MlTokenAtivoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame('Autorização do cliente foi revogada', $resultado->motivo);
    }

    /** @test */
    public function empresa_sem_ml_token_resolve_nao_coletado(): void
    {
        $company = Company::factory()->create();
        [$onboarding, $passo] = $this->criarOnboardingComPasso(
            $company,
            OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'grant_ecf'
        );

        $resultado = app(MlTokenAtivoResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
        $this->assertSame('Cliente ainda não autorizou o acesso', $resultado->motivo);
    }

    /** @test */
    public function os_dois_motivos_de_nao_coletado_do_passo_5_sao_distintos_entre_si(): void
    {
        // Painel e portal mostram textos diferentes para "revogado" e
        // "nunca autorizou" — não pode ser o mesmo motivo genérico.
        $companyRevogada = Company::factory()->create();
        MlToken::create([
            'company_id'    => $companyRevogada->id,
            'ml_user_id'    => '444555666',
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addHour(),
            'status'        => 'revoked',
            'connected_at'  => now(),
        ]);
        [$onboardingRevogada, $passoRevogada] = $this->criarOnboardingComPasso(
            $companyRevogada,
            OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'grant_ecf'
        );

        $companySemToken = Company::factory()->create();
        [$onboardingSemToken, $passoSemToken] = $this->criarOnboardingComPasso(
            $companySemToken,
            OnboardingPasso::AUTO_FONTE_ML_TOKEN,
            'grant_ecf'
        );

        $resolver = app(MlTokenAtivoResolver::class);
        $resultadoRevogada = $resolver->resolver($onboardingRevogada, $passoRevogada);
        $resultadoSemToken = $resolver->resolver($onboardingSemToken, $passoSemToken);

        $this->assertNotSame($resultadoRevogada->motivo, $resultadoSemToken->motivo);
    }

    /** @test */
    public function ml_token_ativo_resolver_e_sincrono(): void
    {
        $this->assertFalse(app(MlTokenAtivoResolver::class)->assincrono());
    }

    // ─── Contrato: catálogo com as chaves registradas até aqui ──────────────

    /**
     * O catálogo cresce a cada plano que registra um resolver novo no
     * `AppServiceProvider` (previsto em 135-03-SUMMARY.md: "hoje expõe as 2
     * chaves locais; crescerá para 5 no Plano 06"). Este teste prova que os
     * 2 resolvers LOCAIS deste plano continuam registrados — nunca que o
     * catálogo tem exatamente 2 chaves no total, que deixou de ser verdade
     * assim que o Plano 06 começou a acrescentar resolvers de rede.
     */
    /** @test */
    public function catalogo_contem_as_2_chaves_locais_registradas_neste_plano(): void
    {
        $chaves = collect(app(OnboardingResolverFactory::class)->catalogo())
            ->pluck('chave')
            ->all();

        $this->assertContains(OnboardingPasso::AUTO_FONTE_ADMAN_ACCOUNT_ID, $chaves);
        $this->assertContains(OnboardingPasso::AUTO_FONTE_ML_TOKEN, $chaves);
    }
}
