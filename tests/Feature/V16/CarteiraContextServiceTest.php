<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use App\Services\Portfolio\CarteiraContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite Feature do CarteiraContextService — fundação da milestone v17.0.
 *
 * Por que este teste vive em tests/Feature/V16/ (não tests/Feature/V17/):
 * reusa a trait `CriaCenarioResponsaveis` (fixtures de servico/contrato/pivot
 * por-serviço) já existente neste namespace desde a Phase 76 (v16.0). O
 * diretório tests/Feature/V16/ é de uso EXCLUSIVO deste fluxo de trabalho —
 * o dev paralelo do módulo MLB/Anúncios usa tests/Feature/Phase77..82/, sem
 * overlap esperado.
 *
 * Cobre CTX-01..05 (ver 88-01-PLAN.md <behavior>): os 4 cenários canônicos do
 * plano canônico (só Performance / só Shopee / Performance+Shopee em pessoas
 * diferentes / mesmo profissional nos dois serviços da mesma empresa) — os
 * ramos de compatibilidade legado (CTX-05), Mentoria sem hardcode (CTX-03),
 * empresa inativa e filtros são adicionados na Task 2.
 */
class CarteiraContextServiceTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private CarteiraContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CarteiraContextService::class);
    }

    // ─── Cenários canônicos (CTX-01, CTX-02, CTX-04) ─────────────────────────

    public function test_cenario_1_so_performance_retorna_vinculo_com_shape_completo_e_flags_financeiras(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();

        $vinculos = $this->service->forUser($cenario['analista']);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertSame($cenario['company']->id, $vinculo['company_id']);
        $this->assertSame($cenario['company']->name, $vinculo['company_name']);
        $this->assertSame($cenario['servicoPerf'], $vinculo['servico_id']);
        $this->assertSame(Servico::SETOR_PERFORMANCE, $vinculo['setor']);
        $this->assertSame('consultor', $vinculo['role']);
        $this->assertSame('Analista', $vinculo['role_label']);
        $this->assertTrue($vinculo['has_financial_source']);
        $this->assertSame('adman', $vinculo['financial_source']);
        $this->assertTrue($vinculo['financial_metrics_eligible']);
    }

    public function test_cenario_2_so_shopee_retorna_vinculo_sem_fonte_financeira(): void
    {
        $company = Company::factory()->create();
        $gustavo = User::factory()->create();

        $this->inserirLinhaShopee($company->id, $gustavo->id, 'consultor');

        $vinculos = $this->service->forUser($gustavo);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertSame(Servico::SETOR_SHOPEE, $vinculo['setor']);
        $this->assertFalse($vinculo['has_financial_source']);
        $this->assertNull($vinculo['financial_source']);
        $this->assertFalse($vinculo['financial_metrics_eligible']);
    }

    public function test_cenario_3_performance_e_shopee_mesma_empresa_pessoas_diferentes_nao_vaza(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $gustavo = User::factory()->create();

        $this->inserirLinhaShopee($cenario['company']->id, $gustavo->id, 'consultor');

        $vinculosAnalistaMl = $this->service->forUser($cenario['analista']);
        $vinculosGustavo    = $this->service->forUser($gustavo);

        $this->assertCount(1, $vinculosAnalistaMl);
        $this->assertSame(Servico::SETOR_PERFORMANCE, $vinculosAnalistaMl->first()['setor']);

        $this->assertCount(1, $vinculosGustavo);
        $this->assertSame(Servico::SETOR_SHOPEE, $vinculosGustavo->first()['setor']);
    }

    public function test_cenario_4_mesmo_profissional_dois_servicos_mesma_empresa_nao_colapsa(): void
    {
        $vinculos = $this->cenarioMesmoProfissionalDoisServicos();

        $this->assertCount(2, $vinculos);
        $this->assertEqualsCanonicalizing(
            [Servico::SETOR_PERFORMANCE, Servico::SETOR_SHOPEE],
            $vinculos->pluck('setor')->all()
        );
    }

    public function test_contadores_dedup_empresas_unicas_vs_vinculos_de_servico(): void
    {
        $vinculos = $this->cenarioMesmoProfissionalDoisServicos();

        $contadores = $this->service->contadores($vinculos);

        $this->assertSame(1, $contadores['empresas_unicas']);
        $this->assertSame(2, $contadores['vinculos_servico']);
        $this->assertSame(1, $contadores['vinculos_financeiros']);
        $this->assertSame(1, $contadores['vinculos_sem_fonte_financeira']);
    }

    // ─── Helpers privados do teste (não vão para a trait — compartilhada com dev paralelo) ─

    /**
     * Monta o cenário "mesmo profissional nos dois serviços da mesma empresa"
     * (CTX-04) e retorna os vínculos já resolvidos via forUser().
     */
    private function cenarioMesmoProfissionalDoisServicos(): \Illuminate\Support\Collection
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);
        $this->inserirLinhaShopee($company->id, $user->id, 'consultor');

        return $this->service->forUser($user);
    }
}
