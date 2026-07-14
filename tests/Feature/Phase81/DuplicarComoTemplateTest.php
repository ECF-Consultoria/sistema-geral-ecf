<?php

namespace Tests\Feature\Phase81;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 81 Plan 01 — Testes de duplicarComoTemplate() (UX-03).
 *
 * Cobre:
 *   - Cópia fiel do payload (título e listing_tier INTACTOS, sem sufixo de tier)
 *   - Zeragem dos três ml_item_id* (ml_item_id, ml_item_id_classico, ml_item_id_premium)
 *   - Status = STATUS_RASCUNHO no template criado
 *   - company_id e mlb_empresa_id copiados do original
 *   - 403 para consultor (middleware role:admin bloqueia antes do controller)
 *   - Rota mlb.anuncios.rascunho.duplicar-template registrada corretamente
 *
 * Estratégia: RefreshDatabase (SQLite in-memory). Não precisa Http::fake
 * pois o método não chama a API do Mercado Livre.
 *
 * @group phase81
 */
class DuplicarComoTemplateTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers de fixture ───

    /** Cria usuário admin com e-mail verificado. */
    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /** Cria usuário consultor (sem role:admin). */
    private function criarConsultor(): User
    {
        return User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria Company + MlToken + MlbEmpresa + MlAnuncioRascunho prontos para o teste.
     * Retorna [$rascunho, $empresa, $admin].
     */
    private function criarFixture(array $overrideRascunho = []): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo (necessário para a empresa ter conta conectada)
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $empresa = MlbEmpresa::create([
            'nome'           => 'Empresa Teste ' . $company->id,
            'tipo'           => 'ASSESSORIA',
            'company_id'     => $company->id,
            'responsavel_id' => $admin->id,
        ]);

        // Rascunho base — publicado com título e tier definidos
        $rascunhoBase = [
            'company_id'     => $company->id,
            'mlb_empresa_id' => $empresa->id,
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [
                'title'           => 'Sofá 3 Lugares',
                'price'           => 1299.90,
                'currency_id'     => 'BRL',
                'listing_type_id' => 'gold_pro',
                'condition'       => 'new',
            ],
            'status'       => MlAnuncioRascunho::STATUS_PUBLICADO,
            'listing_tier' => 'premium',
        ];

        $rascunho = MlAnuncioRascunho::create(array_merge($rascunhoBase, $overrideRascunho));

        return [$rascunho, $empresa, $admin];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Testes de comportamento do template
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function duplicar_template_copia_payload_e_mantem_titulo_e_tier(): void
    {
        // Rascunho publicado com tier premium e listing_type_id gold_pro
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'premium',
            'payload'      => [
                'title'           => 'Sofá 3 Lugares',
                'price'           => 1299.90,
                'currency_id'     => 'BRL',
                'listing_type_id' => 'gold_pro',
                'condition'       => 'new',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-template");

        $response->assertOk()->assertJson(['ok' => true]);

        // Carrega o template criado diretamente do banco para validação
        $template = MlAnuncioRascunho::find($response->json('rascunho.id'));
        $this->assertNotNull($template, 'Template não foi criado no banco');

        // Título deve ser IDÊNTICO ao original (sem sufixo de tier — UX-03)
        $this->assertEquals('Sofá 3 Lugares', $template->payload['title'] ?? null,
            'Título do template deve ser cópia fiel, sem sufixo de tier');

        // Tier mantido (NÃO troca de classico para premium nem vice-versa)
        $this->assertEquals('premium', $template->listing_tier,
            'listing_tier deve ser preservado do original');

        // listing_type_id mantido (gold_pro permanece gold_pro)
        $this->assertEquals('gold_pro', $template->payload['listing_type_id'] ?? null,
            'listing_type_id deve ser preservado do payload original');

        // category_id e sku_origem copiados
        $this->assertEquals($rascunho->category_id, $template->category_id);
    }

    /** @test */
    public function duplicar_template_zera_os_tres_ml_item_ids(): void
    {
        // Rascunho publicado com todos os ml_item_ids preenchidos
        [$rascunho, , $admin] = $this->criarFixture([
            'status'              => MlAnuncioRascunho::STATUS_PUBLICADO,
            'ml_item_id'          => 'MLB-ORIGINAL',
            'ml_item_id_classico' => 'MLB-ORIGINAL-C',
            'ml_item_id_premium'  => 'MLB-ORIGINAL-P',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-template");

        $response->assertOk();

        $template = MlAnuncioRascunho::find($response->json('rascunho.id'));
        $this->assertNotNull($template);

        // UX-03: os três ml_item_id* devem nascer null no template (novo anúncio)
        $this->assertNull($template->ml_item_id,          'ml_item_id deve ser null no template');
        $this->assertNull($template->ml_item_id_classico, 'ml_item_id_classico deve ser null no template');
        $this->assertNull($template->ml_item_id_premium,  'ml_item_id_premium deve ser null no template');
    }

    /** @test */
    public function duplicar_template_nasce_com_status_rascunho(): void
    {
        [$rascunho, , $admin] = $this->criarFixture([
            'status' => MlAnuncioRascunho::STATUS_PUBLICADO,
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-template");

        $response->assertOk();

        $template = MlAnuncioRascunho::find($response->json('rascunho.id'));
        $this->assertNotNull($template);

        // Template deve voltar a STATUS_RASCUNHO independente do status do original
        $this->assertEquals(
            MlAnuncioRascunho::STATUS_RASCUNHO,
            $template->status,
            'Template criado deve ter status rascunho'
        );
    }

    /** @test */
    public function duplicar_template_copia_company_e_mlb_empresa(): void
    {
        [$rascunho, $empresa, $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-template");

        $response->assertOk();

        $template = MlAnuncioRascunho::find($response->json('rascunho.id'));
        $this->assertNotNull($template);

        // company_id e mlb_empresa_id devem ser copiados do original (imutáveis — SEL-03)
        $this->assertEquals($rascunho->company_id,     $template->company_id,
            'company_id deve ser copiado do original');
        $this->assertEquals($rascunho->mlb_empresa_id, $template->mlb_empresa_id,
            'mlb_empresa_id deve ser copiado do original');
    }

    /** @test */
    public function duplicar_template_retorna_403_para_consultor(): void
    {
        [$rascunho] = $this->criarFixture();
        $consultor  = $this->criarConsultor();

        // Middleware role:admin bloqueia antes do controller chegar ao SEL-04
        $this->actingAs($consultor)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-template")
            ->assertForbidden();
    }

    /** @test */
    public function rota_duplicar_template_esta_registrada(): void
    {
        // Verifica que a rota nomeada existe e gera a URL correta
        $url = route('mlb.anuncios.rascunho.duplicar-template', ['rascunho' => 999]);
        $this->assertStringContainsString('/mlb/anuncios/rascunho/999/duplicar-template', $url);
    }
}
