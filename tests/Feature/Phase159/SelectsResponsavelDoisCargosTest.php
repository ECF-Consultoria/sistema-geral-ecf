<?php

namespace Tests\Feature\Phase159;

use App\Models\Cargo;
use App\Models\Company;
use App\Models\Servico;
use App\Models\Setor;
use App\Models\User;
use App\Services\FluxoEntrada\DistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\V16\CriaCenarioResponsaveis;
use Tests\TestCase;

/**
 * Fase 159 Plano 03 — D-04/SC3: quem tem os dois cargos (analista e
 * estrategista) aparece nos dois selects de responsável, nos quatro lugares
 * que resolvem "quem tem o cargo X".
 *
 * A pesquisa (§5) mostrou que os quatro backends já resolvem por query
 * independente por slug (`whereIn`/`whereExists`), não por `keyBy`/`value()`
 * — então NENHUM deles precisa de mudança de código aqui. Este plano só
 * escreve o teste de regressão; se algum falhar, é achado (ver SUMMARY).
 */
class SelectsResponsavelDoisCargosTest extends TestCase
{
    use RefreshDatabase;
    use CriaCenarioResponsaveis;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function cargo(Setor $setor, string $slug): Cargo
    {
        return Cargo::firstOrCreate(
            ['setor_id' => $setor->id, 'slug' => $slug],
            ['nome' => ucfirst($slug)]
        );
    }

    private function darCargo(User $user, Cargo $cargo, bool $principal = false): void
    {
        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $cargo->setor_id,
            'cargo_id'     => $cargo->id,
            'is_principal' => $principal,
            'assigned_at'  => now()->toDateString(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 1 — /companies: props analistas/estrategistas
    // ═════════════════════════════════════════════════════════════════════

    public function test_companies_index_lista_a_pessoa_nos_dois_selects(): void
    {
        // A migration 2026_09_10_140000_seed_setor_performance já cria o
        // setor Performance — firstOrCreate por idempotência.
        $setorPerf = Setor::firstOrCreate(['slug' => 'performance'], ['nome' => 'Performance', 'active' => true]);
        $cargoAnalista     = $this->cargo($setorPerf, 'analista');
        $cargoEstrategista = $this->cargo($setorPerf, 'estrategista');

        $pessoa = User::factory()->create(['active' => true, 'role' => 'consultor']);
        $this->darCargo($pessoa, $cargoAnalista, true);
        $this->darCargo($pessoa, $cargoEstrategista, false);

        $response = $this->actingAs($this->admin())->get('/companies');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Index')
            ->where('analistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
            ->where('estrategistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 2 — /shopee/empresas: props analistas/estrategistas (cargos do
    // próprio setor shopee)
    // ═════════════════════════════════════════════════════════════════════

    public function test_shopee_empresas_index_lista_a_pessoa_nos_dois_selects(): void
    {
        // Seedado por 2026_07_14_120000_seed_setor_shopee_e_usuarios — cargos
        // 'analista'/'estrategista' escopados ao setor shopee já existem.
        $setorShopee = Setor::where('slug', 'shopee')->firstOrFail();
        $cargoAnalista     = $this->cargo($setorShopee, 'analista');
        $cargoEstrategista = $this->cargo($setorShopee, 'estrategista');

        $pessoa = User::factory()->create(['active' => true, 'role' => 'consultor']);
        $this->darCargo($pessoa, $cargoAnalista, true);
        $this->darCargo($pessoa, $cargoEstrategista, false);

        $response = $this->actingAs($this->admin())->get('/shopee/empresas');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shopee/Empresas')
            ->where('analistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
            ->where('estrategistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 3 — DistribuicaoService::elegiveis()
    // ═════════════════════════════════════════════════════════════════════

    public function test_distribuicao_elegiveis_lista_a_pessoa_nos_dois_cargos(): void
    {
        $setorPerf = Setor::firstOrCreate(['slug' => 'performance'], ['nome' => 'Performance', 'active' => true]);
        $cargoAnalista     = $this->cargo($setorPerf, 'analista');
        $cargoEstrategista = $this->cargo($setorPerf, 'estrategista');

        $pessoa = User::factory()->create(['active' => true, 'role' => 'consultor']);
        $this->darCargo($pessoa, $cargoAnalista, true);
        $this->darCargo($pessoa, $cargoEstrategista, false);

        $company     = Company::factory()->create();
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        $r = app(DistribuicaoService::class)->elegiveis($company->fresh());

        $this->assertContains($pessoa->id, array_column($r['analistas'], 'id'));
        $this->assertContains($pessoa->id, array_column($r['estrategistas'], 'id'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 4 — DistribuicaoService::distribuir() grava os dois papéis
    // quando analistaId === estrategistaId
    // ═════════════════════════════════════════════════════════════════════

    public function test_distribuicao_distribuir_grava_a_mesma_pessoa_nos_dois_papeis(): void
    {
        $admin  = $this->admin();
        $pessoa = User::factory()->create(['active' => true, 'role' => 'consultor']);

        $company     = Company::factory()->create(['etapa' => Company::ETAPA_AGUARDANDO_DISTRIBUICAO]);
        $servicoPerf = $this->criarServico(Servico::SETOR_PERFORMANCE, true);
        $this->criarContrato($company->id, $servicoPerf, true);

        // O status devolvido pela transição de etapa não importa aqui — só a
        // pivot, que é gravada ANTES da tentativa de transição (D-E).
        app(DistribuicaoService::class)->distribuir($company->fresh(), $pessoa->id, $pessoa->id, $admin);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id,
            'user_id'    => $pessoa->id,
            'role'       => 'consultor',
            'servico_id' => $servicoPerf,
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $company->id,
            'user_id'    => $pessoa->id,
            'role'       => 'estrategista',
            'servico_id' => $servicoPerf,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Teste 5 — GET /dashboard?period=30: props analistas/estrategistas do
    // filtro admin
    // ═════════════════════════════════════════════════════════════════════

    public function test_dashboard_admin_lista_a_pessoa_nos_dois_filtros(): void
    {
        // Mesmo padrão de DashboardWidgetsRecorteTest::setUp — sem HTTP
        // externo e cache isolado entre testes.
        Http::preventStrayRequests();
        Http::fake([
            '*/performance/*'       => Http::response([], 404),
            '*/accounts/*/metrics*' => Http::response([], 404),
        ]);
        Cache::flush();

        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance 159-03',
            'slug'       => 'performance-159-03-' . uniqid(),
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $setorId, 'nome' => 'Analista', 'slug' => 'analista',
            'active' => true, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cargoEstrategistaId = DB::table('cargos')->insertGetId([
            'setor_id' => $setorId, 'nome' => 'Estrategista', 'slug' => 'estrategista',
            'active' => true, 'ordem' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pessoa = User::factory()->create(['active' => true, 'role' => 'consultor']);
        DB::table('user_setores')->insert([
            ['user_id' => $pessoa->id, 'setor_id' => $setorId, 'cargo_id' => $cargoAnalistaId, 'is_principal' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $pessoa->id, 'setor_id' => $setorId, 'cargo_id' => $cargoEstrategistaId, 'is_principal' => false, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('analistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
            ->where('estrategistas', fn ($lista) => collect($lista)->pluck('id')->contains($pessoa->id))
        );
    }
}
