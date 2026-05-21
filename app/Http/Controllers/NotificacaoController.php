<?php

namespace App\Http\Controllers;

use App\Models\Setor;
use App\Models\User;
use App\Notifications\ManualNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backend de leitura de notificações — POLL-01/02/03 + HIST-01/02/03/04 + SINO-03.
 *
 * Controller único do MVP v3.0 que serve os 5 endpoints HTTP que a UI da
 * Phase 10 (sino + página de histórico) consome:
 *
 *   - GET    /notificacoes                          → index Inertia paginada com aba (HIST-01/02)
 *   - GET    /api/notificacoes/contador             → JSON {count:N} polling (POLL-02)
 *   - GET    /api/notificacoes/recentes             → JSON com as 10 mais recentes (SINO-03, Phase 10)
 *   - PATCH  /notificacoes/{id}/marcar-lida         → marca uma como lida (HIST-03)
 *   - POST   /notificacoes/marcar-todas-lidas       → marca todas atômico (HIST-04)
 *
 * O contador de unread em toda página Inertia (POLL-01 + POLL-03) é entregue
 * via shared prop `notificacoes_nao_lidas` no `HandleInertiaRequests::share()`,
 * não por este controller.
 *
 * Não usa Form Request — as rotas têm validação trivial (ID via route param
 * para `marcarLida`, nenhuma para `marcarTodasLidas`/`index`/`contador`/`recentes`).
 * O model nativo `Illuminate\Notifications\DatabaseNotification` é usado direto
 * (D-06 Phase 8 — sem model próprio nem trait LogsActivity).
 */
class NotificacaoController extends Controller
{
    /**
     * Lista as notifications do user autenticado, filtradas por aba e paginadas
     * em 15 por página (HIST-01 + HIST-02).
     *
     * A aba é controlada pelo query param `?aba=`:
     *   - `nao-lidas` (default)  → apenas unread (`whereNull('read_at')`)
     *   - `todas`                → unread + read dos últimos 30 dias (POLL-04)
     *
     * Cada item é enriquecido com `autor_nome` (resolvido a partir de
     * `data.autor_user_id` — Phase 10 D-07). Quando o autor é `null`, a UI
     * exibe "Sistema". O paginator é serializado via `->through()` para
     * preservar a estrutura `LengthAwarePaginator` (meta + data) que o
     * front consome.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        // Default = não lidas para casar com o foco do sino (SINO-04).
        $aba   = $request->query('aba', 'nao-lidas');

        $query = $user->notifications()->latest();

        if ($aba === 'nao-lidas') {
            $query->whereNull('read_at');
        } else {
            // Aba "todas" — janela rolling de 30 dias (alinha com cleanup da Phase 12 / POLL-04).
            $query->where('created_at', '>=', now()->subDays(30));
        }

        $notificacoes = $query->paginate(15)->through(fn(DatabaseNotification $n) => $this->serializar($n));

        return Inertia::render('Notificacoes/Index', [
            'notificacoes' => $notificacoes,
            'aba'          => $aba,
        ]);
    }

    /**
     * Lista JSON das 10 notifications mais recentes do user autenticado (SINO-03).
     *
     * Consumido pelo dropdown do `<NotificationBell />` em modo lazy-fetch on-open
     * (Phase 10 D-04) — payload enxuto para evitar carga em todas as páginas.
     * Mistura unread + read (sem filtro de `read_at`): a UI marca visualmente
     * via bolinha amarela à esquerda quando `read_at` é null.
     */
    public function recentes(Request $request): JsonResponse
    {
        $notificacoes = $request->user()
            ->notifications()
            ->latest()
            ->take(10)
            ->get()
            ->map(fn(DatabaseNotification $n) => $this->serializar($n));

        return response()->json(['notificacoes' => $notificacoes]);
    }

    /**
     * Serializa uma notification para o payload canônico consumido pela UI
     * (sino + página de histórico) — id, read_at, created_at, data + autor_nome.
     *
     * `autor_nome` é resolvido a partir de `data.autor_user_id` (Phase 10 D-07)
     * — quando ausente, vira `null` e a UI mostra "Sistema". Eager-friendly:
     * para a lista de 10 do sino o overhead é trivial; para o paginator (15/p),
     * o `User::find` é cacheado pelo identity map do Eloquent.
     *
     * @return array<string, mixed>
     */
    private function serializar(DatabaseNotification $n): array
    {
        $autorId = $n->data['autor_user_id'] ?? null;

        return [
            'id'         => $n->id,
            'read_at'    => $n->read_at,
            'created_at' => $n->created_at,
            'data'       => $n->data,
            'autor_nome' => $autorId ? User::find($autorId)?->name : null,
        ];
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

    /**
     * Renderiza a página de envio manual de notificação (ENVIO-01 da Phase 12).
     *
     * Gating é via middleware `permission:notificacoes.criar` aplicado na rota
     * — admin sempre passa (short-circuit no `User::hasPermission`), líderes
     * passam via `AUTO_LIDERANCA`, e outros users só passam se o setor deles
     * tiver `notificacoes.criar` em `setor_permissoes`.
     *
     * Passa a lista de setores (ordenada por nome) como prop para popular o
     * select de público=setor na UI.
     */
    public function nova(Request $request): Response
    {
        return Inertia::render('Notificacoes/Nova', [
            'setores' => Setor::orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    /**
     * Processa o envio manual de notificação com targeting por público
     * (ENVIO-02/03/04/05 + POLL-05).
     *
     * Validation:
     *   - titulo: required, max 100 chars (ENVIO-03)
     *   - mensagem: required, max 1000 chars (ENVIO-03)
     *   - publico: required, in:usuario|setor|lideres|todos (ENVIO-02)
     *   - usuario_id: required quando publico=usuario, exists:users
     *   - setor_id: required quando publico=setor, exists:setores
     *
     * Resolução de destinatários (ENVIO-02):
     *   - usuario  → 1 User pelo id
     *   - setor    → todos os membros do setor (Setor::membros via pivot)
     *   - lideres  → todos os users que são líderes em pelo menos 1 setor
     *   - todos    → todos users com `active = true` (inclui o autor, sem
     *                exclusão self — decisão do plano D-04)
     *
     * Após `Notification::send`, registra entry em `activity_log` via helper
     * global `activity()` do `spatie/laravel-activitylog` (POLL-05). Apenas
     * disparos manuais logam — automáticos da Phase 11 não.
     *
     * Flash success contém a contagem exata de destinatários (ENVIO-04).
     */
    public function criar(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'titulo'     => 'required|string|max:100',
            'mensagem'   => 'required|string|max:1000',
            'publico'    => 'required|in:usuario,setor,lideres,todos',
            'usuario_id' => 'required_if:publico,usuario|exists:users,id',
            'setor_id'   => 'required_if:publico,setor|exists:setores,id',
        ]);

        // Resolve destinatários conforme o público escolhido (ENVIO-02).
        $destinatarios = match ($data['publico']) {
            'usuario' => User::where('id', $data['usuario_id'])->get(),
            'setor'   => Setor::find($data['setor_id'])->membros,
            'lideres' => User::whereHas('setoresLiderados')->get(),
            'todos'   => User::where('active', true)->get(),
        };

        $count = $destinatarios->count();

        if ($count > 0) {
            // Meta carrega o público + IDs específicos (quando aplicável) — útil
            // para auditoria via activity_log e para futuros filtros na UI.
            $meta = ['publico' => $data['publico']]
                + (isset($data['usuario_id']) ? ['usuario_id' => $data['usuario_id']] : [])
                + (isset($data['setor_id'])   ? ['setor_id'   => $data['setor_id']]   : []);

            Notification::send(
                $destinatarios,
                new ManualNotification(
                    titulo:      $data['titulo'],
                    mensagem:    $data['mensagem'],
                    autorUserId: $request->user()->id,
                    meta:        $meta,
                )
            );

            // Registra apenas envios MANUAIS no activity_log (POLL-05).
            // Disparos automáticos da Phase 11 NÃO logam — evita inundação.
            activity()
                ->causedBy($request->user())
                ->withProperties([
                    'publico'             => $data['publico'],
                    'usuario_id'          => $data['usuario_id'] ?? null,
                    'setor_id'            => $data['setor_id'] ?? null,
                    'destinatarios_count' => $count,
                    'titulo'              => $data['titulo'],
                ])
                ->log('Notificação manual enviada');
        }

        return back()->with('success', "Notificação enviada para {$count} destinatário(s).");
    }
}
