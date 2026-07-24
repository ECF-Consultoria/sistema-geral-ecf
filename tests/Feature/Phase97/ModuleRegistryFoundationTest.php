<?php

namespace Tests\Feature\Phase97;

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fundação do Module Registry (Fase 97) — prova os Success Criteria SC1, SC3
 * e SC4 do ROADMAP: schema das tabelas novas, capability `User::isAdminDev()`
 * (DEC-1, fora do short-circuit de admin), o service `ModuleRegistry`
 * (cache/default seguro/invalidação por evento) e as relações do model
 * `Module` (dependências + favoritos).
 *
 * @group phase97
 */
class ModuleRegistryFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ─── SC1: schema ──────────────────────────────────────────────────────

    public function test_tabelas_do_module_registry_existem(): void
    {
        $this->assertTrue(Schema::hasTable('modules'));
        $this->assertTrue(Schema::hasTable('module_user'));
        $this->assertTrue(Schema::hasTable('module_dependencies'));
    }

    public function test_coluna_is_dev_existe_em_users(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_dev'));
    }

    // ─── SC4: isAdminDev (DEC-1) ──────────────────────────────────────────

    public function test_is_dev_true_torna_isAdminDev_true(): void
    {
        $dev = User::factory()->create(['is_dev' => true]);

        $this->assertTrue($dev->isAdminDev());
    }

    public function test_admin_de_negocio_sem_is_dev_nao_e_admin_dev(): void
    {
        // Prova explicitamente o caso DEC-1: role=admin NÃO herda a capability.
        $admin = User::factory()->create(['role' => 'admin', 'is_dev' => false]);

        $this->assertFalse($admin->isAdminDev());
    }

    // ─── SC3: ModuleRegistry — default seguro para chave ausente ─────────

    public function test_stageFor_retorna_producao_para_chave_inexistente(): void
    {
        $registry = app(ModuleRegistry::class);

        $this->assertSame('producao', $registry->stageFor('chave.inexistente'));
    }

    // ─── SC3: ModuleRegistry — mapa completo key=>stage ───────────────────

    public function test_map_reflete_key_stage_de_todos_os_modulos_criados(): void
    {
        $a = Module::factory()->create(['key' => 'teste.modulo_a', 'stage' => 'homologacao']);
        $b = Module::factory()->create(['key' => 'teste.modulo_b', 'stage' => 'em_desenvolvimento']);

        $mapa = app(ModuleRegistry::class)->map();

        $this->assertSame('homologacao', $mapa[$a->key]);
        $this->assertSame('em_desenvolvimento', $mapa[$b->key]);
    }

    // ─── SC3: invalidação de cache via Module::booted() ───────────────────

    public function test_atualizar_stage_invalida_cache_do_registry(): void
    {
        $modulo = Module::factory()->create(['key' => 'mlb.anunciar', 'stage' => 'homologacao']);

        $registry = app(ModuleRegistry::class);
        $this->assertSame('homologacao', $registry->stageFor($modulo->key));

        // Sem isto, o cache continuaria servindo 'homologacao' (stale) —
        // este teste falharia se Module::booted() não chamasse flush().
        $modulo->update(['stage' => 'producao']);

        $this->assertSame('producao', $registry->stageFor($modulo->key));
    }

    // ─── MOD-97-2: relações de dependência (module_dependencies) ──────────

    public function test_relacao_dependencies_e_dependents(): void
    {
        $a = Module::factory()->create();
        $b = Module::factory()->create();

        $a->dependencies()->attach($b->id);

        $this->assertTrue($a->dependencies->pluck('id')->contains($b->id));
        $this->assertTrue($b->dependents->pluck('id')->contains($a->id));
    }

    // ─── MOD-97-2: relação de favoritos (module_user) ─────────────────────

    public function test_relacao_modulesFavoritados_traz_pivot_favorito(): void
    {
        $user   = User::factory()->create();
        $modulo = Module::factory()->create();

        $user->modulesFavoritados()->attach($modulo->id, ['favorito' => true]);

        $favoritado = $user->modulesFavoritados()->first();

        $this->assertNotNull($favoritado);
        $this->assertSame($modulo->id, $favoritado->id);
        $this->assertTrue((bool) $favoritado->pivot->favorito);
    }
}
