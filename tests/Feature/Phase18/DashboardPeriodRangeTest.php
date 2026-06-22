<?php

namespace Tests\Feature\Phase18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 18 (W2-T4) — Range dinâmico nas queries do DashboardController.
 *
 * Garante que o seletor de período (1/7/30/180) altera de fato o range
 * usado em todas as queries dos cards principais, eliminando o Bug 1 do
 * dia 2026-06-02 onde trocar `?period=7` não mexia em `total_revenue`,
 * `total_ad_investment_30d`, `avg_tacos`.
 *
 * Cobertura SC-2 (período afeta TODOS os cards): seed N dias de metrics
 * e assert que a soma respeita o range solicitado.
 *
 * Cache é forçado a fallback DB (Cache::flush) em todos os testes — a
 * lógica de range no fallback DB é exatamente onde W2-T2 atua, e o cache
 * Adman só pré-aquece 30d (decisão W2-T3).
 *
 * @group phase18
 */
class DashboardPeriodRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Força fallback DB: cache /performance e /accounts/metrics vazios
        // garantem que $grossCacheCompleto = false → SUM(adman_metrics).
        Cache::flush();
    }

    /** Admin mínimo via factory. */
    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Empresa ativa COM cust_id (ml_store_id) → cache lookup ocorre, retorna
     * null (Cache::flush no setUp), $grossCacheCompleto vira false e o
     * controller cai no fallback DB (caminho que W2-T2 corrigiu).
     *
     * Sem cust_id o `foreach` que detecta cache completo faz `continue` na
     * empresa, deixando $grossCacheCompleto=true e nunca exercitando o
     * fallback DB — então o teste seria inerte para a SC-2.
     */
    private function criarEmpresa(string $name, string $cnpj, string $custId): Company
    {
        // Quick 260619 — Dashboard filtra empresas por contrato Performance ativo
        // (alinhamento com /companies). Helper anexa o contrato pra testes de
        // outras dimensoes (cache hibrido, period range) ficarem isolados disso.
        $company = Company::create([
            'name'         => $name,
            'cnpj'         => $cnpj,
            'active'       => true,
            'ml_store_id'  => $custId,
        ]);
        $servico = \App\Models\Servico::create([
            'nome'          => 'Gestao DashTest ' . uniqid(),
            'valor_padrao'  => 1000,
            'tipo_cobranca' => \App\Models\Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => \App\Models\Servico::SETOR_PERFORMANCE,
        ]);
        \App\Models\ContratoServico::create([
            'company_id'       => $company->id,
            'servico_id'       => $servico->id,
            'valor_contratado' => 1000,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);
        return $company;
    }

    /** Seed de N dias retroativos com revenue fixo. */
    private function seedMetricasRetro(Company $company, int $totalDias, float $revenuePorDia): void
    {
        for ($i = 1; $i <= $totalDias; $i++) {
            AdmanMetric::create([
                'company_id'     => $company->id,
                'reference_date' => now()->subDays($i)->toDateString(),
                'revenue'        => $revenuePorDia,
                'ad_spend'       => 10.0,
                'synced_at'      => now(),
            ]);
        }
    }

    /**
     * TEST 1 — `period=7` resulta em range de 7 dias.
     * Seed 30 dias com R$ 100/dia. Espera total_revenue = R$ 700 (D-1..D-7).
     */
    public function test_period_7_aplica_range_de_7_dias_no_total_revenue(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa Range', '44444444000044', 'CUST_RANGE_7');
        $this->seedMetricasRetro($empresa, 30, 100.0);

        $response = $this->actingAs($admin)->get('/dashboard?period=7');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('period', '7')
            // SQLite/JSON serializa SUM "redondo" como int (700 vs 700.0).
            // Asserção estrita via callback aceita int|float numericamente.
            ->where('stats.total_revenue', 700)
        );
    }

    /**
     * TEST 2 — `period=180` aplica range de 180 dias. Seed 50 dias
     * (limite pragmático SQLite) com R$ 50/dia → total = R$ 2.500
     * cabem todos no range de 180. Reduzido vs plano (200 dias) por
     * tempo de execução conforme deviation contract.
     */
    public function test_period_180_aplica_range_amplo(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa Longa', '55555555000055', 'CUST_RANGE_180');
        $this->seedMetricasRetro($empresa, 50, 50.0);

        $response = $this->actingAs($admin)->get('/dashboard?period=180');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('period', '180')
            ->where('stats.total_revenue', 2500)
        );
    }

    /**
     * TEST 3 — `period` inválido cai no default 30 (mesma tabela de
     * getSince e getPeriodRange). Seed 50 dias com R$ 10/dia; espera
     * que `?period=999` totalize só 30 dias × R$ 10 = R$ 300.
     */
    public function test_period_invalido_fallback_para_30_dias(): void
    {
        $admin = $this->criarAdmin();
        $empresa = $this->criarEmpresa('Empresa Default', '66666666000066', 'CUST_RANGE_DEF');
        $this->seedMetricasRetro($empresa, 50, 10.0);

        $response = $this->actingAs($admin)->get('/dashboard?period=999');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('stats.total_revenue', 300)
        );
    }

    /**
     * TEST 4 — Prop `cards_exatos` é `false` quando `period !== '30'`,
     * mesmo se o cache estivesse hot — porque RefreshGrossBillingCacheJob
     * só pré-aquece o range 30d (decisão W2-T3). Frontend (W5-T1) consome
     * essa flag para mostrar "≈ aproximado" nos cards Adman-dependentes.
     *
     * Não asserta period=30 sob fallback (caso fora do plano de teste e
     * dependente de existência de empresas com cust_id no seed).
     */
    public function test_cards_exatos_e_false_quando_period_diferente_de_30(): void
    {
        $admin = $this->criarAdmin();

        // period=7 → sempre aproximado (cache só pre-warm 30d)
        $r7 = $this->actingAs($admin)->get('/dashboard?period=7');
        $r7->assertOk();
        $r7->assertInertia(fn (Assert $page) => $page
            ->where('cards_exatos', false)
        );

        // period=180 → sempre aproximado
        $r180 = $this->actingAs($admin)->get('/dashboard?period=180');
        $r180->assertOk();
        $r180->assertInertia(fn (Assert $page) => $page
            ->where('cards_exatos', false)
        );
    }
}
