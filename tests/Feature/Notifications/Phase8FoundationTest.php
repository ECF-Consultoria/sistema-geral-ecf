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
     * Cobre PERM-01 (registro no catálogo) no nível de catálogo plano. Prova
     * que a key foi inserida em `catalog()` E que `all()` (que varre catalog
     * dinamicamente) está enxergando-a, sem precisar editar `all()` — esta é
     * a propriedade que evita "chaves fantasma" no catálogo (T-08-08).
     */
    public function test_permissions_all_inclui_notificacoes_criar(): void
    {
        $this->assertContains('notificacoes.criar', Permissions::all());
        $this->assertSame('notificacoes.criar', Permissions::NOTIFICACOES_CRIAR);
        $this->assertTrue(Permissions::isValid('notificacoes.criar'));
    }

    /**
     * Test 3 — Catálogo `Permissions::catalog()` inclui grupo "Notificações"
     * com a entrada `notificacoes.criar` (label + description em pt-BR).
     *
     * Cobre PERM-01 (label + description em pt-BR exposta para Phase 12 UI
     * de setores). A descrição menciona "manuais" para travar D-08 — o texto
     * tem que dizer que a permissão é sobre *envio manual*, não sobre
     * recepção de notificação.
     */
    public function test_catalog_inclui_grupo_notificacoes(): void
    {
        $catalog = Permissions::catalog();

        $this->assertArrayHasKey('Notificações', $catalog);

        // `firstWhere` localiza a entry pela key — independe da ordem dentro do grupo.
        $entry = collect($catalog['Notificações'])->firstWhere('key', 'notificacoes.criar');

        $this->assertNotNull($entry);
        $this->assertSame('Criar notificações', $entry['label']);
        // Descrição em pt-BR menciona "manuais" (envio manual, não automático).
        $this->assertStringContainsString('manuais', $entry['description']);
    }

    /**
     * Test 4 — Constante `AUTO_LIDERANCA` contém `notificacoes.criar`,
     * garantindo que todo líder de setor herda a permissão.
     *
     * Cobre PERM-03 no nível de catálogo (D-09 implementado). A verificação
     * end-to-end via `User::hasPermission()` em líder real fica para Plan 04
     * (Test 6) — aqui basta provar que a key está no array que o
     * `User::effectivePermissions()` merge-eia automaticamente.
     */
    public function test_auto_lideranca_inclui_notificacoes_criar(): void
    {
        $this->assertContains('notificacoes.criar', Permissions::AUTO_LIDERANCA);
    }

    /**
     * Test 5 — Admin retorna `true` em `hasPermission('notificacoes.criar')`
     * via short-circuit em `User::isAdmin()`, sem precisar de atribuição.
     *
     * Cobre PERM-02 + Success Criterion #3 do ROADMAP §Phase 8. Prova D-10:
     * o caminho admin já existia em `User::hasPermission` (linha 106:
     * `if ($this->isAdmin()) return true;`) e cobre a permission nova
     * `notificacoes.criar` automaticamente — ZERO mudança em User.php.
     *
     * O teste NÃO atribui setor (`setores()->attach`) nem permission manual
     * (`SetorPermissao::create`) nem `setoresLiderados()->attach` — qualquer
     * atribuição comprometeria a prova de que o short-circuit basta sozinho.
     */
    public function test_admin_tem_permissao_via_short_circuit(): void
    {
        // Setup mínimo: apenas um admin, sem nenhuma vinculação a setor.
        $admin = User::factory()->create(['role' => 'admin']);

        // Assert defensivo — deixa claro no failure message que estamos
        // verificando o caminho short-circuit (isAdmin() === true → bypass total).
        $this->assertTrue($admin->isAdmin());

        // Assert principal — PERM-02 verificado end-to-end via hasPermission().
        // `User::hasPermission` linha 106 retorna `true` antes de consultar
        // `effectivePermissions()`, então a permission `notificacoes.criar` é
        // resolvida automaticamente para qualquer admin, sem precisar estar
        // em `setor_permissoes` nem em `setor_lideres`.
        $this->assertTrue($admin->hasPermission('notificacoes.criar'));
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
     * (`titulo`, `mensagem`, `categoria`, `autor_user_id`, `url`, `meta`).
     *
     * Cobre Success Criterion #1 end-to-end — a tabela não só existe, ela
     * aceita escrita via `DatabaseChannel` e o payload é deserializado pelo
     * cast `array` do `DatabaseNotification`. Também prova implicitamente
     * `BaseNotification::toArray()` (enum→string em `categoria`) e que a
     * coluna `type` é populada pelo dispatcher (sem hardcodar nome de classe
     * anônima — Pitfall 2 do 08-RESEARCH.md).
     */
    public function test_base_notification_persiste_payload_canonico(): void
    {
        // Setup: notifiable real (User com trait Notifiable já presente no model).
        $user = User::factory()->create(['role' => 'consultor']);

        // Classe anônima estendendo BaseNotification com os 6 args canônicos via
        // named arguments. D-04 do 08-CONTEXT.md trava: Phase 8 NÃO cria subclasses
        // concretas — o smoke usa anônima inline (`ManualNotification` etc. saem
        // nas Phases 11/12).
        $notif = new class(
            titulo: 'Título teste',
            mensagem: 'Mensagem teste',
            categoria: Categoria::MANUAL,
            autorUserId: null,
            url: '/notificacoes',
            meta: ['ref' => 'phase-8-smoke'],
        ) extends BaseNotification {};

        // Disparo REAL via facade. NÃO usar Notification::fake() — queremos
        // que o DatabaseChannel persista de fato na tabela `notifications`
        // (Pitfall 1 do 08-RESEARCH.md: fake() intercepta o envio e impede
        // a gravação que o teste precisa observar).
        Notification::send($user, $notif);

        // Identidade da linha persistida.
        $this->assertDatabaseCount('notifications', 1);
        $row = DatabaseNotification::query()->first();
        $this->assertSame($user->id, (int) $row->notifiable_id);
        $this->assertSame(User::class, $row->notifiable_type);
        $this->assertNull($row->read_at);

        // Coluna `type` foi populada pelo dispatcher do Laravel (W4). NÃO
        // hardcodar o nome da classe anônima (`class@anonymous...`) — esses
        // nomes incluem hash de offset interno e são instáveis entre runs
        // (Pitfall 2 do 08-RESEARCH.md). Basta provar que a coluna não veio vazia.
        $this->assertNotEmpty($row->type);

        // Payload canônico de 6 chaves (cast `array` do DatabaseNotification
        // faz o round-trip JSON automático — Pitfall 3 do 08-RESEARCH.md
        // trava acesso à coluna `data` como string crua).
        $this->assertSame('Título teste', $row->data['titulo']);
        $this->assertSame('Mensagem teste', $row->data['mensagem']);
        $this->assertSame('manual', $row->data['categoria']);   // enum→value persistido
        $this->assertNull($row->data['autor_user_id']);
        $this->assertSame('/notificacoes', $row->data['url']);
        $this->assertSame(['ref' => 'phase-8-smoke'], $row->data['meta']);
    }
}
