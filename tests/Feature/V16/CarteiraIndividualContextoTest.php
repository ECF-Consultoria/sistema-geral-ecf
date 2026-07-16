<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 89 Plan 01 — CART-01/02.
 *
 * Cobre o agrupamento por empresa (`renderCarteiraProfissional()` consumindo
 * `CarteiraContextService::forUser()`): empresa com Performance+Shopee do
 * mesmo profissional aparece 1x, com `servicos[]` listando os 2 vinculos
 * separadamente; o vinculo Shopee sempre com `financial_metrics_eligible
 * === false`.
 */
class CarteiraIndividualContextoTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * CART-01 — profissional com Performance+Shopee da MESMA empresa:
     * a empresa aparece 1x em `empresas[]`, com `servicos` contendo as 2
     * entradas (setores 'performance' e 'shopee').
     */
    public function test_empresa_performance_e_shopee_aparece_1x_com_2_servicos(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $servicos = $props['empresas'][0]['servicos'];
        $this->assertCount(2, $servicos, 'Empresa com 2 vinculos do mesmo profissional deve expor 2 entradas em servicos[].');

        $setores = collect($servicos)->pluck('setor')->sort()->values()->all();
        $this->assertSame(['performance', 'shopee'], $setores);

        foreach ($servicos as $s) {
            $this->assertSame('Analista', $s['role_label']);
        }
    }

    /**
     * CART-02 — a entrada 'shopee' de servicos tem financial_metrics_eligible
     * === false; a entrada 'performance' tem === true.
     */
    public function test_entrada_shopee_nao_elegivel_performance_elegivel(): void
    {
        $admin   = $this->criarAdmin();
        $cenario = $this->criarCenarioMlComResponsaveis();
        $this->inserirLinhaShopee($cenario['company']->id, $cenario['analista']->id, 'consultor');

        $response = $this->actingAs($admin)->get(route('portfolio.show', $cenario['analista']));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $servicos = collect($props['empresas'][0]['servicos'])->keyBy('setor');

        $this->assertFalse($servicos['shopee']['financial_metrics_eligible']);
        $this->assertTrue($servicos['performance']['financial_metrics_eligible']);
    }

    /**
     * Mentoria — vinculo apontando pra um SEGUNDO serviço de setor
     * performance (não hardcoded por servico_id) também deve ser elegível
     * financeiramente e entrar na empresa normalmente.
     */
    public function test_segundo_servico_performance_setor_tambem_elegivel(): void
    {
        $admin = $this->criarAdmin();

        $company = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoMentoria = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoMentoria, true);

        $profissional = User::factory()->create();
        $this->inserirPivot($company->id, $profissional->id, 'consultor', $servicoMentoria);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $servicos = $props['empresas'][0]['servicos'];
        $this->assertCount(1, $servicos);
        $this->assertTrue($servicos[0]['financial_metrics_eligible']);
    }

    /**
     * Legado — vinculo com servico_id NULL em empresa com contrato
     * performance ativo continua aparecendo em servicos com setor
     * 'performance' e elegível (ramo CTX-05 atravessando o controller).
     */
    public function test_vinculo_legado_servico_id_null_aparece_elegivel(): void
    {
        $admin = $this->criarAdmin();

        $company     = Company::factory()->create(['adman_account_id' => 'ACC-' . uniqid()]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $profissional = User::factory()->create();
        $this->inserirPivot($company->id, $profissional->id, 'consultor', null);

        $response = $this->actingAs($admin)->get(route('portfolio.show', $profissional));
        $response->assertOk();
        $props = $response->viewData('page')['props'];

        $this->assertCount(1, $props['empresas']);
        $servicos = $props['empresas'][0]['servicos'];
        $this->assertCount(1, $servicos);
        $this->assertSame('performance', $servicos[0]['setor']);
        $this->assertTrue($servicos[0]['financial_metrics_eligible']);
    }
}
