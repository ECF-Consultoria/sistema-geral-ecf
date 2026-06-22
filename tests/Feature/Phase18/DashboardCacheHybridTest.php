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
 * Phase 18 (W4-T4) — Cobertura da politica de cache hibrido per-empresa
 * introduzida em W4-T3 no DashboardController.
 *
 * Substitui a politica tudo-ou-nada: agora cada empresa decide cache vs DB
 * individualmente. Cobre:
 *  1) Mistura cache hit + cache miss no mesmo request → total = cache + DB,
 *     cards_exatos = false (porque alguma empresa caiu em fallback).
 *  2) Todas as empresas com cache hit → cards_exatos = true e total = soma
 *     dos caches.
 *  3) Empresa sem cust_id NAO conta no denominador de cards_exatos: 1 empresa
 *     com cache hit + 1 empresa sem cust_id ainda da cards_exatos = true.
 *
 * Para forjar cache hit usamos Cache::put diretamente na chave que o
 * AdmanService::getCachedGrossBillingsMany le. Estrutura da chave:
 *   "adman:gross_billing:{custId}:{dateFrom}:{dateTo}:{cacheDay}"
 *
 * @group phase18
 */
class DashboardCacheHybridTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function criarAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Empresa ativa COM cust_id via ml_store_id. Replica o padrao do
     * DashboardPeriodRangeTest pra ter cache lookup acontecendo.
     *
     * Hotfix Quick 260619 — Phase 37 Plan 37-06 fez /companies (e Dashboard
     * por extensao no quick 260619) filtrar empresas por contrato Performance
     * ativo. Helper agora anexa um contrato Performance default; testes que
     * focam em outros aspectos (cache hibrido, etc.) ficam isolados do filtro.
     */
    private function criarEmpresa(string $name, string $cnpj, string $custId): Company
    {
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

    /**
     * Pre-popula cache para o range 30d (alinhado com $period === '30'
     * default). Replica EXATAMENTE a chave do AdmanService.
     *
     * Phase 18.5: chave passou a incluir {marketplace} entre o prefixo e o
     * custId. Empresas criadas pelos testes usam o default 'meli'.
     */
    private function semearCacheGross(string $custId, float $value, string $marketplace = 'meli'): void
    {
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();
        $day      = now()->setTimezone(config('app.timezone'))->toDateString();

        $key = "adman:gross_billing:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
        Cache::put($key, $value, now()->addHours(24));
    }

    /**
     * Pre-popula tambem o account_metrics — necessario pra cards_exatos=true
     * (a flag exige accountCacheCompleto). Conteudo minimo: tacos/acos/margem.
     */
    private function semearCacheAccount(string $custId, float $investment = 100.0, string $marketplace = 'meli'): void
    {
        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();
        $day      = now()->setTimezone(config('app.timezone'))->toDateString();

        // Phase 18.5: chave passou a incluir {marketplace}.
        $key = "adman:account_metrics:{$marketplace}:{$custId}:{$dateFrom}:{$dateTo}:{$day}";
        Cache::put($key, [
            'investment'        => $investment,
            'acos'              => 10.0,
            'tacos'             => 5.0,
            'percentage_margin' => 30.0,
        ], now()->addHours(24));
    }

    /** Seeda revenue agregado em adman_metrics no range 30d para fallback DB. */
    private function seedAdmanMetrics(Company $company, int $dias, float $revenuePorDia): void
    {
        for ($i = 1; $i <= $dias; $i++) {
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
     * TEST 1 — Hibrido: A cache hit + B cache miss → total = R$ 15.000.
     *
     * Antes do W4-T3 (politica tudo-ou-nada): 1 miss zerava o cache do pool
     * inteiro → ambas as empresas iam pro fallback DB → A contribuia R$ 0
     * (sem metrics) + B contribuia R$ 5.000 → total = R$ 5.000 (errado).
     * Apos W4-T3: A (cache) + B (DB) coexistem → total = R$ 15.000.
     *
     * cards_exatos = false porque B caiu em fallback.
     */
    public function test_cache_hibrido_mistura_cache_hit_e_db_fallback(): void
    {
        $admin = $this->criarAdmin();

        // A: cache hit R$ 10.000 (sem metrics em adman_metrics — antes do
        // W4-T3 ia pra zero quando descartavamos o cache global).
        $this->criarEmpresa('Empresa A Cache', '77777777000007', 'CUST_HYBRID_A');
        $this->semearCacheGross('CUST_HYBRID_A', 10000.0);
        // account_metrics tambem para A — manter accountCacheCompleto coerente
        // com a expectativa de hit no gross.
        $this->semearCacheAccount('CUST_HYBRID_A');

        // B: cache miss (sem semente). Tem metrics no DB para fallback per-empresa.
        $empresaB = $this->criarEmpresa('Empresa B DB', '88888888000008', 'CUST_HYBRID_B');
        $this->seedAdmanMetrics($empresaB, 10, 500.0); // soma 30d = R$ 5.000

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Admin')
            ->where('period', '30')
            // 10.000 (cache A) + 5.000 (DB B) = 15.000.
            // JSON serializa numero redondo como int — comparacao numerica.
            ->where('stats.total_revenue', fn ($v) => (float) $v === 15000.0)
            // cards_exatos = false porque B caiu em cache miss
            ->where('cards_exatos', false)
        );
    }

    /**
     * TEST 2 — Todas com cache hit → cards_exatos = true, total = soma dos caches.
     */
    public function test_cache_hibrido_todas_em_cache_hit_marca_exatos(): void
    {
        $admin = $this->criarAdmin();

        $this->criarEmpresa('Empresa A Full Cache', '11111111000011', 'CUST_FULL_A');
        $this->semearCacheGross('CUST_FULL_A', 12345.67);
        $this->semearCacheAccount('CUST_FULL_A');

        $this->criarEmpresa('Empresa B Full Cache', '22222222000022', 'CUST_FULL_B');
        $this->semearCacheGross('CUST_FULL_B', 7654.33);
        $this->semearCacheAccount('CUST_FULL_B');

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            // 12345.67 + 7654.33 = 20000.0 (redondo, JSON pode serializar como int).
            ->where('stats.total_revenue', fn ($v) => (float) $v === 20000.0)
            ->where('cards_exatos', true)
        );
    }

    /**
     * TEST 3 — Regressao guard: empresa sem cust_id NAO conta no denominador.
     * 1 empresa cache hit + 1 empresa sem cust_id → cards_exatos = true
     * (porque a unica empresa com cust_id teve hit).
     *
     * A empresa sem cust_id contribui R$ 0 no total (sem metrics no DB).
     * Eh exatamente o cenario "empresa cadastrada incompleta" que nao
     * deveria poluir a medida.
     */
    public function test_empresa_sem_cust_id_nao_afeta_cards_exatos(): void
    {
        $admin = $this->criarAdmin();

        $this->criarEmpresa('Empresa Cache OK', '33333333000033', 'CUST_GOOD');
        $this->semearCacheGross('CUST_GOOD', 8000.0);
        $this->semearCacheAccount('CUST_GOOD');

        // Empresa SEM cust_id (sem ml_store_id e sem adman_account_id).
        // Quick 260619 — anexa contrato Performance pro filtro Dashboard aceitar.
        $semCustid = Company::create([
            'name'   => 'Empresa Sem Custid',
            'cnpj'   => '44444444000044',
            'active' => true,
        ]);
        $svc = \App\Models\Servico::create([
            'nome'          => 'Gestao SemCustid ' . uniqid(),
            'valor_padrao'  => 1000,
            'tipo_cobranca' => \App\Models\Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => \App\Models\Servico::SETOR_PERFORMANCE,
        ]);
        \App\Models\ContratoServico::create([
            'company_id'       => $semCustid->id,
            'servico_id'       => $svc->id,
            'valor_contratado' => 1000,
            'data_contratacao' => now()->toDateString(),
            'ativo'            => true,
        ]);

        $response = $this->actingAs($admin)->get('/dashboard?period=30');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            // 8000 (cache hit) + 0 (sem metrics da empresa sem cust_id) = 8000.
            // JSON pode serializar redondo como int — comparacao numerica.
            ->where('stats.total_revenue', fn ($v) => (float) $v === 8000.0)
            // cards_exatos = true: empresa sem cust_id NAO entra no denominador
            ->where('cards_exatos', true)
        );
    }
}
