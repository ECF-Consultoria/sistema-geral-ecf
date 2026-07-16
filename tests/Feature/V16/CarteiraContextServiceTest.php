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
 * diferentes / mesmo profissional nos dois serviços da mesma empresa) mais os
 * ramos de compatibilidade legado (CTX-05), Mentoria sem hardcode (CTX-03),
 * empresa inativa e filtros.
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

    // ─── Ramos legado / prioridade CTX-05 ─────────────────────────────────────

    public function test_ctx05a_servico_id_null_com_contrato_performance_ativo_resolve_como_legado(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $vinculo = $vinculos->first();

        $this->assertSame(Servico::SETOR_PERFORMANCE, $vinculo['setor']);
        $this->assertNull($vinculo['servico_id']);
        $this->assertNull($vinculo['servico_nome']);
        $this->assertTrue($vinculo['financial_metrics_eligible']);
    }

    public function test_ctx05b_servico_id_null_com_contrato_shopee_ativo_nao_assume_responsavel_shopee(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servicoShopee, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(0, $vinculos);
    }

    public function test_ctx05c_linha_com_servico_id_preenchido_tem_prioridade_sobre_linha_null(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        // Mesmo user/company/role: uma linha com servico_id preenchido, outra null.
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);
        $this->inserirPivot($company->id, $user->id, 'consultor', null);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $this->assertSame($servicoPerf, $vinculos->first()['servico_id']);
    }

    // ─── CTX-03: Mentoria sem hardcode ────────────────────────────────────────

    public function test_ctx03_mentoria_elegivel_via_setor_sem_hardcode_de_servico_id(): void
    {
        $company = Company::factory()->create();
        $user    = User::factory()->create();

        // Segundo serviço de setor performance com nome "Mentoria" — o id gerado
        // pelo autoincrement é qualquer um, provando ausência de hardcode.
        $servicoMentoria = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoMentoria, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoMentoria);

        $vinculos = $this->service->forUser($user);

        $this->assertCount(1, $vinculos);
        $this->assertTrue($vinculos->first()['financial_metrics_eligible']);
    }

    // ─── Empresa inativa ───────────────────────────────────────────────────────

    public function test_empresa_inativa_nao_entra_no_retorno_default_mas_entra_com_filtro_active_false(): void
    {
        $company = Company::factory()->create(['active' => false]);
        $user    = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);
        $this->inserirPivot($company->id, $user->id, 'consultor', $servicoPerf);

        $this->assertCount(0, $this->service->forUser($user));
        $this->assertCount(1, $this->service->forUser($user, ['active' => false]));
    }

    // ─── Filtros ────────────────────────────────────────────────────────────

    public function test_filtro_setor_restringe_vinculos_retornados(): void
    {
        $vinculos = $this->cenarioMesmoProfissionalDoisServicos();
        $user     = $vinculos->first()['user_id'];
        $user     = User::find($user);

        $soShopee = $this->service->forUser($user, ['setor' => Servico::SETOR_SHOPEE]);
        $this->assertCount(1, $soShopee);
        $this->assertSame(Servico::SETOR_SHOPEE, $soShopee->first()['setor']);

        $soPerformance = $this->service->forUser($user, ['setor' => Servico::SETOR_PERFORMANCE]);
        $this->assertCount(1, $soPerformance);
        $this->assertSame(Servico::SETOR_PERFORMANCE, $soPerformance->first()['setor']);
    }

    public function test_filtro_role_restringe_vinculos_retornados(): void
    {
        $companyConsultor = Company::factory()->create();
        $companyEstrategista = Company::factory()->create();
        $user = User::factory()->create();

        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($companyConsultor->id, $servicoPerf, true);
        $this->criarContrato($companyEstrategista->id, $servicoPerf, true);
        $this->inserirPivot($companyConsultor->id, $user->id, 'consultor', $servicoPerf);
        $this->inserirPivot($companyEstrategista->id, $user->id, 'estrategista', $servicoPerf);

        $soEstrategista = $this->service->forUser($user, ['role' => 'estrategista']);

        $this->assertCount(1, $soEstrategista);
        $this->assertSame('estrategista', $soEstrategista->first()['role']);
        $this->assertSame($companyEstrategista->id, $soEstrategista->first()['company_id']);
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
