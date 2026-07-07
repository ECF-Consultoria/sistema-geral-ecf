<?php

namespace Tests\Feature\Phase60;

use App\Models\Company;
use App\Models\MlToken;
use App\Services\Metrics\AdmanMetricsProvider;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Metrics\MlMetricsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 60 Plan 60-03 (Wave 3) — Testes do `MetricsProviderFactory`.
 *
 * Foco:
 *  - Regra de precedência do ADR DATA-04: caso "ambos" retorna [ML, Adman]
 *    NESSA ORDEM (ML primeiro por ser primário).
 *  - `forCompany()` retorna array<MetricsProvider> ordenado (não UM provider —
 *    a fusão de dados no caso "ambos" acontece no UnifiedMetricsService no
 *    Plan 60-04, este factory apenas informa quem participa).
 *  - `caseFor()` retorna literal 'ambos'|'so-ml'|'so-adman'|'none' — pareado
 *    com o caso do ADR e utilizado como discriminador em logs/tests do
 *    Plan 60-04.
 *
 * Cobertura:
 *  - test_caso_ambos_retorna_ml_primeiro_adman_depois
 *  - test_caso_so_ml_retorna_apenas_ml
 *  - test_caso_so_adman_retorna_apenas_adman
 *  - test_caso_none_retorna_array_vazio
 *  - test_case_for_retorna_string_correta_por_caso
 *
 * Referência canônica: ADR
 * `.planning/adrs/DATA-04-precedencia-multifonte.md` — "Detecção do caso"
 * e "Regra de precedência".
 */
class MetricsProviderFactoryTest extends TestCase
{
    use RefreshDatabase;

    // ────────────────────────── Helpers ──────────────────────────

    /**
     * Cria Company persistida com adman_account_id opcional e MlToken persistido
     * com status configurável.
     */
    private function makeCompany(?string $admanAccountId, ?string $mlStatus): Company
    {
        $company = Company::factory()->create([
            'adman_account_id' => $admanAccountId,
            'ml_store_id'      => '465723451',
        ]);

        if ($mlStatus !== null) {
            MlToken::create([
                'company_id'        => $company->id,
                'ml_user_id'        => '465723451',
                'access_token'      => 'fake-token',
                'refresh_token'     => 'fake-refresh',
                'token_type'        => 'bearer',
                'scope'             => 'read offline_access',
                'expires_at'        => now()->addHour(),
                'last_refreshed_at' => now(),
                'status'            => $mlStatus,
                'connected_at'      => now(),
            ]);
        }

        return $company->fresh();
    }

    private function factory(): MetricsProviderFactory
    {
        // Resolve via DI Laravel — atesta que o binding automático (Adman +
        // Ml + MercadoLivreService via constructor injection) funciona.
        return $this->app->make(MetricsProviderFactory::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // Caso "ambos" — regra de precedência do ADR
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_ambos_retorna_ml_primeiro_adman_depois(): void
    {
        $company = $this->makeCompany(admanAccountId: '12345', mlStatus: 'active');

        $providers = $this->factory()->forCompany($company);

        $this->assertCount(2, $providers, 'Caso "ambos" deve retornar 2 providers');
        // ADR DATA-04 tabela de precedência: ML é primário — deve vir primeiro
        // na ordem para que UnifiedMetricsService (Plan 60-04) aplique fusão
        // "ML primeiro, Adman enriquece" percorrendo o array em ordem.
        $this->assertInstanceOf(MlMetricsProvider::class, $providers[0], 'ML deve ser o primeiro (precedência)');
        $this->assertInstanceOf(AdmanMetricsProvider::class, $providers[1], 'Adman deve ser o segundo (enriquece)');
        $this->assertSame('ml', $providers[0]->name());
        $this->assertSame('adman', $providers[1]->name());
    }

    // ═══════════════════════════════════════════════════════════════
    // Caso "só-ML"
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_so_ml_retorna_apenas_ml(): void
    {
        $company = $this->makeCompany(admanAccountId: null, mlStatus: 'active');

        $providers = $this->factory()->forCompany($company);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(MlMetricsProvider::class, $providers[0]);
        $this->assertSame('ml', $providers[0]->name());
    }

    // ═══════════════════════════════════════════════════════════════
    // Caso "só-Adman"
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_so_adman_retorna_apenas_adman(): void
    {
        // mlToken null OU status != active → só-Adman quando adman_account_id existe.
        $company = $this->makeCompany(admanAccountId: '12345', mlStatus: null);

        $providers = $this->factory()->forCompany($company);

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(AdmanMetricsProvider::class, $providers[0]);
        $this->assertSame('adman', $providers[0]->name());
    }

    // ═══════════════════════════════════════════════════════════════
    // Caso "none" — nenhuma integração
    // ═══════════════════════════════════════════════════════════════

    public function test_caso_none_retorna_array_vazio(): void
    {
        $company = $this->makeCompany(admanAccountId: null, mlStatus: null);

        $providers = $this->factory()->forCompany($company);

        // ADR DATA-04: caso "none" — array vazio permite que
        // UnifiedMetricsService interprete como sentinela e emita
        // source='none' no DTO final.
        $this->assertSame([], $providers);
    }

    // ═══════════════════════════════════════════════════════════════
    // caseFor() — literal string por caso
    // ═══════════════════════════════════════════════════════════════

    public function test_case_for_retorna_string_correta_por_caso(): void
    {
        $factory = $this->factory();

        $ambos  = $this->makeCompany(admanAccountId: '12345', mlStatus: 'active');
        $soMl   = $this->makeCompany(admanAccountId: null,    mlStatus: 'active');
        $soAdm  = $this->makeCompany(admanAccountId: '54321', mlStatus: null);
        $none   = $this->makeCompany(admanAccountId: null,    mlStatus: null);
        $mlInc  = $this->makeCompany(admanAccountId: '99999', mlStatus: 'expired'); // ML inativo = "só-adman"

        $this->assertSame('ambos',    $factory->caseFor($ambos));
        $this->assertSame('so-ml',    $factory->caseFor($soMl));
        $this->assertSame('so-adman', $factory->caseFor($soAdm));
        $this->assertSame('none',     $factory->caseFor($none));
        // ML inativo com adman_account_id presente → cai em "só-adman"
        // (mlToken.status != active ⇒ MlMetricsProvider::supports() = false).
        $this->assertSame('so-adman', $factory->caseFor($mlInc));
    }
}
