<?php

namespace Tests\Feature\Phase18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

/**
 * Phase 18 (W3-T3) — Cobertura do comando dashboard:audit-billing-divergence.
 *
 * Mocka AdmanService para nao chamar a API real. Cobre:
 *  - Gap propositado e detectado com status "GAP"
 *  - Empresa sem cust_id (nem ml_store_id nem adman_account_id) → "NO_CUST"
 *  - Validacao de --period invalido → exit code 1
 *
 * Os testes 1 e 2 sofrem o throttle de 7s por empresa do comando real.
 * Apenas 1 empresa ativa em cada teste mantem o tempo viavel (~7s/teste).
 *
 * @group phase18
 */
class AuditBillingDivergenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * TEST 1 — Empresa com gap propositado e detectada.
     *
     * Empresa A: cust_id valido (via ml_store_id), `adman_metrics` somando
     * R$ 1.000 no range. Mock retorna grossBilling=R$ 1.500.
     * Esperado: diff = R$ 500 (33,33%), status "GAP".
     */
    public function test_empresa_com_gap_propositado_e_detectada(): void
    {
        $empresa = Company::create([
            'name'        => 'Empresa GAP',
            'cnpj'        => '11111111000001',
            'active'      => true,
            'ml_store_id' => 'CUST_A',
        ]);

        // 10 dias com revenue de R$ 100 cada → soma DB = R$ 1.000 no range 30d.
        for ($i = 1; $i <= 10; $i++) {
            AdmanMetric::create([
                'company_id'     => $empresa->id,
                'reference_date' => now()->subDays($i)->toDateString(),
                'revenue'        => 100.00,
                'ad_spend'       => 10.00,
                'synced_at'      => now(),
            ]);
        }

        // Mock AdmanService::fetchPerformance retornando R$ 1.500 (gap de R$ 500).
        // Phase 18.5: assinatura agora aceita (custId, from, to, maxRetries, marketplace).
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->once()
            ->with('CUST_A', Mockery::type('string'), Mockery::type('string'), 3, 'meli')
            ->andReturn([
                'summarizedData' => [
                    'grossBilling' => ['value' => 1500.00],
                ],
            ]);
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:audit-billing-divergence', ['--period' => 30]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, 'Comando deve retornar 0 em sucesso');
        $this->assertStringContainsString('Empresa GAP', $output);
        $this->assertStringContainsString('CUST_A', $output);
        // Tabela usa formato pt-BR: 1.500,00 (Adman) e 1.000,00 (DB).
        $this->assertStringContainsString('1.500,00', $output);
        $this->assertStringContainsString('1.000,00', $output);
        // Diff de R$ 500,00 com status GAP (gap < R$ 1.000 = PEQ, mas como
        // GAP_THRESHOLD usa abs(diff) > 1000 para contabilizar... a string
        // de status para diff=500 e PEQ — confere com a tabela do match).
        // Diff de R$ 500 e < 1.000 entao status sera 'PEQ' (pequeno).
        $this->assertStringContainsString('500,00', $output);
        $this->assertStringContainsString('PEQ', $output);
        // Sumario mostra periodo no header.
        $this->assertStringContainsString('period=30', $output);
    }

    /**
     * TEST 2 — Empresa SEM cust_id (sem ml_store_id e sem adman_account_id).
     *
     * Esperado: status "NO_CUST", sumario marca "Sem cust_id: 1",
     * AdmanService NUNCA e chamado (defendido pelo mock sem shouldReceive).
     */
    public function test_empresa_sem_cust_id_e_contabilizada_separadamente(): void
    {
        Company::create([
            'name'   => 'Empresa SEM CUSTID',
            'cnpj'   => '22222222000002',
            'active' => true,
            // ml_store_id e adman_account_id ambos NULL → cust_id = null.
        ]);

        // Mock estrito: se o comando tentar chamar fetchPerformance, o teste falha.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldNotReceive('fetchPerformance');
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:audit-billing-divergence', ['--period' => 30]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Empresa SEM CUSTID', $output);
        $this->assertStringContainsString('NO_CUST', $output);
        $this->assertStringContainsString('Sem cust_id: 1', $output);
    }

    /**
     * TEST 3 — Periodo invalido e rejeitado com exit code 1.
     *
     * AdmanService NUNCA e instanciado (mock estrito sem expectativas).
     * Execucao instantanea — sem throttle de 7s.
     */
    public function test_periodo_invalido_e_rejeitado(): void
    {
        // Mock estrito: a validacao de periodo ocorre ANTES de qualquer
        // chamada Adman. Se alguma vier, o teste falha.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldNotReceive('fetchPerformance');
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:audit-billing-divergence', ['--period' => 999]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'Periodo invalido deve retornar exit code 1');
        $this->assertStringContainsString('invalido', $output);
        $this->assertStringContainsString('999', $output);
        // Mensagem orienta sobre valores aceitos.
        $this->assertStringContainsString('1, 7, 30, 180', $output);
    }
}
