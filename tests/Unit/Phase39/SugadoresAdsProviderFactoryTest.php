<?php

// Phase 39 Plan 39-01 (criação) + Plan 39-02 (expansão branch ml).
// Testes Unit do SugadoresAdsProviderFactory cobrindo:
//   - Resolução por forceName ('adman' | 'ml')
//   - Capability detection via supports() — preferência Adman até Phase 42
//   - Fallback para ML quando apenas ML suporta
//   - RuntimeException quando nenhum provider suporta

namespace Tests\Unit\Phase39;

use App\Models\Company;
use App\Services\AdmanService;
use App\Services\MercadoLivreService;
use App\Services\Sugadores\AdmanSugadoresProvider;
use App\Services\Sugadores\MercadoLivreAdsService;
use App\Services\Sugadores\MercadoLivreSugadoresProvider;
use App\Services\Sugadores\SugadoresAdsProviderFactory;
use Mockery;
use Tests\TestCase;

class SugadoresAdsProviderFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria uma Company sem persistir no banco — suficiente para os métodos do
     * factory que só leem atributos via supports() dos providers.
     *
     * Para os tests do branch ml usamos Mockery parcial em Company (override
     * de getIsMlDrivenAttribute / mlToken) para evitar dependência de DB.
     */
    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $defaults = ['id' => 999, 'name' => 'Teste', 'adman_account_id' => '12345', 'marketplace' => 'meli'];
        $company->setRawAttributes(array_merge($defaults, $attrs));
        return $company;
    }

    /**
     * Cria uma Company "mockada" cujo supports(ML) retorne true — usado quando
     * precisamos simular mlToken active sem persistir MlToken no DB.
     *
     * Estratégia: cria a Company in-memory e injeta uma relação mlToken via
     * setRelation() apontando para um MlToken stub com status='active'.
     */
    private function makeCompanyWithMlTokenStub(array $attrs = [], string $tokenStatus = 'active'): Company
    {
        $company = $this->makeCompany($attrs);

        $token = new \App\Models\MlToken();
        $token->setRawAttributes(['status' => $tokenStatus]);
        $company->setRelation('mlToken', $token);

        return $company;
    }

    private function makeFactory(): SugadoresAdsProviderFactory
    {
        // Composição real dos providers em torno de Mockery dos services consumidos.
        $adman         = Mockery::mock(AdmanService::class);
        $admanProvider = new AdmanSugadoresProvider($adman);

        $ml            = Mockery::mock(MercadoLivreAdsService::class);
        // Phase 53-01: provider ganhou 2o construtor param (MercadoLivreService).
        $mlProvider    = new MercadoLivreSugadoresProvider($ml, Mockery::mock(MercadoLivreService::class));

        return new SugadoresAdsProviderFactory($admanProvider, $mlProvider);
    }

    // ─────────── Test 1: for(company, 'adman') retorna AdmanProvider ───────────

    public function test_for_with_force_name_adman_returns_adman_provider(): void
    {
        $factory = $this->makeFactory();
        $company = $this->makeCompany(['adman_account_id' => '12345']);

        $provider = $factory->for($company, 'adman');

        $this->assertInstanceOf(AdmanSugadoresProvider::class, $provider);
        $this->assertSame('adman', $provider->name());
    }

    // ─────────── Test 2: for(company) sem forceName cai em Adman via supports() ───────────

    public function test_for_with_no_force_falls_back_to_adman_when_company_has_adman_account_id(): void
    {
        $factory = $this->makeFactory();
        $company = $this->makeCompany(['adman_account_id' => '12345']);
        // Phase 42 Plan 42-04: factory agora consulta mlToken ANTES de Adman
        // (cut-over D-05 — ML preferido). Como este Unit test nao usa
        // RefreshDatabase, eager-set mlToken=null para evitar lazy-load
        // contra tabela inexistente.
        $company->setRelation('mlToken', null);

        $provider = $factory->for($company);

        $this->assertInstanceOf(AdmanSugadoresProvider::class, $provider);
    }

    // ─────────── Test 3: for(company, 'ml') retorna MlProvider (Plan 39-02) ───────────

    public function test_for_with_force_name_ml_returns_ml_provider(): void
    {
        $factory = $this->makeFactory();
        $company = $this->makeCompanyWithMlTokenStub();

        $provider = $factory->for($company, 'ml');

        $this->assertInstanceOf(MercadoLivreSugadoresProvider::class, $provider);
        $this->assertSame('ml', $provider->name());
    }

    // ─────────── Test 4: for(company) sem provider compatível lança RuntimeException ───────────

    public function test_for_throws_runtimeexception_when_no_provider_supports_company(): void
    {
        $factory = $this->makeFactory();
        // Sem adman_account_id E sem mlToken — nenhum provider suporta.
        $company = $this->makeCompany(['adman_account_id' => null]);
        // Eager-set a relação como null para evitar lazy-load no SQLite em-memory
        // sem tabela ml_tokens (test Unit não usa RefreshDatabase).
        $company->setRelation('mlToken', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sem provider compatível');

        $factory->for($company);
    }

    // ─────────── Test 5 (Plan 39-02 → Phase 42 Plan 42-04 cut-over): default prefere ML ───────────
    // Antes da Phase 42, Adman ganhava quando ambos suportam (regra travada).
    // Após o cut-over de Plan 42-04 (D-05), ML passa a ser preferido quando empresa
    // tem mlToken active — Adman vira fallback. Teste atualizado para refletir nova realidade.

    public function test_for_default_prefers_ml_when_both_providers_support(): void
    {
        $factory = $this->makeFactory();
        // Empresa com adman_account_id E mlToken active — ambos suportam.
        $company = $this->makeCompanyWithMlTokenStub(['adman_account_id' => '12345']);

        $provider = $factory->for($company);

        // Phase 42 D-05: ML ganha quando ambos suportam.
        $this->assertInstanceOf(MercadoLivreSugadoresProvider::class, $provider);
        $this->assertSame('ml', $provider->name());
    }

    // ─────────── Test 6 (Plan 39-02): fallback para ML quando só ML suporta ───────────

    public function test_for_default_falls_back_to_ml_when_only_ml_supports(): void
    {
        $factory = $this->makeFactory();
        // Empresa SEM adman_account_id, com mlToken active.
        $company = $this->makeCompanyWithMlTokenStub(['adman_account_id' => null]);

        $provider = $factory->for($company);

        $this->assertInstanceOf(MercadoLivreSugadoresProvider::class, $provider);
        $this->assertSame('ml', $provider->name());
    }
}
