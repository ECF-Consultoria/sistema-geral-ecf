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
 * Phase 18 (W5-T6) — Cobertura do comando dashboard:mark-custid-status.
 *
 * Garante:
 *  1) Empresa com sync recente em adman_metrics ganha curto-circuito
 *     VALIDADO_OK e cust_id_status vira 'ok'.
 *  2) Empresa SUSPEITA com Adman retornando 500 vira 'invalido'.
 *  3) Empresa em ERRO_INDEFINIDO (Adman lanca 429) PRESERVA o status
 *     anterior — nao persiste categoria transitoria.
 *  4) Empresa sem cust_id (nem adman_account_id nem ml_store_id) vira
 *     'nao_aplicavel'.
 *
 * Importante: a coluna cust_id_status NUNCA muta cust_id, adman_account_id
 * ou ml_store_id. Cada teste verifica esses campos defensivamente.
 *
 * Throttle 7s nao roda em ambiente de testes (bypass via environment()).
 *
 * @group phase18
 */
class MarkCustIdStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * TEST 1 — Sync recente em adman_metrics → curto-circuito VALIDADO_OK.
     * cust_id_status deve virar 'ok' sem chamar a Adman.
     */
    public function test_empresa_com_sync_recente_vira_ok_sem_chamar_adman(): void
    {
        $empresa = Company::create([
            'name'             => 'Empresa OK Sync',
            'cnpj'             => '11111111000011',
            'active'           => true,
            'ml_store_id'      => '999888777',
            'adman_account_id' => 'ABC123XYZ',
            'cust_id_status'   => 'desconhecido',
        ]);

        // Sync recente (ontem) — curto-circuito VALIDADO_OK.
        AdmanMetric::create([
            'company_id'     => $empresa->id,
            'reference_date' => now()->subDay()->toDateString(),
            'revenue'        => 1500.50,
        ]);

        // Mock estrito: nenhuma chamada Adman esperada (curto-circuito).
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldNotReceive('fetchPerformance');
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:mark-custid-status');
        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        $this->assertSame('ok', $empresa->cust_id_status);

        // Defesa: IDs preservados (nunca podem mudar via este comando).
        $this->assertSame('999888777', $empresa->ml_store_id);
        $this->assertSame('ABC123XYZ', $empresa->adman_account_id);

        // Activity log gravado para a mudanca.
        $logs = $empresa->activities()->get();
        $this->assertTrue(
            $logs->contains(fn($a) => str_contains((string) $a->description, 'cust_id_status atualizado de')),
            'Activity log deveria ter sido gravado para mudanca de status'
        );
    }

    /**
     * TEST 2 — SUSPEITO_IGUAIS com Adman 500 → cust_id_status='invalido'.
     */
    public function test_suspeito_com_500_vira_invalido(): void
    {
        $custIdAmbiguo = '1234567890'; // 10 digitos — SUSPEITO_IGUAIS
        $empresa = Company::create([
            'name'             => 'Empresa Suspeita',
            'cnpj'             => '22222222000022',
            'active'           => true,
            'ml_store_id'      => $custIdAmbiguo,
            'adman_account_id' => $custIdAmbiguo,
            'cust_id_status'   => 'desconhecido',
        ]);

        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->once()
            ->with($custIdAmbiguo, Mockery::type('string'), Mockery::type('string'))
            ->andThrow(new \RuntimeException('Erro HTTP 500 - cliente nao encontrado'));
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:mark-custid-status');
        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        $this->assertSame('invalido', $empresa->cust_id_status);

        // IDs preservados.
        $this->assertSame($custIdAmbiguo, $empresa->ml_store_id);
        $this->assertSame($custIdAmbiguo, $empresa->adman_account_id);
    }

    /**
     * TEST 3 — ERRO_INDEFINIDO (Adman lanca 429 transitorio) → PRESERVA
     * status anterior. Nao persiste categoria transitoria nem grava
     * activity log (porque nao houve mudanca real).
     */
    public function test_erro_indefinido_preserva_status_anterior(): void
    {
        $custIdAmbiguo = '5555555555'; // 10 digitos — SUSPEITO_IGUAIS
        $empresa = Company::create([
            'name'             => 'Empresa Transitoria',
            'cnpj'             => '33333333000033',
            'active'           => true,
            'ml_store_id'      => $custIdAmbiguo,
            'adman_account_id' => $custIdAmbiguo,
            'cust_id_status'   => 'desconhecido',
        ]);

        // Adman responde 429 (rate limit) → ERRO_INDEFINIDO transitorio.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->once()
            ->andThrow(new \RuntimeException('Erro HTTP 429 - Too Many Requests'));
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:mark-custid-status');
        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        // Mantem desconhecido — nao persiste categoria transitoria.
        $this->assertSame('desconhecido', $empresa->cust_id_status);

        // Activity log NAO gravado (sem mudanca real).
        $logs = $empresa->activities()->get();
        $this->assertFalse(
            $logs->contains(fn($a) => str_contains((string) $a->description, 'cust_id_status atualizado de')),
            'Activity log NAO deveria ser gravado para ERRO_INDEFINIDO transitorio'
        );
    }

    /**
     * TEST 4 — Empresa sem cust_id → 'nao_aplicavel'.
     * Sem chamada Adman (decisao pelo shape).
     */
    public function test_empresa_sem_cust_id_vira_nao_aplicavel(): void
    {
        $empresa = Company::create([
            'name'             => 'Empresa Sem Cust',
            'cnpj'             => '44444444000044',
            'active'           => true,
            'ml_store_id'      => null,
            'adman_account_id' => null,
            'cust_id_status'   => 'desconhecido',
        ]);

        // Mock estrito: nenhuma chamada Adman esperada.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldNotReceive('fetchPerformance');
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:mark-custid-status');
        $this->assertSame(0, $exitCode);

        $empresa->refresh();
        $this->assertSame('nao_aplicavel', $empresa->cust_id_status);

        // IDs continuam null.
        $this->assertNull($empresa->ml_store_id);
        $this->assertNull($empresa->adman_account_id);
    }
}
