<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlbEmpresa;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 75 Plan 03 — SEL-03: company_id e mlb_empresa_id imutáveis após criação.
 *
 * Prova que o PUT /rascunho/{id} ignora qualquer company_id/mlb_empresa_id
 * enviado no corpo — a empresa fixada na criação nunca muda.
 *
 * @group phase75
 */
class RascunhoCompanyIdImutavelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───

    /** Cria um usuário admin. */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria uma MlbEmpresa com Company associada e responsavel_id definido.
     *
     * Não cria MlToken — os testes desta suite não precisam de token ML.
     */
    private function criarEmpresa(int $responsavelId): MlbEmpresa
    {
        $company = Company::factory()->create();

        return MlbEmpresa::create([
            'nome'           => 'Empresa Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $responsavelId,
        ]);
    }

    // ─── Testes ───

    /**
     * SEL-07: salvarRascunho() grava mlb_empresa_id e company_id derivado da empresa.
     *
     * Prova que o rascunho nasce ancorado na empresa: mlb_empresa_id obrigatório,
     * company_id copiado da empresa (não enviado pelo cliente).
     */
    public function test_salvar_rascunho_grava_mlb_empresa_id_e_company_id(): void
    {
        $admin   = $this->criarAdmin();
        $empresa = $this->criarEmpresa($admin->id);

        $response = $this->actingAs($admin)
            ->postJson('/mlb/anuncios/rascunho', [
                'mlb_empresa_id' => $empresa->id,
                'payload'        => ['title' => 'Produto Teste'],
            ]);

        $response->assertOk();

        // Verifica que mlb_empresa_id e company_id foram gravados corretamente no banco
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'mlb_empresa_id' => $empresa->id,
            'company_id'     => $empresa->company_id,
            'user_id'        => $admin->id,
        ]);
    }

    /**
     * SEL-03: atualizarRascunho() ignora company_id e mlb_empresa_id enviados no corpo.
     *
     * Mesmo que o cliente envie company_id/mlb_empresa_id da empresa B, o rascunho
     * criado na empresa A permanece vinculado à empresa A (mudança rejeitada silenciosamente).
     */
    public function test_atualizar_rascunho_ignora_company_id_e_mlb_empresa_id(): void
    {
        $admin    = $this->criarAdmin();
        $empresaA = $this->criarEmpresa($admin->id);
        $empresaB = $this->criarEmpresa($admin->id);

        // Cria o rascunho na empresa A via POST
        $criarResponse = $this->actingAs($admin)
            ->postJson('/mlb/anuncios/rascunho', [
                'mlb_empresa_id' => $empresaA->id,
                'payload'        => ['title' => 'Produto Original'],
            ]);

        $criarResponse->assertOk();
        $rascunhoId = $criarResponse->json('rascunho.id');
        $this->assertNotNull($rascunhoId, 'ID do rascunho deve ser retornado no POST');

        // Tenta trocar para empresa B enviando company_id e mlb_empresa_id da B no PUT
        $this->actingAs($admin)
            ->putJson("/mlb/anuncios/rascunho/{$rascunhoId}", [
                'company_id'     => $empresaB->company_id,   // tentativa de troca — deve ser ignorada
                'mlb_empresa_id' => $empresaB->id,           // tentativa de troca — deve ser ignorada
                'category_id'    => 'MLB1234',
                'payload'        => ['title' => 'Produto Atualizado'],
            ])
            ->assertOk();

        // SEL-03: o rascunho deve permanecer vinculado à empresa A (troca ignorada silenciosamente)
        $rascunho = MlAnuncioRascunho::find($rascunhoId);
        $this->assertEquals($empresaA->id, $rascunho->mlb_empresa_id, 'mlb_empresa_id NÃO deve mudar');
        $this->assertEquals($empresaA->company_id, $rascunho->company_id, 'company_id NÃO deve mudar');
    }
}
