import AppLayout from '@/Layouts/AppLayout';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import {
    Search, Plus, Copy, Check, Trash2, ShieldCheck, QrCode, Link2,
    History, X, Loader2, KeyRound, ChevronDown,
} from 'lucide-react';
import { cn } from '@/lib/utils';

// ─── Constantes de UI ───────────────────────────────────────────────────────
const STATUS_LABELS = { ativo: 'Ativo', expirando: 'Expirando', inativo: 'Inativo' };
const STATUS_BADGE = {
    ativo:     'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    expirando: 'bg-amber-500/15 text-amber-300 border-amber-500/30',
    inativo:   'bg-zinc-500/15 text-zinc-300 border-zinc-500/30',
};

// Ícone do serviço: usa os SVGs que o projeto já serve em /images quando houver,
// senão a inicial do serviço num quadradinho.
const SERVICO_ICON = {
    'mercado livre': '/images/mercado-livre-87.svg',
    shopee:          '/images/shopee-icon.svg',
    amazon:          '/images/icons8-amazon.svg',
};

const iniciais = (texto) => (texto || '?').trim().slice(0, 2).toUpperCase();
const antesDoArroba = (conta) => (conta || '').split('@')[0];

// ─── Helper de clipboard (mesmo padrão de Sugadores/Index) ──────────────────
const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            return true;
        }
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
    } catch {
        return false;
    }
};

const fmtCodigo = (c) => (c ? `${c.slice(0, Math.floor(c.length / 2))} ${c.slice(Math.floor(c.length / 2))}` : '•••  •••');

// ─── Componentes locais ─────────────────────────────────────────────────────

function StatusBadge({ status }) {
    return (
        <span className={cn('inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold border', STATUS_BADGE[status] || STATUS_BADGE.inativo)}>
            <span className="w-1.5 h-1.5 rounded-full bg-current" />
            {STATUS_LABELS[status] || status}
        </span>
    );
}

function ServicoIcone({ servico }) {
    const src = SERVICO_ICON[(servico || '').toLowerCase()];
    if (src) {
        return <img src={src} alt="" className="w-5 h-5 object-contain" />;
    }
    return (
        <span className="w-5 h-5 rounded bg-white/10 text-[10px] font-bold text-white/70 flex items-center justify-center">
            {iniciais(servico)}
        </span>
    );
}

function Avatar({ texto }) {
    return (
        <span className="w-9 h-9 rounded-lg bg-ecf-yellow/[0.12] border border-ecf-yellow/20 text-ecf-yellow text-[13px] font-bold flex items-center justify-center shrink-0">
            {iniciais(texto)}
        </span>
    );
}

// Anel de contagem regressiva (SVG). `fraction` 1→0.
function RingCountdown({ seconds, fraction }) {
    const r = 26;
    const c = 2 * Math.PI * r;
    const low = seconds <= 5;
    return (
        <div className="relative w-16 h-16 shrink-0">
            <svg viewBox="0 0 64 64" className="w-16 h-16 -rotate-90">
                <circle cx="32" cy="32" r={r} fill="none" stroke="currentColor" strokeWidth="5" className="text-white/10" />
                <circle
                    cx="32" cy="32" r={r} fill="none" strokeWidth="5" strokeLinecap="round"
                    stroke="currentColor"
                    className={cn('transition-[stroke-dashoffset] duration-500 ease-linear', low ? 'text-amber-400' : 'text-ecf-yellow')}
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - Math.max(fraction, 0))}
                />
            </svg>
            <span className={cn('absolute inset-0 flex items-center justify-center text-[13px] font-semibold tabular-nums', low ? 'text-amber-400' : 'text-white')}>
                {seconds}s
            </span>
        </div>
    );
}

// ─── Página ─────────────────────────────────────────────────────────────────

export default function Index({ autenticadores = [], filtros = {}, servicos = [], responsaveis = [], ultimosAcessos = [] }) {
    const { csrf_token } = usePage().props;

    // Busca/filtros — client-side (a lista inteira vem nas props; é um cofre interno,
    // não paginação pesada). Mantém os jeitos de buscar: cliente, parte antes do @,
    // número no domínio e serviço.
    const [q, setQ] = useState(filtros.q || '');
    const [fServico, setFServico] = useState(filtros.servico || '');
    const [fResp, setFResp] = useState(filtros.responsavel || '');
    const [fStatus, setFStatus] = useState(filtros.status || '');

    const lista = useMemo(() => {
        const termo = q.trim().toLowerCase();
        return autenticadores.filter((a) => {
            if (fServico && a.servico !== fServico) return false;
            if (fResp && String(a.responsavel_id || '') !== String(fResp)) return false;
            if (fStatus && a.status !== fStatus) return false;
            if (!termo) return true;
            return [a.cliente, a.conta, a.servico, a.issuer, antesDoArroba(a.conta)]
                .filter(Boolean)
                .some((v) => String(v).toLowerCase().includes(termo));
        });
    }, [autenticadores, q, fServico, fResp, fStatus]);

    const [selId, setSelId] = useState(autenticadores[0]?.id ?? null);
    useEffect(() => {
        // Se o selecionado saiu da lista filtrada, seleciona o primeiro visível.
        if (!lista.some((a) => a.id === selId)) setSelId(lista[0]?.id ?? null);
    }, [lista, selId]);
    const selecionado = useMemo(() => autenticadores.find((a) => a.id === selId) || null, [autenticadores, selId]);

    const [addAberto, setAddAberto] = useState(false);

    return (
        <AppLayout title="Autenticadores 2FA">
            <div className="max-w-7xl mx-auto space-y-6">
                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h1 className="text-2xl font-bold text-white flex items-center gap-2">
                            <ShieldCheck size={24} className="text-ecf-yellow" />
                            Autenticadores 2FA
                        </h1>
                        <p className="text-white/40 text-sm mt-1">Gerencie os códigos de autenticação das contas com segurança.</p>
                    </div>
                    <button
                        onClick={() => setAddAberto((v) => !v)}
                        className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition"
                    >
                        <Plus size={18} /> Adicionar autenticador
                    </button>
                </div>

                {/* Busca + filtros */}
                <div className="flex gap-3 flex-wrap">
                    <div className="relative flex-1 min-w-[240px]">
                        <Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-white/30" />
                        <input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Pesquisar cliente, e-mail, domínio ou serviço…"
                            className="w-full pl-10 pr-3 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm placeholder:text-white/30 focus:outline-none focus:border-ecf-yellow/40"
                        />
                    </div>
                    <FiltroSelect value={fServico} onChange={setFServico} placeholder="Serviço" options={servicos.map((s) => ({ value: s, label: s }))} />
                    <FiltroSelect value={fResp} onChange={setFResp} placeholder="Responsável" options={responsaveis.map((r) => ({ value: String(r.id), label: r.name }))} />
                    <FiltroSelect value={fStatus} onChange={setFStatus} placeholder="Status" options={Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label }))} />
                </div>

                {addAberto && (
                    <NovoAutenticador csrf={csrf_token} responsaveis={responsaveis} onClose={() => setAddAberto(false)} />
                )}

                {/* Lista + Detalhe */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 rounded-xl border border-white/[0.08] bg-ecf-card overflow-hidden">
                        <div className="flex items-center gap-2 px-5 py-4 border-b border-white/[0.06]">
                            <h2 className="text-white font-semibold">Lista de autenticadores</h2>
                            <span className="text-[11px] text-white/40 bg-white/5 rounded-full px-2 py-0.5">{lista.length} registros</span>
                        </div>
                        <ListaTabela lista={lista} selId={selId} onSelect={setSelId} />
                    </div>

                    <Detalhe autenticador={selecionado} csrf={csrf_token} />
                </div>

                {/* Últimos acessos */}
                <UltimosAcessos acessos={ultimosAcessos} />
            </div>
        </AppLayout>
    );
}

// ─── Lista (tabela) ─────────────────────────────────────────────────────────

function ListaTabela({ lista, selId, onSelect }) {
    if (lista.length === 0) {
        return <div className="px-5 py-12 text-center text-white/40 text-sm">Nenhum autenticador encontrado.</div>;
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-white/40 text-[12px] uppercase tracking-wide">
                        <th className="text-left font-medium px-5 py-3">Cliente</th>
                        <th className="text-left font-medium px-3 py-3">Conta</th>
                        <th className="text-left font-medium px-3 py-3">Serviço</th>
                        <th className="text-left font-medium px-3 py-3">Responsável</th>
                        <th className="text-left font-medium px-3 py-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    {lista.map((a) => (
                        <tr
                            key={a.id}
                            onClick={() => onSelect(a.id)}
                            className={cn(
                                'border-t border-white/[0.05] cursor-pointer transition',
                                a.id === selId ? 'bg-ecf-yellow/[0.06]' : 'hover:bg-white/[0.02]',
                            )}
                        >
                            <td className="px-5 py-3">
                                <div className="flex items-center gap-3">
                                    <Avatar texto={a.cliente} />
                                    <span className="text-white font-medium">{a.cliente}</span>
                                </div>
                            </td>
                            <td className="px-3 py-3 text-white/60">{a.conta}</td>
                            <td className="px-3 py-3">
                                <span className="inline-flex items-center gap-2 text-white/80">
                                    <ServicoIcone servico={a.servico} /> {a.servico}
                                </span>
                            </td>
                            <td className="px-3 py-3 text-white/60">{a.responsavel || '—'}</td>
                            <td className="px-3 py-3"><StatusBadge status={a.status} /></td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Detalhe (código ao vivo) ───────────────────────────────────────────────

function Detalhe({ autenticador, csrf }) {
    const [codigo, setCodigo] = useState(null);
    const [deadline, setDeadline] = useState(0);
    const [periodo, setPeriodo] = useState(30);
    const [agora, setAgora] = useState(Date.now());
    const [copiado, setCopiado] = useState(false);
    const [erro, setErro] = useState(null);
    const [histAberto, setHistAberto] = useState(false);
    const refreshRef = useRef(null);
    const tickRef = useRef(null);
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
        } catch (e) {
            if (seq !== seqRef.current) return;
            setCodigo(null);
            setErro('Falha ao obter o código.');
        }
    }, []);

    useEffect(() => {
        clearTimeout(refreshRef.current);
        seqRef.current++;
        setCodigo(null);
        setErro(null);
        setHistAberto(false);
        if (autenticador) buscarCodigo(autenticador.id);
        return () => clearTimeout(refreshRef.current);
    }, [autenticador, buscarCodigo]);

    useEffect(() => {
        tickRef.current = setInterval(() => setAgora(Date.now()), 250);
        return () => clearInterval(tickRef.current);
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
            window.axios.post(route('autenticadores.copiar', autenticador.id), {}, { headers: { 'X-CSRF-TOKEN': csrf } }).catch(() => {});
        }
    };

    if (!autenticador) {
        return (
            <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-6 flex items-center justify-center text-white/30 text-sm min-h-[320px]">
                Selecione um autenticador.
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5 space-y-5">
            <div className="flex items-center justify-between">
                <h2 className="text-white font-semibold">Detalhes do autenticador</h2>
                <button
                    onClick={() => { if (confirm(`Remover "${autenticador.cliente}"? O secret será apagado.`)) router.delete(route('autenticadores.destroy', autenticador.id), { preserveScroll: true }); }}
                    className="inline-flex items-center gap-1.5 text-[12px] text-white/50 hover:text-red-300 transition"
                >
                    <Trash2 size={14} /> Remover
                </button>
            </div>

            <div className="flex items-center gap-3">
                <Avatar texto={autenticador.cliente} />
                <div className="min-w-0">
                    <p className="text-white font-semibold leading-tight">{autenticador.cliente}</p>
                    <p className="text-white/50 text-[13px] truncate">{autenticador.conta}</p>
                </div>
                <div className="ml-auto"><StatusBadge status={autenticador.status} /></div>
            </div>

            <div className="grid grid-cols-2 gap-3 text-[13px]">
                <Info rotulo="Serviço"><span className="inline-flex items-center gap-1.5"><ServicoIcone servico={autenticador.servico} /> {autenticador.servico}</span></Info>
                <Info rotulo="Responsável">{autenticador.responsavel || '—'}</Info>
                <Info rotulo="Criado em">{autenticador.criado_em || '—'}</Info>
                <Info rotulo="Atualização">{autenticador.atualizado_em || '—'}</Info>
            </div>

            {/* Código ao vivo */}
            <div className="rounded-lg border border-white/[0.08] bg-white/[0.02] p-4">
                <div className="flex items-center gap-2 text-[12px] text-white/50 mb-2">
                    <KeyRound size={14} className="text-ecf-yellow" /> Código de autenticação (TOTP)
                </div>
                <div className="flex items-center justify-between gap-3">
                    <div className="font-mono text-[34px] leading-none font-semibold text-white tabular-nums tracking-wider">
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
    );
}

function Info({ rotulo, children }) {
    return (
        <div>
            <p className="text-white/35 text-[11px] uppercase tracking-wide">{rotulo}</p>
            <p className="text-white/85 mt-0.5">{children}</p>
        </div>
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
        <div className="border-t border-white/[0.06] pt-3 space-y-2 max-h-48 overflow-y-auto">
            {acessos.map((a, i) => (
                <div key={i} className="flex items-center justify-between text-[12px]">
                    <span className="text-white/70">{a.usuario} · {a.descricao}</span>
                    <span className="text-white/35">{a.created_at}</span>
                </div>
            ))}
        </div>
    );
}

// ─── Últimos acessos (global) ───────────────────────────────────────────────

function UltimosAcessos({ acessos }) {
    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5">
            <h2 className="text-white font-semibold mb-4">Últimos acessos</h2>
            {acessos.length === 0
                ? <p className="text-white/40 text-sm">Nenhum acesso registrado ainda.</p>
                : (
                    <div className="space-y-3">
                        {acessos.map((a, i) => (
                            <div key={i} className="flex items-center gap-3 text-sm">
                                <span className="w-8 h-8 rounded-full bg-white/[0.04] flex items-center justify-center shrink-0">
                                    {a.acao === 'copiou' ? <Copy size={14} className="text-white/50" /> : <History size={14} className="text-white/50" />}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-white/80 truncate">
                                        <span className="font-medium">{a.usuario}</span> {a.descricao?.toLowerCase()}
                                        {a.cliente && <span className="text-white/50"> · {a.cliente}</span>}
                                    </p>
                                </div>
                                <span className="text-white/35 text-[12px] shrink-0">{a.created_at}</span>
                            </div>
                        ))}
                    </div>
                )}
        </div>
    );
}

// ─── Novo autenticador ──────────────────────────────────────────────────────

function NovoAutenticador({ csrf, responsaveis, onClose }) {
    const [modo, setModo] = useState('uri'); // 'uri' | 'qr'
    const [qrMsg, setQrMsg] = useState(null);
    const dropRef = useRef(null);

    const form = useForm({ uri: '', cliente: '', conta: '', servico: '', responsavel_id: '', secret: '' });

    const temUri = form.data.uri.trim() !== '';

    const submeter = (e) => {
        e.preventDefault();
        form.post(route('autenticadores.store'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); onClose(); },
        });
    };

    // Decodifica QR de uma imagem com a BarcodeDetector nativa (Chrome/Edge).
    const lerQr = async (file) => {
        setQrMsg(null);
        if (!('BarcodeDetector' in window)) {
            setQrMsg('Leitura de QR não suportada neste navegador — use "Colar URI".');
            setModo('uri');
            return;
        }
        try {
            const bitmap = await createImageBitmap(file);
            const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            const codes = await detector.detect(bitmap);
            const raw = codes.find((c) => /^otpauth/i.test(c.rawValue || ''))?.rawValue;
            if (!raw) {
                setQrMsg('Nenhum QR otpauth:// encontrado na imagem.');
                return;
            }
            form.setData('uri', raw);
            setModo('uri');
            setQrMsg('QR lido. Confira e clique em Adicionar.');
        } catch {
            setQrMsg('Não foi possível ler a imagem.');
        }
    };

    const onDrop = (e) => {
        e.preventDefault();
        const file = e.dataTransfer.files?.[0];
        if (file) lerQr(file);
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card p-5">
            <div className="flex items-center justify-between mb-1">
                <h2 className="text-white font-semibold">Novo autenticador</h2>
                <button onClick={onClose} className="text-white/40 hover:text-white/70"><X size={18} /></button>
            </div>
            <p className="text-white/40 text-[13px] mb-4">Importe por QR Code, cole a URI (uma ou várias contas) ou informe os dados manualmente.</p>

            {form.errors.uri && <p className="text-red-300 text-[13px] mb-3">{form.errors.uri}</p>}

            <form onSubmit={submeter} className="grid grid-cols-1 md:grid-cols-2 gap-5">
                {/* Coluna esquerda: QR / URI */}
                <div>
                    <div className="inline-flex rounded-lg border border-white/[0.1] p-1 mb-3">
                        <TabBtn ativo={modo === 'qr'} onClick={() => setModo('qr')} icon={QrCode}>Ler QR Code</TabBtn>
                        <TabBtn ativo={modo === 'uri'} onClick={() => setModo('uri')} icon={Link2}>Colar URI</TabBtn>
                    </div>

                    {modo === 'qr' ? (
                        <div
                            ref={dropRef}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={onDrop}
                            className="rounded-lg border-2 border-dashed border-white/[0.12] p-6 text-center"
                        >
                            <QrCode size={36} className="mx-auto text-white/25 mb-2" />
                            <label className="text-ecf-yellow text-sm font-medium cursor-pointer hover:underline">
                                Escolher imagem do QR Code
                                <input type="file" accept="image/*" className="hidden" onChange={(e) => e.target.files?.[0] && lerQr(e.target.files[0])} />
                            </label>
                            <p className="text-white/30 text-[12px] mt-1">ou arraste uma imagem aqui (JPG, PNG, WEBP)</p>
                            {qrMsg && <p className="text-white/60 text-[12px] mt-2">{qrMsg}</p>}
                        </div>
                    ) : (
                        <>
                            <textarea
                                value={form.data.uri}
                                onChange={(e) => form.setData('uri', e.target.value)}
                                rows={5}
                                spellCheck={false}
                                placeholder="otpauth://totp/…  ou  otpauth-migration://offline?data=…"
                                className="w-full px-3 py-2.5 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-[13px] font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40 resize-none"
                                style={{ WebkitTextSecurity: temUri ? 'disc' : 'none' }}
                            />
                            <p className="text-white/30 text-[12px] mt-1">A URI de "Transferir contas" traz várias contas de uma vez. O secret vai cifrado e não reaparece.</p>
                        </>
                    )}
                </div>

                {/* Coluna direita: manual */}
                <div className={cn('space-y-3', temUri && 'opacity-40 pointer-events-none')}>
                    <div className="text-white/30 text-[12px] uppercase tracking-wide">ou preencha manualmente</div>
                    <Campo rotulo="Cliente / loja" valor={form.data.cliente} onChange={(v) => form.setData('cliente', v)} placeholder="Ex.: Loja Prime" />
                    <Campo rotulo="E-mail ou identificação" valor={form.data.conta} onChange={(v) => form.setData('conta', v)} placeholder="financeiro@loja.com" />
                    <Campo rotulo="Serviço" valor={form.data.servico} onChange={(v) => form.setData('servico', v)} placeholder="Google, Amazon, Mercado Livre…" />
                    <div>
                        <label className="text-white/50 text-[12px] font-medium">Secret TOTP</label>
                        <input
                            value={form.data.secret}
                            onChange={(e) => form.setData('secret', e.target.value)}
                            spellCheck={false}
                            placeholder="abcd efgh ijkl mnop …"
                            className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm font-mono placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
                            style={{ WebkitTextSecurity: form.data.secret ? 'disc' : 'none' }}
                        />
                    </div>
                </div>

                {/* Responsável + submit (linha inteira) */}
                <div className="md:col-span-2 flex flex-wrap items-end gap-3 pt-1 border-t border-white/[0.06]">
                    <div className="min-w-[200px]">
                        <label className="text-white/50 text-[12px] font-medium">Responsável</label>
                        <div className="relative mt-1">
                            <select
                                value={form.data.responsavel_id}
                                onChange={(e) => form.setData('responsavel_id', e.target.value)}
                                className="w-full appearance-none px-3 py-2 pr-8 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm focus:outline-none focus:border-ecf-yellow/40"
                            >
                                <option value="" className="bg-ecf-card">Sem responsável</option>
                                {responsaveis.map((r) => <option key={r.id} value={r.id} className="bg-ecf-card">{r.name}</option>)}
                            </select>
                            <ChevronDown size={16} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
                        </div>
                    </div>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="ml-auto inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-ecf-yellow text-black font-semibold text-sm hover:brightness-105 transition disabled:opacity-50"
                    >
                        {form.processing ? <Loader2 size={16} className="animate-spin" /> : <Plus size={16} />} Adicionar autenticador
                    </button>
                </div>
            </form>
        </div>
    );
}

function TabBtn({ ativo, onClick, icon: Icon, children }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-[13px] font-medium transition', ativo ? 'bg-white/10 text-white' : 'text-white/50 hover:text-white/80')}
        >
            <Icon size={15} /> {children}
        </button>
    );
}

function Campo({ rotulo, valor, onChange, placeholder }) {
    return (
        <div>
            <label className="text-white/50 text-[12px] font-medium">{rotulo}</label>
            <input
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="w-full mt-1 px-3 py-2 rounded-lg bg-white/[0.03] border border-white/[0.1] text-white text-sm placeholder:text-white/25 focus:outline-none focus:border-ecf-yellow/40"
            />
        </div>
    );
}

function FiltroSelect({ value, onChange, placeholder, options }) {
    return (
        <div className="relative">
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="appearance-none pl-3 pr-9 py-2.5 rounded-lg bg-ecf-card border border-white/[0.08] text-white text-sm focus:outline-none focus:border-ecf-yellow/40 min-w-[150px]"
            >
                <option value="" className="bg-ecf-card">{placeholder}: todos</option>
                {options.map((o) => <option key={o.value} value={o.value} className="bg-ecf-card">{o.label}</option>)}
            </select>
            <ChevronDown size={16} className="absolute right-2.5 top-1/2 -translate-y-1/2 text-white/30 pointer-events-none" />
        </div>
    );
}
