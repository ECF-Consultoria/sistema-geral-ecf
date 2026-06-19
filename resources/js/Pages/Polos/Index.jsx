import { useState, useMemo } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Clock, RefreshCw, X, Megaphone, MegaphoneOff, LayoutList } from 'lucide-react';
import { formatCurrency, cn } from '@/lib/utils';
import DonutCard from './components/DonutCard';
import RoseChart from './components/RoseChart';

// Paleta de identidade por polo (pizza de distribuição + ponto no card). Amarelo ECF primeiro.
const POLO_PALETTE = ['#ffe600', '#38bdf8', '#22c55e', '#a855f7', '#fb923c', '#f43f5e', '#2dd4bf', '#e879f9'];

// Paleta de status para a vista de distribuição de Status (réplica do "Gráfico Junho" — D-14).
// Mantida aqui (e não importada do DonutCard) para encapsulamento — usada apenas em Index.
const STATUS_META = {
    'Sim':          { cor: '#22c55e', label: 'No alvo' },
    'Em progresso': { cor: '#ffe600', label: 'Em progresso' },
    'Não':          { cor: '#ef4444', label: 'Não' },
    'Problema':     { cor: '#a855f7', label: 'Problema' },
};

// Ordem de exibição fixa na legenda da vista de Status
const STATUS_ORDEM = ['Sim', 'Em progresso', 'Não', 'Problema'];

/**
 * PolosIndex — Página /polos (Phase 38).
 *
 * Layout "Distribuição + grade 3D" com DUAS visões:
 *   1. Vista por polo: pizza 3D de distribuição de faturamento + grade de donuts (% da meta)
 *   2. Vista de distribuição de Status: pizza 3D Sim/Em progresso/Não/Problema (D-14)
 *
 * O CSV POLOS MENSAL empilha ~12 meses; exibe UM mês por vez (default: o mais
 * recente / corrente, parcial, atualizado diariamente pelo ECF Drive).
 *
 * Props de PolosController::index(): polos, statusDist, meses, mesSelecionado,
 * mesRefLabel, parcial, erro.
 */
export default function PolosIndex({
    polos          = [],
    statusDist     = { Sim: 0, 'Em progresso': 0, 'Não': 0, Problema: 0, total: 0 },
    meses          = [],
    mesSelecionado = null,
    mesRefLabel    = null,
    parcial        = false,
    erro           = null,
}) {
    // Cor estável por polo (ordem alfabética que o backend já entrega)
    const corDoPolo = useMemo(() => {
        const mapa = {};
        polos.forEach((p, i) => { mapa[p.polo] = POLO_PALETTE[i % POLO_PALETTE.length]; });
        return mapa;
    }, [polos]);

    // Filtro client-side de polos (default: todos visíveis)
    const [ativos, setAtivos] = useState(() => polos.map((p) => p.polo));
    const visiveis = polos.filter((p) => ativos.includes(p.polo));

    const togglePolo = (nome) => {
        setAtivos((cur) => cur.includes(nome) ? cur.filter((n) => n !== nome) : [...cur, nome]);
    };

    // Troca de mês: recarrega via Inertia com ?mes=YYYYMM
    const trocarMes = (e) => {
        router.get(route('polos.index'), { mes: e.target.value }, { preserveScroll: true, preserveState: false });
    };

    // Botão "Sincronizar": aquece o cache da Adman do mês exibido (roda em background).
    const [sincronizando, setSincronizando] = useState(false);
    const [syncMsg, setSyncMsg] = useState(null);
    const sincronizar = () => {
        router.post(route('polos.sync'), { mes: mesSelecionado }, {
            preserveScroll: true,
            preserveState: true,
            onStart:   () => setSincronizando(true),
            onSuccess: () => setSyncMsg('Sincronização iniciada — recarregue a página em alguns minutos.'),
            onFinish:  () => setSincronizando(false),
        });
    };

    // ── Detalhe por empresa: drawer do polo + semanal sob demanda ──────────────
    const [poloAberto, setPoloAberto] = useState(null);     // objeto do polo (com .empresas)
    const [empresaSel, setEmpresaSel] = useState(null);     // cust_id expandido
    const [semanal, setSemanal]       = useState({});       // cust_id -> { semanas, total, loading, erro }

    const abrirEmpresa = (cust) => {
        setEmpresaSel((cur) => (cur === cust ? null : cust));
        if (!semanal[cust]) {
            setSemanal((s) => ({ ...s, [cust]: { loading: true } }));
            fetch(route('polos.empresa.semanal', { cust, mes: mesSelecionado }))
                .then((r) => r.json())
                .then((d) => setSemanal((s) => ({ ...s, [cust]: { ...d, loading: false } })))
                .catch(() => setSemanal((s) => ({ ...s, [cust]: { erro: true, loading: false } })));
        }
    };

    const fecharDrawer = () => { setPoloAberto(null); setEmpresaSel(null); };

    // Distribuição: fatias por faturamento dos polos visíveis (maior → menor)
    const distrib = useMemo(() => {
        const ordenado = [...visiveis].sort((a, b) => (b.faturamento ?? 0) - (a.faturamento ?? 0));
        const total = ordenado.reduce((acc, p) => acc + (p.faturamento ?? 0), 0);
        return {
            total,
            itens: ordenado.map((p) => ({
                polo: p.polo,
                cor: corDoPolo[p.polo],
                faturamento: p.faturamento ?? 0,
                share: total > 0 ? (p.faturamento ?? 0) / total * 100 : 0,
            })),
        };
    }, [visiveis, corDoPolo]);

    const totalMeta   = visiveis.reduce((acc, p) => acc + (p.meta ?? 0), 0);
    const totalAtivos = visiveis.reduce((acc, p) => acc + (p.ativos ?? 0), 0);
    const pctGeral    = totalMeta > 0 ? distrib.total / totalMeta * 100 : 0;

    const temDados = !erro && polos.length > 0;

    return (
        <AppLayout title="Faturamento Polos">
            <Head title="Faturamento Polos" />

            <div className="space-y-6 max-w-[1400px] mx-auto">

                {/* ── Cabeçalho + seletor de mês ────────────────────────────── */}
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-white font-display font-extrabold text-2xl tracking-tight">
                            Faturamento por Polo
                        </h1>
                        <div className="flex items-center gap-2">
                            <p className="text-white/40 text-sm">
                                {mesRefLabel ? `Mês de referência: ${mesRefLabel}` : 'Dados ao vivo — ECF Drive'}
                            </p>
                            {parcial && (
                                <span className="inline-flex items-center gap-1 rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-ecf-yellow">
                                    <Clock size={11} /> Mês parcial
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-col items-end gap-1.5">
                        <div className="flex items-center gap-2">
                            {/* Visão completa: abre a página de todas as empresas em nova aba */}
                            {temDados && (
                                <a
                                    href={route('polos.empresas', { mes: mesSelecionado })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title="Abrir a visão completa de todas as empresas em nova aba"
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/[0.12] bg-white/[0.04] px-3 py-1.5 text-sm font-semibold text-white/80 transition hover:bg-white/[0.08]"
                                >
                                    <LayoutList size={14} /> Todas as empresas
                                </a>
                            )}

                            {/* Sincronizar: aquece o faturamento do dia direto da Adman */}
                            <button
                                type="button"
                                onClick={sincronizar}
                                disabled={sincronizando}
                                title="Puxa o faturamento do dia direto da Adman (roda em background, leva alguns minutos)"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-ecf-yellow/30 bg-ecf-yellow/10 px-3 py-1.5 text-sm font-semibold text-ecf-yellow transition hover:bg-ecf-yellow/20 disabled:opacity-50"
                            >
                                <RefreshCw size={14} className={cn(sincronizando && 'animate-spin')} />
                                {sincronizando ? 'Iniciando…' : 'Sincronizar'}
                            </button>

                            {meses.length > 0 && (
                                <>
                                    <label className="text-white/40 text-xs uppercase tracking-wider ml-1">Mês</label>
                                    <select
                                        value={mesSelecionado ?? ''}
                                        onChange={trocarMes}
                                        className="rounded-lg border border-white/[0.1] bg-ecf-card px-3 py-1.5 text-sm text-white/90 outline-none focus:border-ecf-yellow/40"
                                    >
                                        {meses.map((m) => (
                                            <option key={m.value} value={m.value} className="bg-ecf-card text-white">
                                                {m.label}{m.parcial ? ' (parcial)' : ''}
                                            </option>
                                        ))}
                                    </select>
                                </>
                            )}
                        </div>
                        {syncMsg && (
                            <span className="text-[11px] text-ecf-yellow/80">{syncMsg}</span>
                        )}
                    </div>
                </div>

                {/* ── Estado de erro: ECF Drive offline ─────────────────────── */}
                {erro && (
                    <div className={cn('flex items-start gap-3 rounded-xl p-4', 'border border-red-500/20 bg-red-500/[0.06]')}>
                        <AlertTriangle size={18} className="text-red-400 mt-0.5 shrink-0" />
                        <div>
                            <p className="text-red-300 font-semibold text-sm">Erro ao carregar dados dos polos</p>
                            <p className="text-red-400/70 text-xs mt-0.5">{erro}</p>
                        </div>
                    </div>
                )}

                {/* ── Estado vazio (sem erro) ───────────────────────────────── */}
                {!erro && polos.length === 0 && (
                    <div className={cn('rounded-xl p-6 text-center', 'border border-white/[0.06] bg-white/[0.02]')}>
                        <p className="text-white/40 text-sm">Nenhum polo encontrado para o mês selecionado.</p>
                    </div>
                )}

                {/* ── Filtro de polos (chips) ───────────────────────────────── */}
                {temDados && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-white/30 text-[11px] uppercase tracking-wider mr-1">Polos</span>
                        {polos.map((p) => {
                            const on = ativos.includes(p.polo);
                            return (
                                <button
                                    key={p.polo}
                                    onClick={() => togglePolo(p.polo)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors',
                                        on
                                            ? 'border-white/15 bg-white/[0.06] text-white/90'
                                            : 'border-white/[0.06] bg-transparent text-white/30',
                                    )}
                                >
                                    <span
                                        className="h-2.5 w-2.5 rounded-full transition-opacity"
                                        style={{ background: corDoPolo[p.polo], opacity: on ? 1 : 0.3 }}
                                    />
                                    {p.polo}
                                </button>
                            );
                        })}
                    </div>
                )}

                {/* ── Pizza rose de distribuição de faturamento + legenda ────── */}
                {temDados && visiveis.length > 0 && (
                    <div className={cn('rounded-2xl border border-white/[0.08] bg-white/[0.02] p-6',
                                       'grid grid-cols-1 lg:grid-cols-2 gap-6 items-center')}>
                        <div className="flex flex-col items-center gap-4">
                            <p className="text-white/40 text-xs uppercase tracking-wider self-start">
                                Distribuição do faturamento — {mesRefLabel}
                            </p>
                            <div className="w-full">
                                <RoseChart
                                    slices={distrib.itens.map((i) => ({ color: i.cor, value: i.faturamento, label: i.polo }))}
                                    height={300}
                                    emptyLabel="Sem faturamento no mês selecionado"
                                />
                            </div>
                            <p className="text-white/50 text-sm">
                                Total: <span className="text-ecf-yellow font-semibold">{formatCurrency(distrib.total)}</span>
                            </p>
                        </div>

                        {/* Legenda / ranking */}
                        <div className="space-y-1.5">
                            {distrib.itens.map((i) => (
                                <div key={i.polo} className="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-white/[0.03]">
                                    <span className="h-3 w-3 rounded-full shrink-0" style={{ background: i.cor }} />
                                    <span className="text-white/80 text-sm flex-1 truncate">{i.polo}</span>
                                    <span className="text-white font-semibold text-sm tabular-nums">{formatCurrency(i.faturamento)}</span>
                                    <span className="text-white/40 text-xs tabular-nums w-12 text-right">{i.share.toFixed(1)}%</span>
                                </div>
                            ))}
                            {/* KPIs agregados */}
                            <div className="mt-3 grid grid-cols-3 gap-2 border-t border-white/[0.06] pt-3">
                                <Kpi label="Total Meta" value={formatCurrency(totalMeta)} />
                                <Kpi label="% Geral" value={`${pctGeral.toFixed(1)}%`} accent />
                                <Kpi label="Empresas" value={totalAtivos} />
                            </div>
                        </div>
                    </div>
                )}

                {/* ── Vista de distribuição de Status (réplica "Gráfico Junho" — D-14) ──
                 *  Pizza rose Sim/Em progresso/Não/Problema entre todos os ativos do mês
                 *  selecionado. Renderizada apenas quando há ativos (statusDist.total > 0). */}
                {temDados && statusDist.total > 0 && (
                    <div className={cn('rounded-2xl border border-white/[0.08] bg-white/[0.02] p-6',
                                       'grid grid-cols-1 lg:grid-cols-2 gap-6 items-center')}>
                        <div className="flex flex-col items-center gap-4">
                            <p className="text-white/40 text-xs uppercase tracking-wider self-start">
                                Distribuição de status — {mesRefLabel}
                            </p>
                            <div className="w-full">
                                <RoseChart
                                    slices={STATUS_ORDEM.map((k) => ({
                                        color: STATUS_META[k].cor,
                                        value: statusDist[k] ?? 0,
                                        label: STATUS_META[k].label,
                                    }))}
                                    height={300}
                                    money={false}
                                    emptyLabel="Sem ativos no mês selecionado"
                                />
                            </div>
                            <p className="text-white/50 text-sm">
                                Total: <span className="text-ecf-yellow font-semibold">{statusDist.total}</span> ativos
                            </p>
                        </div>

                        {/* Legenda com label + contagem + % sobre o total de ativos */}
                        <div className="space-y-1.5">
                            {STATUS_ORDEM.map((k) => {
                                const count = statusDist[k] ?? 0;
                                const pct   = statusDist.total > 0 ? (count / statusDist.total * 100) : 0;
                                const { cor, label } = STATUS_META[k];
                                return (
                                    <div key={k} className="flex items-center gap-3 rounded-lg px-3 py-2 hover:bg-white/[0.03]">
                                        <span className="h-3 w-3 rounded-full shrink-0" style={{ background: cor }} />
                                        <span className="text-white/80 text-sm flex-1">{label}</span>
                                        <span className="text-white font-semibold text-sm tabular-nums">{count}</span>
                                        <span className="text-white/40 text-xs tabular-nums w-12 text-right">{pct.toFixed(1)}%</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* ── Grade de pizzas 3D por polo (% da meta) ───────────────── */}
                {temDados && visiveis.length > 0 && (
                    <div className="space-y-3">
                        <p className="text-white/40 text-xs uppercase tracking-wider">% da meta por polo</p>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                            {visiveis.map((p) => (
                                <button
                                    key={p.polo}
                                    type="button"
                                    onClick={() => setPoloAberto(p)}
                                    title={`Ver empresas do polo ${p.polo}`}
                                    className="rounded-2xl p-2 transition hover:bg-white/[0.03] hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-ecf-yellow/40"
                                >
                                    <DonutCard polo={p} cor={corDoPolo[p.polo]} />
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {/* Todos os polos desmarcados */}
                {temDados && visiveis.length === 0 && (
                    <div className={cn('rounded-xl p-6 text-center', 'border border-white/[0.06] bg-white/[0.02]')}>
                        <p className="text-white/40 text-sm">Nenhum polo selecionado. Reative um polo no filtro acima.</p>
                    </div>
                )}
            </div>

            {/* Painel lateral: empresas do polo + faturamento semanal sob demanda */}
            {poloAberto && (
                <PoloDrawer
                    polo={poloAberto}
                    cor={corDoPolo[poloAberto.polo]}
                    mesRefLabel={mesRefLabel}
                    empresaSel={empresaSel}
                    semanal={semanal}
                    onEmpresa={abrirEmpresa}
                    onClose={fecharDrawer}
                />
            )}
        </AppLayout>
    );
}

// Badge de status reutilizável (mesma paleta da legenda).
function StatusBadge({ status }) {
    const meta = STATUS_META[status] ?? { cor: '#94a3b8', label: status };
    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold"
            style={{ background: `${meta.cor}22`, color: meta.cor }}
        >
            <span className="h-1.5 w-1.5 rounded-full" style={{ background: meta.cor }} />
            {meta.label}
        </span>
    );
}

/**
 * PoloDrawer — painel lateral com as empresas do polo. Cada empresa mostra
 * status, faturamento vs meta (%), ads ligado/desligado e problema. Ao clicar,
 * expande o faturamento semanal (Adman, carregado sob demanda).
 */
function PoloDrawer({ polo, cor, mesRefLabel, empresaSel, semanal, onEmpresa, onClose }) {
    const empresas = polo.empresas ?? [];
    const [filtro, setFiltro] = useState('todas');

    const FILTROS = [
        { key: 'todas',        label: 'Todas' },
        { key: 'Sim',          label: 'No alvo' },
        { key: 'Em progresso', label: 'Em progresso' },
        { key: 'Não',          label: 'Não faturou' },
        { key: 'Problema',     label: 'Problema' },
        { key: 'ads',          label: 'Ads desligado' },
    ];

    const empresasFiltradas = empresas.filter((e) => {
        if (filtro === 'todas') return true;
        if (filtro === 'ads')   return e.ads_desligado === true;
        return e.status === filtro;
    });

    return (
        <div className="fixed inset-0 z-50 flex justify-end">
            {/* backdrop */}
            <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} />

            <aside className="relative h-full w-full max-w-xl overflow-y-auto border-l border-white/[0.1] bg-ecf-bg shadow-2xl">
                {/* Cabeçalho do polo */}
                <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-white/[0.08] bg-ecf-bg/95 px-5 py-4 backdrop-blur">
                    <div className="flex items-center gap-3">
                        <span className="h-3 w-3 rounded-full" style={{ background: cor }} />
                        <div>
                            <h2 className="text-white font-display font-extrabold text-lg leading-tight">{polo.polo}</h2>
                            <p className="text-white/40 text-xs">{empresas.length} empresas · {mesRefLabel}</p>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-white/50 hover:bg-white/[0.06] hover:text-white">
                        <X size={18} />
                    </button>
                </div>

                {/* Resumo do polo */}
                <div className="grid grid-cols-3 gap-2 px-5 py-4 border-b border-white/[0.06]">
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Faturamento</p>
                        <p className="text-ecf-yellow font-display font-extrabold text-base mt-0.5">{formatCurrency(polo.faturamento)}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">Meta</p>
                        <p className="text-white/80 font-display font-extrabold text-base mt-0.5">{formatCurrency(polo.meta)}</p>
                    </div>
                    <div>
                        <p className="text-white/30 text-[10px] uppercase tracking-wider">% da meta</p>
                        <p className="text-white/80 font-display font-extrabold text-base mt-0.5">{polo.pct}%</p>
                    </div>
                </div>

                {/* Filtros rápidos */}
                <div className="flex flex-wrap gap-1.5 px-5 py-3 border-b border-white/[0.06]">
                    {FILTROS.map((f) => {
                        const n = f.key === 'todas'
                            ? empresas.length
                            : f.key === 'ads'
                                ? empresas.filter((e) => e.ads_desligado === true).length
                                : empresas.filter((e) => e.status === f.key).length;
                        const ativo = filtro === f.key;
                        return (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => setFiltro(f.key)}
                                className={cn(
                                    'rounded-full px-2.5 py-1 text-[11px] font-semibold transition',
                                    ativo ? 'bg-ecf-yellow text-black' : 'bg-white/[0.05] text-white/60 hover:bg-white/[0.1]',
                                )}
                            >
                                {f.label} <span className="tabular-nums opacity-70">{n}</span>
                            </button>
                        );
                    })}
                </div>

                {/* Lista de empresas */}
                <div className="divide-y divide-white/[0.05]">
                    {empresasFiltradas.length === 0 && (
                        <p className="px-5 py-6 text-center text-white/40 text-sm">Nenhuma empresa neste filtro.</p>
                    )}
                    {empresasFiltradas.map((e) => {
                        const expandida = empresaSel === e.cust_id;
                        const sem        = semanal[e.cust_id];
                        const pctBar     = Math.min(e.pct ?? 0, 100);
                        const statusCor  = (STATUS_META[e.status] ?? {}).cor ?? '#94a3b8';
                        return (
                            <div key={e.cust_id} className={cn('px-5 py-3 transition', expandida && 'bg-white/[0.03]')}>
                                <button type="button" onClick={() => onEmpresa(e.cust_id)} className="w-full text-left focus:outline-none">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="min-w-0 flex items-center gap-2">
                                            <span className="text-white/90 font-semibold text-sm truncate">{e.nome}</span>
                                            <span className="text-white/30 text-[10px] uppercase shrink-0">{e.fase}</span>
                                            {polo.todas && e.polo && (
                                                <span className="text-white/30 text-[10px] shrink-0">· {e.polo}</span>
                                            )}
                                        </div>
                                        <StatusBadge status={e.status} />
                                    </div>

                                    {/* Faturamento + barra % da meta */}
                                    <div className="mt-2 flex items-center gap-3">
                                        <span className="text-white font-semibold text-sm tabular-nums w-28 shrink-0">{formatCurrency(e.faturamento)}</span>
                                        <div className="flex-1 h-1.5 rounded-full bg-white/[0.08] overflow-hidden">
                                            <div className="h-full rounded-full" style={{ width: `${pctBar}%`, background: statusCor }} />
                                        </div>
                                        <span className="text-white/40 text-xs tabular-nums w-14 text-right shrink-0">{e.pct}%</span>
                                    </div>

                                    {/* Badges: ads + problema */}
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        {e.ads_desligado === true && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-red-500/[0.12] px-2 py-0.5 text-[10px] font-semibold text-red-300">
                                                <MegaphoneOff size={11} /> Ads desligado
                                            </span>
                                        )}
                                        {e.ads_desligado === false && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-green-500/[0.12] px-2 py-0.5 text-[10px] font-semibold text-green-300">
                                                <Megaphone size={11} /> Ads ligado
                                            </span>
                                        )}
                                        {e.problema && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-purple-500/[0.15] px-2 py-0.5 text-[10px] font-semibold text-purple-300" title={e.problema_nota || ''}>
                                                <AlertTriangle size={11} /> Problema{e.problema_nota ? ' · ' + e.problema_nota : ''}
                                            </span>
                                        )}
                                    </div>
                                </button>

                                {/* Detalhe semanal (sob demanda) */}
                                {expandida && (
                                    <div className="mt-3 rounded-lg border border-white/[0.06] bg-white/[0.02] p-3">
                                        <p className="text-white/30 text-[10px] uppercase tracking-wider mb-2">Faturamento por semana</p>
                                        {sem?.loading && <p className="text-white/40 text-xs">Carregando da Adman…</p>}
                                        {sem?.erro && <p className="text-red-300 text-xs">Falha ao buscar o semanal.</p>}
                                        {sem && !sem.loading && !sem.erro && (
                                            <div className="space-y-1.5">
                                                {sem.semanas?.map((s) => {
                                                    const maxFat = Math.max(...sem.semanas.map((x) => x.faturamento), 1);
                                                    return (
                                                        <div key={s.semana} className="flex items-center gap-2">
                                                            <span className="text-white/40 text-[11px] w-16 shrink-0">Semana {s.semana}</span>
                                                            <div className="flex-1 h-2 rounded bg-white/[0.06] overflow-hidden">
                                                                <div className="h-full rounded bg-ecf-yellow" style={{ width: `${(s.faturamento / maxFat) * 100}%` }} />
                                                            </div>
                                                            <span className="text-white/80 text-[11px] tabular-nums w-24 text-right shrink-0">{formatCurrency(s.faturamento)}</span>
                                                        </div>
                                                    );
                                                })}
                                                <div className="flex items-center justify-between pt-1.5 mt-1 border-t border-white/[0.06]">
                                                    <span className="text-white/40 text-[11px]">Total do mês</span>
                                                    <span className="text-ecf-yellow font-semibold text-xs tabular-nums">{formatCurrency(sem.total)}</span>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </aside>
        </div>
    );
}

/**
 * Kpi — caixa compacta de KPI agregado. `accent` destaca em amarelo ECF.
 */
function Kpi({ label, value, accent = false }) {
    return (
        <div>
            <p className="text-white/30 text-[10px] uppercase tracking-wider">{label}</p>
            <p className={cn('font-display font-extrabold text-lg mt-0.5', accent ? 'text-ecf-yellow' : 'text-white/80')}>
                {value}
            </p>
        </div>
    );
}
