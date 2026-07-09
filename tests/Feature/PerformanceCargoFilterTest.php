<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Suite Feature — Phase 49 Plan 49-01 (REQ-49-01).
 *
 * Verifica que o parâmetro ?cargo filtra o ranking de /performance
 * pós-cálculo via cargo_slug já presente em cada item do $ranking.
 *
 * Cenários cobertos:
 *  1. Sem cargo: retorna todos os users (analistas + estrategistas)
 *  2. cargo=analista: retorna apenas users com cargo_slug='analista'
 *  3. cargo=estrategista: retorna apenas users com cargo_slug='estrategista'
 *  4. cargo inválido (ex: 'gestor'): ignorado, retorna todos (mesmo que sem cargo)
 *  5. cargo='geral': equivale a sem cargo, retorna todos
 *  6. Prop 'cargo' é propagada no response Inertia
 *
 * Estratégia: admin (isAdmin() → short-circuit em hasPermission) + users
 * analistas e estrategistas via pivot user_setores → cargos.
 * O PortfolioScoreService roda mas retorna scores zerados/padrão — suficiente
 * para validar o filtro pós-cálculo.
 */
class PerformanceCargoFilterTest extends TestCase
{
    use RefreshDatabase;

    // ─── IDs de setor e cargos criados no setUp ───────────────────────────────

    private int $setorId;
    private int $cargoAnalistaId;
    private int $cargoEstrategistaId;

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // Cria setor "Performance" mínimo para o pivot
        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cargo "analista" vinculado ao setor
        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Cargo "estrategista" vinculado ao setor
        $this->cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Estrategista',
            'slug'       => 'estrategista',
            'active'     => true,
            'ordem'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Cria e autentica um user admin (short-circuit total em hasPermission).
     */
    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'name'     => 'Admin Perf49 ' . uniqid(),
            'email'    => 'admin.p49.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'admin',
            'active'   => true,
        ]);
        $this->actingAs($admin);
        return $admin;
    }

    /**
     * Cria um user consultor e o vincula ao setor com cargo analista ou estrategista.
     *
     * Phase 74 D-10 (DESEMP-10) — o `PerformanceController::index` remove do
     * ranking usuários com `sem_carteira=true`. Para manter o teste focado
     * no FILTRO POR CARGO (sem mudar o intent), damos ao user uma empresa
     * ativa via pivot `company_users` — assim ele passa pelo filtro sem_carteira
     * e entra no ranking. A empresa é criada há -3 meses para não cair no
     * filtro "empresa nova" do DESEMP-04.
     */
    private function criarUserComCargo(string $cargoSlug): User
    {
        $user = User::create([
            'name'     => 'User ' . $cargoSlug . ' ' . uniqid(),
            'email'    => $cargoSlug . '.' . uniqid() . '@ecf.test',
            'password' => bcrypt('senha'),
            'role'     => 'consultor',
            'active'   => true,
        ]);

        $cargoId = $cargoSlug === 'estrategista'
            ? $this->cargoEstrategistaId
            : $this->cargoAnalistaId;

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Attach empresa ativa para o user passar pelo filtro DESEMP-10
        // (sem_carteira=false).
        $company = Company::factory()->create();
        $ts = now()->subMonths(3)->toDateTimeString();
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'assigned_at' => $ts,
            'created_at'  => $ts,
            'updated_at'  => $ts,
        ]);

        return $user;
    }

    /**
     * Extrai o array 'ranking' das props Inertia do response.
     */
    private function rankingDaResposta($response): array
    {
        return $response->viewData('page')['props']['ranking'] ?? [];
    }

    /**
     * Extrai o valor 'cargo' das props Inertia do response.
     * Usa array_key_exists para distinguir chave ausente de chave=null.
     */
    private function cargoDaResposta($response): mixed
    {
        $props = $response->viewData('page')['props'] ?? [];
        if (!array_key_exists('cargo', $props)) {
            return 'CHAVE_AUSENTE';
        }
        return $props['cargo'];
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 1. Sem cargo: retorna todos os users (analistas + estrategistas)
    // ═════════════════════════════════════════════════════════════════════════

    public function test_sem_cargo_retorna_ranking_completo(): void
    {
        $this->actingAsAdmin();

        $analista     = $this->criarUserComCargo('analista');
        $estrategista = $this->criarUserComCargo('estrategista');

        $response = $this->get('/performance');
        $response->assertStatus(200);

        $ids = array_column($this->rankingDaResposta($response), 'id');

        $this->assertContains($analista->id, $ids,
            'Ranking sem ?cargo deve conter analistas');
        $this->assertContains($estrategista->id, $ids,
            'Ranking sem ?cargo deve conter estrategistas');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 2. cargo=analista: retorna apenas analistas
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cargo_analista_retorna_apenas_analistas(): void
    {
        $this->actingAsAdmin();

        $analista     = $this->criarUserComCargo('analista');
        $estrategista = $this->criarUserComCargo('estrategista');

        $response = $this->get('/performance?cargo=analista');
        $response->assertStatus(200);

        $ranking = $this->rankingDaResposta($response);
        $ids     = array_column($ranking, 'id');

        $this->assertContains($analista->id, $ids,
            'cargo=analista deve incluir analistas no ranking');
        $this->assertNotContains($estrategista->id, $ids,
            'cargo=analista deve excluir estrategistas do ranking');

        // Todos os itens retornados devem ter cargo_slug='analista'
        $slugs = array_unique(array_column($ranking, 'cargo_slug'));
        $this->assertSame(['analista'], $slugs,
            'Todos os items retornados com cargo=analista devem ter cargo_slug=analista');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 3. cargo=estrategista: retorna apenas estrategistas
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cargo_estrategista_retorna_apenas_estrategistas(): void
    {
        $this->actingAsAdmin();

        $analista     = $this->criarUserComCargo('analista');
        $estrategista = $this->criarUserComCargo('estrategista');

        $response = $this->get('/performance?cargo=estrategista');
        $response->assertStatus(200);

        $ranking = $this->rankingDaResposta($response);
        $ids     = array_column($ranking, 'id');

        $this->assertContains($estrategista->id, $ids,
            'cargo=estrategista deve incluir estrategistas no ranking');
        $this->assertNotContains($analista->id, $ids,
            'cargo=estrategista deve excluir analistas do ranking');

        // Todos os itens retornados devem ter cargo_slug='estrategista'
        $slugs = array_unique(array_column($ranking, 'cargo_slug'));
        $this->assertSame(['estrategista'], $slugs,
            'Todos os items retornados com cargo=estrategista devem ter cargo_slug=estrategista');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 4. cargo inválido: ignorado, retorna todos
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cargo_invalido_ignorado_retorna_ranking_completo(): void
    {
        $this->actingAsAdmin();

        $analista     = $this->criarUserComCargo('analista');
        $estrategista = $this->criarUserComCargo('estrategista');

        $response = $this->get('/performance?cargo=gestor');
        $response->assertStatus(200);

        $ids = array_column($this->rankingDaResposta($response), 'id');

        $this->assertContains($analista->id, $ids,
            'cargo inválido deve ser ignorado: analista deve aparecer');
        $this->assertContains($estrategista->id, $ids,
            'cargo inválido deve ser ignorado: estrategista deve aparecer');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 5. cargo='geral': equivale a sem cargo, retorna todos
    // ═════════════════════════════════════════════════════════════════════════

    public function test_cargo_geral_retorna_ranking_completo(): void
    {
        $this->actingAsAdmin();

        $analista     = $this->criarUserComCargo('analista');
        $estrategista = $this->criarUserComCargo('estrategista');

        $response = $this->get('/performance?cargo=geral');
        $response->assertStatus(200);

        $ids = array_column($this->rankingDaResposta($response), 'id');

        $this->assertContains($analista->id, $ids,
            'cargo=geral deve retornar analistas (mesmo comportamento que sem cargo)');
        $this->assertContains($estrategista->id, $ids,
            'cargo=geral deve retornar estrategistas (mesmo comportamento que sem cargo)');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // 6. Prop 'cargo' é propagada no response Inertia
    // ═════════════════════════════════════════════════════════════════════════

    public function test_prop_cargo_propagada_no_inertia(): void
    {
        $this->actingAsAdmin();

        // Sem cargo → null
        $response = $this->get('/performance');
        $this->assertNull($this->cargoDaResposta($response),
            'Sem ?cargo na URL, prop cargo deve ser null');

        // Com cargo=analista → 'analista'
        $response = $this->get('/performance?cargo=analista');
        $this->assertSame('analista', $this->cargoDaResposta($response),
            'Com ?cargo=analista, prop cargo deve ser "analista"');

        // Com cargo inválido → null (valor sanitizado)
        $response = $this->get('/performance?cargo=invalido');
        $this->assertNull($this->cargoDaResposta($response),
            'Cargo inválido deve ser sanitizado para null na prop Inertia');
    }
}
