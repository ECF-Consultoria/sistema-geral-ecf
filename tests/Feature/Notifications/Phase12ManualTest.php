<?php

namespace Tests\Feature\Notifications;

use App\Models\Setor;
use App\Models\SetorPermissao;
use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Categoria;
use App\Notifications\ManualNotification;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Suíte canônica da Phase 12 — Criação Manual + Permissão + Cleanup.
 *
 * Cobre 10 testes:
 *   1. PERM-04: catálogo Permissions inclui grupo "Notificações"
 *   2. ENVIO-05: GET /notificacoes/nova sem permission → 403
 *   3. ENVIO-05: POST /notificacoes/nova sem permission → 403
 *   4. ENVIO-01: user COM permission acessa /notificacoes/nova
 *   5. ENVIO-02: público=setor envia apenas para membros do setor
 *   6. ENVIO-02: público=lideres envia apenas para líderes
 *   7. ENVIO-02: público=todos envia para users ativos (inclui autor)
 *   8. ENVIO-03: validation rejeita título/mensagem vazios ou >limit
 *   9. ENVIO-04 + POLL-05: flash success + activity_log registrado
 *  10. POLL-04: command notifications:cleanup remove lidas >30d (preserva unread + recentes)
 *
 * Convenção mantida: NUNCA usar `Notification::fake()` — observa persistência
 * real no canal database. ManualNotification (Phase 12) é a classe concreta
 * que diferencia type FQCN no activity_log e na tabela notifications.
 *
 * Helpers internos:
 *   - autorComPermissao(): cria user admin (short-circuit hasPermission)
 *   - autorViaSetorPermissao(): cria user sem admin, com setor que tem `notificacoes.criar`
 *   - userSemPermissao(): cria user consultor sem nenhum setor (não tem a key)
 */
class Phase12ManualTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria um user admin — atalho mais simples (short-circuit em hasPermission).
     * Usado em testes onde o foco não é o gating em si, mas o comportamento do
     * controller para um caller autorizado.
     */
    private function autorComPermissao(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Cria um user NÃO admin com permissão `notificacoes.criar` concedida via
     * setor — exercita o caminho real de SetorPermissao + effectivePermissions.
     * Usado pelo teste ENVIO-01 (acesso a /nova) para garantir que o gating
     * não depende de role=admin.
     */
    private function autorViaSetorPermissao(): User
    {
        $setor = Setor::create(['nome' => 'Setor Com Permissao', 'active' => true]);
        SetorPermissao::create([
            'setor_id'       => $setor->id,
            'permission_key' => Permissions::NOTIFICACOES_CRIAR,
        ]);

        $user = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($user->id, [
            'is_principal' => true,
            'assigned_at'  => now(),
        ]);

        return $user;
    }

    /**
     * Cria user sem nenhuma permissão (consultor sem setor) — pra testes de 403.
     */
    private function userSemPermissao(): User
    {
        return User::factory()->create(['role' => 'consultor']);
    }

    /**
     * Test 1 — PERM-04: catálogo `Permissions` contém o grupo "Notificações"
     * com a key `notificacoes.criar`.
     *
     * Sanity check do que a Phase 8 já entregou. Garante que a UI de setores
     * (que itera `Permissions::catalog()`) mostra a opção pra atribuição.
     */
    public function test_perm_04_catalog_inclui_notificacoes_criar(): void
    {
        $catalog = Permissions::catalog();

        $this->assertArrayHasKey('Notificações', $catalog);

        $keys = array_column($catalog['Notificações'], 'key');
        $this->assertContains(Permissions::NOTIFICACOES_CRIAR, $keys);
    }

    /**
     * Test 2 — ENVIO-05: user sem permissão recebe 403 em GET /notificacoes/nova.
     *
     * Garante que o middleware `permission:notificacoes.criar` está ativo na
     * rota e que o `EnsurePermission` aborta com 403 (não com 302/login).
     */
    public function test_envio_05_user_sem_permissao_recebe_403_em_get_nova(): void
    {
        $user = $this->userSemPermissao();

        $this->actingAs($user)
            ->get(route('notificacoes.nova'))
            ->assertStatus(403);
    }

    /**
     * Test 3 — ENVIO-05: user sem permissão recebe 403 em POST /notificacoes/nova.
     *
     * Mesmo gating do GET. Cobre o POST separado (Laravel registra GET e POST
     * em rotas distintas mesmo quando a URL é idêntica — middleware aplica em
     * ambas via group, mas o teste confirma a aplicação efetiva).
     */
    public function test_envio_05_user_sem_permissao_recebe_403_em_post_criar(): void
    {
        $user = $this->userSemPermissao();

        $this->actingAs($user)
            ->post(route('notificacoes.criar'), [
                'titulo'   => 'Tentativa',
                'mensagem' => 'Sem permissão',
                'publico'  => 'todos',
            ])
            ->assertStatus(403);
    }

    /**
     * Test 4 — ENVIO-01: user COM permissão (via setor) acessa /notificacoes/nova
     * e recebe o componente Inertia esperado com a prop `setores`.
     *
     * Exercita o caminho real de SetorPermissao (não usa admin short-circuit) —
     * prova que `effectivePermissions` une as keys do setor corretamente.
     */
    public function test_envio_01_user_com_permissao_acessa_nova_com_setores(): void
    {
        // Cria um setor de "infra" pra que apareça na prop setores; o autor
        // tem permissão via OUTRO setor diferente.
        $setorOutro = Setor::create(['nome' => 'Outro Setor Listavel', 'active' => true]);

        $autor = $this->autorViaSetorPermissao();

        $response = $this->actingAs($autor)->get(route('notificacoes.nova'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->component('Notificacoes/Nova')
                 ->has('setores')
        );
    }

    /**
     * Test 5 — ENVIO-02 (publico=setor): envia apenas para membros do setor
     * escolhido, mesmo havendo outros users e setores.
     *
     * Setup: setor S com 3 membros + 1 user fora do setor. Autor é admin.
     * POST com `publico=setor, setor_id=S->id`. Espera 3 notifications, todas
     * `ManualNotification`, nenhuma para o user de fora.
     */
    public function test_envio_02_publico_setor_envia_para_membros_apenas(): void
    {
        $autor = $this->autorComPermissao();

        $setor = Setor::create(['nome' => 'Setor Alvo', 'active' => true]);

        // 3 membros do setor + 1 user fora.
        $membros = User::factory()->count(3)->create(['role' => 'consultor']);
        foreach ($membros as $m) {
            $setor->membros()->attach($m->id, [
                'is_principal' => true,
                'assigned_at'  => now(),
            ]);
        }
        $foraDoSetor = User::factory()->create(['role' => 'consultor']);

        $this->actingAs($autor)
            ->post(route('notificacoes.criar'), [
                'titulo'   => 'Aviso ao setor',
                'mensagem' => 'Mensagem específica do setor.',
                'publico'  => 'setor',
                'setor_id' => $setor->id,
            ])
            ->assertStatus(302);

        // 3 notifications criadas (uma por membro), nenhuma para o user fora.
        $this->assertDatabaseCount('notifications', 3);

        foreach ($membros as $m) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $m->id,
                'notifiable_type' => User::class,
                'type'            => ManualNotification::class,
            ]);
        }

        $this->assertSame(
            0,
            DatabaseNotification::where('notifiable_id', $foraDoSetor->id)->count()
        );
    }

    /**
     * Test 6 — ENVIO-02 (publico=lideres): envia apenas para users que são
     * líderes em pelo menos 1 setor.
     *
     * Setup: 2 líderes L1/L2 (cada um em um setor diferente) + 3 não-líderes.
     * Autor é admin (não é líder de setor algum — não recebe). Espera 2
     * notifications, ambas para os líderes.
     */
    public function test_envio_02_publico_lideres_envia_para_lideres_apenas(): void
    {
        $autor = $this->autorComPermissao();

        $setorA = Setor::create(['nome' => 'Setor A', 'active' => true]);
        $setorB = Setor::create(['nome' => 'Setor B', 'active' => true]);

        $lider1 = User::factory()->create(['role' => 'consultor']);
        $lider2 = User::factory()->create(['role' => 'consultor']);
        $setorA->lideres()->attach($lider1->id, ['assigned_by' => null, 'assigned_at' => now()]);
        $setorB->lideres()->attach($lider2->id, ['assigned_by' => null, 'assigned_at' => now()]);

        // 3 não-líderes — não devem receber.
        $naoLideres = User::factory()->count(3)->create(['role' => 'consultor']);

        $this->actingAs($autor)
            ->post(route('notificacoes.criar'), [
                'titulo'   => 'Aviso aos líderes',
                'mensagem' => 'Mensagem para liderança.',
                'publico'  => 'lideres',
            ])
            ->assertStatus(302);

        // 2 notifications (uma por líder), nenhuma para autor/não-líderes.
        $this->assertDatabaseCount('notifications', 2);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $lider1->id,
            'notifiable_type' => User::class,
            'type'            => ManualNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $lider2->id,
            'notifiable_type' => User::class,
            'type'            => ManualNotification::class,
        ]);

        $this->assertSame(0, DatabaseNotification::where('notifiable_id', $autor->id)->count());
        foreach ($naoLideres as $n) {
            $this->assertSame(0, DatabaseNotification::where('notifiable_id', $n->id)->count());
        }
    }

    /**
     * Test 7 — ENVIO-02 (publico=todos): envia para todos os users ativos,
     * incluindo o autor (sem self-exclusion — decisão D-04 do plano).
     *
     * Setup: 3 users ativos + 1 user inactive. Autor (admin, ativo) já conta
     * como 1 dos ativos. POST `publico=todos`. Espera 4 notifications (4
     * ativos: 3 outros + autor). O inactive NÃO recebe.
     */
    public function test_envio_02_publico_todos_envia_para_users_ativos(): void
    {
        $autor = $this->autorComPermissao(); // ativo por default

        $ativos = User::factory()->count(3)->create(['role' => 'consultor', 'active' => true]);
        $inactive = User::factory()->create(['role' => 'consultor', 'active' => false]);

        $this->actingAs($autor)
            ->post(route('notificacoes.criar'), [
                'titulo'   => 'Aviso geral',
                'mensagem' => 'Mensagem para todos.',
                'publico'  => 'todos',
            ])
            ->assertStatus(302);

        // 4 ativos = autor + 3 outros. inactive não conta.
        $this->assertDatabaseCount('notifications', 4);

        foreach ($ativos as $a) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id'   => $a->id,
                'notifiable_type' => User::class,
                'type'            => ManualNotification::class,
            ]);
        }

        // Autor recebe (sem self-exclusion).
        $this->assertDatabaseHas('notifications', [
            'notifiable_id'   => $autor->id,
            'notifiable_type' => User::class,
            'type'            => ManualNotification::class,
        ]);

        // Inactive NÃO recebe.
        $this->assertSame(
            0,
            DatabaseNotification::where('notifiable_id', $inactive->id)->count()
        );
    }

    /**
     * Test 8 — ENVIO-03: validation rejeita título vazio, título >100,
     * mensagem vazia e mensagem >1000 (4 cenários combinados em um único
     * teste por economia, cada cenário no array `$cenarios`).
     *
     * Cada cenário é POSTado e assertSessionHasErrors no campo apropriado.
     * Nenhuma notification deve ser criada em qualquer cenário inválido.
     */
    public function test_envio_03_validation_rejeita_titulo_e_mensagem_invalidos(): void
    {
        $autor = $this->autorComPermissao();

        $cenarios = [
            ['titulo' => '',                    'mensagem' => 'ok',                        'campo' => 'titulo'],
            ['titulo' => str_repeat('a', 101),  'mensagem' => 'ok',                        'campo' => 'titulo'],
            ['titulo' => 'ok',                  'mensagem' => '',                          'campo' => 'mensagem'],
            ['titulo' => 'ok',                  'mensagem' => str_repeat('a', 1001),       'campo' => 'mensagem'],
        ];

        foreach ($cenarios as $c) {
            $this->actingAs($autor)
                ->post(route('notificacoes.criar'), [
                    'titulo'   => $c['titulo'],
                    'mensagem' => $c['mensagem'],
                    'publico'  => 'todos',
                ])
                ->assertSessionHasErrors($c['campo']);
        }

        // Nenhuma notification criada em nenhum cenário inválido.
        $this->assertDatabaseCount('notifications', 0);
    }

    /**
     * Test 9 — ENVIO-04 + POLL-05: flash success contém contagem exata + entry
     * em `activity_log` registra a operação com `causedBy` e `properties` corretos.
     *
     * Setup: setor com 2 membros, autor admin. POST `publico=setor`. Espera:
     *   - response 302 com flash `success` contendo "2 destinatário(s)"
     *   - 1 entry em `activity_log` com:
     *       * description = 'Notificação manual enviada'
     *       * causer_id   = autor->id
     *       * properties->destinatarios_count = 2
     *       * properties->publico = 'setor'
     *       * properties->setor_id = setor->id
     */
    public function test_envio_04_e_poll_05_flash_count_e_activity_log_registrado(): void
    {
        $autor = $this->autorComPermissao();

        $setor = Setor::create(['nome' => 'Setor Log', 'active' => true]);
        $m1 = User::factory()->create(['role' => 'consultor']);
        $m2 = User::factory()->create(['role' => 'consultor']);
        $setor->membros()->attach($m1->id, ['is_principal' => true, 'assigned_at' => now()]);
        $setor->membros()->attach($m2->id, ['is_principal' => true, 'assigned_at' => now()]);

        $response = $this->actingAs($autor)
            ->post(route('notificacoes.criar'), [
                'titulo'   => 'Aviso log',
                'mensagem' => 'Verifica que loga.',
                'publico'  => 'setor',
                'setor_id' => $setor->id,
            ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success', 'Notificação enviada para 2 destinatário(s).');

        // 1 entry em activity_log com a descrição esperada.
        $activity = Activity::where('description', 'Notificação manual enviada')->first();

        $this->assertNotNull($activity, 'Esperava 1 entry em activity_log para envio manual.');
        $this->assertSame($autor->id, $activity->causer_id);
        $this->assertSame(User::class, $activity->causer_type);
        $this->assertSame(2,           $activity->properties['destinatarios_count']);
        $this->assertSame('setor',     $activity->properties['publico']);
        $this->assertSame($setor->id,  $activity->properties['setor_id']);
        $this->assertSame('Aviso log', $activity->properties['titulo']);
    }

    /**
     * Test 10 — POLL-04: command `notifications:cleanup` remove notifications
     * lidas há >30 dias e preserva unread + lidas recentes.
     *
     * Setup: 3 notifications dispatched via classe anônima inline (boilerplate
     * canônico das Phases 9/10):
     *   - A: lida há 40 dias (read_at antigo)
     *   - B: lida há 5 dias (read_at recente)
     *   - C: unread (read_at = null)
     *
     * Após `Artisan::call('notifications:cleanup')`:
     *   - A foi removida
     *   - B preservada (lida mas dentro da janela)
     *   - C preservada (unread, nunca remove)
     */
    public function test_poll_04_cleanup_remove_lidas_antigas_e_preserva_unread_e_recentes(): void
    {
        $user = User::factory()->create(['role' => 'consultor']);

        // Dispatch 3 notifications usando classe anônima inline (D-04 Phase 8).
        for ($i = 1; $i <= 3; $i++) {
            $notif = new class(
                titulo:      "Notif {$i}",
                mensagem:    "Mensagem {$i}",
                categoria:   Categoria::MANUAL,
                autorUserId: null,
                url:         null,
                meta:        [],
            ) extends BaseNotification {};
            Notification::send($user, $notif);
        }

        $notifs = $user->notifications()->orderBy('created_at')->get();
        $a = $notifs[0]; // lida há 40 dias
        $b = $notifs[1]; // lida há 5 dias
        $c = $notifs[2]; // unread

        // Marca A e B como lidas e força read_at antigo via update (ignora $timestamps).
        DatabaseNotification::where('id', $a->id)->update(['read_at' => now()->subDays(40)]);
        DatabaseNotification::where('id', $b->id)->update(['read_at' => now()->subDays(5)]);

        $this->assertDatabaseCount('notifications', 3);

        // Roda o cleanup.
        Artisan::call('notifications:cleanup');

        // A removida; B e C preservadas.
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseMissing('notifications', ['id' => $a->id]);
        $this->assertDatabaseHas('notifications',    ['id' => $b->id]);
        $this->assertDatabaseHas('notifications',    ['id' => $c->id]);
    }

    /**
     * Test 11 — REGRESSION: Inertia `useForm` sempre envia TODAS as chaves do form
     * (inclusive `usuario_id` e `setor_id` como string vazia) quando `publico=todos`
     * ou `publico=lideres`. Bug descoberto em produção: regra `exists:users,id`
     * falhava na string vazia, retornando 302 silenciosamente.
     *
     * Reproduz exatamente o payload que o frontend manda no caso "todos".
     */
    public function test_envio_regression_inertia_envia_chaves_vazias_de_usuario_e_setor(): void
    {
        $autor = $this->autorComPermissao();
        User::factory()->count(2)->create(['role' => 'consultor', 'active' => true]);

        // Payload idêntico ao do Inertia useForm: usuario_id e setor_id presentes mas vazios.
        $this->actingAs($autor)
            ->post(route('notificacoes.criar'), [
                'titulo'     => 'Teste regressão',
                'mensagem'   => 'Inertia sempre envia todas as chaves do form.',
                'publico'    => 'todos',
                'usuario_id' => '',
                'setor_id'   => '',
            ])
            ->assertStatus(302)
            ->assertSessionHas('success')          // success flash setado
            ->assertSessionMissing('errors');      // sem ValidationException

        // 3 ativos = autor + 2 outros — confirma que a request passou pela lógica
        // de dispatch e não foi barrada na validação.
        $this->assertDatabaseCount('notifications', 3);
    }
}
