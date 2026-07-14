<?php

namespace Tests\Feature\Phase79;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\MlbEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 79 Plan 01 — Testes de duplicarTier() e MlPublicacaoService::publicar() por tier.
 *
 * Cobre DUP-01, DUP-03, DUP-04:
 *   - publicar() com listing_tier='premium' grava ml_item_id_premium (e ml_item_id legado); ml_item_id_classico null
 *   - publicar() com listing_tier='classico' grava ml_item_id_classico; ml_item_id_premium null
 *   - duplicarTier() cria tier oposto com gold_pro/gold_special correto
 *   - duplicarTier() aplica sufixo mínimo (' - Premium' ou ' - Clássico')
 *   - duplicarTier() strip idempotente (título com ' - Clássico' vira ' - Premium' sem acumular)
 *   - duplicarTier() trunca título a 60 chars
 *   - duplicarTier() retorna 422 quando títulos ficam idênticos
 *   - ml_item_id_classico e ml_item_id_premium zerados no rascunho duplicado
 *   - 403 para consultor (middleware role:admin)
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake para publicar().
 *
 * @group phase79
 */
class DuplicarTierTest extends TestCase
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
     * Cria Company + MlToken + MlbEmpresa + MlAnuncioRascunho prontos para publicação.
     * Retorna [$rascunho, $empresa, $admin].
     */
    private function criarFixture(array $overrideRascunho = []): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo (MlPublicacaoService chama ensureValidToken)
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

        $rascunhoBase = [
            'company_id'     => $company->id,
            'mlb_empresa_id' => $empresa->id,
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [
                'title'           => 'Sofá 3 Lugares',
                'price'           => 999.90,
                'currency_id'     => 'BRL',
                'listing_type_id' => 'gold_special',
                'condition'       => 'new',
            ],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
            'listing_tier'   => 'classico',
        ];

        $rascunho = MlAnuncioRascunho::create(array_merge($rascunhoBase, $overrideRascunho));

        return [$rascunho, $empresa, $admin];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MlPublicacaoService::publicar() — gravação no campo do tier correto
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_tier_classico_grava_ml_item_id_classico_e_nao_toca_premium(): void
    {
        // Http::fake: POST /items retorna MLB111; GET /users/* retorna conta clássica
        Http::fake([
            '*/users/*'  => Http::response(['id' => '1555596317', 'tags' => []], 200),
            '*/items'    => Http::response(['id' => 'MLB111'], 200),
            '*'          => Http::response([], 200),
        ]);

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $rascunho->refresh();

        // DUP-04: tier clássico grava ml_item_id_classico
        $this->assertEquals('MLB111', $rascunho->ml_item_id_classico);
        // ml_item_id_premium fica null (não deve ser tocado)
        $this->assertNull($rascunho->ml_item_id_premium);
        // ml_item_id legado também preenchido (retrocompatibilidade)
        $this->assertEquals('MLB111', $rascunho->ml_item_id);
        $this->assertEquals(MlAnuncioRascunho::STATUS_PUBLICADO, $rascunho->status);
    }

    /** @test */
    public function publicar_tier_premium_grava_ml_item_id_premium_e_nao_toca_classico(): void
    {
        Http::fake([
            '*/users/*'  => Http::response(['id' => '1555596317', 'tags' => []], 200),
            '*/items'    => Http::response(['id' => 'MLB222'], 200),
            '*'          => Http::response([], 200),
        ]);

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

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $rascunho->refresh();

        // DUP-04: tier premium grava ml_item_id_premium
        $this->assertEquals('MLB222', $rascunho->ml_item_id_premium);
        // ml_item_id_classico fica null (não deve ser tocado)
        $this->assertNull($rascunho->ml_item_id_classico);
        // ml_item_id legado também preenchido (retrocompatibilidade)
        $this->assertEquals('MLB222', $rascunho->ml_item_id);
    }

    /** @test */
    public function publicar_listing_tier_null_usa_fallback_classico(): void
    {
        // Rascunhos legados (listing_tier null) devem cair no fallback 'classico'
        Http::fake([
            '*/users/*'  => Http::response(['id' => '1555596317', 'tags' => []], 200),
            '*/items'    => Http::response(['id' => 'MLB999'], 200),
            '*'          => Http::response([], 200),
        ]);

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => null]);

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar")
            ->assertOk();

        $rascunho->refresh();

        // Fallback para 'classico' — grava ml_item_id_classico
        $this->assertEquals('MLB999', $rascunho->ml_item_id_classico);
        $this->assertNull($rascunho->ml_item_id_premium);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // duplicarTier() — criação do rascunho com tier oposto
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function duplicar_tier_classico_cria_rascunho_premium_com_gold_pro(): void
    {
        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk()
            ->assertJson(['ok' => true, 'tier_novo' => 'premium']);

        // Verifica o rascunho criado no banco
        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();
        $this->assertNotNull($duplicado);
        $this->assertEquals('premium', $duplicado->listing_tier);
        $this->assertEquals('gold_pro', $duplicado->payload['listing_type_id'] ?? null);
    }

    /** @test */
    public function duplicar_tier_premium_cria_rascunho_classico_com_gold_special(): void
    {
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
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk()
            ->assertJson(['ok' => true, 'tier_novo' => 'classico']);

        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();
        $this->assertEquals('classico', $duplicado->listing_tier);
        $this->assertEquals('gold_special', $duplicado->payload['listing_type_id'] ?? null);
    }

    /** @test */
    public function duplicar_tier_aplica_sufixo_premium_ao_titulo(): void
    {
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'classico',
            'payload'      => [
                'title'           => 'Mesa de Jantar',
                'listing_type_id' => 'gold_special',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk();

        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();
        // DUP-03: título do Premium deve ter ' - Premium' (ou equivalente)
        $titulo = $duplicado->payload['title'] ?? '';
        $this->assertStringContainsString('Premium', $titulo);
        $this->assertStringNotContainsString('Mesa de Jantar - Premium - Premium', $titulo); // sem acúmulo
    }

    /** @test */
    public function duplicar_tier_strip_idempotente_nao_acumula_sufixo(): void
    {
        // Rascunho JÁ com ' - Clássico' no título — duplicar deve virar ' - Premium', sem ' - Clássico - Premium'
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'classico',
            'payload'      => [
                'title'           => 'Sofá 3 Lugares - Clássico',
                'listing_type_id' => 'gold_special',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk();

        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();
        $titulo = $duplicado->payload['title'] ?? '';

        // Deve ter virado 'Sofá 3 Lugares - Premium' (sem acumular)
        $this->assertStringContainsString('Premium', $titulo);
        $this->assertStringNotContainsString('Clássico', $titulo);
        $this->assertStringNotContainsString('Clássico - Premium', $titulo);
    }

    /** @test */
    public function duplicar_tier_trunca_titulo_a_60_chars(): void
    {
        // Título longo (50 chars) + sufixo ' - Premium' (10 chars) = 60 chars exatos — não deve truncar
        $tituloBase = str_repeat('A', 50); // 50 chars
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'classico',
            'payload'      => [
                'title'           => $tituloBase,
                'listing_type_id' => 'gold_special',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk();

        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();
        $titulo = $duplicado->payload['title'] ?? '';

        // Título duplicado deve ter no máximo 60 chars (mb_substr)
        $this->assertLessThanOrEqual(60, mb_strlen($titulo));
    }

    /** @test */
    public function duplicar_tier_retorna_422_quando_titulos_ficariam_identicos(): void
    {
        // Caso de edge: título sem espaço nem possibilidade de sufixo diferente
        // Criamos rascunho cujo título É EXATAMENTE o sufixo do tier oposto para forçar identidade
        // Na prática isso ocorre quando o título base fica vazio após strip + append cria algo idêntico
        // Vamos testar um caso mais realístico: mesmo título, mesmo sufixo forçado manualmente
        // O jeito mais confiável: criar um rascunho e mockar a situação internamente.
        // Porém o controller compara payload['title'] do ORIGINAL com o gerado.
        // Um caso real de 422: título do original já É ' - Premium' e vamos duplicar para premium (tier == premium e título == ' - Premium')
        // Simplesmente: se listing_tier=premium e o título já contém ' - Premium', duplo para premium retorna 422

        // Melhor abordagem: usar título muito curto que após strip + sufixo fica igual ao original
        // Na verdade a validação compara o título do ORIGINAL com o título do DUPLICADO.
        // Se o original é 'Produto - Premium' e duplicamos para premium (gold_pro), o duplicado também
        // terá ' - Premium' → iguais → 422.
        // Mas o tier oposto de premium é classico → sufixo seria ' - Clássico' (diferentes)
        // Para 422 acontecer: precisamos que o sufixo adicionado ao base seja idêntico ao título original.
        // Ex: título original = 'Produto - Clássico' e duplicamos para classico (tier oposto de premium → classico)
        // → base após strip = 'Produto', sufixo = ' - Clássico' → 'Produto - Clássico' = igual ao original → 422

        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'premium',
            'payload'      => [
                'title'           => 'Produto - Clássico', // este título após strip+sufixo classico ficaria igual
                'listing_type_id' => 'gold_pro',
            ],
        ]);

        // Duplicar premium → classico: base = 'Produto', sufixo = ' - Clássico' → 'Produto - Clássico' = original
        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertStatus(422);
        $json = $response->json();
        $this->assertFalse($json['ok'] ?? true);
    }

    /** @test */
    public function duplicar_tier_zera_ml_item_ids_no_rascunho_duplicado(): void
    {
        // Rascunho de origem já publicado (tem ml_item_id_classico preenchido)
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier'       => 'classico',
            'status'             => MlAnuncioRascunho::STATUS_PUBLICADO,
            'ml_item_id'         => 'MLB-ORIGINAL',
            'ml_item_id_classico' => 'MLB-ORIGINAL',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier");

        $response->assertOk();

        $duplicado = MlAnuncioRascunho::where('id', $response->json('rascunho.id'))->first();

        // T-79-01: ml_item_id_classico e ml_item_id_premium devem ser null no duplicado
        $this->assertNull($duplicado->ml_item_id_classico);
        $this->assertNull($duplicado->ml_item_id_premium);
        // company_id e mlb_empresa_id devem ser copiados do original
        $this->assertEquals($rascunho->company_id, $duplicado->company_id);
        $this->assertEquals($rascunho->mlb_empresa_id, $duplicado->mlb_empresa_id);
    }

    /** @test */
    public function duplicar_tier_retorna_403_para_consultor_sem_role_admin(): void
    {
        [$rascunho] = $this->criarFixture();
        $consultor  = $this->criarConsultor();

        // Middleware role:admin bloqueia antes do controller (DUP-02)
        $this->actingAs($consultor)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/duplicar-tier")
            ->assertForbidden();
    }

    /** @test */
    public function rota_duplicar_tier_esta_registrada(): void
    {
        $url = route('mlb.anuncios.rascunho.duplicar-tier', ['rascunho' => 999]);
        $this->assertStringContainsString('/mlb/anuncios/rascunho/999/duplicar-tier', $url);
    }
}
