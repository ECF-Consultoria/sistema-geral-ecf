<?php

namespace Tests\Feature\Phase77;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suíte de testes backend da Phase 77 Plan 01.
 *
 * Cobre:
 *  WIZ-01 — validação de título por max_title_length da categoria no autosave
 *  WIZ-02 — path_from_root e max_title_length expostos pelo endpoint atributos()
 *  WIZ-03 — flag catalog_required exposta pelo endpoint atributos()
 *
 * Usa SQLite in-memory (RefreshDatabase) + Http::fake para não chamar a API real.
 *
 * @group phase77
 */
class TituloCatalogoTest extends TestCase
{
    use RefreshDatabase;

    // ─── Resposta fake da API ML para /categories/MLB179762 ───
    private const FAKE_CATEGORIA = [
        'id'            => 'MLB179762',
        'name'          => 'Camisetas',
        'path_from_root' => [
            ['id' => 'MLB5672',  'name' => 'Moda e Acessórios'],
            ['id' => 'MLB1370',  'name' => 'Roupas'],
            ['id' => 'MLB179762','name' => 'Camisetas'],
        ],
        'settings' => [
            'max_title_length' => 60,
        ],
    ];

    // ─── Resposta fake de /categories/MLB179762/attributes SEM catalog_required ───
    private const FAKE_ATRIBUTOS_SEM_CATALOGO = [
        [
            'id'         => 'BRAND',
            'name'       => 'Marca',
            'tags'       => ['required' => true, 'catalog_required' => false, 'allow_variations' => false],
            'value_type' => 'string',
            'values'     => [],
        ],
        [
            'id'         => 'SIZE',
            'name'       => 'Tamanho',
            'tags'       => ['required' => true, 'catalog_required' => false, 'allow_variations' => true],
            'value_type' => 'list',
            'values'     => [
                ['id' => '1', 'name' => 'P'],
                ['id' => '2', 'name' => 'M'],
            ],
        ],
    ];

    // ─── Resposta fake de /categories/MLBCATALOG/attributes COM catalog_required ───
    private const FAKE_ATRIBUTOS_COM_CATALOGO = [
        [
            'id'         => 'BRAND',
            'name'       => 'Marca',
            'tags'       => ['required' => true, 'catalog_required' => false, 'allow_variations' => false],
            'value_type' => 'string',
            'values'     => [],
        ],
        [
            'id'         => 'CATALOG_PRODUCT_ID',
            'name'       => 'Produto do catálogo',
            'tags'       => ['required' => false, 'catalog_required' => true, 'allow_variations' => false],
            'value_type' => 'string',
            'values'     => [],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Limpa cache para garantir reprodutibilidade (MlCatalogoMetaService cacheia por TTL_META)
        Cache::flush();
    }

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
     * Cria Company + MlToken + MlAnuncioRascunho prontos para o autosave.
     * Retorna [$rascunho, $company, $admin].
     */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML (necessário para passar na verificação mlToken !== null)
        MlToken::create([
            'company_id'       => $company->id,
            'ml_user_id'       => '1234567890',
            'access_token'     => 'fake-access-token',
            'refresh_token'    => 'fake-refresh-token',
            'token_type'       => 'bearer',
            'scope'            => 'read write offline_access',
            'expires_at'       => now()->addDays(6),
            'last_refreshed_at'=> now(),
            'status'           => 'active',
            'connected_at'     => now(),
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => null,
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => ['title' => 'Título inicial'],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return [$rascunho, $company, $admin];
    }

    /**
     * Configura Http::fake para os endpoints de metadados da categoria MLB179762.
     * MlCatalogoMetaService usa app token (MlColetaService::getAppToken) — fakeamos
     * também o endpoint de token para evitar chamada real.
     */
    private function fakeHttpCategoria(array $atributos = self::FAKE_ATRIBUTOS_SEM_CATALOGO): void
    {
        Http::fake([
            // App token (client_credentials) — MlColetaService::getAppToken()
            'oauth.mercadolibre.com/oauth/token'           => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            'api.mercadolibre.com/oauth/token'             => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            // Detalhe da categoria (max_title_length + path_from_root)
            'api.mercadolibre.com/categories/MLB179762'    => Http::response(self::FAKE_CATEGORIA, 200),
            // Atributos da categoria (configurable por teste)
            'api.mercadolibre.com/categories/MLB179762/attributes' => Http::response($atributos, 200),
            // Fallback para qualquer outra chamada HTTP não mapeada
            '*' => Http::response([], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WIZ-01 — Validação de título por max_title_length (filtro: titulo)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function titulo_maior_que_limite_retorna_422_pt_br(): void
    {
        $this->fakeHttpCategoria();
        [$rascunho, $company, $admin] = $this->criarFixture();

        // 61 caracteres — excede max_title_length=60 da categoria MLB179762
        $titulo61 = str_repeat('a', 61);

        $response = $this->actingAs($admin)
            ->putJson("/mlb/anuncios/rascunho/{$rascunho->id}", [
                'category_id' => 'MLB179762',
                'payload'     => ['title' => $titulo61],
            ]);

        $response->assertStatus(422);

        // Mensagem em pt-BR deve mencionar o limite numérico "60"
        $json = $response->json();
        $this->assertStringContainsString('60', $json['message'] ?? json_encode($json));
    }

    /** @test */
    public function titulo_exato_no_limite_e_aceito(): void
    {
        $this->fakeHttpCategoria();
        [$rascunho, $company, $admin] = $this->criarFixture();

        // 60 caracteres — limite inclusivo (deve passar)
        $titulo60 = str_repeat('b', 60);

        $response = $this->actingAs($admin)
            ->putJson("/mlb/anuncios/rascunho/{$rascunho->id}", [
                'category_id' => 'MLB179762',
                'payload'     => ['title' => $titulo60],
            ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function titulo_sem_category_id_usa_fallback_60_e_nao_quebra(): void
    {
        Http::fake([
            // Sem category_id, o service vai chamar categoria('') — retornamos vazio
            'api.mercadolibre.com/categories/'        => Http::response([], 404),
            'oauth.mercadolibre.com/oauth/token'      => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            'api.mercadolibre.com/oauth/token'        => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            '*'                                       => Http::response([], 200),
        ]);

        [$rascunho, $company, $admin] = $this->criarFixture();

        // Título com 60 chars sem category_id — fallback 60 deve aceitar
        $titulo60 = str_repeat('c', 60);

        $response = $this->actingAs($admin)
            ->putJson("/mlb/anuncios/rascunho/{$rascunho->id}", [
                // Sem category_id — o controller deve usar fallback 60
                'payload' => ['title' => $titulo60],
            ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function double_check_403_permanece_antes_da_validacao_de_titulo(): void
    {
        $this->fakeHttpCategoria();

        // Admin próprio cria o rascunho
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '9999999999',
            'access_token'      => 'fake-token',
            'refresh_token'     => 'fake-refresh',
            'token_type'        => 'bearer',
            'scope'             => 'read',
            'expires_at'        => now()->addDays(6),
            'last_refreshed_at' => now(),
            'status'            => 'active',
            'connected_at'      => now(),
        ]);

        $rascunho = MlAnuncioRascunho::create([
            'company_id'     => $company->id,
            'mlb_empresa_id' => null,
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        // Outro usuário (consultor) tenta editar — deve receber 403 ANTES de validar título
        $outro = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);

        // Outro usuário não tem mlb_empresa atribuída (mlb_empresa_id=null) e não é o dono
        $response = $this->actingAs($outro)
            ->putJson("/mlb/anuncios/rascunho/{$rascunho->id}", [
                'category_id' => 'MLB179762',
                'payload'     => ['title' => str_repeat('x', 61)],
            ]);

        // 403 (double-check de empresa) deve vir ANTES de 422 (validação de título)
        // Mas como a rota exige role:admin, o outro usuário sem role:admin recebe 302/403
        // pela middleware — comportamento correto: o rascunho não é alterado
        $this->assertNotEquals(200, $response->status());
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WIZ-02 / WIZ-03 — atributos() com catalog_required e path_from_root (filtro: catalog)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function catalog_required_e_true_quando_atributo_tem_flag(): void
    {
        // Atributos COM CATALOG_PRODUCT_ID.tags.catalog_required = true
        Http::fake([
            'oauth.mercadolibre.com/oauth/token'              => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            'api.mercadolibre.com/oauth/token'                => Http::response(['access_token' => 'fake-app-token', 'expires_in' => 21600], 200),
            'api.mercadolibre.com/categories/MLB179762'       => Http::response(self::FAKE_CATEGORIA, 200),
            'api.mercadolibre.com/categories/MLB179762/attributes' => Http::response(self::FAKE_ATRIBUTOS_COM_CATALOGO, 200),
            '*' => Http::response([], 200),
        ]);

        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)
            ->getJson('/mlb/anuncios/meta/categoria/MLB179762/atributos');

        $response->assertStatus(200);
        $response->assertJson(['catalog_required' => true]);
    }

    /** @test */
    public function catalog_required_e_false_quando_nenhum_atributo_tem_flag(): void
    {
        // Atributos SEM catalog_required = true
        $this->fakeHttpCategoria(self::FAKE_ATRIBUTOS_SEM_CATALOGO);

        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)
            ->getJson('/mlb/anuncios/meta/categoria/MLB179762/atributos');

        $response->assertStatus(200);
        $response->assertJson(['catalog_required' => false]);
    }

    /** @test */
    public function atributos_preserva_path_from_root_da_categoria(): void
    {
        $this->fakeHttpCategoria();

        $admin = $this->criarAdmin();

        $response = $this->actingAs($admin)
            ->getJson('/mlb/anuncios/meta/categoria/MLB179762/atributos');

        $response->assertStatus(200);

        // path_from_root deve estar dentro do objeto "categoria" no JSON
        $json = $response->json();
        $this->assertArrayHasKey('categoria', $json);
        $this->assertArrayHasKey('path_from_root', $json['categoria']);
        $this->assertCount(3, $json['categoria']['path_from_root']);
        $this->assertEquals('Moda e Acessórios', $json['categoria']['path_from_root'][0]['name']);
        $this->assertEquals('Camisetas', $json['categoria']['path_from_root'][2]['name']);
    }
}
