<?php

namespace Tests\Feature\Phase77;

use App\Models\Company;
use App\Models\MlAnuncioRascunho;
use App\Models\MlToken;
use App\Models\User;
use App\Services\Mlb\Publicacao\MlGradeService;
use App\Services\MercadoLivreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Suíte de testes do MlGradeService e do endpoint de grades (Phase 77, Plan 03).
 *
 * Cobre WIZ-06:
 *  - categoriaExigeGrade() detecta atributos grid_id/grid_row_id e SIZE_GRID_ID
 *  - listarGrades() chama POST /catalog/charts/search, cacheia 1h (1 HTTP em 2 chamadas)
 *  - listarGrades() degrada graciosamente a {results:[]} em falha HTTP 500
 *  - criarGrade() faz POST /catalog/charts e retorna o id
 *  - GET /rascunho/{id}/grades → 200 com grades (T-77-08, T-77-09, T-77-10)
 *  - GET /rascunho/{id}/grades sem domain_id → 422 (T-77-10)
 *  - GET /rascunho/{id}/grades por usuário sem atribuição → bloqueado (T-77-08)
 *
 * Estratégia de banco: RefreshDatabase (SQLite in-memory) + Http::fake.
 * Gotcha NPS: apenas a suíte isolada roda (nunca migrate completo no MariaDB local).
 *
 * @group phase77
 */
class GradeServiceTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fake de resposta do POST /catalog/charts/search ───
    private const FAKE_GRADES = [
        'results' => [
            [
                'id'        => 'chart_abc123',
                'names'     => ['pt_BR' => 'Grade Tênis Masculino P-M-G'],
                'domain_id' => 'SNEAKERS',
            ],
            [
                'id'        => 'chart_def456',
                'names'     => ['pt_BR' => 'Grade Tênis Feminino P-M-G'],
                'domain_id' => 'SNEAKERS',
            ],
        ],
        'paging'  => ['total' => 2, 'offset' => 0, 'limit' => 50],
    ];

    // ─── Atributos fake com e sem grade ───

    // Atributo com value_type = grid_id → categoria exige grade
    private const ATRIBUTOS_COM_GRADE_GRID_ID = [
        ['id' => 'BRAND',       'name' => 'Marca',    'value_type' => 'string'],
        ['id' => 'SIZE_GRID_ID','name' => 'Grade de Tamanho', 'value_type' => 'grid_id'],
    ];

    // Atributo com id = SIZE_GRID_ID (independente do value_type) → exige grade
    private const ATRIBUTOS_COM_GRADE_ID_EXPLICITO = [
        ['id' => 'BRAND',       'name' => 'Marca',    'value_type' => 'string'],
        ['id' => 'SIZE_GRID_ID','name' => 'Grade de Tamanho', 'value_type' => 'string'],
    ];

    // Atributos comuns sem grade
    private const ATRIBUTOS_SEM_GRADE = [
        ['id' => 'BRAND', 'name' => 'Marca',    'value_type' => 'string'],
        ['id' => 'COLOR', 'name' => 'Cor',      'value_type' => 'list'],
        ['id' => 'SIZE',  'name' => 'Tamanho',  'value_type' => 'list'],
    ];

    // ─── setUp ───────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        // Limpa o cache para garantir reprodutibilidade (MlGradeService cacheia 1h)
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
     * Cria Company + MlToken + MlAnuncioRascunho prontos para consulta de grades.
     * Retorna [$rascunho, $company, $admin].
     */
    private function criarFixture(): array
    {
        $admin   = $this->criarAdmin();
        $company = Company::factory()->create();

        // Token ML ativo (MlGradeService::http() chama ensureValidToken)
        MlToken::create([
            'company_id'        => $company->id,
            'ml_user_id'        => '1555596317',   // seller_id confirmado do spike
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
            'mlb_empresa_id' => null,  // sem mlb_empresa → fallback por user_id
            'user_id'        => $admin->id,
            'category_id'    => 'MLB179762',
            'payload'        => [],
            'status'         => MlAnuncioRascunho::STATUS_RASCUNHO,
        ]);

        return [$rascunho, $company, $admin];
    }

    /** Configura Http::fake para POST /catalog/charts/search com resposta de sucesso. */
    private function fakeHttpGradesOk(): void
    {
        Http::fake([
            'api.mercadolibre.com/catalog/charts/search' => Http::response(self::FAKE_GRADES, 200),
            '*' => Http::response([], 200),
        ]);
    }

    /** Configura Http::fake para POST /catalog/charts/search retornando HTTP 500. */
    private function fakeHttpGradesFalha(): void
    {
        Http::fake([
            'api.mercadolibre.com/catalog/charts/search' => Http::response(['error' => 'internal_error'], 500),
            '*' => Http::response([], 200),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // categoriaExigeGrade() — 3 casos
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function categoria_exige_grade_quando_value_type_e_grid_id(): void
    {
        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        $this->assertTrue($service->categoriaExigeGrade(self::ATRIBUTOS_COM_GRADE_GRID_ID));
    }

    /** @test */
    public function categoria_exige_grade_quando_id_e_size_grid_id(): void
    {
        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        $this->assertTrue($service->categoriaExigeGrade(self::ATRIBUTOS_COM_GRADE_ID_EXPLICITO));
    }

    /** @test */
    public function categoria_nao_exige_grade_para_atributos_comuns(): void
    {
        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        $this->assertFalse($service->categoriaExigeGrade(self::ATRIBUTOS_SEM_GRADE));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // listarGrades() — cache (1 HTTP em 2 chamadas) + degradação graciosa
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function listar_grades_cacheia_e_faz_apenas_uma_chamada_http(): void
    {
        $this->fakeHttpGradesOk();

        [, $company] = $this->criarFixture();

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        // Primeira chamada → HTTP real (fake)
        $primeiraResposta = $service->listarGrades($company, 'SNEAKERS');

        // Segunda chamada com os mesmos args → deve vir do cache (sem nova requisição HTTP)
        $segundaResposta = $service->listarGrades($company, 'SNEAKERS');

        // Ambas as respostas devem ter os mesmos results
        $this->assertCount(2, $primeiraResposta['results']);
        $this->assertCount(2, $segundaResposta['results']);
        $this->assertEquals('chart_abc123', $primeiraResposta['results'][0]['id']);

        // Apenas 1 requisição HTTP deve ter sido feita (cache evitou a segunda)
        Http::assertSentCount(1);
    }

    /** @test */
    public function listar_grades_degrada_graciosamente_quando_ml_retorna_500(): void
    {
        $this->fakeHttpGradesFalha();

        [, $company] = $this->criarFixture();

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        $resposta = $service->listarGrades($company, 'SNEAKERS');

        // Deve retornar {results: []} em falha, sem lançar exceção
        $this->assertIsArray($resposta);
        $this->assertArrayHasKey('results', $resposta);
        $this->assertEmpty($resposta['results']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // criarGrade() — POST /catalog/charts retorna o id
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function criar_grade_retorna_id_da_grade_criada(): void
    {
        Http::fake([
            'api.mercadolibre.com/catalog/charts' => Http::response(['id' => 'chart_456'], 201),
            '*' => Http::response([], 200),
        ]);

        [, $company] = $this->criarFixture();

        $ml      = $this->app->make(MercadoLivreService::class);
        $service = new MlGradeService($ml);

        $dados = [
            'names'          => ['pt_BR' => 'Minha Grade Teste'],
            'domain_id'      => 'SNEAKERS',
            'main_attribute' => 'SIZE',
            'attributes'     => [['id' => 'SIZE', 'name' => 'Tamanho']],
            'rows'           => [
                ['attributes' => [['id' => 'SIZE', 'value_name' => 'P']]],
                ['attributes' => [['id' => 'SIZE', 'value_name' => 'M']]],
                ['attributes' => [['id' => 'SIZE', 'value_name' => 'G']]],
            ],
        ];

        $id = $service->criarGrade($company, $dados);

        $this->assertEquals('chart_456', $id);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // GET /rascunho/{id}/grades — endpoint HTTP (filtro: Grades)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @test */
    public function endpoint_grades_retorna_200_com_lista_de_grades(): void
    {
        $this->fakeHttpGradesOk();

        [$rascunho, $company, $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/grades?domain_id=SNEAKERS");

        $response->assertStatus(200);
        $response->assertJsonStructure(['ok', 'grades']);

        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertCount(2, $json['grades']);
        $this->assertEquals('chart_abc123', $json['grades'][0]['id']);
    }

    /** @test */
    public function endpoint_grades_sem_domain_id_retorna_422(): void
    {
        $this->fakeHttpGradesOk();

        [$rascunho, $company, $admin] = $this->criarFixture();

        $response = $this->actingAs($admin)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/grades");

        $response->assertStatus(422);
    }

    /** @test */
    public function endpoint_grades_bloqueado_para_usuario_sem_role_admin(): void
    {
        $this->fakeHttpGradesOk();

        [$rascunho] = $this->criarFixture();

        // Consultor não tem role:admin — middleware bloqueia antes do controller
        $consultor = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($consultor)
            ->getJson("/mlb/anuncios/rascunho/{$rascunho->id}/grades?domain_id=SNEAKERS");

        // Middleware role:admin bloqueia (T-77-08)
        $this->assertNotEquals(200, $response->status());
    }

    /** @test */
    public function rota_rascunho_grades_esta_registrada(): void
    {
        $rascunhoId = 1;
        $url = route('mlb.anuncios.rascunho.grades', ['rascunho' => $rascunhoId]);
        $this->assertStringContainsString("/mlb/anuncios/rascunho/{$rascunhoId}/grades", $url);
    }
}
