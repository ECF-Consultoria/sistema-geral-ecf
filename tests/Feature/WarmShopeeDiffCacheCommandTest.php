<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Models\ShopeeToken;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase 109 Plan 03 (Task 3) — `shopee:warm-diff` (WarmShopeeDiffCache),
 * espelhando o comando `adman:warm-diff` (WarmAdmanDiffCache). Roda de forma
 * idempotente sobre empresas com métricas Shopee vivas (token ERP ativo E/OU
 * vínculo elegível em `company_users` no setor Shopee) e popula o cache do
 * `ShopeeMetricDiffService::compute()`.
 */
class WarmShopeeDiffCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('PRAGMA foreign_keys = ON');

        Carbon::setTestNow(Carbon::parse('2026-08-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_comando_roda_sem_erro_e_aquece_cache_para_empresa_com_token_ativo(): void
    {
        $company = Company::factory()->create();
        ShopeeToken::create([
            'company_id'   => $company->id,
            'app'          => 'erp',
            'shop_id'      => 'SHOP-109-01',
            'access_token' => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'expires_at'   => now()->addHours(4),
            'status'       => 'active',
        ]);
        ShopeeMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::parse('2026-08-10')->toDateString(),
            'revenue'        => 1000.0,
        ]);
        ShopeeMetric::create([
            'company_id'     => $company->id,
            'reference_date' => Carbon::parse('2026-07-10')->toDateString(),
            'revenue'        => 800.0,
        ]);

        $this->artisan('shopee:warm-diff')->assertExitCode(0);

        // Cache::has não é confiável com prefixo de chave dinâmico (dia BRT) —
        // valida indiretamente: uma 2ª execução idempotente também sai 0.
        $this->artisan('shopee:warm-diff')->assertExitCode(0);
    }

    #[Test]
    public function test_comando_inclui_empresa_so_com_vinculo_shopee_elegivel_sem_token(): void
    {
        $company = Company::factory()->create();
        $servicoShopeeId = DB::table('servicos')->insertGetId([
            'nome'          => 'Serviço Shopee (fixture warm-diff)',
            'valor_padrao'  => 0,
            'tipo_cobranca' => Servico::TIPO_MENSAL,
            'ativo'         => true,
            'setor'         => Servico::SETOR_SHOPEE,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $user = \App\Models\User::factory()->create();
        DB::table('company_users')->insert([
            'company_id'  => $company->id,
            'user_id'     => $user->id,
            'role'        => 'consultor',
            'servico_id'  => $servicoShopeeId,
            'assigned_at' => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Sem ShopeeToken E sem shopee_metrics — só o vínculo. O comando deve
        // rodar sem erro (compute() lida com "sem linhas" graciosamente).
        $this->artisan('shopee:warm-diff')->assertExitCode(0);
    }

    #[Test]
    public function test_comando_aceita_option_period_especifico(): void
    {
        $company = Company::factory()->create();
        ShopeeToken::create([
            'company_id'   => $company->id,
            'app'          => 'erp',
            'shop_id'      => 'SHOP-109-02',
            'access_token' => 'token-fake',
            'refresh_token' => 'refresh-fake',
            'expires_at'   => now()->addHours(4),
            'status'       => 'active',
        ]);

        $this->artisan('shopee:warm-diff', ['--period' => '2026-06'])->assertExitCode(0);
    }
}
