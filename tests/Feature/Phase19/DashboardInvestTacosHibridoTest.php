<?php

namespace Tests\Feature\Phase19;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 19 — Plano 03 (2026-06-05). Cobertura do híbrido per-empresa para
 * "Invest. Ads 30d" e "TACOS médio".
 *
 * Antes: política tudo-ou-nada do account cache — 1 empresa em cache miss
 * fazia AMBOS os cards caírem em fallback DB agregado (`SUM(ad_spend)` da
 * base inteira). Como 33 empresas Shopee/Amazon (Phase 18.5) têm sync
 * histórico incompleto, o SUM subestimava os totais.
 *
 * Agora: cada empresa decide individualmente — cache hit usa investment/
 * tacos exatos da Adman; cache miss usa SUM(ad_spend) só da empresa, com
 * TACOS recalculado como (ad_spend/revenue)*100.
 */
class DashboardInvestTacosHibridoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function empresa(string $name, string $cnpj, ?string $custId): Company
    {
        // Quick 260619 — Dashboard filtra por contrato Performance ativo.
        // Helper anexa o contrato pra testes de invest/tacos hibrido ficarem
        // isolados desse filtro.
        $company = Company::create([
            'name'        => $name,
            'cnpj'        => $cnpj,
            'active'      => true,
            'ml_store_id' => $custId,
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

    /** Pre-popula cache /performance (gross) para cache hit de faturamento. */
    private function semearCacheGross(string $custId, float $value, string $marketplace = 'meli'): void
    {
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();
        $day      = now()->setTimezone(config('app.timezone'))->toDateString();
        $key      = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
        Cache::put($key, $value, now()->addHours(24));
    }

    /** Pre-popula cache /accounts/metrics — alimenta investment + acos + tacos + margem. */
    private function semearCacheAccount(string $custId, float $investment, float $tacos, string $marketplace = 'meli'): void
    {
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();
        $day      = now()->setTimezone(config('app.timezone'))->toDateString();
        $key      = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
        Cache::put($key, [
            'investment'        => $investment,
            'acos'              => 10.0,
            'tacos'             => $tacos,
            'percentage_margin' => 30.0,
        ], now()->addHours(24));
    }

    private function seedAdmanMetrics(Company $c, int $dias, float $revenuePorDia, float $adSpendPorDia): void
    {
        for ($i = 1; $i <= $dias; $i++) {
            AdmanMetric::create([
                'company_id'     => $c->id,
                'reference_date' => now()->subDays($i)->toDateString(),
                'revenue'        => $revenuePorDia,
                'ad_spend'       => $adSpendPorDia,
                'synced_at'      => now(),
            ]);
        }
    }

    /**
     * TEST 1 — Híbrido: A cache hit (invest=1000) + B cache miss (SUM DB=300) →
     * total_ad_investment_30d = R$ 1.300.
     *
     * Antes do Plano 03 (tudo-ou-nada): B em cache miss fazia ambos caírem em
     * fallback DB agregado — total = SUM(ad_spend) da base = R$ 300 (errado,
     * porque cache da A com R$ 1.000 era ignorado).
     * Depois do Plano 03: A (cache 1000) + B (DB 300) coexistem → R$ 1.300.
     */
    public function test_invest_ads_hibrido_mistura_cache_hit_e_db_fallback(): void
    {
        $admin = $this->admin();

        // A: cache hit completo. invest=1000, tacos=5%.
        $empresaA = $this->empresa('A Cache', '11000000000011', 'CUST_INV_A');
        $this->semearCacheGross('CUST_INV_A', 20000.0);
        $this->semearCacheAccount('CUST_INV_A', 1000.0, 5.0);

        // B: cache miss no account (sem semente). Tem 30 dias DB:
        // revenue 30×100=3000; ad_spend 30×10=300; tacos derivado = 10%.
        $empresaB = $this->empresa('B DB', '22000000000022', 'CUST_INV_B');
        $this->semearCacheGross('CUST_INV_B', 10000.0); // gross hit pra revenue
        $this->seedAdmanMetrics($empresaB, 30, 100.0, 10.0);

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            // 1000 (cache A) + 300 (SUM DB B) = 1300
            ->where('stats.total_ad_investment_30d', fn ($v) => (float) $v === 1300.0)
            // cards_exatos = false porque B caiu em cache miss no account
            ->where('cards_exatos', false)
        );
    }

    /**
     * TEST 2 — TACOS médio = média per-empresa, misturando cache hit + DB fallback.
     * A cache hit tacos=5%; B cache miss → calculado como (300/10000)*100 = 3%.
     * Média = (5+3)/2 = 4%.
     */
    public function test_tacos_medio_hibrido_mistura_per_empresa(): void
    {
        $admin = $this->admin();

        $empresaA = $this->empresa('A Cache', '33000000000033', 'CUST_TAC_A');
        $this->semearCacheGross('CUST_TAC_A', 20000.0);
        $this->semearCacheAccount('CUST_TAC_A', 1000.0, 5.0); // tacos cache = 5

        $empresaB = $this->empresa('B DB', '44000000000044', 'CUST_TAC_B');
        $this->semearCacheGross('CUST_TAC_B', 10000.0); // revenue cache 10000
        $this->seedAdmanMetrics($empresaB, 30, 0, 10.0); // ad_spend total = 300

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            // (5 + 3) / 2 = 4.0
            ->where('stats.avg_tacos', fn ($v) => (float) $v === 4.0)
        );
    }

    /**
     * TEST 3 — Todas as empresas com cache hit no account → cards_exatos = true
     * e total = soma dos investments do cache.
     */
    public function test_invest_e_tacos_todas_em_cache_hit_marca_exatos(): void
    {
        $admin = $this->admin();

        $this->empresa('A Full', '55000000000055', 'CUST_FULL_A');
        $this->semearCacheGross('CUST_FULL_A', 30000.0);
        $this->semearCacheAccount('CUST_FULL_A', 2500.0, 8.0);

        $this->empresa('B Full', '66000000000066', 'CUST_FULL_B');
        $this->semearCacheGross('CUST_FULL_B', 15000.0);
        $this->semearCacheAccount('CUST_FULL_B', 1500.0, 6.0);

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_ad_investment_30d', fn ($v) => (float) $v === 4000.0)  // 2500 + 1500
            ->where('stats.avg_tacos', fn ($v) => (float) $v === 7.0)                    // (8 + 6) / 2
            ->where('cards_exatos', true)
        );
    }

    /**
     * TEST 4 — Empresa em cache miss SEM revenue (nem DB nem cache gross): TACOS
     * dela fica null para não poluir o avg com 0% artificial. Investment ainda
     * conta a soma DB (mesmo que seja 0).
     *
     * Cenário: empresa Shopee recém-ativada sem nenhum dia de adman_metrics.
     */
    public function test_empresa_sem_revenue_nao_gera_tacos_zero_artificial(): void
    {
        $admin = $this->admin();

        // A: cache hit com tacos=10
        $this->empresa('A OK', '77000000000077', 'CUST_OK_A');
        $this->semearCacheGross('CUST_OK_A', 10000.0);
        $this->semearCacheAccount('CUST_OK_A', 500.0, 10.0);

        // B: cache miss em ambos + sem adman_metrics → revenue=0, ad_spend=0
        $this->empresa('B Vazia', '88000000000088', 'CUST_EMPTY_B');

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            // avg_tacos = média só da A (10.0) — B fica de fora porque revenue=0
            ->where('stats.avg_tacos', fn ($v) => (float) $v === 10.0)
        );
    }
}
