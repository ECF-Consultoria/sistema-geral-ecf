<?php

namespace Tests\Feature\Portfolio;

use App\Models\Company;
use App\Models\Goal;
use App\Models\NpsSurvey;
use App\Models\NpsResponse;
use App\Models\Sugador;
use App\Models\User;
use App\Services\PortfolioScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 48-01 — Cobre mudancas backend de renderPortfolio().
 *
 * Verifica:
 *  1. 'meta_carteira' ausente do payload (removida em Phase 48).
 *  2. 'meta_carteira_calculada' presente com has_goal=false quando sem Goals.
 *  3. has_goal=true e target_value correto com Goals de revenue.
 *  4. sugador_counters preenchido para analista; ppa_counters null.
 *  5. ppa_counters preenchido para estrategista; sugador_counters null.
 *  6. nps_history presente como array com estrutura correta.
 *  7. PortfolioScoreService::compute() nunca retorna metaOrigem='portfolio'.
 *
 * Banco de dados: SQLite in-memory (phpunit.xml).
 * Nota: SQLite nao executa ALTER ENUM das migrations posteriores, entao
 * status de sugadores aceita apenas os valores originais do create_table.
 * Para goals.metric='revenue', desabilitamos CHECK via PRAGMA.
 */
class RenderPortfolioTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** Cria empresa ativa e associa ao user via company_users. */
    private function criarEmpresaParaUser(User $user, string $role = 'consultor'): Company
    {
        $empresa = Company::create([
            'name'             => 'Empresa Teste ' . uniqid(),
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-' . uniqid(),
            'active'           => true,
        ]);

        DB::table('company_users')->insert([
            'company_id'  => $empresa->id,
            'user_id'     => $user->id,
            'role'        => $role,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $empresa;
    }

    /**
     * Cria setor + cargo com o slug informado e associa ao user via user_setores.
     */
    private function associarCargoAoUser(User $user, string $cargoSlug): void
    {
        $setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Setor ' . $cargoSlug . ' ' . uniqid(),
            'slug'       => 'setor-' . $cargoSlug . '-' . uniqid(),
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cargoId = DB::table('cargos')->insertGetId([
            'setor_id'   => $setorId,
            'nome'       => ucfirst($cargoSlug),
            'slug'       => $cargoSlug,
            'active'     => true,
            'ordem'      => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_setores')->insert([
            'user_id'     => $user->id,
            'setor_id'    => $setorId,
            'cargo_id'    => $cargoId,
            'is_principal'=> true,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /** Cria user analista (role='consultor', cargo='analista' em user_setores). */
    private function criarAnalista(): User
    {
        $user = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
        $this->associarCargoAoUser($user, 'analista');
        return $user;
    }

    /** Cria user estrategista (role='mentor', cargo='estrategista' em user_setores). */
    private function criarEstrategista(): User
    {
        $user = User::factory()->create([
            'role'              => 'mentor',
            'email_verified_at' => now(),
        ]);
        $this->associarCargoAoUser($user, 'estrategista');
        return $user;
    }

    /**
     * Insere Goal com metric='revenue' contornando o CHECK constraint do SQLite
     * (o ALTER ENUM que adiciona 'revenue' a lista so executa em MySQL).
     * Usa PRAGMA ignore_check_constraints=ON temporariamente.
     */
    private function criarGoalRevenue(int $companyId, float $target): void
    {
        DB::unprepared('PRAGMA ignore_check_constraints=ON');
        DB::table('goals')->insert([
            'company_id'  => $companyId,
            'metric'      => 'revenue',
            'target_value'=> $target,
            'value_type'  => 'currency',
            'period_type' => 'monthly',
            'active'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::unprepared('PRAGMA ignore_check_constraints=OFF');
    }

    /**
     * Insere Sugador com os campos obrigatorios. Em SQLite, so os status
     * do create_table original sao aceitos pelo CHECK: pendente, em_acao,
     * resolvido, ignorado.
     */
    private function criarSugador(int $companyId, string $status): void
    {
        DB::table('sugadores')->insert([
            'company_id'         => $companyId,
            'reference_date'     => now()->toDateString(),
            'tipo'               => Sugador::TIPO_CAMPANHA,
            'campaign_id'        => 'CAM-' . uniqid(),
            'campaign_name'      => 'Campanha Teste',
            'periodo_inicio'     => now()->subDays(30)->toDateString(),
            'periodo_fim'        => now()->toDateString(),
            'investimento_periodo'=> 0,
            'faturamento_periodo' => 0,
            'motivos'            => json_encode(['tacos_alto']),
            'status'             => $status,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    /**
     * Teste 1 — 'meta_carteira' deve estar AUSENTE do payload Inertia.
     * Phase 48 removeu essa prop (era baseada em PortfolioGoal revenue).
     */
    public function test_meta_carteira_ausente_do_payload(): void
    {
        $user = $this->criarAnalista();

        $response = $this->actingAs($user)->get(route('portfolio.own'));
        $response->assertOk();

        // Extrai props do Inertia via dados da view (page.props)
        $props = $response->viewData('page')['props'] ?? [];

        $this->assertArrayNotHasKey(
            'meta_carteira',
            $props,
            "Prop 'meta_carteira' nao deve existir no payload (removida em Phase 48)"
        );
    }

    /**
     * Teste 2 — 'meta_carteira_calculada' presente com has_goal=false quando
     * nao ha Goals de revenue na carteira.
     */
    public function test_meta_carteira_calculada_presente_sem_goals(): void
    {
        $user = $this->criarAnalista();
        $this->criarEmpresaParaUser($user, 'consultor');

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->has('meta_carteira_calculada')
                ->where('meta_carteira_calculada.has_goal', false)
                ->where('meta_carteira_calculada.target_value', null)
            );
    }

    /**
     * Teste 3 — has_goal=true e target_value correto com Goals de revenue.
     * Usa PRAGMA para bypassar o CHECK constraint do SQLite.
     */
    public function test_meta_carteira_calculada_has_goal_true_com_goals(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        $this->criarGoalRevenue($empresa->id, 100000.00);

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->has('meta_carteira_calculada')
                ->where('meta_carteira_calculada.has_goal', true)
                ->where('meta_carteira_calculada.target_value', fn ($v) => (float) $v === 100000.0)
            );
    }

    /**
     * Teste 4 — sugador_counters nao null para analista; ppa_counters null.
     * Status validos no SQLite: pendente, em_acao, resolvido, ignorado.
     */
    public function test_sugador_counters_para_analista(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // 2 pendentes, 1 resolvido, 1 ignorado
        $this->criarSugador($empresa->id, 'pendente');
        $this->criarSugador($empresa->id, 'pendente');
        $this->criarSugador($empresa->id, 'resolvido');
        $this->criarSugador($empresa->id, 'ignorado');

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->where('ppa_counters', null)
                ->has('sugador_counters')
                ->where('sugador_counters.pendentes', 2)
                ->where('sugador_counters.resolvidos', 1)
                ->where('sugador_counters.nao_resolvidos', 1)
            );
    }

    /**
     * Teste 5 — ppa_counters nao null para estrategista; sugador_counters null.
     * Status validos no SQLite: draft, sent, completed (CREATE TABLE original).
     */
    public function test_ppa_counters_para_estrategista(): void
    {
        $user    = $this->criarEstrategista();
        $empresa = $this->criarEmpresaParaUser($user, 'estrategista');

        // PPA concluido este mes
        DB::table('ppas')->insert([
            'company_id'  => $empresa->id,
            'mentor_id'   => $user->id,
            'title'       => 'PPA Concluido',
            'status'      => 'completed',
            'completed_at'=> now()->toDateTimeString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        // PPA em andamento (status 'draft' = nao concluido)
        DB::table('ppas')->insert([
            'company_id'  => $empresa->id,
            'mentor_id'   => $user->id,
            'title'       => 'PPA Em Andamento',
            'status'      => 'draft',
            'completed_at'=> null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->where('sugador_counters', null)
                ->has('ppa_counters')
                ->where('ppa_counters.concluidos_mes', 1)
                ->where('ppa_counters.em_andamento', 1)
                ->where('ppa_counters.total', 2)
            );
    }

    /**
     * Teste 6 — nps_history presente como array com estrutura {month, avg, count, ultima_nota}.
     * Cria survey completada e verifica que o historico e retornado.
     */
    public function test_nps_history_presente_no_payload(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Survey completada com resposta para o mes atual
        $survey = NpsSurvey::create([
            'token'          => 'tok-' . uniqid(),
            'company_id'     => $empresa->id,
            'generated_by'   => null,
            'status'         => 'completed',
            'completed_at'   => now(),
            'month_reference'=> now()->startOfMonth()->toDateString(),
        ]);
        NpsResponse::create([
            'survey_id'          => $survey->id,
            'score_estrategista' => 4,
            'score_analista'     => 5,
            'score_empresa'      => 4,
        ]);

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->has('nps_history')
                // Deve ter pelo menos 1 entrada com todos os campos obrigatorios
                ->has('nps_history.0', fn (Assert $row) => $row
                    ->hasAll(['month', 'avg', 'count', 'ultima_nota'])
                )
            );
    }

    /**
     * Teste 7 — PortfolioScoreService::compute() nunca retorna metaOrigem='portfolio'.
     * Garante que o Caminho A (PortfolioGoal) foi removido do service.
     */
    public function test_portfolio_score_service_nao_retorna_meta_origem_portfolio(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Goal de revenue para que Caminho B retorne metaOrigem='empresas:1'
        $this->criarGoalRevenue($empresa->id, 50000.00);

        /** @var PortfolioScoreService $service */
        $service = app(PortfolioScoreService::class);
        $result  = $service->compute($user);

        $metaOrigem = $result['metricas']['atingimento_meta']['origem'] ?? null;

        $this->assertNotEquals(
            'portfolio',
            $metaOrigem,
            "metaOrigem nao deve ser 'portfolio' apos remocao do Caminho A (Phase 48)"
        );

        // Com Goal de revenue, metaOrigem deve ser 'empresas:1'
        if ($metaOrigem !== null) {
            $this->assertStringStartsWith(
                'empresas:',
                $metaOrigem,
                "metaOrigem deve comecar com 'empresas:' quando ha Goals de revenue"
            );
        }
    }
}
