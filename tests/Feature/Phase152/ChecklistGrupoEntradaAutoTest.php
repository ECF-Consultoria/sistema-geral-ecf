<?php

namespace Tests\Feature\Phase152;

use App\Models\Company;
use App\Models\MlToken;
use App\Models\OnboardingLink;
use App\Services\ChecklistAdministrativo\Resolvers\ConexaoEcfResolver;
use App\Services\ChecklistAdministrativo\Resolvers\MlOAuthConectadoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 152 Plano 04 (D-05, D-14) — MlOAuthConectadoResolver (item 7) e
 * ConexaoEcfResolver (item 8).
 */
class ChecklistGrupoEntradaAutoTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::factory()->create();
    }

    private function mlToken(Company $company, array $overrides = []): MlToken
    {
        return MlToken::create(array_merge([
            'company_id'        => $company->id,
            'ml_user_id'        => '465723451',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read offline_access',
            'expires_at'        => now()->addHour(),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ], $overrides));
    }

    // ─── Item 7 — Caso 1: sem MlToken ───────────────────────────────────────

    public function test_empresa_sem_ml_token_deixa_item_7_nao_coletado_com_motivo_de_nunca_autorizou(): void
    {
        $empresa = $this->company();

        $resultado = (new MlOAuthConectadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('ainda não autorizou', (string) $resultado->motivo);
    }

    // ─── Item 7 — Caso 2: MlToken revogado ─────────────────────────────────

    public function test_ml_token_revogado_deixa_item_7_nao_coletado_com_motivo_de_revogada(): void
    {
        $empresa = $this->company();
        $this->mlToken($empresa, ['status' => 'revoked']);

        $resultado = (new MlOAuthConectadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
        $this->assertStringContainsString('revogada', (string) $resultado->motivo);
    }

    // ─── Item 7 — Caso 3: MlToken ativo ─────────────────────────────────────

    public function test_ml_token_ativo_fecha_item_7_e_o_valor_traz_ml_user_id(): void
    {
        $empresa = $this->company();
        $this->mlToken($empresa, ['status' => 'active', 'ml_user_id' => '999888777']);

        $resultado = (new MlOAuthConectadoResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehConcluido());
        $this->assertSame('999888777', $resultado->valor['ml_user_id'] ?? null);
    }

    // ─── Item 8 — Caso 4: sem OnboardingLink ───────────────────────────────

    public function test_empresa_sem_onboarding_link_deixa_item_8_nao_coletado(): void
    {
        $empresa = $this->company();

        $resultado = (new ConexaoEcfResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehNaoColetado());
    }

    // ─── Item 8 — Caso 5: com OnboardingLink ───────────────────────────────

    public function test_empresa_com_onboarding_link_fecha_item_8(): void
    {
        $empresa = $this->company();
        OnboardingLink::create(['company_id' => $empresa->id, 'token' => 'token-de-teste-152-04']);

        $resultado = (new ConexaoEcfResolver())->resolver($empresa->fresh());

        $this->assertTrue($resultado->ehConcluido());
    }

    // ─── Item 8 — Caso 6: defesa do efeito colateral — nunca cria a linha ──

    public function test_resolver_o_item_8_sem_onboarding_link_nao_cria_nenhuma_linha(): void
    {
        $empresa = $this->company();

        (new ConexaoEcfResolver())->resolver($empresa->fresh());

        // Reconsulta ao banco — a prova de que o resolver NÃO cria a linha.
        $this->assertDatabaseMissing('onboarding_links', ['company_id' => $empresa->id]);
        $this->assertSame(0, OnboardingLink::where('company_id', $empresa->id)->count());
    }
}
