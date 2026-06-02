<?php

namespace Tests\Feature\Phase18;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\MlToken;
use App\Services\AdmanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

/**
 * Phase 18 (W4-T4) — Cobertura do comando dashboard:diagnose-cust-id.
 *
 * Garante:
 *  1) Empresa com adman_account_id distinto do ml_store_id e fora do formato
 *     "10 digitos" classifica como OK (sem chamada Adman).
 *  2) Empresa com adman_account_id == ml_store_id (10 digitos) cai em
 *     SUSPEITO_IGUAIS, sobe pra INVALIDO_CONFIRMADO quando Adman retorna 500.
 *  3) --fix limpa SOMENTE empresas INVALIDO_CONFIRMADO com mlToken ativo;
 *     preserva ml_store_id e nao toca empresas sem fallback ML.
 *  4) Empresa SUSPEITO_FORMATO com fetchPerformance OK vira VALIDADO_API
 *     (falso positivo) e --fix NAO atualiza essa empresa.
 *
 * Importante: mockamos AdmanService via $this->app->instance para evitar
 * chamadas reais. O throttle de 7s do comando roda no teste mas com no maximo
 * 1-2 chamadas validas, fica em ~14s acumulados nos testes 2-4.
 *
 * @group phase18
 */
class DiagnoseCustIdTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Garante mlToken vinculado e ativo para a empresa indicada (cobre
     * is_ml_driven = true). Phase 18 W4-T1 usa esse criterio para liberar
     * empresas seguras pro --fix.
     */
    private function vincularMlTokenAtivo(Company $company): void
    {
        MlToken::create([
            'company_id'    => $company->id,
            'ml_user_id'    => '999',
            'access_token'  => 'tok_dummy',
            'refresh_token' => 'refresh_dummy',
            'token_type'    => 'bearer',
            'expires_at'    => now()->addHours(6),
            'status'        => 'active',
        ]);
    }

    /**
     * TEST 1 — adman_account_id distinto e sem formato suspeito → OK.
     *
     * 'ABC123XYZ' nao tem 10 digitos numericos e e diferente do ml_store_id.
     * AdmanService NAO deve ser chamado (mock estrito sem expectativa).
     */
    public function test_empresa_ok_nao_consome_chamada_adman(): void
    {
        Company::create([
            'name'             => 'Empresa OK',
            'cnpj'             => '11111111000001',
            'active'           => true,
            'ml_store_id'      => '999888777',
            'adman_account_id' => 'ABC123XYZ',
        ]);

        // Mock estrito: nenhuma chamada Adman esperada.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldNotReceive('fetchPerformance');
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:diagnose-cust-id');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Empresa OK', $output);
        // Categoria OK e contagem no sumario.
        $this->assertStringContainsString('OK:', $output);
        // Sumario mostra "Chamadas Adman feitas (validacao): 0" — empresa OK
        // nao deveria gerar chamada.
        $this->assertStringContainsString('Chamadas Adman feitas (validacao): 0', $output);
    }

    /**
     * TEST 2 — SUSPEITO_IGUAIS (adman == ml, 10 digitos) → INVALIDO_CONFIRMADO
     * quando Adman responde 500.
     */
    public function test_suspeito_iguais_com_500_vira_invalido_confirmado(): void
    {
        $custIdAmbiguo = '1234567890'; // 10 digitos
        Company::create([
            'name'             => 'Empresa SUSPEITA',
            'cnpj'             => '22222222000002',
            'active'           => true,
            'ml_store_id'      => $custIdAmbiguo,
            'adman_account_id' => $custIdAmbiguo,
        ]);

        // Mock simula erro 500 do Adman (cliente desconhecido).
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->once()
            // Phase 18.5: assinatura passou a aceitar (..., maxRetries, marketplace).
            ->with($custIdAmbiguo, Mockery::type('string'), Mockery::type('string'), 3, 'meli')
            ->andThrow(new \RuntimeException('Erro HTTP 500 - cliente nao encontrado'));
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:diagnose-cust-id');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Empresa SUSPEITA', $output);
        $this->assertStringContainsString('INVALIDO_CONFIRMADO', $output);
        // Sumario contabilizou 1 chamada Adman.
        $this->assertStringContainsString('Chamadas Adman feitas (validacao): 1', $output);
    }

    /**
     * TEST 3 — --fix seletivo: limpa apenas INVALIDO_CONFIRMADO + ml ativo;
     * preserva empresa sem fallback ML.
     */
    public function test_fix_so_limpa_invalido_confirmado_com_ml_ativo(): void
    {
        // Empresa A: INVALIDO_CONFIRMADO com ml ativo → ELEGIVEL para --fix.
        $empresaComMl = Company::create([
            'name'             => 'Empresa Com ML',
            'cnpj'             => '33333333000003',
            'active'           => true,
            'ml_store_id'      => '5555555555',
            'adman_account_id' => '5555555555', // SUSPEITO_IGUAIS inicial
        ]);
        $this->vincularMlTokenAtivo($empresaComMl);

        // Empresa B: INVALIDO_CONFIRMADO SEM ml ativo → NAO ELEGIVEL.
        $empresaSemMl = Company::create([
            'name'             => 'Empresa Sem ML',
            'cnpj'             => '44444444000004',
            'active'           => true,
            'ml_store_id'      => '6666666666',
            'adman_account_id' => '6666666666', // SUSPEITO_IGUAIS inicial
        ]);
        // Sem MlToken vinculado → is_ml_driven = false.

        // Ambas geram 500 na Adman → INVALIDO_CONFIRMADO.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->twice() // 1 por empresa
            ->andThrow(new \RuntimeException('Erro HTTP 500'));
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:diagnose-cust-id', ['--fix' => true]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);

        // Empresa A teve adman_account_id zerado; ml_store_id preservado.
        $empresaComMl->refresh();
        $this->assertNull($empresaComMl->adman_account_id, 'Empresa com ML ativo deve ter adman_account_id limpo pelo --fix');
        $this->assertSame('5555555555', $empresaComMl->ml_store_id, 'ml_store_id deve ser preservado');

        // Empresa B NAO foi tocada — adman_account_id intacto.
        $empresaSemMl->refresh();
        $this->assertSame('6666666666', $empresaSemMl->adman_account_id, 'Empresa SEM ML nao pode ser modificada pelo --fix');

        // Output mostra contagem de atualizacoes = 1.
        $this->assertStringContainsString('--fix concluido: 1 empresa(s) atualizada(s)', $output);

        // Activity log gravado para a empresa modificada.
        $logsEmpresaA = $empresaComMl->activities()->get();
        $this->assertTrue(
            $logsEmpresaA->contains(fn($a) => str_contains((string) $a->description, 'adman_account_id corrompido removido')),
            'Activity log deveria ter sido gravado para a empresa modificada'
        );

        // Empresa B sem activity log do fix.
        $logsEmpresaB = $empresaSemMl->activities()->get();
        $this->assertFalse(
            $logsEmpresaB->contains(fn($a) => str_contains((string) $a->description, 'adman_account_id corrompido removido')),
            'Empresa sem ML nao pode ter activity log do --fix'
        );
    }

    /**
     * TEST 4 — SUSPEITO_FORMATO com fetchPerformance OK → VALIDADO_API.
     * --fix NAO deve mexer nessa empresa (falso positivo).
     */
    public function test_suspeito_formato_validado_api_nao_e_modificado_pelo_fix(): void
    {
        $custIdFormatoMl = '7890123456'; // 10 digitos
        $empresa = Company::create([
            'name'             => 'Empresa Formato',
            'cnpj'             => '55555555000005',
            'active'           => true,
            'ml_store_id'      => null,
            'adman_account_id' => $custIdFormatoMl, // SUSPEITO_FORMATO
        ]);
        // Mesmo sem ml_store_id; vinculamos um mlToken ativo para passar
        // o filtro hipotetico (mas como o status final sera VALIDADO_API,
        // o --fix nao deve tocar a empresa de qualquer forma).
        $this->vincularMlTokenAtivo($empresa);

        // Adman retorna dados validos → VALIDADO_API.
        $mock = Mockery::mock(AdmanService::class);
        $mock->shouldReceive('fetchPerformance')
            ->once()
            // Phase 18.5: assinatura passou a aceitar (..., maxRetries, marketplace).
            ->with($custIdFormatoMl, Mockery::type('string'), Mockery::type('string'), 3, 'meli')
            ->andReturn([
                'summarizedData' => [
                    'grossBilling' => ['value' => 1000.0],
                ],
            ]);
        $this->app->instance(AdmanService::class, $mock);

        $exitCode = Artisan::call('dashboard:diagnose-cust-id', ['--fix' => true]);
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('VALIDADO_API', $output);

        // --fix nao mexeu — adman_account_id permanece intacto.
        $empresa->refresh();
        $this->assertSame($custIdFormatoMl, $empresa->adman_account_id, 'VALIDADO_API nao pode ser modificado pelo --fix');

        // Output confirma que --fix nao encontrou candidata segura.
        $this->assertStringContainsString('nenhuma empresa candidata segura', $output);
    }
}
