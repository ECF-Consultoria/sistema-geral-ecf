<?php

namespace Tests\Feature\Phase54;

use App\Models\Company;
use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 54 Plan 54-01 (A3) — Filtro por analista no /sugadores.
 *
 * O CONTEXT.md 54-CONTEXT §A3 define:
 * - Admin recebe prop `is_admin=true` + `analistas` (users com pivot
 *   company_users.role='analista', distintos, ordenados por nome).
 * - Não-admin recebe `is_admin=false` + `analistas=[]` (dropdown escondido).
 * - `?analista_id=X` como admin reduz `companies_summary` para empresas em
 *   que o user X aparece como pivot role='analista'.
 * - `?analista_id=X` como não-admin é IGNORADO (proteção contra bypass:
 *   analista/consultor não deve poder inspecionar carteira alheia).
 *
 * Todos os cenários usam SQLite in-memory (phpunit.xml) — o ENUM
 * `company_users.role` só é aplicado em MySQL/MariaDB, então gravar
 * `role='analista'` diretamente funciona nos testes.
 */
class SugadorIndexAnalistaFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixa "hoje" para que o filtro default (vista hoje) da index() não
        // deixe agregados divergirem entre testes que rodam próximos à meia-noite.
        Carbon::setTestNow(Carbon::parse('2026-07-02 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria user com permission `CORE_SUGADORES` (via setor) — evita 403 no
     * viewAny do SugadorPolicy sem precisar ser admin. Usado para representar
     * analista/consultor no cenário "não-admin".
     */
    private function userComCoreSugadores(string $slugPrefix = 'analista', string $role = 'consultor'): User
    {
        $setor = Setor::create([
            'nome'   => 'Setor ' . $slugPrefix,
            'slug'   => $slugPrefix . '-' . uniqid(),
            'active' => true,
        ]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::CORE_SUGADORES,
        ]);
        $user = User::factory()->create(['role' => $role]);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);
        return $user;
    }

    private function novaEmpresa(string $suffix): Company
    {
        return Company::create([
            'name'             => 'Empresa ' . $suffix,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'CID-' . $suffix,
            'active'           => true,
        ]);
    }

    /**
     * Attach direto na pivot company_users para garantir controle do enum
     * `role` (consultor/estrategista). Valores válidos em prod: consultor,
     * estrategista. "analista" não existe na pivot — o vínculo de analista
     * é gravado como role='consultor' (memory project_atribuicao_profissionais).
     */
    private function attachCarteira(Company $company, User $user, string $role): void
    {
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'assigned_at' => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Fix UAT 2026-07-02 — marca user como analista via CARGO (user_setores →
     * cargos.slug='analista'). Em prod não existe registro em company_users
     * com role='analista'; a identificação vem do cargo.
     */
    private function marcarComoAnalistaPorCargo(User $user): void
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome' => 'Setor Teste ' . uniqid(),
            'slug' => 'setor-teste-' . uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cargoId = DB::table('cargos')->where('slug', 'analista')->value('id');
        if (!$cargoId) {
            $cargoId = DB::table('cargos')->insertGetId([
                'setor_id' => $setorId,
                'nome'     => 'Analista',
                'slug'     => 'analista',
                'ordem'    => 0,
                'active'   => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function extractInertiaPage($response): array
    {
        $html = $response->getContent();
        $ok   = preg_match('/data-page="([^"]+)"/', $html, $m);
        $this->assertSame(1, $ok, 'Nao encontrou data-page no HTML da response Inertia.');
        $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $arr  = json_decode($json, true);
        $this->assertIsArray($arr, 'data-page nao decodificou como JSON valido.');
        return $arr;
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    /**
     * Cenário 1 — Admin recebe prop `is_admin=true` e lista `analistas`
     * contendo apenas users com pivot role='analista' (não repete users
     * duplicados em várias empresas, não inclui consultor/estrategista).
     */
    public function test_admin_recebe_prop_is_admin_true_e_lista_analistas(): void
    {
        $admin = $this->admin();

        // Cria 2 empresas e vincula users com roles diferentes na pivot.
        $empA = $this->novaEmpresa('A');
        $empB = $this->novaEmpresa('B');

        $userAnalista   = User::factory()->create(['role' => 'consultor', 'name' => 'Ana Analista']);
        $userConsultor  = User::factory()->create(['role' => 'consultor', 'name' => 'Carlos Consultor']);

        // Ana tem cargo analista + vinculada em A e B via role=consultor
        // (pivot company_users não aceita 'analista' — memory project_atribuicao_profissionais).
        $this->marcarComoAnalistaPorCargo($userAnalista);
        $this->attachCarteira($empA, $userAnalista, 'consultor');
        $this->attachCarteira($empB, $userAnalista, 'consultor');
        // Carlos SEM cargo analista + role=consultor — NÃO deve aparecer na lista.
        $this->attachCarteira($empA, $userConsultor, 'consultor');

        $response = $this->actingAs($admin)->get(route('sugadores.index'));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $this->assertArrayHasKey('is_admin', $props, 'Prop is_admin ausente.');
        $this->assertTrue($props['is_admin'], 'Admin deve receber is_admin=true.');

        $this->assertArrayHasKey('analistas', $props, 'Prop analistas ausente.');
        $this->assertIsArray($props['analistas']);
        $this->assertCount(1, $props['analistas'], 'analistas deve conter apenas Ana (distinct).');

        $primeiro = $props['analistas'][0];
        $this->assertArrayHasKey('id', $primeiro);
        $this->assertArrayHasKey('name', $primeiro);
        $this->assertSame($userAnalista->id, $primeiro['id']);
        $this->assertSame('Ana Analista', $primeiro['name']);
    }

    /**
     * Cenário 2 — Não-admin (com CORE_SUGADORES pra passar do viewAny) recebe
     * is_admin=false e analistas=[]. Dropdown esconde do próprio analista.
     */
    public function test_nao_admin_recebe_is_admin_false_e_analistas_vazio(): void
    {
        // Cria universo com um analista vinculado, para provar que mesmo
        // existindo analistas no banco, o não-admin NÃO os recebe.
        $emp = $this->novaEmpresa('X');
        $outroAnalista = User::factory()->create(['role' => 'consultor']);
        $this->marcarComoAnalistaPorCargo($outroAnalista);
        $this->attachCarteira($emp, $outroAnalista, 'consultor');

        // Usuário logado: consultor com CORE_SUGADORES + carteira própria.
        $meuUser = $this->userComCoreSugadores('meu-consultor');
        $this->attachCarteira($emp, $meuUser, 'consultor');

        $response = $this->actingAs($meuUser)->get(route('sugadores.index'));
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $this->assertArrayHasKey('is_admin', $props);
        $this->assertFalse($props['is_admin'], 'Consultor NÃO deve receber is_admin=true.');

        $this->assertArrayHasKey('analistas', $props);
        $this->assertSame([], $props['analistas'], 'Não-admin deve receber analistas=[].');
    }

    /**
     * Cenário 3 — Admin com ?analista_id=X reduz companies_summary para as
     * empresas em que o user X aparece na pivot com role='analista'.
     */
    public function test_filtro_analista_id_reduz_companies_summary_para_admin(): void
    {
        $admin = $this->admin();

        $empA = $this->novaEmpresa('A'); // vinculada ao analista X
        $empB = $this->novaEmpresa('B'); // vinculada ao analista Y
        $empC = $this->novaEmpresa('C'); // sem analista

        $analistaX = User::factory()->create(['role' => 'consultor', 'name' => 'X']);
        $analistaY = User::factory()->create(['role' => 'consultor', 'name' => 'Y']);

        $this->marcarComoAnalistaPorCargo($analistaX);
        $this->marcarComoAnalistaPorCargo($analistaY);
        $this->attachCarteira($empA, $analistaX, 'consultor');
        $this->attachCarteira($empB, $analistaY, 'consultor');

        $response = $this->actingAs($admin)->get(
            route('sugadores.index', ['analista_id' => $analistaX->id])
        );
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $this->assertArrayHasKey('companies_summary', $props);
        $ids = array_column($props['companies_summary'], 'company_id');
        $this->assertSame([$empA->id], $ids, 'Só empresa A (analista X) deveria aparecer no summary.');
    }

    /**
     * Cenário 4 — Não-admin com ?analista_id=X: o param é IGNORADO,
     * companies_summary continua sendo a carteira própria do user. Proteção
     * contra bypass (analista tentando espiar carteira de outro analista).
     */
    public function test_filtro_analista_id_ignorado_para_nao_admin(): void
    {
        // Universo: 3 empresas, todas na carteira do consultor logado.
        $empA = $this->novaEmpresa('A');
        $empB = $this->novaEmpresa('B');
        $empC = $this->novaEmpresa('C');

        // Outro analista (X) vinculado por cargo + role consultor em A — não deve interferir.
        $analistaX = User::factory()->create(['role' => 'consultor']);
        $this->marcarComoAnalistaPorCargo($analistaX);
        $this->attachCarteira($empA, $analistaX, 'consultor');

        // Consultor logado tem carteira em A, B e C (como consultor).
        $meuUser = $this->userComCoreSugadores('meu-consultor2');
        $this->attachCarteira($empA, $meuUser, 'consultor');
        $this->attachCarteira($empB, $meuUser, 'consultor');
        $this->attachCarteira($empC, $meuUser, 'consultor');

        $response = $this->actingAs($meuUser)->get(
            route('sugadores.index', ['analista_id' => $analistaX->id])
        );
        $response->assertOk();

        $props = $this->extractInertiaPage($response)['props'];

        $this->assertArrayHasKey('companies_summary', $props);
        $ids = array_column($props['companies_summary'], 'company_id');
        sort($ids);
        $expected = [$empA->id, $empB->id, $empC->id];
        sort($expected);
        $this->assertSame($expected, $ids, 'Non-admin: filtro analista_id deve ser ignorado (proteção contra bypass).');
    }
}
