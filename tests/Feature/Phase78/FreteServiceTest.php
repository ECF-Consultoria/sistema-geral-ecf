<?php

namespace Tests\Feature\Phase78;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Mlb\Publicacao\MlFreteService;
use App\Services\MercadoLivreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suíte de testes do MlFreteService e do endpoint de cotação de frete (Phase 78, Plan 01).
 *
 * Cobre SHIP-02:
 *  - cotar() retorna estimativa quando ML responde com sucesso (shipping_options[0].list_cost)
 *  - cotar() retorna null graciosamente em falha HTTP 500 (sem lançar exceção)
 *  - cotar() retorna null quando a empresa não tem token ML (ensureValidToken retorna null)
 *  - cotar() monta a string dimensions como "{altura}x{largura}x{comprimento},{peso_g}"
 *  - GET /rascunho/{id}/frete → 200 com estimativa_frete=12.50 (sucesso)
 *  - GET /rascunho/{id}/frete → 200 com estimativa_frete=null (falha ML — degradação graciosa)
 *  - GET /rascunho/{id}/frete sem params obrigatórios → 422 (T-78-02)
 *  - GET /rascunho/{id}/frete por consultor (sem role:admin) → != 200 (middleware bloqueia)
 *  - Rota mlb.anuncios.rascunho.frete registrada e resolve para /mlb/anuncios/rascunho/{id}/frete
 *
 * Estratégia de banco: RefreshDatabase (SQLite in-memory) + Http::fake.
 * Gotcha NPS: apenas a suíte isolada roda (nunca migrate completo no MariaDB local).
 *
 * @group phase78
 */
class FreteServiceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fake de resposta do GET /users/*/shipping_options/free (sucesso) ───
    private const FAKE_FRETE = [
        'shipping_options' => [
            [
                'id'           => 1,
                'name'         => 'Mercado Envios',
                'currency_id'  => 'BRL',
                'list_cost'    => 12.50,
                'cost'         => 0.0,
                'estimated_delivery_time' => [
                    'type'   => 'known_frame',
                    'unit'   => 'business_day',
                    'offset' => ['date' => '2026-07-15', 'shipping' => 1],
                ],
            ],
        ],
    ];

    // ─── Helpers de fixture ──────────────────────────────────────────────────

    /** Cria usuário admin com e-mail verificado. */
    private function criarAdmin(): User
    {
        return User::factory()->create([
            'role'              => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Cria Company + MlToken + MlAnuncioRascunho prontos para cotação de frete.
     * Retorna [$rascunho, $company, $admin].
     */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo (MlFreteService chama ensureValidToken)
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',  // seller_id confirmado do spike
            'access_token'      => 'fake-access-token',
            'refresh_token'     => 'fake-refresh-token',
            'token_type'        => 'bearer',
            'scope'             => 'read write offline_access',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => null,  // sem mlb_empresa → fallback por user_id (T-78-01)
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return [$rascunho, $company, $admin];
    }

    /** Params válidos para a requisição de cotação de frete. */
    private function paramsValidos(): array
    {
        return [
            'peso_g'          => 500,
            'altura_cm'       => 10,
            'largura_cm'      => 20,
            'comprimento_cm'  => 30,
            'item_price'      => 99.90,
            'listing_type_id' => 'gold_special',
        ];
    }

    /** Configura Http::fake para GET shipping_options/free com sucesso. */
    private function fakeFreteOk(): void
    {
        Http::fake([
            '*/shipping_options/free*' => Http::response(self::FAKE_FRETE, 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** Configura Http::fake para GET shipping_options/free retornando HTTP 500. */
    private function fakeFreteErro(): void
    {
        Http::fake([
            '*/shipping_options/free*' => Http::response(['error' => 'internal_error'], 500),
            '*' => Http::response([], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MlFreteService::cotar() — comportamentos do service
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function cotar_retorna_estimativa_quando_ml_responde_com_sucesso(): void
    {
        $this->fakeFreteOk();

        [, $company] = $this->criarFixture();

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlFreteService($ml);

        // O service recebe as chaves individuais e monta a string dimensions internamente
        $resultado = $service->cotar($company, [
            'peso_g'          => 500,
            'altura_cm'       => 10,
            'largura_cm'      => 20,
            'comprimento_cm'  => 30,
            'item_price'      => 99.90,
            'listing_type_id' => 'gold_special',
        ]);

        // Deve retornar o array com shipping_options (SHIP-02: estimativa presente)
        $this->assertIsArray($resultado);
        $this->assertArrayHasKey('shipping_options', $resultado);
        $this->assertCount(1, $resultado['shipping_options']);
        $this->assertEquals(12.50, $resultado['shipping_options'][0]['list_cost']);
    }

    /** @test */
    public function cotar_retorna_null_graciosamente_quando_ml_responde_500(): void
    {
        $this->fakeFreteErro();

        [, $company] = $this->criarFixture();

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlFreteService($ml);

        // Não deve lançar exceção — SHIP-02: publicação não é bloqueada por falha de cotação
        $resultado = $service->cotar($company, [
            'peso_g'          => 500,
            'altura_cm'       => 10,
            'largura_cm'      => 20,
            'comprimento_cm'  => 30,
            'item_price'      => 99.90,
            'listing_type_id' => 'gold_special',
        ]);

        $this->assertNull($resultado);
    }

    /** @test */
    public function cotar_retorna_null_quando_empresa_nao_tem_token_ml(): void
    {
        $this->fakeFreteOk();

        $admin   = $this->criarAdmin();

        // Empresa SEM token ML (sem MlToken::create)
        $companySemToken = Company::factory()->create();

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $companySemToken->id,
            'mlb_empresa_id' => null,
            'user_id'        => $admin->id,
            'category_id'    => null,
            'payload'        => [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlFreteService($ml);

        // Sem token → ensureValidToken retorna null → service retorna null (não lança exceção)
        $resultado = $service->cotar($companySemToken, [
            'peso_g'          => 500,
            'altura_cm'       => 10,
            'largura_cm'      => 20,
            'comprimento_cm'  => 30,
            'item_price'      => 99.90,
            'listing_type_id' => 'gold_special',
        ]);

        $this->assertNull($resultado);

        // Não deve ter feito nenhuma requisição HTTP (sem token, para antes)
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Montagem da string dimensions no controller
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function endpoint_frete_monta_dimensions_no_formato_correto(): void
    {
        $this->fakeFreteOk();

        [$rascunho, , $admin] = $this->criarFixture();

        $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query([
                'peso_g'          => 500,
                'altura_cm'       => 10,
                'largura_cm'      => 20,
                'comprimento_cm'  => 30,
                'item_price'      => 99.90,
                'listing_type_id' => 'gold_special',
            ]));

        // Verifica que a string dimensions foi montada como "{altura}x{largura}x{comprimento},{peso_g}"
        Http::assertSent(function ($request) {
            $query = $request->data();
            return isset($query['dimensions']) && $query['dimensions'] === '10x20x30,500';
        });
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GET /rascunho/{id}/frete — endpoint HTTP
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function endpoint_frete_retorna_200_com_estimativa_quando_ml_responde_ok(): void
    {
        $this->fakeFreteOk();

        [$rascunho, , $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($this->paramsValidos()));

        $response->assertStatus(200);
        $response->assertJsonStructure(['ok', 'estimativa_frete', 'opcoes']);

        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertEquals(12.50, $json['estimativa_frete']);
        $this->assertNotEmpty($json['opcoes']);
    }

    /** @test */
    public function endpoint_frete_retorna_200_com_estimativa_null_quando_ml_falha(): void
    {
        $this->fakeFreteErro();

        [$rascunho, , $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($this->paramsValidos()));

        // SHIP-02: degradação graciosa — sempre 200, estimativa_frete=null (publicação não bloqueada)
        $response->assertStatus(200);
        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertNull($json['estimativa_frete']);
        $this->assertEmpty($json['opcoes']);
    }

    /** @test */
    public function endpoint_frete_sem_peso_retorna_422(): void
    {
        $this->fakeFreteOk();

        [$rascunho, , $admin] = $this->criarFixture();

        // Sem peso_g — campo obrigatório (T-78-02)
        $paramsSemPeso = $this->paramsValidos();
        unset($paramsSemPeso['peso_g']);

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($paramsSemPeso));

        $response->assertStatus(422);
    }

    /** @test */
    public function endpoint_frete_sem_dimensoes_retorna_422(): void
    {
        $this->fakeFreteOk();

        [$rascunho, , $admin] = $this->criarFixture();

        // Sem altura_cm, largura_cm, comprimento_cm — campos obrigatórios (T-78-02)
        $paramsSemDims = [
            'peso_g'          => 500,
            'item_price'      => 99.90,
            'listing_type_id' => 'gold_special',
        ];

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($paramsSemDims));

        $response->assertStatus(422);
    }

    /** @test */
    public function endpoint_frete_sem_listing_type_invalido_retorna_422(): void
    {
        $this->fakeFreteOk();

        [$rascunho, , $admin] = $this->criarFixture();

        // listing_type_id inválido (não está em in:gold_special,gold_pro) — T-78-02
        $paramsInvalidos = $this->paramsValidos();
        $paramsInvalidos['listing_type_id'] = 'bronze_standard';

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($paramsInvalidos));

        $response->assertStatus(422);
    }

    /** @test */
    public function endpoint_frete_bloqueado_para_usuario_sem_role_admin(): void
    {
        $this->fakeFreteOk();

        [$rascunho] = $this->criarFixture();

        // Consultor não tem role:admin — middleware bloqueia antes do controller (T-78-01)
        $consultor = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($consultor)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/frete?" . http_build_query($this->paramsValidos()));

        // Middleware role:admin bloqueia
        $this->assertNotEquals(200, $response->status());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Rota registrada
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function rota_rascunho_frete_esta_registrada(): void
    {
        $rascunhoId = 1;
        $url = route('mlb.anuncios.rascunho.frete', ['rascunho' => $rascunhoId]);
        $this->assertStringContainsString("/mlb/anuncios/rascunho/{$rascunhoId}/frete", $url);
    }
}
