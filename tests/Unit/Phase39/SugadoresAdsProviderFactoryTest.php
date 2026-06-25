<?php

// Phase 39 Plan 39-01 — Testes Unit do SugadoresAdsProviderFactory.
// Valida a resolução por (forceName) e por capability detection via supports().
// Plan 39-02 expandirá o factory para incluir MercadoLivreSugadoresProvider.

namespace Tests\Unit\Phase39;

use App\Models\Company;
use App\Services\AdmanService;
use App\Services\Sugadores\AdmanSugadoresProvider;
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
     * factory que só leem $company->adman_account_id via AdmanSugadoresProvider::supports().
     */
    private function makeCompany(array $attrs = []): Company
    {
        $company = new Company();
        $defaults = ['id' => 999, 'name' => 'Teste', 'adman_account_id' => '12345', 'marketplace' => 'meli'];
        $company->setRawAttributes(array_merge($defaults, $attrs));
        return $company;
    }

    private function makeFactory(): SugadoresAdsProviderFactory
    {
        // Composição real do AdmanSugadoresProvider em torno de um Mockery do AdmanService.
        $adman         = Mockery::mock(AdmanService::class);
        $admanProvider = new AdmanSugadoresProvider($adman);
        return new SugadoresAdsProviderFactory($admanProvider);
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

        $provider = $factory->for($company);

        $this->assertInstanceOf(AdmanSugadoresProvider::class, $provider);
    }

    // ─────────── Test 3: for(company, 'ml') lança RuntimeException no Plan 39-01 ───────────

    public function test_for_with_force_name_ml_throws_runtimeexception_in_plan_39_01(): void
    {
        $factory = $this->makeFactory();
        $company = $this->makeCompany();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plan 39-02');

        $factory->for($company, 'ml');
    }

    // ─────────── Test 4: for(company) lança RuntimeException quando empresa sem provider compatível ───────────

    public function test_for_throws_runtimeexception_when_no_provider_supports_company(): void
    {
        $factory = $this->makeFactory();
        $company = $this->makeCompany(['adman_account_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('sem provider compatível');

        $factory->for($company);
    }
}
