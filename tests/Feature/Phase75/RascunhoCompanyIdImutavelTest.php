<?php

namespace Tests\Feature\Phase75;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 75 — SEL-03: company_id e mlb_empresa_id imutáveis após criação do rascunho.
 *
 * O POST /mlb/anuncios/rascunho agora recebe `company_id` (não mlb_empresa_id).
 * A âncora é a Company com ml_token; mlb_empresa_id é derivado server-side (opcional).
 * O PUT /rascunho/{id} ignora qualquer company_id/mlb_empresa_id enviado no corpo.
 *
 * @group phase75
 */
class RascunhoCompanyIdImutavelTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ───

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria uma Company com MlToken associado.
     *
     * salvarRascunho() aborta 422 quando a company não tem ml_token, então
     * os testes desta suite precisam de token para que o rascunho seja gravado.
     */
    private function criarCompanyComToken(): Company
    {
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => 'USER_' . uniqid(),
            'access_token'  => 'token_test',
            'refresh_token' => 'refresh_test',
            'status'        => 'active',
            'expires_at'    => now()->addDays(30),
            'connected_at'  => now()->subDays(5),
        ]);

        return $company;
    }

    // ─── Testes ───

    /**
     * POST rascunho com company_id de company COM token grava company_id correto.
     *
     * Valida que:
     * - company_id é gravado no banco
     * - user_id é derivado server-side (não enviado pelo cliente)
     * - status nasce como 'rascunho'
     */
    public function test_salvar_rascunho_grava_company_id_e_user_id(): void
    {
        $admin   = $this->criarAdmin();
        $company = $this->criarCompanyComToken();

        $response = $this->actingAs($admin)
            ->postJson('/mlb/anuncios/rascunho', [
                'company_id' => $company->id,
                'payload'    => ['title' => 'Produto Teste'],
            ]);

        $response->assertOk();

        // company_id e user_id são gravados corretamente; mlb_empresa_id pode ser null
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'company_id' => $company->id,
            'user_id'    => $admin->id,
            'status'     => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);
    }

    /**
     * POST rascunho com company SEM ml_token → 422 (company sem conta ML).
     */
    public function test_salvar_rascunho_company_sem_token_retorna_422(): void
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create(); // sem MlToken

        $this->actingAs($admin)
            ->postJson('/mlb/anuncios/rascunho', [
                'company_id' => $company->id,
                'payload'    => ['title' => 'Produto Sem Conta'],
            ])
            ->assertStatus(422);
    }

    /**
     * SEL-03: PUT rascunho tentando trocar company_id/mlb_empresa_id → ignorado (imutável).
     *
     * Cria rascunho na company A e tenta alterar company_id para company B no PUT.
     * O rascunho deve permanecer vinculado à company A.
     */
    public function test_atualizar_rascunho_ignora_company_id_e_mlb_empresa_id(): void
    {
        $admin    = $this->criarAdmin();
        $companyA = $this->criarCompanyComToken();
        $companyB = $this->criarCompanyComToken();

        // Cria o rascunho na company A via POST
        $criarResponse = $this->actingAs($admin)
            ->postJson('/mlb/anuncios/rascunho', [
                'company_id' => $companyA->id,
                'payload'    => ['title' => 'Produto Original'],
            ]);

        $criarResponse->assertOk();
        $rascunhoId = $criarResponse->json('rascunho.id');
        $this->assertNotNull($rascunhoId, 'ID do rascunho deve ser retornado no POST');

        // Tenta trocar para company B enviando company_id e mlb_empresa_id da B no PUT
        $this->actingAs($admin)
            ->putJson("/mlb/anuncios/rascunho/{$rascunhoId}", [
                'company_id'     => $companyB->id,   // tentativa de troca — deve ser ignorada
                'mlb_empresa_id' => 9999,             // tentativa de troca — deve ser ignorada
                'category_id'    => 'MLB1234',
                'payload'        => ['title' => 'Produto Atualizado'],
            ])
            ->assertOk();

        // SEL-03: o rascunho deve permanecer vinculado à company A (troca ignorada)
        $rascunho = MlAnuncioRascunho::find($rascunhoId);
        $this->assertEquals($companyA->id, $rascunho->company_id, 'company_id NÃO deve mudar');
    }
}
