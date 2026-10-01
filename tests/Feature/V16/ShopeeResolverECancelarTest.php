<?php

namespace Tests\Feature\V16;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 78 (DEC-78-2/4): resolver pendência (atribui responsáveis Shopee +
 * email, por-serviço) e cancelar SÓ o serviço Shopee. Ambos gated por
 * permission:shopee.empresas + guard de escopo (anti-IDOR).
 */
class ShopeeResolverECancelarTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Empresa com contrato Shopee ativo (sem responsáveis). */
    private function empresaShopee(): array
    {
        $company    = Company::factory()->create();
        $servShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $this->criarContrato($company->id, $servShopee, true);

        return [$company, $servShopee];
    }

    public function test_resolver_atribui_responsaveis_shopee_e_email(): void
    {
        [$company, $servShopee] = $this->empresaShopee();
        $analista     = User::factory()->create();
        $estrategista = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.resolver'), [
                'company_id'      => $company->id,
                'analista_id'     => $analista->id,
                'estrategista_id' => $estrategista->id,
                'email_cliente'   => 'contato@empresa.com',
            ])
            ->assertRedirect();

        // Responsáveis gravados POR-SERVIÇO shopee.
        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id, 'user_id' => $analista->id,
            'role' => 'consultor', 'servico_id' => $servShopee,
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id, 'user_id' => $estrategista->id,
            'role' => 'estrategista', 'servico_id' => $servShopee,
        ]);
        $this->assertSame('contato@empresa.com', $company->fresh()->email_cliente);
    }

    /**
     * Trocar o responsável de uma empresa que JÁ tem um: o slot Shopee daquele
     * papel fica com UMA linha só, a do novo. (A aba Todas agora abre este mesmo
     * popup pelo botão "Responsáveis" — antes só empresa pendente chegava aqui.)
     */
    public function test_resolver_troca_responsavel_ja_existente(): void
    {
        [$company, $servShopee] = $this->empresaShopee();
        $antigo = User::factory()->create();
        $novo   = User::factory()->create();
        $this->inserirPivot($company->id, $antigo->id, 'consultor', $servShopee);

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.resolver'), [
                'company_id'  => $company->id,
                'analista_id' => $novo->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id, 'user_id' => $novo->id,
            'role' => 'consultor', 'servico_id' => $servShopee,
        ]);
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $company->id, 'user_id' => $antigo->id,
            'role' => 'consultor', 'servico_id' => $servShopee,
        ]);
    }

    /**
     * Campo enviado VAZIO ("— sem analista —") remove o responsável. É o que
     * diferencia a presença da chave do seu preenchimento: antes o vazio era
     * ignorado em silêncio e a tela ainda dizia que tinha atualizado.
     */
    public function test_resolver_remove_responsavel_quando_o_campo_vem_vazio(): void
    {
        [$company, $servShopee] = $this->empresaShopee();
        $analista = User::factory()->create();
        $this->inserirPivot($company->id, $analista->id, 'consultor', $servShopee);

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.resolver'), [
                'company_id'  => $company->id,
                'analista_id' => '',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('company_users', [
            'company_id' => $company->id, 'role' => 'consultor', 'servico_id' => $servShopee,
        ]);
    }

    /**
     * Remoção do responsável Shopee NÃO alcança o slot do outro canal — mesma
     * garantia por-serviço do Pattern 4 / T-76-02, agora no caminho do vazio.
     */
    public function test_remocao_shopee_nao_toca_o_responsavel_ml(): void
    {
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $shopee  = $this->inserirLinhaShopee($company->id, User::factory()->create()->id, 'consultor');

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.resolver'), [
                'company_id'  => $company->id,
                'analista_id' => '',
            ])
            ->assertRedirect();

        // Slot Shopee vazio; slot performance (ML) intacto.
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $company->id, 'role' => 'consultor',
            'servico_id' => $shopee['servicoShopee'],
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id, 'user_id' => $cenario['analista']->id,
            'role' => 'consultor', 'servico_id' => $cenario['servicoPerf'],
        ]);
    }

    public function test_cancelar_servico_desativa_so_o_contrato_shopee(): void
    {
        // Empresa multi-marketplace: ML (performance) + Shopee ativos.
        $cenario = $this->criarCenarioMlComResponsaveis();
        $company = $cenario['company'];
        $servShopee = $this->criarServico(Servico::SETOR_SHOPEE, true);
        $ctShopee = $this->criarContrato($company->id, $servShopee, true);

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.cancelar-servico'), ['company_id' => $company->id])
            ->assertRedirect();

        // Contrato Shopee desativado; contrato ML (performance) permanece; empresa existe.
        $this->assertDatabaseHas('contratos_servico', ['id' => $ctShopee, 'ativo' => false]);
        $this->assertDatabaseHas('contratos_servico', [
            'company_id' => $company->id, 'servico_id' => $cenario['servicoPerf'], 'ativo' => true,
        ]);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_gate_403_para_usuario_sem_a_key(): void
    {
        [$company] = $this->empresaShopee();
        $semKey = User::factory()->create(['role' => 'consultor']); // não-admin, sem shopee.empresas

        $this->actingAs($semKey)
            ->post(route('shopee.empresas.resolver'), ['company_id' => $company->id])
            ->assertForbidden();

        $this->actingAs($semKey)
            ->post(route('shopee.empresas.cancelar-servico'), ['company_id' => $company->id])
            ->assertForbidden();
    }

    public function test_guard_escopo_rejeita_empresa_fora_do_shopee(): void
    {
        // Empresa ML-only (sem contrato shopee) → fora do escopo.
        $cenario = $this->criarCenarioMlComResponsaveis();
        $mlOnly  = $cenario['company'];
        $analista = User::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('shopee.empresas.resolver'), [
                'company_id'  => $mlOnly->id,
                'analista_id' => $analista->id,
            ])
            ->assertSessionHasErrors('company_id');

        // Nada gravado para a empresa ML-only no slot shopee.
        $this->assertDatabaseMissing('company_users', [
            'company_id' => $mlOnly->id, 'user_id' => $analista->id, 'role' => 'consultor',
        ]);
    }
}
