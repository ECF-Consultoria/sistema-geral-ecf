<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\BaseNotification;
use App\Notifications\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Suíte canônica da Phase 9 — Backend de Leitura, Contador e Polling.
 *
 * Cobre 6 testes que provam end-to-end:
 *   - Test 1/2: shared prop `notificacoes_nao_lidas` (POLL-01/POLL-03)
 *   - Test 3: endpoint JSON `/api/notificacoes/contador` (POLL-02)
 *   - Test 4: index Inertia paginada (HIST-01)
 *   - Test 5: marcar individual com 403 anti-cross-user (HIST-03)
 *   - Test 6: marcar todas atômico (HIST-04)
 *
 * NUNCA usar `Notification::fake()` — Phase 8 já provou que o canal database
 * real funciona; mockear quebraria a persistência que esta phase precisa
 * observar via HTTP real.
 *
 * Pattern de dispatch idêntico à Phase 8 (Test 7): classe anônima inline
 * estendendo BaseNotification com named arguments, evitando criar subclasses
 * concretas (D-04 da Phase 8 — concretas saem nas Phases 11/12).
 */
class Phase9BackendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria N notifications reais para o user via DatabaseChannel (sem fake).
     *
     * Centraliza o boilerplate de dispatch para os 6 testes — usa classe
     * anônima inline (D-04 Phase 8) com payload canônico mínimo.
     */
    private function enviarNotificacoes(User $user, int $quantidade, string $tituloPrefix = 'Notif'): void
    {
        for ($i = 1; $i <= $quantidade; $i++) {
            $notif = new class(
                titulo: "{$tituloPrefix} {$i}",
                mensagem: "Mensagem {$i}",
                categoria: Categoria::MANUAL,
                autorUserId: null,
                url: '/notificacoes',
                meta: ['idx' => $i],
            ) extends BaseNotification {};

            Notification::send($user, $notif);
        }
    }

    /**
     * Test 1 — Shared prop `notificacoes_nao_lidas` reflete contagem real
     * de unread do user autenticado em toda navegação Inertia (POLL-01 + POLL-03).
     *
     * Cria 3 notifications para o $user, faz GET autenticado numa página
     * Inertia qualquer (`/dashboard`), e lê a prop compartilhada via
     * `AssertableInertia` — prova que `HandleInertiaRequests::share()`
     * injeta a contagem a cada request.
     */
    public function test_shared_prop_notificacoes_nao_lidas_reflete_contagem(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->enviarNotificacoes($user, 3);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->where('notificacoes_nao_lidas', 3)
        );
    }

    /**
     * Test 2 — Shared prop retorna 0 quando o user não tem nenhuma
     * notification (cobre a coalesce `?? 0` da closure).
     *
     * Setup idêntico ao Test 1, mas sem dispatch — garante que a closure
     * trata user-sem-nada como zero (não null, não erro).
     */
    public function test_shared_prop_e_zero_quando_user_sem_notificacoes(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->where('notificacoes_nao_lidas', 0)
        );
    }

    /**
     * Test 3 — Endpoint `/api/notificacoes/contador` retorna JSON `{count: N}`
     * apenas com o número de notifications UNREAD do user (POLL-02).
     *
     * Cria 5 + 2 (5 unread, 2 read via `markAsRead`) e assert count===5.
     * `getJson` exige resposta JSON e seta `Accept: application/json`.
     */
    public function test_endpoint_contador_retorna_json_com_count(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // 7 dispatched no total — depois marcamos 2 como lidas.
        $this->enviarNotificacoes($user, 7);

        // Marca as 2 mais antigas como lidas — sobram 5 unread.
        $user->notifications()->orderBy('created_at')->limit(2)->get()
            ->each(fn(DatabaseNotification $n) => $n->markAsRead());

        $response = $this->actingAs($user)->getJson('/api/notificacoes/contador');

        $response->assertStatus(200);
        $response->assertExactJson(['count' => 5]);
    }

    /**
     * Test 4 — Rota nomeada `notificacoes.index` retorna Inertia render do
     * componente `Notificacoes/Index` com prop `notificacoes` paginada (HIST-01).
     *
     * Cria 20 notifications e assert que a página 1 traz 15 (paginação default
     * declarada no controller via `->paginate(15)`).
     */
    public function test_index_retorna_inertia_com_notificacoes_paginadas(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->enviarNotificacoes($user, 20);

        $response = $this->actingAs($user)->get('/notificacoes');

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->component('Notificacoes/Index')
                 ->has('notificacoes.data', 15)
        );
    }

    /**
     * Test 5 — `notificacoes.marcar-lida` funciona quando o dono chama e
     * retorna 403 quando outro user tenta marcar notificação alheia (HIST-03).
     *
     * Cobre o assert `abort_unless` em `notifiable_id === user->id &&
     * notifiable_type === User::class`. Prova T-09-01 (cross-user mark).
     */
    public function test_marcar_lida_funciona_no_dono_e_retorna_403_em_alheia(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $outro = User::factory()->create(['role' => 'admin']);

        // 1 notification para cada um.
        $this->enviarNotificacoes($user, 1, 'Própria');
        $this->enviarNotificacoes($outro, 1, 'Alheia');

        $propria = $user->notifications()->first();
        $alheia  = $outro->notifications()->first();

        // Tentar marcar alheia → 403.
        $this->actingAs($user)
            ->patch("/notificacoes/{$alheia->id}/marcar-lida")
            ->assertStatus(403);

        // Marcar a própria → 302 redirect (back).
        $this->actingAs($user)
            ->patch("/notificacoes/{$propria->id}/marcar-lida")
            ->assertStatus(302);

        // Após a operação, a própria deve ter `read_at` preenchido; a alheia continua null.
        $this->assertNotNull($propria->fresh()->read_at);
        $this->assertNull($alheia->fresh()->read_at);
    }

    /**
     * Test 6 — `notificacoes.marcar-todas-lidas` zera o unread do user
     * autenticado em UMA query, sem afetar outros users (HIST-04).
     *
     * Cobre T-09-02 (operação atômica) e o escopo correto da query
     * (filtragem implícita por `unreadNotifications()` do user).
     */
    public function test_marcar_todas_lidas_zera_unread_count_do_user(): void
    {
        $user  = User::factory()->create(['role' => 'admin']);
        $outro = User::factory()->create(['role' => 'admin']);

        $this->enviarNotificacoes($user, 5, 'User');
        $this->enviarNotificacoes($outro, 3, 'Outro');

        $this->actingAs($user)
            ->post('/notificacoes/marcar-todas-lidas')
            ->assertStatus(302);

        $this->assertSame(0, $user->unreadNotifications()->count());
        // O dispatch para $outro não pode ter sido afetado.
        $this->assertSame(3, $outro->unreadNotifications()->count());
    }
}
