<?php

// Phase 41 Plan 41-03 — Suite RED → GREEN do refactor `sugadores:shadow-ml`
// priorizando a tabela `sugador_ml_company_config` (Plan 41-01) sobre o env
// CSV `SUGADORES_ML_SHADOW_COMPANIES` da Phase 40-04.
//
// Esta suite valida (5 testes):
//  1. DB com 2 rows shadow_enabled=true sobrescreve env CSV (env IGNORADO)
//  2. DB vazio → fallback env CSV continua funcional (back-compat Phase 40-04)
//  3. DB vazio + env vazio → aborta exit 1 com mensagem citando AMBAS as fontes
//  4. --company={id} numerico ignora ambos (DB + env) e dispara apenas pra empresa
//  5. DB com row shadow_enabled=FALSE não conta — empresa só entra se shadow_enabled=true
//
// Setup: mocka ShadowRunService no container via $this->app->instance(...).
// Pattern reutilizado do Plan 40-04 (SugadoresShadowMlCommandTest).
//
// Estado esperado RED (antes do refactor):
//   - Test 1 FALHA: resolveCompanies('all') le env (sem DB), tenta empresa
//     999999 inexistente, aborta exit 1 (esperado exit 0 com 2 calls no mock).
//   - Test 2 PASSA por acaso (fallback env ja eh o comportamento atual).
//   - Test 3 FALHA: mensagem atual NAO cita 'sugador_ml_company_config'.
//   - Test 4 PASSA (--company={id} numerico ja ignora env/DB hoje).
//   - Test 5 PASSA por acaso (DB vazio do ponto de vista do command atual).
//
// Após o GREEN (Tarefa 2), todos os 5 testes passam.

namespace Tests\Feature\Phase41;

use App\Models\Company;
use App\Models\SugadorMlCompanyConfig;
use App\Services\Sugadores\ShadowRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SugadoresShadowMlCommandConfigTableTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─────────── Helpers (pattern copiado do Plan 40-04) ───────────

    /**
     * Cria empresa mínima válida.
     */
    private function makeCompany(?int $id = null, string $name = 'Empresa Phase41 Cmd'): Company
    {
        $attrs = [
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-P41-' . random_int(1000, 9999),
            'active'           => true,
        ];
        if ($id !== null) {
            $attrs['id'] = $id;
        }
        return Company::create($attrs);
    }

    /**
     * Retorno padrão do ShadowRunService::run para mocks.
     */
    private function runReturn(int $admanRunId = 1, int $mlRunId = 2, string $admanStatus = 'completed', string $mlStatus = 'completed'): array
    {
        return [
            'adman_run_id' => $admanRunId,
            'ml_run_id'    => $mlRunId,
            'adman_status' => $admanStatus,
            'ml_status'    => $mlStatus,
        ];
    }

    /**
     * Habilita shadow_enabled=true para a empresa via SugadorMlCompanyConfig.
     * Cria a row se não existir (updateOrCreate por company_id).
     */
    private function enableShadowFor(Company $company): void
    {
        SugadorMlCompanyConfig::updateOrCreate(
            ['company_id' => $company->id],
            [
                'shadow_enabled'    => true,
                'shadow_started_at' => now(),
                'primary_enabled'   => false,
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 1 — DB com 2 rows shadow_enabled=true sobrescreve env CSV
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function db_com_shadow_enabled_prioriza_sobre_env(): void
    {
        // 2 empresas reais com shadow_enabled=true no DB
        $c1 = $this->makeCompany(name: 'Empresa A DB');
        $c2 = $this->makeCompany(name: 'Empresa B DB');
        $this->enableShadowFor($c1);
        $this->enableShadowFor($c2);

        // env aponta pra empresa INEXISTENTE — se o env fosse consultado o
        // comando abortaria com "Empresa 999999 não encontrada".
        config(['sugadores.ml_shadow_companies' => [999999]]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        // Esperamos EXATAMENTE 2 chamadas (uma por empresa do DB, env ignorado).
        $mock->shouldReceive('run')
            ->twice()
            ->with(
                Mockery::on(fn ($c) => $c instanceof Company && in_array($c->id, [$c1->id, $c2->id], true)),
                Mockery::type(Carbon::class)
            )
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit   = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $output = Artisan::output();

        $this->assertEquals(0, $exit, 'Exit esperado: 0 (DB tem 2 empresas válidas). Output: ' . $output);
        // Env IGNORADO — empresa 999999 nem foi consultada (sem mensagem de erro).
        $this->assertStringNotContainsString('Empresa 999999 não encontrada', $output);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 2 — DB vazio cai em env CSV (back-compat Phase 40-04)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function db_vazio_cai_em_env_csv(): void
    {
        // Nenhum SugadorMlCompanyConfig criado — DB está vazio.
        $company = $this->makeCompany(name: 'Empresa Env Fallback');

        // env aponta pra empresa real — fallback deve funcionar.
        config(['sugadores.ml_shadow_companies' => [$company->id]]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn ($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::type(Carbon::class)
            )
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $this->assertEquals(0, $exit, 'Exit esperado: 0 (fallback env). Output: ' . Artisan::output());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 3 — DB vazio + env vazio aborta com mensagem citando ambas as fontes
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function db_e_env_vazios_aborta_com_mensagem_atualizada(): void
    {
        // Nenhum SugadorMlCompanyConfig + env vazio
        config(['sugadores.ml_shadow_companies' => []]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(ShadowRunService::class, $mock);

        $exit   = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $output = Artisan::output();

        $this->assertEquals(1, $exit);
        // Mensagem nova precisa citar AMBAS as fontes (tabela DB + env CSV).
        $this->assertStringContainsString('sugador_ml_company_config', $output);
        $this->assertStringContainsString('SUGADORES_ML_SHADOW_COMPANIES', $output);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 4 — --company={id} numérico ignora DB e env, roda só pra empresa
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function company_id_numerico_ignora_db_e_env(): void
    {
        // Setup armadilha: DB tem A em shadow, env aponta B; alvo é C (3ª empresa).
        $a = $this->makeCompany(name: 'Empresa A (DB)');
        $b = $this->makeCompany(name: 'Empresa B (env)');
        $c = $this->makeCompany(name: 'Empresa C (alvo)');

        $this->enableShadowFor($a);
        config(['sugadores.ml_shadow_companies' => [$b->id]]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        // Exatamente 1 chamada, e tem que ser pra C (não A nem B).
        $mock->shouldReceive('run')
            ->once()
            ->with(
                Mockery::on(fn ($empresa) => $empresa instanceof Company && $empresa->id === $c->id),
                Mockery::type(Carbon::class)
            )
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => (string) $c->id]);
        $this->assertEquals(0, $exit, 'Exit esperado: 0. Output: ' . Artisan::output());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Test 5 — Row com shadow_enabled=FALSE não conta (precisa ser true)
    // ──────────────────────────────────────────────────────────────────────

    #[Test]
    public function db_com_shadow_disabled_nao_entra(): void
    {
        // Empresa tem row no SugadorMlCompanyConfig mas com shadow_enabled=false.
        $company = $this->makeCompany(name: 'Empresa Shadow Disabled');
        SugadorMlCompanyConfig::create([
            'company_id'      => $company->id,
            'shadow_enabled'  => false,
            'primary_enabled' => false,
        ]);

        // env CSV também vazio — deve cair no abort.
        config(['sugadores.ml_shadow_companies' => []]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(ShadowRunService::class, $mock);

        $exit   = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $output = Artisan::output();

        $this->assertEquals(1, $exit, 'Exit esperado: 1 (DB sem shadow_enabled=true e env vazio).');
        $this->assertStringContainsString('Nenhuma empresa elegível', $output);
    }
}
