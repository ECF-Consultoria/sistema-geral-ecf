<?php

// Phase 39 Plan 39-05 — Testes Feature do comando `sugadores:analyze` estendido com
// `--provider={adman|ml}` + guard que aborta `--provider=ml` sem `--dry-run`.
//
// Cobre exclusivamente as EXTENSÕES introduzidas pelo Plan 39-05:
//  - Nova flag --provider e validação de valores (adman|ml)
//  - Guard que aborta exec ml_primary antes da Phase 42 (--provider=ml sem --dry-run)
//  - Propagação de $forceProvider para SugadorAnalysisService::analyzeCompany
//  - Preservação do comportamento --dry-run (não grava em sugadores)
//  - Preservação do comportamento default (sem flags → grava normal via path Adman)
//
// A lógica interna de detecção (evaluateMetrics, STATUS_TRAVADOS, etc.) continua
// coberta pelas suites legadas Tests\Feature\Sugadores\*. Esta suite NÃO duplica.

namespace Tests\Feature\Phase39;

use App\Models\Company;
use App\Models\Sugador;
use App\Models\SugadorConfig;
use App\Services\AdmanService;
use App\Services\SugadorAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use Tests\TestCase;

class AnalyzeSugadoresCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Cria empresa persistida com config ativa (incluir_anuncios=true,
     * incluir_campanhas=false para reduzir noise nas chamadas mock).
     */
    private function makeCompanyWithConfig(array $companyAttrs = [], array $configAttrs = []): Company
    {
        $defaults = [
            'name'             => 'Empresa Command 39-05',
            'cnpj'             => str_pad((string) random_int(1, 99999999999999), 14, '0', STR_PAD_LEFT),
            'adman_account_id' => 'ACC-CMD-39-05',
            'active'           => true,
        ];
        $company = Company::create(array_merge($defaults, $companyAttrs));

        SugadorConfig::create(array_merge([
            'company_id'                      => $company->id,
            'dias_analise'                    => 30,
            'gasto_minimo_sem_venda'          => 20.00,
            'gasto_minimo_logic'              => SugadorConfig::LOGIC_OPTIONAL,
            'pct_anuncios_para_flag_campanha' => 50,
            'incluir_campanhas'               => false,
            'incluir_anuncios'                => true,
            'ativo'                           => true,
        ], $configAttrs));

        return $company;
    }

    /**
     * Mocka AdmanService para devolver 1 adgroup que bate gasto_sem_venda,
     * permitindo que o pipeline real (sem dry-run) grave 1 row em sugadores.
     */
    private function bindAdmanMockWithOneSugadorAd(): MockInterface
    {
        /** @var MockInterface&AdmanService $admanMock */
        $admanMock = Mockery::mock(AdmanService::class);
        $admanMock->shouldReceive('fetchAllCampaigns')->andReturn([])->byDefault();
        $admanMock->shouldReceive('fetchAdsMetrics')->andReturn([
            [
                'adgroup_id'      => 'AG-CMD-1',
                'adgroup_name'    => 'Adgroup command test',
                'campaign_id'     => 'CP-CMD-1',
                'thumbnail'       => null,
                'adgroup_type'    => null,
                'catalog_listing' => false,
                'investment'      => 100.00, // > gasto_minimo_sem_venda 20.00
                'revenue'         => 0,
                'sold_quantity'   => 0,
                'clicks'          => 10,
                'impressions'     => 100,
                'cpc'             => 10.0,
                'ctr'             => 10.0,
                'acos'            => null,
                'roas'            => null,
                'organic_amount'  => null,
                'organic_units'   => null,
                'raw'             => ['raw' => true],
            ],
        ])->byDefault();
        $admanMock->shouldReceive('fetchCampaignsRange')->andReturn([])->byDefault();
        $this->app->instance(AdmanService::class, $admanMock);

        return $admanMock;
    }

    // ─────────── Test 1: --provider=ml sem --dry-run aborta com exit 1 ───────────

    public function test_command_with_provider_ml_without_dryrun_aborts_with_exit_1(): void
    {
        $company = $this->makeCompanyWithConfig();

        $this->artisan('sugadores:analyze', [
            '--company'  => $company->id,
            '--provider' => 'ml',
        ])
            ->expectsOutputToContain('Modo ml_primary só disponível em Phase 42')
            ->assertExitCode(1);
    }

    // ─────────── Test 2: --provider=ml + --dry-run propaga 'ml' para analyzeCompany ───────────

    public function test_command_with_provider_ml_and_dryrun_passes_force_provider_to_service(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $serviceMock */
        $serviceMock = Mockery::mock(SugadorAnalysisService::class);
        $serviceMock->shouldReceive('analyzeCompany')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::any(),
                true,    // $dryRun
                'ml'     // $forceProvider
            )
            ->andReturn([
                'skipped'   => false,
                'reason'    => null,
                'campanhas' => 0,
                'adgroups'  => 0,
                'detalhes'  => [],
            ]);
        $this->app->instance(SugadorAnalysisService::class, $serviceMock);

        $this->artisan('sugadores:analyze', [
            '--company'  => $company->id,
            '--provider' => 'ml',
            '--dry-run'  => true,
        ])->assertExitCode(0);
    }

    // ─────────── Test 3: --provider=adman + --dry-run propaga 'adman' para analyzeCompany ───────────

    public function test_command_with_provider_adman_and_dryrun_passes_force_provider_to_service(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $serviceMock */
        $serviceMock = Mockery::mock(SugadorAnalysisService::class);
        $serviceMock->shouldReceive('analyzeCompany')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::any(),
                true,
                'adman'
            )
            ->andReturn([
                'skipped'   => false,
                'reason'    => null,
                'campanhas' => 0,
                'adgroups'  => 0,
                'detalhes'  => [],
            ]);
        $this->app->instance(SugadorAnalysisService::class, $serviceMock);

        $this->artisan('sugadores:analyze', [
            '--company'  => $company->id,
            '--provider' => 'adman',
            '--dry-run'  => true,
        ])->assertExitCode(0);
    }

    // ─────────── Test 4: sem --provider, analyzeCompany recebe null ───────────

    public function test_command_without_provider_passes_null_force_provider(): void
    {
        $company = $this->makeCompanyWithConfig();

        /** @var MockInterface&SugadorAnalysisService $serviceMock */
        $serviceMock = Mockery::mock(SugadorAnalysisService::class);
        $serviceMock->shouldReceive('analyzeCompany')
            ->once()
            ->with(
                Mockery::on(fn($c) => $c instanceof Company && $c->id === $company->id),
                Mockery::any(),
                true,
                null   // $forceProvider default
            )
            ->andReturn([
                'skipped'   => false,
                'reason'    => null,
                'campanhas' => 0,
                'adgroups'  => 0,
                'detalhes'  => [],
            ]);
        $this->app->instance(SugadorAnalysisService::class, $serviceMock);

        $this->artisan('sugadores:analyze', [
            '--company' => $company->id,
            '--dry-run' => true,
        ])->assertExitCode(0);
    }

    // ─────────── Test 5: --dry-run não grava em sugadores ───────────

    public function test_command_dryrun_does_not_write_to_sugadores_table(): void
    {
        $company = $this->makeCompanyWithConfig();
        $this->bindAdmanMockWithOneSugadorAd();

        $countBefore = Sugador::count();

        $this->artisan('sugadores:analyze', [
            '--company' => $company->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $countAfter = Sugador::count();
        $this->assertSame(
            $countBefore,
            $countAfter,
            '--dry-run NÃO deve gravar em sugadores (pré=' . $countBefore . ', pós=' . $countAfter . ')'
        );
    }

    // ─────────── Test 6: sem --dry-run grava em sugadores (preserva comportamento prod) ───────────

    public function test_command_without_dryrun_writes_to_sugadores_table(): void
    {
        $company = $this->makeCompanyWithConfig();
        $this->bindAdmanMockWithOneSugadorAd();

        $countBefore = Sugador::count();

        $this->artisan('sugadores:analyze', [
            '--company' => $company->id,
        ])->assertExitCode(0);

        $countAfter = Sugador::count();
        $this->assertSame(
            $countBefore + 1,
            $countAfter,
            'Sem --dry-run deve gravar 1 sugador via path Adman default (pré=' . $countBefore . ', pós=' . $countAfter . ')'
        );
    }

    // ─────────── Test 7: --provider=valor_invalido aborta com mensagem clara ───────────

    public function test_command_with_invalid_provider_value_rejects_with_clear_message(): void
    {
        $company = $this->makeCompanyWithConfig();

        // Captura output bruto via Kernel para validar a linha INTEIRA da
        // mensagem (que inclui "Provider inválido: 'invalid'. Valores
        // aceitos: adman, ml"). expectsOutputToContain do PendingCommand do
        // Laravel não casa bem strings curtas duplicadas no mesmo error line;
        // usar BufferedOutput é mais robusto e cobre o contrato real.
        $out  = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call(
            'sugadores:analyze',
            [
                '--company'  => $company->id,
                '--provider' => 'invalid',
                '--dry-run'  => true,
            ],
            $out
        );

        $this->assertSame(1, $exit, 'Provider inválido deve retornar exit code 1');
        $output = $out->fetch();
        $this->assertStringContainsString("Provider inválido: 'invalid'", $output);
        $this->assertStringContainsString('adman, ml', $output);
    }

    // ─────────── Test 8: signature do command declara opção --provider ───────────

    public function test_command_signature_includes_provider_option(): void
    {
        $refl = new ReflectionClass(\App\Console\Commands\AnalyzeSugadores::class);
        $prop = $refl->getProperty('signature');
        $prop->setAccessible(true);

        // Em Laravel commands, $signature pode ser acessado em instância nova.
        $command   = $this->app->make(\App\Console\Commands\AnalyzeSugadores::class);
        $signature = $prop->getValue($command);

        $this->assertStringContainsString(
            '--provider',
            $signature,
            'Signature do AnalyzeSugadores deve declarar opção --provider (Plan 39-05)'
        );
    }
}
