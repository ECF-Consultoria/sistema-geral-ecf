import AppLayout from '@/Layouts/AppLayout';
import { Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef, useCallback } from 'react';
import { ArrowLeft, Copy, Check, Trash2, History, KeyRound, ShieldCheck } from 'lucide-react';
import {
    StatusBadge, ServicoIcone, Avatar, RingCountdown, Info, fmtCodigo, copyToClipboard,
} from '@/Components/Autenticadores/common';

// ─── Página: código ao vivo de uma conta ────────────────────────────────────

export default function Show({ autenticador }) {
    const { csrf_token } = usePage().props;

    const [codigo, setCodigo] = useState(null);
    const [deadline, setDeadline] = useState(0);
    const [periodo, setPeriodo] = useState(autenticador?.periodo || 30);
    const [, setAgora] = useState(Date.now()); // força o tick da contagem
    const [copiado, setCopiado] = useState(false);
    const [erro, setErro] = useState(null);
    const [histAberto, setHistAberto] = useState(false);
    const refreshRef = useRef(null);
    const seqRef = useRef(0);

    const buscarCodigo = useCallback(async (id) => {
        const seq = ++seqRef.current;
        const sentAt = performance.now();
        try {
            const { data } = await window.axios.get(route('autenticadores.codigo', id));
            if (seq !== seqRef.current) return;
            const recv = performance.now();
            setCodigo(data.code);
            setPeriodo(data.period);
            setDeadline(performance.now() - (recv - sentAt) / 2 + data.remaining_ms);
            setErro(null);
            const wait = Math.max(data.remaining_ms - (recv - sentAt) / 2, 0) + 200;
            clearTimeout(refreshRef.current);
            refreshRef.current = setTimeout(() => buscarCodigo(id), wait);
        } catch {
            if (seq !== seqRef.current) return;
            setCodigo(null);
            setErro('Falha ao obter o código.');
        }
    }, []);

    useEffect(() => {
        if (autenticador) buscarCodigo(autenticador.id);
        return () => clearTimeout(refreshRef.current);
    }, [autenticador, buscarCodigo]);

    useEffect(() => {
        const t = setInterval(() => setAgora(Date.now()), 250);
        return () => clearInterval(t);
    }, []);

    const remainingMs = Math.max(deadline - performance.now(), 0);
    const seconds = codigo ? Math.ceil(remainingMs / 1000) : 0;
    const fraction = codigo ? remainingMs / (periodo * 1000) : 0;

    const copiar = async () => {
        if (!codigo) return;
        const ok = await copyToClipboard(codigo);
        if (ok) {
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
            window.axios.post(route('autenticadores.copiar', autenticador.id), {}, { headers: { 'X-CSRF-TOKEN': csrf_token } }).catch(() => {});
        }
    };

    const remover = () => {
        if (confirm(`Remover "${autenticador.cliente}"? O secret será apagado.`)) {
            router.delete(route('autenticadores.destroy', autenticador.id));
        }
    };

    return (
        <AppLayout title={autenticador.cliente}>
            <div className="max-w-xl mx-auto space-y-5">
                <Link href={route('autenticadores.index')} className="inline-flex items-center gap-1.5 text-sm text-white/50 hover:text-white/90 transition">
                    <ArrowLeft size={16} /> Voltar para a lista
                </Link>

                <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5 space-y-5">
                    <div className="flex items-center justify-between">
                        <h1 className="text-white font-semibold">Detalhes do autenticador</h1>
                        <button
                            onClick={remover}
                            className="inline-flex items-center gap-1.5 text-[12px] text-white/50 hover:text-red-300 transition"
                        >
                            <Trash2 size={14} /> Remover
                        </button>
                    </div>

                    <div className="flex items-center gap-3">
                        <Avatar texto={autenticador.cliente} className="w-11 h-11 text-[15px]" />
                        <div className="min-w-0">
                            <p className="text-white font-semibold text-lg leading-tight overflow-wrap-anywhere">{autenticador.cliente}</p>
                            <p className="text-white/50 text-[13px] break-all">{autenticador.conta}</p>
                        </div>
                        <div className="ml-auto"><StatusBadge status={autenticador.status} /></div>
                    </div>

                    <div className="grid grid-cols-2 gap-3 text-[13px]">
                        <Info rotulo="Serviço"><span className="inline-flex items-center gap-1.5"><ServicoIcone servico={autenticador.servico} /> {autenticador.servico}</span></Info>
                        <Info rotulo="Criado em">{autenticador.criado_em || '—'}</Info>
                        <Info rotulo="Atualização">{autenticador.atualizado_em || '—'}</Info>
                    </div>

                    {/* Código ao vivo */}
                    <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-4">
                        <div className="flex items-center gap-2 text-[12px] text-white/50 mb-2">
                            <KeyRound size={14} className="text-ecf-yellow" /> Código de autenticação (TOTP)
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <div className="font-mono text-[40px] leading-none font-semibold text-white tabular-nums tracking-wider">
                                {erro ? '—' : fmtCodigo(codigo)}
                            </div>
                            <RingCountdown seconds={seconds} fraction={fraction} />
                        </div>
                        {erro
                            ? <p className="text-red-300/80 text-[12px] mt-2">{erro}</p>
                            : <p className="text-white/40 text-[12px] mt-2">Expira em <span className="text-white/70 font-medium">{seconds}s</span></p>}
                    </div>

                    <div className="flex gap-2">
                        <button
                            onClick={copiar}
                            disabled={!codigo}
                            className="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-40"
                        >
                            {copiado ? <><Check size={16} /> Copiado</> : <><Copy size={16} /> Copiar código</>}
                        </button>
                        <button
                            onClick={() => setHistAberto((v) => !v)}
                            className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border border-white/[0.1] text-white/80 font-medium text-sm hover:bg-white/[0.03] transition"
                        >
                            <History size={16} /> Histórico
                        </button>
                    </div>

                    <p className="text-[11px] text-white/30 flex items-center gap-1.5"><ShieldCheck size={12} /> O secret nunca é exibido.</p>

                    {histAberto && <Historico id={autenticador.id} />}
                </div>
            </div>
        </AppLayout>
    );
}

function Historico({ id }) {
    const [acessos, setAcessos] = useState(null);
    useEffect(() => {
        let vivo = true;
        window.axios.get(route('autenticadores.historico', id))
            .then(({ data }) => { if (vivo) setAcessos(data.acessos); })
            .catch(() => { if (vivo) setAcessos([]); });
        return () => { vivo = false; };
    }, [id]);

    if (acessos === null) return <p className="text-white/40 text-[12px]">Carregando histórico…</p>;
    if (acessos.length === 0) return <p className="text-white/40 text-[12px]">Sem acessos registrados.</p>;
    return (
        <div className="border-t border-white/[0.06] pt-3 space-y-2 max-h-56 overflow-y-auto">
            {acessos.map((a, i) => (
                <div key={i} className="flex items-center justify-between text-[12px]">
                    <span className="text-white/70">{a.usuario} · {a.descricao}</span>
                    <span className="text-white/35">{a.created_at}</span>
                </div>
            ))}
        </div>
    );
}
