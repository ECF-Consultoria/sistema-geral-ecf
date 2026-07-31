import AppLayout from '@/Layouts/AppLayout';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle, MessageSquare, Package, ExternalLink, AlertTriangle, Clock, CheckCheck } from 'lucide-react';
import { cn } from '@/lib/utils';

const MESES_PT = {
    '01':'Janeiro','02':'Fevereiro','03':'Março','04':'Abril',
    '05':'Maio','06':'Junho','07':'Julho','08':'Agosto',
    '09':'Setembro','10':'Outubro','11':'Novembro','12':'Dezembro',
};

function nomeMes(ym) {
    if (!ym) return '';
    const [y, m] = ym.split('-');
    return `${MESES_PT[m]} / ${y}`;
}

function fmt(n) { return Number(n ?? 0).toLocaleString('pt-BR'); }

/** Pendências fechadas no grupo: problema resolvido ou comentário resolvido. */
function resolvidosNoGrupo(pubs) {
    return pubs.filter(p => p.problema_resolvido_em || p.comentario_resolvido).length;
}
function mlbUrl(code) { return `https://produto.mercadolivre.com.br/${code.replace('MLB', 'MLB-')}`; }

function KpiMini({ title, value, icon: Icon, color = 'yellow' }) {
    const colors = {
        yellow: { text:'text-ecf-yellow', bg:'bg-ecf-yellow/10', border:'border-ecf-yellow/20' },
        green:  { text:'text-emerald-400', bg:'bg-emerald-500/10', border:'border-emerald-500/20' },
        blue:   { text:'text-blue-400',   bg:'bg-blue-500/10',   border:'border-blue-500/20' },
        purple: { text:'text-purple-400', bg:'bg-purple-500/10', border:'border-purple-500/20' },
        red:    { text:'text-red-400',    bg:'bg-red-500/10',    border:'border-red-500/20' },
    };
    const c = colors[color];
    return (
        <div className="card-ecf rounded-xl p-4 flex items-center gap-3">
            <div className={cn('w-9 h-9 rounded-xl flex items-center justify-center border shrink-0', c.bg, c.border)}>
                <Icon size={16} className={c.text} />
            </div>
            <div>
                <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wide">{title}</p>
                <p className={cn('font-display font-bold text-xl', c.text)}>{value}</p>
            </div>
        </div>
    );
}

function Toggle({ checked, onChange, label, colorChecked = 'bg-ecf-yellow border-ecf-yellow' }) {
    return (
        <label className="flex items-center gap-2 cursor-pointer select-none">
            <button
                type="button"
                onClick={onChange}
                className={cn(
                    'w-10 h-5 rounded-full border-2 relative transition-all',
                    checked ? colorChecked : 'bg-white/10 border-white/20'
                )}
            >
                <span className={cn(
                    'absolute top-0.5 w-3 h-3 rounded-full bg-white transition-all',
                    checked ? 'left-[22px]' : 'left-0.5'
                )} />
            </button>
            <span className="text-white/60 text-[12px]">{label}</span>
        </label>
    );
}

function CardPub({ pub }) {
    const [abrirComentario, setAbrirComentario] = useState(false);
    const [abrirProblema, setAbrirProblema] = useState(false);
    const { data, setData, patch, processing } = useForm({ comentario: pub.comentario ?? '' });
    const problemaForm = useForm({ problema_nota: pub.problema_nota ?? '' });

    function toggleRevisado() {
        router.patch(route('mlb.revisado', pub.id), {}, { preserveState: true, preserveScroll: true });
    }

    function toggleProblema() {
        if (pub.problema) {
            router.patch(route('mlb.problema', pub.id), {}, { preserveState: true, preserveScroll: true });
        } else {
            setAbrirProblema(true);
        }
    }

    function salvarProblema() {
        problemaForm.patch(route('mlb.problema', pub.id), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setAbrirProblema(false),
        });
    }

    function salvarComentario() {
        patch(route('mlb.comentario', pub.id), {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => setAbrirComentario(false),
        });
    }

    function resolverComentario() {
        router.patch(route('mlb.resolver', pub.id), {}, { preserveState: true, preserveScroll: true });
    }

    const temProblema = pub.problema;
    // Problema que já foi fechado — mantém a nota original como histórico
    const problemaResolvido = !temProblema && !!pub.problema_resolvido_em;

    return (
        <div className={cn(
            'rounded-xl border p-4 transition-all',
            temProblema
                ? 'border-red-500/40 bg-red-500/8 ring-1 ring-red-500/20'
                : problemaResolvido
                    ? 'border-emerald-500/25 bg-emerald-500/[0.04]'
                    : pub.revisado
                        ? 'border-purple-500/20 bg-purple-500/5'
                        : 'border-white/[0.06] bg-white/[0.02]'
        )}>
            {/* Badge problema destacado */}
            {temProblema && (
                <div className="flex items-center gap-2 mb-3 px-2.5 py-1.5 rounded-lg bg-red-500/15 border border-red-500/30">
                    <AlertTriangle size={12} className="text-red-400 shrink-0" />
                    <span className="text-red-400 text-[12px] font-semibold">
                        {pub.problema_nota || 'Com problema'}
                    </span>
                    {pub.problema_em && (
                        <span className="text-red-400/50 text-[11px] ml-auto">desde {pub.problema_em}</span>
                    )}
                </div>
            )}

            {/* Problema resolvido — quem fechou e quando */}
            {problemaResolvido && (
                <div className="mb-3 px-2.5 py-1.5 rounded-lg bg-emerald-500/10 border border-emerald-500/25">
                    <div className="flex items-center gap-2">
                        <CheckCheck size={12} className="text-emerald-400 shrink-0" />
                        <span className="text-emerald-400 text-[12px] font-semibold">Problema resolvido</span>
                        <span className="text-emerald-400/50 text-[11px] ml-auto">{pub.problema_resolvido_em}</span>
                    </div>
                    {pub.problema_nota && (
                        <p className="text-white/50 text-[12px] mt-1 line-through decoration-white/20">↳ {pub.problema_nota}</p>
                    )}
                    <p className="text-white/30 text-[11px] mt-1">
                        por {pub.problema_resolvido_por ?? '—'}
                        {pub.problema_em && ` · aberto em ${pub.problema_em}`}
                    </p>
                </div>
            )}

            {/* Cabeçalho */}
            <div className="flex items-center justify-between mb-3">
                <div className="text-white/40 text-[12px] flex items-center gap-1.5">
                    {pub.data} · <span className="text-white/60 font-medium">{pub.usuario}</span>
                    {pub.usuario_removido && (
                        <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-red-500/15 border border-red-500/30 text-red-400">removido</span>
                    )}
                </div>
                <a
                    href={mlbUrl(pub.mlb_code)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-purple-400 font-mono text-[12px] hover:text-purple-300 flex items-center gap-1"
                >
                    {pub.mlb_code} <ExternalLink size={11} />
                </a>
            </div>

            {/* Toggles: Revisado + Com Problema */}
            <div className="flex items-center gap-6 mb-3 flex-wrap">
                <Toggle
                    checked={pub.revisado}
                    onChange={toggleRevisado}
                    label="Revisado"
                    colorChecked="bg-ecf-yellow border-ecf-yellow"
                />
                <Toggle
                    checked={temProblema}
                    onChange={toggleProblema}
                    label="Com problema"
                    colorChecked="bg-red-500 border-red-500"
                />
            </div>

            {/* Formulário de nota do problema */}
            {abrirProblema && (
                <div className="mb-3 space-y-2">
                    <textarea
                        value={problemaForm.data.problema_nota}
                        onChange={e => problemaForm.setData('problema_nota', e.target.value)}
                        rows={2}
                        placeholder="Descreva o problema (conta suspensa, sem imagem, etc.)…"
                        className="w-full px-3 py-2 rounded-lg border border-red-500/30 bg-red-500/5 text-white text-[12px] focus:outline-none focus:border-red-400/50 resize-none placeholder:text-white/20"
                    />
                    <div className="flex gap-2">
                        <button onClick={salvarProblema} disabled={problemaForm.processing}
                            className="h-7 px-3 rounded-lg bg-red-500 text-white text-[12px] font-bold disabled:opacity-50">
                            Confirmar
                        </button>
                        <button onClick={() => setAbrirProblema(false)}
                            className="h-7 px-3 rounded-lg border border-white/[0.08] text-white/40 text-[12px] hover:text-white/70">
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            {/* Comentário existente */}
            {pub.comentario && !abrirComentario && (
                <div className={cn(
                    'rounded-lg border px-3 py-2 text-[12px] text-white/70 mb-2',
                    pub.comentario_resolvido
                        ? 'border-emerald-500/20 bg-emerald-500/5'
                        : 'border-blue-500/20 bg-blue-500/5'
                )}>
                    {pub.comentario_resolvido && (
                        <span className="text-emerald-400 text-[10px] font-bold uppercase tracking-wide block mb-1">
                            ✓ Resolvido
                            {pub.comentario_resolvido_por && ` por ${pub.comentario_resolvido_por}`}
                            {pub.comentario_resolvido_em && ` · ${pub.comentario_resolvido_em}`}
                        </span>
                    )}
                    💬 {pub.comentario}
                    <div className="text-white/30 text-[11px] mt-1">
                        por {pub.comentario_autor} · {pub.comentario_em}
                    </div>
                    <div className="flex gap-3 mt-2">
                        <button onClick={() => setAbrirComentario(true)} className="text-white/40 hover:text-white/70 text-[11px] underline">Editar</button>
                        {!pub.comentario_resolvido && (
                            <button onClick={resolverComentario} className="text-emerald-400/60 hover:text-emerald-400 text-[11px] underline">✓ Marcar resolvido</button>
                        )}
                    </div>
                </div>
            )}

            {abrirComentario && (
                <div className="space-y-2">
                    <textarea
                        value={data.comentario}
                        onChange={e => setData('comentario', e.target.value)}
                        rows={2}
                        placeholder="Feedback / observação sobre este anúncio…"
                        className="w-full px-3 py-2 rounded-lg border border-white/[0.08] bg-white/[0.03] text-white text-[12px] focus:outline-none focus:border-ecf-yellow/40 resize-none placeholder:text-white/20"
                    />
                    <div className="flex gap-2">
                        <button
                            onClick={salvarComentario}
                            disabled={processing}
                            className="h-7 px-3 rounded-lg bg-ecf-yellow text-[#252525] text-[12px] font-bold disabled:opacity-50"
                        >
                            Salvar
                        </button>
                        <button
                            onClick={() => { setAbrirComentario(false); setData('comentario', pub.comentario ?? ''); }}
                            className="h-7 px-3 rounded-lg border border-white/[0.08] text-white/40 text-[12px] hover:text-white/70"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            )}

            {!pub.comentario && !abrirComentario && (
                <button
                    onClick={() => setAbrirComentario(true)}
                    className="text-white/25 hover:text-white/50 text-[11px] flex items-center gap-1 transition-colors"
                >
                    <MessageSquare size={12} /> Adicionar comentário
                </button>
            )}
        </div>
    );
}

export default function Revisao({ publicacoes, publicadores, meses, kpis, filters, empresasComAtraso = [] }) {
    const [f, setF] = useState({ ...filters });

    function apply(updates) {
        const novo = { ...f, ...updates };
        setF(novo);
        router.get(route('mlb.revisao'), {
            mes:    novo.mesRef,
            func:   novo.funcId,
            status: novo.status,
            busca:  novo.busca,
        }, { preserveState: true });
    }

    // Problemas sempre no topo, depois o resto
    const comProblema = (publicacoes ?? []).filter(p => p.problema);
    const semProblema = (publicacoes ?? []).filter(p => !p.problema);

    function groupByEmpresa(list) {
        return list.reduce((acc, p) => {
            const key = p.empresa || '— sem loja —';
            if (!acc[key]) acc[key] = [];
            acc[key].push(p);
            return acc;
        }, {});
    }

    const problemasGroup = groupByEmpresa(comProblema);
    const normalGroup    = groupByEmpresa(semProblema);

    const atrasadoSet = new Set(empresasComAtraso);

    function renderGroup(map, highlight = false) {
        return Object.entries(map).map(([empresa, pubs]) => (
            <div key={empresa} className="mb-6">
                <div className="flex items-center justify-between mb-3 pl-1">
                    <div className="flex items-center gap-2">
                        <div className={cn('w-1 h-5 rounded-full shrink-0', highlight ? 'bg-red-500' : 'bg-ecf-yellow')} />
                        <span className="text-white font-semibold text-[15px]">{empresa}</span>
                        {highlight && (
                            <span className="flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold border bg-red-500/15 text-red-400 border-red-500/30">
                                <AlertTriangle size={9} /> Com problema
                            </span>
                        )}
                        {atrasadoSet.has(empresa) && (
                            <span title="Possui SKU(s) concluído(s) fora do prazo"
                                className="flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[10px] font-bold border bg-orange-500/10 border-orange-500/25 text-orange-400">
                                <Clock size={9} /> Atrasado
                            </span>
                        )}
                    </div>
                    <span className="text-white/30 text-xs">
                        {pubs.length} anúncio(s) · {pubs.filter(p => p.revisado).length} revisado(s)
                        {resolvidosNoGrupo(pubs) > 0 && (
                            <span className="text-emerald-400/60"> · {resolvidosNoGrupo(pubs)} resolvido(s)</span>
                        )}
                    </span>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    {pubs.map(p => <CardPub key={p.id} pub={p} />)}
                </div>
            </div>
        ));
    }

    const total = (publicacoes ?? []).length;

    return (
        <AppLayout title="Revisão">
            <div className="mb-6">
                <h1 className="text-white font-display font-bold text-2xl">Revisão de Anúncios</h1>
                <p className="text-white/40 text-sm mt-0.5">Marque revisões, deixe feedback e registre problemas por MLB</p>
            </div>

            {/* KPIs */}
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
                <KpiMini title="Na seleção"    value={fmt(kpis?.total)}          icon={Package}        color="yellow" />
                <KpiMini title="Revisados"      value={fmt(kpis?.revisados)}      icon={CheckCircle}    color="purple" />
                <KpiMini title="Com comentário" value={fmt(kpis?.com_comentario)} icon={MessageSquare}  color="blue" />
                <KpiMini title="Com problema"   value={fmt(kpis?.com_problema)}   icon={AlertTriangle}  color="red" />
                <KpiMini title="Resolvidos"     value={fmt(kpis?.resolvidos)}     icon={CheckCheck}     color="green" />
            </div>

            {/* Filtros */}
            <div className="card-ecf rounded-2xl p-4 mb-6 flex flex-wrap gap-3 items-center">
                <select
                    value={f.mesRef}
                    onChange={e => apply({ mesRef: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    {(meses ?? []).map(m => (
                        <option key={m} value={m}>{nomeMes(m)}</option>
                    ))}
                </select>

                {publicadores?.length > 0 && (
                    <select
                        value={f.funcId}
                        onChange={e => apply({ funcId: e.target.value })}
                        className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                    >
                        <option value="">Todos os publicadores</option>
                        {(publicadores ?? []).map(p => (
                            <option key={p.id} value={p.id}>{p.nome}</option>
                        ))}
                    </select>
                )}

                <select
                    value={f.status}
                    onChange={e => apply({ status: e.target.value })}
                    className="h-9 pl-3 pr-8 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none cursor-pointer"
                >
                    <option value="todos">Todos</option>
                    <option value="sem_revisao">Sem revisão</option>
                    <option value="sem_comentario">Sem comentário</option>
                    <option value="com_comentario">Com comentário</option>
                    <option value="com_problema">Com problema</option>
                    <option value="resolvidos">Resolvidos</option>
                    <option value="vendidos">Vendidos</option>
                    <option value="nao_vendidos">Não vendidos</option>
                </select>

                <input
                    type="text"
                    value={f.busca}
                    onChange={e => setF(p => ({ ...p, busca: e.target.value }))}
                    onKeyDown={e => e.key === 'Enter' && apply({ busca: f.busca })}
                    placeholder="Buscar MLB ou loja…"
                    className="h-9 px-3 rounded-xl border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none placeholder:text-white/20 min-w-[180px]"
                />
            </div>

            {total === 0 && (
                <div className="card-ecf rounded-2xl p-12 text-center text-white/20">
                    Nenhum anúncio encontrado com esses filtros.
                </div>
            )}

            {/* Problemas em destaque no topo */}
            {comProblema.length > 0 && (
                <div className="mb-4">
                    <div className="flex items-center gap-2 mb-4">
                        <AlertTriangle size={14} className="text-red-400" />
                        <span className="text-red-400 font-semibold text-sm uppercase tracking-wide">
                            Com Problema — Prioridade ({comProblema.length})
                        </span>
                        <div className="h-px flex-1 bg-red-500/20" />
                    </div>
                    {renderGroup(problemasGroup, true)}
                </div>
            )}

            {/* Demais publicações */}
            {semProblema.length > 0 && (
                <>
                    {comProblema.length > 0 && (
                        <div className="flex items-center gap-2 mb-4">
                            <div className="h-px flex-1 bg-white/[0.06]" />
                            <span className="text-white/20 text-[11px] uppercase tracking-wider">Demais anúncios ({semProblema.length})</span>
                            <div className="h-px flex-1 bg-white/[0.06]" />
                        </div>
                    )}
                    {renderGroup(normalGroup, false)}
                </>
            )}
        </AppLayout>
    );
}
