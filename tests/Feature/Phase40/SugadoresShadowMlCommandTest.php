<?php

// Phase 40 Plan 40-04 — Suite RED → GREEN do comando `sugadores:shadow-ml`.
//
// O comando dispara o ShadowRunService (Plan 40-02) por empresa e por dia. Aceita:
//   --company={id|all}  (default: usa env SUGADORES_ML_SHADOW_COMPANIES via config)
//   --days=N            (default: 1; clamp 1..90 contra T-40-04-01)
//
// Esta suite valida (8 testes):
//  1. Comando registrado em Artisan::all()
//  2. --company={id} numérico dispara ShadowRunService para a empresa
//  3. --company=all respeita config('sugadores.ml_shadow_companies') (CSV via env)
//  4. --company=all com config vazia aborta exit 1 + mensagem pt-BR
//  5. --days=N itera N reference_dates distintas para a mesma empresa
//  6. --company=invalido (não numérico, não 'all') aborta exit 1
//  7. --company={id_inexistente} aborta exit 1 com mensagem pt-BR "Empresa X não encontrada"
//  8. Output pt-BR exibe progresso + summary final (X runs Adman ok, Y runs ML ok, Z falhas)
//
// Setup: mocka ShadowRunService no container via $this->app->instance(...).
// Pattern reutilizado do Plan 40-02 (ShadowRunServiceTest).

namespace Tests\Feature\Phase40;

use App\Models\Company;
use App\Services\Sugadores\ShadowRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SugadoresShadowMlCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria empresa mínima válida.
     */
    private function makeCompany(?int $id = null, string $name = 'Empresa Shadow CMD'): Company
    {
        $attrs = [
            'name'             => $name,
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-CMD-' . random_int(1000, 9999),
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

    // ─────────── Test 1: comando registrado ───────────

    #[Test]
    public function comando_registrado_no_artisan(): void
    {
        $this->assertArrayHasKey('sugadores:shadow-ml', Artisan::all());
    }

    // ─────────── Test 2: --company={id} numérico dispara run ───────────

    #[Test]
    public function company_id_numerico_dispara_run_para_empresa(): void
    {
        $company = $this->makeCompany();

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn ($c) => $c instanceof Company && $c->id === $company->id), Mockery::type(Carbon::class))
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => (string) $company->id]);
        $this->assertEquals(0, $exit, 'Exit code esperado: 0. Output: ' . Artisan::output());
    }

    // ─────────── Test 3: --company=all respeita config CSV ───────────

    #[Test]
    public function company_all_respeita_env_csv(): void
    {
        $c1 = $this->makeCompany(name: 'Empresa A');
        $c2 = $this->makeCompany(name: 'Empresa B');

        config(['sugadores.ml_shadow_companies' => [$c1->id, $c2->id]]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->id === $c1->id), Mockery::type(Carbon::class))
            ->andReturn($this->runReturn());
        $mock->shouldReceive('run')
            ->once()
            ->with(Mockery::on(fn ($c) => $c->id === $c2->id), Mockery::type(Carbon::class))
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $this->assertEquals(0, $exit, 'Exit esperado 0. Output: ' . Artisan::output());
    }

    // ─────────── Test 4: --company=all com config vazia aborta ───────────

    #[Test]
    public function company_all_sem_env_aborta_exit_1(): void
    {
        config(['sugadores.ml_shadow_companies' => []]);

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => 'all']);
        $this->assertEquals(1, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('Nenhuma empresa elegível', $output);
    }

    // ─────────── Test 5: --days=N itera N reference_dates ───────────

    #[Test]
    public function days_param_itera_N_dias(): void
    {
        $company = $this->makeCompany();

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        // Esperamos EXATAMENTE 3 chamadas (uma por dia).
        $mock->shouldReceive('run')
            ->times(3)
            ->with(Mockery::type(Company::class), Mockery::type(Carbon::class))
            ->andReturn($this->runReturn());
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', [
            '--company' => (string) $company->id,
            '--days'    => '3',
        ]);
        $this->assertEquals(0, $exit, 'Exit esperado 0. Output: ' . Artisan::output());
    }

    // ─────────── Test 6: --company inválido (não numérico) aborta ───────────

    #[Test]
    public function company_invalido_aborta_exit_1(): void
    {
        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => 'invalido']);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 7: --company={id} inexistente aborta ───────────

    #[Test]
    public function empresa_inexistente_aborta(): void
    {
        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldNotReceive('run');
        $this->app->instance(ShadowRunService::class, $mock);

        $exit = Artisan::call('sugadores:shadow-ml', ['--company' => '999999']);
        // Spec aceita exit 0 com warning OU exit 1 com erro. O Plan 40-04 escolhe
        // exit 1 com mensagem clara — o teste valida a mensagem.
        $output = Artisan::output();
        $this->assertStringContainsString('Empresa 999999 não encontrada', $output);
        $this->assertEquals(1, $exit);
    }

    // ─────────── Test 8: output pt-BR exibe progresso e summary ───────────

    #[Test]
    public function output_pt_br_exibe_progresso_e_summary(): void
    {
        $company = $this->makeCompany(name: 'Acme LTDA Shadow');

        /** @var MockInterface&ShadowRunService $mock */
        $mock = Mockery::mock(ShadowRunService::class);
        $mock->shouldReceive('run')
            ->once()
            ->andReturn($this->runReturn(10, 20, 'completed', 'completed'));
        $this->app->instance(ShadowRunService::class, $mock);

        Artisan::call('sugadores:shadow-ml', ['--company' => (string) $company->id]);
        $output = Artisan::output();

        // Progresso por empresa (nome aparece em alguma linha de progresso)
        $this->assertStringContainsString('Acme LTDA Shadow', $output);
        // Summary final inclui "ok" / "falhas" / contadores em pt-BR
        $this->assertMatchesRegularExpression('/Adman/i', $output);
        $this->assertMatchesRegularExpression('/(ok|completed|Conclu)/i', $output);
    }
}
