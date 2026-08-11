<?php

namespace Tests\Feature\Phase135;

use App\Jobs\ResolveOnboardingPassoJob;
use App\Models\Company;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\OnboardingTemplate;
use App\Models\Servico;
use App\Models\TemplatePasso;
use App\Services\AdmanService;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\Resolvers\AdmanGrantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 135 Plano 06 (Tasks 1 e 3) — sonda do passo 4 (`grant_consultoria_adman`,
 * D-18), com classificação de 3 estados herdada de
 * `DiagnoseCustId::validarViaAdman()`, e `ResolveOnboardingPassoJob` — o
 * único lugar de onde este resolver pode rodar.
 */
class OnboardingResolverAdmanGrantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um Onboarding + OnboardingPasso mínimos com `auto_fonte =
     * adman_grant_ativo`, para exercitar o resolver isoladamente — mesmo
     * molde de `OnboardingResolversLocaisTest::criarOnboardingComPasso()`
     * (Plano 03).
     */
    private function criarOnboardingComPasso(Company $company): array
    {
        $servico = Servico::create([
            'nome'          => 'Gestao ' . uniqid(),
            'valor_padrao'  => 3000,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_PERFORMANCE,
        ]);

        $template = OnboardingTemplate::create([
            'servico_id'   => $servico->id,
            'versao'       => 1,
            'ativo'        => true,
            'publicado_em' => now(),
        ]);

        $templatePasso = TemplatePasso::create([
            'template_id' => $template->id,
            'ordem'       => 1,
            'chave'       => 'grant_consultoria_adman',
            'titulo'      => 'Grant com a Consultoria (Adman)',
            'dono'        => TemplatePasso::DONO_SISTEMA,
            'auto_fonte'  => TemplatePasso::AUTO_FONTE_ADMAN_GRANT,
        ]);

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
            'template_id' => $template->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id'     => $onboarding->id,
            'template_passo_id' => $templatePasso->id,
            'chave'             => 'grant_consultoria_adman',
        ]);

        return [$onboarding, $passo];
    }

    /**
     * Resolver com o throttle de 7s substituído por no-op — a suíte não
     * pode pagar tempo real por caminho que "chamou a API" (sugestão
     * explícita do plano: encapsular a espera num método sobrescrevível).
     */
    private function resolverSemThrottle(): AdmanGrantResolver
    {
        return new class(app(AdmanService::class)) extends AdmanGrantResolver {
            protected function aguardarThrottle(): void
            {
                // no-op no teste.
            }
        };
    }

    // ─── Os 4 caminhos de classificação ─────────────────────────────────────

    /** @test */
    public function empresa_sem_cust_id_resolve_nao_coletado_sem_chamar_a_adman(): void
    {
        Http::preventStrayRequests();
        $company = Company::factory()->create(['adman_account_id' => null]);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
        Http::assertNothingSent();
    }

    /** @test */
    public function resposta_200_com_resumo_do_dia_zerado_resolve_concluido(): void
    {
        Http::fake(['*/performance/*' => Http::response([
            'summarizedData' => [
                'grossBilling' => ['value' => 0],
                'netBilling'   => ['value' => 0],
            ],
        ], 200)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_OK']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertFalse($resultado->ehNaoColetado());
        $this->assertSame('CUST_OK', $resultado->valor['cust_id']);
        Http::assertSentCount(1);
    }

    /** @test */
    public function resposta_404_resolve_nao_coletado(): void
    {
        Http::fake(['*/performance/*' => Http::response('nao encontrado', 404)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_404']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
    }

    /** @test */
    public function resposta_429_resolve_indeterminado_nunca_sem_grant(): void
    {
        Http::fake(['*/performance/*' => Http::response('rate limit', 429)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_429']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehIndeterminado());
        $this->assertFalse($resultado->ehNaoColetado());
    }

    /** @test */
    public function resolver_e_assincrono(): void
    {
        $this->assertTrue($this->resolverSemThrottle()->assincrono());
    }

    // ─── Teste negativo do sinal de coleta (protege o SC-11) ────────────────

    /** @test */
    public function nao_coletado_sem_cust_id_nao_sinaliza_coleta_e_engine_mantem_aberto(): void
    {
        Http::preventStrayRequests();
        $company = Company::factory()->create(['adman_account_id' => null]);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
        $this->assertArrayNotHasKey('coleta_em_andamento', $resultado->valor);

        (new OnboardingEngineService())->aplicarResultado($passo, $resultado);

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
    }

    /** @test */
    public function nao_coletado_por_404_nao_sinaliza_coleta_e_engine_mantem_aberto(): void
    {
        Http::fake(['*/performance/*' => Http::response('nao encontrado', 404)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_404B']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = $this->resolverSemThrottle()->resolver($onboarding, $passo);

        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
        $this->assertArrayNotHasKey('coleta_em_andamento', $resultado->valor);

        (new OnboardingEngineService())->aplicarResultado($passo, $resultado);

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
    }

    // ─── ResolveOnboardingPassoJob (Task 3) ─────────────────────────────────

    /** @test */
    public function job_para_passo_ja_concluido_nao_envia_request(): void
    {
        Http::preventStrayRequests();

        $company = Company::factory()->create(['adman_account_id' => 'CUST_JOB_CONCLUIDO']);
        [, $passo] = $this->criarOnboardingComPasso($company);
        $passo->update(['status' => OnboardingPasso::STATUS_CONCLUIDO]);

        ResolveOnboardingPassoJob::dispatch($passo->fresh());

        Http::assertNothingSent();
    }

    /** @test */
    public function job_para_onboarding_em_rascunho_nao_envia_request(): void
    {
        Http::preventStrayRequests();

        $company = Company::factory()->create(['adman_account_id' => 'CUST_JOB_RASCUNHO']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);
        $this->assertSame(Onboarding::STATUS_RASCUNHO, $onboarding->fresh()->status);

        ResolveOnboardingPassoJob::dispatch($passo);

        Http::assertNothingSent();
    }

    /** @test */
    public function job_com_resposta_429_deixa_o_passo_indeterminado_com_tentativas_maior_e_valor_inalterado(): void
    {
        Http::fake(['*/performance/*' => Http::response('rate limit', 429)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_JOB_429']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);
        $onboarding->update(['status' => Onboarding::STATUS_ANDAMENTO, 'iniciado_em' => now()]);

        $this->assertSame(0, $passo->fresh()->tentativas);
        $this->assertNull($passo->fresh()->valor);

        ResolveOnboardingPassoJob::dispatch($passo->fresh());

        $passoAtualizado = $passo->fresh();
        $this->assertSame(OnboardingPasso::STATUS_INDETERMINADO, $passoAtualizado->status);
        $this->assertGreaterThan(0, $passoAtualizado->tentativas);
        $this->assertNull($passoAtualizado->valor);
    }
}
