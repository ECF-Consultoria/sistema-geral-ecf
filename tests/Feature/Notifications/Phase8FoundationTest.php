<?php

namespace Tests\Feature\Notifications;

use App\Models\Setor;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Categoria;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Suíte canônica da Phase 8 — Fundação de Notificações.
 *
 * Concentra os 7 testes que provam a fundação completa do sistema de
 * Notificações v3.0:
 *   - Test 1: schema da tabela `notifications` (Slice 1 — Storage)
 *   - Tests 2/3/4: catálogo de permissões + AUTO_LIDERANCA (Slice 3 — Permissions)
 *   - Tests 5/6: admin via short-circuit + líder via auto-liderança (Slice 3)
 *   - Test 7: smoke `Notification::send` com payload canônico (Slice 4)
 *
 * Nesta Slice 1 apenas o Test 1 fica implementado; os demais entram como
 * esqueletos `markTestIncomplete` e serão preenchidos pelas slices seguintes.
 *
 * IMPORTANTE: nunca usar `Notification::fake()` aqui — o canal database depende
 * do dispatcher real para gravar na tabela `notifications`; mockear quebra a
 * persistência (Pitfall 1 do 08-RESEARCH.md).
 */
class Phase8FoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1 — Schema da tabela `notifications` no formato canônico Laravel 12.
     *
     * Valida que a migration criou a tabela com as 8 colunas nativas
     * (`id` UUID PK, `type`, polimórficas `notifiable_id`/`notifiable_type`,
     * `data` text, `read_at` nullable e timestamps `created_at`/`updated_at`).
     */
    public function test_migration_cria_tabela_notifications(): void
    {
        // Tabela criada pela migration de Slice 1.
        $this->assertTrue(Schema::hasTable('notifications'));

        // 8 colunas canônicas do schema Laravel 12 (ver D-05 + Pitfall 3).
        $this->assertTrue(Schema::hasColumn('notifications', 'id'));
        $this->assertTrue(Schema::hasColumn('notifications', 'type'));
        $this->assertTrue(Schema::hasColumn('notifications', 'notifiable_id'));
        $this->assertTrue(Schema::hasColumn('notifications', 'notifiable_type'));
        $this->assertTrue(Schema::hasColumn('notifications', 'data'));
        $this->assertTrue(Schema::hasColumn('notifications', 'read_at'));
        $this->assertTrue(Schema::hasColumn('notifications', 'created_at'));
        $this->assertTrue(Schema::hasColumn('notifications', 'updated_at'));
    }

    /**
     * Test 2 — Constante `NOTIFICACOES_CRIAR` exposta e presente em `Permissions::all()`.
     *
     * Será implementado pela Slice 3 (Permissions) — Plan 03.
     */
    public function test_permissions_all_inclui_notificacoes_criar(): void
    {
        $this->markTestIncomplete('Implementado em Slice 3 (Permissions) — Plan 03.');
    }

    /**
     * Test 3 — Catálogo `Permissions::catalog()` inclui grupo "Notificações"
     * com a entrada `notificacoes.criar` (label + description em pt-BR).
     *
     * Será implementado pela Slice 3 (Permissions) — Plan 03.
     */
    public function test_catalog_inclui_grupo_notificacoes(): void
    {
        $this->markTestIncomplete('Implementado em Slice 3 (Permissions) — Plan 03.');
    }

    /**
     * Test 4 — Constante `AUTO_LIDERANCA` contém `notificacoes.criar`,
     * garantindo que todo líder de setor herda a permissão.
     *
     * Será implementado pela Slice 3 (Permissions) — Plan 03.
     */
    public function test_auto_lideranca_inclui_notificacoes_criar(): void
    {
        $this->markTestIncomplete('Implementado em Slice 3 (Permissions) — Plan 03.');
    }

    /**
     * Test 5 — Admin retorna `true` em `hasPermission('notificacoes.criar')`
     * via short-circuit em `User::isAdmin()`, sem precisar de atribuição.
     *
     * Será implementado pela Slice 3 (Permissions) — Plan 03.
     */
    public function test_admin_tem_permissao_via_short_circuit(): void
    {
        $this->markTestIncomplete('Implementado em Slice 3 (Permissions) — Plan 03.');
    }

    /**
     * Test 6 — Líder de setor retorna `true` em `hasPermission('notificacoes.criar')`
     * automaticamente via `AUTO_LIDERANCA`, sem grant explícito.
     *
     * Será implementado pela Slice 3 (Permissions) — Plan 03.
     */
    public function test_lider_tem_permissao_via_auto_lideranca(): void
    {
        $this->markTestIncomplete('Implementado em Slice 3 (Permissions) — Plan 03.');
    }

    /**
     * Test 7 — Smoke ponta-a-ponta: `Notification::send` persiste 1 linha em
     * `notifications` com payload canônico de 6 chaves
     * (`titulo`, `mensagem`, `categoria`, `cta_label`, `cta_url`, `meta`).
     *
     * Será implementado pela Slice 4 (BaseNotification + smoke) — Plan 04.
     */
    public function test_base_notification_persiste_payload_canonico(): void
    {
        $this->markTestIncomplete('Implementado em Slice 4 (BaseNotification + smoke) — Plan 04.');
    }
}
