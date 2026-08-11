<?php

namespace Tests\Feature\Phase136;

use App\Models\Company;
use App\Services\Metrics\FinancialSourceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 136 (D-10) — cobertura direta de `FinancialSourceResolver`, sem
 * passar pelo motor de nota. O caso NOVO desta suíte (carteira mista SEM
 * `cust_id`) é o gap que a `Phase119\CompanyScoreServiceFonteTest` nunca
 * cobriu — lá a fixture `montarCenarioMisto()` sempre seta
 * `adman_account_id` válido.
 *
 * @see .planning/phases/136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-/136-01-PLAN.md
 * @see app/Services/Metrics/FinancialSourceResolver.php
 */
class FinancialSourceResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste desta suíte pode disparar HTTP real à Adman.
        Http::preventStrayRequests();
    }

    private function resolver(): FinancialSourceResolver
    {
        return app(FinancialSourceResolver::class);
    }

    /** Vínculos de uma carteira mista (performance + shopee) para 1 empresa. */
    private function vinculosMistos(int $companyId): \Illuminate\Support\Collection
    {
        return collect([
            ['company_id' => $companyId, 'financial_source' => 'adman', 'financial_metrics_eligible' => true],
            ['company_id' => $companyId, 'financial_source' => 'shopee', 'financial_metrics_eligible' => true],
        ]);
    }

    #[Test]
    public function test_carteira_mista_sem_cust_id_resolve_shopee(): void
    {
        $empresa = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa(
            $this->vinculosMistos($empresa->id),
            collect([$empresa->id => $empresa])
        );

        $this->assertSame('shopee', $resultado->get($empresa->id),
            "'adman' não pode vencer sem a empresa ter conta Adman de fato (D-10).");
    }

    #[Test]
    public function test_carteira_mista_com_adman_account_id_preenchido_resolve_adman(): void
    {
        $empresa = Company::factory()->create([
            'adman_account_id' => 'CUST-136-ADMAN',
            'ml_store_id'      => null,
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa(
            $this->vinculosMistos($empresa->id),
            collect([$empresa->id => $empresa])
        );

        $this->assertSame('adman', $resultado->get($empresa->id));
    }

    #[Test]
    public function test_carteira_mista_com_adman_account_id_nulo_mas_ml_store_id_preenchido_resolve_adman(): void
    {
        // Este é o caso que o critério literal (`adman_account_id` cru)
        // quebraria — é a razão de existir do accessor `cust_id`.
        $empresa = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => 'ML-136-STORE',
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa(
            $this->vinculosMistos($empresa->id),
            collect([$empresa->id => $empresa])
        );

        $this->assertSame('adman', $resultado->get($empresa->id));
    }

    #[Test]
    public function test_carteira_mista_com_adman_account_id_string_vazia_e_ml_store_id_nulo_resolve_shopee(): void
    {
        // O accessor `cust_id` devolve `null` para string vazia.
        $empresa = Company::factory()->create([
            'adman_account_id' => '',
            'ml_store_id'      => null,
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa(
            $this->vinculosMistos($empresa->id),
            collect([$empresa->id => $empresa])
        );

        $this->assertSame('shopee', $resultado->get($empresa->id));
    }

    #[Test]
    public function test_vinculo_so_performance_sem_cust_id_resolve_adman_por_ausencia_de_alternativa(): void
    {
        // Terceiro ramo do match — comportamento preservado quando não há
        // fonte alternativa real. D-10 corrige o DESEMPATE entre fontes
        // concorrentes, não inventa um quarto estado "sem fonte nenhuma".
        $empresa = Company::factory()->create([
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ]);

        $vinculos = collect([
            ['company_id' => $empresa->id, 'financial_source' => 'adman', 'financial_metrics_eligible' => true],
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa($vinculos, collect([$empresa->id => $empresa]));

        $this->assertSame('adman', $resultado->get($empresa->id));
    }

    #[Test]
    public function test_vinculo_so_shopee_resolve_shopee(): void
    {
        $empresa = Company::factory()->create();

        $vinculos = collect([
            ['company_id' => $empresa->id, 'financial_source' => 'shopee', 'financial_metrics_eligible' => true],
        ]);

        $resultado = $this->resolver()->resolverPorEmpresa($vinculos, collect([$empresa->id => $empresa]));

        $this->assertSame('shopee', $resultado->get($empresa->id));
    }

    #[Test]
    public function test_empresa_ausente_de_companies_by_id_nao_lanca_excecao_e_cai_no_adman_invalido(): void
    {
        $companyId = 999999;

        $vinculos = collect([
            ['company_id' => $companyId, 'financial_source' => 'adman', 'financial_metrics_eligible' => true],
            ['company_id' => $companyId, 'financial_source' => 'shopee', 'financial_metrics_eligible' => true],
        ]);

        // $companiesById vazio — empresa NÃO carregada pelo chamador.
        $resultado = $this->resolver()->resolverPorEmpresa($vinculos, collect());

        $this->assertSame('shopee', $resultado->get($companyId),
            'Empresa ausente de $companiesById deve cair no ramo "adman inválido" (company === null), nunca lançar exceção.');
    }
}
