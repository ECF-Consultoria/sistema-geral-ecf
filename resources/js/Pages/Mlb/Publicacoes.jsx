import AppLayout from '@/Layouts/AppLayout';
import { useForm, router, usePage } from '@inertiajs/react';
import { useState, useMemo } from 'react';
import {
    ChevronDown, ChevronUp, ExternalLink, CheckCircle2,
    Circle, PlusCircle, Target, Zap, Clock, TrendingUp,
    AlertTriangle, RefreshCw, X, CheckCheck, MessageSquare, CalendarClock, Copy, Check, BookUser
} from 'lucide-react';
import { cn } from '@/lib/utils';

/* ── helpers ── */
function fmt(n)    { return Number(n ?? 0).toLocaleString('pt-BR'); }
function fmtDec(n) { return Number(n ?? 0).toFixed(1).replace('.', ','); }
function mlbUrl(c) { return `https://produto.mercadolivre.com.br/${c.replace('MLB', 'MLB-')}`; }

const MLB_RE = /\bMLB[\s\-]?(\d{7,13})\b|\b(\d{9,13})\b/gi;
function extractMlbs(text) {
    const found = []; const seen = new Set(); let m;
    while ((m = MLB_RE.exec(text)) !== null) {
        const code = (m[1] || m[2] || '').trim();
        if (code.length < 9 || code.length > 13) continue;
        const mlb = 'MLB' + code;
        if (!seen.has(mlb)) { seen.add(mlb); found.push(mlb); }
    }
    MLB_RE.lastIndex = 0;
    return found;
}

const PRIO_COLOR = {
    '1 Urgente': 'text-red-400 border-red-500/30 bg-red-500/10',
    '2 Alto':    'text-orange-400 border-orange-500/30 bg-orange-500/10',
    '3 Média':   'text-yellow-400 border-yellow-500/30 bg-yellow-500/10',
};

/* ── KPI mini card ── */
function KpiCard({ title, value, sub, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text: 'text-ecf-yellow', bg: 'bg-ecf-yellow/10', border: 'border-ecf-yellow/20' },
        green:  { text: 'text-emerald-400', bg: 'bg-emerald-500/10', border: 'border-emerald-500/20' },
        orange: { text: 'text-orange-400', bg: 'bg-orange-500/10', border: 'border-orange-500/20' },
        purple: { text: 'text-purple-400', bg: 'bg-purple-500/10', border: 'border-purple-500/20' },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-2xl p-4">
            <div className="flex items-center justify-between mb-2">
                <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
                <div className={cn('w-7 h-7 rounded-lg flex items-center justify-center border', c.bg, c.border)}>
                    <Icon size={13} className={c.text} />
                </div>
            </div>
            <p className={cn('font-display font-extrabold text-2xl', c.text)}>{value}</p>
            {sub && <p className="text-white/30 text-[11px] mt-0.5">{sub}</p>}
        </div>
    );
}

/* ── Modal Sync Vendas ── */
function SyncVendasModal({ empresa, onClose }) {
    const today = new Date().toISOString().slice(0, 10);
    const { data, setData, post, processing, errors } = useForm({
        date_from: today,
        date_to:   today,
    });

    function submit(e) {
        e.preventDefault();
        post(route('mlb.empresas.sync-vendas', empresa.id), { onSuccess: onClose });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h2 className="text-white font-bold text-base">Sincronizar Vendas</h2>
                        <p className="text-white/40 text-[12px] mt-0.5">{empresa.nome}</p>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>
                <p className="text-white/50 text-[12px] mb-4 leading-relaxed">
                    Consulta a API Adman e marca como <strong className="text-white/80">vendido</strong> os anúncios com venda no período.
                </p>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">De</label>
                            <input type="date" value={data.date_from} max={today}
                                onChange={e => setData('date_from', e.target.value)}
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                            {errors.date_from && <p className="text-red-400 text-xs mt-1">{errors.date_from}</p>}
                        </div>
                        <div>
                            <label className="text-white/50 text-[11px] uppercase tracking-wide font-semibold block mb-1">Até</label>
                            <input type="date" value={data.date_to} max={today}
                                onChange={e => setData('date_to', e.target.value)}
                                className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[13px] focus:outline-none focus:border-ecf-yellow/40" />
                            {errors.date_to && <p className="text-red-400 text-xs mt-1">{errors.date_to}</p>}
                        </div>
                    </div>
                    {errors.api && <p className="text-red-400 text-[12px] bg-red-500/10 rounded-lg px-3 py-2">{errors.api}</p>}
                    <div className="flex gap-3 pt-1">
                        <button type="submit" disabled={processing}
                            className="h-9 px-5 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] disabled:opacity-50 hover:bg-ecf-yellow/90 transition-colors flex items-center gap-2">
                            <RefreshCw size={13} className={processing ? 'animate-spin' : ''} />
                            {processing ? 'Sincronizando…' : 'Sincronizar'}
                        </button>
                        <button type="button" onClick={onClose}
                            className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/* ── Modal Marcar Problema ── */
function ProblemaModal({ pub, onClose }) {
    const { data, setData, patch, processing } = useForm({ problema_nota: pub.problema_nota ?? '' });

    function submit(e) {
        e.preventDefault();
        patch(route('mlb.problema', pub.id), { onSuccess: onClose });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-white font-bold text-base flex items-center gap-2">
                        <AlertTriangle size={16} className="text-red-400" />
                        {pub.problema ? 'Remover problema' : 'Marcar com problema'}
                    </h2>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>
                <p className="text-white/50 text-[12px] mb-3">
                    {pub.problema
                        ? 'Clique em confirmar para remover o problema desta publicação.'
                        : 'Descreva o problema desta conta (conta suspensa, sem imagem, etc).'}
                </p>
                <form onSubmit={submit} className="space-y-3">
                    {!pub.problema && (
                        <textarea
                            value={data.problema_nota}
                            onChange={e => setData('problema_nota', e.target.value)}
                            rows={3}
                            placeholder="Ex: Conta suspensa desde 01/05..."
                            className="w-full px-3 py-2 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-red-400/40 resize-none placeholder:text-white/20"
                        />
                    )}
                    <div className="flex gap-3">
                        <button type="submit" disabled={processing}
                            className={cn(
                                'h-9 px-5 rounded-xl font-bold text-[13px] disabled:opacity-50 transition-colors',
                                pub.problema
                                    ? 'bg-white/10 text-white hover:bg-white/20'
                                    : 'bg-red-500 text-white hover:bg-red-600'
                            )}>
                            {processing ? 'Salvando…' : pub.problema ? 'Remover problema' : 'Confirmar problema'}
                        </button>
                        <button type="button" onClick={onClose}
                            className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

/* ── Modal Marcar Problema na Empresa (conta suspensa, etc.) ── */
function ProblemaEmpresaModal({ empresa, onClose }) {
    const [nota, setNota] = useState(empresa.problema_nota ?? '');
    const [processing, setProcessing] = useState(false);

    function send(acao) {
        setProcessing(true);
        const payload = acao === 'remover' ? { acao } : { problema_nota: nota, acao };
        router.patch(route('mlb.empresas.problema', empresa.id), payload, {
            preserveScroll: true,
            onSuccess: () => { setProcessing(false); onClose(); },
            onError:   () => setProcessing(false),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm">
            <div className="card-ecf rounded-2xl w-full max-w-sm p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-white font-bold text-base flex items-center gap-2">
                        <AlertTriangle size={16} className="text-red-400" />
                        {empresa.problema ? 'Editar problema da conta' : 'Reportar problema na conta'}
                    </h2>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-white/30 hover:text-white/70 transition-colors">
                        <X size={16} />
                    </button>
                </div>

                <p className="text-white/60 text-[13px] font-medium mb-3">{empresa.nome}</p>

                {empresa.problema && empresa.problema_em && (
                    <p className="text-red-400/50 text-[11px] mb-2">Registrado em {empresa.problema_em}</p>
                )}

                <div className="space-y-3">
                    <textarea
                        value={nota}
                        onChange={e => setNota(e.target.value)}
                        rows={3}
                        placeholder="Ex: Conta suspensa pelo Mercado Livre desde 02/05…"
                        className="w-full px-3 py-2 rounded-xl border border-red-500/30 bg-red-500/5 text-white text-[12px] focus:outline-none focus:border-red-400/50 resize-none placeholder:text-white/20"
                    />
                    <div className="flex gap-3 flex-wrap">
                        {empresa.problema ? (
                            <>
                                <button onClick={() => send('editar')} disabled={processing}
                                    className="h-9 px-5 rounded-xl bg-red-500 text-white font-bold text-[13px] disabled:opacity-50 hover:bg-red-600 transition-colors">
                                    {processing ? 'Salvando…' : 'Salvar edição'}
                                </button>
                                <button onClick={() => send('remover')} disabled={processing}
                                    className="h-9 px-5 rounded-xl bg-white/10 text-white font-bold text-[13px] disabled:opacity-50 hover:bg-white/20 transition-colors">
                                    Remover problema
                                </button>
                            </>
                        ) : (
                            <button onClick={() => send('marcar')} disabled={processing}
                                className="h-9 px-5 rounded-xl bg-red-500 text-white font-bold text-[13px] disabled:opacity-50 hover:bg-red-600 transition-colors">
                                {processing ? 'Salvando…' : 'Confirmar problema'}
                            </button>
                        )}
                        <button type="button" onClick={onClose}
                            className="h-9 px-5 rounded-xl border border-white/[0.08] text-white/60 text-[13px] hover:text-white transition-colors">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ── Formulário inline de registro de MLBs por empresa/SKU ── */
function MlbRegisterForm({ empresa, skuStage, skuPosition, skuNome, registrados, onClose }) {
    const today = new Date().toISOString().slice(0, 10);
    const { data, setData, post, processing, errors } = useForm({
        data:           today,
        empresa:        empresa.nome,
        cust_id:        empresa.cust_id ?? '',
        codigos:        '',
        mlb_empresa_id: empresa.id,
        tipo:           'anuncio',
        sku_stage:      skuStage,
        sku_position:   skuPosition,
    });

    const registradosSet = useMemo(() => new Set(registrados ?? []), [registrados]);
    const detectados = useMemo(() => extractMlbs(data.codigos), [data.codigos]);
    const novos      = useMemo(() => detectados.filter(m => !registradosSet.has(m)), [detectados, registradosSet]);
    const dups       = detectados.length - novos.length;

    function submit(e) {
        e.preventDefault();
        post(route('mlb.store'), { onSuccess: onClose });
    }

    return (
        <div className="mt-3 rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/5 p-4">
            <p className="text-ecf-yellow text-[12px] font-bold mb-3">
                Registrar MLBs — SKU: <span className="text-white">{skuNome || `Estágio ${skuStage} · #${skuPosition + 1}`}</span>
            </p>
            <form onSubmit={submit} className="space-y-3">
                <div className="flex items-center gap-3">
                    <input type="date" value={data.data} max={today}
                        onChange={e => setData('data', e.target.value)}
                        className="h-8 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />

                    {/* Toggle Anúncio / Variação */}
                    <div className="flex rounded-lg border border-white/[0.08] overflow-hidden text-[11px] font-bold">
                        <button type="button"
                            onClick={() => setData('tipo', 'anuncio')}
                            className={cn(
                                'px-3 h-8 transition-colors',
                                data.tipo === 'anuncio'
                                    ? 'bg-ecf-yellow text-[#252525]'
                                    : 'bg-white/[0.03] text-white/50 hover:text-white/80'
                            )}>
                            Anúncio
                        </button>
                        <button type="button"
                            onClick={() => setData('tipo', 'variacao')}
                            className={cn(
                                'px-3 h-8 transition-colors border-l border-white/[0.08]',
                                data.tipo === 'variacao'
                                    ? 'bg-sky-500 text-white'
                                    : 'bg-white/[0.03] text-white/50 hover:text-white/80'
                            )}>
                            Variação
                        </button>
                    </div>
                </div>
                {data.tipo === 'variacao' && (
                    <p className="text-sky-400/70 text-[11px] leading-relaxed">
                        Variações rastreiam vendas mas não contam como anúncio feito nos KPIs.
                    </p>
                )}

                <textarea
                    value={data.codigos}
                    onChange={e => setData('codigos', e.target.value)}
                    rows={4}
                    placeholder={"Cole os códigos MLB aqui\nMLB1234567890\nMLB2345678901\nou só os números"}
                    className="w-full px-3 py-2 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] font-mono focus:outline-none focus:border-ecf-yellow/40 resize-none placeholder:text-white/20"
                />

                {data.codigos.trim() && (
                    <div className="flex gap-4 text-[12px]">
                        <span className="text-emerald-400 font-bold">{novos.length} novos</span>
                        {dups > 0 && <span className="text-yellow-400">{dups} duplicados ignorados</span>}
                    </div>
                )}

                {errors.codigos && <p className="text-red-400 text-[11px]">{errors.codigos}</p>}

                <div className="flex gap-2">
                    <button type="submit" disabled={processing || novos.length === 0}
                        className={cn(
                            'h-8 px-4 rounded-lg font-bold text-[12px] disabled:opacity-40 transition-colors',
                            data.tipo === 'variacao'
                                ? 'bg-sky-500 text-white hover:bg-sky-400'
                                : 'bg-ecf-yellow text-[#252525] hover:bg-ecf-yellow/90'
                        )}>
                        {processing ? 'Salvando…' : data.tipo === 'variacao'
                            ? `Registrar ${novos.length} variação(ões)`
                            : `Registrar ${novos.length} anúncio(s)`}
                    </button>
                    <button type="button" onClick={onClose}
                        className="h-8 px-4 rounded-lg border border-white/[0.08] text-white/50 text-[12px] hover:text-white transition-colors">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    );
}

/* ── Card de empresa com SKUs ── */
function prazoInfo(prazoStr) {
    if (!prazoStr) return null;
    const prazo = new Date(prazoStr + 'T00:00:00');
    const hoje  = new Date(); hoje.setHours(0, 0, 0, 0);
    const diff  = Math.ceil((prazo - hoje) / 86400000);
    const label = prazo.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' });
    return { diff, label, vencido: diff < 0, urgente: diff >= 0 && diff <= 7 };
}

function PrazoBadge({ prazoStr, label }) {
    const info = prazoInfo(prazoStr);
    if (!info) return null;
    if (info.vencido) return (
        <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-red-500/20 border-red-500/40 text-red-400">
            <Clock size={9} /> Vencido · {info.label}
        </span>
    );
    if (info.urgente) return (
        <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-orange-500/15 border-orange-500/30 text-orange-400">
            <Clock size={9} /> {info.diff === 0 ? 'Hoje' : `${info.diff}d`} · {info.label}
        </span>
    );
    return (
        <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border border-white/[0.08] text-white/40">
            <Clock size={9} /> {label ?? 'Prazo'} {info.label}
        </span>
    );
}

function CopyGmail({ gmail }) {
    const [copied, setCopied] = useState(false);

    function handleCopy(e) {
        e.stopPropagation();
        navigator.clipboard.writeText(gmail).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    }

    return (
        <span className="flex items-center gap-1 shrink-0">
            <span className="text-white/40 text-[11px]">{gmail}</span>
            <button
                onClick={handleCopy}
                title="Copiar Gmail"
                className={cn(
                    'flex items-center justify-center w-5 h-5 rounded-md border transition-colors',
                    copied
                        ? 'border-emerald-500/40 bg-emerald-500/15 text-emerald-400'
                        : 'border-white/[0.08] text-white/25 hover:text-white/60 hover:border-white/20'
                )}
            >
                {copied ? <Check size={9} /> : <Copy size={9} />}
            </button>
        </span>
    );
}

function EmpresaCard({ empresa, mlbsRegistrados, onSync, onProblemaEmpresa, showResponsavel = false, appUrl = '' }) {
    const [expanded, setExpanded] = useState(false);
    const [registerFor, setRegisterFor] = useState(null);

    const { ok, total, pct } = empresa.progresso;
    const concluida = empresa.estagio === 'Concluido';
    const isAssessoria = (empresa.tipo ?? 'POLO') === 'ASSESSORIA';
    const temProblema = !!empresa.problema;
    const temAtrasado = [1,2,3].some(s => (empresa[`skus_estagio${s}`] ?? []).some(sk => sk.atrasado));

    const prazos = {
        1: empresa.prazo_estagio1,
        2: empresa.prazo_estagio2,
        3: empresa.prazo_estagio3,
    };

    // Estágio atual → índice do prazo relevante
    const stageAtual = { 'Estágio 1': 1, 'Estágio 2': 2, 'Estágio 3': 3 }[empresa.estagio] ?? null;
    const prazoAtual = stageAtual ? prazos[stageAtual] : null;
    const diasUrgencia = minDiasPendente(empresa);

    function handleMarcarSku(stage, position, ok) {
        router.patch(route('mlb.empresas.sku', empresa.id), { stage, position, ok },
            { preserveScroll: true });
    }

    function handleSkuClick(stage, idx, novoOk) {
        handleMarcarSku(stage, idx, novoOk);
    }

    function toggleRegister(stage, position) {
        const key = `${stage}-${position}`;
        setRegisterFor(prev => prev === key ? null : key);
    }

    const barColor = concluida ? '#22c55e' : pct >= 80 ? '#eab308' : '#8b5cf6';

    return (
        <div className={cn(
            'card-ecf rounded-2xl overflow-hidden',
            concluida && 'opacity-70',
            temProblema && 'ring-1 ring-red-500/40'
        )}>
            {/* Banner de problema da conta — visível sem expandir */}
            {temProblema && (
                <div className="flex items-start gap-2 px-4 pt-3 pb-0">
                    <div className="flex-1 flex items-start gap-2 rounded-xl border border-red-500/30 bg-red-500/10 px-3 py-2">
                        <AlertTriangle size={13} className="text-red-400 shrink-0 mt-0.5" />
                        <div className="flex-1 min-w-0">
                            <span className="text-red-300 text-[12px] font-semibold">Conta com problema: </span>
                            <span className="text-red-300 text-[12px]">{empresa.problema_nota || 'Sem descrição'}</span>
                            {empresa.problema_em && (
                                <span className="text-red-400/50 text-[11px] ml-2">({empresa.problema_em})</span>
                            )}
                        </div>
                        <button
                            onClick={ev => { ev.stopPropagation(); onProblemaEmpresa(empresa); }}
                            className="text-red-400/60 hover:text-red-300 text-[11px] underline shrink-0 transition-colors"
                        >
                            Editar
                        </button>
                    </div>
                </div>
            )}

            {/* Header clicável */}
            <button
                className="w-full flex items-center gap-4 p-4 text-left hover:bg-white/[0.02] transition-colors"
                onClick={() => setExpanded(e => !e)}
            >
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap mb-1.5">
                        <span className="text-white font-semibold text-[14px]">{empresa.nome}</span>
                        {empresa.cust_id && <span className="text-white/30 text-[11px] font-mono">{empresa.cust_id}</span>}
                        {empresa.gmail && <CopyGmail gmail={empresa.gmail} />}
                        {isAssessoria && (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-blue-500/15 text-blue-400 border-blue-500/30">
                                ASSESSORIA
                            </span>
                        )}
                        {showResponsavel && empresa.responsavel && (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold border bg-white/[0.05] text-white/50 border-white/[0.08]">
                                {empresa.responsavel}
                            </span>
                        )}
                        {empresa.estagio && (
                            <span className={cn('px-2 py-0.5 rounded-full text-[10px] font-bold border',
                                concluida
                                    ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                    : 'bg-purple-500/15 text-purple-400 border-purple-500/30'
                            )}>
                                {empresa.estagio}
                            </span>
                        )}
                        {empresa.fase && (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-sky-500/15 text-sky-400 border-sky-500/30">
                                {empresa.fase}
                            </span>
                        )}
                        {empresa.prioridade && PRIO_COLOR[empresa.prioridade] && (
                            <span className={cn('px-2 py-0.5 rounded-full text-[10px] font-bold border', PRIO_COLOR[empresa.prioridade])}>
                                {empresa.prioridade}
                            </span>
                        )}
                        {concluida && (
                            <span className="px-2 py-0.5 rounded-full text-[10px] font-bold border bg-emerald-500/15 text-emerald-400 border-emerald-500/30">
                                ✓ Concluído
                            </span>
                        )}
                        {/* Prazo do estágio atual — destaque no header */}
                        {prazoAtual && !concluida && (
                            <PrazoBadge prazoStr={prazoAtual} label={`Est.${stageAtual}`} />
                        )}
                        {temAtrasado && (
                            <span title="Possui SKU(s) concluído(s) fora do prazo"
                                className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold border bg-orange-500/10 border-orange-500/25 text-orange-400">
                                <Clock size={9} /> Atrasado
                            </span>
                        )}
                    </div>
                    {/* Barra de progresso + urgência */}
                    <div className="flex items-center gap-3">
                        <div className="flex-1 max-w-[200px] h-1.5 bg-white/10 rounded-full overflow-hidden">
                            <div style={{ width: `${pct}%`, background: barColor }} className="h-full rounded-full transition-all" />
                        </div>
                        <span className="text-white/40 text-[11px]">{ok}/{total} SKUs · {empresa.mlbs_count} MLBs</span>
                        {diasUrgencia !== null && !concluida && (
                            <span className={cn('text-[10px] font-semibold px-1.5 py-0.5 rounded-md border shrink-0',
                                diasUrgencia < 0  ? 'text-red-400 border-red-500/30 bg-red-500/10' :
                                diasUrgencia === 0 ? 'text-orange-400 border-orange-500/30 bg-orange-500/10' :
                                diasUrgencia <= 7  ? 'text-yellow-400 border-yellow-500/30 bg-yellow-500/10' :
                                'text-white/30 border-white/[0.08]'
                            )}>
                                {diasUrgencia < 0 ? `${Math.abs(diasUrgencia)}d vencido` :
                                 diasUrgencia === 0 ? 'Vence hoje' :
                                 `${diasUrgencia}d`}
                            </span>
                        )}
                        {/* Botão Reportar Problema da Conta */}
                        <button
                            onClick={ev => { ev.stopPropagation(); onProblemaEmpresa(empresa); }}
                            className={cn(
                                'flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-semibold border transition-colors',
                                temProblema
                                    ? 'bg-red-500/20 border-red-500/40 text-red-400 hover:bg-red-500/30'
                                    : 'border-white/[0.06] text-white/20 hover:text-red-400 hover:border-red-500/30'
                            )}
                        >
                            <AlertTriangle size={9} />
                            {temProblema ? 'Conta c/ problema' : 'Reportar conta'}
                        </button>
                        {empresa.cust_id && (
                            <button
                                onClick={ev => { ev.stopPropagation(); onSync(empresa); }}
                                className="flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-semibold border border-ecf-yellow/20 text-ecf-yellow/60 hover:text-ecf-yellow hover:bg-ecf-yellow/10 transition-colors"
                            >
                                <RefreshCw size={10} /> Sync
                            </button>
                        )}
                        {empresa.implementacao_token && (
                            <a
                                href={`${appUrl}/implementacao/${empresa.implementacao_token}/publicador`}
                                target="_blank"
                                rel="noreferrer"
                                onClick={ev => ev.stopPropagation()}
                                title="Link do Publicador"
                                className="p-1.5 rounded-lg text-white/30 hover:text-violet-400 hover:bg-violet-500/10 transition-colors"
                            >
                                <BookUser size={14} />
                            </a>
                        )}
                    </div>
                </div>
                {expanded ? <ChevronUp size={16} className="text-white/30 shrink-0" /> : <ChevronDown size={16} className="text-white/30 shrink-0" />}
            </button>

            {/* Estágios + SKUs expandidos */}
            {expanded && (
                <div className="border-t border-white/[0.06] p-4 space-y-4">
                    {empresa.contexto && (
                        <p className="text-white/40 text-[12px] italic">📝 {empresa.contexto}</p>
                    )}

                    {[1, 2, 3].map(stage => {
                        const skus = empresa[`skus_estagio${stage}`] ?? [];
                        const filled = skus.filter(s => (s.sku ?? '').trim() !== '');
                        if (filled.length === 0) return null;

                        const doneCount = filled.filter(s => s.ok).length;

                        return (
                            <div key={stage}>
                                <div className="flex items-center justify-between mb-2">
                                    <p className="text-white/50 text-[11px] font-semibold uppercase tracking-wide">
                                        Estágio {stage} · {doneCount}/{filled.length} concluídos
                                    </p>
                                    {prazos[stage] && (
                                        <PrazoBadge prazoStr={prazos[stage]} label="Prazo" />
                                    )}
                                </div>
                                <div className="space-y-2">
                                    {skus.map((sku, idx) => {
                                        if (!(sku.sku ?? '').trim()) return null;
                                        const key = `${stage}-${idx}`;
                                        const isReg = registerFor === key;
                                        const skuMlbs = (mlbsRegistrados ?? []).filter(
                                            m => m.mlb_empresa_id === empresa.id &&
                                                 m.sku_stage === stage &&
                                                 m.sku_position === idx
                                        );

                                        return (
                                            <div key={idx} className={cn(
                                                'rounded-xl border p-3',
                                                sku.ok
                                                    ? 'border-emerald-500/20 bg-emerald-500/5'
                                                    : 'border-white/[0.06] bg-white/[0.02]'
                                            )}>
                                                <div className="flex items-center gap-3">
                                                    <button
                                                        onClick={() => handleSkuClick(stage, idx, !sku.ok)}
                                                        className="shrink-0 transition-colors"
                                                        title={sku.ok ? 'Desmarcar' : 'Marcar como concluído'}
                                                    >
                                                        {sku.ok
                                                            ? <CheckCircle2 size={20} className="text-emerald-400" />
                                                            : <Circle size={20} className="text-white/25 hover:text-white/60" />
                                                        }
                                                    </button>
                                                    <div className="flex-1 min-w-0">
                                                        <span className={cn('text-[13px] font-medium block',
                                                            sku.ok ? 'text-emerald-400 line-through' : 'text-white')}>
                                                            {sku.sku}
                                                        </span>
                                                        {sku.ok && sku.concluido_em && (
                                                            <span className={cn('text-[10px]', sku.atrasado ? 'text-red-400' : 'text-white/30')}>
                                                                {sku.atrasado ? '⚠ Fora do prazo · ' : ''}
                                                                Concluído {new Date(sku.concluido_em).toLocaleDateString('pt-BR')}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="flex items-center gap-2 shrink-0">
                                                        {skuMlbs.length > 0 && (
                                                            <span className="text-white/40 text-[11px]">
                                                                {skuMlbs.length} MLB(s)
                                                            </span>
                                                        )}
                                                        {!sku.ok && (
                                                            <button
                                                                onClick={() => toggleRegister(stage, idx)}
                                                                className={cn(
                                                                    'flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-semibold border transition-colors',
                                                                    isReg
                                                                        ? 'bg-ecf-yellow/15 border-ecf-yellow/30 text-ecf-yellow'
                                                                        : 'border-white/[0.08] text-white/50 hover:text-white hover:border-white/20'
                                                                )}
                                                            >
                                                                <PlusCircle size={12} />
                                                                {isReg ? 'Fechar' : 'Registrar MLBs'}
                                                            </button>
                                                        )}
                                                    </div>
                                                </div>

                                                {skuMlbs.length > 0 && (
                                                    <div className="mt-2 flex flex-wrap gap-1.5 pl-8">
                                                        {skuMlbs.map(m => (
                                                            <a key={m.id} href={mlbUrl(m.mlb_code)} target="_blank" rel="noopener noreferrer"
                                                                className="flex items-center gap-1 px-2 py-0.5 rounded-full bg-purple-500/10 border border-purple-500/20 text-purple-300 text-[11px] font-mono hover:text-purple-200 transition-colors">
                                                                {m.mlb_code} <ExternalLink size={9} />
                                                            </a>
                                                        ))}
                                                    </div>
                                                )}

                                                {isReg && (
                                                    <MlbRegisterForm
                                                        empresa={empresa}
                                                        skuStage={stage}
                                                        skuPosition={idx}
                                                        skuNome={sku.sku}
                                                        registrados={(mlbsRegistrados ?? []).map(m => m.mlb_code)}
                                                        onClose={() => setRegisterFor(null)}
                                                    />
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

        </div>
    );
}

/* ── Página principal ── */
/* ── Registro Livre — MLBs sem empresa vinculada ao sistema ── */
function RegistroLivreForm({ registrados }) {
    const today = new Date().toISOString().slice(0, 10);
    const { data, setData, post, processing, errors, reset } = useForm({
        data:    today,
        empresa: '',
        cust_id: '',
        codigos: '',
    });

    const registradosSet = new Set(registrados ?? []);
    const detectados = extractMlbs(data.codigos);
    const novos      = detectados.filter(m => !registradosSet.has(m));

    function submit(e) {
        e.preventDefault();
        post(route('mlb.store'), { onSuccess: () => reset('empresa', 'cust_id', 'codigos') });
    }

    return (
        <div className="card-ecf rounded-2xl p-5 border border-white/[0.06]">
            <p className="text-white font-semibold text-sm mb-0.5">Registro Livre</p>
            <p className="text-white/30 text-[12px] mb-4">Para MLBs de contas não cadastradas no sistema</p>
            <form onSubmit={submit} className="space-y-3">
                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <label className="text-white/40 text-[11px] uppercase tracking-wide block mb-1">Data</label>
                        <input type="date" value={data.data} max={today}
                            onChange={e => setData('data', e.target.value)}
                            className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40" />
                    </div>
                    <div>
                        <label className="text-white/40 text-[11px] uppercase tracking-wide block mb-1">Empresa *</label>
                        <input type="text" value={data.empresa} onChange={e => setData('empresa', e.target.value)}
                            placeholder="Nome da empresa"
                            className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                        {errors.empresa && <p className="text-red-400 text-[11px] mt-0.5">{errors.empresa}</p>}
                    </div>
                    <div>
                        <label className="text-white/40 text-[11px] uppercase tracking-wide block mb-1">Cust ID</label>
                        <input type="text" value={data.cust_id} onChange={e => setData('cust_id', e.target.value)}
                            placeholder="opcional"
                            className="w-full h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 placeholder:text-white/20" />
                    </div>
                </div>
                <textarea value={data.codigos} onChange={e => setData('codigos', e.target.value)} rows={4}
                    placeholder={"MLB1234567890\nMLB2345678901\n\nou separados por vírgula/espaço"}
                    className="w-full px-3 py-2.5 rounded-xl border border-white/[0.08] bg-white/[0.03] text-white text-[12px] font-mono focus:outline-none focus:border-ecf-yellow/40 resize-none placeholder:text-white/15" />
                {data.codigos.trim() && (
                    <p className="text-[12px]">
                        <span className="text-emerald-400 font-bold">{novos.length} novos</span>
                        {detectados.length - novos.length > 0 && (
                            <span className="text-yellow-400 ml-3">{detectados.length - novos.length} duplicados ignorados</span>
                        )}
                    </p>
                )}
                {errors.codigos && <p className="text-red-400 text-[11px]">{errors.codigos}</p>}
                <button type="submit" disabled={processing || novos.length === 0 || !data.empresa.trim()}
                    className="h-9 px-5 rounded-xl bg-ecf-yellow text-[#252525] font-bold text-[13px] disabled:opacity-40 hover:bg-ecf-yellow/90 transition-colors">
                    {processing ? 'Salvando…' : `Registrar ${novos.length} anúncio(s)`}
                </button>
            </form>
        </div>
    );
}

// Retorna o menor número de dias até o prazo de um estágio com SKUs pendentes
function minDiasPendente(empresa) {
    let min = Infinity;
    const hoje = new Date(); hoje.setHours(0, 0, 0, 0);
    for (let s = 1; s <= 3; s++) {
        const prazo = empresa[`prazo_estagio${s}`];
        if (!prazo) continue;
        const skus = empresa[`skus_estagio${s}`] ?? [];
        if (!skus.filter(sk => (sk.sku ?? '').trim()).some(sk => !sk.ok)) continue;
        const dias = Math.ceil((new Date(prazo + 'T00:00:00') - hoje) / 86400000);
        if (dias < min) min = dias;
    }
    return min === Infinity ? null : min;
}

const FILTRO_LIMITE = { hoje: 0, '7d': 7, '14d': 14, '30d': 30 };
const FILTRO_LABELS = { hoje: 'hoje', '7d': '7 dias', '14d': '14 dias', '30d': '30 dias' };

export default function Publicacoes({ kpis, hoje, ultimos, meta, empresas, isAdmin = false, estagiosOpts = [], fasesOpts = [], projetosOpts = [] }) {
    const { props } = usePage();
    const appUrl = props.asset_url ?? '';
    const [syncEmpresa, setSyncEmpresa] = useState(null);
    const [problemaModal, setProblemaModal] = useState(null);
    const [problemaEmpresaModal, setProblemaEmpresaModal] = useState(null);
    const [showLivre, setShowLivre] = useState(false);
    const [filtroEstagio, setFiltroEstagio] = useState('');
    const [filtroFase, setFiltroFase] = useState('');
    const [filtroProjeto, setFiltroProjeto] = useState('');
    const [filtroBusca, setFiltroBusca] = useState('');
    const [filtroData, setFiltroData] = useState('todos');
    const [filtroResp, setFiltroResp] = useState('');

    const k = kpis ?? {};

    // Publicadores disponíveis (para admin filtrar por responsável)
    const publicadoresMap = {};
    (empresas ?? []).forEach(e => {
        if (e.responsavel_id && e.responsavel) publicadoresMap[e.responsavel_id] = e.responsavel;
    });
    const publicadoresDisponiveis = Object.entries(publicadoresMap).map(([id, nome]) => ({ id, nome }));

    // Opções de estágio/fase/projeto filtradas pelo responsável selecionado (cascata)
    const empresasDoResp = useMemo(() =>
        filtroResp
            ? (empresas ?? []).filter(e => String(e.responsavel_id) === String(filtroResp))
            : (empresas ?? []),
        [empresas, filtroResp]
    );
    const estagiosDisponiveis = useMemo(() =>
        [...new Set(empresasDoResp.map(e => e.estagio).filter(Boolean))].sort(),
        [empresasDoResp]
    );
    const fasesDisponiveis = useMemo(() =>
        [...new Set(empresasDoResp.map(e => e.fase).filter(Boolean))].sort(),
        [empresasDoResp]
    );
    const projetosDisponiveis = useMemo(() =>
        [...new Set(empresasDoResp.map(e => e.projeto).filter(Boolean))].sort(),
        [empresasDoResp]
    );

    const temFiltros = estagiosDisponiveis.length > 0 || fasesDisponiveis.length > 0 || projetosDisponiveis.length > 0;
    const filtroAtivo = filtroEstagio || filtroFase || filtroProjeto || filtroBusca || filtroResp;

    // Ao trocar responsável, limpa filtros de estágio/fase/projeto que saíram das opções
    function handleFiltroResp(newResp) {
        const base = newResp
            ? (empresas ?? []).filter(e => String(e.responsavel_id) === String(newResp))
            : (empresas ?? []);
        const novosEstagios = new Set(base.map(e => e.estagio).filter(Boolean));
        const novasFases    = new Set(base.map(e => e.fase).filter(Boolean));
        const novosProjetos = new Set(base.map(e => e.projeto).filter(Boolean));
        setFiltroResp(newResp);
        if (filtroEstagio && !novosEstagios.has(filtroEstagio)) setFiltroEstagio('');
        if (filtroFase    && !novasFases.has(filtroFase))       setFiltroFase('');
        if (filtroProjeto && !novosProjetos.has(filtroProjeto)) setFiltroProjeto('');
    }

    // Filtragem base (estagio/fase/projeto/responsavel/busca)
    let empresasParaView = (empresas ?? []).filter(e => {
        if (filtroEstagio && e.estagio !== filtroEstagio) return false;
        if (filtroFase    && e.fase    !== filtroFase)    return false;
        if (filtroProjeto && e.projeto !== filtroProjeto) return false;
        if (filtroResp    && String(e.responsavel_id) !== String(filtroResp)) return false;
        if (filtroBusca   && !e.nome.toLowerCase().includes(filtroBusca.toLowerCase())) return false;
        return true;
    });

    // Filtro de prazo: só mostra empresas com SKUs pendentes dentro do limite
    const filtragemPrazoAtiva = filtroData !== 'todos';
    if (filtragemPrazoAtiva) {
        const limite = FILTRO_LIMITE[filtroData];
        empresasParaView = empresasParaView.filter(e => {
            const min = minDiasPendente(e);
            return min !== null && min <= limite;
        });
    }

    // Ordenação: quando filtro de prazo ativo → mais urgente primeiro
    const empresasOrdenadas = filtragemPrazoAtiva
        ? [...empresasParaView].sort((a, b) => (minDiasPendente(a) ?? 999) - (minDiasPendente(b) ?? 999))
        : empresasParaView;

    const pendentes  = filtragemPrazoAtiva
        ? empresasOrdenadas
        : empresasOrdenadas.filter(e => e.estagio !== 'Concluido');
    const concluidas = filtragemPrazoAtiva
        ? []
        : empresasOrdenadas.filter(e => e.estagio === 'Concluido');
    const temEmpresas = (empresas ?? []).length > 0;

    function handleResolverComentario(pub) {
        router.patch(route('mlb.resolver', pub.id), {}, { preserveScroll: true });
    }

    function handleResolverProblema(pub) {
        router.patch(route('mlb.problema', pub.id), {}, { preserveScroll: true });
    }

    return (
        <AppLayout title="Publicação">
            <div className="mb-6">
                <h1 className="text-white font-display font-bold text-2xl">Publicação</h1>
                <p className="text-white/40 text-sm mt-0.5">
                    {isAdmin
                        ? 'Visão geral de todas as empresas e publicadores'
                        : 'Registre MLBs por empresa e marque os SKUs conforme concluir'}
                </p>
            </div>

            {/* Barra de filtros */}
            <div className="card-ecf rounded-2xl p-3 mb-4 flex gap-2 flex-wrap items-center">
                <input
                    type="text"
                    value={filtroBusca}
                    onChange={e => setFiltroBusca(e.target.value)}
                    placeholder="Buscar empresa…"
                    className="h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none placeholder:text-white/20 min-w-[180px]"
                />
                {temFiltros && (<>
                    {estagiosDisponiveis.length > 0 && (
                        <select value={filtroEstagio} onChange={e => setFiltroEstagio(e.target.value)}
                            className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer">
                            <option value="">Todos os estágios</option>
                            {estagiosDisponiveis.map(v => <option key={v} value={v}>{v}</option>)}
                        </select>
                    )}
                    {fasesDisponiveis.length > 0 && (
                        <select value={filtroFase} onChange={e => setFiltroFase(e.target.value)}
                            className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer">
                            <option value="">Todos os status</option>
                            {fasesDisponiveis.map(v => <option key={v} value={v}>{v}</option>)}
                        </select>
                    )}
                    {projetosDisponiveis.length > 0 && (
                        <select value={filtroProjeto} onChange={e => setFiltroProjeto(e.target.value)}
                            className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer">
                            <option value="">Todos os projetos</option>
                            {projetosDisponiveis.map(v => <option key={v} value={v}>{v}</option>)}
                        </select>
                    )}
                </>)}
                {filtroAtivo && (
                    <button onClick={() => { setFiltroEstagio(''); setFiltroFase(''); setFiltroProjeto(''); setFiltroBusca(''); setFiltroResp(''); }}
                        className="h-8 px-3 rounded-lg border border-white/[0.08] text-white/40 text-[11px] hover:text-white transition-colors flex items-center gap-1">
                        <X size={11} /> Limpar
                    </button>
                )}
                <span className="text-white/25 text-[11px] ml-auto">
                    {empresasParaView.length} de {(empresas ?? []).length} empresa{(empresas ?? []).length !== 1 ? 's' : ''}
                </span>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
                <KpiCard title="Hoje"         value={fmt(hoje)}                            sub="anúncios registrados"          icon={Zap}        color="yellow" />
                <KpiCard title="Feito no Mês" value={fmt(k.feito)}                         sub={`${fmtDec(k.percentual)}% da meta`} icon={CheckCircle2} color="green" />
                <KpiCard title="Faltam"       value={fmt(k.faltantes)}                     sub={`${k.dias_uteis_restantes} dias úteis`} icon={Clock} color="orange" />
                <KpiCard title="Ritmo Alvo"   value={`${fmtDec(k.media_diaria_alvo)}/dia`} sub="para bater a meta"             icon={TrendingUp} color="purple" />
            </div>

            {/* Filtros de prazo */}
            <div className="card-ecf rounded-2xl p-3 mb-5 flex items-center gap-2 flex-wrap">
                <CalendarClock size={13} className="text-white/30 shrink-0" />
                <span className="text-white/30 text-[11px] font-semibold uppercase tracking-wide mr-1">Prazo</span>
                {[
                    { key: 'todos', label: 'Todos' },
                    { key: 'hoje',  label: 'Hoje' },
                    { key: '7d',    label: '7 dias' },
                    { key: '14d',   label: '14 dias' },
                    { key: '30d',   label: '30 dias' },
                ].map(f => (
                    <button key={f.key} onClick={() => setFiltroData(f.key)}
                        className={cn(
                            'h-7 px-3 rounded-lg text-[12px] font-semibold transition-colors',
                            filtroData === f.key
                                ? 'bg-ecf-yellow text-[#252525]'
                                : 'border border-white/[0.08] text-white/50 hover:text-white hover:border-white/20'
                        )}>
                        {f.label}
                    </button>
                ))}

                {isAdmin && publicadoresDisponiveis.length > 0 && (
                    <select value={filtroResp} onChange={e => handleFiltroResp(e.target.value)}
                        className="ml-2 h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer">
                        <option value="">Todos os publicadores</option>
                        {publicadoresDisponiveis.map(p => (
                            <option key={p.id} value={p.id}>{p.nome}</option>
                        ))}
                    </select>
                )}

                {filtragemPrazoAtiva && (
                    <span className="ml-auto text-white/30 text-[12px]">
                        {pendentes.length} empresa{pendentes.length !== 1 ? 's' : ''} com prazo em {FILTRO_LABELS[filtroData]}
                        {' · '}ordenado por urgência
                    </span>
                )}
            </div>

            {/* Empresas atribuídas */}
            {temEmpresas ? (
                <div className="space-y-3 mb-6">
                    {pendentes.map(e => (
                        <EmpresaCard key={e.id} empresa={e} mlbsRegistrados={ultimos} onSync={setSyncEmpresa} onProblemaEmpresa={setProblemaEmpresaModal} showResponsavel={isAdmin} appUrl={appUrl} />
                    ))}

                    {concluidas.length > 0 && (
                        <>
                            <div className="flex items-center gap-2 py-2">
                                <div className="h-px flex-1 bg-white/[0.06]" />
                                <span className="text-white/20 text-[11px] uppercase tracking-wider">Concluídas ({concluidas.length})</span>
                                <div className="h-px flex-1 bg-white/[0.06]" />
                            </div>
                            {concluidas.map(e => (
                                <EmpresaCard key={e.id} empresa={e} mlbsRegistrados={ultimos} onSync={setSyncEmpresa} onProblemaEmpresa={setProblemaEmpresaModal} showResponsavel={isAdmin} appUrl={appUrl} />
                            ))}
                        </>
                    )}
                </div>
            ) : (
                <div className="card-ecf rounded-2xl p-5 mb-6 border border-white/[0.06]">
                    <p className="text-white/40 text-sm text-center py-4">
                        Nenhuma empresa atribuída a você ainda.<br />
                        <span className="text-white/25 text-[12px]">O analista ou líder precisa cadastrar empresas e atribuí-las ao seu usuário.</span>
                    </p>
                </div>
            )}

            {/* Registro livre — contas fora do sistema */}
            {temEmpresas && (
                <div className="mb-4">
                    <button onClick={() => setShowLivre(v => !v)}
                        className="w-full text-white/25 hover:text-white/50 text-[12px] flex items-center justify-center gap-2 py-1.5 transition-colors">
                        <PlusCircle size={13} />
                        {showLivre ? 'Ocultar registro livre' : 'Registrar MLBs de conta não cadastrada no sistema'}
                    </button>
                    {showLivre && (
                        <div className="mt-3">
                            <RegistroLivreForm registrados={(ultimos ?? []).map(u => u.mlb_code)} />
                        </div>
                    )}
                </div>
            )}

            {!temEmpresas && (
                <div className="mb-6">
                    <RegistroLivreForm registrados={(ultimos ?? []).map(u => u.mlb_code)} />
                </div>
            )}

            {/* Avisos de problema ou comentário pendente — destaque no topo */}
            {(ultimos ?? []).some(p => p.problema || (p.comentario && !p.comentario_resolvido)) && (
                <div className="card-ecf rounded-2xl p-5 mt-2 border border-red-500/20">
                    <p className="text-red-400 font-semibold text-sm mb-3 flex items-center gap-2">
                        <AlertTriangle size={14} /> Requer Atenção
                    </p>
                    <div className="space-y-2">
                        {(ultimos ?? []).filter(p => p.problema || (p.comentario && !p.comentario_resolvido)).map(p => (
                            <div key={p.id} className={cn(
                                'rounded-xl border p-3',
                                p.problema
                                    ? 'border-red-500/30 bg-red-500/8'
                                    : 'border-orange-500/25 bg-orange-500/8'
                            )}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center gap-2 flex-wrap mb-1">
                                            <span className="text-white/50 text-[11px]">{p.data}</span>
                                            <span className="text-white/70 text-[12px] font-medium truncate">{p.empresa}</span>
                                            <a href={mlbUrl(p.mlb_code)} target="_blank" rel="noopener noreferrer"
                                                className="text-purple-400 font-mono text-[11px] hover:text-purple-300 flex items-center gap-1">
                                                {p.mlb_code} <ExternalLink size={9} />
                                            </a>
                                        </div>
                                        {/* Nota do problema — visível */}
                                        {p.problema && (
                                            <div className="flex items-start gap-1.5 mt-1">
                                                <AlertTriangle size={11} className="text-red-400 shrink-0 mt-0.5" />
                                                <span className="text-red-300 text-[12px]">
                                                    {p.problema_nota || 'Marcado com problema'}
                                                    {p.problema_em && <span className="text-red-400/50 text-[11px] ml-2">({p.problema_em})</span>}
                                                </span>
                                            </div>
                                        )}
                                        {/* Comentário do líder — visível */}
                                        {p.comentario && !p.comentario_resolvido && (
                                            <div className="flex items-start gap-1.5 mt-1">
                                                <MessageSquare size={11} className="text-orange-400 shrink-0 mt-0.5" />
                                                <span className="text-orange-200 text-[12px]">
                                                    {p.comentario}
                                                    <span className="text-orange-400/50 text-[11px] ml-2">— {p.comentario_autor}{p.comentario_em ? `, ${p.comentario_em}` : ''}</span>
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                    {/* Ações */}
                                    <div className="flex flex-col gap-1.5 shrink-0">
                                        {p.problema && (
                                            <button onClick={() => handleResolverProblema(p)}
                                                className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors">
                                                <CheckCheck size={9} /> Marcar resolvido
                                            </button>
                                        )}
                                        <button onClick={() => setProblemaModal(p)}
                                            className={cn(
                                                'flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border transition-colors',
                                                p.problema
                                                    ? 'border-white/[0.08] text-white/30 hover:text-red-400 hover:border-red-500/30'
                                                    : 'border-white/[0.08] text-white/40 hover:text-red-400 hover:border-red-500/30'
                                            )}>
                                            <AlertTriangle size={9} />
                                            {p.problema ? 'Editar nota' : 'Reportar problema'}
                                        </button>
                                        {p.comentario && !p.comentario_resolvido && (
                                            <button onClick={() => handleResolverComentario(p)}
                                                className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold border border-emerald-500/30 text-emerald-400 bg-emerald-500/10 hover:bg-emerald-500/20 transition-colors">
                                                <CheckCheck size={9} /> Resolver comentário
                                            </button>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Últimos lançamentos */}
            {(ultimos ?? []).length > 0 && (
                <div className="card-ecf rounded-2xl p-5 mt-2">
                    <p className="text-white font-semibold text-sm mb-4">Últimos Lançamentos</p>
                    <div className="overflow-x-auto">
                        <table className="w-full text-[13px]">
                            <thead>
                                <tr className="border-b border-white/[0.06]">
                                    {['Data','Empresa','Anúncio',''].map(h => (
                                        <th key={h} className="text-left text-white/40 font-semibold py-2 pr-4">{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {(ultimos ?? []).map(p => (
                                    <tr key={p.id} className={cn(
                                        'border-b border-white/[0.03] hover:bg-white/[0.02]',
                                        p.problema && 'bg-red-500/5',
                                        p.comentario && !p.comentario_resolvido && 'bg-orange-500/5'
                                    )}>
                                        <td className="py-2 pr-4 text-white/50 text-[12px] whitespace-nowrap">{p.data}</td>
                                        <td className="py-2 pr-4 text-white font-medium max-w-[140px] truncate text-[12px]">{p.empresa}</td>
                                        <td className="py-2 pr-4">
                                            <div className="flex items-center gap-1.5">
                                                <a href={mlbUrl(p.mlb_code)} target="_blank" rel="noopener noreferrer"
                                                    className="text-purple-400 font-mono text-[12px] hover:text-purple-300 flex items-center gap-1">
                                                    {p.mlb_code} <ExternalLink size={11} />
                                                </a>
                                                {p.tipo === 'variacao' && (
                                                    <span className="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-sky-500/15 border border-sky-500/30 text-sky-400">
                                                        Variação
                                                    </span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-2">
                                            <div className="flex items-center gap-1">
                                                {p.problema && (
                                                    <span className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-red-500/20 border border-red-500/30 text-red-400">
                                                        <AlertTriangle size={8} /> Problema
                                                    </span>
                                                )}
                                                {p.comentario && !p.comentario_resolvido && (
                                                    <span className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-orange-500/15 border border-orange-500/30 text-orange-400">
                                                        <MessageSquare size={8} /> Feedback
                                                    </span>
                                                )}
                                                {/* Botão reportar problema (sempre disponível, discreto) */}
                                                {!p.problema && (
                                                    <button onClick={() => setProblemaModal(p)}
                                                        className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] border border-white/[0.06] text-white/20 hover:text-red-400 hover:border-red-500/30 transition-colors">
                                                        <AlertTriangle size={8} /> Reportar
                                                    </button>
                                                )}

                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Modal Sync */}
            {syncEmpresa && (
                <SyncVendasModal empresa={syncEmpresa} onClose={() => setSyncEmpresa(null)} />
            )}

            {/* Modal Problema em publicação */}
            {problemaModal && (
                <ProblemaModal pub={problemaModal} onClose={() => setProblemaModal(null)} />
            )}


            {/* Modal Problema na empresa/conta */}
            {problemaEmpresaModal && (
                <ProblemaEmpresaModal empresa={problemaEmpresaModal} onClose={() => setProblemaEmpresaModal(null)} />
            )}
        </AppLayout>
    );
}
