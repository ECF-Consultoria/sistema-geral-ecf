<?php

namespace Tests\Feature\Phase123;

use App\Models\Company;
use App\Models\DesempenhoCompanyScoreSnapshot;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base de fixtures herdada pelas 6 suítes `Phase123` (123-01-PLAN.md Task 1)
 * — nenhuma delas deve reescrever fixture. Espelha o padrão já usado em
 * `tests/Feature/Phase122/VerificarConsolidacaoTest.php`.
 *
 * "Hoje" é fixado em 2026-08-15 → 2026-06 e 2026-07 são competências
 * fechadas.
 */
abstract class Phase123TestCase extends TestCase
{
    use RefreshDatabase;

    protected int $setorId;

    protected int $cargoAnalistaId;

    protected int $cargoEstrategistaId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));

        $this->setorId = DB::table('setores')->insertGetId([
            'nome'       => 'Performance',
            'slug'       => 'performance-123',
            'active'     => true,
            'is_system'  => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cargoAnalistaId = DB::table('cargos')->insertGetId([
            'setor_id'   => $this->setorId,
            'nome'       => 'Analista',
            'slug'       => 'analista',
            'active'     => true,
            'ordem'      => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ═══ Helpers ══════════════════════════════════════════════════════════

    /**
     * Cria um profissional elegível pelo cargo informado (`analista` por
     * padrão), vinculado ao setor `performance-123`.
     */
    protected function criarUserElegivel(string $nome, string $cargoSlug = 'analista'): User
    {
        $cargoId = $cargoSlug === 'estrategista' ? $this->cargoEstrategistaId : $this->cargoAnalistaId;

        $user = User::factory()->create(['name' => $nome, 'role' => 'consultor', 'active' => true]);

        DB::table('user_setores')->insert([
            'user_id'      => $user->id,
            'setor_id'     => $this->setorId,
            'cargo_id'     => $cargoId,
            'is_principal' => true,
            'assigned_at'  => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $user;
    }

    /**
     * Cria um admin autenticado (`actingAs`) para os testes das telas
     * admin-only (Relatório de Bonificação / Auditoria de Bônus).
     */
    protected function adminLogado(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Vincula uma empresa nova à carteira do profissional. Devolve o
     * `company_id` gerado.
     */
    protected function darCarteira(User $user): int
    {
        $company = Company::factory()->create();

        DB::table('company_users')->insert([
            'user_id'    => $user->id,
            'company_id' => $company->id,
            'role'       => 'consultor',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $company->id;
    }

    /**
     * Row mensal agregada (`desempenho_score_snapshots`). Semear este
     * snapshot faz `PerformanceController::show()` usar `breakdown_json` em
     * vez de cair no `computeCached()` — é a fixture que torna os testes
     * desta fase herméticos (sem HTTP).
     */
    protected function seedMensal(int $userId, string $mesStr, array $overrides = []): DesempenhoScoreSnapshot
    {
        return DesempenhoScoreSnapshot::create(array_merge([
            'user_id'              => $userId,
            'ref_date'             => $mesStr,
            'mes_referencia'       => $mesStr,
            'score'                => 80,
            'classificacao'        => 'intermediario',
            'tem_base_comparativa' => true,
            'empresas_carteira'    => 1,
            'empresas_eligiveis'   => 1,
            'breakdown_json'       => [
                'nota_final'     => 4.0,
                'score_status'   => 'complete',
                'margem_amostra' => [
                    'cobertura' => 0.8,
                    'base'      => 'margem_var_pp',
                    'legado'    => ['cobertura' => 0.75],
                ],
                'componentes'    => [
                    'nps_medio'          => 4.0,
                    'var_faturamento_pct' => 5.0,
                    'var_margem_pct'      => 2.0,
                    'var_margem_pp'       => 2.0,
                ],
                'empresas_score' => [],
            ],
        ], $overrides));
    }

    /**
     * Linha por empresa (`desempenho_company_score_snapshots`) — cópia
     * literal do helper de `VerificarConsolidacaoTest`, acrescida do default
     * de `quality`.
     */
    protected function seedLinha(int $userId, int $companyId, string $mesStr, array $overrides = []): DesempenhoCompanyScoreSnapshot
    {
        return DesempenhoCompanyScoreSnapshot::create(array_merge([
            'user_id'               => $userId,
            'company_id'            => $companyId,
            'mes_referencia'        => $mesStr,
            'company_name'          => "Empresa {$companyId}",
            'fonte_financeira'      => 'adman',
            'status'                => 'complete',
            'margem_var_pp'         => 2.0,
            'nota_empresa'          => 4.0,
            'nota_empresa_parcial'  => 4.0,
            'componentes_presentes' => 3,
            'origem'                => 'consolidar_mes',
            'gerado_em'             => now(),
            'quality'               => [
                'revenue_diff_source' => 'native',
                'margin_diff_source'  => 'native',
                'margin_source'       => null,
                'motivos'             => [],
            ],
        ], $overrides));
    }

    /**
     * Atalho para o caso Matheus Estrela (carteira só-Shopee): margem
     * marcada como placeholder — a régua de margem nunca é aplicada sobre
     * Shopee (D-02 do `CompanyScoreService`).
     */
    protected function seedLinhaShopee(int $userId, int $companyId, string $mesStr, array $overrides = []): DesempenhoCompanyScoreSnapshot
    {
        return $this->seedLinha($userId, $companyId, $mesStr, array_merge([
            'fonte_financeira'    => 'shopee',
            'status'              => 'complete',
            'margem_pontos'       => 1.0,
            'margem_var_pp'       => null,
            'margem_pct_atual'    => null,
            'margem_pct_anterior' => null,
            'quality'             => [
                'revenue_diff_source' => 'native',
                'margin_diff_source'  => null,
                'margin_source'       => 'placeholder_shopee',
                'motivos'             => [],
            ],
        ], $overrides));
    }
}
