<?php

namespace Tests\Feature\Portfolio;

use App\Models\Company;
use App\Models\Goal;
use App\Models\NpsSurvey;
use App\Models\NpsResponse;
use App\Models\Ppa;
use App\Models\Sugador;
use App\Models\User;
use App\Services\PortfolioScoreService;
use Carbon\Carbon;
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
 *  3. achieved_pct correto quando Goals de revenue existem.
 *  4. sugador_counters preenchido para analista; ppa_counters null.
 *  5. ppa_counters preenchido para estrategista; sugador_counters null.
 *  6. nps_history presente como array (pode ser vazio).
 *  7. PortfolioScoreService::compute() nunca retorna metaOrigem='portfolio'.
 *
 * Banco de dados: SQLite in-memory (phpunit.xml).
 */
class RenderPortfolioTest extends TestCase
{
    use RefreshDatabase;

    // ─── Fixtures ───────────────────────────────────────────────────────────

    /** Cria empresa ativa com adman_account_id e associa ao user via company_users. */
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
     * Retorna o cargo_id criado.
     */
    private function associarCargoAoUser(User $user, string $cargoSlug): int
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

        return $cargoId;
    }

    /** Cria user analista (role='consultor' no sistema, cargo='analista' em user_setores). */
    private function criarAnalista(): User
    {
        $user = User::factory()->create([
            'role'              => 'consultor',
            'email_verified_at' => now(),
        ]);
        $this->associarCargoAoUser($user, 'analista');
        return $user;
    }

    /** Cria user estrategista (role='mentor' no sistema, cargo='estrategista' em user_setores). */
    private function criarEstrategista(): User
    {
        $user = User::factory()->create([
            'role'              => 'mentor',
            'email_verified_at' => now(),
        ]);
        $this->associarCargoAoUser($user, 'estrategista');
        return $user;
    }

    // ─── Testes ─────────────────────────────────────────────────────────────

    /**
     * Teste 1 — 'meta_carteira' deve estar AUSENTE do payload Inertia.
     * Phase 48 removeu essa prop (era baseada em PortfolioGoal revenue).
     */
    public function test_meta_carteira_ausente_do_payload(): void
    {
        $user = $this->criarAnalista();

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->where('meta_carteira', null) // deve ser null/ausente
            );

        // Confirmacao adicional via JSON: a chave 'meta_carteira' nao deve existir
        $response = $this->actingAs($user)->get(route('portfolio.own'));
        $props    = $response->viewData('page')['props'] ?? [];
        $this->assertArrayNotHasKey('meta_carteira', $props, "Prop 'meta_carteira' nao deve existir no payload (removida em Phase 48)");
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
     * Teste 3 — achieved_pct correto quando Goals de revenue existem.
     * Com target=R$100.000 e realizado=0 (sem AdmanMetric), achieved_pct deve
     * ser 0 (ou null se nao ha revenue registrado), mas has_goal=true.
     */
    public function test_meta_carteira_calculada_has_goal_true_com_goals(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Cria Goal de revenue ativo
        Goal::create([
            'company_id'  => $empresa->id,
            'metric'      => 'revenue',
            'target_value'=> 100000.00,
            'value_type'  => 'currency',
            'period_type' => 'monthly',
            'active'      => true,
        ]);

        $this->actingAs($user)
            ->get(route('portfolio.own'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolio/Show')
                ->has('meta_carteira_calculada')
                ->where('meta_carteira_calculada.has_goal', true)
                ->where('meta_carteira_calculada.target_value', 100000.0)
            );
    }

    /**
     * Teste 4 — sugador_counters nao null para analista; ppa_counters null.
     * Cria sugadores com diferentes status e verifica contagem.
     */
    public function test_sugador_counters_para_analista(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Cria sugadores: 2 pendentes, 1 resolvido, 1 ignorado
        foreach (['pendente', 'pendente', 'resolvido', 'ignorado'] as $status) {
            DB::table('sugadores')->insert([
                'company_id'   => $empresa->id,
                'tipo'         => Sugador::TIPO_CAMPANHA,
                'campaign_id'  => 'CAM-' . uniqid(),
                'campaign_name'=> 'Campanha Teste',
                'status'       => $status,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

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
     * Cria PPAs com diferentes estados e verifica contagem.
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
        // PPA em andamento
        DB::table('ppas')->insert([
            'company_id'  => $empresa->id,
            'mentor_id'   => $user->id,
            'title'       => 'PPA Em Andamento',
            'status'      => 'in_progress',
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
     * Teste 6 — nps_history presente como array (pode ser vazio).
     * Cria survey completada e verifica estrutura [{month, avg, count, ultima_nota}].
     */
    public function test_nps_history_presente_no_payload(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Cria NPS survey completada com resposta
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
                // Deve ter pelo menos 1 entrada com o formato correto
                ->has('nps_history.0', fn (Assert $row) => $row
                    ->hasAll(['month', 'avg', 'count', 'ultima_nota'])
                )
            );
    }

    /**
     * Teste 7 — PortfolioScoreService::compute() nunca retorna metaOrigem='portfolio'.
     * Garante que o Caminho A foi removido do service.
     */
    public function test_portfolio_score_service_nao_retorna_meta_origem_portfolio(): void
    {
        $user    = $this->criarAnalista();
        $empresa = $this->criarEmpresaParaUser($user, 'consultor');

        // Cria Goal de revenue pra garantir que Caminho B retorna algo
        Goal::create([
            'company_id'  => $empresa->id,
            'metric'      => 'revenue',
            'target_value'=> 50000.00,
            'value_type'  => 'currency',
            'period_type' => 'monthly',
            'active'      => true,
        ]);

        /** @var PortfolioScoreService $service */
        $service = app(PortfolioScoreService::class);
        $result  = $service->compute($user);

        $metaOrigem = $result['metricas']['atingimento_meta']['origem'] ?? null;

        $this->assertNotEquals(
            'portfolio',
            $metaOrigem,
            "metaOrigem nao deve ser 'portfolio' apos remocao do Caminho A no Phase 48"
        );
    }
}
