// Cartão fixo no canto inferior direito: avisa quem ABRIU o ticket que houve
// movimento nele (resposta da equipe, status, resolvido…). Só o solicitante
// recebe — a lista vem de /api/notificacoes/tickets, que filtra no servidor
// pelas notificações do próprio usuário. O cartão fica até a pessoa abrir o
// ticket ou fechar o aviso: o sino sozinho passava despercebido.
import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { MessageSquareText, X } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { ptBR } from 'date-fns/locale';

const INTERVALO_MS = 30000;

export default function AvisoTicketRespondido() {
    const [avisos, setAvisos] = useState([]);
    const [total, setTotal] = useState(0);

    const carregar = useCallback(() => {
        fetch(route('notificacoes.tickets'), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((json) => {
                if (!json) return;
                setAvisos(json.avisos ?? []);
                setTotal(json.total ?? 0);
            })
            .catch(() => { /* silencioso — aviso não pode quebrar a página */ });
    }, []);

    useEffect(() => {
        carregar();
        const id = setInterval(carregar, INTERVALO_MS);
        return () => clearInterval(id);
    }, [carregar]);

    // Tira da tela na hora; o servidor marca como lida em seguida.
    const marcarLida = (aviso) => {
        setAvisos((lista) => lista.filter((a) => a.id !== aviso.id));
        setTotal((t) => Math.max(0, t - 1));
        return window.axios
            .patch(route('notificacoes.marcar-lida', aviso.id), {}, { headers: { Accept: 'application/json' } })
            .catch(() => { /* se falhar, o próximo polling traz de volta */ });
    };

    const abrir = (aviso) => {
        marcarLida(aviso).finally(() => router.visit(aviso.url));
    };

    if (avisos.length === 0) return null;

    const restantes = total - avisos.length;

    return (
        <div className="flex w-[340px] max-w-[calc(100vw-2.5rem)] flex-col gap-2">
            {avisos.map((a) => (
                <div
                    key={a.id}
                    role="alert"
                    className="animate-in fade-in slide-in-from-bottom-2 rounded-xl border border-ecf-yellow/30 bg-ecf-card/95 p-3 shadow-2xl backdrop-blur-md"
                >
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-ecf-yellow/15 text-ecf-yellow">
                            <MessageSquareText size={16} />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="text-[13px] font-bold text-white">{a.titulo}</div>
                            {a.mensagem && <div className="truncate text-[12px] text-white/60">{a.mensagem}</div>}
                            <div className="mt-0.5 text-[11px] text-white/40">
                                {a.autor_nome ? `${a.autor_nome} · ` : ''}
                                {formatDistanceToNow(new Date(a.created_at), { addSuffix: true, locale: ptBR })}
                            </div>
                            <button
                                type="button"
                                onClick={() => abrir(a)}
                                className="mt-2 rounded-lg bg-ecf-yellow px-3 py-1.5 text-[12px] font-bold text-ecf-bg transition-opacity hover:opacity-90"
                            >
                                Abrir ticket
                            </button>
                        </div>
                        <button
                            type="button"
                            onClick={() => marcarLida(a)}
                            aria-label="Fechar aviso"
                            title="Fechar (marca como lido)"
                            className="shrink-0 rounded p-0.5 text-white/40 transition-colors hover:text-white"
                        >
                            <X size={14} />
                        </button>
                    </div>
                </div>
            ))}
            {restantes > 0 && (
                <div className="text-right text-[11px] text-white/50">
                    + {restantes} aviso{restantes === 1 ? '' : 's'} de ticket no sino
                </div>
            )}
        </div>
    );
}
