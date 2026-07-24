<?php

namespace Tests\Feature\Phase97;

use App\Models\Module;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comandos artisan do Module Registry (Fase 97) — prova os Success Criteria
 * SC2 (`modules:sync` idempotente, preserva estado de runtime sem `--force`)
 * e SC5 (`modules:check`, guard anti-drift NAV_TREE <-> catálogo com exit
 * code de CI).
 *
 * @group phase97
 */
class ModulesCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** Caminho da fixture de NAV_TREE criada em teste — limpo no tearDown. */
    private ?string $fixtureNavPath = null;

    protected function tearDown(): void
    {
        if ($this->fixtureNavPath !== null && is_file($this->fixtureNavPath)) {
            @unlink($this->fixtureNavPath);
        }

        parent::tearDown();
    }

    // ─── SC2: modules:sync — idempotência ─────────────────────────────────

    public function test_sync_popula_todos_os_modulos_do_catalogo_sem_duplicata(): void
    {
        $this->artisan('modules:sync')->assertExitCode(0);

        $this->assertSame(count(Modules::all()), Module::count());

        $keys = Module::pluck('key');
        $this->assertSame($keys->count(), $keys->unique()->count());
    }

    public function test_sync_rodado_duas_vezes_nao_altera_o_total(): void
    {
        $this->artisan('modules:sync')->assertExitCode(0);
        $totalAposPrimeiraExecucao = Module::count();

        $this->artisan('modules:sync')->assertExitCode(0);

        $this->assertSame($totalAposPrimeiraExecucao, Module::count());
    }

    public function test_sync_grava_stage_default_do_catalogo_na_criacao(): void
    {
        $this->artisan('modules:sync')->assertExitCode(0);

        $modulo = Module::where('key', Modules::MLB_ANUNCIAR)->first();

        $this->assertNotNull($modulo);
        $this->assertSame(Modules::defaultStageFor(Modules::MLB_ANUNCIAR), $modulo->stage);
        $this->assertSame('homologacao', $modulo->stage);
    }

    public function test_sync_sem_force_preserva_stage_divergente_do_runtime(): void
    {
        $this->artisan('modules:sync')->assertExitCode(0);

        // Simula o board (Fase 100) alterando o estágio em runtime.
        Module::where('key', Modules::MLB_ANUNCIAR)->update(['stage' => 'em_desenvolvimento']);

        $this->artisan('modules:sync')->assertExitCode(0);

        $this->assertSame(
            'em_desenvolvimento',
            Module::where('key', Modules::MLB_ANUNCIAR)->value('stage')
        );
    }

    public function test_sync_com_force_ressincroniza_stage_para_o_default_do_catalogo(): void
    {
        $this->artisan('modules:sync')->assertExitCode(0);

        Module::where('key', Modules::MLB_ANUNCIAR)->update(['stage' => 'em_desenvolvimento']);

        $this->artisan('modules:sync', ['--force' => true])->assertExitCode(0);

        $this->assertSame(
            'homologacao',
            Module::where('key', Modules::MLB_ANUNCIAR)->value('stage')
        );
    }

    public function test_sync_dry_run_nao_grava_nada(): void
    {
        $this->artisan('modules:sync', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, Module::count());
    }

    // ─── SC5: modules:check — anti-drift NAV_TREE <-> catálogo ────────────

    public function test_check_passa_com_o_nav_tree_real(): void
    {
        // Nesta fase o NAV_TREE ainda não decora nenhum item com moduleKey
        // (Fase 98) — o comando deve passar trivialmente (exit 0).
        $this->artisan('modules:check')->assertExitCode(0);
    }

    public function test_check_falha_com_modulekey_fantasma_no_nav(): void
    {
        $this->fixtureNavPath = tempnam(sys_get_temp_dir(), 'phase97_nav_');

        file_put_contents($this->fixtureNavPath, <<<'JSX'
        const NAV_TREE = [
            { name: 'Fantasma', moduleKey: 'chave.fantasma' },
            { name: 'Dashboard', moduleKey: 'core.dashboard' },
        ];
        JSX);

        $this->artisan('modules:check', ['--nav-path' => $this->fixtureNavPath])
            ->assertExitCode(1);
    }
}
