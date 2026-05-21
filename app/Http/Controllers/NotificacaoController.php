<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backend de leitura de notificações — POLL-01/02/03 + HIST-01/03/04.
 *
 * Controller único da Phase 9 que serve os 4 endpoints HTTP que a UI da
 * Phase 10 (sino + página de histórico) consome:
 *
 *   - GET    /notificacoes                          → index Inertia paginada (HIST-01)
 *   - GET    /api/notificacoes/contador             → JSON {count:N} polling (POLL-02)
 *   - PATCH  /notificacoes/{id}/marcar-lida         → marca uma como lida (HIST-03)
 *   - POST   /notificacoes/marcar-todas-lidas       → marca todas atômico (HIST-04)
 *
 * O contador de unread em toda página Inertia (POLL-01 + POLL-03) é entregue
 * via shared prop `notificacoes_nao_lidas` no `HandleInertiaRequests::share()`,
 * não por este controller.
 *
 * Não usa Form Request — as rotas têm validação trivial (ID via route param
 * para `marcarLida`, nenhuma para `marcarTodasLidas`/`index`/`contador`). O
 * model nativo `Illuminate\Notifications\DatabaseNotification` é usado direto
 * (D-06 Phase 8 — sem model próprio nem trait LogsActivity).
 */
class NotificacaoController extends Controller
{
    /**
     * Lista as notifications do user autenticado, paginadas em 15 por página.
     *
     * Ordem garantida pelo `latest()` interno de `HasDatabaseNotifications::notifications()`
     * (created_at DESC). A página `Notificacoes/Index` é um stub na Phase 9 — a UI
     * completa (cards, abas filtro lida/não lida, botão "marcar todas") sai na Phase 10.
     */
    public function index(Request $request): Response
    {
        $notificacoes = $request->user()->notifications()->paginate(15);

        return Inertia::render('Notificacoes/Index', [
            'notificacoes' => $notificacoes,
        ]);
    }

    /**
     * Endpoint de polling JSON — retorna `{count: N}` com a contagem de unread
     * do user autenticado (POLL-02).
     *
     * Formato minimal por design: o cliente da Phase 10 vai consultar com
     * `setInterval` (~30s) e atualizar apenas o badge do sino quando o número
     * muda. JSON enxuto evita custo de payload em polls frequentes.
     */
    public function contador(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marca uma notification específica como lida (HIST-03).
     *
     * `abort_unless` faz a defesa contra cross-user mark (T-09-01): garante
     * que o user autenticado é o dono da notification antes de marcar.
     * A checagem dupla (`notifiable_id` + `notifiable_type`) é necessária
     * porque a tabela é polimórfica — um id colidente entre tipos diferentes
     * (User vs Setor, futuro) não pode passar.
     *
     * `markAsRead()` é método nativo do `DatabaseNotification` que preenche
     * `read_at = now()` e dá save.
     */
    public function marcarLida(Request $request, string $id): RedirectResponse
    {
        $notificacao = DatabaseNotification::findOrFail($id);
        $user        = $request->user();

        abort_unless(
            $notificacao->notifiable_id === $user->id
                && $notificacao->notifiable_type === User::class,
            403,
            'Notificação não pertence ao usuário autenticado.'
        );

        $notificacao->markAsRead();

        return back()->with('success', 'Notificação marcada como lida.');
    }

    /**
     * Marca todas as notifications unread do user autenticado como lidas em
     * uma única query (HIST-04).
     *
     * Atomicidade via `update()` no relationship `unreadNotifications()` —
     * gera um UPDATE com WHERE `notifiable_id`/`notifiable_type` + `read_at
     * IS NULL`, sem N+1. Não afeta outros users (filtro do morphMany cuida).
     */
    public function marcarTodasLidas(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Todas as notificações foram marcadas como lidas.');
    }
}
