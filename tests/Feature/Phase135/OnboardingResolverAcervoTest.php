<?php

namespace Tests\Feature\Phase135;

use App\Jobs\SyncMlAcervoCompanyJob;
use App\Models\Company;
use App\Models\MlAcervoItem;
use App\Models\MlToken;
use App\Models\Onboarding;
use App\Models\OnboardingPasso;
use App\Models\Servico;
use App\Services\Onboarding\OnboardingEngineService;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\Resolvers\AcervoColetadoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Fase 135 Plano 07 — resolver do passo 8 (`acervo_coletado`, D-11/SC-07).
 * Prova, com dois testes separados e asserções diferentes, que tabela vazia
 * (`aguardando coleta`) nunca vira "zero de verdade" (`concluido` com
 * `ativos=0`) — a mesma armadilha já sofrida no Shopee.
 */
class OnboardingResolverAcervoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Monta um Onboarding + OnboardingPasso mínimos com `auto_fonte =
     * acervo_coletado`, mesmo molde de
     * `OnboardingResolverAdmanGrantTest::criarOnboardingComPasso()` (Plano 06).
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
            'chave'         => 'acervo_coletado_meus_anuncios',
            'titulo'        => 'Acervo de anúncios coletado (Meus Anúncios)',
            'dono'          => OnboardingPasso::DONO_SISTEMA,
            'auto_fonte'    => OnboardingPasso::AUTO_FONTE_ACERVO,
        ]);

        return [$onboarding, $passo];
    }

    private function criarTokenAtivo(Company $company): MlToken
    {
        return MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'ML_USER_' . $company->id,
            'access_token'  => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'expires_at'    => now()->addHours(6),
            'status'        => 'active',
            'connected_at'  => now(),
        ]);
    }

    private function criarItemAcervo(Company $company, string $status): MlAcervoItem
    {
        return MlAcervoItem::create([
            'company_id' => $company->id,
            'ml_item_id' => 'MLB' . fake()->unique()->numerify('###########'),
            'status'     => $status,
        ]);
    }

    // ─── Guard: empresa sem token ML ativo ──────────────────────────────────

    /** @test */
    public function empresa_sem_ml_token_ativo_resolve_nao_coletado_sem_disparar_job(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertFalse($resultado->sinalizouColetaEmAndamento());
        Queue::assertNothingPushed();

        (new OnboardingEngineService())->aplicarResultado($passo, $resultado);

        $this->assertSame(OnboardingPasso::STATUS_ABERTO, $passo->fresh()->status);
    }

    // ─── Caso A: tabela vazia — nunca conclui, dispara e sinaliza ──────────

    /** @test */
    public function tabela_vazia_com_token_ativo_dispara_coleta_e_sinaliza_em_andamento(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertTrue($resultado->sinalizouColetaEmAndamento());
        $this->assertArrayNotHasKey('ativos', $resultado->valor);
        $this->assertArrayNotHasKey('inativos', $resultado->valor);
        Queue::assertPushed(SyncMlAcervoCompanyJob::class);
    }

    /** @test */
    public function tabela_vazia_aplicar_resultado_poe_passo_em_aguardando_coleta_com_carimbo(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo);

        (new OnboardingEngineService())->aplicarResultado($passo, $resultado);

        $passoAtualizado = $passo->fresh();
        $this->assertSame(OnboardingPasso::STATUS_AGUARDANDO_COLETA, $passoAtualizado->status);
        $this->assertNotNull($passoAtualizado->coleta_iniciada_em);
    }

    // ─── Caso B: zero real (tabela populada, tudo inativo) ─────────────────

    /** @test */
    public function tabela_populada_toda_pausada_resolve_concluido_com_zero_ativos_real(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        for ($i = 0; $i < 5; $i++) {
            $this->criarItemAcervo($company, 'paused');
        }

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame(0, $resultado->valor['ativos']);
        $this->assertSame(5, $resultado->valor['inativos']);
        Queue::assertNothingPushed();
    }

    // ─── Caso C: mix — breakdown por status ─────────────────────────────────

    /** @test */
    public function tabela_populada_com_mix_resolve_concluido_com_breakdown_por_status(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);

        for ($i = 0; $i < 3; $i++) {
            $this->criarItemAcervo($company, 'active');
        }
        for ($i = 0; $i < 2; $i++) {
            $this->criarItemAcervo($company, 'closed');
        }

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo);

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame(3, $resultado->valor['ativos']);
        $this->assertSame(2, $resultado->valor['inativos']);
        $this->assertSame(3, $resultado->valor['por_status']['active']);
        $this->assertSame(2, $resultado->valor['por_status']['closed']);
    }

    // ─── Redisparo controlado pela janela de 30min ──────────────────────────

    /** @test */
    public function coleta_iniciada_ha_5_minutos_com_tabela_ainda_vazia_nao_redispara(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);
        $passo->update(['coleta_iniciada_em' => now()->subMinutes(5)]);

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertTrue($resultado->sinalizouColetaEmAndamento());
        Queue::assertNothingPushed();
    }

    /** @test */
    public function coleta_iniciada_ha_45_minutos_com_tabela_ainda_vazia_redispara(): void
    {
        Queue::fake();

        $company = Company::factory()->create();
        $this->criarTokenAtivo($company);
        [$onboarding, $passo] = $this->criarOnboardingComPasso($company);
        $passo->update(['coleta_iniciada_em' => now()->subMinutes(45)]);

        $resultado = (new AcervoColetadoResolver())->resolver($onboarding, $passo->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertTrue($resultado->sinalizouColetaEmAndamento());
        Queue::assertPushed(SyncMlAcervoCompanyJob::class);
    }

    // ─── Contrato ────────────────────────────────────────────────────────

    /** @test */
    public function resolver_e_assincrono(): void
    {
        $this->assertTrue((new AcervoColetadoResolver())->assincrono());
    }

    /** @test */
    public function catalogo_expoe_as_5_chaves_de_auto_fontes(): void
    {
        $catalogo = app(OnboardingResolverFactory::class)->catalogo();
        $chaves = array_column($catalogo, 'chave');

        sort($chaves);
        $esperado = OnboardingPasso::AUTO_FONTES;
        sort($esperado);

        $this->assertSame($esperado, $chaves);
    }
}
