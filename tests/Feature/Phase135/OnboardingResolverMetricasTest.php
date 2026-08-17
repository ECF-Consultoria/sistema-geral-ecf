<?php

namespace Tests\Feature\Phase135;

use App\Models\Company;
use App\Models\CompanyGrant;
use App\Models\MlToken;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\Resolvers\MetricasContaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 135 Plano 06 (Task 2) — passo 7 (`metricas_da_conta`), parsing
 * defensivo agregando `MercadoLivreService::fetchUserInfo()` + faturamento
 * Adman dos últimos 3 meses + `CompanyGrant::active()`.
 */
class OnboardingResolverMetricasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um Onboarding + OnboardingPasso mínimos com `auto_fonte =
     * metricas_conta`, para exercitar o resolver isoladamente — mesmo molde
     * de `OnboardingResolversLocaisTest::criarOnboardingComPasso()`.
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

        $onboarding = Onboarding::create([
            'company_id'  => $company->id,
            'servico_id'  => $servico->id,
        ]);

        $passo = OnboardingPasso::create([
            'onboarding_id' => $onboarding->id,
            'ordem'         => 1,
            'chave'         => 'metricas_da_conta',
            'titulo'        => 'Métricas da conta',
            'dono'          => OnboardingPasso::DONO_SISTEMA,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_METRICAS,
        ]);

        return [$onboarding, $passo];
    }

    private function comMlTokenAtivo(Company $company): MlToken
    {
        return MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '777888999',
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addDays(30),
            'status'        => 'active',
            'connected_at'  => now(),
        ]);
    }

    private function comGrantAtivo(Company $company): CompanyGrant
    {
        return CompanyGrant::create([
            'company_id'        => $company->id,
            'status'            => 'active',
            'medalha_fecha_in'  => now()->subMonths(2)->toDateString(),
            'medalha_fecha_out' => now()->addMonths(4)->toDateString(),
            'programa'          => 'Full',
            'iniciativa'        => 'Mentoria',
            'parceiro'          => 'ECF Consultoria',
        ]);
    }

    // ─── Payload completo ─────────────────────────────────────────────────

    /** @test */
    public function payload_completo_resolve_concluido_com_nao_obtidos_vazio(): void
    {
        Http::fake([
            '*/users/*'       => Http::response([
                'id'                => 777888999,
                'nickname'          => 'LOJA_TESTE',
                // `metrics`/`transactions` fazem parte do payload real de
                // `/users/{id}` — antes o fake os omitia e nós os
                // descartávamos, então ninguém notava a falta.
                'seller_reputation' => [
                    'level_id'            => '5_green',
                    'power_seller_status' => 'platinum',
                    'metrics' => [
                        'claims'                => ['period' => '60 days', 'rate' => 0.003, 'value' => 1],
                        'cancellations'         => ['period' => '60 days', 'rate' => 0.001, 'value' => 1],
                        'delayed_handling_time' => ['period' => '60 days', 'rate' => 0.015, 'value' => 5],
                        'sales'                 => ['period' => '60 days', 'completed' => 340],
                    ],
                    'transactions' => ['completed' => 340, 'canceled' => 2, 'total' => 342],
                ],
                'tags' => ['full', 'normal'],
            ], 200),
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 45000.0]],
            ], 200),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_METRICAS']);
        $this->comMlTokenAtivo($company);
        $this->comGrantAtivo($company);

        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('LOJA_TESTE', $resultado->valor['nickname']);
        $this->assertSame('5_green', $resultado->valor['reputacao']['level_id']);
        $this->assertSame([], $resultado->valor['nao_obtidos']);

        // As métricas deixam de ser descartadas.
        $this->assertSame(0.003, $resultado->valor['reputacao']['metrics']['claims']['rate']);
        $this->assertSame(340, $resultado->valor['reputacao']['transactions']['completed']);
    }

    /**
     * As duas medalhas em campos separados: a MercadoLíder é da conta do
     * CLIENTE, a do programa de parceiros é da ECF. Antes dividiam o mesmo
     * slot.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function as_duas_medalhas_chegam_em_campos_distintos(): void
    {
        Http::fake([
            '*/users/*'       => Http::response([
                'id'                => 777888999,
                'nickname'          => 'LOJA_TESTE',
                'seller_reputation' => [
                    'level_id'            => '5_green',
                    'power_seller_status' => 'gold',
                    'metrics' => [
                        'claims'                => ['rate' => 0.02],
                        'cancellations'         => ['rate' => 0.001],
                        'delayed_handling_time' => ['rate' => 0.01],
                        'sales'                 => ['period' => '60 days', 'completed' => 500],
                    ],
                ],
                'tags' => ['full'],
            ], 200),
            '*/performance/*' => Http::response(['summarizedData' => []], 200),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_MEDALHAS']);
        $this->comMlTokenAtivo($company);
        $this->comGrantAtivo($company);

        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $valor = app(MetricasContaResolver::class)->resolver($onboarding, $passo)->valor;

        // Medalha da CONTA (MercadoLíder), com o que falta para a próxima.
        $this->assertSame('gold', $valor['medalha_conta']['medalha_atual']);
        $this->assertSame('platinum', $valor['medalha_conta']['proxima_medalha']);
        $this->assertSame('platinum', $valor['proxima_medalha']);
        $this->assertContains(
            'Reclamações em 2% — o limite é 1%',
            $valor['medalha_conta']['bloqueios']
        );

        // Medalha do PROGRAMA DE PARCEIROS — outra coisa, outro campo.
        $this->assertSame('ECF Consultoria', $valor['medalha_parceiro']['parceiro']);
        $this->assertSame('Full', $valor['medalha_parceiro']['programa']);

        // Chaves planas antigas seguem preenchidas — `valor` gravado antes
        // desta mudança continua legível pelas telas já publicadas.
        $this->assertSame('ECF Consultoria', $valor['parceiro']);
        $this->assertSame('Full', $valor['programa']);
    }

    /** @test */
    public function payload_sem_seller_reputation_ainda_resolve_concluido_com_campo_null(): void
    {
        Http::fake([
            '*/users/*'       => Http::response([
                'id'       => 777888999,
                'nickname' => 'LOJA_SEM_REPUTACAO',
            ], 200),
            '*/performance/*' => Http::response([
                'summarizedData' => ['grossBilling' => ['value' => 12000.0]],
            ], 200),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_SEM_REP']);
        $this->comMlTokenAtivo($company);

        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertContains('seller_reputation', $resultado->valor['nao_obtidos']);
        $this->assertNull($resultado->valor['reputacao']['level_id']);
        $this->assertNull($resultado->valor['reputacao']['power_seller_status']);
        $this->assertNotSame(false, $resultado->valor['reputacao']['level_id']);
        $this->assertNotSame(0, $resultado->valor['reputacao']['level_id']);
    }

    // ─── Falta de token (pendência humana) ──────────────────────────────────

    /** @test */
    public function empresa_sem_ml_token_resolve_nao_coletado_sem_chamar_a_api(): void
    {
        Http::preventStrayRequests();

        $company = Company::factory()->create(['adman_account_id' => 'CUST_SEM_TOKEN']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->ehIndeterminado());
        Http::assertNothingSent();
    }

    // ─── 429 do Mercado Livre ────────────────────────────────────────────────

    /** @test */
    public function erro_429_da_api_ml_resolve_indeterminado(): void
    {
        Http::fake(['*/users/*' => Http::response('rate limit', 429)]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_429_ML']);
        $this->comMlTokenAtivo($company);

        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehIndeterminado());
        $this->assertFalse($resultado->ehNaoColetado());
    }

    // ─── Falha isolada da Adman não derruba o passo ─────────────────────────

    /** @test */
    public function falha_da_adman_com_fetch_user_info_ok_ainda_resolve_concluido(): void
    {
        Http::fake([
            '*/users/*'       => Http::response([
                'id'                => 777888999,
                'nickname'          => 'LOJA_ADMAN_FALHOU',
                'seller_reputation' => ['level_id' => '5_green', 'power_seller_status' => 'platinum'],
                'tags'              => ['full'],
            ], 200),
            '*/performance/*' => Http::response('erro interno', 500),
        ]);

        $company = Company::factory()->create(['adman_account_id' => 'CUST_ADMAN_FALHOU']);
        $this->comMlTokenAtivo($company);

        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertContains('faturamento_3_meses', $resultado->valor['nao_obtidos']);
        $this->assertNull($resultado->valor['faturamento_3_meses']);
    }

    // ─── Teste negativo do sinal de coleta (protege o SC-11) ────────────────

    /** @test */
    public function nao_coletado_por_falta_de_token_nao_sinaliza_coleta_e_engine_mantem_aberto(): void
    {
        Http::preventStrayRequests();

        $company = Company::factory()->create(['adman_account_id' => 'CUST_SEM_TOKEN_2']);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = app(MetricasContaResolver::class)->resolver($onboarding, $passo);

        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
        $this->assertArrayNotHasKey('coleta_em_andamento', $resultado->valor);

        (new OnboardingEngineService())->aplicarResultado($passo, $resultado);

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
    }
}
