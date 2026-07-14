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
 * Phase 79 Plan 01 — Testes de publicarDuplo() com Http::fake.
 *
 * Cobre DUP-02, DUP-03, DUP-04:
 *   (a) Publicação dupla grava MLB111 e MLB222 em ml_item_id_classico e ml_item_id_premium
 *       dos respectivos rascunhos (assertDatabaseHas por listing_tier)
 *   (b) Os 2 títulos publicados são diferentes (assert no payload dos rascunhos)
 *   (c) FALHA DO 2º tier: Http::fakeSequence sucesso→erro → ml_item_id do 1º tier permanece
 *       gravado no banco; resposta tem ok=true(1º) e ok=false(2º) com erros
 *   (d) 403 para consultor (middleware role:admin), como no análogo Phase75
 *   (e) 422 quando títulos do par ficariam idênticos (anti-duplicata DUP-03)
 *   (f) resposta JSON no shape correto: { ok, classico:{ok,ml_item_id,tier,erros}, premium:{...} }
 *
 * Estratégia: RefreshDatabase (SQLite in-memory) + Http::fake para interceptar
 * as chamadas do MercadoLivreService ao ML.
 *
 * Gotcha NPS: apenas suíte isolada — nunca migrate completo no MariaDB local.
 *
 * @group phase79
 */
class PublicarDuploTest extends TestCase
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
     * Cria Company + MlToken + MlbEmpresa + MlAnuncioRascunho (tier clássico por padrão).
     * Retorna [$rascunho, $empresa, $admin].
     */
    private function criarFixture(array $overrideRascunho = []): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo — MlPublicacaoService chama ensureValidToken internamente
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
                'title'           => 'Mesa de Jantar',
                'price'           => 899.90,
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

    /**
     * Configura Http::fake para duas chamadas POST /items com ids distintos.
     * Primeira chamada retorna MLB111, segunda retorna MLB222.
     * GET /users/* retorna conta clássica (sem tag user_product_seller).
     * POST /items/{id}/description retorna 200 vazio (após publicação de cada tier).
     */
    private function fakeDuploPorSequencia(): void
    {
        Http::fake([
            // Detectar modelo da conta: responde sempre como conta clássica
            '*/users/*' => Http::response(['id' => '1555596317', 'tags' => []], 200),
            // POST /items retorna ids sequenciais distintos
            '*/items'   => Http::sequence()
                ->push(['id' => 'MLB111'], 200)
                ->push(['id' => 'MLB222'], 200),
            // Descrição: sempre vazio/ok
            '*'         => Http::response([], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (a) Publicação dupla grava ids distintos nos campos separados por tier
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_duplo_grava_ids_distintos_em_campos_separados_por_tier(): void
    {
        $this->fakeDuploPorSequencia();

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo");

        $response->assertOk();
        $json = $response->json();

        // Resposta deve ter estrutura correta por tier
        $this->assertArrayHasKey('ok', $json);
        $this->assertArrayHasKey('classico', $json);
        $this->assertArrayHasKey('premium', $json);

        // Ambos os tiers devem ter publicado com ok=true
        $this->assertTrue($json['classico']['ok'] ?? false, 'Clássico deve ter publicado com ok=true');
        $this->assertTrue($json['premium']['ok']  ?? false, 'Premium deve ter publicado com ok=true');

        // ok geral = true (pelo menos um tier publicou)
        $this->assertTrue($json['ok']);

        // ml_item_id retornados devem ser distintos
        $idClassico = $json['classico']['ml_item_id'] ?? null;
        $idPremium  = $json['premium']['ml_item_id']  ?? null;
        $this->assertNotEquals($idClassico, $idPremium, 'Os dois ml_item_ids devem ser distintos');

        // DUP-04: verificar no banco que os campos separados foram gravados
        // O rascunho original (classico) deve ter ml_item_id_classico preenchido
        $rascunho->refresh();
        $this->assertEquals(MlAnuncioRascunho::STATUS_PUBLICADO, $rascunho->status);
        $this->assertNotNull($rascunho->ml_item_id_classico, 'ml_item_id_classico deve estar gravado');
        $this->assertNull($rascunho->ml_item_id_premium, 'ml_item_id_premium deve ser null no rascunho clássico');

        // O rascunho duplicado (premium) deve ter ml_item_id_premium preenchido
        $rascunhoPremium = MlAnuncioRascunho::where('listing_tier', 'premium')
            ->where('company_id', $rascunho->company_id)
            ->first();
        $this->assertNotNull($rascunhoPremium, 'Rascunho premium deve ter sido criado');
        $this->assertNotNull($rascunhoPremium->ml_item_id_premium, 'ml_item_id_premium deve estar gravado');
        $this->assertNull($rascunhoPremium->ml_item_id_classico, 'ml_item_id_classico deve ser null no rascunho premium');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (b) Os 2 títulos publicados são diferentes
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_duplo_gera_dois_titulos_diferentes(): void
    {
        $this->fakeDuploPorSequencia();

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo")
            ->assertOk();

        // Busca os dois rascunhos no banco
        $rascunhoPremium = MlAnuncioRascunho::where('listing_tier', 'premium')
            ->where('company_id', $rascunho->company_id)
            ->first();

        $this->assertNotNull($rascunhoPremium, 'Rascunho premium deve ter sido criado');

        $tituloClassico = $rascunho->payload['title']         ?? '';
        $tituloPremium  = $rascunhoPremium->payload['title']  ?? '';

        // DUP-03: os dois títulos devem ser diferentes
        $this->assertNotEquals(
            $tituloClassico,
            $tituloPremium,
            "Os títulos devem ser diferentes (clássico: '{$tituloClassico}', premium: '{$tituloPremium}')"
        );

        // O Premium deve ter o sufixo ' - Premium'
        $this->assertStringContainsString('Premium', $tituloPremium);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (c) FALHA DO 2º tier preserva o ml_item_id do 1º tier
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function falha_do_segundo_tier_preserva_ml_item_id_do_primeiro(): void
    {
        // Http::fakeSequence: 1ª chamada POST /items → sucesso (MLB111), 2ª → erro 400
        Http::fake([
            '*/users/*' => Http::response(['id' => '1555596317', 'tags' => []], 200),
            '*/items'   => Http::sequence()
                ->push(['id' => 'MLB111'], 200)             // 1ª publicação: sucesso
                ->push(['message' => 'item_not_active'], 400), // 2ª publicação: falha
            '*'         => Http::response([], 200),
        ]);

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo");

        // A resposta deve ter ok=true (pelo menos 1 tier publicou)
        $response->assertOk();
        $json = $response->json();
        $this->assertTrue($json['ok'], 'ok geral deve ser true (1º tier publicou)');

        // DUP-04: o ml_item_id do 1º tier (classico) deve estar gravado no banco
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'id'                 => $rascunho->id,
            'status'             => MlAnuncioRascunho::STATUS_PUBLICADO,
            'ml_item_id_classico' => 'MLB111',
        ]);

        // A resposta deve indicar 1 tier ok e 1 com falha
        $classicoOk = $json['classico']['ok'] ?? null;
        $premiumOk  = $json['premium']['ok']  ?? null;

        $this->assertTrue($classicoOk,  'Clássico (1º tier) deve ter ok=true');
        $this->assertFalse($premiumOk,  'Premium (2º tier) deve ter ok=false');

        // Verificar que o 2º tier reportou erros
        $errosPremium = $json['premium']['erros'] ?? [];
        $this->assertNotEmpty($errosPremium, 'Premium deve ter erros reportados');
    }

    /** @test */
    public function falha_do_primeiro_tier_preserva_ml_item_id_do_segundo(): void
    {
        // 1ª chamada POST /items → falha, 2ª → sucesso (MLB222)
        Http::fake([
            '*/users/*' => Http::response(['id' => '1555596317', 'tags' => []], 200),
            '*/items'   => Http::sequence()
                ->push(['message' => 'item_not_active'], 400) // 1ª publicação: falha
                ->push(['id' => 'MLB222'], 200),              // 2ª publicação: sucesso
            '*'         => Http::response([], 200),
        ]);

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo");

        // ok geral = true (pelo menos 1 tier publicou)
        $response->assertOk();
        $json = $response->json();
        $this->assertTrue($json['ok'], 'ok geral deve ser true (2º tier publicou)');

        // DUP-04: classico falhou, premium publicou → ml_item_id_premium gravado no duplicado
        $rascunhoPremium = MlAnuncioRascunho::where('listing_tier', 'premium')
            ->where('company_id', $rascunho->company_id)
            ->first();

        $this->assertNotNull($rascunhoPremium);
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'id'                => $rascunhoPremium->id,
            'status'            => MlAnuncioRascunho::STATUS_PUBLICADO,
            'ml_item_id_premium' => 'MLB222',
        ]);

        // Classico deve estar com erro (não publicado)
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'id'     => $rascunho->id,
            'status' => MlAnuncioRascunho::STATUS_ERRO,
        ]);

        $this->assertFalse($json['classico']['ok'] ?? true, 'Clássico deve ter ok=false');
        $this->assertTrue($json['premium']['ok']   ?? false, 'Premium deve ter ok=true');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (d) 403 para consultor (middleware role:admin)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_duplo_retorna_403_para_consultor_sem_role_admin(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        [$rascunho] = $this->criarFixture();
        $consultor  = $this->criarConsultor();

        // Middleware role:admin bloqueia antes do controller (DUP-02)
        $this->actingAs($consultor)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo")
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (e) 422 quando títulos ficariam idênticos
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_duplo_retorna_422_quando_titulos_identicos(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        // Rascunho premium com título que após strip+sufixo classico ficaria igual ao original
        // Ex: 'Produto - Clássico' → strip → 'Produto' → '+Clássico' → 'Produto - Clássico' = igual
        [$rascunho, , $admin] = $this->criarFixture([
            'listing_tier' => 'premium',
            'payload'      => [
                'title'           => 'Produto - Clássico',
                'listing_type_id' => 'gold_pro',
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo");

        // DUP-03: 422 antes de publicar qualquer coisa
        $response->assertStatus(422);

        // Nenhum rascunho deve ter sido publicado
        $this->assertDatabaseHas('ml_anuncio_rascunhos', [
            'id'     => $rascunho->id,
            'status' => MlAnuncioRascunho::STATUS_RASCUNHO, // permanece rascunho
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // (f) Shape da resposta JSON
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function publicar_duplo_retorna_shape_json_correto(): void
    {
        $this->fakeDuploPorSequencia();

        [$rascunho, , $admin] = $this->criarFixture(['listing_tier' => 'classico']);

        $response = $this->actingAs($admin)
            ->postJson("/mlb/anuncios/rascunho/{$rascunho->id}/publicar-duplo");

        $response->assertOk()
            ->assertJsonStructure([
                'ok',
                'classico' => ['ok', 'tier', 'ml_item_id'],
                'premium'  => ['ok', 'tier', 'ml_item_id'],
            ]);

        $json = $response->json();
        // Tier correto nos sub-campos
        $this->assertEquals('classico', $json['classico']['tier'] ?? '');
        $this->assertEquals('premium',  $json['premium']['tier']  ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Rota registrada
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function rota_publicar_duplo_esta_registrada(): void
    {
        $url = route('mlb.anuncios.publicar-duplo', ['rascunho' => 999]);
        $this->assertStringContainsString('/mlb/anuncios/rascunho/999/publicar-duplo', $url);
    }
}
