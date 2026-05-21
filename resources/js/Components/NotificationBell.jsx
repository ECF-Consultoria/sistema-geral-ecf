// Sino do header — Phase 10. Consome shared prop `notificacoes_nao_lidas`
// para o badge inicial; polling 60s no endpoint `/api/notificacoes/contador`
// (POLL-02) mantém o número fresco; dropdown faz lazy-fetch das 10 mais
// recentes via `/api/notificacoes/recentes` quando aberto (SINO-03/D-04).
//
// Decisões:
//   - badge oculto quando count === 0 (SINO-02)
//   - clicar em item unread chama `notificacoes.marcar-lida` via Inertia
//     com `preserveScroll`; decrementa contagem local sem reload (SINO-05)
//   - link "Ver todas" no rodapé navega para `/notificacoes` (SINO-06)
import { useState, useEffect } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

export default function NotificationBell() {
    const sharedCount = usePage().props.notificacoes_nao_lidas ?? 0;

    const [unreadCount, setUnreadCount] = useState(sharedCount);
    const [open, setOpen] = useState(false);
    const [notificacoes, setNotificacoes] = useState([]);
    const [loading, setLoading] = useState(false);

    // Sempre que a shared prop muda (navegação Inertia revalida), sincroniza
    // o estado local para refletir a contagem authoritative do backend.
    useEffect(() => {
        setUnreadCount(sharedCount);
    }, [sharedCount]);

    // Polling 60s no endpoint de contador (POLL-02) — só atualiza o badge,
    // não recarrega a lista do dropdown. Cleanup garante que o setInterval
    // não vaza quando o componente é desmontado.
    useEffect(() => {
        const id = setInterval(() => {
            fetch(route('notificacoes.contador'), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            })
                .then(r => r.ok ? r.json() : null)
                .then(json => {
                    if (json && typeof json.count === 'number') {
                        setUnreadCount(json.count);
                    }
                })
                .catch(() => { /* silencioso — polling não deve quebrar UI */ });
        }, 60000);

        return () => clearInterval(id);
    }, []);

    // Lazy-fetch das 10 mais recentes só quando o dropdown abre.
    useEffect(() => {
        if (!open) return;

        setLoading(true);
        fetch(route('notificacoes.recentes'), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(r => r.ok ? r.json() : { notificacoes: [] })
            .then(json => {
                setNotificacoes(json.notificacoes ?? []);
            })
            .catch(() => setNotificacoes([]))
            .finally(() => setLoading(false));
    }, [open]);

    // Marca uma notification como lida via Inertia. `preserveScroll` evita
    // pular pro topo; `onSuccess` decrementa otimisticamente o badge e atualiza
    // o item local para mostrar o estado lido na hora.
    const marcarComoLida = (n) => {
        if (n.read_at) return;
        router.patch(route('notificacoes.marcar-lida', n.id), {}, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setUnreadCount(c => Math.max(0, c - 1));
                setNotificacoes(items => items.map(item =>
                    item.id === n.id ? { ...item, read_at: new Date().toISOString() } : item
                ));
            },
        });
    };

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Notificações"
                    className="relative text-white/30 hover:text-white/60 p-1.5 rounded-lg hover:bg-white/[0.05] transition-colors"
                >
                    <Bell size={16} />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-ecf-yellow text-ecf-bg text-[10px] font-bold flex items-center justify-center">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 bg-ecf-card border-white/[0.08] p-0">
                <div className="px-3 py-2 border-b border-white/[0.06]">
                    <h3 className="text-sm font-bold text-white">Notificações</h3>
                </div>

                <div className="max-h-96 overflow-y-auto">
                    {loading && (
                        <div className="px-3 py-4 text-xs text-white/50">Carregando…</div>
                    )}
                    {!loading && notificacoes.length === 0 && (
                        <div className="px-3 py-4 text-xs text-white/50">Nenhuma notificação.</div>
                    )}
                    {!loading && notificacoes.map((n) => (
                        <button
                            key={n.id}
                            type="button"
                            onClick={() => marcarComoLida(n)}
                            className={cn(
                                'w-full text-left px-3 py-2 border-b border-white/[0.04] hover:bg-white/[0.03] flex gap-2',
                                !n.read_at && 'bg-white/[0.02]'
                            )}
                        >
                            <span
                                className={cn(
                                    'mt-1.5 w-1.5 h-1.5 rounded-full shrink-0',
                                    !n.read_at ? 'bg-ecf-yellow' : 'bg-transparent'
                                )}
                            />
                            <div className="flex-1 min-w-0">
                                <div className="text-xs font-bold text-white truncate">
                                    {n.data?.titulo}
                                </div>
                                <div className="text-[11px] text-white/60 truncate">
                                    {n.data?.mensagem}
                                </div>
                                <div className="text-[10px] text-white/40 mt-0.5">
                                    {n.autor_nome ? `${n.autor_nome} · ` : ''}
                                    {formatDistanceToNow(new Date(n.created_at), { addSuffix: true, locale: ptBR })}
                                </div>
                            </div>
                        </button>
                    ))}
                </div>

                <div className="px-3 py-2 border-t border-white/[0.06]">
                    <Link
                        href={route('notificacoes.index')}
                        className="text-xs text-ecf-yellow hover:underline"
                    >
                        Ver todas
                    </Link>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
