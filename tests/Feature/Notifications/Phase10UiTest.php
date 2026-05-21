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
 * Suíte canônica da Phase 10 — UI do Sino + Página de Histórico (backend layer).
 *
 * Cobre 4 testes que provam as extensões backend que a UI da Phase 10 consome:
 *   - Test 1: `notificacoes.recentes` retorna no máximo 10 itens em ordem desc (SINO-03)
 *   - Test 2: `notificacoes.index` aba "nao-lidas" filtra unread (HIST-02 default)
 *   - Test 3: `notificacoes.index` aba "todas" inclui lidas até 30 dias (HIST-02 janela)
 *   - Test 4: `notificacoes.index` sem query param vira default "nao-lidas"
 *
 * Mesma convenção de dispatch da Phase 9 — classe anônima inline estendendo
 * BaseNotification (D-04 Phase 8). NUNCA usar `Notification::fake()`: o objetivo
 * desta suíte é observar a persistência real via HTTP, não mockear.
 *
 * Para forçar `created_at` antigo numa notification (Test 3), o atalho é
 * fazer `DatabaseNotification::...->update(['created_at' => now()->subDays(40)])`
 * APÓS o `Notification::send()` — `update` ignora `$timestamps`.
 */
class Phase10UiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Helper de dispatch — N notifications reais via DatabaseChannel, sem fake.
     *
     * Idêntico ao da Phase 9 (boilerplate canônico): classe anônima inline
     * com BaseNotification + Categoria::MANUAL.
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
     * Test 1 — Endpoint `notificacoes.recentes` retorna até 10 notifications
     * mais recentes do user em ordem decrescente por `created_at` (SINO-03).
     *
     * Cria 15 dispatches (mais do que o limite); espera 200, 10 itens no
     * payload, e que o primeiro item seja o mais recente (último dispatched).
     */
    public function test_recentes_retorna_no_maximo_10_em_ordem_decrescente(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->enviarNotificacoes($user, 15);

        $response = $this->actingAs($user)->getJson(route('notificacoes.recentes'));

        $response->assertStatus(200);
        $response->assertJsonCount(10, 'notificacoes');

        // O primeiro item deve ser o dispatched por último (mais recente) — "Notif 15".
        $primeiro = $response->json('notificacoes.0');
        $this->assertSame('Notif 15', $primeiro['data']['titulo']);
    }

    /**
     * Test 2 — Aba "nao-lidas" (default) filtra `whereNull('read_at')` no index,
     * trazendo apenas unread (HIST-02).
     *
     * Cria 5 unread + 3 read; chama `/notificacoes` (default = nao-lidas);
     * espera 5 itens na prop `notificacoes.data`, todos com `read_at === null`.
     */
    public function test_index_aba_nao_lidas_filtra_unread(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // 5 unread.
        $this->enviarNotificacoes($user, 5, 'Unread');

        // 3 read — dispatch e depois `markAsRead()` nas 3 últimas inseridas.
        $this->enviarNotificacoes($user, 3, 'Read');
        $user->notifications()->orderBy('created_at', 'desc')->limit(3)->get()
            ->each(fn(DatabaseNotification $n) => $n->markAsRead());

        $response = $this->actingAs($user)->get(route('notificacoes.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->component('Notificacoes/Index')
                 ->where('aba', 'nao-lidas')
                 ->has('notificacoes.data', 5)
                 ->where('notificacoes.data.0.read_at', null)
        );
    }

    /**
     * Test 3 — Aba "todas" inclui unread + read, mas exclui itens com
     * `created_at` anterior a 30 dias (HIST-02 janela rolling / POLL-04).
     *
     * Cria 4 unread (hoje) + 3 read (hoje) + 2 read (40 dias atrás).
     * Aba `todas` deve retornar 7 (4+3); as 2 antigas ficam fora.
     */
    public function test_index_aba_todas_inclui_lidas_de_ate_30_dias(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // 4 unread (hoje).
        $this->enviarNotificacoes($user, 4, 'Hoje unread');

        // 3 read (hoje) — dispatch + markAsRead nas 3 mais recentes.
        $this->enviarNotificacoes($user, 3, 'Hoje read');
        $user->notifications()->orderBy('created_at', 'desc')->limit(3)->get()
            ->each(fn(DatabaseNotification $n) => $n->markAsRead());

        // 2 read antigas (40 dias atrás) — dispatch, markAsRead, e força created_at via update().
        $this->enviarNotificacoes($user, 2, 'Antiga');
        $user->notifications()->orderBy('created_at', 'desc')->limit(2)->get()
            ->each(function (DatabaseNotification $n) {
                $n->markAsRead();
                // `update` ignora $timestamps — força created_at sem disparar touch.
                DatabaseNotification::where('id', $n->id)
                    ->update(['created_at' => now()->subDays(40)]);
            });

        $response = $this->actingAs($user)->get(route('notificacoes.index', ['aba' => 'todas']));

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->component('Notificacoes/Index')
                 ->where('aba', 'todas')
                 ->has('notificacoes.data', 7)
        );
    }

    /**
     * Test 4 — Sem query param `?aba=`, o controller defaulta para "nao-lidas".
     *
     * Cria 1 notification e marca como lida — aba default não deve incluir.
     * Assert prop `aba === 'nao-lidas'` e `notificacoes.data` vazio.
     */
    public function test_index_aba_default_e_nao_lidas(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->enviarNotificacoes($user, 1, 'Lida');
        $user->notifications()->first()->markAsRead();

        $response = $this->actingAs($user)->get(route('notificacoes.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn(AssertableInertia $page) =>
            $page->component('Notificacoes/Index')
                 ->where('aba', 'nao-lidas')
                 ->has('notificacoes.data', 0)
        );
    }
}
