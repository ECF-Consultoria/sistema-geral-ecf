<?php

namespace Tests\Unit\Portfolio;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Portfolio\CarteiraContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 109 Plan 01 (SHOP-CAR-01) — branch `shopee` elegível em
 * `CarteiraContextService::flagsFinanceirasPorSetor()`.
 *
 * Decisão travada 2026-07-23: Shopee passa a ser fonte financeira elegível
 * (`financial_source='shopee'`), assim como Performance já era
 * (`financial_source='adman'`). Reusa `CriaCenarioResponsaveis` (fixtures de
 * servico/contrato/pivot por-serviço) já consagrada em `tests/Feature/V16/`.
 *
 * @see .planning/phases/109-shopee-em-carteira-e-desempenho-regra-do-ml-sem-margem-por-o/109-01-PLAN.md
 */
class CarteiraContextShopeeElegivelTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private CarteiraContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CarteiraContextService::class);
    }

    public function test_vinculo_shopee_vem_com_financial_metrics_eligible_true_e_source_shopee(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $this->inserirLinhaShopee($company->id, $user->id, 'consultor');

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertSame(Servico::SETOR_SHOPEE, $vinculo['setor']);
        $this->assertTrue($vinculo['has_financial_source']);
        $this->assertSame('shopee', $vinculo['financial_source']);
        $this->assertTrue($vinculo['financial_metrics_eligible']);
    }

    public function test_vinculo_performance_segue_financial_source_adman_regressao(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertSame('adman', $vinculo['financial_source']);
        $this->assertTrue($vinculo['financial_metrics_eligible']);
    }

    public function test_setor_desconhecido_segue_nao_elegivel_default_intocado(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoPolos = $this->criarServico(Servico::SETOR_POLOS, true);
        $this->criarContrato($company->id, $servicoPolos, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPolos);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertFalse($vinculo['has_financial_source']);
        $this->assertNull($vinculo['financial_source']);
        $this->assertFalse($vinculo['financial_metrics_eligible']);
    }
}
